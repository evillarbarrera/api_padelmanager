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

$region = isset($_GET['region']) ? $_GET['region'] : '';
$comuna = isset($_GET['comuna']) ? $_GET['comuna'] : '';
$current_date = date("Y-m-d");

$torneos = [];

try {
    // 1. OBTENER TORNEOS AMERICANOS (Solo futuros)
    $sqlA = "SELECT t.*, c.nombre as club_nombre, c.direccion as club_direccion, 
                    d.region as club_region, d.comuna as club_comuna,
                    u.foto_perfil as admin_foto,
                    (SELECT COUNT(*) FROM torneo_participantes WHERE torneo_id = t.id) as inscritos
             FROM torneos_americanos t 
             JOIN clubes c ON t.club_id = c.id 
             LEFT JOIN direcciones d ON d.club_id = c.id
             LEFT JOIN usuarios u ON t.creator_id = u.id
             WHERE (t.estado != 'Cerrado' OR t.estado IS NULL OR t.estado = '')
             AND t.fecha >= '$current_date'";

    if (!empty($region)) $sqlA .= " AND d.region = '" . $conn->real_escape_string($region) . "'";
    if (!empty($comuna)) $sqlA .= " AND d.comuna = '" . $conn->real_escape_string($comuna) . "'";

    $resA = $conn->query($sqlA);
    if ($resA) {
        while ($row = $resA->fetch_assoc()) {
            $row['table_source'] = 'americanos';
            $row['tipo'] = 'Americano';
            $row['imagen_display'] = $row['imagen'] ?? '';
            $row['imagen'] = $row['imagen'] ?? ''; 
            $row['fecha_display'] = $row['fecha'] ?? '';
            $torneos[] = $row;
        }
    }

    // 2. OBTENER TORNEOS V2 (TRADICIONALES - Solo futuros)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'torneos_v2'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $sqlV2 = "SELECT t.*, c.nombre as club_nombre, c.direccion as club_direccion, 
                         d.region as club_region, d.comuna as club_comuna,
                         (SELECT COUNT(*) FROM torneo_participantes WHERE torneo_id = t.id) as inscritos
                  FROM torneos_v2 t 
                  JOIN clubes c ON t.club_id = c.id 
                  LEFT JOIN direcciones d ON d.club_id = c.id
                  WHERE t.inscripciones_abiertas = 1
                  AND t.fecha_inicio >= '$current_date'";

        if (!empty($region)) $sqlV2 .= " AND d.region = '" . $conn->real_escape_string($region) . "'";
        if (!empty($comuna)) $sqlV2 .= " AND d.comuna = '" . $conn->real_escape_string($comuna) . "'";

        $resV2 = $conn->query($sqlV2);
        if ($resV2) {
            while ($row = $resV2->fetch_assoc()) {
                $row['table_source'] = 'v2';
                $row['imagen'] = $row['poster_url'] ?? ''; 
                $row['imagen_display'] = $row['poster_url'] ?? '';
                $row['fecha_display'] = $row['fecha_inicio'] ?? '';
                $row['fecha'] = $row['fecha_inicio'] ?? ''; 
                $torneos[] = $row;
            }
        }
    }

    usort($torneos, function($a, $b) {
        $fA = $a['fecha_display'] ?? '9999-12-31';
        $fB = $b['fecha_display'] ?? '9999-12-31';
        return strcmp($fA, $fB);
    });

    echo json_encode($torneos);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
