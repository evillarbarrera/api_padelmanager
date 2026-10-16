<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once "../db.php";

// SILENT SCHEMA FIX: Ensure new columns exist
function ensureLigaColumns($conn) {
    $cols = [
        'imagen_url' => "VARCHAR(255) NULL AFTER descripcion",
        'precio' => "DECIMAL(10,2) DEFAULT 0.00 AFTER fecha_fin",
        'visible_app' => "TINYINT(1) DEFAULT 1 AFTER estado"
    ];
    foreach ($cols as $col => $def) {
        $check = $conn->query("SHOW COLUMNS FROM ligas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            $conn->query("ALTER TABLE ligas ADD `$col` $def");
        }
    }
}
ensureLigaColumns($conn);

$input = json_decode(file_get_contents("php://input"), true);

$id = $input['id'] ?? null;
$club_id = (int)($input['club_id'] ?? 0);
if (!$club_id) {
    $admin_id = (int)($input['admin_id'] ?? $input['user_id'] ?? 0);
    if ($admin_id > 0) {
        $resC = $conn->query("SELECT id FROM clubes WHERE admin_id = $admin_id LIMIT 1");
        if ($resC && $resC->num_rows > 0) {
            $rowC = $resC->fetch_assoc();
            $club_id = (int)$rowC['id'];
        }
    }
}
$nombre = trim($input['nombre'] ?? '');
$descripcion = trim($input['descripcion'] ?? '');
$imagen_url = trim($input['imagen_url'] ?? '');
$fecha_inicio = $input['fecha_inicio'] ?? '';
$raw_precio = $input['precio'] ?? 0;
if (is_string($raw_precio)) {
    $clean_str = trim(str_replace(['$', ' '], '', $raw_precio));
    if (strpos($clean_str, '.') !== false && strpos($clean_str, ',') === false) {
        $parts = explode('.', $clean_str);
        if (count($parts) === 2 && strlen($parts[1]) === 3) {
            $clean_str = $parts[0] . $parts[1];
        }
    }
    $clean_str = str_replace(['.', ','], '', $clean_str);
    $precio = (float)$clean_str;
} else {
    $precio = (float)$raw_precio;
}
if ($precio > 0 && $precio < 1000) {
    $precio = $precio * 1000;
}
$estado = $input['estado'] ?? 'Inscripción';
$visible_app = isset($input['visible_app']) ? ((bool)$input['visible_app'] ? 1 : 0) : 1;
$categorias = $input['categorias'] ?? [];

$reglas_puntos = $input['reglas_puntos'] ?? [
    'puntos_victoria_normal' => 3,
    'puntos_victoria_decisivo' => 2,
    'puntos_derrota_jugada' => 1,
    'puntos_derrota_wo' => 0,
    'criterios_desempate' => ['puntos', 'dif_sets', 'dif_games', 'enfrentamiento_directo', 'games_favor'],
    'puntos_ranking_participacion' => 50,
    'puntos_ranking_primer_lugar' => 500,
    'puntos_ranking_segundo_lugar' => 300,
    'puntos_ranking_tercer_lugar' => 150
];

if (empty($nombre) || empty($fecha_inicio) || empty($fecha_fin)) {
    echo json_encode(["success" => false, "error" => "Campos obligatorios requeridos: nombre, fecha_inicio, fecha_fin"]);
    exit;
}

$reglas_json = json_encode($reglas_puntos);

