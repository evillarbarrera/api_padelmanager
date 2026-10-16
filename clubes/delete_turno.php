<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, X-Requested-With, Origin, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once "../auth/auth_helper.php";
    if (!validateToken()) {
        sendUnauthorized();
    }

    require_once "../db.php";

    $data = json_decode(file_get_contents("php://input"), true);
    $id = isset($data['id']) ? intval($data['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    $club_id = isset($data['club_id']) ? intval($data['club_id']) : (isset($_GET['club_id']) ? intval($_GET['club_id']) : 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "ID del turno inválido"]);
        exit;
    }

    if ($club_id > 0) {
        $stmt = $conn->prepare("DELETE FROM club_turnos WHERE id = ? AND club_id = ?");
        $stmt->bind_param("ii", $id, $club_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM club_turnos WHERE id = ?");
        $stmt->bind_param("i", $id);
    }

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Turno eliminado exitosamente"]);
    } else {
        throw new Exception("Error al eliminar turno: " . $conn->error);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
