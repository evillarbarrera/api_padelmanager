<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// TOKEN
$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}
require_once "../db.php";

// BODY
$input = file_get_contents("php://input");
file_put_contents("debug_insert_pack.log", date('Y-m-d H:i:s') . " - Input: " . $input . "\n", FILE_APPEND);

$data = json_decode($input, true);

// 1. Obtener datos básicos
$pack_id    = isset($data['pack_id']) ? (int)$data['pack_id'] : 0;
$jugador_id = isset($data['jugador_id']) ? (int)$data['jugador_id'] : 0;
$cupon_id   = isset($data['cupon_id']) ? (int)$data['cupon_id'] : null;
$precio_pagado = isset($data['precio_pagado']) ? (float)$data['precio_pagado'] : null;

if ($pack_id === 0 || $jugador_id === 0) {
    http_response_code(400);
    echo json_encode(["error" => "Datos incompletos"]);
    exit;
}

// 2. Validar que el pack exista y obtener precio oficial del servidor (Seguridad QA)
$stmtPackInfo = $conn->prepare("SELECT id, precio, sesiones_totales FROM packs WHERE id = ? AND activo = 1");
$stmtPackInfo->bind_param("i", $pack_id);
$stmtPackInfo->execute();
$resPack = $stmtPackInfo->get_result()->fetch_assoc();

if (!$resPack) {
    http_response_code(400);
    echo json_encode(["error" => "El pack seleccionado no existe o no está activo."]);
    exit;
}

// Usamos el precio del servidor como fuente de verdad
$precio_oficial = $resPack['precio'];
$precio_a_guardar = ($precio_pagado !== null) ? $precio_pagado : $precio_oficial; 

// Alerta de seguridad si hay discrepancia sospechosa
if ($precio_pagado !== null && abs($precio_pagado - $precio_oficial) > 1.00) {
    error_log("[SECURITY] POSIBLE INYECCION PRECIO: Usuario $jugador_id envió $precio_pagado (Pack #$pack_id, Oficial: $precio_oficial)");
}

// fechas calculadas SOLO AQUÍ
$fecha_inicio = date('Y-m-d');
$fecha_fin    = date('Y-m-d', strtotime('+6 months'));

$sql = "INSERT INTO pack_jugadores
        (pack_id, jugador_id, sesiones_usadas, fecha_inicio, fecha_fin, cupon_id, precio_pagado)
        VALUES (?, ?, 0, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iissid", $pack_id, $jugador_id, $fecha_inicio, $fecha_fin, $cupon_id, $precio_a_guardar);

if ($stmt->execute()) {
    $pack_jugador_id = $conn->insert_id;

    // Actualizar uso del cupón si existe
    if ($cupon_id) {
        $updateCupon = $conn->prepare("UPDATE cupones SET uso_actual = uso_actual + 1 WHERE id = ?");
        $updateCupon->bind_param("i", $cupon_id);
        $updateCupon->execute();
    }

    // --- NOTIFICATIONS ---
    require_once "../system/mail_service.php";

    // Obtener detalles para el correo
    $sqlDetails = "
        SELECT 
            p.nombre as pack_nombre, p.sesiones_totales, p.entrenador_id,
            u1.nombre as nom_jugador, u1.usuario as email_jugador,
            u2.nombre as nom_entrenador, u2.usuario as email_entrenador
        FROM packs p
        JOIN usuarios u1 ON u1.id = ?
        JOIN usuarios u2 ON u2.id = p.entrenador_id
        WHERE p.id = ?
    ";
    $stmtDetails = $conn->prepare($sqlDetails);
    $stmtDetails->bind_param("ii", $jugador_id, $pack_id);
    $stmtDetails->execute();
    $details = $stmtDetails->get_result()->fetch_assoc();

    if ($details) {
        $nomJugador = $details['nom_jugador'];
        $emailJugador = $details['email_jugador'];
        $nomEntrenador = $details['nom_entrenador'];
        $emailEntrenador = $details['email_entrenador'];
        $packNombre = $details['pack_nombre'];
        $sesiones = $details['sesiones_totales'];

        $subject = "Nuevo Pack Adquirido - $packNombre";

        // Correo para el Jugador
        $bodyJugador = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #111;'>¡Felicidades por tu nuevo Pack!</h2>
            <p>Hola <strong>$nomJugador</strong>,</p>
            <p>Has adquirido con éxito el pack <strong>$packNombre</strong> con el entrenador <strong>$nomEntrenador</strong>.</p>
            <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Pack:</strong> $packNombre</p>
                <p style='margin: 5px 0;'><strong>Sesiones:</strong> $sesiones clases</p>
                <p style='margin: 5px 0;'><strong>Vigencia:</strong> Aproximadamente 30 días (según acuerdo con tu entrenador)</p>
            </div>
            <p>Ya puedes comenzar a agendar tus clases desde la aplicación.</p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
        </div>";

        // Correo para el Entrenador
        $bodyEntrenador = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #111;'>Nuevo Alumno con Pack</h2>
            <p>Hola <strong>$nomEntrenador</strong>,</p>
            <p>El jugador <strong>$nomJugador</strong> ha adquirido tu pack <strong>$packNombre</strong>.</p>
            <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Jugador:</strong> $nomJugador</p>
                <p style='margin: 5px 0;'><strong>Pack:</strong> $packNombre</p>
                <p style='margin: 5px 0;'><strong>Sesiones:</strong> $sesiones clases</p>
            </div>
            <p>¡Prepárate para las próximas sesiones!</p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
        </div>";

        if (!empty($emailJugador)) enviarCorreoSMTP($emailJugador, $subject, $bodyJugador);
        if (!empty($emailEntrenador)) enviarCorreoSMTP($emailEntrenador, $subject, $bodyEntrenador);

        // --- PUSH NOTIFICATIONS ---
        require_once "../notifications/notificaciones_helper.php";
        
        // Notificar al Entrenador
        $entrenador_id = intval($details['entrenador_id'] ?? 0);
        if ($entrenador_id > 0) {
            $tituloPush = "Nuevo Pack Vendido";
            $mensajePush = "$nomJugador ha adquirido el pack: $packNombre";
            notifyUser($conn, $entrenador_id, $tituloPush, $mensajePush, 'nuevo_pack');
        }

        // Notificar al Jugador
        if ($jugador_id > 0) {
            $tituloPush = "Pack Activo";
            $mensajePush = "Tu pack $packNombre ya está activo. ¡Puedes agendar tus clases!";
            notifyUser($conn, $jugador_id, $tituloPush, $mensajePush, 'pack_activado');
        }
    }

    echo json_encode([
        "success" => true,
        "pack_jugador_id" => $pack_jugador_id
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => $stmt->error]);
}
