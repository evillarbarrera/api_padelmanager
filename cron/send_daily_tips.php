<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header("Content-Type: application/json; charset=UTF-8");
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

function daily_tips_log($msg) {
    $file = __DIR__ . '/daily_tips.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts] $msg" . PHP_EOL, FILE_APPEND);
}

try {
    require_once __DIR__ . "/../db.php";
    require_once __DIR__ . "/../notifications/fcm_sender.php";
    require_once __DIR__ . "/../notifications/notificaciones_helper.php";

    $hoy = date('Y-m-d');
    $currentHour = (int)date('H');

    // 0. Asegurar existencia de la tabla
    @$conn->query("CREATE TABLE IF NOT EXISTS tips_diarios_ia (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        fecha DATE, 
        titulo VARCHAR(255), 
        mensaje TEXT, 
        posicion TINYINT DEFAULT 1, 
        UNIQUE KEY unique_fecha_pos (fecha, posicion)
    )");

    // 1. Obtener o generar los Tips de IA de hoy directamente
    if (!function_exists('getTipsDirectly')) {
        function getTipsDirectly($conn, $hoy) {
            // Primero buscar en caché
            $sql = "SELECT titulo, mensaje, posicion FROM tips_diarios_ia WHERE fecha = '$hoy' ORDER BY posicion ASC";
            $res = $conn->query($sql);
            if ($res && $res->num_rows >= 1) {
                $tips = [];
                while($row = $res->fetch_assoc()) { $tips[] = $row; }
                return $tips;
            }

            // SI NO HAY TIPS, GENERARLOS CON GEMINI
            $GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";
            $seed = rand(1, 1000);
            $dayOfWeek = date('l'); $dayNum = date('j'); $month = date('F');
            $prompt = "Actúa como un experto entrenador de Pádel profesional (WPT style). Hoy es $dayOfWeek $dayNum de $month.
Genera exactamente 2 consejos técnicos de pádel TOTALMENTE ÚNICOS, INNOVADORES y PRÁCTICOS para hoy. 
Semilla de aleatoriedad: $seed.
Formato estricto (una línea por consejo):
Emoji Titulo Corto | Consejo técnico de 1-2 frases máximo.
Solo devuelve las 2 líneas.";

            // Intentar con modelo flash-latest
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $GEMINI_API_KEY;
            $data = ["contents" => [["parts" => [["text" => $prompt]]]]];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $tips = [];
            $resAI = json_decode($response, true);
            if ($httpCode === 200 && isset($resAI['candidates'][0]['content']['parts'][0]['text'])) {
                $lines = explode("\n", trim($resAI['candidates'][0]['content']['parts'][0]['text']));
                $p = 1;
                foreach($lines as $l) {
                    $l = trim($l); if (empty($l)) continue;
                    $l = preg_replace('/^[\*\-\d\.]+\s*/', '', $l);
                    if(strpos($l, '|') !== false) {
                        $parts = explode("|", $l, 2);
                        $tips[] = ["titulo" => trim($parts[0]), "mensaje" => trim($parts[1]), "posicion" => $p];
                        $p++; if($p > 2) break;
                    }
                }
            }

            // Fallback: amplio repertorio rotativo si la IA no responde
            if (count($tips) < 1) {
                $pool = [
                    ["titulo" => "⚡ Volea de Bloqueo", "mensaje" => "No hagas backswing en la red. Deja que la potencia del rival rebote en tu pala firme."],
                    ["titulo" => "🥎 Chiquita a los pies", "mensaje" => "Usa la chiquita cuando el rival esté pegado a la red para obligarlos a volear bajo."],
                    ["titulo" => "📐 Bandeja a la reja", "mensaje" => "Busca la reja lateral en tu bandeja para generar rebotes impredecibles."],
                    ["titulo" => "🧠 La Táctica de la Nevera", "mensaje" => "Si un rival es superior, juega el 80% de las bolas al otro compañero."],
                    ["titulo" => "💪 Terminación del Smash", "mensaje" => "Apunta al hombro contrario en el final del golpe para maximizar el efecto top-spin."],
                    ["titulo" => "🛡️ El Globo de Rescate", "mensaje" => "Un globo alto y profundo es tu mejor herramienta para recuperar la red."],
                    ["titulo" => "🎯 Saque con Rebote Lateral", "mensaje" => "Busca que el saque bote cerca de la línea y muera en el cristal lateral."],
                    ["titulo" => "👣 Pasos de Ajuste", "mensaje" => "Usa pasos cortos y rápidos antes del impacto. La posición de pies define el golpe."],
                    ["titulo" => "🔥 Víbora Agresiva", "mensaje" => "Impacta la bola a las 2 en punto para lograr ese efecto lateral a ras de suelo."],
                    ["titulo" => "💬 Comunicación Activa", "mensaje" => "Informa a tu compañero antes de golpear: 'Uno arriba', 'Están en el fondo'."]
                ];
                $daySeed = (int)date('z') + (int)date('Y');
                srand($daySeed);
                shuffle($pool);
                $tips = [array_merge($pool[0], ["posicion" => 1]), array_merge($pool[1], ["posicion" => 2])];
            }

            // Guardar para hoy
            foreach($tips as $t) {
                $tit = $conn->real_escape_string($t['titulo']);
                $men = $conn->real_escape_string($t['mensaje']);
                $pos = (int)$t['posicion'];
                $conn->query("INSERT INTO tips_diarios_ia (fecha, titulo, mensaje, posicion) 
                              VALUES ('$hoy', '$tit', '$men', $pos) 
                              ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), mensaje = VALUES(mensaje)");
            }
            
            return $tips;
        }
    }

    $tips_disponibles = getTipsDirectly($conn, $hoy);

    if (empty($tips_disponibles)) {
        daily_tips_log("ERROR: Fallo crítico, no se pudieron obtener ni generar tips.");
        die(json_encode(["status" => "error", "message" => "Fallo crítico: No se pudieron obtener ni generar tips."]));
    }

    // 2. Determinar qué tip enviar basado en parámetro o en la hora del día
    // Si es antes de las 14:00 hrs -> Tip #1 (mañana)
    // Si es después de las 14:00 hrs -> Tip #2 (tarde)
    if (isset($_GET['pos'])) {
        $posicion_a_enviar = (int)$_GET['pos'];
    } elseif ($isCli && isset($argv[1]) && is_numeric($argv[1])) {
        $posicion_a_enviar = (int)$argv[1];
    } else {
        $posicion_a_enviar = ($currentHour >= 14) ? 2 : 1;
    }

    $tip_seleccionado = null;
    foreach ($tips_disponibles as $t) {
        if ($t['posicion'] == $posicion_a_enviar) {
            $tip_seleccionado = $t;
            break;
        }
    }

    if (!$tip_seleccionado) {
        $tip_seleccionado = $tips_disponibles[0];
    }

    $titulo_base = $tip_seleccionado['titulo'];
    $cuerpo_ia = $tip_seleccionado['mensaje'];

    // 3. Obtener destinatarios: Alumnos, Jugadores y cualquier usuario con token FCM activo
    $targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    if ($targetUserId > 0) {
        $sql = "SELECT u.id, u.nombre, u.rol FROM usuarios u WHERE u.id = $targetUserId";
    } else {
        $sql = "SELECT DISTINCT u.id, u.nombre, u.rol 
                FROM usuarios u 
                WHERE u.rol IN ('jugador', 'alumno', 'entrenador') 
                   OR u.id IN (SELECT DISTINCT user_id FROM fcm_tokens)";
    }
    $res = $conn->query($sql);

    $count_notificaciones = 0;
    $count_omitidos_duplicados = 0;
    $ultimo_mensaje = "";
    $isForce = isset($_GET['force']) && ($_GET['force'] == '1' || $_GET['force'] === 'true');

    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $jugadorId = (int)$row['id'];
            $primerNombre = explode(' ', trim($row['nombre']))[0];
            if (empty($primerNombre)) $primerNombre = "Jugador";

            // Verificar si ya se le envió este tip hoy (salvo modo force)
            if (!$isForce) {
                $refCheck = $hoy . "_pos_" . $posicion_a_enviar;
                $stDup = $conn->prepare("SELECT id FROM notificaciones 
                                         WHERE user_id = ? 
                                           AND tipo = 'daily_tip' 
                                           AND fecha_referencia = ? 
                                         LIMIT 1");
                if ($stDup) {
                    $stDup->bind_param('is', $jugadorId, $refCheck);
                    $stDup->execute();
                    $rDup = $stDup->get_result();
                    if ($rDup && $rDup->num_rows > 0) {
                        $count_omitidos_duplicados++;
                        $stDup->close();
                        continue;
                    }
                    $stDup->close();
                }
            }

            // Personalización Dinámica
            if ($posicion_a_enviar == 1) {
                $saludos = [
                    "¡Hola $primerNombre! Buen día. Tu Coach IA reportándose. 🥎",
                    "¡Buen día $primerNombre! Aquí tienes tu primer tip de hoy para mejorar en la cancha:",
                    "Hola $primerNombre, ¿listo para entrenar? Mira este consejo que tengo para ti:"
                ];
                $saludo = $saludos[array_rand($saludos)];
                $mensaje = "$saludo $cuerpo_ia";
            } else {
                $saludos = [
                    "¡Hola de nuevo $primerNombre! Si vas a jugar hoy, no olvides esto:",
                    "¿Cómo va el día $primerNombre? Aquí te dejo otro consejo clave:",
                    "¡Hola $primerNombre! Te traigo un último tip para pulir tu técnica hoy:"
                ];
                $saludo = $saludos[array_rand($saludos)];
                $mensaje = "$saludo $cuerpo_ia";
            }

            $ultimo_mensaje = $mensaje;
            $refEnvio = $hoy . "_pos_" . $posicion_a_enviar;

            if (notifyUser($conn, $jugadorId, "Consejo PadelBlox: $titulo_base", $mensaje, 'daily_tip', $refEnvio)) {
                $count_notificaciones++;
            }
        }
        
        $logInfo = "ÉXITO: Tip #$posicion_a_enviar ('$titulo_base') procesado para $count_notificaciones usuarios (Omitidos por duplicado hoy: $count_omitidos_duplicados).";
        daily_tips_log($logInfo);

        echo json_encode([
            "status" => "success", 
            "message" => "Se envió el consejo #$posicion_a_enviar a $count_notificaciones jugadores.",
            "omitidos_ya_enviados_hoy" => $count_omitidos_duplicados,
            "detalle" => ["titulo" => $titulo_base, "mensaje" => $ultimo_mensaje]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        daily_tips_log("AVISO: No se encontraron jugadores ni usuarios con tokens activos.");
        echo json_encode(["status" => "error", "message" => "No se encontraron usuarios activos"]);
    }

    if (isset($conn) && $conn) {
        $conn->close();
    }
} catch (Throwable $e) {
    daily_tips_log("ERROR EXCEPCIÓN: " . $e->getMessage());
    http_response_code(200);
    echo json_encode([
        "status" => "error",
        "message" => "Error de ejecución: " . $e->getMessage()
    ]);
}
?>
