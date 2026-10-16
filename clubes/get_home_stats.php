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
$fecha = $_GET['fecha'] ?? date('Y-m-d');

/**
 * DETERMINAR FILTRO DE CLUBES
 * admin_id puede ser un Administrador (dueño) o un Staff (miembro).
 */
if ($club_id) {
    $cancha_club_filter = "AND c.club_id = " . intval($club_id);
    $torneo_filter = "AND t.club_id = " . intval($club_id);
    $inv_filter = "WHERE club_id = " . intval($club_id);
} else {
    $subquery_clubs = "SELECT id FROM clubes WHERE admin_id = " . intval($admin_id) . " 
                       UNION 
                       SELECT club_id FROM usuarios_clubes WHERE usuario_id = " . intval($admin_id) . " AND activo = 1";
    $cancha_club_filter = "AND c.club_id IN ($subquery_clubs)";
    $torneo_filter = "AND t.club_id IN ($subquery_clubs)";
    $inv_filter = "WHERE club_id IN ($subquery_clubs)";
}

// 1. Ocupación y Canchas Totales
$sqlCanchas = "SELECT COUNT(*) as total FROM canchas c WHERE c.activa = 1 $cancha_club_filter";
$resCanchas = $conn->query($sqlCanchas);
$totalCanchas = 0;
if ($resCanchas) {
    $rowCanchas = $resCanchas->fetch_assoc();
    $totalCanchas = intval($rowCanchas['total'] ?? 0);
}

$sqlOcupacion = "SELECT COUNT(*) as total FROM reservas_cancha r 
                 JOIN canchas c ON r.cancha_id = c.id 
                 WHERE r.fecha = '$fecha' $cancha_club_filter AND r.estado != 'Cancelada'";
$resOcupacion = $conn->query($sqlOcupacion);
$totalReservasHoy = 0;
if ($resOcupacion) {
    $ocupacionRow = $resOcupacion->fetch_assoc();
    $totalReservasHoy = intval($ocupacionRow['total'] ?? 0);
}

$pctOcupacion = 0;
if ($totalCanchas > 0) {
    $slotsTotales = $totalCanchas * 14;
    $pctOcupacion = min(100, round(($totalReservasHoy / $slotsTotales) * 100));
}

// 2. Ingresos Canchas Hoy
$sqlRes = "SELECT r.* FROM reservas_cancha r 
           JOIN canchas c ON r.cancha_id = c.id 
           WHERE r.fecha = '$fecha' $cancha_club_filter AND r.estado != 'Cancelada'";
$resRes = $conn->query($sqlRes);
$ingresosCanchas = 0;
if ($resRes) {
    while ($r = $resRes->fetch_assoc()) {
        $precio = floatval($r['precio'] ?? 0);
        $pagado = intval($r['pagado'] ?? 0);
        $metodo = strtolower($r['metodo_pago'] ?? 'total');

        if ($pagado === 1) {
            $ingresosCanchas += $precio;
        } else if ($metodo === 'proporcional') {
            $activos = 0;
            $pagados = 0;
            if (!empty($r['nombre_externo']) || !empty($r['jugador_id']) || !empty($r['usuario_id'])) { $activos++; if (intval($r['pago_p1'] ?? 0) === 1) $pagados++; }
            if (!empty($r['nombre_externo2']) || !empty($r['jugador2_id'])) { $activos++; if (intval($r['pago_p2'] ?? 0) === 1) $pagados++; }
            if (!empty($r['nombre_externo3']) || !empty($r['jugador3_id'])) { $activos++; if (intval($r['pago_p3'] ?? 0) === 1) $pagados++; }
            if (!empty($r['nombre_externo4']) || !empty($r['jugador4_id'])) { $activos++; if (intval($r['pago_p4'] ?? 0) === 1) $pagados++; }

            if ($activos > 0) {
                $ingresosCanchas += ($precio / $activos) * $pagados;
            }
        }
    }
}

// 3. Ingresos Productos Hoy (Inventario / Ventas Tienda + Consumos)
$ingresosProductos = 0;
$checkInv = $conn->query("SHOW TABLES LIKE 'inventario_ventas'");
if ($checkInv && $checkInv->num_rows > 0) {
    $sqlProdInv = "SELECT SUM(vd.cantidad * vd.precio_unitario) as total 
                   FROM inventario_ventas v 
                   JOIN inventario_venta_detalles vd ON v.id = vd.venta_id 
                   $inv_filter AND DATE(v.fecha) = '$fecha' AND vd.producto_id > 0";
    $resProdInv = $conn->query($sqlProdInv);
    if ($resProdInv) {
        $rowP = $resProdInv->fetch_assoc();
        $ingresosProductos += floatval($rowP['total'] ?? 0);
    }
}

$sqlConsumos = "SELECT SUM(rc.subtotal) as total 
                FROM reservas_consumos rc 
                JOIN reservas_cancha r ON rc.reserva_id = r.id 
                JOIN canchas c ON r.cancha_id = c.id 
                WHERE r.fecha = '$fecha' $cancha_club_filter AND rc.pagado = 1";
$resCons = $conn->query($sqlConsumos);
if ($resCons) {
    $rowC = $resCons->fetch_assoc();
    $sumCons = floatval($rowC['total'] ?? 0);
    if ($ingresosProductos == 0) {
        $ingresosProductos += $sumCons;
    }
}

// 4. Americanos Activos (Abiertos y con fecha actual o futura)
$sqlAmericanos = "SELECT COUNT(*) as total FROM torneos_americanos t 
                  WHERE 1=1 $torneo_filter 
                  AND (t.estado = 'Abierto' OR t.estado IS NULL)
                  AND (t.estado IS NULL OR t.estado NOT IN ('Cerrado', 'Finalizado', 'Cancelado', 'cerrado', 'finalizado', 'cancelado'))
                  AND (t.fecha >= CURDATE() OR t.fecha IS NULL)";
$resAmericanos = $conn->query($sqlAmericanos);
$americanosActivos = 0;
if ($resAmericanos) {
    $rowA = $resAmericanos->fetch_assoc();
    $americanosActivos = intval($rowA['total'] ?? 0);
}

echo json_encode([
    "success" => true,
    "stats" => [
        "ocupacion" => $pctOcupacion . "%",
        "total_reservas_hoy" => $totalReservasHoy,
        "ingresos_canchas" => round($ingresosCanchas, 0),
        "ingresos_productos" => round($ingresosProductos, 0),
        "ingresos_dia" => round($ingresosCanchas + $ingresosProductos, 0),
        "americanos_activos" => $americanosActivos
    ]
]);
?>
