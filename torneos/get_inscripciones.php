<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$categoria_id = $_GET['categoria_id'] ?? 0;

if (!$categoria_id) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT i.*, p.nombre_pareja, p.jugador1_id, p.jugador2_id,
        COALESCE(u1.nombre, p.jugador1_nombre_manual) as jugador1_nombre, 
        COALESCE(u2.nombre, p.jugador2_nombre_manual) as jugador2_nombre
        FROM torneo_inscripciones i 
        JOIN torneo_parejas p ON i.pareja_id = p.id
        LEFT JOIN usuarios u1 ON p.jugador1_id = u1.id
        LEFT JOIN usuarios u2 ON p.jugador2_id = u2.id
        WHERE i.categoria_id = ?
        ORDER BY i.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $categoria_id);
$stmt->execute();
$result = $stmt->get_result();

$inscripciones = [];
while ($row = $result->fetch_assoc()) {
    $inscripciones[] = $row;
}

echo json_encode($inscripciones);
?>
