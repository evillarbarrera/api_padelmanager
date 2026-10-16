<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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
    sendUnauthorized("Token inválido o expirado");
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "JSON inválido"]);
    exit;
}

// 1. Determinar el entrenador_id: Por defecto siempre es el usuario autenticado
$entrenador_id = $userId;
if (!empty($input['entrenador_id']) && intval($input['entrenador_id']) > 0) {
    $requestedId = intval($input['entrenador_id']);
    if ($requestedId !== $userId) {
        $chkAdmin = $conn->query("SELECT rol FROM usuarios WHERE id = $userId");
        $userRow = $chkAdmin ? $chkAdmin->fetch_assoc() : null;
        $rol = strtolower($userRow['rol'] ?? '');
        if ($rol === 'administrador' || $rol === 'admin') {
            $entrenador_id = $requestedId;
        } else {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "No tienes permisos para editar este perfil"]);
            exit;
        }
    }
}

// 2. Sanitizar slug
$rawSlug = trim($input['slug'] ?? '');
if (empty($rawSlug)) {
    $resName = $conn->query("SELECT nombre FROM usuarios WHERE id = $entrenador_id");
    $nameRow = $resName ? $resName->fetch_assoc() : null;
    $rawSlug = $nameRow ? $nameRow['nombre'] : "entrenador-$entrenador_id";
}

$slug = strtolower(trim($rawSlug));
$slug = preg_replace('~[^\pL\d]+~u', '-', $slug);
$slug = iconv('utf-8', 'us-ascii//TRANSLIT', $slug);
$slug = preg_replace('~[^-\w]+~', '', $slug);
$slug = trim($slug, '-');
$slug = preg_replace('~-+~', '-', $slug);
if (empty($slug)) {
    $slug = "entrenador-$entrenador_id";
}

// Verificar que el slug no esté en uso por otro entrenador
$stmtChk = $conn->prepare("SELECT entrenador_id FROM entrenador_perfil_publico WHERE slug = ? AND entrenador_id != ?");
$stmtChk->bind_param("si", $slug, $entrenador_id);
$stmtChk->execute();
if ($stmtChk->get_result()->num_rows > 0) {
    $slug .= "-$entrenador_id";
}

// 3. Preparar variables
$publicado = !empty($input['publicado']) ? 1 : 0;
$titulo = trim($input['titulo'] ?? 'Profesor de Pádel Profesional');
$bio = trim($input['bio'] ?? '');
$especialidadesArr = is_array($input['especialidades'] ?? null) ? $input['especialidades'] : [];
$especialidades = json_encode($especialidadesArr, JSON_UNESCAPED_UNICODE);
$nivelesArr = is_array($input['niveles'] ?? null) ? $input['niveles'] : [];
$niveles = json_encode($nivelesArr, JSON_UNESCAPED_UNICODE);
$anios_experiencia = intval($input['anios_experiencia'] ?? 1);
$certificaciones = trim($input['certificaciones'] ?? '');
$comunasArr = is_array($input['comunas'] ?? null) ? $input['comunas'] : [];
$comunas = json_encode($comunasArr, JSON_UNESCAPED_UNICODE);
$clubesArr = is_array($input['clubes_ids'] ?? null) ? array_map('intval', $input['clubes_ids']) : [];
$clubes_ids = json_encode($clubesArr, JSON_UNESCAPED_UNICODE);
$banner_url = trim($input['banner_url'] ?? '');
if (!empty($banner_url) && strpos($banner_url, 'uploads/') === 0) {
    $banner_url = 'https://api.padelmanager.cl/prd/' . $banner_url;
}
$fondo_url = trim($input['fondo_url'] ?? '');
if (!empty($fondo_url) && strpos($fondo_url, 'uploads/') === 0) {
    $fondo_url = 'https://api.padelmanager.cl/prd/' . $fondo_url;
}
$telefono_contacto = trim($input['telefono_contacto'] ?? '');
$whatsapp = trim($input['whatsapp'] ?? '');
$instagram = trim($input['instagram'] ?? '');
$facebook = trim($input['facebook'] ?? '');
$google_business_url = trim($input['google_business_url'] ?? '');
$color_primary = trim($input['color_primary'] ?? '#0f172a');
$color_accent = trim($input['color_accent'] ?? '#ccff00');
$mostrar_packs = isset($input['mostrar_packs']) ? ($input['mostrar_packs'] ? 1 : 0) : 1;
$mostrar_horarios = isset($input['mostrar_horarios']) ? ($input['mostrar_horarios'] ? 1 : 0) : 1;
$mostrar_resenas = isset($input['mostrar_resenas']) ? ($input['mostrar_resenas'] ? 1 : 0) : 1;

