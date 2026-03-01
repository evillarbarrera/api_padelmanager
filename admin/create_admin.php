<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../db.php";

$email = 'admin@padelmanager.cl';
$password = 'admin123';
$nombre = 'Admin PadelManager';
$rol = 'administrador';

// Check if it exists
$sqlCheck = "SELECT id FROM usuarios WHERE usuario = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $email);
$stmtCheck->execute();
$resultCheck = $stmtCheck->get_result();

if ($resultCheck->num_rows > 0) {
    echo "El usuario ya existe.<br>";
    $user = $resultCheck->fetch_assoc();
    $userId = $user['id'];
    
    // update password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $sqlUpdate = "UPDATE usuarios SET password = ?, rol = ? WHERE id = ?";
    $stmtUpdate = $conn->prepare($sqlUpdate);
    $stmtUpdate->bind_param("ssi", $passwordHash, $rol, $userId);
    if($stmtUpdate->execute()){
         echo "Contraseña y rol actualizados con éxito. Usa: $email / $password";
    } else {
         echo "Error al actualizar: " . $stmtUpdate->error;
    }
} else {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $sqlInsert = "INSERT INTO usuarios (usuario, password, rol, nombre) VALUES (?, ?, ?, ?)";
    $stmtInsert = $conn->prepare($sqlInsert);
    $stmtInsert->bind_param("ssss", $email, $passwordHash, $rol, $nombre);
    
    if ($stmtInsert->execute()) {
        echo "Usuario administrador creado con éxito.<br>";
        echo "Email: " . $email . "<br>";
        echo "Contraseña: " . $password . "<br>";
    } else {
        echo "Error al crear administrador: " . $stmtInsert->error;
    }
}
?>
