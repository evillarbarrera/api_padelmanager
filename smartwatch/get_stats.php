<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

function ensureSmartwatchSchema($conn) {
    $sqlSesiones = "CREATE TABLE IF NOT EXISTS `smartwatch_sesiones` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `usuario_id` INT NOT NULL,
        `club_id` INT NULL DEFAULT NULL,
        `liga_partido_id` INT NULL DEFAULT NULL,
        `reserva_id` INT NULL DEFAULT NULL,
        `tipo_actividad` VARCHAR(50) NOT NULL DEFAULT 'partido',
        `mano_reloj` VARCHAR(20) NOT NULL DEFAULT 'derecha',
        `fecha_inicio` DATETIME NOT NULL,
        `fecha_fin` DATETIME NULL DEFAULT NULL,
        `duracion_segundos` INT NOT NULL DEFAULT 0,
        `calorias_quemadas` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        `fc_promedio` INT NOT NULL DEFAULT 0,
        `fc_maxima` INT NOT NULL DEFAULT 0,
        `total_golpes` INT NOT NULL DEFAULT 0,
        `smash_count` INT NOT NULL DEFAULT 0,
        `bandeja_count` INT NOT NULL DEFAULT 0,
        `vibora_count` INT NOT NULL DEFAULT 0,
        `drive_count` INT NOT NULL DEFAULT 0,
        `reves_count` INT NOT NULL DEFAULT 0,
        `volea_count` INT NOT NULL DEFAULT 0,
        `globo_count` INT NOT NULL DEFAULT 0,
        `velocidad_max_kmh` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        `velocidad_media_kmh` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        `potencia_max_g` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        `marcador_final_t1` VARCHAR(50) NULL DEFAULT NULL,
        `marcador_final_t2` VARCHAR(50) NULL DEFAULT NULL,
        `equipo_ganador` INT NULL DEFAULT NULL,
        `dispositivo_modelo` VARCHAR(100) NULL DEFAULT 'Apple Watch',
        `golpes_detalle_json` LONGTEXT NULL DEFAULT NULL,
        `fc_timeline_json` LONGTEXT NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`usuario_id`),
        INDEX (`liga_partido_id`),
        INDEX (`fecha_inicio`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($sqlSesiones);
}

