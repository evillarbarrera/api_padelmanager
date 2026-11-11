<?php
/**
 * PadelBlox - Reserva Pública sin Cuenta de Usuario (Guest Checkout)
 * Permite a cualquier jugador reservar una cancha desde el link público del club
 * sin requerir login ni registro previo.
 * 
 * Uso:
 * POST /api/clubes/reserva_publica_guest.php
 * Body: {
 *   "cancha_id": 1,
 *   "fecha": "2026-11-11",
 *   "hora_inicio": "19:30",
 *   "duracion": 90,
 *   "nombre_invitado": "Carlos Mendoza",
 *   "telefono_invitado": "+56912345678",
 *   "email_invitado": "carlos@gmail.com",
 *   "metodo_pago": "club",
 *   "notas": "Traigo palas propias"
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, x-authorization, X-Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once "../db.php";
require_once "../notifications/notificaciones_helper.php";
require_once "../system/mail_service.php";

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Cuerpo de la petición inválido (JSON requerido)"]);
    exit;
}

$canchaId = intval($data['cancha_id'] ?? 0);
$fecha = trim($data['fecha'] ?? '');
$horaInicio = trim($data['hora_inicio'] ?? '');
$duracion = intval($data['duracion'] ?? 90);
if ($duracion <= 0) $duracion = 90;

$nombreInvitado = trim($data['nombre_invitado'] ?? '');
$telefonoInvitado = trim($data['telefono_invitado'] ?? '');
$emailInvitado = trim($data['email_invitado'] ?? '');
$metodoPago = strtolower(trim($data['metodo_pago'] ?? 'club')); // 'club' (pagar en recepción), 'transferencia', 'webpay'
$notas = trim($data['notas'] ?? '');

// Validaciones básicas
if ($canchaId <= 0 || empty($fecha) || empty($horaInicio)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos de la reserva (cancha, fecha u horario)."]);
    exit;
}

if (empty($nombreInvitado)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Por favor ingresa tu nombre y apellido para la reserva."]);
    exit;
}

if (empty($telefonoInvitado)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Por favor ingresa un teléfono o WhatsApp de contacto."]);
    exit;
}

// Normalizar formato de hora
if (strlen($horaInicio) === 5) {
    $horaInicio .= ':00';
}
$hIniShort = substr($horaInicio, 0, 5);

// Calcular hora de fin
$tFin = strtotime("$fecha $horaInicio") + ($duracion * 60);
$horaFin = date('H:i:s', $tFin);
$hFinShort = substr($horaFin, 0, 5);

try {
    // 1. Asegurar columnas adicionales en reservas_cancha si no existen
    $colsCheck = [
        "telefono_externo" => "VARCHAR(50) NULL",
        "email_externo" => "VARCHAR(120) NULL",
        "tipo_origen" => "VARCHAR(30) DEFAULT 'web_guest'",
        "notas_reserva" => "TEXT NULL"
    ];
    foreach ($colsCheck as $col => $typeDef) {
        $cRes = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE '$col'");
        if ($cRes && $cRes->num_rows === 0) {
            @$conn->query("ALTER TABLE reservas_cancha ADD COLUMN `$col` $typeDef");
        }
    }

    // 2. Obtener datos de la Cancha y del Club
    $stmtCancha = $conn->prepare("
        SELECT c.id, c.nombre, c.club_id, c.precio_hora, c.precio_90, c.precio_90_alto, c.hora_inicio_alto,
               cl.nombre as club_nombre, cl.telefono as club_telefono, cl.landing_whatsapp, cl.direccion, cl.comuna, cl.usuario_id as club_admin_id
        FROM canchas c
        JOIN clubes cl ON c.club_id = cl.id
        WHERE c.id = ? AND c.activa = 1
        LIMIT 1
    ");
    $stmtCancha->bind_param("i", $canchaId);
    $stmtCancha->execute();
    $canchaData = $stmtCancha->get_result()->fetch_assoc();
    $stmtCancha->close();

    if (!$canchaData) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "La cancha seleccionada no está disponible o no existe."]);
        exit;
    }

    $clubId = intval($canchaData['club_id']);
    $canchaNombre = $canchaData['nombre'];
    $clubNombre = $canchaData['club_nombre'];
    $clubAdminId = intval($canchaData['club_admin_id'] ?? 0);
    $clubTelefono = $canchaData['landing_whatsapp'] ?: $canchaData['club_telefono'];

    // 3. Calcular precio según horario pico
    $hInicioAlto = !empty($canchaData['hora_inicio_alto']) ? substr($canchaData['hora_inicio_alto'], 0, 5) : '18:00';
    $esPico = ($hIniShort >= $hInicioAlto);
    $precioBase = floatval($canchaData['precio_90'] ?: ($canchaData['precio_hora'] ? $canchaData['precio_hora'] * 1.5 : 20000));
    $precioAlto = floatval($canchaData['precio_90_alto'] ?: $precioBase);
    $precioFinal = intval($esPico ? $precioAlto : $precioBase);

    // 4. VERIFICAR QUE EL HORARIO SIGUE LIBRE (Prevención de colisiones concurrentes)
    $stmtCheck = $conn->prepare("
        SELECT id FROM reservas_cancha 
        WHERE cancha_id = ? 
          AND fecha = ? 
          AND LOWER(COALESCE(estado, '')) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada')
          AND hora_inicio < ? 
          AND (CASE WHEN hora_fin IN ('00:00:00', '00:00', '24:00', '24:00:00', '23:59', '23:59:00') THEN '24:00:00' ELSE hora_fin END) > ?
        LIMIT 1
    ");
    $stmtCheck->bind_param("isss", $canchaId, $fecha, $horaFin, $horaInicio);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck && $resCheck->num_rows > 0) {
        $stmtCheck->close();
        http_response_code(409); // Conflict
        echo json_encode([
            "status" => "error", 
            "message" => "Lo sentimos, el horario $hIniShort - $hFinShort acaba de ser tomado por otro jugador. Por favor selecciona otro bloque disponible."
        ]);
        exit;
    }
    $stmtCheck->close();

    // 5. Vincular a usuario existente si coincide el email, o dejar en NULL
    $userId = null;
    if (!empty($emailInvitado)) {
        $stmtU = $conn->prepare("SELECT id FROM usuarios WHERE LOWER(usuario) = LOWER(?) LIMIT 1");
        if ($stmtU) {
            $stmtU->bind_param("s", $emailInvitado);
            $stmtU->execute();
            $rU = $stmtU->get_result()->fetch_assoc();
            if ($rU) {
                $userId = intval($rU['id']);
            }
            $stmtU->close();
        }
    }

    // 6. Insertar Reserva en la Base de Datos
    $estadoReserva = 'Confirmada';
    $pagado = 0; // Pagar en recepción por defecto
    $origen = 'web_guest';

    $stmtInsert = $conn->prepare("
        INSERT INTO reservas_cancha 
        (cancha_id, usuario_id, nombre_externo, telefono_externo, email_externo, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, metodo_pago, tipo_origen, notas_reserva)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtInsert->bind_param(
        "iissssssiiissss",
        $canchaId,
        $userId,
        $nombreInvitado,
        $telefonoInvitado,
        $emailInvitado,
        $fecha,
        $horaInicio,
        $horaFin,
        $precioFinal,
        $precioFinal,
        $pagado,
        $estadoReserva,
        $metodoPago,
        $origen,
        $notas
    );

    if (!$stmtInsert->execute()) {
        throw new Exception("Error al insertar la reserva: " . $stmtInsert->error);
    }

    $reservaId = $conn->insert_id;
    $stmtInsert->close();

    $codigoReserva = "PBX-" . str_pad($reservaId, 5, '0', STR_PAD_LEFT);

    // 7. Notificaciones
    // A) Notificar al Administrador del Club (Push + Campana in-app)
    if ($clubAdminId > 0) {
        $tituloAdmin = "🎾 Nueva Reserva Online sin Login";
        $mensajeAdmin = "$nombreInvitado ha reservado la $canchaNombre para el $fecha de $hIniShort a $hFinShort hrs. Tel: $telefonoInvitado ($metodoPago).";
        @notifyUser($conn, $clubAdminId, $tituloAdmin, $mensajeAdmin, 'nueva_reserva', "$fecha $horaInicio", [
            'route' => '/club-reservas',
            'reserva_id' => (string)$reservaId
        ]);
    }

    // B) Enviar Email de confirmación si el invitado puso correo
    if (!empty($emailInvitado) && filter_var($emailInvitado, FILTER_VALIDATE_EMAIL)) {
        try {
            $asunto = "🎾 Reserva Confirmada: $canchaNombre - $clubNombre";
            $cuerpoEmail = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #0f172a; color: #ffffff; border-radius: 12px;'>
                    <h2 style='color: #ccff00; margin-top: 0;'>¡Tu cancha está reservada! 🎾</h2>
                    <p>Hola <strong>$nombreInvitado</strong>, hemos confirmado tu turno con éxito.</p>
                    
                    <div style='background: #1e293b; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 5px 0;'><strong>Club:</strong> $clubNombre</p>
                        <p style='margin: 5px 0;'><strong>Cancha:</strong> $canchaNombre</p>
                        <p style='margin: 5px 0;'><strong>Fecha:</strong> $fecha</p>
                        <p style='margin: 5px 0;'><strong>Horario:</strong> $hIniShort a $hFinShort hrs ($duracion min)</p>
                        <p style='margin: 5px 0;'><strong>Total:</strong> $" . number_format($precioFinal, 0, ',', '.') . " CLP</p>
                        <p style='margin: 5px 0;'><strong>Código:</strong> <span style='color: #ccff00; font-weight: bold;'>$codigoReserva</span></p>
                    </div>

                    <p style='color: #94a3b8; font-size: 14px;'>Dirección: {$canchaData['direccion']}, {$canchaData['comuna']}.</p>
                    <p style='color: #94a3b8; font-size: 12px;'>Presenta tu nombre o código al llegar a la recepción del club.</p>
                </div>
            ";
            @sendEmail($emailInvitado, $asunto, $cuerpoEmail, $nombreInvitado);
        } catch (Throwable $eMail) {
            error_log("Error enviando email guest: " . $eMail->getMessage());
        }
    }

    // C) Construir URL directa de WhatsApp al club con el mensaje prellenado
    $waUrl = null;
    if (!empty($clubTelefono)) {
        $cleanPhone = preg_replace('/[^0-9]/', '', $clubTelefono);
        $waMsg = "Hola $clubNombre! Acabo de reservar en la $canchaNombre para el $fecha a las $hIniShort hrs a nombre de $nombreInvitado (Código: $codigoReserva).";
        $waUrl = "https://wa.me/{$cleanPhone}?text=" . urlencode($waMsg);
    }

    echo json_encode([
        "status" => "success",
        "message" => "¡Reserva confirmada exitosamente!",
        "reserva" => [
            "id" => $reservaId,
            "codigo" => $codigoReserva,
            "cancha" => $canchaNombre,
            "club" => $clubNombre,
            "fecha" => $fecha,
            "hora_inicio" => $hIniShort,
            "hora_fin" => $hFinShort,
            "duracion" => $duracion,
            "precio" => $precioFinal,
            "precio_formateado" => "$" . number_format($precioFinal, 0, ',', '.'),
            "nombre" => $nombreInvitado,
            "telefono" => $telefonoInvitado,
            "email" => $emailInvitado,
            "metodo_pago" => $metodoPago,
            "whatsapp_confirmacion_url" => $waUrl
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error interno al procesar la reserva: " . $e->getMessage()
    ]);
}
