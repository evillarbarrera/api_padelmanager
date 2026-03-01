<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$club_id = $data['club_id'] ?? 0;
$creator_id = $data['creator_id'] ?? 0;
$nombre = $data['nombre'] ?? '';
$descripcion = $data['descripcion'] ?? '';
$fecha_inicio = $data['fecha_inicio'] ?? '';
$fecha_fin = $data['fecha_fin'] ?? '';
$tipo = $data['tipo'] ?? 'Grupos + Playoffs';
$categorias = $data['categorias'] ?? []; // Array of {nombre, max_parejas, puntos_repartir}

if (empty($club_id) || empty($nombre) || empty($fecha_inicio)) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan campos obligatorios"]);
    exit;
}

// Iniciar transacción para asegurar que se crea todo o nada
$conn->begin_transaction();

try {
    $sql = "INSERT INTO torneos_v2 (club_id, creator_id, nombre, descripcion, fecha_inicio, fecha_fin, tipo) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisssss", $club_id, $creator_id, $nombre, $descripcion, $fecha_inicio, $fecha_fin, $tipo);
    
    if (!$stmt->execute()) {
        throw new Exception($conn->error);
    }
    
    $torneo_id = $conn->insert_id;

    // Crear las categorías
    foreach ($categorias as $cat) {
        $c_nombre = $cat['nombre'] ?? '';
        $c_max = $cat['max_parejas'] ?? 16;
        $c_puntos = $cat['puntos_repartir'] ?? 0;
        
        if (!empty($c_nombre)) {
            $sqlCat = "INSERT INTO torneo_categorias (torneo_id, nombre, max_parejas, puntos_repartir) VALUES (?, ?, ?, ?)";
            $stmtC = $conn->prepare($sqlCat);
            $stmtC->bind_param("isii", $torneo_id, $c_nombre, $c_max, $c_puntos);
            $stmtC->execute();
        }
    }

    $conn->commit();
    
    $res = $conn->query("SELECT * FROM torneos_v2 WHERE id = $torneo_id");
    echo json_encode(["success" => true, "status" => "Torneo y categorías creados", "torneo" => $res->fetch_assoc()]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
