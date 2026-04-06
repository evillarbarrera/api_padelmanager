<?php
require_once "../db.php";
$conn->query("ALTER TABLE alumno_malla_seguimiento ADD COLUMN IF NOT EXISTS pack_id INT DEFAULT 0 AFTER entrenador_id");
echo "Schema updated for alumno_malla_seguimiento (pack_id added).";
?>
