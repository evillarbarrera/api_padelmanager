<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../db.php';

$data = json_decode(file_get_contents("php://input"));

if(empty($data->torneo_id) || !isset($data->grid)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing parameters"]);
    exit();
}

$torneo_id = (int)$data->torneo_id;
$grid = $data->grid; // Associative array { "YYYY-MM-DD": [8,9,10] }

// Start transaction
$conn->begin_transaction();

try {
    // 1. Clear existing availability for this tournament to ensure clean state
    $stmt = $conn->prepare("DELETE FROM torneo_horarios_disponibilidad WHERE torneo_id = ?");
    $stmt->bind_param("i", $torneo_id);
    if (!$stmt->execute()) {
        throw new Exception("Error clearing existing availability");
    }
    $stmt->close();

    // 2. Insert new availability
    $stmt = $conn->prepare("INSERT INTO torneo_horarios_disponibilidad (torneo_id, fecha, hora) VALUES (?, ?, ?)");
    
    foreach ($grid as $fecha => $hours) {
        // Validate date format if needed, but assuming valid YYYY-MM-DD from frontend
        if (!is_array($hours)) continue;
        
        foreach ($hours as $hour) {
            $hour = (int)$hour;
            $stmt->bind_param("isi", $torneo_id, $fecha, $hour);
            if (!$stmt->execute()) {
                throw new Exception("Error inserting availability for $fecha at $hour: " . $stmt->error);
            }
        }
    }
    $stmt->close();

    $conn->commit();
    echo json_encode(["status" => "success", "message" => "Availability updated successfully"]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

$conn->close();
?>
