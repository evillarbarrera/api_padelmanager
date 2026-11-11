<?php
/**
 * PadelBlox - Consulta Pública de Disponibilidad de Canchas por Club
 * Endpoint público y sin autenticación requerida (para links de reserva directa).
 * 
 * Uso:
 * GET /api/clubes/get_disponibilidad_publica.php?slug=powerpadel&fecha=2026-11-11
 * GET /api/clubes/get_disponibilidad_publica.php?club_id=1&fecha=2026-11-11
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, x-authorization, X-Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once "../db.php";

$slug = trim($_GET['slug'] ?? '');
$clubId = intval($_GET['club_id'] ?? 0);
$fecha = trim($_GET['fecha'] ?? '');

// Validar y normalizar fecha
if (empty($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}

if (empty($slug) && $clubId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Parámetro 'slug' o 'club_id' es requerido."]);
    exit;
}

try {
    // 1. Resolver el Club
    $club = null;
    if ($clubId > 0) {
        $stmtClub = $conn->prepare("SELECT id, nombre, landing_slug, telefono, landing_whatsapp, landing_color_primary, landing_color_accent, logo_url, direccion, comuna, region FROM clubes WHERE id = ? LIMIT 1");
        $stmtClub->bind_param("i", $clubId);
        $stmtClub->execute();
        $club = $stmtClub->get_result()->fetch_assoc();
        $stmtClub->close();
    } elseif (!empty($slug)) {
        $slugNum = is_numeric($slug) ? intval($slug) : 0;
        $cleanSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $slug));

        $stmtClub = $conn->prepare("
            SELECT id, nombre, landing_slug, telefono, landing_whatsapp, landing_color_primary, landing_color_accent, logo_url, direccion, comuna, region 
            FROM clubes 
            WHERE LOWER(landing_slug) = LOWER(?) 
               OR (id = ? AND ? > 0)
               OR LOWER(REPLACE(nombre, ' ', '-')) = LOWER(?)
               OR LOWER(REPLACE(REPLACE(nombre, ' ', ''), '-', '')) = ?
            LIMIT 1
        ");
        $stmtClub->bind_param("siiss", $slug, $slugNum, $slugNum, $slug, $cleanSlug);
        $stmtClub->execute();
        $club = $stmtClub->get_result()->fetch_assoc();
        $stmtClub->close();

        // Fallback con LIKE
        if (!$club && !empty($cleanSlug)) {
            $likePattern = "%" . $cleanSlug . "%";
            $stmtLike = $conn->prepare("
                SELECT id, nombre, landing_slug, telefono, landing_whatsapp, landing_color_primary, landing_color_accent, logo_url, direccion, comuna, region 
                FROM clubes 
                WHERE LOWER(REPLACE(REPLACE(nombre, ' ', ''), '-', '')) LIKE ?
                   OR LOWER(nombre) LIKE ?
                LIMIT 1
            ");
            $likeRaw = "%" . strtolower($slug) . "%";
            $stmtLike->bind_param("ss", $likePattern, $likeRaw);
            $stmtLike->execute();
            $club = $stmtLike->get_result()->fetch_assoc();
            $stmtLike->close();
        }

        // Fallback general si hay clubes
        if (!$club) {
            $resF = $conn->query("SELECT id, nombre, landing_slug, telefono, landing_whatsapp, landing_color_primary, landing_color_accent, logo_url, direccion, comuna, region FROM clubes ORDER BY id ASC LIMIT 1");
            if ($resF && $resF->num_rows > 0) {
                $club = $resF->fetch_assoc();
            }
        }
    }

    if (!$club) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Club no encontrado"]);
        exit;
    }

    $cid = intval($club['id']);
    $hoySantiago = date('Y-m-d');
    $horaActualSantiago = date('H:i');
    $esHoy = ($fecha === $hoySantiago);

    // 2. Obtener Canchas activas del Club
    $canchas = [];
    $stmtC = $conn->prepare("
        SELECT id, nombre, tipo, techada, superficie, precio_hora, precio_60, precio_90, precio_120, 
               precio_60_alto, precio_90_alto, precio_120_alto, hora_inicio_bajo, hora_fin_bajo, 
               hora_inicio_alto, hora_fin_alto, horarios_adicionales, categoria, icono, descripcion, capacidad 
        FROM canchas 
        WHERE club_id = ? AND activa = 1 
        ORDER BY categoria ASC, nombre ASC
    ");
    $stmtC->bind_param("i", $cid);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($row = $resC->fetch_assoc()) {
        $canchas[] = $row;
    }
    $stmtC->close();

    // 3. Obtener todas las reservas activas para esta fecha en este club
    $reservasExistentes = []; // [ cancha_id => [ [inicio, fin] ] ]
    $stmtR = $conn->prepare("
        SELECT r.cancha_id, r.hora_inicio, r.hora_fin, r.estado
        FROM reservas_cancha r
        JOIN canchas c ON r.cancha_id = c.id
        WHERE c.club_id = ? 
          AND r.fecha = ?
          AND LOWER(COALESCE(r.estado, '')) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada')
    ");
    if ($stmtR) {
        $stmtR->bind_param("is", $cid, $fecha);
        $stmtR->execute();
        $resR = $stmtR->get_result();
        while ($r = $resR->fetch_assoc()) {
            $cId = intval($r['cancha_id']);
            $hIni = substr($r['hora_inicio'], 0, 5);
            $hFin = substr($r['hora_fin'], 0, 5);
            if ($hFin === '00:00' || $hFin === '23:59') $hFin = '24:00';
            $reservasExistentes[$cId][] = [
                'inicio' => $hIni,
                'fin' => $hFin
            ];
        }
        $stmtR->close();
    }

    // 4. Obtener también reservas de entrenadores/clases si existen
    $checkClases = $conn->query("SHOW TABLES LIKE 'reservas'");
    if ($checkClases && $checkClases->num_rows > 0) {
        $stmtClases = $conn->prepare("
            SELECT r.cancha_id, r.hora_inicio, r.hora_fin 
            FROM reservas r
            JOIN canchas c ON r.cancha_id = c.id
            WHERE c.club_id = ? AND r.fecha = ? AND (r.estado IS NULL OR r.estado NOT IN ('cancelada', 'cancelado'))
        ");
        if ($stmtClases) {
            $stmtClases->bind_param("is", $cid, $fecha);
            $stmtClases->execute();
            $resClases = $stmtClases->get_result();
            while ($cl = $resClases->fetch_assoc()) {
                $cId = intval($cl['cancha_id']);
                if ($cId > 0) {
                    $reservasExistentes[$cId][] = [
                        'inicio' => substr($cl['hora_inicio'], 0, 5),
                        'fin' => substr($cl['hora_fin'], 0, 5)
                    ];
                }
            }
            $stmtClases->close();
        }
    }

    // 5. Generar la parrilla de slots para cada cancha
    $canchasConSlots = [];

    // Duración estándar para pádel: 90 minutos (con opción 60 si está configurado)
    $duracionDefecto = 90; // minutos

    foreach ($canchas as $cancha) {
        $cId = intval($cancha['id']);
        $p90 = floatval($cancha['precio_90'] ?: ($cancha['precio_hora'] ? $cancha['precio_hora'] * 1.5 : 20000));
        $p90Alto = floatval($cancha['precio_90_alto'] ?: $p90);
        $p60 = floatval($cancha['precio_60'] ?: ($cancha['precio_hora'] ?: 15000));
        $p60Alto = floatval($cancha['precio_60_alto'] ?: $p60);

        $hInicioAlto = !empty($cancha['hora_inicio_alto']) ? substr($cancha['hora_inicio_alto'], 0, 5) : '18:00';

        // Horarios de apertura y cierre (por defecto 08:00 a 23:30)
        $horaApertura = '08:00';
        $horaCierre = '23:30';

        if (!empty($cancha['hora_inicio_bajo'])) {
            $horaApertura = substr($cancha['hora_inicio_bajo'], 0, 5);
        }

        // Generar intervalos regulares de 90 minutos y también desplazados de 60 si aplica
        // Para mayor cobertura de disponibilidad, generamos turnos cada 90 min (08:30, 10:00, 11:30, 13:00, 14:30, 16:00, 17:30, 19:00, 20:30, 22:00)
        $slots = [];
        $tActual = strtotime("$fecha $horaApertura:00");
        $tFinDia = strtotime("$fecha $horaCierre:00");

        // Si empieza a las 08:00, los bloques naturales son 08:00-09:30, 09:30-11:00, etc. o cada hora.
        // Utilizaremos bloques de 90 minutos que es el estándar chileno de pádel:
        $stepMinutos = 90;
        
        while ($tActual + ($stepMinutos * 60) <= $tFinDia + 1800) {
            $hIniSlot = date('H:i', $tActual);
            $hFinSlot = date('H:i', $tActual + ($stepMinutos * 60));

            // Determinar si este horario choca con alguna reserva existente
            $ocupado = false;
            $motivo = null;

            if (isset($reservasExistentes[$cId])) {
                foreach ($reservasExistentes[$cId] as $resItem) {
                    $rIni = $resItem['inicio'];
                    $rFin = $resItem['fin'];

                    // Hay colisión si: inicio_slot < fin_reserva Y fin_slot > inicio_reserva
                    if ($hIniSlot < $rFin && $hFinSlot > $rIni) {
                        $ocupado = true;
                        $motivo = "Ocupado";
                        break;
                    }
                }
            }

            // Si es hoy y la hora ya pasó
            $esPasado = false;
            if ($esHoy && $hIniSlot <= $horaActualSantiago) {
                $esPasado = true;
                if (!$ocupado) {
                    $ocupado = true;
                    $motivo = "Horario pasado";
                }
            }

            // Calcular precio según horario pico
            $esPico = ($hIniSlot >= $hInicioAlto);
            $precioSlot = $esPico ? $p90Alto : $p90;

            $slots[] = [
                "hora_inicio" => $hIniSlot,
                "hora_fin" => $hFinSlot,
                "duracion" => $stepMinutos,
                "disponible" => !$ocupado,
                "precio" => intval($precioSlot),
                "precio_formateado" => "$" . number_format($precioSlot, 0, ',', '.'),
                "es_pico" => $esPico,
                "es_pasado" => $esPasado,
                "motivo" => $motivo
            ];

            // Avanzar al siguiente bloque
            $tActual += ($stepMinutos * 60);
        }

        $canchasConSlots[] = [
            "id" => $cId,
            "nombre" => $cancha['nombre'],
            "tipo" => $cancha['tipo'] ?: 'Outdoor',
            "techada" => intval($cancha['techada']) === 1,
            "superficie" => $cancha['superficie'] ?: 'Césped Sintético',
            "categoria" => $cancha['categoria'] ?: 'cancha_padel',
            "icono" => $cancha['icono'] ?: '🎾',
            "precio_desde" => intval($p90),
            "slots_disponibles_count" => count(array_filter($slots, function($s) { return $s['disponible']; })),
            "slots" => $slots
        ];
    }

    echo json_encode([
        "status" => "success",
        "club" => [
            "id" => $cid,
            "nombre" => $club['nombre'],
            "slug" => $club['landing_slug'] ?: "club-$cid",
            "telefono" => $club['telefono'],
            "whatsapp" => $club['landing_whatsapp'] ?: $club['telefono'],
            "color_primary" => $club['landing_color_primary'] ?: '#0b0f19',
            "color_accent" => $club['landing_color_accent'] ?: '#ccff00',
            "logo_url" => $club['logo_url'],
            "direccion" => $club['direccion'],
            "comuna" => $club['comuna'],
            "region" => $club['region']
        ],
        "fecha" => $fecha,
        "es_hoy" => $esHoy,
        "total_canchas" => count($canchasConSlots),
        "canchas" => $canchasConSlots
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error interno al consultar disponibilidad: " . $e->getMessage()
    ]);
}
