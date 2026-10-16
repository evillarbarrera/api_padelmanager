<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "init_tables.php";
ensureCoachPublicProfileTables($conn);

try {
    $sql = "SELECT epp.slug, epp.updated_at, u.nombre, d.comuna 
            FROM entrenador_perfil_publico epp
            JOIN usuarios u ON u.id = epp.entrenador_id
            LEFT JOIN direcciones d ON d.usuario_id = u.id
            WHERE epp.publicado = 1 AND u.rol = 'entrenador'
            ORDER BY epp.updated_at DESC";
    
    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }
    }

    echo json_encode(["status" => "success", "entrenadores" => $list]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
