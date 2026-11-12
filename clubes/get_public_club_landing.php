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

    // 1. Obtener Canchas (tolerante con activa = 1 o NULL)
    $canchas = [];
    try {
        $stmtC = $conn->prepare("SELECT id, nombre, tipo, techada, superficie, precio_hora, precio_60, precio_90, precio_120, precio_60_alto, precio_90_alto, precio_120_alto, hora_inicio_bajo, hora_fin_bajo, hora_inicio_alto, hora_fin_alto, categoria, icono, descripcion, capacidad FROM canchas WHERE club_id = ? AND (activa = 1 OR activa IS NULL) ORDER BY categoria ASC, nombre ASC");
        if ($stmtC) {
            $stmtC->bind_param("i", $cid);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            while ($r = $resC->fetch_assoc()) {
                $canchas[] = $r;
            }
            $stmtC->close();
        }

        // Si no encontró, buscar canchas sin filtro de activa
        if (empty($canchas)) {
            $stmtC2 = $conn->prepare("SELECT id, nombre, tipo, techada, superficie, precio_hora, precio_60, precio_90, precio_120, precio_60_alto, precio_90_alto, precio_120_alto, hora_inicio_bajo, hora_fin_bajo, hora_inicio_alto, hora_fin_alto, categoria, icono, descripcion, capacidad FROM canchas WHERE club_id = ? ORDER BY id ASC");
            if ($stmtC2) {
                $stmtC2->bind_param("i", $cid);
                $stmtC2->execute();
                $resC2 = $stmtC2->get_result();
                while ($r2 = $resC2->fetch_assoc()) {
                    $canchas[] = $r2;
                }
                $stmtC2->close();
            }
        }
    } catch (Throwable $t) {}

    // Generar slots de disponibilidad para hoy directamente
    $hoy = date('Y-m-d');
    $horaActual = date('H:i');
    
    $resExistentes = [];
    $stmtR = $conn->prepare("SELECT r.cancha_id, r.hora_inicio, r.hora_fin FROM reservas_cancha r JOIN canchas c ON r.cancha_id = c.id WHERE c.club_id = ? AND r.fecha = ? AND LOWER(COALESCE(r.estado, '')) NOT IN ('cancelada', 'cancelado', 'anulada', 'rechazada', 'eliminada')");
    if ($stmtR) {
        $stmtR->bind_param("is", $cid, $hoy);
        $stmtR->execute();
        $rRes = $stmtR->get_result();
        while ($rr = $rRes->fetch_assoc()) {
            $hIni = substr($rr['hora_inicio'], 0, 5);
            $hFin = substr($rr['hora_fin'], 0, 5);
            if ($hFin === '00:00' || $hFin === '23:59') $hFin = '24:00';
            $resExistentes[intval($rr['cancha_id'])][] = ['inicio' => $hIni, 'fin' => $hFin];
        }
        $stmtR->close();
    }

    foreach ($canchas as &$c) {
        $cId = intval($c['id']);
        $p90 = floatval($c['precio_90'] ?: ($c['precio_hora'] ? $c['precio_hora'] * 1.5 : 20000));
        $p90Alto = floatval($c['precio_90_alto'] ?: $p90);
        $hInicioAlto = !empty($c['hora_inicio_alto']) ? substr($c['hora_inicio_alto'], 0, 5) : '18:00';
        $hApertura = !empty($c['hora_inicio_bajo']) ? substr($c['hora_inicio_bajo'], 0, 5) : '08:30';
        
        $slots = [];
        $t = strtotime("$hoy $hApertura:00");
        $tFinDia = strtotime("$hoy 23:30:00");
        
        while ($t + 5400 <= $tFinDia + 1800) {
            $hIniSlot = date('H:i', $t);
            $hFinSlot = date('H:i', $t + 5400);
            
            $ocupado = false;
            $motivo = null;
            if (isset($resExistentes[$cId])) {
                foreach ($resExistentes[$cId] as $item) {
                    if ($hIniSlot < $item['fin'] && $hFinSlot > $item['inicio']) {
                        $ocupado = true;
                        $motivo = "Ocupado";
                        break;
                    }
                }
            }
            if ($hIniSlot <= $horaActual) {
                $ocupado = true;
                $motivo = $motivo ?: "Pasado";
            }
            
            $precio = ($hIniSlot >= $hInicioAlto) ? $p90Alto : $p90;
            $slots[] = [
                "hora_inicio" => $hIniSlot,
                "hora_fin" => $hFinSlot,
                "duracion" => 90,
                "disponible" => !$ocupado,
                "precio" => intval($precio),
                "precio_formateado" => "$" . number_format($precio, 0, ',', '.'),
                "motivo" => $motivo
            ];
            $t += 5400;
        }
        $c['slots'] = $slots;
        $c['slots_disponibles_count'] = count(array_filter($slots, function($s) { return $s['disponible']; }));
        $c['precio_desde'] = intval($p90);
        $c['techada'] = intval($c['techada']) === 1;
    }
    unset($c);

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
                $fotoPerfil = $r['foto_perfil'];
                if (!empty($fotoPerfil)) {
                    if (strpos($fotoPerfil, 'http') === 0 || strpos($fotoPerfil, 'assets/') === 0) {
                        // ya es URL absoluta o asset
                    } else {
                        $clean = ltrim($fotoPerfil, '/');
                        $clean = preg_replace('/^(prd|dev)\//', '', $clean);
                        if (strpos($clean, '/') === false) {
                            $clean = "uploads/perfiles/" . $clean;
                        }
                        $fotoPerfil = "https://api.padelmanager.cl/prd/" . $clean;
                    }
                } else {
                    $fotoPerfil = null;
                }

                $ranking[] = [
                    "id" => $r['id'],
                    "nombre" => $r['nombre'],
                    "categoria" => $r['categoria'] ?: "General",
                    "puntos" => intval($r['puntos_ranking']),
                    "foto" => $fotoPerfil
                ];
            }
        }
    } catch (Throwable $t) {}

    // Normalizar logo_url si solo existe 'logo'
    $rawLogo = !empty($club['logo']) ? $club['logo'] : (!empty($club['logo_url']) ? $club['logo_url'] : null);
    if ($rawLogo) {
        if (strpos($rawLogo, 'http') === 0 || strpos($rawLogo, 'assets/') === 0) {
            $club['logo_url'] = $rawLogo;
        } else {
            $cleanPath = ltrim($rawLogo, '/');
            $cleanPath = preg_replace('/^(prd|dev)\//', '', $cleanPath);
            if (strpos($cleanPath, '/') === false) {
                $cleanPath = "uploads/clubes/" . $cleanPath;
            }
            $club['logo_url'] = "https://api.padelmanager.cl/prd/" . $cleanPath;
        }
    }

    echo json_encode([
        "status" => "success",
        "club" => $club,
        "canchas" => $canchas,
        "americanos" => $americanos,
        "ranking" => $ranking
    ]);

    $conn->close();
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        "status" => "error",
        "message" => "Error de servidor al cargar la información del club: " . $e->getMessage(),
        "line" => $e->getLine(),
        "file" => basename($e->getFile())
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
