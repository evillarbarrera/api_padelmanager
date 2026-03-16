<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../db.php";
require_once "payment_processor.php";

$token_ws = $_POST['token_ws'] ?? $_GET['token_ws'] ?? '';

// Check Cancellation
if (strpos($token_ws, 'CANCEL_') === 0) {
    header("Location: https://padelmanager.cl/pack-alumno?status=cancelled");
    exit;
}

// Decode Data (MOCK only strategy / Transbank legacy compatibility)
$json = base64_decode($token_ws);
$data = json_decode($json, true);

if (!$data || !isset($data['pack_id'])) {
    header("Location: https://padelmanager.cl/pack-alumno?status=error_token");
    exit;
}

$origin = $data['origin'] ?? 'https://padelmanager.cl/pack-alumno';

// Fulfill the payment using shared logic
$success = fulfillPayment($conn, $data);

if ($success) {
    $reserva_id = $data['reserva_id'] ?? null;
    $status = (isset($data['tipo']) && $data['tipo'] === 'grupal') ? "success_group" : "success";
    header("Location: " . $origin . "?status=" . $status . ($reserva_id ? "&reserva=confirmed" : ""));
} else {
    header("Location: " . $origin . "?status=error_db");
}
exit;

