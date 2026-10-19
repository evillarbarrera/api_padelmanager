<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once "../db.php";

ob_start();

// SILENT SCHEMA FIX: Ensure tables and columns exist
function ensureLigaSchema($conn) {
    // 0. Column apellido en usuarios (por si no existe en algunas BDs)
    $check_ape = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'apellido'");
    if ($check_ape && $check_ape->num_rows == 0) {
        @$conn->query("ALTER TABLE usuarios ADD `apellido` VARCHAR(100) NULL AFTER nombre");
    }

    // 1. Tabla Ligas
    @$conn->query("CREATE TABLE IF NOT EXISTS `ligas` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `club_id` INT NOT NULL DEFAULT 1,
      `nombre` VARCHAR(150) NOT NULL,
      `descripcion` TEXT,
      `imagen_url` VARCHAR(255) NULL,
      `fecha_inicio` DATE NOT NULL,
      `fecha_fin` DATE NOT NULL,
      `precio` DECIMAL(10,2) DEFAULT 0.00,
      `estado` ENUM('Inscripción', 'En Curso', 'Playoffs', 'Finalizada') NOT NULL DEFAULT 'Inscripción',
      `visible_app` TINYINT(1) DEFAULT 1,
      `reglas_puntos_json` JSON NOT NULL,
      `creado_el` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `actualizado_el` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $cols = [
        'imagen_url' => "VARCHAR(255) NULL AFTER descripcion",
        'precio' => "DECIMAL(10,2) DEFAULT 0.00 AFTER fecha_fin",
        'visible_app' => "TINYINT(1) DEFAULT 1 AFTER estado"
    ];
    foreach ($cols as $col => $def) {
        $check = @$conn->query("SHOW COLUMNS FROM ligas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            @$conn->query("ALTER TABLE ligas ADD `$col` $def");
        }
    }

    // Auto fix visible_app and club_id for ligas
    @$conn->query("UPDATE ligas SET visible_app = 1 WHERE visible_app IS NULL OR visible_app = 0");
    @$conn->query("UPDATE ligas SET club_id = 21 WHERE (club_id = 1 OR club_id = 0 OR club_id IS NULL) AND (nombre LIKE '%Power%' OR nombre LIKE '%Machali%' OR nombre LIKE '%Apertura%')");
    @$conn->query("UPDATE ligas l JOIN clubes c ON (c.nombre LIKE '%PowerPadel%' OR c.nombre LIKE '%Power Padel%') SET l.club_id = c.id WHERE (l.club_id = 1 OR l.club_id = 0 OR l.club_id IS NULL)");
    
    // Auto-heal precio: corrección de escala (ej. 30 -> 30000) o extracción desde la descripción/reglamento
    @$conn->query("UPDATE ligas SET precio = precio * 1000 WHERE precio > 0 AND precio < 1000");
    $res_check_p = @$conn->query("SELECT id, descripcion, precio FROM ligas WHERE precio IS NULL OR precio = 0");
    if ($res_check_p) {
        while ($lp = $res_check_p->fetch_assoc()) {
            $lid = (int)$lp['id'];
            if (!empty($lp['descripcion'])) {
                if (preg_match('/(?:\$|valor|precio|inscripci[oó]n|costo)[:\s]*\$?\s*([0-9]{1,3}(?:\.[0-9]{3})+|[0-9]{4,6})/i', $lp['descripcion'], $m)) {
                    $ext_p = (float)str_replace('.', '', $m[1]);
                    if ($ext_p > 0) {
                        @$conn->query("UPDATE ligas SET precio = $ext_p WHERE id = $lid");
                    }
                }
            }
        }
    }

    @$conn->query("UPDATE reservas_cancha SET hora_fin = ADDTIME(hora_inicio, '01:30:00') WHERE liga_partido_id IS NOT NULL AND liga_partido_id > 0 AND (TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin) != 90 OR hora_fin IS NULL)");
    @$conn->query("INSERT INTO reservas_cancha (cancha_id, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, liga_partido_id, nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, usuario_id, jugador2_id, jugador3_id, jugador4_id)
                  SELECT lp.cancha_id, 
                         DATE(lp.fecha_hora), 
                         TIME(lp.fecha_hora), 
                         ADDTIME(TIME(lp.fecha_hora), '01:30:00'), 
                         0, 0, 1, 'Confirmada', lp.id,
                         COALESCE(u11.nombre, par1.nombre_externo1, 'P1-J1'),
                         COALESCE(u12.nombre, par1.nombre_externo2, 'P1-J2'),
                         COALESCE(u21.nombre, par2.nombre_externo1, 'P2-J1'),
                         COALESCE(u22.nombre, par2.nombre_externo2, 'P2-J2'),
                         par1.jugador1_id, par1.jugador2_id, par2.jugador1_id, par2.jugador2_id
                  FROM liga_partidos lp
                  LEFT JOIN reservas_cancha r ON r.liga_partido_id = lp.id
                  LEFT JOIN liga_parejas par1 ON lp.pareja1_id = par1.id
                  LEFT JOIN liga_parejas par2 ON lp.pareja2_id = par2.id
                  LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
                  LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
                  LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
                  LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
                  WHERE lp.fecha_hora IS NOT NULL 
                    AND lp.fecha_hora NOT LIKE '0000%' 
                    AND lp.cancha_id IS NOT NULL 
                    AND lp.estado != 'Suspendido'
                    AND r.id IS NULL");

    // 2. Otras tablas de ligas
    $conn->query("CREATE TABLE IF NOT EXISTS `liga_categorias` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `liga_id` INT NOT NULL,
      `nombre` VARCHAR(100) NOT NULL,
      `genero` ENUM('Caballeros', 'Damas', 'Mixto') NOT NULL DEFAULT 'Caballeros',
      `nivel` VARCHAR(50) DEFAULT 'B',
      `max_parejas` INT DEFAULT 16,
      `parejas_clasifican_playoff` INT DEFAULT 2,
      `dias_juego_json` JSON NULL,
      FOREIGN KEY (`liga_id`) REFERENCES `ligas`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $check_dias = @$conn->query("SHOW COLUMNS FROM liga_categorias LIKE 'dias_juego_json'");
    if ($check_dias && $check_dias->num_rows == 0) {
        @$conn->query("ALTER TABLE liga_categorias ADD `dias_juego_json` JSON NULL");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `liga_parejas` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `categoria_id` INT NOT NULL,
      `jugador1_id` INT NOT NULL,
      `jugador2_id` INT NOT NULL,
      `nombre_pareja` VARCHAR(150),
      `estado` ENUM('Confirmada', 'Pendiente_Pago', 'Cancelada') DEFAULT 'Confirmada',
      `grupo_id` INT NULL,
      `fecha_inscripcion` DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`categoria_id`) REFERENCES `liga_categorias`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->query("CREATE TABLE IF NOT EXISTS `liga_grupos` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `categoria_id` INT NOT NULL,
      `nombre` VARCHAR(50) NOT NULL,
      FOREIGN KEY (`categoria_id`) REFERENCES `liga_categorias`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->query("CREATE TABLE IF NOT EXISTS `liga_jornadas` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `categoria_id` INT NOT NULL,
      `numero_jornada` INT NOT NULL,
      `nombre` VARCHAR(100) NOT NULL,
      `fecha_limite` DATE NULL,
      FOREIGN KEY (`categoria_id`) REFERENCES `liga_categorias`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->query("CREATE TABLE IF NOT EXISTS `liga_partidos` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `categoria_id` INT NULL,
      `jornada_id` INT NULL,
      `grupo_id` INT NULL,
      `pareja1_id` INT NOT NULL,
      `pareja2_id` INT NOT NULL,
      `cancha_id` INT NULL,
      `fecha_hora` DATETIME NULL,
      `es_playoff` TINYINT(1) DEFAULT 0,
      `ronda_playoff` VARCHAR(50) NULL,
      `estado` ENUM('Programado', 'Jugado', 'Walkover', 'Suspendido') DEFAULT 'Programado',
      `pareja_ganadora_id` INT NULL,
      `set1_p1` INT DEFAULT 0,
      `set1_p2` INT DEFAULT 0,
      `set2_p1` INT DEFAULT 0,
      `set2_p2` INT DEFAULT 0,
      `set3_p1` INT DEFAULT 0,
      `set3_p2` INT DEFAULT 0,
      `es_super_tiebreak` TINYINT(1) DEFAULT 0,
      `es_wo` TINYINT(1) DEFAULT 0,
      `puntos_p1` INT DEFAULT 0,
      `puntos_p2` INT DEFAULT 0,
      `observaciones` VARCHAR(255) NULL,
      `actualizado_el` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      FOREIGN KEY (`pareja1_id`) REFERENCES `liga_parejas`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`pareja2_id`) REFERENCES `liga_parejas`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $partidos_cols = [
        'categoria_id' => "INT NULL",
        'es_playoff' => "TINYINT(1) DEFAULT 0",
        'ronda_playoff' => "VARCHAR(50) NULL"
    ];
    foreach ($partidos_cols as $col => $def) {
        $check = @$conn->query("SHOW COLUMNS FROM liga_partidos LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            @$conn->query("ALTER TABLE liga_partidos ADD `$col` $def");
        }
    }

    $parejas_cols = [
        'nombre_externo1' => "VARCHAR(150) NULL",
        'nombre_externo2' => "VARCHAR(150) NULL",
        'restricciones_horarias_json' => "JSON NULL"
    ];
    foreach ($parejas_cols as $col => $def) {
        $check = @$conn->query("SHOW COLUMNS FROM liga_parejas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            @$conn->query("ALTER TABLE liga_parejas ADD `$col` $def");
        }
    }
    @$conn->query("ALTER TABLE liga_parejas MODIFY jugador1_id INT NULL");
    @$conn->query("ALTER TABLE liga_parejas MODIFY jugador2_id INT NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS `liga_posiciones` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `grupo_id` INT NOT NULL,
      `pareja_id` INT NOT NULL,
      `pj` INT DEFAULT 0,
      `pg` INT DEFAULT 0,
      `pp` INT DEFAULT 0,
      `puntos` INT DEFAULT 0,
      `sets_favor` INT DEFAULT 0,
      `sets_contra` INT DEFAULT 0,
      `dif_sets` INT DEFAULT 0,
      `games_favor` INT DEFAULT 0,
      `games_contra` INT DEFAULT 0,
      `dif_games` INT DEFAULT 0,
      `wo_contra` INT DEFAULT 0,
      `posicion` INT DEFAULT 0,
      UNIQUE KEY `uk_grupo_pareja` (`grupo_id`, `pareja_id`),
      FOREIGN KEY (`grupo_id`) REFERENCES `liga_grupos`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`pareja_id`) REFERENCES `liga_parejas`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Deduplicar jornadas redundantes por categoría
    @$conn->query("UPDATE liga_partidos lp
                   JOIN (
                       SELECT categoria_id, numero_jornada, MIN(id) as min_id
                       FROM liga_jornadas
                       GROUP BY categoria_id, numero_jornada
                       HAVING COUNT(*) > 1
                   ) j_dup ON j_dup.categoria_id = lp.categoria_id
                   JOIN liga_jornadas lj ON lp.jornada_id = lj.id AND lj.numero_jornada = j_dup.numero_jornada
                   SET lp.jornada_id = j_dup.min_id");

    @$conn->query("DELETE j1 FROM liga_jornadas j1 
                   INNER JOIN liga_jornadas j2 
                   WHERE j1.categoria_id = j2.categoria_id 
                     AND j1.numero_jornada = j2.numero_jornada 
                     AND j1.id > j2.id");

    $check_uk = @$conn->query("SHOW INDEX FROM liga_jornadas WHERE Key_name = 'uk_cat_num'");
    if ($check_uk && $check_uk->num_rows == 0) {
        @$conn->query("ALTER TABLE liga_jornadas ADD UNIQUE KEY `uk_cat_num` (`categoria_id`, `numero_jornada`)");
    }
}

ensureLigaSchema($conn);

// Auto-sincronizar categoria_id en liga_partidos
@$conn->query("UPDATE liga_partidos lp JOIN liga_jornadas lj ON lp.jornada_id = lj.id SET lp.categoria_id = lj.categoria_id WHERE lp.categoria_id IS NULL OR lp.categoria_id = 0");
@$conn->query("UPDATE liga_partidos lp JOIN liga_parejas lpar ON lp.pareja1_id = lpar.id SET lp.categoria_id = lpar.categoria_id WHERE lp.categoria_id IS NULL OR lp.categoria_id = 0");

// Auto-sanar nombres de parejas reales en liga_parejas cuando tengan nombres genéricos o estén vacíos
@$conn->query("UPDATE liga_parejas
               SET nombre_pareja = CONCAT(TRIM(nombre_externo1), ' / ', TRIM(nombre_externo2))
               WHERE (nombre_pareja IS NULL OR nombre_pareja = '' OR nombre_pareja = 'Pareja' OR nombre_pareja = 'Jugador 1 / Jugador 2' OR nombre_pareja LIKE '%Jugador 1%')
                 AND nombre_externo1 IS NOT NULL AND TRIM(nombre_externo1) != ''
                 AND nombre_externo2 IS NOT NULL AND TRIM(nombre_externo2) != ''");

@$conn->query("UPDATE liga_parejas lp
               JOIN usuarios u1 ON lp.jugador1_id = u1.id
               JOIN usuarios u2 ON lp.jugador2_id = u2.id
               SET lp.nombre_pareja = CONCAT(TRIM(CONCAT(u1.nombre, ' ', COALESCE(u1.apellido, ''))), ' / ', TRIM(CONCAT(u2.nombre, ' ', COALESCE(u2.apellido, ''))))
               WHERE (lp.nombre_pareja IS NULL OR lp.nombre_pareja = '' OR lp.nombre_pareja = 'Pareja' OR lp.nombre_pareja = 'Jugador 1 / Jugador 2' OR lp.nombre_pareja LIKE '%Jugador 1%')");

@$conn->query("UPDATE liga_parejas lp
               JOIN usuarios u1 ON lp.jugador1_id = u1.id
               SET lp.nombre_pareja = CONCAT(TRIM(CONCAT(u1.nombre, ' ', COALESCE(u1.apellido, ''))), ' / ', TRIM(lp.nombre_externo2))
               WHERE (lp.nombre_pareja IS NULL OR lp.nombre_pareja = '' OR lp.nombre_pareja = 'Pareja' OR lp.nombre_pareja = 'Jugador 1 / Jugador 2' OR lp.nombre_pareja LIKE '%Jugador 1%')
                 AND lp.nombre_externo2 IS NOT NULL AND TRIM(lp.nombre_externo2) != ''");

@$conn->query("UPDATE liga_parejas lp
               JOIN usuarios u2 ON lp.jugador2_id = u2.id
               SET lp.nombre_pareja = CONCAT(TRIM(lp.nombre_externo1), ' / ', TRIM(CONCAT(u2.nombre, ' ', COALESCE(u2.apellido, ''))))
               WHERE (lp.nombre_pareja IS NULL OR lp.nombre_pareja = '' OR lp.nombre_pareja = 'Pareja' OR lp.nombre_pareja = 'Jugador 1 / Jugador 2' OR lp.nombre_pareja LIKE '%Jugador 1%')
                 AND lp.nombre_externo1 IS NOT NULL AND TRIM(lp.nombre_externo1) != ''");

// Auto-sincronizar partidos de liga con sus reservas de cancha existentes en el club
@$conn->query("UPDATE liga_partidos lp 
               JOIN reservas_cancha rc ON rc.liga_partido_id = lp.id 
               SET lp.cancha_id = rc.cancha_id, 
                   lp.fecha_hora = CONCAT(rc.fecha, ' ', rc.hora_inicio)
               WHERE (lp.cancha_id IS NULL OR lp.cancha_id = 0 OR lp.fecha_hora IS NULL OR lp.fecha_hora <= '2000-01-01 00:00:00')
                 AND rc.cancha_id IS NOT NULL 
                 AND rc.fecha IS NOT NULL 
                 AND rc.hora_inicio IS NOT NULL 
                 AND rc.estado != 'Cancelada'");

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null);
$estado_filtro = $_GET['estado'] ?? null;
$mobile_only = isset($_GET['mobile_only']) ? (bool)$_GET['mobile_only'] : false;

try {
    if ($id) {
        // 1. Obtener datos de la Liga
        $res = $conn->query("SELECT * FROM ligas WHERE id = $id");
        if (!$res || $res->num_rows === 0) {
            echo json_encode(["success" => false, "error" => "Liga no encontrada"]);
            exit;
        }
        $liga = $res->fetch_assoc();
        $liga_p = (float)($liga['precio'] ?? 0.00);
        if ($liga_p > 0 && $liga_p < 1000) {
            $liga_p = $liga_p * 1000;
        } else if ($liga_p == 0 && !empty($liga['descripcion'])) {
            if (preg_match('/(?:\$|valor|precio|inscripci[oó]n|costo)[:\s]*\$?\s*([0-9]{1,3}(?:\.[0-9]{3})+|[0-9]{4,6})/i', $liga['descripcion'], $m)) {
                $ext_p = (float)str_replace('.', '', $m[1]);
                if ($ext_p > 0) $liga_p = $ext_p;
            }
        }
        $liga['precio'] = $liga_p;
        $liga['reglas_puntos_json'] = json_decode($liga['reglas_puntos_json'], true);
        $user_id_req = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

        // Obtener clubes asociados al usuario
        $mis_clubes = [];
        if ($user_id_req > 0) {
            $res_mc = $conn->query("SELECT DISTINCT c.id, c.nombre FROM clubes c 
                                    LEFT JOIN usuarios_clubes uc ON uc.club_id = c.id 
                                    WHERE c.admin_id = $user_id_req OR (uc.usuario_id = $user_id_req AND uc.activo = 1) 
                                    ORDER BY c.nombre ASC");
            if ($res_mc) {
                while ($mc = $res_mc->fetch_assoc()) {
                    $mis_clubes[] = $mc;
                }
            }
        }
        if (empty($mis_clubes)) {
            $res_mc_all = $conn->query("SELECT id, nombre FROM clubes ORDER BY id ASC");
            if ($res_mc_all) {
                while ($mc = $res_mc_all->fetch_assoc()) {
                    $mis_clubes[] = $mc;
                }
            }
        }

        // Determinar club objetivo
        $club_target_id = $club_id > 0 ? $club_id : (int)($liga['club_id'] ?? 0);
        if ($club_target_id === 0 && !empty($mis_clubes)) {
            $club_target_id = (int)$mis_clubes[0]['id'];
        }

        // Obtener Canchas del club activo
        $canchas = [];
        if ($club_target_id > 0) {
            $res_canchas = $conn->query("SELECT id, nombre FROM canchas WHERE club_id = $club_target_id ORDER BY id ASC");
            if ($res_canchas) {
                while ($c = $res_canchas->fetch_assoc()) {
                    $canchas[] = $c;
                }
            }
        }

        // Si el club seleccionado no tiene canchas registradas, intentar con el primer club de mis_clubes que sí tenga canchas
        if (empty($canchas) && !empty($mis_clubes)) {
            foreach ($mis_clubes as $mc) {
                $alt_cid = (int)$mc['id'];
                $res_alt = $conn->query("SELECT id, nombre FROM canchas WHERE club_id = $alt_cid ORDER BY id ASC");
                if ($res_alt && $res_alt->num_rows > 0) {
                    $canchas = [];
                    while ($c = $res_alt->fetch_assoc()) {
                        $canchas[] = $c;
                    }
                    $club_target_id = $alt_cid;
                    break;
                }
            }
        }

        // Obtener Categorías
        $res_cat = $conn->query("SELECT * FROM liga_categorias WHERE liga_id = $id ORDER BY id ASC");
        $categorias = [];
        $total_parejas_liga = 0;
        $total_partidos_liga = 0;
        $partidos_jugados_liga = 0;

        if ($res_cat) {
            while ($cat = $res_cat->fetch_assoc()) {
                $cat_id = (int)$cat['id'];
                $cat['dias_juego'] = !empty($cat['dias_juego_json']) ? json_decode($cat['dias_juego_json'], true) : [];

                // Parejas Inscritas
                $sql_p = "SELECT p.*, u1.nombre as p1_nom, COALESCE(u1.apellido, '') as p1_ape, COALESCE(u1.foto_perfil, u1.foto, '') as p1_foto,
                                 u2.nombre as p2_nom, COALESCE(u2.apellido, '') as p2_ape, COALESCE(u2.foto_perfil, u2.foto, '') as p2_foto
                          FROM liga_parejas p
                          LEFT JOIN usuarios u1 ON p.jugador1_id = u1.id
                          LEFT JOIN usuarios u2 ON p.jugador2_id = u2.id
                          WHERE p.categoria_id = $cat_id ORDER BY p.id DESC";
                $res_p = $conn->query($sql_p);
                $parejas = [];
                if ($res_p) {
                    while ($par = $res_p->fetch_assoc()) {
                        $nom1 = trim(($par['p1_nom'] ?? '') . ' ' . ($par['p1_ape'] ?? ''));
                        if (empty($nom1)) {
                            $nom1 = !empty($par['nombre_externo1']) ? $par['nombre_externo1'] : 'Jugador 1';
                        }
                        $nom2 = trim(($par['p2_nom'] ?? '') . ' ' . ($par['p2_ape'] ?? ''));
                        if (empty($nom2)) {
                            $nom2 = !empty($par['nombre_externo2']) ? $par['nombre_externo2'] : 'Jugador 2';
                        }

                        if (empty($par['nombre_pareja']) || strpos($par['nombre_pareja'], ' / ') !== false || $par['nombre_pareja'] === 'Pareja') {
                            $par['nombre_pareja'] = $nom1 . ' / ' . $nom2;
                        }

                        if (!empty($par['id']) && $par['nombre_pareja'] !== 'Jugador 1 / Jugador 2' && strpos($par['nombre_pareja'], 'Jugador 1') === false && strpos($par['nombre_pareja'], 'Jugador 2') === false) {
                            @$conn->query("UPDATE liga_parejas SET nombre_pareja = '" . $conn->real_escape_string($par['nombre_pareja']) . "' WHERE id = " . (int)$par['id'] . " AND (nombre_pareja = 'Jugador 1 / Jugador 2' OR nombre_pareja LIKE '%Jugador 1%' OR nombre_pareja = 'Pareja' OR nombre_pareja IS NULL OR nombre_pareja = '')");
                        }
                        $parejas[] = $par;
                    }
                }
                $cat['parejas'] = $parejas;
                $cat['parejas_count'] = count($parejas);
                $total_parejas_liga += count($parejas);

                // Grupos
                $res_g = $conn->query("SELECT * FROM liga_grupos WHERE categoria_id = $cat_id ORDER BY id ASC");
                $grupos = [];
                if ($res_g) {
                    while ($g = $res_g->fetch_assoc()) {
                        $grupos[] = $g;
                    }
                }
                $cat['grupos'] = $grupos;

                // Jornadas & Partidos
                $res_j = $conn->query("SELECT * FROM liga_jornadas WHERE categoria_id = $cat_id ORDER BY numero_jornada ASC");
                $jornadas = [];
                if ($res_j) {
                    while ($j = $res_j->fetch_assoc()) {
                        $j_id = (int)$j['id'];
                        $sql_partidos = "SELECT p.*, cn.nombre as cancha_nombre,
                            par1.nombre_pareja as pareja1_nombre, par1.nombre_externo1 as p1_ext1, par1.nombre_externo2 as p1_ext2,
                            par2.nombre_pareja as pareja2_nombre, par2.nombre_externo1 as p2_ext1, par2.nombre_externo2 as p2_ext2,
                            u1.nombre as p1_u1_nom, COALESCE(u1.apellido, '') as p1_u1_ape, COALESCE(u1.foto_perfil, u1.foto, '') as p1_u1_foto, COALESCE(u1.telefono, '') as p1_u1_tel,
                            u2.nombre as p1_u2_nom, COALESCE(u2.apellido, '') as p1_u2_ape, COALESCE(u2.foto_perfil, u2.foto, '') as p1_u2_foto, COALESCE(u2.telefono, '') as p1_u2_tel,
                            u3.nombre as p2_u1_nom, COALESCE(u3.apellido, '') as p2_u1_ape, COALESCE(u3.foto_perfil, u3.foto, '') as p2_u1_foto, COALESCE(u3.telefono, '') as p2_u1_tel,
                            u4.nombre as p2_u2_nom, COALESCE(u4.apellido, '') as p2_u2_ape, COALESCE(u4.foto_perfil, u4.foto, '') as p2_u2_foto, COALESCE(u4.telefono, '') as p2_u2_tel
                            FROM liga_partidos p
                            LEFT JOIN canchas cn ON p.cancha_id = cn.id
                            LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                            LEFT JOIN usuarios u1 ON par1.jugador1_id = u1.id
                            LEFT JOIN usuarios u2 ON par1.jugador2_id = u2.id
                            LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                            LEFT JOIN usuarios u3 ON par2.jugador1_id = u3.id
                            LEFT JOIN usuarios u4 ON par2.jugador2_id = u4.id
                            WHERE p.jornada_id = $j_id ORDER BY p.id ASC";
                        
                        $res_part = $conn->query($sql_partidos);
                        $partidos = [];
                        if ($res_part) {
                            while ($pt = $res_part->fetch_assoc()) {
                                $n1 = trim(($pt['p1_u1_nom'] ?? '') . ' ' . ($pt['p1_u1_ape'] ?? ''));
                                if (empty($n1)) $n1 = !empty($pt['p1_ext1']) ? $pt['p1_ext1'] : 'Jugador 1';
                                $n2 = trim(($pt['p1_u2_nom'] ?? '') . ' ' . ($pt['p1_u2_ape'] ?? ''));
                                if (empty($n2)) $n2 = !empty($pt['p1_ext2']) ? $pt['p1_ext2'] : 'Jugador 2';
                                if (empty($pt['pareja1_nombre']) || strpos($pt['pareja1_nombre'], ' / ') !== false || $pt['pareja1_nombre'] === 'Pareja') {
                                    $pt['pareja1_nombre'] = $n1 . ' / ' . $n2;
                                }

                                $n3 = trim(($pt['p2_u1_nom'] ?? '') . ' ' . ($pt['p2_u1_ape'] ?? ''));
                                if (empty($n3)) $n3 = !empty($pt['p2_ext1']) ? $pt['p2_ext1'] : 'Jugador 1';
                                $n4 = trim(($pt['p2_u2_nom'] ?? '') . ' ' . ($pt['p2_u2_ape'] ?? ''));
                                if (empty($n4)) $n4 = !empty($pt['p2_ext2']) ? $pt['p2_ext2'] : 'Jugador 2';
                                if (empty($pt['pareja2_nombre']) || strpos($pt['pareja2_nombre'], ' / ') !== false || $pt['pareja2_nombre'] === 'Pareja') {
                                    $pt['pareja2_nombre'] = $n3 . ' / ' . $n4;
                                }

                                $total_partidos_liga++;
                                if (in_array($pt['estado'], ['Jugado', 'Walkover'])) {
                                    $partidos_jugados_liga++;
                                }
                                $partidos[] = $pt;
                            }
                        }
                        $j['partidos'] = $partidos;
                        $jornadas[] = $j;
                    }
                }
                $cat['jornadas'] = $jornadas;

                // Playoffs / Eliminatorias
                $sql_playoffs = "SELECT p.*, cn.nombre as cancha_nombre,
                    par1.nombre_pareja as pareja1_nombre, par1.nombre_externo1 as p1_ext1, par1.nombre_externo2 as p1_ext2,
                    par2.nombre_pareja as pareja2_nombre, par2.nombre_externo1 as p2_ext1, par2.nombre_externo2 as p2_ext2,
                    u1.nombre as p1_u1_nom, COALESCE(u1.apellido, '') as p1_u1_ape, COALESCE(u1.foto_perfil, u1.foto, '') as p1_u1_foto, COALESCE(u1.telefono, '') as p1_u1_tel,
                    u2.nombre as p1_u2_nom, COALESCE(u2.apellido, '') as p1_u2_ape, COALESCE(u2.foto_perfil, u2.foto, '') as p1_u2_foto, COALESCE(u2.telefono, '') as p1_u2_tel,
                    u3.nombre as p2_u1_nom, COALESCE(u3.apellido, '') as p2_u1_ape, COALESCE(u3.foto_perfil, u3.foto, '') as p2_u1_foto, COALESCE(u3.telefono, '') as p2_u1_tel,
                    u4.nombre as p2_u2_nom, COALESCE(u4.apellido, '') as p2_u2_ape, COALESCE(u4.foto_perfil, u4.foto, '') as p2_u2_foto, COALESCE(u4.telefono, '') as p2_u2_tel
                    FROM liga_partidos p
                    LEFT JOIN canchas cn ON p.cancha_id = cn.id
                    LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                    LEFT JOIN usuarios u1 ON par1.jugador1_id = u1.id
                    LEFT JOIN usuarios u2 ON par1.jugador2_id = u2.id
                    LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                    LEFT JOIN usuarios u3 ON par2.jugador1_id = u3.id
                    LEFT JOIN usuarios u4 ON par2.jugador2_id = u4.id
                    WHERE (p.categoria_id = $cat_id OR par1.categoria_id = $cat_id OR p.jornada_id IN (SELECT id FROM liga_jornadas WHERE categoria_id = $cat_id)) AND p.es_playoff = 1 
                    ORDER BY p.id ASC";
                $res_po = $conn->query($sql_playoffs);
                $playoffs = [];
                if ($res_po) {
                    while ($po = $res_po->fetch_assoc()) {
                        $obs = !empty($po['observaciones']) ? json_decode($po['observaciones'], true) : [];
                        
                        if (!empty($po['pareja1_id'])) {
                            $n1 = trim(($po['p1_u1_nom'] ?? '') . ' ' . ($po['p1_u1_ape'] ?? ''));
                            if (empty($n1)) $n1 = !empty($po['p1_ext1']) ? $po['p1_ext1'] : 'Jugador 1';
                            $n2 = trim(($po['p1_u2_nom'] ?? '') . ' ' . ($po['p1_u2_ape'] ?? ''));
                            if (empty($n2)) $n2 = !empty($po['p1_ext2']) ? $po['p1_ext2'] : 'Jugador 2';
                            if (empty($po['pareja1_nombre']) || strpos($po['pareja1_nombre'], ' / ') !== false || $po['pareja1_nombre'] === 'Pareja') {
                                $po['pareja1_nombre'] = $n1 . ' / ' . $n2;
                            }
                        } else {
                            $po['pareja1_nombre'] = $obs['seed1_label'] ?? 'Por definir';
                        }

                        if (!empty($po['pareja2_id'])) {
                            $n3 = trim(($po['p2_u1_nom'] ?? '') . ' ' . ($po['p2_u1_ape'] ?? ''));
                            if (empty($n3)) $n3 = !empty($po['p2_ext1']) ? $po['p2_ext1'] : 'Jugador 1';
                            $n4 = trim(($po['p2_u2_nom'] ?? '') . ' ' . ($po['p2_u2_ape'] ?? ''));
                            if (empty($n4)) $n4 = !empty($po['p2_ext2']) ? $po['p2_ext2'] : 'Jugador 2';
                            if (empty($po['pareja2_nombre']) || strpos($po['pareja2_nombre'], ' / ') !== false || $po['pareja2_nombre'] === 'Pareja') {
                                $po['pareja2_nombre'] = $n3 . ' / ' . $n4;
                            }
                        } else {
                            $po['pareja2_nombre'] = $obs['seed2_label'] ?? 'Por definir';
                        }

                        $po['meta_playoff'] = $obs;
                        $playoffs[] = $po;
                    }
                }
                $cat['playoffs'] = $playoffs;

                $categorias[] = $cat;
            }
        }

        $liga['categorias'] = $categorias;
        $liga['canchas'] = $canchas;
        $liga['mis_clubes'] = $mis_clubes;
        $liga['club_id_activo'] = $club_target_id;
        $liga['stats'] = [
            'total_parejas' => $total_parejas_liga,
            'total_partidos' => $total_partidos_liga,
            'partidos_jugados' => $partidos_jugados_liga,
            'partidos_pendientes' => $total_partidos_liga - $partidos_jugados_liga,
            'porcentaje_progreso' => $total_partidos_liga > 0 ? round(($partidos_jugados_liga / $total_partidos_liga) * 100) : 0
        ];

        if (ob_get_length()) ob_clean();
        echo json_encode(["success" => true, "liga" => $liga]);

    } else {
        // Listar ligas con filtros
        $conditions = [];
        if ($club_id) {
            $conditions[] = "l.club_id = $club_id";
        } else if ($user_id) {
            $conditions[] = "(l.club_id IN (
                SELECT id FROM clubes WHERE admin_id = $user_id 
                UNION 
                SELECT club_id FROM club_staff WHERE usuario_id = $user_id
                UNION
                SELECT club_id FROM usuarios WHERE id = $user_id AND club_id IS NOT NULL AND club_id > 0
            ))";
        }
        if ($estado_filtro === 'activas') {
            $conditions[] = "l.estado IN ('Inscripción', 'En Curso', 'Playoffs')";
        } else if ($estado_filtro === 'historicas') {
            $conditions[] = "l.estado = 'Finalizada'";
        }

        $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT l.*, c.nombre as club_nombre, c.direccion as club_direccion,
                (SELECT COUNT(*) FROM liga_categorias cat WHERE cat.liga_id = l.id) as num_categorias,
                (SELECT COUNT(*) FROM liga_parejas p JOIN liga_categorias cat2 ON p.categoria_id = cat2.id WHERE cat2.liga_id = l.id) as total_parejas
                FROM ligas l 
                LEFT JOIN clubes c ON l.club_id = c.id 
                $where ORDER BY l.id DESC";
        
        $res = $conn->query($sql);
        $ligas = [];
        if ($res) {
            while ($l = $res->fetch_assoc()) {
                $p = (float)($l['precio'] ?? 0.00);
                if ($p > 0 && $p < 1000) {
                    $p = $p * 1000;
                } else if ($p == 0 && !empty($l['descripcion'])) {
                    if (preg_match('/(?:\$|valor|precio|inscripci[oó]n|costo)[:\s]*\$?\s*([0-9]{1,3}(?:\.[0-9]{3})+|[0-9]{4,6})/i', $l['descripcion'], $m)) {
                        $ext_p = (float)str_replace('.', '', $m[1]);
                        if ($ext_p > 0) $p = $ext_p;
                    }
                }
                $l['precio'] = $p;
                $l['reglas_puntos_json'] = json_decode($l['reglas_puntos_json'], true);
                $ligas[] = $l;
            }
        }
        if (ob_get_length()) ob_clean();
        echo json_encode(["success" => true, "ligas" => $ligas]);
    }
} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