try {
    $conn->begin_transaction();

    if ($id) {
        $stmt = $conn->prepare("UPDATE ligas SET club_id=?, nombre=?, descripcion=?, imagen_url=?, fecha_inicio=?, fecha_fin=?, precio=?, estado=?, visible_app=?, reglas_puntos_json=? WHERE id=?");
        $stmt->bind_param("isssssdsisi", $club_id, $nombre, $descripcion, $imagen_url, $fecha_inicio, $fecha_fin, $precio, $estado, $visible_app, $reglas_json, $id);
        $stmt->execute();
        $message = "Liga actualizada exitosamente";
    } else {
        $stmt = $conn->prepare("INSERT INTO ligas (club_id, nombre, descripcion, imagen_url, fecha_inicio, fecha_fin, precio, estado, visible_app, reglas_puntos_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssdsis", $club_id, $nombre, $descripcion, $imagen_url, $fecha_inicio, $fecha_fin, $precio, $estado, $visible_app, $reglas_json);
        $stmt->execute();
        $id = $stmt->insert_id;
        $message = "Liga creada exitosamente";
    }

    // Procesar Categorías
    if (!empty($categorias)) {
        // Si estamos editando una liga existente, eliminar las categorías que ya no estén presentes en el array enviado
        if ($id) {
            $cat_ids_enviados = [];
            foreach ($categorias as $cat) {
                if (!empty($cat['id'])) {
                    $cat_ids_enviados[] = (int)$cat['id'];
                }
            }

            if (!empty($cat_ids_enviados)) {
                $in_clause = implode(',', $cat_ids_enviados);
                $res_del = $conn->query("SELECT id FROM liga_categorias WHERE liga_id = $id AND id NOT IN ($in_clause)");
                if ($res_del) {
                    while ($r_del = $res_del->fetch_assoc()) {
                        $del_cat_id = (int)$r_del['id'];
                        $conn->query("DELETE FROM liga_partidos WHERE grupo_id IN (SELECT id FROM liga_grupos WHERE categoria_id = $del_cat_id) OR jornada_id IN (SELECT id FROM liga_jornadas WHERE categoria_id = $del_cat_id) OR pareja1_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $del_cat_id) OR pareja2_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $del_cat_id)");
                        $conn->query("DELETE FROM liga_posiciones WHERE grupo_id IN (SELECT id FROM liga_grupos WHERE categoria_id = $del_cat_id) OR pareja_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $del_cat_id)");
                        $conn->query("DELETE FROM liga_parejas WHERE categoria_id = $del_cat_id");
                        $conn->query("DELETE FROM liga_grupos WHERE categoria_id = $del_cat_id");
                        $conn->query("DELETE FROM liga_jornadas WHERE categoria_id = $del_cat_id");
                        $conn->query("DELETE FROM liga_categorias WHERE id = $del_cat_id");
                    }
                }
            }
        }

        foreach ($categorias as $cat) {
            $cat_id = $cat['id'] ?? null;
            $cat_nom = trim($cat['nombre'] ?? 'Categoría Única');
            $genero = $cat['genero'] ?? 'Caballeros';
            $nivel = $cat['nivel'] ?? 'B';
            $max_parejas = (int)($cat['max_parejas'] ?? 16);
            $clasifican = (int)($cat['parejas_clasifican_playoff'] ?? 2);
            $dias_juego_json = (!empty($cat['dias_juego']) && is_array($cat['dias_juego'])) ? json_encode(array_values($cat['dias_juego'])) : (is_string($cat['dias_juego_json'] ?? null) ? $cat['dias_juego_json'] : null);

            if ($cat_id) {
                $stmt_c = $conn->prepare("UPDATE liga_categorias SET nombre=?, genero=?, nivel=?, max_parejas=?, parejas_clasifican_playoff=?, dias_juego_json=? WHERE id=? AND liga_id=?");
                $stmt_c->bind_param("ssssisii", $cat_nom, $genero, $nivel, $max_parejas, $clasifican, $dias_juego_json, $cat_id, $id);
                $stmt_c->execute();
            } else {
                $stmt_c = $conn->prepare("INSERT INTO liga_categorias (liga_id, nombre, genero, nivel, max_parejas, parejas_clasifican_playoff, dias_juego_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_c->bind_param("isssiis", $id, $cat_nom, $genero, $nivel, $max_parejas, $clasifican, $dias_juego_json);
                $stmt_c->execute();
            }
        }
    } else if (!$id) {
        $stmt_def = $conn->prepare("INSERT INTO liga_categorias (liga_id, nombre, genero, nivel, max_parejas, parejas_clasifican_playoff) VALUES (?, 'Categoría 1', 'Caballeros', 'B', 16, 2)");
        $stmt_def->bind_param("i", $id);
        $stmt_def->execute();
    }

    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => $message, "liga_id" => $id]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
