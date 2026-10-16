<?php
require_once "../db.php";

$check = $conn->query("SHOW COLUMNS FROM clubes LIKE 'email_notificaciones'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE clubes ADD `email_notificaciones` VARCHAR(255) NULL");
    echo "Columna email_notificaciones agregada exitosamente a la tabla clubes.";
} else {
    echo "La columna email_notificaciones ya existe en la tabla clubes.";
}
?>
