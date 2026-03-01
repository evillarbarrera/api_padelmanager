<?php
require_once "../db.php";
// 1. Agregar columna estado a torneos_americanos
$conn->query("ALTER TABLE torneos_americanos ADD COLUMN IF NOT EXISTS estado ENUM('Abierto', 'Cerrado') DEFAULT 'Abierto'");
// 2. Asegurar que usuarios tiene puntos_ranking
$conn->query("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS puntos_ranking INT DEFAULT 0");
echo "OK";
?>
