<?php
/**
 * Padelblox - Listado Público de Torneos, Americanos y Ligas
 * Optimizado para máxima velocidad de respuesta (solo lectura, sin bloqueos de tabla).
 */
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

mysqli_report(MYSQLI_REPORT_OFF);

$region = isset($_GET['region']) ? trim($_GET['region']) : '';
$comuna = isset($_GET['comuna']) ? trim($_GET['comuna']) : '';
$current_date = date("Y-m-d");

$torneos = [];

// ========================================================
// 1. OBTENER TORNEOS AMERICANOS (Solo activos o futuros)
// ========================================================
try {
    $checkA = $conn->query("SHOW TABLES LIKE 'torneos_americanos'");
    if ($checkA && $checkA->num_rows > 0) {
        $sqlA = "SELECT t.*, 
                        COALESCE(c.nombre, 'Club de Pádel') as club_nombre, 
                        c.direccion as club_direccion, 
                        COALESCE(d.region, c.region, '') as club_region, 
                        COALESCE(d.comuna, c.comuna, '') as club_comuna,
                        u.foto_perfil as admin_foto,
                        COALESCE((SELECT COUNT(*) FROM torneo_participantes tp WHERE tp.torneo_id = t.id), 0) as cant_inscritos
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
                $row['categoria_nombre'] = $row['categoria'] ?? 'Única';
                $row['categorias'] = [['id' => (int)$row['id'], 'nombre' => $row['categoria'] ?? 'Única']];
                $row['inscritos'] = intval($row['cant_inscritos'] ?? 0);
                $row['max_parejas'] = intval($row['max_parejas'] ?? 12);
                $torneos[] = $row;
            }
        }
    }
} catch (Throwable $eA) {}

