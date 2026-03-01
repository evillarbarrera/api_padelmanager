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

$entrenador_id = $_GET['entrenador_id'] ?? 0;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es requerido"]);
    exit;
}

try {
    // Buscar jugadores que han comprado packs a este entrenador
    // Y traer información de packs INDIVIDUALES con sesiones disponibles
    $sql = "
        SELECT 
            u.id as jugador_id,
            u.nombre as jugador_nombre,
            u.foto_perfil as jugador_foto,
            p.id as pack_id,
            p.nombre as pack_nombre,
            p.sesiones_totales,
            pj.id as pack_jugador_id,
            p.rango_horario_inicio,
            p.rango_horario_fin,
            (
                SELECT COUNT(*) 
                FROM reserva_jugadores rj2 
                JOIN reservas r2 ON rj2.reserva_id = r2.id 
                WHERE rj2.jugador_id = u.id 
                  AND r2.pack_id = p.id 
                  AND r2.estado != 'cancelado'
            ) as sesiones_gastadas
        FROM usuarios u
        JOIN pack_jugadores pj ON u.id = pj.jugador_id
        JOIN packs p ON pj.pack_id = p.id
        WHERE p.entrenador_id = ? 
          AND (p.tipo = 'individual' OR p.tipo IS NULL)
        ORDER BY u.nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $entrenador_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $alumnos = [];
    while ($row = $result->fetch_assoc()) {
        $restantes = (int)$row['sesiones_totales'] - (int)$row['sesiones_gastadas'];
        if ($restantes > 0) {
            $row['sesiones_restantes'] = $restantes;
            $alumnos[] = $row;
        }
    }

    echo json_encode($alumnos);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
?>
