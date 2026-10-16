<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../auth/auth_helper.php";
require_once "init_tables.php";
ensureCoachPublicProfileTables($conn);

$slug = trim($_GET['slug'] ?? '');
$entrenador_id = intval($_GET['entrenador_id'] ?? 0);
$previewMode = isset($_GET['preview']) && $_GET['preview'] === '1';

// Token opcional para detectar si el usuario logueado es el dueño o admin
$authUserId = intval(validateToken());

if (empty($slug) && $entrenador_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Parámetro 'slug' o 'entrenador_id' es requerido"]);
    exit;
}

try {
    // 1. Obtener perfil
    $sqlPerfil = "SELECT p.*, u.id as coach_id, u.nombre as coach_nombre, 
                         COALESCE(u.foto_perfil, u.foto) as coach_foto, 
                         u.usuario as coach_email, 
                         u.telefono as coach_telefono, 
                         d.comuna as coach_comuna, 
                         d.region as coach_region
                  FROM usuarios u
                  LEFT JOIN entrenador_perfil_publico p ON p.entrenador_id = u.id
                  LEFT JOIN direcciones d ON d.usuario_id = u.id
                  WHERE (LOWER(p.slug) = LOWER(?) OR (u.id = ? AND ? > 0))
                    AND u.rol = 'entrenador'
                  LIMIT 1";

    $stmt = $conn->prepare($sqlPerfil);
    if (!$stmt) {
        throw new Exception("Error al preparar consulta de perfil público: " . $conn->error);
    }
    $stmt->bind_param("sii", $slug, $entrenador_id, $entrenador_id);
    $stmt->execute();
    $resPerfil = $stmt->get_result();

    if ($resPerfil->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Entrenador no encontrado"]);
        exit;
    }

    $row = $resPerfil->fetch_assoc();
    $coachId = intval($row['entrenador_id'] ?: $row['coach_id'] ?: $entrenador_id);

    $isPublicado = isset($row['publicado']) && intval($row['publicado']) === 1;
    $isOwnerOrAdmin = ($authUserId > 0 && ($authUserId === $coachId || $authUserId === intval($row['coach_id'])));

    // Validación de modo vista previa o publicado
    if (!$isPublicado && !$previewMode && !$isOwnerOrAdmin) {
        http_response_code(403);
        echo json_encode([
            "status" => "error", 
            "message" => "El perfil de este entrenador está en pausa o aún no ha sido publicado.",
            "coach_id" => $coachId
        ]);
        exit;
    }

    // Decodificar JSON fields
    $especialidades = !empty($row['especialidades']) ? json_decode($row['especialidades'], true) : [];
    $niveles = !empty($row['niveles']) ? json_decode($row['niveles'], true) : [];
    $comunas = !empty($row['comunas']) ? json_decode($row['comunas'], true) : [];
    $clubes_ids = !empty($row['clubes_ids']) ? json_decode($row['clubes_ids'], true) : [];

    // Fallbacks si es draft
    if (empty($row['titulo'])) {
        $row['titulo'] = "Profesor de Pádel Profesional";
    }
    if (empty($row['bio'])) {
        $row['bio'] = "Clases particulares y grupales adaptadas a todos los niveles: iniciación, intermedio y competición.";
    }

    // 2. Obtener clubes donde hace clases
    $clubes = [];
    if (!empty($clubes_ids)) {
        $idsStr = implode(',', array_map('intval', $clubes_ids));
        $resClubes = $conn->query("SELECT id, nombre, direccion, comuna, region FROM clubes WHERE id IN ($idsStr)");
        if ($resClubes) {
            while ($c = $resClubes->fetch_assoc()) {
                $clubes[] = $c;
            }
        }
    }

    // 3. Obtener packs activos
    $packs = [];
    $sqlPacks = "SELECT p.*, c.nombre as club_nombre, c.comuna as club_comuna,
                        COALESCE(ig.cupos_ocupados, 0) as cupos_ocupados,
                        (p.capacidad_maxima - COALESCE(ig.cupos_ocupados, 0)) as cupos_disponibles
                 FROM packs p
                 LEFT JOIN clubes c ON c.id = p.club_id
                 LEFT JOIN (
                     SELECT pack_id, COUNT(*) as cupos_ocupados 
                     FROM inscripciones_grupales 
                     WHERE estado = 'activo' 
                     GROUP BY pack_id
                 ) ig ON ig.pack_id = p.id
                 WHERE p.entrenador_id = ? AND p.activo = 1
                   AND (p.fecha IS NULL OR p.fecha >= CURDATE())
                   AND (p.tipo != 'grupal' OR p.permite_inscripcion = 1)
                 ORDER BY p.precio ASC";
    $stmtP = $conn->prepare($sqlPacks);
    $stmtP->bind_param("i", $coachId);
    $stmtP->execute();
    $resPacks = $stmtP->get_result();
    while ($p = $resPacks->fetch_assoc()) {
        $packs[] = $p;
    }

    // 4. Obtener próximos horarios disponibles (próximos 30 días)
    $horarios = [];
    $existingReservas = [];
    $sqlResv = "SELECT fecha, hora_inicio, hora_fin FROM reservas 
                WHERE entrenador_id = ? 
                  AND fecha >= CURDATE() 
                  AND estado IN ('reservado', 'aceptado')";
    $stmtResv = $conn->prepare($sqlResv);
    if ($stmtResv) {
        $stmtResv->bind_param("i", $coachId);
        $stmtResv->execute();
        $resResv = $stmtResv->get_result();
        while ($rv = $resResv->fetch_assoc()) {
            $existingReservas[] = $rv;
        }
        $stmtResv->close();
    }

    $sqlDisp = "SELECT d.id, d.fecha_inicio, d.fecha_fin, d.club_id, c.nombre as club_nombre
                FROM disponibilidad_profesor d
                LEFT JOIN clubes c ON c.id = d.club_id
                WHERE d.profesor_id = ? AND d.activo = 1
                  AND d.fecha_inicio >= NOW()
                  AND d.fecha_inicio <= DATE_ADD(NOW(), INTERVAL 30 DAY)
                ORDER BY d.fecha_inicio ASC";
    $stmtD = $conn->prepare($sqlDisp);
    $stmtD->bind_param("i", $coachId);
    $stmtD->execute();
    $resDisp = $stmtD->get_result();
    while ($h = $resDisp->fetch_assoc()) {
        $start = strtotime($h['fecha_inicio']);
        $end = strtotime($h['fecha_fin']);
        if ($end <= $start) {
            $end = $start + 3600;
        }
        while ($start < $end) {
            $slotEnd = $start + 3600;
            $slotDate = date('Y-m-d', $start);
            $slotTime = date('H:i', $start);
            $slotTimeSec = date('H:i:s', $start);

            $isBooked = false;
            foreach ($existingReservas as $res) {
                if ($res['fecha'] === $slotDate && substr($res['hora_inicio'], 0, 5) === $slotTime) {
                    $isBooked = true;
                    break;
                }
            }

            if (!$isBooked && $start >= time()) {
                $horarios[] = [
                    "id" => $h['id'] . '_' . date('Hi', $start),
                    "fecha_inicio" => date('Y-m-d H:i:s', $start),
                    "fecha_fin" => date('Y-m-d H:i:s', $slotEnd),
                    "fecha" => $slotDate,
                    "hora" => $slotTime,
                    "club_id" => $h['club_id'],
                    "club_nombre" => $h['club_nombre']
                ];
            }
            $start = $slotEnd;
        }
    }
    $stmtD->close();

    // Fallback: Si no tiene registros en disponibilidad_profesor, usar su plantilla recurrente (entrenador_horarios_config)
    if (empty($horarios)) {
        $sqlTpl = "SELECT ehc.*, c.nombre as club_nombre 
                   FROM entrenador_horarios_config ehc
                   LEFT JOIN clubes c ON c.id = ehc.club_id
                   WHERE ehc.entrenador_id = ?
                   ORDER BY ehc.dia_semana, ehc.hora_inicio";
        $stmtT = $conn->prepare($sqlTpl);
        if ($stmtT) {
            $stmtT->bind_param("i", $coachId);
            $stmtT->execute();
            $resT = $stmtT->get_result();
            $tplConfigs = [];
            while ($tc = $resT->fetch_assoc()) {
                $tplConfigs[intval($tc['dia_semana'])][] = $tc;
            }
            $stmtT->close();

            if (!empty($tplConfigs)) {
                $now = new DateTime();
                for ($d = 0; $d < 21; $d++) {
                    $cur = clone $now;
                    $cur->modify("+$d day");
                    $dayOfWeek = intval($cur->format('w')); // 0=Dom, 1=Lun ...
                    if (isset($tplConfigs[$dayOfWeek])) {
                        $dateStr = $cur->format('Y-m-d');
                        foreach ($tplConfigs[$dayOfWeek] as $conf) {
                            $dur = intval($conf['duracion_bloque'] ?? 60);
                            if ($dur <= 0) $dur = 60;
                            $sTime = new DateTime($dateStr . ' ' . $conf['hora_inicio']);
                            $eTime = new DateTime($dateStr . ' ' . $conf['hora_fin']);
                            while ($sTime < $eTime) {
                                if ($sTime > $now) {
                                    $slotDate = $sTime->format('Y-m-d');
                                    $slotTime = $sTime->format('H:i');
                                    
                                    $isBooked = false;
                                    foreach ($existingReservas as $res) {
                                        if ($res['fecha'] === $slotDate && substr($res['hora_inicio'], 0, 5) === $slotTime) {
                                            $isBooked = true;
                                            break;
                                        }
                                    }
                                    if (!$isBooked) {
                                        $horarios[] = [
                                            "id" => "tpl_" . $slotDate . "_" . str_replace(':', '', $slotTime),
                                            "fecha_inicio" => $sTime->format('Y-m-d H:i:s'),
                                            "fecha_fin" => (clone $sTime)->modify("+$dur minutes")->format('Y-m-d H:i:s'),
                                            "fecha" => $slotDate,
                                            "hora" => $slotTime,
                                            "club_id" => $conf['club_id'],
                                            "club_nombre" => $conf['club_nombre']
                                        ];
                                    }
                                }
                                $sTime->modify("+$dur minutes");
                            }
                        }
                    }
                }
            }
        }
    }

    // Ordenar cronológicamente y limitar a 150 slots
    usort($horarios, function($a, $b) {
        return strcmp($a['fecha_inicio'], $b['fecha_inicio']);
    });
    $horarios = array_slice($horarios, 0, 150);

    // 5. Obtener reseñas verificadas
    $resenas = [];
    $sqlRes = "SELECT r.id, r.rating, r.comentario, r.respuesta_entrenador, r.fecha_respuesta, r.created_at,
                      u.nombre as alumno_nombre, COALESCE(u.foto_perfil, u.foto) as alumno_foto
               FROM resenas_entrenador r
               INNER JOIN usuarios u ON u.id = r.alumno_id
               WHERE r.entrenador_id = ? AND r.visible = 1
               ORDER BY r.created_at DESC";
    $stmtR = $conn->prepare($sqlRes);
    $stmtR->bind_param("i", $coachId);
    $stmtR->execute();
    $resRes = $stmtR->get_result();
    $totalRating = 0;
    while ($r = $resRes->fetch_assoc()) {
        $totalRating += intval($r['rating']);
        $resenas[] = $r;
    }

    $totalResenas = count($resenas);
    $promedioRating = $totalResenas > 0 ? round($totalRating / $totalResenas, 1) : 5.0;

    // 6. Obtener certificados verificados subidos
    $certificados = [];
    $sqlCerts = "SELECT id, titulo, institucion, archivo_url, tipo_archivo, orden, created_at 
                 FROM entrenador_certificados 
                 WHERE entrenador_id = ? AND activo = 1 
                 ORDER BY orden ASC, id DESC";
    $stmtC = $conn->prepare($sqlCerts);
    if ($stmtC) {
        $stmtC->bind_param("i", $coachId);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        while ($c = $resC->fetch_assoc()) {
            if (!empty($c['archivo_url']) && strpos($c['archivo_url'], 'uploads/') === 0) {
                $c['archivo_url'] = 'https://api.padelmanager.cl/prd/' . $c['archivo_url'];
            }
            $certificados[] = $c;
        }
    }

    $bannerUrl = $row['banner_url'] ?? '';
    if (!empty($bannerUrl) && strpos($bannerUrl, 'uploads/') === 0) {
        $bannerUrl = 'https://api.padelmanager.cl/prd/' . $bannerUrl;
    }
    $fondoUrl = $row['fondo_url'] ?? '';
    if (!empty($fondoUrl) && strpos($fondoUrl, 'uploads/') === 0) {
        $fondoUrl = 'https://api.padelmanager.cl/prd/' . $fondoUrl;
    }
    $coachFoto = $row['coach_foto'] ?? '';
    if (!empty($coachFoto) && strpos($coachFoto, 'uploads/') === 0) {
        $coachFoto = 'https://api.padelmanager.cl/prd/' . $coachFoto;
    }

    echo json_encode([
        "status" => "success",
        "perfil" => [
            "entrenador_id" => $coachId,
            "slug" => $row['slug'] ?? "coach-{$coachId}",
            "publicado" => $isPublicado,
            "is_publicado" => $isPublicado,
            "is_owner" => $isOwnerOrAdmin,
            "titulo" => $row['titulo'],
            "bio" => $row['bio'],
            "especialidades" => $especialidades,
            "niveles" => $niveles,
            "anios_experiencia" => intval($row['anios_experiencia'] ?? 1),
            "certificaciones" => $row['certificaciones'] ?? '',
            "comunas" => $comunas,
            "clubes_ids" => $clubes_ids,
            "banner_url" => $bannerUrl,
            "fondo_url" => $fondoUrl,
            "telefono_contacto" => $row['telefono_contacto'] ?? $row['coach_telefono'],
            "whatsapp" => $row['whatsapp'] ?? $row['coach_telefono'],
            "instagram" => $row['instagram'] ?? '',
            "facebook" => $row['facebook'] ?? '',
            "google_business_url" => $row['google_business_url'] ?? '',
            "color_primary" => $row['color_primary'] ?? '#0f172a',
            "color_accent" => $row['color_accent'] ?? '#ccff00',
            "mostrar_packs" => !isset($row['mostrar_packs']) || intval($row['mostrar_packs']) === 1,
            "mostrar_horarios" => !isset($row['mostrar_horarios']) || intval($row['mostrar_horarios']) === 1,
            "mostrar_resenas" => !isset($row['mostrar_resenas']) || intval($row['mostrar_resenas']) === 1,
            "visitas" => intval($row['visitas'] ?? 0)
        ],
        "entrenador" => [
            "id" => $coachId,
            "nombre" => $row['coach_nombre'],
            "foto" => $coachFoto,
            "email" => $row['coach_email'],
            "comuna" => $row['coach_comuna'],
            "region" => $row['coach_region']
        ],
        "clubes" => $clubes,
        "packs" => $packs,
        "horarios_disponibles" => $horarios,
        "certificados" => $certificados,
        "resenas" => $resenas,
        "estadisticas" => [
            "promedio_rating" => $promedioRating,
            "total_resenas" => $totalResenas
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
