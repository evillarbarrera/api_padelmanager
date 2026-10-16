<?php
require_once "../db.php";
$checkCol = @$conn->query("SHOW COLUMNS FROM alumno_malla_seguimiento LIKE 'pack_id'");
if ($checkCol && $checkCol->num_rows == 0) {
    @$conn->query("ALTER TABLE alumno_malla_seguimiento ADD COLUMN pack_id INT DEFAULT 0 AFTER entrenador_id");
}
echo "Schema updated for alumno_malla_seguimiento (pack_id added).";
?>
