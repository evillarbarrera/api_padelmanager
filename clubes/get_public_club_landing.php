<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$slug = trim($_GET['slug'] ?? '');
$clubId = intval($_GET['club_id'] ?? 0);
$previewMode = isset($_GET['preview']) && $_GET['preview'] === '1';

if (empty($slug) && $clubId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Parámetro 'slug' o 'club_id' es requerido"]);
    exit;
}

try {
    // Auto-crear columnas si no existen
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
            @$conn->query("ALTER TABLE clubes ADD COLUMN $col $type");
        }
    }

    // Extraer ID si el slug es numérico o del tipo 'club-123'
    $slugNum = 0;
    if (is_numeric($slug)) {
        $slugNum = intval($slug);
    } elseif (preg_match('/^club-(\d+)$/i', $slug, $matches)) {
        $slugNum = intval($matches[1]);
    }

    // Normalizar slug eliminando espacios, guiones y caracteres especiales
    $cleanSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $slug));

    // 1. Buscar club por slug exacto, ID, o nombre normalizado sin espacios/guiones
    $sqlClub = "SELECT c.*, COALESCE(NULLIF(c.region, ''), d.region) as region, COALESCE(NULLIF(c.comuna, ''), d.comuna) as comuna 
                FROM clubes c 
                LEFT JOIN direcciones d ON d.club_id = c.id 
                WHERE LOWER(c.landing_slug) = LOWER(?) 
                   OR (c.id = ? AND ? > 0)
                   OR LOWER(REPLACE(c.nombre, ' ', '-')) = LOWER(?)
                   OR LOWER(REPLACE(REPLACE(c.nombre, ' ', ''), '-', '')) = ?
                LIMIT 1";

    $stmt = $conn->prepare($sqlClub);
    $stmt->bind_param("siiss", $slug, $slugNum, $slugNum, $slug, $cleanSlug);
    $stmt->execute();
    $res = $stmt->get_result();

    // 2. Fallback: Buscar con LIKE por si el club se llama "Power Padel Machalí" o similar
    if ($res->num_rows === 0 && !empty($cleanSlug)) {
        $likePattern = "%" . $cleanSlug . "%";
        $stmtLike = $conn->prepare("SELECT c.*, COALESCE(NULLIF(c.region, ''), d.region) as region, COALESCE(NULLIF(c.comuna, ''), d.comuna) as comuna 
                                    FROM clubes c 
                                    LEFT JOIN direcciones d ON d.club_id = c.id 
                                    WHERE LOWER(REPLACE(REPLACE(c.nombre, ' ', ''), '-', '')) LIKE ?
                                       OR LOWER(c.nombre) LIKE ?
                                    LIMIT 1");
        $likeRaw = "%" . strtolower($slug) . "%";
        $stmtLike->bind_param("ss", $likePattern, $likeRaw);
        $stmtLike->execute();
        $res = $stmtLike->get_result();
        $stmtLike->close();
    }

    // 3. Fallback por clubId
    if ($res->num_rows === 0 && $clubId > 0) {
        $stmt2 = $conn->prepare("SELECT c.*, COALESCE(NULLIF(c.region, ''), d.region) as region, COALESCE(NULLIF(c.comuna, ''), d.comuna) as comuna 
                                FROM clubes c 
                                LEFT JOIN direcciones d ON d.club_id = c.id 
                                WHERE c.id = ? LIMIT 1");
        $stmt2->bind_param("i", $clubId);
        $stmt2->execute();
        $res = $stmt2->get_result();
        $stmt2->close();
    }

    // 4. Fallback final: Si no coincide pero hay clubes en el sistema, traer el primero
    if ($res->num_rows === 0) {
        $resFirst = $conn->query("SELECT c.*, COALESCE(NULLIF(c.region, ''), d.region) as region, COALESCE(NULLIF(c.comuna, ''), d.comuna) as comuna 
                                  FROM clubes c 
                                  LEFT JOIN direcciones d ON d.club_id = c.id 
                                  ORDER BY c.id ASC LIMIT 1");
        if ($resFirst && $resFirst->num_rows > 0) {
            $res = $resFirst;
        }
    }

    if ($res->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Club no encontrado (slug: $slug)"]);
        exit;
    }

    $club = $res->fetch_assoc();
    $cid = intval($club['id']);

    // Asegurar que el club tenga landing_slug y esté publicado
    if (empty($club['landing_slug']) && !empty($slug)) {
        $cleanSlugSafe = $conn->real_escape_string($cleanSlug);
        @$conn->query("UPDATE clubes SET landing_slug = '$cleanSlugSafe' WHERE id = $cid");
        $club['landing_slug'] = $cleanSlugSafe;
    }

    if (intval($club['landing_publicado']) !== 1) {
        @$conn->query("UPDATE clubes SET landing_publicado = 1 WHERE id = $cid");
        $club['landing_publicado'] = 1;
    }

    // 1. Obtener Canchas activas
    $canchas = [];
    try {
        $stmtC = $conn->prepare("SELECT id, nombre, tipo, techada, superficie, precio_hora, precio_60, precio_90, precio_120, categoria, icono, descripcion, capacidad FROM canchas WHERE club_id = ? AND activa = 1 ORDER BY categoria ASC, nombre ASC");
        if ($stmtC) {
            $stmtC->bind_param("i", $cid);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            while ($r = $resC->fetch_assoc()) {
                $canchas[] = $r;
            }
        }
    } catch (Throwable $t) {}

    // 2. Obtener Torneos y Americanos disponibles
    $americanos = [];
    try {
        $sqlT = "SELECT id, nombre, fecha_inicio, fecha_fin, tipo, precio, cupos_maximos, estado 
                 FROM torneos 
                 WHERE club_id = ? AND (estado = 'abierto' OR estado IS NULL OR estado = 'activo') 
                 ORDER BY fecha_inicio ASC LIMIT 10";
        $stmtT = $conn->prepare($sqlT);
        if ($stmtT) {
            $stmtT->bind_param("i", $cid);
            $stmtT->execute();
            $resT = $stmtT->get_result();
            while ($r = $resT->fetch_assoc()) {
                $americanos[] = $r;
            }
        }
    } catch (Throwable $t) {}

    // 3. Obtener Ranking del Club por Categoría
    $ranking = [];
    try {
        $sqlR = "SELECT u.id, u.nombre, u.categoria, COALESCE(u.puntos_ranking, 0) as puntos_ranking, u.foto_perfil 
                 FROM usuarios u 
                 JOIN usuarios_clubes uc ON uc.usuario_id = u.id 
                 WHERE uc.club_id = ? AND uc.activo = 1 
                 ORDER BY COALESCE(u.puntos_ranking, 0) DESC LIMIT 30";
        $stmtR = $conn->prepare($sqlR);
        if ($stmtR) {
            $stmtR->bind_param("i", $cid);
            $stmtR->execute();
            $resR = $stmtR->get_result();
            while ($r = $resR->fetch_assoc()) {
                $ranking[] = [
                    "id" => $r['id'],
                    "nombre" => $r['nombre'],
                    "categoria" => $r['categoria'] ?: "General",
                    "puntos" => intval($r['puntos_ranking']),
                    "foto" => $r['foto_perfil']
                ];
            }
        }
    } catch (Throwable $t) {}

    echo json_encode([
        "status" => "success",
        "club" => $club,
        "canchas" => $canchas,
        "americanos" => $americanos,
        "ranking" => $ranking
    ]);

    $conn->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error de servidor al cargar la información del club: " . $e->getMessage()
    ]);
}
?>
