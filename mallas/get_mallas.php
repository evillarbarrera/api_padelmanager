<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$entrenador_id = $_GET['entrenador_id'] ?? 0;
$malla_id = $_GET['id'] ?? 0;

if (!$entrenador_id && !$malla_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id o id de malla es requerido"]);
    exit;
}

if ($malla_id) {
    // Get single malla with all classes
    $sql = "SELECT m.* FROM mallas m WHERE m.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $malla_id);
    $stmt->execute();
    $malla = $stmt->get_result()->fetch_assoc();

    if ($malla) {
        $sql_clases = "SELECT * FROM clases_malla WHERE malla_id = ? ORDER BY orden ASC";
        $stmtC = $conn->prepare($sql_clases);
        $stmtC->bind_param("i", $malla_id);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        
        $clases = [];
        while($c = $resC->fetch_assoc()) {
            // Intentar decodificar objetivo si es JSON, o dejarlo como string si no
            $obj = json_decode($c['objetivo'], true);
            $c['objetivo_decoded'] = $obj ? $obj : $c['objetivo'];
            $clases[] = $c;
        }
        $malla['clases'] = $clases;
        echo json_encode($malla);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Malla no encontrada"]);
    }
} else {
    // List all mallas for this coach
    $sql = "SELECT id, nombre, nivel, publico, created_at FROM mallas WHERE entrenador_id = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $entrenador_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $mallas = [];
    while ($row = $result->fetch_assoc()) {
        $mallas[] = $row;
    }
    echo json_encode($mallas);
}
?>
