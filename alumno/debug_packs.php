<?php
// Script de diagnóstico para ver el estado de reservas grupales
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "../db.php";

$jugador_id = $_GET['id'] ?? 0;

echo "<h1>Diagnostico Reservas - Jugador $jugador_id</h1>";

// 1. Ver Pack Jugadores (Pagos)
echo "<h2>1. Pack Jugadores (Pagos)</h2>";
$sqlPJ = "SELECT * FROM pack_jugadores WHERE jugador_id = $jugador_id";
$resPJ = $conn->query($sqlPJ);
echo "<table border=1><tr><th>ID</th><th>Pack ID</th><th>Sesiones Usadas</th><th>Inicio</th><th>Fin</th></tr>";
while($r = $resPJ->fetch_assoc()) {
    echo "<tr><td>{$r['id']}</td><td>{$r['pack_id']}</td><td>{$r['sesiones_usadas']}</td><td>{$r['fecha_inicio']}</td><td>{$r['fecha_fin']}</td></tr>";
}
echo "</table>";

// 2. Ver Inscripciones Grupales
echo "<h2>2. Inscripciones Grupales</h2>";
$sqlIG = "SELECT * FROM inscripciones_grupales WHERE jugador_id = $jugador_id";
$resIG = $conn->query($sqlIG);
echo "<table border=1><tr><th>ID</th><th>Pack ID</th><th>Fecha Insc</th><th>Estado</th></tr>";
while($r = $resIG->fetch_assoc()) {
    echo "<tr><td>{$r['id']}</td><td>{$r['pack_id']}</td><td>{$r['fecha_inscripcion']}</td><td>{$r['estado']}</td></tr>";
}
echo "</table>";

// 3. Ver Packs relacionados
echo "<h2>3. Packs Detalles</h2>";
$sqlP = "SELECT id, nombre, tipo, capacidad_maxima, cupos_ocupados, estado_grupo FROM packs WHERE tipo='grupal'";
$resP = $conn->query($sqlP);
echo "<table border=1><tr><th>ID</th><th>Nombre</th><th>Capacidad</th><th>Ocupados</th><th>Estado Grupo</th></tr>";
while($r = $resP->fetch_assoc()) {
    echo "<tr><td>{$r['id']}</td><td>{$r['nombre']}</td><td>{$r['capacidad_maxima']}</td><td>{$r['cupos_ocupados']}</td><td>{$r['estado_grupo']}</td></tr>";
}
echo "</table>";
?>
