<?php
/**
 * PadelBlox - Consulta Pública de Disponibilidad de Canchas por Club
 * Endpoint público y sin autenticación requerida (para links de reserva directa).
 * 
 * Uso:
 * GET /api/clubes/get_disponibilidad_publica.php?slug=powerpadel&fecha=2026-11-11
 * GET /api/clubes/get_disponibilidad_publica.php?club_id=17&fecha=2026-11-11
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
    // 0. Auto-asegurar columnas en tabla clubes si no existen
    $columns = [
        "landing_slug" => "VARCHAR(100) DEFAULT NULL",
        "landing_publicado" => "TINYINT(1) DEFAULT 0",
        "landing_descripcion" => "TEXT DEFAULT NULL",
        "landing_banner" => "VARCHAR(255) DEFAULT NULL",
        "landing_whatsapp" => "VARCHAR(50) DEFAULT NULL",
        "landing_instagram" => "VARCHAR(100) DEFAULT NULL",
        "landing_facebook" => "VARCHAR(100) DEFAULT NULL",
        "landing_color_primary" => "VARCHAR(20) DEFAULT '#0f172a'",
        "landing_color_accent" => "VARCHAR(20) DEFAULT '#ccff00'",
        "landing_mostrar_reservas" => "TINYINT(1) DEFAULT 1",
        "landing_mostrar_americanos" => "TINYINT(1) DEFAULT 1",
        "landing_mostrar_ranking" => "TINYINT(1) DEFAULT 1"
    ];
    foreach ($columns as $col => $type) {
        $check = $conn->query("SHOW COLUMNS FROM clubes LIKE '$col'");
        if ($check && $check->num_rows === 0) {
            @$conn->query("ALTER TABLE clubes ADD COLUMN `$col` $type");
        }
    }

    // 1. Resolver el Club
    $club = null;
    if ($clubId > 0) {
        $stmtClub = $conn->prepare("SELECT * FROM clubes WHERE id = ? LIMIT 1");
        if ($stmtClub) {
            $stmtClub->bind_param("i", $clubId);
            $stmtClub->execute();
            $club = $stmtClub->get_result()->fetch_assoc();
            $stmtClub->close();
        }
    }

    if (!$club && !empty($slug)) {
        $slugNum = is_numeric($slug) ? intval($slug) : 0;
        $cleanSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $slug));

        $stmtClub = $conn->prepare("
            SELECT * FROM clubes 
            WHERE LOWER(COALESCE(landing_slug, '')) = LOWER(?) 
               OR (id = ? AND ? > 0)
               OR LOWER(REPLACE(nombre, ' ', '-')) = LOWER(?)
               OR LOWER(REPLACE(REPLACE(nombre, ' ', ''), '-', '')) = ?
            LIMIT 1
        ");
        if ($stmtClub) {
            $stmtClub->bind_param("siiss", $slug, $slugNum, $slugNum, $slug, $cleanSlug);
            $stmtClub->execute();
            $club = $stmtClub->get_result()->fetch_assoc();
            $stmtClub->close();
        }

        // Fallback con LIKE
        if (!$club && !empty($cleanSlug)) {
            $likePattern = "%" . $cleanSlug . "%";
            $stmtLike = $conn->prepare("
                SELECT * FROM clubes 
                WHERE LOWER(REPLACE(REPLACE(nombre, ' ', ''), '-', '')) LIKE ?
                   OR LOWER(nombre) LIKE ?
                LIMIT 1
            ");
            if ($stmtLike) {
                $likeRaw = "%" . strtolower($slug) . "%";
                $stmtLike->bind_param("ss", $likePattern, $likeRaw);
                $stmtLike->execute();
                $club = $stmtLike->get_result()->fetch_assoc();
                $stmtLike->close();
            }
        }

        // Fallback general si hay clubes
        if (!$club) {
            $resF = $conn->query("SELECT * FROM clubes ORDER BY id ASC LIMIT 1");
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

    // 2. Obtener Canchas del Club (usar SELECT * para evitar fallos de columnas opcionales)
    $canchas = [];
    $stmtC = $conn->prepare("SELECT * FROM canchas WHERE club_id = ? AND (activa = 1 OR activa IS NULL) ORDER BY id ASC");
    if ($stmtC) {
        $stmtC->bind_param("i", $cid);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        while ($row = $resC->fetch_assoc()) {
            $canchas[] = $row;
        }
        $stmtC->close();
    }

    // Fallback: si no encontró con activa = 1 o NULL, buscar cualquier cancha del club
    if (empty($canchas)) {
        $stmtC2 = $conn->prepare("SELECT * FROM canchas WHERE club_id = ? ORDER BY id ASC");
        if ($stmtC2) {
            $stmtC2->bind_param("i", $cid);
            $stmtC2->execute();
            $resC2 = $stmtC2->get_result();
            while ($row2 = $resC2->fetch_assoc()) {
                $canchas[] = $row2;
            }
            $stmtC2->close();
        }
    }

    // 3. Obtener todas las reservas activas para esta fecha en este club
    $reservasExistentes = []; // [ cancha_id => [ [inicio, fin] ] ]
    $checkRC = $conn->query("SHOW TABLES LIKE 'reservas_cancha'");
    if ($checkRC && $checkRC->num_rows > 0) {
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
    $stepMinutos = 90; // Estándar de pádel chileno

    foreach ($canchas as $cancha) {
        $cId = intval($cancha['id']);
        $p90 = floatval(!empty($cancha['precio_90']) ? $cancha['precio_90'] : (!empty($cancha['precio_hora']) ? $cancha['precio_hora'] * 1.5 : 20000));
        $p90Alto = floatval(!empty($cancha['precio_90_alto']) ? $cancha['precio_90_alto'] : $p90);

        $hInicioAlto = !empty($cancha['hora_inicio_alto']) ? substr($cancha['hora_inicio_alto'], 0, 5) : '18:00';
        $horaApertura = !empty($cancha['hora_inicio_bajo']) ? substr($cancha['hora_inicio_bajo'], 0, 5) : '08:30';
        $horaCierre = '23:30';

        $slots = [];
        $tActual = strtotime("$fecha $horaApertura:00");
        $tFinDia = strtotime("$fecha $horaCierre:00");

        while ($tActual + ($stepMinutos * 60) <= $tFinDia + 1800) {
            $hIniSlot = date('H:i', $tActual);
            $hFinSlot = date('H:i', $tActual + ($stepMinutos * 60));

            $ocupado = false;
            $motivo = null;

            if (isset($reservasExistentes[$cId])) {
                foreach ($reservasExistentes[$cId] as $resItem) {
                    $rIni = $resItem['inicio'];
                    $rFin = $resItem['fin'];

                    if ($hIniSlot < $rFin && $hFinSlot > $rIni) {
                        $ocupado = true;
                        $motivo = "Ocupado";
                        break;
                    }
                }
            }

            $esPasado = false;
            if ($esHoy && $hIniSlot <= $horaActualSantiago) {
                $esPasado = true;
                if (!$ocupado) {
                    $ocupado = true;
                    $motivo = "Horario pasado";
                }
            }

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

            $tActual += ($stepMinutos * 60);
        }

        $canchasConSlots[] = [
            "id" => $cId,
            "nombre" => $cancha['nombre'],
            "tipo" => !empty($cancha['tipo']) ? $cancha['tipo'] : 'Outdoor',
            "techada" => intval($cancha['techada'] ?? 0) === 1,
            "superficie" => !empty($cancha['superficie']) ? $cancha['superficie'] : 'Césped Sintético',
            "categoria" => !empty($cancha['categoria']) ? $cancha['categoria'] : 'cancha_padel',
            "icono" => !empty($cancha['icono']) ? $cancha['icono'] : '🎾',
            "precio_desde" => intval($p90),
            "slots_disponibles_count" => count(array_filter($slots, function($s) { return $s['disponible']; })),
            "slots" => $slots
        ];
    }

    // Normalizar logo_url si existe 'logo'
    $rawLogo = !empty($club['logo']) ? $club['logo'] : (!empty($club['logo_url']) ? $club['logo_url'] : null);
    $clubLogoUrl = null;
    if ($rawLogo) {
        if (strpos($rawLogo, 'http') === 0 || strpos($rawLogo, 'assets/') === 0) {
            $clubLogoUrl = $rawLogo;
        } else {
            $cleanPath = ltrim($rawLogo, '/');
            $cleanPath = preg_replace('/^(prd|dev)\//', '', $cleanPath);
            if (strpos($cleanPath, '/') === false) {
                $cleanPath = "uploads/clubes/" . $cleanPath;
            }
            $clubLogoUrl = "https://api.padelmanager.cl/prd/" . $cleanPath;
        }
    }

    echo json_encode([
        "status" => "success",
        "club" => [
            "id" => $cid,
            "nombre" => $club['nombre'],
            "slug" => !empty($club['landing_slug']) ? $club['landing_slug'] : "club-$cid",
            "telefono" => $club['telefono'] ?? '',
            "whatsapp" => !empty($club['landing_whatsapp']) ? $club['landing_whatsapp'] : ($club['telefono'] ?? ''),
            "color_primary" => !empty($club['landing_color_primary']) ? $club['landing_color_primary'] : '#0b0f19',
            "color_accent" => !empty($club['landing_color_accent']) ? $club['landing_color_accent'] : '#ccff00',
            "logo_url" => $clubLogoUrl,
            "logo" => $club['logo'] ?? '',
            "direccion" => $club['direccion'] ?? '',
            "comuna" => $club['comuna'] ?? '',
            "region" => $club['region'] ?? ''
        ],
        "fecha" => $fecha,
        "es_hoy" => $esHoy,
        "total_canchas" => count($canchasConSlots),
        "canchas" => $canchasConSlots
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(200); // Retornar 200 con status error para no bloquear y diagnosticar claramente
    echo json_encode([
        "status" => "error",
        "message" => "Error al consultar disponibilidad: " . $e->getMessage(),
        "line" => $e->getLine(),
        "file" => basename($e->getFile())
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
