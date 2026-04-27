<?php
require_once "db.php";
$res = $conn->query("SELECT id, codigo, nombre, requisito_valor FROM logros");
while($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} | Codigo: {$row['codigo']} | Name: {$row['nombre']} | Req: {$row['requisito_valor']}\n";
}
?>
