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
$sqlCheck = "SELECT titulo, mensaje FROM tips_diarios_ia WHERE fecha = ? LIMIT 1";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $hoy);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result()->fetch_assoc();

if ($resCheck) {
    echo json_encode(["status" => "success", "titulo" => $resCheck['titulo'], "mensaje" => $resCheck['mensaje']]);
} else {
    // Si no hay tip generado HOY, devuelve uno genérico por ahora
    echo json_encode([
        "status" => "success", 
        "titulo" => "🎾 Acción Rápida", 
        "mensaje" => "¿Tu volea se queda en la red? Flexiona más las rodillas. Esa simple acción evitará que la bola se levante y te pasen. ¡Foco hoy en eso!"
    ]);
}
$conn->close();