try {
    // 4. Comprobar si ya existe registro para este entrenador
    $chkExist = $conn->prepare("SELECT entrenador_id FROM entrenador_perfil_publico WHERE entrenador_id = ?");
    $chkExist->bind_param("i", $entrenador_id);
    $chkExist->execute();
    $exists = $chkExist->get_result()->num_rows > 0;

    if ($exists) {
        // UPDATE explícito
        $sql = "UPDATE entrenador_perfil_publico SET
                    slug = ?,
                    publicado = ?,
                    titulo = ?,
                    bio = ?,
                    especialidades = ?,
                    niveles = ?,
                    anios_experiencia = ?,
                    certificaciones = ?,
                    comunas = ?,
                    clubes_ids = ?,
                    banner_url = ?,
                    fondo_url = ?,
                    telefono_contacto = ?,
                    whatsapp = ?,
                    instagram = ?,
                    facebook = ?,
                    google_business_url = ?,
                    color_primary = ?,
                    color_accent = ?,
                    mostrar_packs = ?,
                    mostrar_horarios = ?,
                    mostrar_resenas = ?,
                    updated_at = NOW()
                WHERE entrenador_id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error en prepare UPDATE: " . $conn->error);
        }
        $stmt->bind_param(
            "sissssissssssssssssiiii",
            $slug, $publicado, $titulo, $bio, $especialidades, $niveles,
            $anios_experiencia, $certificaciones, $comunas, $clubes_ids, $banner_url,
            $fondo_url,
            $telefono_contacto, $whatsapp, $instagram, $facebook, $google_business_url,
            $color_primary, $color_accent, $mostrar_packs, $mostrar_horarios, $mostrar_resenas,
            $entrenador_id
        );
    } else {
        // INSERT explícito
        $sql = "INSERT INTO entrenador_perfil_publico (
                    entrenador_id, slug, publicado, titulo, bio, especialidades, niveles,
                    anios_experiencia, certificaciones, comunas, clubes_ids, banner_url, fondo_url,
                    telefono_contacto, whatsapp, instagram, facebook, google_business_url,
                    color_primary, color_accent, mostrar_packs, mostrar_horarios, mostrar_resenas,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error en prepare INSERT: " . $conn->error);
        }
        $stmt->bind_param(
            "isissssissssssssssssiii",
            $entrenador_id, $slug, $publicado, $titulo, $bio, $especialidades, $niveles,
            $anios_experiencia, $certificaciones, $comunas, $clubes_ids, $banner_url, $fondo_url,
            $telefono_contacto, $whatsapp, $instagram, $facebook, $google_business_url,
            $color_primary, $color_accent, $mostrar_packs, $mostrar_horarios, $mostrar_resenas
        );
    }

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success",
            "message" => "Perfil público guardado exitosamente",
            "slug" => $slug,
            "publicado" => $publicado,
            "entrenador_id" => $entrenador_id,
            "banner_url" => $banner_url,
            "fondo_url" => $fondo_url,
            "config" => [
                "entrenador_id" => $entrenador_id,
                "slug" => $slug,
                "publicado" => ($publicado === 1),
                "titulo" => $titulo,
                "bio" => $bio,
                "especialidades" => $especialidadesArr,
                "niveles" => $nivelesArr,
                "anios_experiencia" => $anios_experiencia,
                "certificaciones" => $certificaciones,
                "comunas" => $comunasArr,
                "clubes_ids" => $clubesArr,
                "banner_url" => $banner_url,
                "fondo_url" => $fondo_url,
                "telefono_contacto" => $telefono_contacto,
                "whatsapp" => $whatsapp,
                "instagram" => $instagram,
                "facebook" => $facebook,
                "google_business_url" => $google_business_url,
                "color_primary" => $color_primary,
                "color_accent" => $color_accent,
                "mostrar_packs" => ($mostrar_packs === 1),
                "mostrar_horarios" => ($mostrar_horarios === 1),
                "mostrar_resenas" => ($mostrar_resenas === 1)
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error al ejecutar SQL: " . $stmt->error]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
