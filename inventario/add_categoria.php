<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['club_id']) || !isset($data['nombre'])) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan campos obligatorios (club_id, nombre)"]);
    exit;
}

$club_id = intval($data['club_id']);
$nombre = trim($data['nombre']);
$id = isset($data['id']) ? intval($data['id']) : null;

if (empty($nombre)) {
    http_response_code(400);
    echo json_encode(["error" => "El nombre de la categoría no puede estar vacío"]);
    exit;
}

try {
    /** @var PDO $pdo */
    if ($id) {
        // Obtener el nombre anterior de la categoría para actualizar los productos correspondientes
        $stmtOld = $pdo->prepare("SELECT nombre FROM inventario_categorias WHERE id = ?");
        $stmtOld->execute([$id]);
        $oldName = $stmtOld->fetchColumn();

        // Actualizar categoría
        $stmt = $pdo->prepare("UPDATE inventario_categorias SET nombre = ? WHERE id = ? AND club_id = ?");
        $stmt->execute([$nombre, $id, $club_id]);

        // Si cambió el nombre, actualizar los productos que tenían la categoría anterior
        if ($oldName && $oldName !== $nombre) {
            $stmtProd = $pdo->prepare("UPDATE inventario_productos SET categoria = ? WHERE club_id = ? AND categoria = ?");
            $stmtProd->execute([$nombre, $club_id, $oldName]);
        }
        
        echo json_encode(["success" => true, "message" => "Categoría actualizada correctamente"]);
    } else {
        // Agregar nueva categoría
        // Si ya existía una categoría inactiva con ese nombre, la reactivamos
        $stmtCheck = $pdo->prepare("SELECT id FROM inventario_categorias WHERE club_id = ? AND nombre = ?");
        $stmtCheck->execute([$club_id, $nombre]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $stmt = $pdo->prepare("UPDATE inventario_categorias SET activo = 1 WHERE id = ?");
            $stmt->execute([$existingId]);
            $insertedId = $existingId;
        } else {
            $stmt = $pdo->prepare("INSERT INTO inventario_categorias (club_id, nombre) VALUES (?, ?)");
            $stmt->execute([$club_id, $nombre]);
            $insertedId = $pdo->lastInsertId();
        }
        
        echo json_encode(["success" => true, "id" => $insertedId, "message" => "Categoría creada correctamente"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]);
}
?>
