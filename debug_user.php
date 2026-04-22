<?php require_once 'db.php'; $res = $conn->query('SELECT id, nombre, usuario FROM usuarios WHERE usuario = "e.villarbarrera@gmail.com"'); if ($row = $res->fetch_assoc()) { echo 'User ID: ' . $row['id'] . ' Name: ' . $row['nombre'] . "
"; } else { echo "User not found
"; }
