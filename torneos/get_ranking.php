<?php
error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

$ranking = [];

try {
    // SILENT SCHEMA FIX: Ensure tables and columns exist safely inside try block
    $check_club = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'club_id'");
    if ($check_club && $check_club->num_rows == 0) {
        @$conn->query("ALTER TABLE usuarios ADD `club_id` INT NULL AFTER rol");
    }

    $check_pts = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'puntos_ranking'");
    if ($check_pts && $check_pts->num_rows == 0) {
        @$conn->query("ALTER TABLE usuarios ADD `puntos_ranking` INT DEFAULT 0");
    }

    $check_foto = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'foto'");
    if ($check_foto && $check_foto->num_rows == 0) {
        @$conn->query("ALTER TABLE usuarios ADD `foto` VARCHAR(255) NULL");
    }

    $check_foto_p = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'foto_perfil'");
    if ($check_foto_p && $check_foto_p->num_rows == 0) {
        @$conn->query("ALTER TABLE usuarios ADD `foto_perfil` VARCHAR(255) NULL");
    }

    $check_ape = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'apellido'");
    if ($check_ape && $check_ape->num_rows == 0) {
        @$conn->query("ALTER TABLE usuarios ADD `apellido` VARCHAR(100) NULL AFTER nombre");
    }

    @$conn->query("CREATE TABLE IF NOT EXISTS `ranking_categorias` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `usuario_id` INT NOT NULL,
      `categoria` VARCHAR(50) NOT NULL,
      `puntos` INT DEFAULT 0,
      `actualizado_el` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_cat_user` (`categoria`, `usuario_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $categoria = isset($_GET['categoria']) ? trim($_GET['categoria']) : 'General';
    $club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null);

    if (!$club_id && $user_id) {
        $resClub = $conn->query("SELECT id FROM clubes WHERE admin_id = $user_id LIMIT 1");
        if ($resClub && $resClub->num_rows > 0) {
            $club_id = (int)$resClub->fetch_assoc()['id'];
        } else {
            $resUserClub = $conn->query("SELECT club_id FROM usuarios WHERE id = $user_id AND club_id > 0 LIMIT 1");
            if ($resUserClub && $resUserClub->num_rows > 0) {
                $club_id = (int)$resUserClub->fetch_assoc()['club_id'];
            }
        }
    }

    $check_link = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'link_foto'");
    $hasLink = ($check_link && $check_link->num_rows > 0);

    // Detect available photo columns safely
    $fotoCols = [];
    if ($check_foto_p && $check_foto_p->num_rows > 0) $fotoCols[] = "NULLIF(u.foto_perfil, '')";
    if ($check_foto && $check_foto->num_rows > 0) $fotoCols[] = "NULLIF(u.foto, '')";
    if ($hasLink) $fotoCols[] = "NULLIF(u.link_foto, '')";

    $fotoSelect = !empty($fotoCols) ? "COALESCE(" . implode(", ", $fotoCols) . ", '') as foto" : "'' as foto";

    // Subquery para validar pertenencia o participación en el club
    $clubFilterClause = "";
    if ($club_id && $club_id > 0) {
        $cid = (int)$club_id;
        $clubFilterClause = " AND (
            u.club_id = $cid 
            OR u.id IN (SELECT usuario_id FROM usuarios_clubes WHERE club_id = $cid)
            OR u.id IN (SELECT tp.jugador_id FROM torneo_participantes tp JOIN torneos_americanos t ON tp.torneo_id = t.id WHERE t.club_id = $cid)
            OR u.id IN (SELECT tp.jugador2_id FROM torneo_participantes tp JOIN torneos_americanos t ON tp.torneo_id = t.id WHERE t.club_id = $cid)
            OR u.id IN (SELECT lp.jugador1_id FROM liga_parejas lp JOIN liga_categorias lc ON lp.categoria_id = lc.id JOIN ligas l ON lc.liga_id = l.id WHERE l.club_id = $cid)
            OR u.id IN (SELECT lp.jugador2_id FROM liga_parejas lp JOIN liga_categorias lc ON lp.categoria_id = lc.id JOIN ligas l ON lc.liga_id = l.id WHERE l.club_id = $cid)
        )";
    }

    if ($categoria === 'General' || empty($categoria)) {
        // Global ranking from usuarios table: Only users with puntos_ranking > 0
        $sql = "SELECT u.id, u.nombre, COALESCE(u.apellido, '') as apellido, u.usuario, 
                       COALESCE(u.puntos_ranking, 0) as puntos_ranking, 
                       $fotoSelect, 
                       COALESCE(u.rol, 'jugador') as role 
                FROM usuarios u 
                WHERE COALESCE(u.puntos_ranking, 0) > 0" . $clubFilterClause;
        
        $sql .= " ORDER BY u.puntos_ranking DESC, u.nombre ASC";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $pts = (int)($row['puntos_ranking'] ?? 0);
                if ($pts <= 0) continue;

                $nombreCompleto = trim(($row['nombre'] ?? 'Jugador') . ' ' . ($row['apellido'] ?? ''));
                if (empty($nombreCompleto)) $nombreCompleto = 'Jugador';
                
                $ranking[] = [
                    "id" => (int)$row['id'],
                    "nombre" => $nombreCompleto,
                    "usuario" => $row['usuario'] ?? '',
                    "puntos_ranking" => $pts,
                    "foto" => $row['foto'] ?? '',
                    "role" => $row['role'] ?? 'jugador'
                ];
            }
        }
    } else {
        // Category ranking: Only players with puntos > 0 in this specific category
        $catEscaped = $conn->real_escape_string($categoria);
        $sql = "SELECT u.id, u.nombre, COALESCE(u.apellido, '') as apellido, u.usuario, 
                       rc.puntos as puntos_ranking, 
                       $fotoSelect, 
                       COALESCE(u.rol, 'jugador') as role 
                FROM ranking_categorias rc
                JOIN usuarios u ON rc.usuario_id = u.id
                WHERE rc.categoria = '$catEscaped' AND rc.puntos > 0" . $clubFilterClause;
                
        $sql .= " ORDER BY rc.puntos DESC, u.nombre ASC";
        
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $pts = (int)($row['puntos_ranking'] ?? 0);
                if ($pts <= 0) continue;

                $nombreCompleto = trim(($row['nombre'] ?? 'Jugador') . ' ' . ($row['apellido'] ?? ''));
                if (empty($nombreCompleto)) $nombreCompleto = 'Jugador';

                $ranking[] = [
                    "id" => (int)$row['id'],
                    "nombre" => $nombreCompleto,
                    "usuario" => $row['usuario'] ?? '',
                    "puntos_ranking" => $pts,
                    "foto" => $row['foto'] ?? '',
                    "role" => $row['role'] ?? 'jugador'
                ];
            }
        }
    }
} catch (Throwable $e) {
    error_log("Error in get_ranking.php: " . $e->getMessage());
}

http_response_code(200);
echo json_encode($ranking);
?>
