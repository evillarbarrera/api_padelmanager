<?php
require_once "../db.php";

echo "<h1>Actualizando Base de Datos...</h1>";

$sql = "ALTER TABLE usuarios 
        ADD COLUMN reset_token VARCHAR(100) DEFAULT NULL,
        ADD COLUMN reset_expires DATETIME DEFAULT NULL";

if ($conn->query($sql)) {
    echo "<p style='color: green;'>✅ Tabla 'usuarios' actualizada con éxito (columnas de reset añadidas).</p>";
} else {
    echo "<p style='color: orange;'>⚠️ Nota: " . $conn->error . " (Es posible que las columnas ya existan).</p>";
}
?>
