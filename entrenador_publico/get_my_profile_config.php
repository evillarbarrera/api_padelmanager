<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../auth/auth_helper.php";
require_once "init_tables.php";
ensureCoachPublicProfileTables($conn);

$userId = intval(validateToken());
if (!$userId) {
    sendUnauthorized("Token inválido o faltante");
}

// 1. Determinar el coachId: Por defecto siempre es el usuario autenticado
$coachId = $userId;
if (!empty($_GET['entrenador_id']) && intval($_GET['entrenador_id']) > 0) {
    $requestedId = intval($_GET['entrenador_id']);
    if ($requestedId !== $userId) {
        $chkAdmin = $conn->query("SELECT rol FROM usuarios WHERE id = $userId");
        $userRow = $chkAdmin ? $chkAdmin->fetch_assoc() : null;
        $rol = strtolower($userRow['rol'] ?? '');
        if ($rol === 'administrador' || $rol === 'admin') {
            $coachId = $requestedId;
        }
    }
}

try {
    // 2. Obtener datos de usuario (email está en 'usuario', comuna/region en 'direcciones')
    $sqlUser = "SELECT u.id, u.nombre, u.usuario as email, u.telefono, 
                       COALESCE(u.foto_perfil, u.foto) as foto_perfil, 
                       d.comuna, d.region, u.rol 
                FROM usuarios u 
                LEFT JOIN direcciones d ON d.usuario_id = u.id 
                WHERE u.id = ? 
                LIMIT 1";
    $stmtUser = $conn->prepare($sqlUser);
    if (!$stmtUser) {
        throw new Exception("Error al preparar consulta de usuario: " . $conn->error);
    }
    $stmtUser->bind_param("i", $coachId);
    $stmtUser->execute();
    $userRow = $stmtUser->get_result()->fetch_assoc();

    if (!$userRow) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        exit;
    }

    // 3. Obtener config del perfil público si existe en la base de datos
    $stmtProf = $conn->prepare("SELECT * FROM entrenador_perfil_publico WHERE entrenador_id = ? LIMIT 1");
    $stmtProf->bind_param("i", $coachId);
    $stmtProf->execute();
    $profRow = $stmtProf->get_result()->fetch_assoc();

    // Default slug
    $defaultSlug = strtolower(trim($userRow['nombre']));
    $defaultSlug = preg_replace('~[^\pL\d]+~u', '-', $defaultSlug);
    $defaultSlug = iconv('utf-8', 'us-ascii//TRANSLIT', $defaultSlug);
    $defaultSlug = preg_replace('~[^-\w]+~', '', $defaultSlug);
    $defaultSlug = trim($defaultSlug, '-');
    if (empty($defaultSlug)) $defaultSlug = "coach-$coachId";

    // Si ya existe registro guardado, usar los valores guardados; si no, sugerir valores iniciales
    $hasSavedRecord = ($profRow !== null);

    $especialidades = [];
    if ($hasSavedRecord && !empty($profRow['especialidades'])) {
        $especialidades = json_decode($profRow['especialidades'], true) ?: [];
    } else if (!$hasSavedRecord) {
        $especialidades = ["Técnica de Golpes", "Táctica y Posicionamiento", "Iniciación", "Competición"];
    }

    $niveles = [];
    if ($hasSavedRecord && !empty($profRow['niveles'])) {
        $niveles = json_decode($profRow['niveles'], true) ?: [];
    } else if (!$hasSavedRecord) {
        $niveles = ["Principiante", "Intermedio", "Avanzado"];
    }

    $comunas = [];
    if ($hasSavedRecord && !empty($profRow['comunas'])) {
        $comunas = json_decode($profRow['comunas'], true) ?: [];
    } else if (!$hasSavedRecord && !empty($userRow['comuna'])) {
        $comunas = [$userRow['comuna']];
    }

    $clubes_ids = [];
    if ($hasSavedRecord && !empty($profRow['clubes_ids'])) {
        $clubes_ids = json_decode($profRow['clubes_ids'], true) ?: [];
    }

    $config = [
        "entrenador_id" => $coachId,
        "slug" => $hasSavedRecord ? ($profRow['slug'] ?: $defaultSlug) : $defaultSlug,
        "publicado" => $hasSavedRecord ? (intval($profRow['publicado']) === 1) : false,
        "titulo" => $hasSavedRecord ? $profRow['titulo'] : "Profesor de Pádel Profesional",
        "bio" => $hasSavedRecord ? $profRow['bio'] : ("Profesor de pádel con amplia experiencia en formación técnica, táctica y física. Clases personalizadas en " . ($userRow['comuna'] ?: "Santiago") . "."),
        "especialidades" => $especialidades,
        "niveles" => $niveles,
        "anios_experiencia" => $hasSavedRecord ? intval($profRow['anios_experiencia']) : 3,
        "certificaciones" => $hasSavedRecord ? ($profRow['certificaciones'] ?? '') : "Certificado FEP / Instructor Nacional",
        "comunas" => $comunas,
        "clubes_ids" => $clubes_ids,
        "banner_url" => $hasSavedRecord ? (
            !empty($profRow['banner_url']) && strpos($profRow['banner_url'], 'uploads/') === 0 
                ? 'https://api.padelmanager.cl/prd/' . $profRow['banner_url'] 
                : ($profRow['banner_url'] ?? '')
        ) : '',
        "fondo_url" => $hasSavedRecord ? (
            !empty($profRow['fondo_url']) && strpos($profRow['fondo_url'], 'uploads/') === 0 
                ? 'https://api.padelmanager.cl/prd/' . $profRow['fondo_url'] 
                : ($profRow['fondo_url'] ?? '')
        ) : '',
        "telefono_contacto" => $hasSavedRecord ? ($profRow['telefono_contacto'] ?? '') : ($userRow['telefono'] ?? ''),
        "whatsapp" => $hasSavedRecord ? ($profRow['whatsapp'] ?? '') : ($userRow['telefono'] ?? ''),
        "instagram" => $hasSavedRecord ? ($profRow['instagram'] ?? '') : '',
        "facebook" => $hasSavedRecord ? ($profRow['facebook'] ?? '') : '',
        "google_business_url" => $hasSavedRecord ? ($profRow['google_business_url'] ?? '') : '',
        "color_primary" => $hasSavedRecord ? ($profRow['color_primary'] ?: '#0f172a') : '#0f172a',
        "color_accent" => $hasSavedRecord ? ($profRow['color_accent'] ?: '#ccff00') : '#ccff00',
        "mostrar_packs" => $hasSavedRecord ? (intval($profRow['mostrar_packs']) === 1) : true,
        "mostrar_horarios" => $hasSavedRecord ? (intval($profRow['mostrar_horarios']) === 1) : true,
        "mostrar_resenas" => $hasSavedRecord ? (intval($profRow['mostrar_resenas']) === 1) : true,
        "visitas" => $hasSavedRecord ? intval($profRow['visitas'] ?? 0) : 0
    ];

    // 4. Obtener todos los clubes disponibles para selección
    $resClubes = $conn->query("SELECT id, nombre, comuna, region FROM clubes ORDER BY nombre ASC");
    $clubesList = [];
    if ($resClubes) {
        while ($c = $resClubes->fetch_assoc()) {
            $clubesList[] = $c;
        }
    }

    // 5. Estadísticas de reseñas
    $stmtStats = $conn->prepare("SELECT COUNT(*) as total, AVG(rating) as promedio FROM resenas_entrenador WHERE entrenador_id = ? AND visible = 1");
    $stmtStats->bind_param("i", $coachId);
    $stmtStats->execute();
    $statsRow = $stmtStats->get_result()->fetch_assoc();
    $stmtStats->close();

    // 6. Obtener certificados y diplomas acreditados
    $certificados = [];
    $stmtCert = $conn->prepare("SELECT id, titulo, institucion, archivo_url, tipo_archivo, created_at 
                                FROM entrenador_certificados 
                                WHERE entrenador_id = ? AND activo = 1 
                                ORDER BY orden ASC, id DESC");
    if ($stmtCert) {
        $stmtCert->bind_param("i", $coachId);
        $stmtCert->execute();
        $resCert = $stmtCert->get_result();
        while ($c = $resCert->fetch_assoc()) {
            if (!empty($c['archivo_url']) && strpos($c['archivo_url'], 'uploads/') === 0) {
                $c['archivo_url'] = 'https://api.padelmanager.cl/prd/' . $c['archivo_url'];
            }
            $certificados[] = $c;
        }
        $stmtCert->close();
    }

    echo json_encode([
        "status" => "success",
        "has_saved_record" => $hasSavedRecord,
        "config" => $config,
        "usuario" => $userRow,
        "clubes_disponibles" => $clubesList,
        "certificados" => $certificados,
        "estadisticas" => [
            "total_resenas" => intval($statsRow['total'] ?? 0),
            "promedio_rating" => $statsRow['total'] > 0 ? round(floatval($statsRow['promedio']), 1) : 5.0,
            "visitas" => $config['visitas']
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
