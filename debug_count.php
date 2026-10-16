<?php
require_once "db.php";
$id = $_GET['id'] ?? 0;
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM disponibilidad_profesor WHERE profesor_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
echo json_encode($res);
