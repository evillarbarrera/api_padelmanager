<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$categoria_id = $_GET['categoria_id'] ?? 0;
$ronda = $_GET['ronda'] ?? ''; // 'Final', 'Semifinal', 'Cuartos', 'Octavos'

if (!$categoria_id) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT p.*, 
        p1.nombre_pareja as pareja1_nombre, p2.nombre_pareja as pareja2_nombre
        FROM torneo_partidos_v2 p 
        JOIN torneo_parejas p1 ON p.pareja1_id = p1.id
        JOIN torneo_parejas p2 ON p.pareja2_id = p2.id
        WHERE p.categoria_id = ? AND p.grupo_id IS NULL";

if (!empty($ronda)) {
    $sql .= " AND p.ronda = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $categoria_id, $ronda);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $categoria_id);
}

$stmt->execute();
$result = $stmt->get_result();

$partidos = [];
while ($row = $result->fetch_assoc()) {
    $partidos[] = $row;
}

echo json_encode($partidos);
?>
