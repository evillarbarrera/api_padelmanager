<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$club_id = intval($_GET['club_id'] ?? 0);
$fecha = trim($_GET['fecha'] ?? date('Y-m-d'));
$hora_inicio = trim($_GET['hora_inicio'] ?? '');
$hora_fin = trim($_GET['hora_fin'] ?? '');

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

// Normalizar formato de horas (ej. '16:00' -> '16:00:00')
if (!empty($hora_inicio) && strlen($hora_inicio) === 5) {
    $hora_inicio .= ':00';
}
if (!empty($hora_fin) && strlen($hora_fin) === 5) {
    $hora_fin .= ':00';
}

try {
    // Si se enviaron fecha y horas, verificar disponibilidad contra reservas_cancha
    if (!empty($fecha) && !empty($hora_inicio) && !empty($hora_fin)) {
        $sql = "
            SELECT c.id, c.nombre, c.tipo, c.categoria, c.icono, c.capacidad,
                   (
                       SELECT COUNT(*) 
                       FROM reservas_cancha rc 
                       WHERE rc.cancha_id = c.id 
                         AND rc.fecha = ?
                         AND LOWER(TRIM(rc.estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada')
                         AND (rc.hora_inicio < ? AND rc.hora_fin > ?)
                   ) AS reservas_conflicto
            FROM canchas c
            WHERE c.club_id = ? AND (c.activa = 1 OR c.activa IS NULL)
            ORDER BY c.nombre ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $fecha, $hora_fin, $hora_inicio, $club_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $canchas = [];
        while ($row = $result->fetch_assoc()) {
            $conflicto = intval($row['reservas_conflicto'] ?? 0);
            $canchas[] = [
                "id" => intval($row['id']),
                "nombre" => $row['nombre'],
                "tipo" => $row['tipo'] ?? 'estandar',
                "categoria" => $row['categoria'] ?? 'cancha_padel',
                "icono" => $row['icono'] ?? '🎾',
                "capacidad" => intval($row['capacidad'] ?? 4),
                "disponible" => ($conflicto === 0),
                "conflicto_count" => $conflicto
            ];
        }

        echo json_encode($canchas);
    } else {
        // Solo listar canchas activas del club
        $sql = "SELECT id, nombre, tipo, categoria, icono, capacidad FROM canchas WHERE club_id = ? AND (activa = 1 OR activa IS NULL) ORDER BY nombre ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $club_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $canchas = [];
        while ($row = $result->fetch_assoc()) {
            $canchas[] = [
                "id" => intval($row['id']),
                "nombre" => $row['nombre'],
                "tipo" => $row['tipo'] ?? 'estandar',
                "categoria" => $row['categoria'] ?? 'cancha_padel',
                "icono" => $row['icono'] ?? '🎾',
                "capacidad" => intval($row['capacidad'] ?? 4),
                "disponible" => true,
                "conflicto_count" => 0
            ];
        }

        echo json_encode($canchas);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al consultar canchas: " . $e->getMessage()]);
}
?>
