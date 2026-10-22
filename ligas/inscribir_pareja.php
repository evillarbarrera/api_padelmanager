<?php
ini_set('display_errors', '0');
error_reporting(0);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";
require_once "posiciones_helper.php";

// SILENT SCHEMA FIX: Crear liga_parejas y columnas requeridas
function ensureParejaSchema($conn) {
    @$conn->query("CREATE TABLE IF NOT EXISTS `liga_parejas` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `categoria_id` INT NOT NULL,
      `jugador1_id` INT NULL,
      `jugador2_id` INT NULL,
      `nombre_externo1` VARCHAR(150) NULL,
      `nombre_externo2` VARCHAR(150) NULL,
      `nombre_pareja` VARCHAR(255) NULL,
      `estado` VARCHAR(50) DEFAULT 'Confirmada',
      `restricciones_horarias_json` TEXT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("ALTER TABLE liga_parejas MODIFY jugador1_id INT NULL");
    @$conn->query("ALTER TABLE liga_parejas MODIFY jugador2_id INT NULL");

    $cols = [
        'nombre_externo1' => "VARCHAR(150) NULL",
        'nombre_externo2' => "VARCHAR(150) NULL",
        'nombre_pareja' => "VARCHAR(255) NULL",
        'estado' => "VARCHAR(50) DEFAULT 'Confirmada'",
        'restricciones_horarias_json' => "TEXT NULL"
    ];
    foreach ($cols as $col => $def) {
        $check = @$conn->query("SHOW COLUMNS FROM liga_parejas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            @$conn->query("ALTER TABLE liga_parejas ADD `$col` $def");
        }
    }
}
ensureParejaSchema($conn);

$input = json_decode(file_get_contents("php://input"), true);

$id = !empty($input['id']) ? (int)$input['id'] : (!empty($input['pareja_id']) ? (int)$input['pareja_id'] : null);
$categoria_id = !empty($input['categoria_id']) ? (int)$input['categoria_id'] : null;
$jugador1_id = !empty($input['jugador1_id']) ? (int)$input['jugador1_id'] : null;
$jugador2_id = !empty($input['jugador2_id']) ? (int)$input['jugador2_id'] : null;

$nombre_externo1 = trim($input['nombre_externo1'] ?? $input['j1_nombre'] ?? '');
$nombre_externo2 = trim($input['nombre_externo2'] ?? $input['j2_nombre'] ?? '');
$nombre_pareja = trim($input['nombre_pareja'] ?? '');
$restricciones_horarias = $input['restricciones_horarias'] ?? null;
$restricciones_json = !empty($restricciones_horarias) ? json_encode($restricciones_horarias) : null;

if (!$categoria_id) {
    echo json_encode(["success" => false, "error" => "ID de categoría requerido"]);
    exit;
}

if (!$jugador1_id && empty($nombre_externo1)) {
    echo json_encode(["success" => false, "error" => "Debes ingresar el nombre o seleccionar al Jugador 1"]);
    exit;
}

if (!$jugador2_id && empty($nombre_externo2)) {
    echo json_encode(["success" => false, "error" => "Debes ingresar el nombre o seleccionar al Jugador 2"]);
    exit;
}

if ($jugador1_id && $jugador2_id && $jugador1_id === $jugador2_id) {
    echo json_encode(["success" => false, "error" => "Los dos jugadores no pueden ser el mismo usuario registrado"]);
    exit;
}

try {
    // Validar si algún jugador registrado ya está en esta categoría (excluyendo la pareja actual si estamos editando)
    if ($jugador1_id || $jugador2_id) {
        $j1 = $jugador1_id ?? 0;
        $j2 = $jugador2_id ?? 0;
        $sql_check = "SELECT id FROM liga_parejas WHERE categoria_id = ? AND ((jugador1_id IS NOT NULL AND jugador1_id > 0 AND jugador1_id IN (?, ?)) OR (jugador2_id IS NOT NULL AND jugador2_id > 0 AND jugador2_id IN (?, ?))) AND estado != 'Cancelada'";
        if ($id) {
            $sql_check .= " AND id != " . (int)$id;
        }
        $stmt_check = $conn->prepare($sql_check);
        if ($stmt_check) {
            $stmt_check->bind_param("iiiii", $categoria_id, $j1, $j2, $j1, $j2);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                echo json_encode(["success" => false, "error" => "Uno de los jugadores seleccionados ya se encuentra inscrito en esta categoría"]);
                exit;
            }
        }
    }

    // Calcular nombres reales para nombre_pareja si corresponde
    $nom1 = '';
    if ($jugador1_id) {
        $u1_res = $conn->query("SELECT nombre, apellido, usuario, usuario as email FROM usuarios WHERE id = $jugador1_id");
        if ($u1_res && $u1 = $u1_res->fetch_assoc()) {
            $nom1 = trim(($u1['nombre'] ?? '') . ' ' . ($u1['apellido'] ?? ''));
            if (empty($nom1)) $nom1 = $u1['usuario'] ?? $u1['email'] ?? 'Jugador 1';
        }
    }
    if (empty($nom1)) $nom1 = $nombre_externo1 ?: 'Jugador 1';

    $nom2 = '';
    if ($jugador2_id) {
        $u2_res = $conn->query("SELECT nombre, apellido, usuario, usuario as email FROM usuarios WHERE id = $jugador2_id");
        if ($u2_res && $u2 = $u2_res->fetch_assoc()) {
            $nom2 = trim(($u2['nombre'] ?? '') . ' ' . ($u2['apellido'] ?? ''));
            if (empty($nom2)) $nom2 = $u2['usuario'] ?? $u2['email'] ?? 'Jugador 2';
        }
    }
    if (empty($nom2)) $nom2 = $nombre_externo2 ?: 'Jugador 2';

    if (empty($nombre_pareja) || $nombre_pareja === 'Pareja' || strpos($nombre_pareja, ' / ') !== false) {
        $nombre_pareja = "$nom1 / $nom2";
    }

    if ($id) {
        $stmt = $conn->prepare("UPDATE liga_parejas SET categoria_id=?, jugador1_id=?, jugador2_id=?, nombre_externo1=?, nombre_externo2=?, nombre_pareja=?, restricciones_horarias_json=? WHERE id=?");
        if (!$stmt) {
            echo json_encode(["success" => false, "error" => "Error al preparar la actualización: " . $conn->error]);
            exit;
        }

        $stmt->bind_param("iiissssi", $categoria_id, $jugador1_id, $jugador2_id, $nombre_externo1, $nombre_externo2, $nombre_pareja, $restricciones_json, $id);
        if (!$stmt->execute()) {
            echo json_encode(["success" => false, "error" => "Error al actualizar la inscripción: " . $stmt->error]);
            exit;
        }

        // Sincronizar reservas existentes si los partidos de esta pareja ya estaban programados
        $res_partidos = $conn->query("SELECT p.id, p.cancha_id, p.fecha_hora 
                                      FROM liga_partidos p 
                                      WHERE (p.pareja1_id = $id OR p.pareja2_id = $id) 
                                        AND p.cancha_id IS NOT NULL 
                                        AND p.fecha_hora IS NOT NULL 
                                        AND p.fecha_hora > '2000-01-01 00:00:00'");
        if ($res_partidos && $res_partidos->num_rows > 0) {
            while ($ptRow = $res_partidos->fetch_assoc()) {
                $pId = (int)$ptRow['id'];
                $sql_det = "SELECT p.pareja1_id, p.pareja2_id,
                                   par1.jugador1_id as p1_j1_id, par1.jugador2_id as p1_j2_id,
                                   par2.jugador1_id as p2_j1_id, par2.jugador2_id as p2_j2_id,
                                   par1.nombre_externo1 as p1_ext1, par1.nombre_externo2 as p1_ext2,
                                   par2.nombre_externo1 as p2_ext1, par2.nombre_externo2 as p2_ext2,
                                   u11.nombre as p1_j1_u, COALESCE(u11.apellido, '') as p1_j1_ape,
                                   u12.nombre as p1_j2_u, COALESCE(u12.apellido, '') as p1_j2_ape,
                                   u21.nombre as p2_j1_u, COALESCE(u21.apellido, '') as p2_j1_ape,
                                   u22.nombre as p2_j2_u, COALESCE(u22.apellido, '') as p2_j2_ape
                            FROM liga_partidos p
                            LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                            LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                            LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
                            LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
                            LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
                            LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
                            WHERE p.id = $pId";
                $res_d = $conn->query($sql_det);
                if ($res_d && $row_d = $res_d->fetch_assoc()) {
                    $j1_id = (!empty($row_d['p1_j1_id']) && (int)$row_d['p1_j1_id'] > 0) ? (int)$row_d['p1_j1_id'] : null;
                    $j2_id = (!empty($row_d['p1_j2_id']) && (int)$row_d['p1_j2_id'] > 0) ? (int)$row_d['p1_j2_id'] : null;
                    $j3_id = (!empty($row_d['p2_j1_id']) && (int)$row_d['p2_j1_id'] > 0) ? (int)$row_d['p2_j1_id'] : null;
                    $j4_id = (!empty($row_d['p2_j2_id']) && (int)$row_d['p2_j2_id'] > 0) ? (int)$row_d['p2_j2_id'] : null;

                    $n1 = trim(($row_d['p1_j1_u'] ?? '') . ' ' . ($row_d['p1_j1_ape'] ?? ''));
                    $ext1 = !empty($n1) ? $n1 : (!empty($row_d['p1_ext1']) ? $row_d['p1_ext1'] : 'P1-J1');

                    $n2 = trim(($row_d['p1_j2_u'] ?? '') . ' ' . ($row_d['p1_j2_ape'] ?? ''));
                    $ext2 = !empty($n2) ? $n2 : (!empty($row_d['p1_ext2']) ? $row_d['p1_ext2'] : 'P1-J2');

                    $n3 = trim(($row_d['p2_j1_u'] ?? '') . ' ' . ($row_d['p2_j1_ape'] ?? ''));
                    $ext3 = !empty($n3) ? $n3 : (!empty($row_d['p2_ext1']) ? $row_d['p2_ext1'] : 'P2-J1');

                    $n4 = trim(($row_d['p2_j2_u'] ?? '') . ' ' . ($row_d['p2_j2_ape'] ?? ''));
                    $ext4 = !empty($n4) ? $n4 : (!empty($row_d['p2_ext2']) ? $row_d['p2_ext2'] : 'P2-J2');

                    $stmt_up_res = $conn->prepare("UPDATE reservas_cancha 
                                                  SET usuario_id = ?, jugador2_id = ?, jugador3_id = ?, jugador4_id = ?,
                                                      nombre_externo = ?, nombre_externo2 = ?, nombre_externo3 = ?, nombre_externo4 = ?
                                                  WHERE liga_partido_id = ?");
                    if ($stmt_up_res) {
                        $stmt_up_res->bind_param("iiiiisssi", $j1_id, $j2_id, $j3_id, $j4_id, $ext1, $ext2, $ext3, $ext4, $pId);
                        $stmt_up_res->execute();
                    }
                }
            }
        }

        // Recalcular posiciones
        recalcularPosicionesCategoria($conn, $categoria_id);

        echo json_encode(["success" => true, "mensaje" => "Pareja actualizada exitosamente", "pareja_id" => $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO liga_parejas (categoria_id, jugador1_id, jugador2_id, nombre_externo1, nombre_externo2, nombre_pareja, estado, restricciones_horarias_json) VALUES (?, ?, ?, ?, ?, ?, 'Confirmada', ?)");
        if (!$stmt) {
            echo json_encode(["success" => false, "error" => "Error al preparar la consulta de inserción: " . $conn->error]);
            exit;
        }

        $stmt->bind_param("iiissss", $categoria_id, $jugador1_id, $jugador2_id, $nombre_externo1, $nombre_externo2, $nombre_pareja, $restricciones_json);
        if (!$stmt->execute()) {
            echo json_encode(["success" => false, "error" => "Error al guardar la inscripción: " . $stmt->error]);
            exit;
        }

        $nuevoParejaId = $stmt->insert_id;

        // Inicializar registro en liga_posiciones de la categoría
        recalcularPosicionesCategoria($conn, $categoria_id);

        echo json_encode(["success" => true, "mensaje" => "Pareja inscrita exitosamente", "pareja_id" => $nuevoParejaId]);
    }
} catch (Throwable $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
