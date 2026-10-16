<?php
// api/torneos/eliminar_puntos_torneo_jugador.php
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

$data = json_decode(file_get_contents('php://input'), true);

$usuario_id = isset($data['usuario_id']) ? (int)$data['usuario_id'] : 0;
$torneo_id = isset($data['torneo_id']) ? (int)$data['torneo_id'] : 0;
$puntos_descontar = isset($data['puntos_descontar']) ? (int)$data['puntos_descontar'] : 0;
$categoria = isset($data['categoria']) ? trim($data['categoria']) : 'Cuarta';
$accion = isset($data['accion']) ? trim($data['accion']) : 'anular'; // 'anular' o 'restaurar'

if (!$usuario_id || !$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "Parámetros usuario_id y torneo_id son requeridos"]);
    exit;
}

try {
    // 1. Asegurar columnas de puntos anulados en torneo_participantes
    $check_a1 = @$conn->query("SHOW COLUMNS FROM torneo_participantes LIKE 'puntos_anulados_1'");
    if ($check_a1 && $check_a1->num_rows == 0) {
        @$conn->query("ALTER TABLE torneo_participantes ADD `puntos_anulados_1` TINYINT(1) DEFAULT 0");
    }
    $check_a2 = @$conn->query("SHOW COLUMNS FROM torneo_participantes LIKE 'puntos_anulados_2'");
    if ($check_a2 && $check_a2->num_rows == 0) {
        @$conn->query("ALTER TABLE torneo_participantes ADD `puntos_anulados_2` TINYINT(1) DEFAULT 0");
    }

    // 2. Buscar inscripción del usuario
    $sqlP = "SELECT id, jugador_id, jugador2_id, puntos_anulados_1, puntos_anulados_2 
             FROM torneo_participantes 
             WHERE torneo_id = ? AND (jugador_id = ? OR jugador2_id = ?)";
    $stmtP = $conn->prepare($sqlP);
    $stmtP->bind_param("iii", $torneo_id, $usuario_id, $usuario_id);
    $stmtP->execute();
    $part = $stmtP->get_result()->fetch_assoc();

    if (!$part) {
        throw new Exception("Inscripción no encontrada para este torneo y jugador");
    }

    $isJug1 = ($part['jugador_id'] == $usuario_id);
    $colAnulado = $isJug1 ? 'puntos_anulados_1' : 'puntos_anulados_2';
    $estadoActual = $isJug1 ? (int)$part['puntos_anulados_1'] : (int)$part['puntos_anulados_2'];

    if ($accion === 'anular') {
        if ($estadoActual === 1) {
            echo json_encode(["success" => true, "mensaje" => "Los puntos ya estaban anulados para este torneo."]);
            exit;
        }

        // Marcar anulado
        $conn->query("UPDATE torneo_participantes SET $colAnulado = 1 WHERE id = " . (int)$part['id']);

        // Descontar puntos en tabla usuarios
        if ($puntos_descontar > 0) {
            $stmtU = $conn->prepare("UPDATE usuarios SET puntos_ranking = GREATEST(0, puntos_ranking - ?) WHERE id = ?");
            $stmtU->bind_param("ii", $puntos_descontar, $usuario_id);
            $stmtU->execute();

            // Descontar en ranking_categorias
            $stmtRC = $conn->prepare("UPDATE ranking_categorias SET puntos = GREATEST(0, puntos - ?) WHERE usuario_id = ? AND categoria = ?");
            $stmtRC->bind_param("iis", $puntos_descontar, $usuario_id, $categoria);
            $stmtRC->execute();
        }

        echo json_encode([
            "success" => true,
            "mensaje" => "Puntos anulados y descontados exitosamente.",
            "puntos_descontados" => $puntos_descontar
        ]);

    } elseif ($accion === 'restaurar') {
        if ($estadoActual === 0) {
            echo json_encode(["success" => true, "mensaje" => "Los puntos ya estaban activos."]);
            exit;
        }

        // Desmarcar anulado
        $conn->query("UPDATE torneo_participantes SET $colAnulado = 0 WHERE id = " . (int)$part['id']);

        // Reintegrar puntos en tabla usuarios
        if ($puntos_descontar > 0) {
            $stmtU = $conn->prepare("UPDATE usuarios SET puntos_ranking = puntos_ranking + ? WHERE id = ?");
            $stmtU->bind_param("ii", $puntos_descontar, $usuario_id);
            $stmtU->execute();

            // Reintegrar en ranking_categorias
            $stmtRC = $conn->prepare("INSERT INTO ranking_categorias (usuario_id, categoria, puntos) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE puntos = puntos + VALUES(puntos)");
            $stmtRC->bind_param("isi", $usuario_id, $categoria, $puntos_descontar);
            $stmtRC->execute();
        }

        echo json_encode([
            "success" => true,
            "mensaje" => "Puntos restaurados exitosamente.",
            "puntos_restaurados" => $puntos_descontar
        ]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
