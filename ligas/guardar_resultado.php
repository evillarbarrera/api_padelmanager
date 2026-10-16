<?php
ini_set('display_errors', 0);
error_reporting(0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";
require_once "posiciones_helper.php";

$input = json_decode(file_get_contents("php://input"), true);
$partido_id = $input['partido_id'] ?? null;
$es_wo = (bool)($input['es_wo'] ?? false);
$pareja_wo_perdedora_id = $input['pareja_wo_perdedora_id'] ?? null;

$set1_p1 = (int)($input['set1_p1'] ?? 0); $set1_p2 = (int)($input['set1_p2'] ?? 0);
$set2_p1 = (int)($input['set2_p1'] ?? 0); $set2_p2 = (int)($input['set2_p2'] ?? 0);
$set3_p1 = (int)($input['set3_p1'] ?? 0); $set3_p2 = (int)($input['set3_p2'] ?? 0);

if (!$partido_id) {
    echo json_encode(["success" => false, "error" => "ID de partido requerido"]);
    exit;
}

try {
    $conn->begin_transaction();

    $sql = "SELECT p.*, 
                   COALESCE(p.categoria_id, g.categoria_id, par1.categoria_id) as categoria_id, 
                   COALESCE(c.liga_id, l.id) as liga_id, 
                   l.reglas_puntos_json 
            FROM liga_partidos p
            LEFT JOIN liga_grupos g ON p.grupo_id = g.id
            LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
            LEFT JOIN liga_categorias c ON (c.id = p.categoria_id OR c.id = g.categoria_id OR c.id = par1.categoria_id)
            LEFT JOIN ligas l ON (l.id = c.liga_id)
            WHERE p.id = $partido_id";
    
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) {
        throw new Exception("Partido no encontrado");
    }
    $match = $res->fetch_assoc();
    $reglas = !empty($match['reglas_puntos_json']) ? json_decode($match['reglas_puntos_json'], true) : [];

    $p1_id = (int)$match['pareja1_id'];
    $p2_id = (int)$match['pareja2_id'];
    $grupo_id = !empty($match['grupo_id']) ? (int)$match['grupo_id'] : null;

    $ganador_id = null;
    $puntos_p1 = 0;
    $puntos_p2 = 0;
    $estado = 'Jugado';
    $es_super_tb = 0;

    if ($es_wo) {
        $estado = 'Walkover';
        if ($pareja_wo_perdedora_id == $p1_id) {
            $ganador_id = $p2_id;
            $puntos_p1 = $reglas['puntos_derrota_wo'] ?? 0;
            $puntos_p2 = $reglas['puntos_victoria_normal'] ?? 3;
            $set1_p1 = 0; $set1_p2 = 6; $set2_p1 = 0; $set2_p2 = 6; $set3_p1 = 0; $set3_p2 = 0;
        } else {
            $ganador_id = $p1_id;
            $puntos_p1 = $reglas['puntos_victoria_normal'] ?? 3;
            $puntos_p2 = $reglas['puntos_derrota_wo'] ?? 0;
            $set1_p1 = 6; $set1_p2 = 0; $set2_p1 = 6; $set2_p2 = 0; $set3_p1 = 0; $set3_p2 = 0;
        }
    } else {
        $sets_p1 = 0; $sets_p2 = 0;
        if ($set1_p1 > $set1_p2) $sets_p1++; else if ($set1_p2 > $set1_p1) $sets_p2++;
        if ($set2_p1 > $set2_p2) $sets_p1++; else if ($set2_p2 > $set2_p1) $sets_p2++;
        
        if ($set3_p1 > 0 || $set3_p2 > 0) {
            if ($set3_p1 > $set3_p2) $sets_p1++; else if ($set3_p2 > $set3_p1) $sets_p2++;
            $es_super_tb = ($set3_p1 >= 10 || $set3_p2 >= 10) ? 1 : 0;
        }

        if ($sets_p1 > $sets_p2) {
            $ganador_id = $p1_id;
            $es_decisivo = ($sets_p1 === 2 && $sets_p2 === 1);
            $puntos_p1 = $es_decisivo ? ($reglas['puntos_victoria_decisivo'] ?? 2) : ($reglas['puntos_victoria_normal'] ?? 3);
            $puntos_p2 = $reglas['puntos_derrota_jugada'] ?? 1;
        } else {
            $ganador_id = $p2_id;
            $es_decisivo = ($sets_p2 === 2 && $sets_p1 === 1);
            $puntos_p2 = $es_decisivo ? ($reglas['puntos_victoria_decisivo'] ?? 2) : ($reglas['puntos_victoria_normal'] ?? 3);
            $puntos_p1 = $reglas['puntos_derrota_jugada'] ?? 1;
        }
    }

    // Manejo de observaciones (suplentes, punto de oro, etc.) sin alterar parejas oficiales
    $obsData = !empty($match['observaciones']) ? json_decode($match['observaciones'], true) : [];
    if (!is_array($obsData)) $obsData = [];

    if (!empty($input['suplente_p1'])) {
        $obsData['suplente_p1'] = trim($input['suplente_p1']);
    }
    if (!empty($input['suplente_p2'])) {
        $obsData['suplente_p2'] = trim($input['suplente_p2']);
    }
    if (isset($input['punto_oro'])) {
        $obsData['punto_oro'] = (bool)$input['punto_oro'];
    }

    $obsJson = !empty($obsData) ? json_encode($obsData, JSON_UNESCAPED_UNICODE) : $match['observaciones'];

    $stmt = $conn->prepare("UPDATE liga_partidos SET estado=?, pareja_ganadora_id=?, set1_p1=?, set1_p2=?, set2_p1=?, set2_p2=?, set3_p1=?, set3_p2=?, es_super_tiebreak=?, es_wo=?, puntos_p1=?, puntos_p2=?, observaciones=? WHERE id=?");
    $stmt->bind_param("siiiiiiiiiiisi", $estado, $ganador_id, $set1_p1, $set1_p2, $set2_p1, $set2_p2, $set3_p1, $set3_p2, $es_super_tb, $es_wo, $puntos_p1, $puntos_p2, $obsJson, $partido_id);
    $stmt->execute();

    // Si es partido de playoff, avanzar automáticamente al ganador a la siguiente ronda en el cuadro
    if (!empty($match['es_playoff']) && $ganador_id && !empty($match['observaciones'])) {
        $obs = json_decode($match['observaciones'], true);
        if (!empty($obs['next_bracket']) && !empty($obs['next_slot'])) {
            $nextCode = $obs['next_bracket'];
            $nextSlot = (int)$obs['next_slot'];
            $targetField = ($nextSlot === 1) ? 'pareja1_id' : 'pareja2_id';
            $cat_id = (int)$match['categoria_id'];

            if ($cat_id > 0) {
                $resNext = $conn->query("SELECT id FROM liga_partidos WHERE categoria_id = $cat_id AND es_playoff = 1 AND observaciones LIKE '%\"bracket_code\":\"$nextCode\"%' LIMIT 1");
                if ($resNext && $rowNext = $resNext->fetch_assoc()) {
                    $nextMatchId = (int)$rowNext['id'];
                    $conn->query("UPDATE liga_partidos SET $targetField = $ganador_id WHERE id = $nextMatchId");
                }
            }
        }
    }

    // Recalcular posiciones de la categoría completa (incluye todos los grupos y parejas directas)
    $cat_id = !empty($match['categoria_id']) ? (int)$match['categoria_id'] : 0;
    if ($cat_id <= 0 && $p1_id > 0) {
        $resCat = $conn->query("SELECT categoria_id FROM liga_parejas WHERE id = $p1_id LIMIT 1");
        if ($resCat && $rowCat = $resCat->fetch_assoc()) {
            $cat_id = (int)$rowCat['categoria_id'];
        }
    }
    if ($cat_id > 0) {
        recalcularPosicionesCategoria($conn, $cat_id);
    }

    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => "Resultado guardado y posiciones actualizadas."]);
} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
