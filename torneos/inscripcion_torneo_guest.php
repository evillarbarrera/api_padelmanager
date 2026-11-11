<?php
/**
 * PadelBlox - Inscripción Pública a Torneos y Americanos (Sin Login)
 * Permite a cualquier pareja inscribirse a un torneo/americano desde el link público del club.
 * 
 * Uso:
 * POST /api/torneos/inscripcion_publica_guest.php
 * Body: {
 *   "torneo_id": 5,
 *   "categoria_id": 12, // opcional
 *   "jugador1_nombre": "Diego Alarcón",
 *   "jugador1_telefono": "+56987654321",
 *   "jugador1_email": "diego@gmail.com",
 *   "jugador2_nombre": "Matías Pérez",
 *   "jugador2_telefono": "+56911223344",
 *   "nombre_pareja": "Los Galácticos",
 *   "notas": "Preferencia horario noche"
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
    echo json_encode(["status" => "error", "message" => "JSON inválido"]);
    exit;
}

$torneoId = intval($data['torneo_id'] ?? 0);
$categoriaId = intval($data['categoria_id'] ?? 0);

$j1Nombre = trim($data['jugador1_nombre'] ?? '');
$j1Telefono = trim($data['jugador1_telefono'] ?? '');
$j1Email = trim($data['jugador1_email'] ?? '');

$j2Nombre = trim($data['jugador2_nombre'] ?? '');
$j2Telefono = trim($data['jugador2_telefono'] ?? '');

$nombrePareja = trim($data['nombre_pareja'] ?? '');
if (empty($nombrePareja)) {
    $nombrePareja = "$j1Nombre / $j2Nombre";
}
$notas = trim($data['notas'] ?? '');

if ($torneoId <= 0 || empty($j1Nombre) || empty($j1Telefono)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (torneo, nombre y teléfono del jugador 1)."]);
    exit;
}

try {
    // 1. Obtener datos del Torneo y Club
    $stmtT = $conn->prepare("
        SELECT t.id, t.nombre, t.club_id, t.fecha_inicio, t.fecha_fin, t.precio, t.cupos_maximos, t.estado,
               c.nombre as club_nombre, c.telefono as club_telefono, c.landing_whatsapp, c.usuario_id as club_admin_id
        FROM torneos t
        JOIN clubes c ON t.club_id = c.id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmtT->bind_param("i", $torneoId);
    $stmtT->execute();
    $torneoData = $stmtT->get_result()->fetch_assoc();
    $stmtT->close();

    if (!$torneoData) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "El torneo seleccionado no existe o no está activo."]);
        exit;
    }

    $clubNombre = $torneoData['club_nombre'];
    $torneoNombre = $torneoData['nombre'];
    $clubAdminId = intval($torneoData['club_admin_id'] ?? 0);
    $clubTelefono = $torneoData['landing_whatsapp'] ?: $torneoData['club_telefono'];
    $precioTorneo = floatval($torneoData['precio'] ?? 0);

    // 2. Resolver Categoría
    if ($categoriaId <= 0) {
        // Buscar la primera categoría activa de este torneo
        $checkCat = $conn->query("SHOW TABLES LIKE 'torneo_categorias'");
        if ($checkCat && $checkCat->num_rows > 0) {
            $rCat = $conn->query("SELECT id FROM torneo_categorias WHERE torneo_id = $torneoId ORDER BY id ASC LIMIT 1");
            if ($rCat && $rowCat = $rCat->fetch_assoc()) {
                $categoriaId = intval($rowCat['id']);
            }
        }
    }

    // 3. Crear Pareja en torneo_parejas
    $checkTP = $conn->query("SHOW TABLES LIKE 'torneo_parejas'");
    $parejaId = 0;
    if ($checkTP && $checkTP->num_rows > 0) {
        // Asegurar columnas de contacto si no existen
        $colsP = [
            "jugador1_telefono" => "VARCHAR(50) NULL",
            "jugador1_email" => "VARCHAR(120) NULL",
            "jugador2_telefono" => "VARCHAR(50) NULL"
        ];
        foreach ($colsP as $c => $def) {
            $cp = $conn->query("SHOW COLUMNS FROM torneo_parejas LIKE '$c'");
            if ($cp && $cp->num_rows === 0) {
                @$conn->query("ALTER TABLE torneo_parejas ADD COLUMN `$c` $def");
            }
        }

        $stmtInsP = $conn->prepare("
            INSERT INTO torneo_parejas (jugador1_nombre_manual, jugador2_nombre_manual, nombre_pareja, jugador1_telefono, jugador1_email, jugador2_telefono)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($stmtInsP) {
            $stmtInsP->bind_param("ssssss", $j1Nombre, $j2Nombre, $nombrePareja, $j1Telefono, $j1Email, $j2Telefono);
            $stmtInsP->execute();
            $parejaId = $conn->insert_id;
            $stmtInsP->close();
        }
    }

    // 4. Crear Inscripción en torneo_inscripciones
    $checkTI = $conn->query("SHOW TABLES LIKE 'torneo_inscripciones'");
    $inscripcionId = 0;
    if ($checkTI && $checkTI->num_rows > 0 && $categoriaId > 0 && $parejaId > 0) {
        $stmtInsI = $conn->prepare("
            INSERT INTO torneo_inscripciones (torneo_id, categoria_id, pareja_id, pagado, validado, created_at)
            VALUES (?, ?, ?, 0, 0, NOW())
        ");
        if ($stmtInsI) {
            $stmtInsI->bind_param("iii", $torneoId, $categoriaId, $parejaId);
            $stmtInsI->execute();
            $inscripcionId = $conn->insert_id;
            $stmtInsI->close();
        }
    }

    // 5. Notificar al Administrador del Club
    if ($clubAdminId > 0) {
        $titAdmin = "🏆 Nueva Pareja Inscrita en Torneo";
        $msgAdmin = "Se ha inscrito la pareja '$nombrePareja' ($j1Nombre y $j2Nombre) al torneo $torneoNombre. Tel: $j1Telefono.";
        @notifyUser($conn, $clubAdminId, $titAdmin, $msgAdmin, 'nueva_inscripcion_torneo', null, [
            'route' => '/torneos',
            'torneo_id' => (string)$torneoId
        ]);
    }

    // 6. Enviar Email al Jugador 1
    if (!empty($j1Email) && filter_var($j1Email, FILTER_VALIDATE_EMAIL)) {
        try {
            $asunto = "🏆 Inscripción Recibida: $torneoNombre - $clubNombre";
            $cuerpo = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #0f172a; color: #ffffff; border-radius: 12px;'>
                    <h2 style='color: #ccff00;'>¡Inscripción Registrada! 🏆</h2>
                    <p>Hola <strong>$j1Nombre</strong>, tu inscripción junto a <strong>$j2Nombre</strong> ha sido recibida con éxito.</p>
                    <div style='background: #1e293b; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p><strong>Torneo:</strong> $torneoNombre</p>
                        <p><strong>Club:</strong> $clubNombre</p>
                        <p><strong>Pareja:</strong> $nombrePareja</p>
                        <p><strong>Valor:</strong> $" . number_format($precioTorneo, 0, ',', '.') . " CLP</p>
                    </div>
                    <p style='color: #94a3b8; font-size: 13px;'>La organización del torneo validará tu inscripción y te notificará los horarios de los partidos.</p>
                </div>
            ";
            @sendEmail($j1Email, $asunto, $cuerpo, $j1Nombre);
        } catch (Throwable $tMail) {}
    }

    // 7. Enlace de WhatsApp
    $waUrl = null;
    if (!empty($clubTelefono)) {
        $cleanPhone = preg_replace('/[^0-9]/', '', $clubTelefono);
        $waMsg = "Hola $clubNombre! Acabo de inscribir a la pareja '$nombrePareja' ($j1Nombre y $j2Nombre) en el torneo '$torneoNombre'.";
        $waUrl = "https://wa.me/{$cleanPhone}?text=" . urlencode($waMsg);
    }

    echo json_encode([
        "status" => "success",
        "message" => "¡Inscripción registrada con éxito! La organización del club ha sido notificada.",
        "detalle" => [
            "torneo" => $torneoNombre,
            "club" => $clubNombre,
            "pareja" => $nombrePareja,
            "jugador1" => $j1Nombre,
            "jugador2" => $j2Nombre,
            "whatsapp_url" => $waUrl
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error interno al procesar la inscripción: " . $e->getMessage()
    ]);
}
