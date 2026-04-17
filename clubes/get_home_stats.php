<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$admin_id = $_GET['admin_id'] ?? 0;
$club_id = $_GET['club_id'] ?? null;

/**
 * DETERMINAR FILTRO DE CLUBES
 * admin_id puede ser un Administrador (dueño) o un Staff (miembro).
 */
if ($club_id) {
    $club_filter = "AND c.id = " . intval($club_id);
    $torneo_filter = "AND t.club_id = " . intval($club_id);
} else {
    // Si no hay club_id, buscamos cabeceras donde sea administrador o staff
    $subquery_clubs = "SELECT id FROM clubes WHERE admin_id = " . intval($admin_id) . " 
                       UNION 
                       SELECT club_id FROM usuarios_clubes WHERE usuario_id = " . intval($admin_id) . " AND activo = 1";
    $club_filter = "AND c.id IN ($subquery_clubs)";
    $torneo_filter = "AND t.club_id IN ($subquery_clubs)";
}

// 1. Ocupación (Ejemplo: Reservas hoy vs Slots totales aproximados)
$hoy = date('Y-m-d');
$sqlOcupacion = "SELECT COUNT(*) as total FROM reservas_cancha r 
                 JOIN canchas c ON r.cancha_id = c.id 
                 WHERE r.fecha = '$hoy' $club_filter";
$resOcupacion = $conn->query($sqlOcupacion);
$totalReservasHoy = 0;
if ($resOcupacion) {
    $ocupacionRow = $resOcupacion->fetch_assoc();
    $totalReservasHoy = $ocupacionRow['total'] ?? 0;
}

// 2. Ingresos Día
$sqlIngresos = "SELECT SUM(r.precio) as total FROM reservas_cancha r 
                JOIN canchas c ON r.cancha_id = c.id 
                WHERE r.fecha = '$hoy' $club_filter AND r.pagado = 1";
$resIngresos = $conn->query($sqlIngresos);
$ingresosHoy = 0;
if ($resIngresos) {
    $ingresosRow = $resIngresos->fetch_assoc();
    $ingresosHoy = $ingresosRow['total'] ?? 0;
}

// 3. Torneos Activos (Combinar Americanos y V2)
$sqlTorneos = "(SELECT id FROM torneos_v2 t WHERE t.id > 0 $torneo_filter)
               UNION ALL
               (SELECT id FROM torneos_americanos t WHERE t.id > 0 $torneo_filter AND t.estado = 'Abierto')";
$resTorneos = $conn->query($sqlTorneos);
$torneosActivos = $resTorneos ? $resTorneos->num_rows : 0;

// 4. Actividad Reciente (Últimas 5 reservas)
$sqlActividad = "SELECT r.*, c.nombre as cancha_nombre, u.nombre as jugador_nombre 
                 FROM reservas_cancha r 
                 JOIN canchas c ON r.cancha_id = c.id 
                 LEFT JOIN usuarios u ON r.usuario_id = u.id 
                 WHERE 1=1 $club_filter 
                 ORDER BY r.fecha DESC, r.hora_inicio DESC LIMIT 5";
$resActividad = $conn->query($sqlActividad);
$actividad = [];
if ($resActividad) {
    while ($row = $resActividad->fetch_assoc()) {
        $actividad[] = $row;
    }
}

echo json_encode([
    "success" => true,
    "stats" => [
        "ocupacion" => $totalReservasHoy > 0 ? ($totalReservasHoy * 10)."%" : "0%", // Simplificación
        "ingresos_dia" => $ingresosHoy,
        "alumnos_mensuales" => 0, // Por implementar
        "torneos_activos" => $torneosActivos
    ],
    "actividad_reciente" => $actividad
]);
?>
