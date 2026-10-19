<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

require_once "../db.php";

// Disable mysqli exception mode to prevent unhandled script crashes
mysqli_report(MYSQLI_REPORT_OFF);

$region = isset($_GET['region']) ? trim($_GET['region']) : '';
$comuna = isset($_GET['comuna']) ? trim($_GET['comuna']) : '';
$current_date = date("Y-m-d");

// Ensure ligas table columns exist silently
function ensureLigasSchemaPublic($conn) {
    $checkT = $conn->query("SHOW TABLES LIKE 'ligas'");
    if ($checkT && $checkT->num_rows > 0) {
        $cols = [
            'imagen_url' => "VARCHAR(255) NULL",
            'precio' => "DECIMAL(10,2) DEFAULT 0.00",
            'visible_app' => "TINYINT(1) DEFAULT 1"
        ];
        foreach ($cols as $col => $def) {
            $checkC = $conn->query("SHOW COLUMNS FROM ligas LIKE '$col'");
            if ($checkC && $checkC->num_rows == 0) {
                @$conn->query("ALTER TABLE ligas ADD `$col` $def");
            }
        }
        // Fix orphan ligas pointing to default club_id 1 so they match PowerPadel Machalí (id 21) or real club
        @$conn->query("UPDATE ligas SET visible_app = 1 WHERE visible_app IS NULL OR visible_app = 0");
        @$conn->query("UPDATE ligas SET club_id = 21 WHERE (club_id = 1 OR club_id = 0 OR club_id IS NULL)");
        @$conn->query("UPDATE ligas l JOIN clubes c ON (c.nombre LIKE '%PowerPadel%' OR c.nombre LIKE '%Power Padel%') SET l.club_id = c.id WHERE (l.club_id = 1 OR l.club_id = 0 OR l.club_id IS NULL)");
    }
}
ensureLigasSchemaPublic($conn);

// Ensure torneos_v2 schema and resolve orphan club_id
function ensureTorneosV2SchemaPublic($conn) {
    $checkT = $conn->query("SHOW TABLES LIKE 'torneos_v2'");
    if ($checkT && $checkT->num_rows > 0) {
        $cols = [
            'inscripciones_abiertas' => "BOOLEAN DEFAULT FALSE",
            'poster_url' => "VARCHAR(255) DEFAULT NULL",
            'precio' => "DECIMAL(10,2) DEFAULT 0.00",
            'estado' => "ENUM('Abierto', 'En Curso', 'Finalizado', 'Cerrado') DEFAULT 'Abierto'",
            'club_id' => "INT NOT NULL DEFAULT 1"
        ];
        foreach ($cols as $col => $def) {
            $checkC = $conn->query("SHOW COLUMNS FROM torneos_v2 LIKE '$col'");
            if ($checkC && $checkC->num_rows == 0) {
                @$conn->query("ALTER TABLE torneos_v2 ADD `$col` $def");
            }
        }
        @$conn->query("UPDATE torneos_v2 t JOIN clubes c ON (c.admin_id = t.creator_id) SET t.club_id = c.id WHERE (t.club_id = 0 OR t.club_id = 1 OR t.club_id IS NULL) AND t.creator_id > 0");
        @$conn->query("UPDATE torneos_v2 t JOIN clubes c ON (c.nombre LIKE '%PowerPadel%' OR c.nombre LIKE '%Power Padel%') SET t.club_id = c.id WHERE (t.club_id = 0 OR t.club_id = 1 OR t.club_id IS NULL)");
        @$conn->query("UPDATE torneos_v2 SET club_id = 21 WHERE (club_id = 0 OR club_id = 1 OR club_id IS NULL)");
    }
}
ensureTorneosV2SchemaPublic($conn);

$torneos = [];

