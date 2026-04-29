<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

// Manejo de peticiones preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
$formato_grupos = $data['formato_grupos'] ?? 4;
$formato_sets = $data['formato_sets'] ?? 'Full Sets';
$poster_url = $data['poster_url'] ?? null;
$categorias = $data['categorias'] ?? [];

// Auto-calcular fecha_fin si viene vacía (7 días por defecto)
if (empty($fecha_fin) && !empty($fecha_inicio)) {
    $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + 7 days'));
}

if (empty($club_id) || empty($nombre) || empty($fecha_inicio)) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan campos obligatorios (Club, Nombre y Fecha Inicio)"]);
    exit;
}

// Iniciar transacción para asegurar que se crea todo o nada
$conn->begin_transaction();

try {
    $inscripciones_abiertas = $data['inscripciones_abiertas'] ?? 0;

    $sql = "INSERT INTO torneos_v2 (club_id, creator_id, nombre, descripcion, fecha_inicio, fecha_fin, tipo, formato_grupos, formato_sets, poster_url, inscripciones_abiertas) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . $conn->error);
    }

    $stmt->bind_param("iisssssissi", $club_id, $creator_id, $nombre, $descripcion, $fecha_inicio, $fecha_fin, $tipo, $formato_grupos, $formato_sets, $poster_url, $inscripciones_abiertas);
    
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
