<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

// SILENT SCHEMA FIX - Ensure columns exist
function ensureColumnUsers($conn, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM usuarios LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE usuarios ADD `$column` $definition");
    }
}
ensureColumnUsers($conn, 'foto', "VARCHAR(255) NULL");
ensureColumnUsers($conn, 'foto_perfil', "VARCHAR(255) NULL");

$sql = "
SELECT
    u.id AS jugador_id,
    u.nombre AS jugador_nombre,
    u.usuario AS jugador_email,
    u.telefono AS jugador_telefono,
    u.foto,
    u.foto_perfil,
    
    /* TOTAL PAGADAS (Solo individuales/multi) */
    COALESCE(SUM(CASE WHEN COALESCE(p.tipo, 'individual') NOT IN ('grupal', 'pack_grupal') THEN p.sesiones_totales ELSE 0 END), 0) AS sesiones_pagadas,

    /* TOTAL RESERVADAS (Solo individuales/multi) */
    (
        SELECT COUNT(DISTINCT r.id)
        FROM reservas r
        JOIN reserva_jugadores rj ON r.id = rj.reserva_id
        WHERE rj.jugador_id = u.id
          AND r.entrenador_id = ?
          AND r.estado = 'reservado'
          AND COALESCE(r.tipo, 'individual') NOT IN ('grupal', 'pack_grupal')
    ) AS sesiones_reservadas,

    /* TOTAL GRUPALES */
    (
        SELECT COUNT(DISTINCT r.id) 
        FROM reservas r
        JOIN reserva_jugadores rj ON r.id = rj.reserva_id
        WHERE rj.jugador_id = u.id 
          AND r.entrenador_id = ? 
          AND r.estado = 'reservado'
          AND COALESCE(r.tipo, 'individual') IN ('grupal', 'pack_grupal')
    ) AS sesiones_grupales,

    /* RESTANTES / PENDIENTES (Saldo real de packs individuales) */
    (
        COALESCE(SUM(CASE WHEN COALESCE(p.tipo, 'individual') NOT IN ('grupal', 'pack_grupal') THEN p.sesiones_totales ELSE 0 END), 0) - 
        (
            SELECT COUNT(DISTINCT r.id)
            FROM reservas r
            JOIN reserva_jugadores rj ON r.id = rj.reserva_id
            WHERE rj.jugador_id = u.id
              AND r.entrenador_id = ?
              AND r.estado != 'cancelado'
              AND COALESCE(r.tipo, 'individual') NOT IN ('grupal', 'pack_grupal')
        )
    ) AS sesiones_pendientes,

    /* Para mostrar qué packs tiene activos (opcional, concatenado) */
    GROUP_CONCAT(DISTINCT p.nombre SEPARATOR ', ') AS pack_nombres

FROM usuarios u
LEFT JOIN pack_jugadores cp ON u.id = cp.jugador_id
LEFT JOIN packs p ON p.id = cp.pack_id AND p.entrenador_id = ?

WHERE (p.id IS NOT NULL AND COALESCE(p.tipo, 'individual') NOT IN ('grupal', 'pack_grupal')) 
   OR (u.id IN (
       SELECT rj3.jugador_id 
       FROM reserva_jugadores rj3 
       JOIN reservas r3 ON r3.id = rj3.reserva_id 
       WHERE r3.entrenador_id = ? AND r3.estado != 'cancelado'
   ))

GROUP BY u.id, u.nombre, u.usuario, u.telefono, u.foto, u.foto_perfil
ORDER BY u.nombre ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiii", $entrenador_id, $entrenador_id, $entrenador_id, $entrenador_id, $entrenador_id);
$stmt->execute();

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
$conn->close();
