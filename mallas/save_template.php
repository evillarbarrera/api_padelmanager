<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}


error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$entrenador_id = $data['entrenador_id'] ?? 0;
$malla_id = $data['mallaId'] ?? 0; // Si viene ID, es una edición
$nombre = $data['nombreMalla'] ?? '';
$nivel = $data['nivel'] ?? '';
$publico = $data['publico'] ?? '';
$clases = $data['clases'] ?? [];

if (!$entrenador_id || !$nombre) {
    http_response_code(400);
    echo json_encode(["error" => "Nombre y entrenador_id son requeridos"]);
    exit;
}

$conn->begin_transaction();

try {
    if ($malla_id > 0) {
        // --- 1. UPDATE existing Malla ---
        $stmt = $conn->prepare("UPDATE mallas SET nombre = ?, nivel = ?, publico = ? WHERE id = ? AND entrenador_id = ?");
        $stmt->bind_param("sssii", $nombre, $nivel, $publico, $malla_id, $entrenador_id);
        $stmt->execute();

        // 2. Delete old classes to replace them
        $stmtDel = $conn->prepare("DELETE FROM clases_malla WHERE malla_id = ?");
        $stmtDel->bind_param("i", $malla_id);
        $stmtDel->execute();
    } else {
        // --- 1. INSERT new Malla ---
        $stmt = $conn->prepare("INSERT INTO mallas (entrenador_id, nombre, nivel, publico, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isss", $entrenador_id, $nombre, $nivel, $publico);
        $stmt->execute();
        $malla_id = $conn->insert_id;
    }

    // --- 3. Insert Classes (shared logic) ---
    $stmtClase = $conn->prepare("INSERT INTO clases_malla (malla_id, orden, titulo, objetivo, calentamiento, parte_tecnica, drills, juego, recursos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($clases as $index => $c) {
        $orden = $index + 1;
        $titulo = $c['titulo'] ?? "Clase $orden";
        $objetivo = $c['objetivo'] ?? '';
        
        // Si el objetivo es un array, lo guardamos como JSON string
        if (is_array($objetivo)) {
            $objetivo = json_encode($objetivo);
        }

        $cal = $c['contenido']['calentamiento'] ?? '';
        $tec = $c['contenido']['parteTecnica'] ?? '';
        $dri = $c['contenido']['drills'] ?? '';
        $jue = $c['contenido']['juego'] ?? '';
        $rec = $c['recursos'] ?? '';

        $stmtClase->bind_param("iisssssss", 
            $malla_id, $orden, $titulo, $objetivo, $cal, $tec, $dri, $jue, $rec
        );
        $stmtClase->execute();
    }

    $conn->commit();
    echo json_encode(["success" => true, "malla_id" => $malla_id]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
