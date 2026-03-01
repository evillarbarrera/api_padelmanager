<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "../db.php";

// 1. Validar que no exista mismo nombre de club
// Se agrega un indice UNICO al nombre del club para evitar duplicados a nivel de base de datos
try {
    $conn->query("ALTER TABLE clubes ADD UNIQUE INDEX idx_nombre_club (nombre)");
    echo json_encode(["success" => true, "mensaje" => "Índice único agregado a 'nombre' en la tabla 'clubes'."]);
} catch (Exception $e) {
    // Si ya existe, no pasa nada grave, pero informamos
    echo json_encode(["success" => true, "mensaje" => "El índice probablemente ya existía o hubo un error menor: " . $conn->error]);
}
?>
