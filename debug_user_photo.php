<?php
require_once "db.php";
$email = 'e.villarbarrera@Gmail.com';
$stmt = $conn->prepare("SELECT id, nombre, foto, foto_perfil FROM usuarios WHERE usuario = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
echo json_encode($res);
?>
