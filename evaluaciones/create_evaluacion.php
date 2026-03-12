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

require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);

$jugador_id = $input['jugador_id'] ?? 0;
$entrenador_id = $input['entrenador_id'] ?? 0; // Debería venir del token, pero por simplicidad lo aceptamos aquí
$scores = $input['scores'] ?? []; // Array de objetos { golpe: 'Derecha', tecnica: 8, ... }
$comentarios = $input['comentarios'] ?? '';
$fecha = date('Y-m-d');

if (!$jugador_id || !$entrenador_id || empty($scores)) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos obligatorios"]);
    exit;
}

// Calcular Promedio General
$total_puntos = 0;
$total_items = 0;

// Estructura esperada de scores:
// { "Derecha": { "tecnica": 8, "control": 7 ... }, "Reves": ... }
// O Array: [ { "golpe": "Derecha", "tecnica": 8 ... } ]

// Normalizamos a JSON string para guardar
$scores_json = json_encode($scores);

// Calculamos promedio simple sumando todo
foreach ($scores as $golpe => $metricas) {
    // Si viene como objeto clave-valor
    if (is_array($metricas)) {
        foreach ($metricas as $k => $v) {
            if (is_numeric($v)) {
                $total_puntos += $v;
                $total_items++;
            }
        }
    }
}

$promedio = ($total_items > 0) ? round($total_puntos / $total_items, 2) : 0;

$sql = "INSERT INTO evaluaciones (jugador_id, entrenador_id, fecha, scores, promedio_general, comentarios) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iissds", $jugador_id, $entrenador_id, $fecha, $scores_json, $promedio, $comentarios);

if ($stmt->execute()) {
    $eval_id = $conn->insert_id;

    // --- NOTIFICATIONS ---
    require_once "../system/mail_service.php";

    // Obtener detalles para el correo
    $sqlDetails = "
        SELECT 
            u1.nombre as nom_jugador, u1.usuario as email_jugador,
            u2.nombre as nom_entrenador
        FROM usuarios u1
        JOIN usuarios u2 ON u2.id = ?
        WHERE u1.id = ?
    ";
    $stmtDetails = $conn->prepare($sqlDetails);
    $stmtDetails->bind_param("ii", $entrenador_id, $jugador_id);
    $stmtDetails->execute();
    $details = $stmtDetails->get_result()->fetch_assoc();

    if ($details) {
        $nomJugador = $details['nom_jugador'];
        $emailJugador = $details['email_jugador'];
        $nomEntrenador = $details['nom_entrenador'];

        $subject = "📊 Tu Evaluación de Padel - $fecha";
        
        // Soporte para imagen del gráfico (base64)
        $chartImgHtml = "";
        if (isset($input['chart_image'])) {
            $chartImgHtml = "<div style='margin-top: 20px; text-align: center;'><img src='" . $input['chart_image'] . "' style='max-width: 100%; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);' /></div>";
        }

        $body = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 15px; overflow: hidden;'>
            <div style='background: #111; padding: 30px; text-align: center;'>
                <h1 style='color: #fff; margin: 0;'>📊 Informe de Evaluación</h1>
            </div>
            <div style='padding: 30px;'>
                <p>Hola <strong>$nomJugador</strong>,</p>
                <p>Tu entrenador <strong>$nomEntrenador</strong> ha registrado una nueva evaluación de tu desempeño en pista.</p>
                
                <div style='background: #f8fafc; padding: 25px; border-radius: 12px; margin: 20px 0; border: 1px solid #e2e8f0;'>
                    <h3 style='margin-top: 0; color: #1e293b; border-bottom: 2px solid #3b82f6; display: inline-block; padding-bottom: 5px;'>Resumen General</h3>
                    <p style='font-size: 24px; font-weight: 800; color: #3b82f6; margin: 15px 0;'>Nota Promedio: $promedio</p>
                    <p style='font-style: italic; color: #64748b;'>\"$comentarios\"</p>
                </div>

                <h3 style='color: #1e293b;'>Detalle por Golpe</h3>
                <div style='margin: 20px 0;'>";

        foreach ($scores as $golpe => $metrics) {
            $avg_golpe = ((isset($metrics['tecnica']) ? $metrics['tecnica'] : 0) + 
                          (isset($metrics['control']) ? $metrics['control'] : 0) + 
                          (isset($metrics['direccion']) ? $metrics['direccion'] : 0) + 
                          (isset($metrics['decision']) ? $metrics['decision'] : 0)) / 4;
            
            $body .= "
            <div style='margin-bottom: 15px; padding: 15px; background: #fff; border: 1px solid #eee; border-radius: 10px;'>
                <div style='display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f0; padding-bottom: 5px; margin-bottom: 8px;'>
                    <strong style='font-size: 16px; color: #111;'>$golpe</strong>
                    <span style='background: #ccff00; color: #000; padding: 2px 8px; border-radius: 10px; font-weight: bold; font-size: 14px;'>$avg_golpe</span>
                </div>
                <div style='font-size: 13px; color: #666;'>
                    Técnica: {$metrics['tecnica']} | Control: {$metrics['control']} | Dierección: {$metrics['direccion']} | Decisión: {$metrics['decision']}
                </div>";
            
            if (!empty($metrics['comentario'])) {
                $body .= "<div style='margin-top: 8px; font-style: italic; color: #333; font-size: 13px; border-left: 3px solid #ccff00; padding-left: 10px;'>\"{$metrics['comentario']}\"</div>";
            }
            
            $body .= "</div>";
        }

        $body .= "
                </div>

                $chartImgHtml

                <p style='margin-top: 30px;'>Puedes ver el detalle completo de tus métricas y evolución histórica en la aplicación.</p>
                
                <hr style='border: 0; border-top: 1px solid #eee; margin: 25px 0;'>
                <p style='font-size: 12px; color: #94a3b8; text-align: center;'>Padel Manager - Academia de Entrenamiento</p>
            </div>
        </div>";

        if (!empty($emailJugador)) {
            enviarCorreoSMTP($emailJugador, $subject, $body);
        }

        // --- PUSH NOTIFICATION ---
        require_once "../notifications/notificaciones_helper.php";
        $tituloPush = "📊 Nueva Evaluación Disponible";
        $mensajePush = "Tu entrenador $nomEntrenador ha subido tu evaluación técnica. ¡Revísala ahora!";
        notifyUser($conn, $jugador_id, $tituloPush, $mensajePush, 'nueva_evaluacion');
    }

    echo json_encode(["success" => true, "id" => $eval_id, "promedio" => $promedio]);
} else {
    http_response_code(500);
    echo json_encode(["error" => $stmt->error]);
}
