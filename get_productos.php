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

require_once "../db.php";

$club_id = $_GET['club_id'] ?? null;

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

try {
    /** @var PDO $pdo */
    $stmt = $pdo->prepare("SELECT * FROM inventario_productos WHERE club_id = ? AND activo = 1 ORDER BY categoria, nombre");
    $stmt->execute([$club_id]);
    $productos = $stmt->fetchAll();
    
    echo json_encode(["success" => true, "productos" => $productos]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al obtener productos: " . $e->getMessage()]);
}
