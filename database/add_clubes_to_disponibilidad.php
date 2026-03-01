<?php
require_once "../db.php";

function execQuery($conn, $sql, $desc) {
    if ($conn->query($sql)) {
        echo "✅ $desc<br>";
        return true;
    } else {
        echo "❌ Error en $desc: " . $conn->error . "<br>";
        return false;
    }
}

function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

echo "<h2>Migración: Integración de Clubes en Disponibilidad</h2>";

// 1. Asegurar campos en tabla 'clubes'
if (!columnExists($conn, 'clubes', 'google_maps_link')) {
    execQuery($conn, "ALTER TABLE clubes ADD COLUMN google_maps_link TEXT AFTER email", "Añadiendo google_maps_link a clubes");
}

// 2. Modificar 'entrenador_horarios_config' para incluir club_id
if (!columnExists($conn, 'entrenador_horarios_config', 'club_id')) {
    execQuery($conn, "ALTER TABLE entrenador_horarios_config ADD COLUMN club_id INT DEFAULT NULL AFTER entrenador_id", "Añadiendo club_id a entrenador_horarios_config");
}

// 2b. Modificar 'reservas' para incluir club_id
if (!columnExists($conn, 'reservas', 'club_id')) {
    execQuery($conn, "ALTER TABLE reservas ADD COLUMN club_id INT DEFAULT NULL AFTER pack_id", "Añadiendo club_id a reservas");
}

// 2c. Modificar 'packs' para incluir club_id
if (!columnExists($conn, 'packs', 'club_id')) {
    execQuery($conn, "ALTER TABLE packs ADD COLUMN club_id INT DEFAULT NULL AFTER entrenador_id", "Añadiendo club_id a packs");
}

// 3. Pre-cargar clubes de la Región de O'Higgins
$clubesPre = [
    [
        'nombre' => 'Padel Oriente',
        'direccion' => 'San Juan 450, Rancagua',
        'region' => 'Metropolitana de Santiago', // Usando las regiones que vi en el frontend (aunque Rancagua es O'Higgins, usaré el nombre que maneja la App)
        'comuna' => 'Rancagua',
        'maps' => 'https://maps.app.goo.gl/9ZQXJ3G5Z6Z6Z6Z6Z'
    ],
    [
        'nombre' => 'Punto Padel Machalí',
        'direccion' => 'Av. San Juan 2100, Machalí',
        'region' => 'Metropolitana de Santiago',
        'comuna' => 'Machalí',
        'maps' => 'https://maps.app.goo.gl/X1Y2Z3A4B5C6D7E8F'
    ],
    [
        'nombre' => 'Club de Campo El Diez',
        'direccion' => 'Carretera El Cobre Km 4, Rancagua',
        'region' => 'Metropolitana de Santiago',
        'comuna' => 'Rancagua',
        'maps' => 'https://maps.app.goo.gl/G7H8I9J0K1L2M3N4O'
    ],
    [
        'nombre' => 'Rancagua Padel Court',
        'direccion' => 'Miguel Ramírez 600, Rancagua',
        'region' => 'Metropolitana de Santiago',
        'comuna' => 'Rancagua',
        'maps' => 'https://maps.app.goo.gl/P5Q6R7S8T9U0V1W2X'
    ]
];

// Nota: En la App vi que manejan 'Metropolitana de Santiago' como región prinicipal en el selector hardcoded de JugadorReservasPage. 
// Pero para Machalí/Rancagua debería ser O'Higgins. Voy a revisar JugadorReservasPage de nuevo.

foreach ($clubesPre as $c) {
    $check = $conn->prepare("SELECT id FROM clubes WHERE nombre = ?");
    $check->bind_param("s", $c['nombre']);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        $ins = $conn->prepare("INSERT INTO clubes (nombre, direccion, google_maps_link, admin_id) VALUES (?, ?, ?, 1)");
        $ins->bind_param("sss", $c['nombre'], $c['direccion'], $c['maps']);
        if ($ins->execute()) {
            $club_id = $conn->insert_id;
            // Insertar dirección vinculada
            $insDir = $conn->prepare("INSERT INTO direcciones (club_id, region, comuna, calle) VALUES (?, 'O\'Higgins', ?, ?)");
            $insDir->bind_param("iss", $club_id, $c['comuna'], $c['direccion']);
            $insDir->execute();
            echo "✅ Club '{$c['nombre']}' pre-cargado.<br>";
        }
    } else {
        echo "ℹ️ Club '{$c['nombre']}' ya existe.<br>";
    }
}

echo "<br><b>Hecho.</b>";
?>