// ========================================================
// 2. OBTENER TORNEOS V2 (TRADICIONALES - Batch Optimized)
// ========================================================
try {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'torneos_v2'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $sqlV2 = "SELECT t.*, 
                         COALESCE(c.nombre, 'Club de Pádel') as club_nombre, 
                         c.direccion as club_direccion, 
                         COALESCE(d.region, c.region, '') as club_region, 
                         COALESCE(d.comuna, c.comuna, '') as club_comuna
                  FROM torneos_v2 t 
                  LEFT JOIN clubes c ON t.club_id = c.id 
                  LEFT JOIN direcciones d ON d.club_id = c.id
                  WHERE (t.inscripciones_abiertas = 1 OR t.estado = 'Abierto' OR t.estado = 'En Curso' OR t.estado IS NULL)
                    AND (t.estado IS NULL OR (t.estado != 'Cerrado' AND t.estado != 'Finalizado'))
                    AND (t.fecha_fin >= '$current_date' OR t.fecha_inicio >= '$current_date' OR t.fecha_fin IS NULL OR t.fecha_fin = '0000-00-00')";

        if (!empty($region)) $sqlV2 .= " AND (d.region = '" . $conn->real_escape_string($region) . "' OR c.region = '" . $conn->real_escape_string($region) . "')";
        if (!empty($comuna)) $sqlV2 .= " AND (d.comuna = '" . $conn->real_escape_string($comuna) . "' OR c.comuna = '" . $conn->real_escape_string($comuna) . "')";

        $resV2 = $conn->query($sqlV2);
        $v2Rows = [];
        $v2Ids = [];

        if ($resV2) {
            while ($row = $resV2->fetch_assoc()) {
                $id = (int)$row['id'];
                $v2Ids[] = $id;
                $row['table_source'] = 'v2';
                $row['tipo'] = 'Torneo';
                $row['club_id'] = (int)($row['club_id'] ?? 0) > 0 ? (int)$row['club_id'] : 21;
                $row['precio'] = floatval($row['precio'] ?? 0);
                $row['imagen'] = $row['poster_url'] ?? ''; 
                $row['imagen_display'] = $row['poster_url'] ?? '';
                $row['fecha_display'] = $row['fecha_inicio'] ?? '';
                $row['fecha'] = $row['fecha_inicio'] ?? ''; 
                $row['inscripciones_abiertas'] = (int)($row['inscripciones_abiertas'] ?? 0) === 1;
                $row['categorias'] = [];
                $row['categoria_nombre'] = '';
                $row['inscritos'] = 0;
                $v2Rows[$id] = $row;
            }

            // Cargar categorías de todos los torneos V2 en 1 sola consulta
            if (!empty($v2Ids)) {
                $idListStr = implode(',', $v2Ids);
                $resCat = $conn->query("SELECT id, torneo_id, nombre, genero, nivel, max_parejas, puntos_repartir, estado 
                                        FROM torneo_categorias 
                                        WHERE torneo_id IN ($idListStr) 
                                        ORDER BY id ASC");
                if ($resCat) {
                    while ($c = $resCat->fetch_assoc()) {
                        $tId = (int)$c['torneo_id'];
                        if (isset($v2Rows[$tId])) {
                            $v2Rows[$tId]['categorias'][] = $c;
                        }
                    }
                }

                // Cargar conteo de inscritos agrupado por torneo en 1 sola consulta
                $resIns = $conn->query("SELECT tc.torneo_id, COUNT(ti.id) as cant 
                                        FROM torneo_inscripciones ti 
                                        JOIN torneo_categorias tc ON ti.categoria_id = tc.id 
                                        WHERE tc.torneo_id IN ($idListStr) 
                                        GROUP BY tc.torneo_id");
                if ($resIns) {
                    while ($ins = $resIns->fetch_assoc()) {
                        $tId = (int)$ins['torneo_id'];
                        if (isset($v2Rows[$tId])) {
                            $v2Rows[$tId]['inscritos'] = (int)$ins['cant'];
                        }
                    }
                }

                foreach ($v2Rows as $tItem) {
                    if (!empty($tItem['categorias'])) {
                        $tItem['categoria_nombre'] = implode(', ', array_column($tItem['categorias'], 'nombre'));
                    }
                    $torneos[] = $tItem;
                }
            }
        }
    }
} catch (Throwable $eV2) {}

// ========================================================
// 3. OBTENER LIGAS DE PÁDEL (Batch Optimized)
// ========================================================
try {
    $tableLigas = $conn->query("SHOW TABLES LIKE 'ligas'");
    if ($tableLigas && $tableLigas->num_rows > 0) {
        $sqlL = "SELECT l.*, 
                        COALESCE(c.nombre, 'POWERPADEL MACHALI') as club_nombre, 
                        c.direccion as club_direccion,
                        COALESCE(d.region, c.region, '') as club_region, 
                        COALESCE(d.comuna, c.comuna, '') as club_comuna
                 FROM ligas l
                 LEFT JOIN clubes c ON l.club_id = c.id
                 LEFT JOIN direcciones d ON d.club_id = c.id
                 WHERE (l.estado IS NULL OR l.estado = '' OR l.estado != 'Finalizada')";

        if (!empty($region)) $sqlL .= " AND (d.region = '" . $conn->real_escape_string($region) . "' OR c.region = '" . $conn->real_escape_string($region) . "')";
        if (!empty($comuna)) $sqlL .= " AND (d.comuna = '" . $conn->real_escape_string($comuna) . "' OR c.comuna = '" . $conn->real_escape_string($comuna) . "')";

        $resL = $conn->query($sqlL);
        $ligaRows = [];
        $ligaIds = [];

        if ($resL) {
            while ($row = $resL->fetch_assoc()) {
                $id = (int)$row['id'];
                $ligaIds[] = $id;
                $row['table_source'] = 'liga';
                $row['tipo'] = 'Liga';
                $row['club_id'] = (int)($row['club_id'] ?? 0) > 0 ? (int)$row['club_id'] : 21;
                $row['precio'] = floatval($row['precio'] ?? 0);
                $row['imagen'] = $row['imagen_url'] ?? '';
                $row['imagen_display'] = $row['imagen_url'] ?? '';
                $row['fecha_display'] = $row['fecha_inicio'] ?? '';
                $row['fecha'] = $row['fecha_inicio'] ?? '';
                $row['categorias'] = [];
                $row['categoria_nombre'] = '';
                $row['inscritos'] = intval($row['total_parejas'] ?? 0);
                $ligaRows[$id] = $row;
            }

            if (!empty($ligaIds)) {
                $ligaIdList = implode(',', $ligaIds);
                $resCatL = $conn->query("SELECT id, liga_id, nombre, genero, nivel 
                                         FROM liga_categorias 
                                         WHERE liga_id IN ($ligaIdList) 
                                         ORDER BY id ASC");
                if ($resCatL) {
                    while ($c = $resCatL->fetch_assoc()) {
                        $lId = (int)$c['liga_id'];
                        if (isset($ligaRows[$lId])) {
                            $ligaRows[$lId]['categorias'][] = $c;
                        }
                    }
                }

                foreach ($ligaRows as $lItem) {
                    if (!empty($lItem['categorias'])) {
                        $lItem['categoria_nombre'] = implode(', ', array_column($lItem['categorias'], 'nombre'));
                    }
                    $torneos[] = $lItem;
                }
            }
        }
    }
} catch (Throwable $eL) {}

// Ordenar por fecha de celebración
usort($torneos, function($a, $b) {
    $fA = $a['fecha_display'] ?? '9999-12-31';
    $fB = $b['fecha_display'] ?? '9999-12-31';
    return strcmp($fA, $fB);
});

echo json_encode($torneos);
$conn->close();
?>