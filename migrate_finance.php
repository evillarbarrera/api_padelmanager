<?php
require_once "../db.php";

$queries = [
    "ALTER TABLE usuarios ADD COLUMN banco_titular VARCHAR(150) DEFAULT NULL",
    "ALTER TABLE usuarios ADD COLUMN banco_rut VARCHAR(20) DEFAULT NULL",
    "ALTER TABLE usuarios ADD COLUMN banco_nombre VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE usuarios ADD COLUMN banco_tipo_cuenta VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE usuarios ADD COLUMN banco_numero_cuenta VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE usuarios ADD COLUMN transbank_active TINYINT(1) DEFAULT 1",
    "ALTER TABLE usuarios ADD COLUMN comision_activa TINYINT(1) DEFAULT 1",
    "ALTER TABLE usuarios ADD COLUMN comision_porcentaje DECIMAL(5,2) DEFAULT 3.50",
    "ALTER TABLE ventas ADD COLUMN comision_admin DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE ventas ADD COLUMN neto_entrenador DECIMAL(10,2) DEFAULT 0"
];

foreach ($queries as $sql) {
    if ($conn->query($sql)) {
        echo "Exito: " . $sql . "<br>";
    } else {
        echo "Error o ya existe ($sql): " . $conn->error . "<br>";
    }
}
echo "Migracion terminada.";
?>
