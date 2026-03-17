<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "../db.php";

$hoy = date('Y-m-d');
// Obtenemos los 2 (o todos los que existan para hoy)
$sqlCheck = "SELECT titulo, mensaje, posicion FROM tips_diarios_ia WHERE fecha = ? ORDER BY posicion ASC";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $hoy);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();

$tips = [];
while($row = $resCheck->fetch_assoc()) {
    $tips[] = $row;
}

if (count($tips) > 0) {
    echo json_encode([
        "status" => "success", 
        "titulo" => $tips[0]['titulo'], // Compatible con versión vieja
        "mensaje" => $tips[0]['mensaje'], // Compatible con versión vieja
        "tips" => $tips // Versión nueva
    ]);
} else {
    // Si no hay tip generado HOY, devuelve uno genérico por ahora
    $generic = [
        "titulo" => "🎾 Acción Rápida", 
        "mensaje" => "¿Tu volea se queda en la red? Flexiona más las rodillas. Esa simple acción evitará que la bola se levante y te pasen. ¡Foco hoy en eso!"
    ];
    echo json_encode([
        "status" => "success", 
        "titulo" => $generic['titulo'], 
        "mensaje" => $generic['mensaje'],
        "tips" => [$generic]
    ]);
}
$conn->close();
?>
