<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once "../db.php";

    // Check database connection
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("Database connection failed: " . ($conn->connect_error ?? "Unknown error"));
    }

    $userId = $_GET['user_id'] ?? ($_POST['user_id'] ?? null);

    if (!$userId) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Falta user_id"]);
        exit;
    }

    // 1. Get base user info
    $sql = "SELECT id, nombre, rol, foto_perfil FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->bind_param("i", $userId);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute statement: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Usuario no encontrado"]);
        exit;
    }

    // 2. Get active profiles
    $perfiles = [];
    $sqlP = "
        SELECT uc.id, uc.club_id, uc.rol, uc.nivel, c.nombre as club_nombre 
        FROM usuarios_clubes uc
        JOIN clubes c ON uc.club_id = c.id
        WHERE uc.usuario_id = ? AND uc.activo = 1
    ";
    $stmtP = $conn->prepare($sqlP);
    
    if (!$stmtP) {
        throw new Exception("Failed to prepare profiles statement: " . $conn->error);
    }
    
    $stmtP->bind_param("i", $userId);
    
    if (!$stmtP->execute()) {
        throw new Exception("Failed to execute profiles statement: " . $stmtP->error);
    }
    
    $resP = $stmtP->get_result();

    // 2.1 Club Profiles
    while ($p = $resP->fetch_assoc()) {
        $perfiles[] = $p;
    }

    // 2.2 Base Identity Profile (from usuarios table)
    // Always add the base role as a profile option (e.g. Entrenador, Jugador)
    $perfiles[] = [
        "id" => 0,
        "club_id" => null,
        "rol" => $user['rol'],
        "nivel" => null,
        "club_nombre" => "Perfil " . ucfirst($user['rol'])
    ];

    // 3. Return updated user object
    echo json_encode([
        "success" => true,
        "user" => [
            "id" => $user['id'],
            "nombre" => $user['nombre'],
            "rol" => $user['rol'],
            "foto_perfil" => $user['foto_perfil'],
            "perfiles" => $perfiles
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error in refresh_session.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Error interno del servidor",
        "details" => $e->getMessage() // Remove this in production
    ]);
}
?>
