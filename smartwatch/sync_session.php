<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

function ensureSmartwatchSchema($conn) {
    // 1. Tabla principal de sesiones de smartwatch
    $sqlSesiones = "CREATE TABLE IF NOT EXISTS `smartwatch_sesiones` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `usuario_id` INT NOT NULL,
        `club_id` INT NULL DEFAULT NULL,
        `liga_partido_id` INT NULL DEFAULT NULL,
        `reserva_id` INT NULL DEFAULT NULL,
        `tipo_actividad` VARCHAR(50) NOT NULL DEFAULT 'partido', -- 'partido', 'entrenamiento', 'clase', 'libre'
        `mano_reloj` VARCHAR(20) NOT NULL DEFAULT 'derecha',     -- 'derecha', 'izquierda'
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

try {
    ensureSmartwatchSchema($conn);

    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) {
        $input = $_POST;
    }

    $usuario_id = !empty($input['usuario_id']) ? (int)$input['usuario_id'] : 0;
    if ($usuario_id <= 0) {
        echo json_encode(["success" => false, "error" => "ID de usuario requerido para sincronizar sesión de Smartwatch"]);
        exit;
    }

    $club_id = !empty($input['club_id']) ? (int)$input['club_id'] : null;
    $liga_partido_id = !empty($input['liga_partido_id']) ? (int)$input['liga_partido_id'] : null;
    $reserva_id = !empty($input['reserva_id']) ? (int)$input['reserva_id'] : null;
    $tipo_actividad = !empty($input['tipo_actividad']) ? trim($input['tipo_actividad']) : 'partido';
    $mano_reloj = !empty($input['mano_reloj']) ? trim($input['mano_reloj']) : 'derecha';

    $fecha_inicio = !empty($input['fecha_inicio']) ? date('Y-m-d H:i:s', strtotime($input['fecha_inicio'])) : date('Y-m-d H:i:s');
    $fecha_fin = !empty($input['fecha_fin']) ? date('Y-m-d H:i:s', strtotime($input['fecha_fin'])) : date('Y-m-d H:i:s');
    $duracion_segundos = !empty($input['duracion_segundos']) ? (int)$input['duracion_segundos'] : 0;

    $calorias = !empty($input['calorias_quemadas']) ? (float)$input['calorias_quemadas'] : (!empty($input['calorias']) ? (float)$input['calorias'] : 0.0);
    $fc_promedio = !empty($input['fc_promedio']) ? (int)$input['fc_promedio'] : (!empty($input['avg_hr']) ? (int)$input['avg_hr'] : 0);
    $fc_maxima = !empty($input['fc_maxima']) ? (int)$input['fc_maxima'] : (!empty($input['max_hr']) ? (int)$input['max_hr'] : 0);

    // Contadores de golpes
    $smash_count = (int)($input['smash_count'] ?? 0);
    $bandeja_count = (int)($input['bandeja_count'] ?? 0);
    $vibora_count = (int)($input['vibora_count'] ?? 0);
    $drive_count = (int)($input['drive_count'] ?? 0);
    $reves_count = (int)($input['reves_count'] ?? 0);
    $volea_count = (int)($input['volea_count'] ?? 0);
    $globo_count = (int)($input['globo_count'] ?? 0);

    $total_golpes = !empty($input['total_golpes']) ? (int)$input['total_golpes'] : ($smash_count + $bandeja_count + $vibora_count + $drive_count + $reves_count + $volea_count + $globo_count);

    $velocidad_max_kmh = !empty($input['velocidad_max_kmh']) ? (float)$input['velocidad_max_kmh'] : 0.0;
    $velocidad_media_kmh = !empty($input['velocidad_media_kmh']) ? (float)$input['velocidad_media_kmh'] : 0.0;
    $potencia_max_g = !empty($input['potencia_max_g']) ? (float)$input['potencia_max_g'] : 0.0;

    $marcador_t1 = !empty($input['marcador_final_t1']) ? trim($input['marcador_final_t1']) : null;
    $marcador_t2 = !empty($input['marcador_final_t2']) ? trim($input['marcador_final_t2']) : null;
    $equipo_ganador = !empty($input['equipo_ganador']) ? (int)$input['equipo_ganador'] : null;
    $dispositivo_modelo = !empty($input['dispositivo_modelo']) ? trim($input['dispositivo_modelo']) : 'Apple Watch';

    $golpes_detalle_json = !empty($input['golpes_detalle']) ? (is_string($input['golpes_detalle']) ? $input['golpes_detalle'] : json_encode($input['golpes_detalle'])) : null;
    $fc_timeline_json = !empty($input['fc_timeline']) ? (is_string($input['fc_timeline']) ? $input['fc_timeline'] : json_encode($input['fc_timeline'])) : null;

    $stmt = $conn->prepare("INSERT INTO smartwatch_sesiones (
        usuario_id, club_id, liga_partido_id, reserva_id, tipo_actividad, mano_reloj,
        fecha_inicio, fecha_fin, duracion_segundos, calorias_quemadas, fc_promedio, fc_maxima,
        total_golpes, smash_count, bandeja_count, vibora_count, drive_count, reves_count,
        volea_count, globo_count, velocidad_max_kmh, velocidad_media_kmh, potencia_max_g,
        marcador_final_t1, marcador_final_t2, equipo_ganador, dispositivo_modelo,
        golpes_detalle_json, fc_timeline_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param("iiiissssidiiiiiiiiiidddssisss",
        $usuario_id, $club_id, $liga_partido_id, $reserva_id, $tipo_actividad, $mano_reloj,
        $fecha_inicio, $fecha_fin, $duracion_segundos, $calorias, $fc_promedio, $fc_maxima,
        $total_golpes, $smash_count, $bandeja_count, $vibora_count, $drive_count, $reves_count,
        $volea_count, $globo_count, $velocidad_max_kmh, $velocidad_media_kmh, $potencia_max_g,
        $marcador_t1, $marcador_t2, $equipo_ganador, $dispositivo_modelo,
        $golpes_detalle_json, $fc_timeline_json
    );

    if (!$stmt->execute()) {
        throw new Exception("Error al guardar sesión de Smartwatch: " . $stmt->error);
    }

    $sesion_id = $stmt->insert_id;

    // Actualizar resultado en la reserva si corresponde
    if ($reserva_id && $reserva_id > 0) {
        $marcador_final = (!empty($marcador_t1) && !empty($marcador_t2)) ? ($marcador_t1 . ' - ' . $marcador_t2) : null;
        $winner = $equipo_ganador ?? 1;
        if ($marcador_final) {
            $stmtUp = $conn->prepare("UPDATE reservas_cancha SET marcador = ?, id_ganador = ?, resultado_registrado = 1 WHERE id = ?");
            if ($stmtUp) {
                $stmtUp->bind_param("sii", $marcador_final, $winner, $reserva_id);
                $stmtUp->execute();
            }
        }
    }

    echo json_encode([
        "success" => true,
        "sesion_id" => $sesion_id,
        "reserva_id" => $reserva_id,
        "liga_partido_id" => $liga_partido_id,
        "mensaje" => "Sesión de Smartwatch sincronizada exitosamente con Padelblox",
        "resumen" => [
            "total_golpes" => $total_golpes,
            "smash" => $smash_count,
            "bandeja" => $bandeja_count,
            "vibora" => $vibora_count,
            "drive" => $drive_count,
            "reves" => $reves_count,
            "volea" => $volea_count,
            "globo" => $globo_count,
            "velocidad_max_kmh" => $velocidad_max_kmh,
            "fc_promedio" => $fc_promedio,
            "calorias" => $calorias
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
