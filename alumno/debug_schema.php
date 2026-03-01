<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: text/plain");

require_once "../db.php";

$table = isset($_GET['table']) ? $_GET['table'] : 'pack_jugadores';
// Validar nombre de tabla para evitar inyección (opcional pero recomendado para debug)
$allowed_tables = ['pack_jugadores', 'reservas', 'packs', 'usuarios', 'inscripciones_grupales', 'reserva_jugadores', 'disponibilidad_profesor', 'entrenador_horarios_config'];
if (!in_array($table, $allowed_tables)) {
    echo "Tabla no permitida o no válida para debug.";
    exit;
}

$sql = "SHOW CREATE TABLE $table";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_row()) {
    echo $row[1];
} else {
    echo "Error: " . $conn->error;
}
?>
