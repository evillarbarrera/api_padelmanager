<?php
require_once "db.php";
$pwd = password_hash('admin123', PASSWORD_DEFAULT);
$sql = "INSERT INTO usuarios (usuario, password, rol, nombre) VALUES ('admin@padelmanager.cl', '$pwd', 'administrador', 'Admin PadelManager')";
if ($conn->query($sql) === TRUE) {
    echo "Admin created successfully.\n";
} else {
    echo "Error: " . $conn->error . "\n";
}
