<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$torneo_id = $_GET['torneo_id'] ?? 0;

if (!$torneo_id) {
    echo json_encode([]);
    exit;
}

// Fetch all matches for the tournament from all categories
$sql = "SELECT p.*, 
        p1.nombre_pareja as pareja1_nombre, p2.nombre_pareja as pareja2_nombre,
        g.nombre as grupo_nombre,
        c.nombre as categoria_nombre
        FROM torneo_partidos_v2 p 
        JOIN torneo_parejas p1 ON p.pareja1_id = p1.id
        JOIN torneo_parejas p2 ON p.pareja2_id = p2.id
        LEFT JOIN torneo_grupos g ON p.grupo_id = g.id
        JOIN torneo_categorias c ON p.categoria_id = c.id
        WHERE c.torneo_id = ?
        ORDER BY c.nombre ASC, g.nombre ASC, p.id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $torneo_id);
$stmt->execute();
$result = $stmt->get_result();

$partidos = [];
while ($row = $result->fetch_assoc()) {
    $partidos[] = $row;
}

echo json_encode($partidos);
?>
