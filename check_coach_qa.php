<?php
require_once "db.php";
header("Content-Type: application/json");

$coach_id = 9; // Entrenador a consultar

$sql = "SELECT id, nombre, email, plan_id, subscription_status, mp_preapproval_id, paypal_sub_id, created_at FROM usuarios WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $coach_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user) {
    $plan_names = [1 => "Emprendedor", 2 => "Inicial 20", 3 => "Profesional (Pro 40)", 4 => "Elite"];
    $user['plan_actual_texto'] = $plan_names[$user['plan_id']] ?? "Desconocido (ID: ".$user['plan_id'].")";
    echo json_encode(["status" => "success", "data" => $user], JSON_PRETTY_PRINT);
} else {
    echo json_encode(["status" => "error", "message" => "No se encontró informacion para el ID $coach_id"]);
}
