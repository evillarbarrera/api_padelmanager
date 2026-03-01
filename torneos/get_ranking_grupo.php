<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$grupo_id = $_GET['grupo_id'] ?? 0;

if (!$grupo_id) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT gp.*, p.nombre_pareja 
        FROM torneo_grupo_parejas gp
        JOIN torneo_parejas p ON gp.pareja_id = p.id
        WHERE gp.grupo_id = ?
        ORDER BY gp.puntos DESC, (gp.sf - gp.sc) DESC, (gp.gf - gp.gc) DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $grupo_id);
$stmt->execute();
$result = $stmt->get_result();

$ranking = [];
while ($row = $result->fetch_assoc()) {
    $ranking[] = $row;
}

echo json_encode($ranking);
?>