// 1. OBTENER TORNEOS AMERICANOS (Solo futuros o activos)
try {
    $checkA = $conn->query("SHOW TABLES LIKE 'torneos_americanos'");
    if ($checkA && $checkA->num_rows > 0) {
        $sqlA = "SELECT t.*, COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.direccion as club_direccion, 
                        COALESCE(d.region, c.region, '') as club_region, COALESCE(d.comuna, c.comuna, '') as club_comuna,
                        u.foto_perfil as admin_foto
                 FROM torneos_americanos t 
                 LEFT JOIN clubes c ON t.club_id = c.id 
                 LEFT JOIN direcciones d ON d.club_id = c.id
                 LEFT JOIN usuarios u ON t.creator_id = u.id
                 WHERE (t.estado != 'Cerrado' OR t.estado IS NULL OR t.estado = '')
                 AND t.fecha >= '$current_date'";

        if (!empty($region)) $sqlA .= " AND (d.region = '" . $conn->real_escape_string($region) . "' OR c.region = '" . $conn->real_escape_string($region) . "')";
        if (!empty($comuna)) $sqlA .= " AND (d.comuna = '" . $conn->real_escape_string($comuna) . "' OR c.comuna = '" . $conn->real_escape_string($comuna) . "')";

        $resA = $conn->query($sqlA);
        if ($resA) {
            while ($row = $resA->fetch_assoc()) {
                $row['table_source'] = 'americanos';
                $row['tipo'] = 'Americano';
                $row['precio'] = floatval($row['precio'] ?? 0);
                $row['imagen_display'] = $row['imagen'] ?? '';
                $row['imagen'] = $row['imagen'] ?? ''; 
                $row['fecha_display'] = $row['fecha'] ?? '';
                $row['categoria_nombre'] = $row['categoria'] ?? '';
                $row['categorias'] = [['id' => (int)$row['id'], 'nombre' => $row['categoria'] ?? 'Única']];
                $row['inscritos'] = 0;
                $torneos[] = $row;
            }
        }
    }
} catch (Throwable $eA) {}

// 2. OBTENER TORNEOS V2 (TRADICIONALES - Activos o con inscripciones abiertas)
try {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'torneos_v2'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $sqlV2 = "SELECT t.*, COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.direccion as club_direccion, 
                         COALESCE(d.region, c.region, '') as club_region, COALESCE(d.comuna, c.comuna, '') as club_comuna
                  FROM torneos_v2 t 
                  LEFT JOIN clubes c ON t.club_id = c.id 
                  LEFT JOIN direcciones d ON d.club_id = c.id
                  WHERE (t.inscripciones_abiertas = 1 OR t.estado = 'Abierto' OR t.estado = 'En Curso' OR t.estado IS NULL)
                    AND (t.estado IS NULL OR (t.estado != 'Cerrado' AND t.estado != 'Finalizado'))
                    AND (t.fecha_fin >= '$current_date' OR t.fecha_inicio >= '$current_date' OR t.fecha_fin IS NULL OR t.fecha_fin = '0000-00-00')";

        if (!empty($region)) $sqlV2 .= " AND (d.region = '" . $conn->real_escape_string($region) . "' OR c.region = '" . $conn->real_escape_string($region) . "')";
        if (!empty($comuna)) $sqlV2 .= " AND (d.comuna = '" . $conn->real_escape_string($comuna) . "' OR c.comuna = '" . $conn->real_escape_string($comuna) . "')";

        $resV2 = $conn->query($sqlV2);
        if ($resV2) {
            while ($row = $resV2->fetch_assoc()) {
                $row['table_source'] = 'v2';
                $row['tipo'] = 'Torneo';
                $row['club_id'] = (int)($row['club_id'] ?? 0) > 0 ? (int)$row['club_id'] : 21;
                $row['precio'] = floatval($row['precio'] ?? 0);
                $row['imagen'] = $row['poster_url'] ?? ''; 
                $row['imagen_display'] = $row['poster_url'] ?? '';
                $row['fecha_display'] = $row['fecha_inicio'] ?? '';
                $row['fecha'] = $row['fecha_inicio'] ?? ''; 
                $row['inscripciones_abiertas'] = (int)($row['inscripciones_abiertas'] ?? 0) === 1;

                $tId = (int)$row['id'];
                $resCatV2 = $conn->query("SELECT id, nombre, genero, nivel, max_parejas, puntos_repartir, estado FROM torneo_categorias WHERE torneo_id = $tId ORDER BY id ASC");
                $catsV2 = [];
                if ($resCatV2) {
                    while ($c = $resCatV2->fetch_assoc()) {
                        $catsV2[] = $c;
                    }
                }
                $row['categorias'] = $catsV2;
                $row['categoria_nombre'] = !empty($catsV2) ? implode(', ', array_column($catsV2, 'nombre')) : '';

                // Conteo de parejas inscritas en el torneo
                $resInsCount = $conn->query("SELECT COUNT(*) as cant FROM torneo_inscripciones ti JOIN torneo_categorias tc ON ti.categoria_id = tc.id WHERE tc.torneo_id = $tId");
                $row['inscritos'] = ($resInsCount && $rIns = $resInsCount->fetch_assoc()) ? (int)$rIns['cant'] : 0;
                $torneos[] = $row;
            }
        }
    }
} catch (Throwable $eV2) {}

