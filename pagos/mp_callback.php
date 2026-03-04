<?php
require_once "../db.php";
require_once "mercado_pago_service.php";

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null; // state is the userId

if (!$code || !$state) {
    die("Error: Falta código o estado.");
}

$response = MercadoPagoService::getAccessToken($code);

if ($response && isset($response['user_id'])) {
    $mp_id = $response['user_id'];
    $user_id = (int)$state;

    // Actualizar el mp_collector_id en la base de datos
    $sql = "UPDATE usuarios SET mp_collector_id = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $mp_id, $user_id);
    
    if ($stmt->execute()) {
        // Redirigir al perfil con éxito
        header("Location: https://padelmanager.cl/perfil?mp_status=success");
    } else {
        header("Location: https://padelmanager.cl/perfil?mp_status=error&msg=db_error");
    }
} else {
    // Error en el intercambio de tokens
    header("Location: https://padelmanager.cl/perfil?mp_status=error&msg=auth_failed");
}
exit;
