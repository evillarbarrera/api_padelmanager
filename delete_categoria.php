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

if (!$data || !isset($data['id']) || !isset($data['club_id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan campos obligatorios (id, club_id)"]);
    exit;
}

$id = intval($data['id']);
$club_id = intval($data['club_id']);

try {
    /** @var PDO $pdo */
    // Obtener nombre de la categoría antes de marcar como inactiva
    $stmtName = $pdo->prepare("SELECT nombre FROM inventario_categorias WHERE id = ? AND club_id = ?");
    $stmtName->execute([$id, $club_id]);
    $catName = $stmtName->fetchColumn();

    if ($catName) {
        // Marcar como inactiva
        $stmt = $pdo->prepare("UPDATE inventario_categorias SET activo = 0 WHERE id = ? AND club_id = ?");
        $stmt->execute([$id, $club_id]);

        // Actualizar todos los productos de esa categoría a 'Otros'
        $stmtProd = $pdo->prepare("UPDATE inventario_productos SET categoria = 'Otros' WHERE club_id = ? AND categoria = ?");
        $stmtProd->execute([$club_id, $catName]);
        
        echo json_encode(["success" => true, "message" => "Categoría eliminada con éxito y productos actualizados a 'Otros'"]);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Categoría no encontrada"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]);
}
?>
