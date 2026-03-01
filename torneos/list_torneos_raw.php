<?php
require_once "../db.php";
echo "<pre>";

echo "<h1>All Torneos Americanos</h1>";

$sql = "SELECT id, nombre, fecha, estado, club_id FROM torneos_americanos ORDER BY fecha DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Nombre</th><th>Fecha</th><th>Estado</th><th>Club ID</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['nombre']}</td>";
        echo "<td>{$row['fecha']}</td>";
        echo "<td>'{$row['estado']}'</td>"; // Quoted to see spaces
        echo "<td>{$row['club_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No hay torneos 'americanos' encontrados.";
}

echo "<h1>Users (Admins/Owners)</h1>";
$sql2 = "SELECT id, nombre, email, usuario FROM usuarios WHERE rol IN ('administrador_club', 'club_admin')";
$res2 = $conn->query($sql2);
if ($res2) {
    echo "<table border='1'><tr><th>ID</th><th>Nombre</th><th>Usuario</th></tr>";
    while ($r = $res2->fetch_assoc()) {
        echo "<tr><td>{$r['id']}</td><td>{$r['nombre']}</td><td>{$r['usuario']}</td></tr>";
    }
    echo "</table>";
}

echo "</pre>";
?>
