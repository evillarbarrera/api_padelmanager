<?php
// api/torneos/limpiar_ranking_club.php
error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$club_id = isset($data['club_id']) ? (int)$data['club_id'] : (isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0);

try {
    // Si no se envió club_id o es PowerPadel
    $clubIds = [];
    if ($club_id <= 0) {
        $resC = $conn->query("SELECT id FROM clubes WHERE nombre LIKE '%Power%'");
        if ($resC) {
            while ($c = $resC->fetch_assoc()) {
                $clubIds[] = (int)$c['id'];
            }
        }
        if (empty($clubIds)) {
            $clubIds = [17, 21];
        }
    } else {
        $clubIds[] = $club_id;
        // Si el club_id es 17 o 21 (ambos IDs de PowerPadel Machalí), incluir ambos
        if (in_array($club_id, [17, 21])) {
            $clubIds = [17, 21];
        }
    }

    $cidsStr = implode(',', $clubIds);

    // 1. Obtener todos los torneos americanos de este club
    $torneosIds = [];
    $resT = $conn->query("SELECT id FROM torneos_americanos WHERE club_id IN ($cidsStr)");
    if ($resT) {
        while ($t = $resT->fetch_assoc()) {
            $torneosIds[] = (int)$t['id'];
        }
    }

    // 2. Anular puntos en torneo_participantes para estos torneos
    $torneosAnuladosCount = 0;
    if (!empty($torneosIds)) {
        $tidsStr = implode(',', $torneosIds);
        $conn->query("UPDATE torneo_participantes SET puntos_anulados_1 = 1, puntos_anulados_2 = 1 WHERE torneo_id IN ($tidsStr)");
        $torneosAnuladosCount = count($torneosIds);
    }

    // 3. Obtener jugadores asociados a este club
    // A) Jugadores con u.club_id del club
    // B) Jugadores en usuarios_clubes
    // C) Jugadores que participaron en torneos de este club
    $subqueryUsers = "
        SELECT id FROM usuarios WHERE club_id IN ($cidsStr)
        UNION
        SELECT usuario_id as id FROM usuarios_clubes WHERE club_id IN ($cidsStr)
    ";
    if (!empty($torneosIds)) {
        $tidsStr = implode(',', $torneosIds);
        $subqueryUsers .= "
            UNION
            SELECT jugador_id as id FROM torneo_participantes WHERE torneo_id IN ($tidsStr) AND jugador_id > 0
            UNION
            SELECT jugador2_id as id FROM torneo_participantes WHERE torneo_id IN ($tidsStr) AND jugador2_id > 0
        ";
    }

    // 4. Resetear puntos de ranking para los usuarios encontrados
    $sqlUsers = "SELECT DISTINCT id FROM ($subqueryUsers) as u_club WHERE id > 0";
    $resU = $conn->query($sqlUsers);
    $userCount = 0;
    $targetUserIds = [];
    if ($resU) {
        while ($u = $resU->fetch_assoc()) {
            $targetUserIds[] = (int)$u['id'];
        }
    }

    if (!empty($targetUserIds)) {
        $uidsStr = implode(',', $targetUserIds);
        // Reset usuarios puntos_ranking
        $conn->query("UPDATE usuarios SET puntos_ranking = 0 WHERE id IN ($uidsStr)");
        // Borrar categorías de ranking para estos usuarios
        $conn->query("DELETE FROM ranking_categorias WHERE usuario_id IN ($uidsStr)");
        $userCount = count($targetUserIds);
    }

    echo json_encode([
        "success" => true,
        "mensaje" => "Puntos de ranking limpiados exitosamente para el club.",
        "club_ids" => $clubIds,
        "torneos_anulados" => $torneosAnuladosCount,
        "jugadores_reseteados" => $userCount
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
