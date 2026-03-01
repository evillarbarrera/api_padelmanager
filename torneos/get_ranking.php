<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$categoria = $_GET['categoria'] ?? 'General';
$club_id = $_GET['club_id'] ?? null;

// SILENT SCHEMA FIX - Ensure club_id exists in usuarios
$conn->query("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS club_id INT AFTER rol");

$ranking = [];

if ($categoria === 'General') {
    // Global ranking from usuarios table: Filter by club if provided
    $sql = "SELECT id, nombre, usuario, COALESCE(puntos_ranking, 0) as puntos_ranking, foto_perfil as foto, rol as role 
            FROM usuarios 
            WHERE puntos_ranking > 0";
    
    if ($club_id) {
        $sql .= " AND club_id = " . intval($club_id);
    }
    
    $sql .= " ORDER BY puntos_ranking DESC, nombre ASC";
    $result = $conn->query($sql);
} else {
    // Category ranking: Only people who actually have points in THIS specific category
    $sql = "SELECT u.id, u.nombre, u.usuario, rc.puntos as puntos_ranking, u.foto_perfil as foto, u.rol as role 
            FROM ranking_categorias rc
            JOIN usuarios u ON rc.usuario_id = u.id
            WHERE rc.categoria = ? AND rc.puntos > 0";
            
    if ($club_id) {
        $sql .= " AND u.club_id = " . intval($club_id);
    }
    
    $sql .= " ORDER BY rc.puntos DESC, u.nombre ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $categoria);
    $stmt->execute();
    $result = $stmt->get_result();
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ranking[] = [
            "id" => (int)$row['id'],
            "nombre" => $row['nombre'],
            "usuario" => $row['usuario'],
            "puntos_ranking" => (int)($row['puntos_ranking'] ?? 0),
            "foto" => $row['foto'],
            "role" => $row['role']
        ];
    }
}

// Return exact results (empty if nobody has points)
echo json_encode($ranking);
?>
