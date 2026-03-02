<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "db.php";

$sql = "SHOW COLUMNS FROM entrenamiento_videos LIKE 'categoria'";
$res = $conn->query($sql);
if ($res->num_rows == 0) {
    echo "Adding column categoria...\n";
    $conn->query("ALTER TABLE entrenamiento_videos ADD COLUMN categoria varchar(100) DEFAULT 'General'");
}

$sql = "SHOW COLUMNS FROM entrenamiento_videos LIKE 'tipo'";
$res = $conn->query($sql);
if ($res->num_rows == 0) {
    echo "Adding column tipo...\n";
    $conn->query("ALTER TABLE entrenamiento_videos ADD COLUMN tipo varchar(50) DEFAULT 'clase'");
}

$sql = "SHOW COLUMNS FROM entrenamiento_videos LIKE 'comentario'";
$res = $conn->query($sql);
if ($res->num_rows == 0) {
    echo "Adding column comentario...\n";
    $conn->query("ALTER TABLE entrenamiento_videos ADD COLUMN comentario text");
}

$sql = "SHOW COLUMNS FROM entrenamiento_videos LIKE 'titulo'";
$res = $conn->query($sql);
if ($res->num_rows == 0) {
    echo "Adding column titulo...\n";
    $conn->query("ALTER TABLE entrenamiento_videos ADD COLUMN titulo varchar(150)");
}

echo "Check complete.\n";