// 3. OBTENER LIGAS DE PÁDEL (Visibles en App)
try {
    $tableLigas = $conn->query("SHOW TABLES LIKE 'ligas'");
    if ($tableLigas && $tableLigas->num_rows > 0) {
        $sqlL = "SELECT l.*, COALESCE(c.nombre, 'POWERPADEL MACHALI') as club_nombre, c.direccion as club_direccion,
                        COALESCE(d.region, c.region, '') as club_region, COALESCE(d.comuna, c.comuna, '') as club_comuna
                 FROM ligas l
                 LEFT JOIN clubes c ON l.club_id = c.id
                 LEFT JOIN direcciones d ON d.club_id = c.id
                 WHERE (l.estado IS NULL OR l.estado = '' OR l.estado != 'Finalizada')";

        if (!empty($region)) $sqlL .= " AND (d.region = '" . $conn->real_escape_string($region) . "' OR c.region = '" . $conn->real_escape_string($region) . "')";
        if (!empty($comuna)) $sqlL .= " AND (d.comuna = '" . $conn->real_escape_string($comuna) . "' OR c.comuna = '" . $conn->real_escape_string($comuna) . "')";

        $resL = $conn->query($sqlL);
        if ($resL) {
            while ($row = $resL->fetch_assoc()) {
                $row['table_source'] = 'liga';
                $row['tipo'] = 'Liga';
                $row['club_id'] = (int)($row['club_id'] ?? 0) > 0 ? (int)$row['club_id'] : 21;
                $row['precio'] = floatval($row['precio'] ?? 0);
                $row['imagen'] = $row['imagen_url'] ?? '';
                $row['imagen_display'] = $row['imagen_url'] ?? '';
                $row['fecha_display'] = $row['fecha_inicio'] ?? '';
                $row['fecha'] = $row['fecha_inicio'] ?? '';

                $lId = (int)$row['id'];
                $resCatL = $conn->query("SELECT id, nombre, genero, nivel FROM liga_categorias WHERE liga_id = $lId ORDER BY id ASC");
                $catsL = [];
                if ($resCatL) {
                    while ($c = $resCatL->fetch_assoc()) {
                        $catsL[] = $c;
                    }
                }
                $row['categorias'] = $catsL;
                $row['categoria_nombre'] = !empty($catsL) ? implode(', ', array_column($catsL, 'nombre')) : '';
                $row['inscritos'] = 0;
                $torneos[] = $row;
            }
        }
    }
} catch (Throwable $eL) {}

usort($torneos, function($a, $b) {
    $fA = $a['fecha_display'] ?? '9999-12-31';
    $fB = $b['fecha_display'] ?? '9999-12-31';
    return strcmp($fA, $fB);
});

echo json_encode($torneos);
$conn->close();
?>