$usuario_id = !empty($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
$liga_partido_id = !empty($_GET['liga_partido_id']) ? (int)$_GET['liga_partido_id'] : 0;
$reserva_id = !empty($_GET['reserva_id']) ? (int)$_GET['reserva_id'] : 0;
$sesion_id = !empty($_GET['sesion_id']) ? (int)$_GET['sesion_id'] : 0;
$limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 20;

try {
    ensureSmartwatchSchema($conn);
    // Si se consulta una sesión específica por ID
    if ($sesion_id > 0) {
        $stmt = $conn->prepare("SELECT s.*, u.nombre, u.apellido, c.nombre as club_nombre 
                                FROM smartwatch_sesiones s
                                LEFT JOIN usuarios u ON s.usuario_id = u.id
                                LEFT JOIN clubes c ON s.club_id = c.id
                                WHERE s.id = ?");
        $stmt->bind_param("i", $sesion_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            if (!empty($row['golpes_detalle_json'])) {
                $row['golpes_detalle'] = json_decode($row['golpes_detalle_json'], true);
            }
            if (!empty($row['fc_timeline_json'])) {
                $row['fc_timeline'] = json_decode($row['fc_timeline_json'], true);
            }
            echo json_encode(["success" => true, "sesion" => $row]);
            exit;
        } else {
            echo json_encode(["success" => false, "error" => "Sesión no encontrada"]);
            exit;
        }
    }

    // Si se consulta por reserva_id (partido o entrenamiento)
    if ($reserva_id > 0) {
        $stmt = $conn->prepare("SELECT s.*, u.nombre, u.apellido, c.nombre as club_nombre 
                                FROM smartwatch_sesiones s
                                LEFT JOIN usuarios u ON s.usuario_id = u.id
                                LEFT JOIN clubes c ON s.club_id = c.id
                                WHERE s.reserva_id = ?
                                ORDER BY s.id DESC");
        $stmt->bind_param("i", $reserva_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $sesiones = [];
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['golpes_detalle_json'])) {
                $row['golpes_detalle'] = json_decode($row['golpes_detalle_json'], true);
            }
            if (!empty($row['fc_timeline_json'])) {
                $row['fc_timeline'] = json_decode($row['fc_timeline_json'], true);
            }
            $sesiones[] = $row;
        }
        echo json_encode([
            "success" => true,
            "reserva_id" => $reserva_id,
            "sesion" => !empty($sesiones[0]) ? $sesiones[0] : null,
            "sesiones" => $sesiones
        ]);
        exit;
    }

    // Si se consulta por partido de liga
    if ($liga_partido_id > 0) {
        $stmt = $conn->prepare("SELECT s.*, u.nombre, u.apellido 
                                FROM smartwatch_sesiones s
                                LEFT JOIN usuarios u ON s.usuario_id = u.id
                                WHERE s.liga_partido_id = ?
                                ORDER BY s.id DESC");
        $stmt->bind_param("i", $liga_partido_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $sesiones = [];
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['golpes_detalle_json'])) {
                $row['golpes_detalle'] = json_decode($row['golpes_detalle_json'], true);
            }
            $sesiones[] = $row;
        }
        echo json_encode(["success" => true, "sesiones" => $sesiones]);
        exit;
    }

    // Consulta general por usuario: estadísticas acumuladas + historial de sesiones
    if ($usuario_id > 0) {
        // 1. Estadísticas acumuladas
        $sqlAgg = "SELECT 
            COUNT(*) as total_sesiones,
            COALESCE(SUM(duracion_segundos), 0) as total_segundos_jugados,
            COALESCE(SUM(calorias_quemadas), 0) as total_calorias,
            COALESCE(SUM(total_golpes), 0) as total_golpes,
            COALESCE(SUM(smash_count), 0) as total_smash,
            COALESCE(SUM(bandeja_count), 0) as total_bandejas,
            COALESCE(SUM(vibora_count), 0) as total_viboras,
            COALESCE(SUM(drive_count), 0) as total_drives,
            COALESCE(SUM(reves_count), 0) as total_reves,
            COALESCE(SUM(volea_count), 0) as total_voleas,
            COALESCE(SUM(globo_count), 0) as total_globos,
            COALESCE(MAX(velocidad_max_kmh), 0) as record_velocidad_kmh,
            COALESCE(AVG(NULLIF(velocidad_media_kmh, 0)), 0) as velocidad_promedio_general,
            COALESCE(AVG(NULLIF(fc_promedio, 0)), 0) as fc_promedio_general,
            COALESCE(MAX(fc_maxima), 0) as fc_maxima_historica
        FROM smartwatch_sesiones 
        WHERE usuario_id = $usuario_id";
        
        $resAgg = $conn->query($sqlAgg);
        $totales = $resAgg ? $resAgg->fetch_assoc() : [];

        // 2. Historial de sesiones recientes
        $stmtList = $conn->prepare("SELECT s.id, s.fecha_inicio, s.duracion_segundos, s.tipo_actividad,
                                           s.calorias_quemadas, s.fc_promedio, s.fc_maxima, s.total_golpes,
                                           s.smash_count, s.bandeja_count, s.vibora_count, s.drive_count,
                                           s.reves_count, s.volea_count, s.globo_count,
                                           s.velocidad_max_kmh, s.marcador_final_t1, s.marcador_final_t2,
                                           s.equipo_ganador, c.nombre as club_nombre
                                    FROM smartwatch_sesiones s
                                    LEFT JOIN clubes c ON s.club_id = c.id
                                    WHERE s.usuario_id = ?
                                    ORDER BY s.fecha_inicio DESC
                                    LIMIT ?");
        $stmtList->bind_param("ii", $usuario_id, $limit);
        $stmtList->execute();
        $resList = $stmtList->get_result();
        $historial = [];
        while ($row = $resList->fetch_assoc()) {
            $historial[] = $row;
        }

        echo json_encode([
            "success" => true,
            "usuario_id" => $usuario_id,
            "resumen_acumulado" => $totales,
            "sesiones" => $historial
        ]);
        exit;
    }

    echo json_encode(["success" => false, "error" => "Parámetro usuario_id, liga_partido_id o sesion_id requerido"]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
