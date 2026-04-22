<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "db.php";

$reserva_id = 455;
$jugador_javiera = 10042;
$jugador_fernando = 10045;

echo "--- Iniciando corrección de Reserva $reserva_id ---\n";

// 1. Actualizar cantidad de personas en la reserva principal
$stmt1 = $conn->prepare("UPDATE reservas SET cantidad_personas = 2 WHERE id = ?");
$stmt1->bind_param("i", $reserva_id);
if ($stmt1->execute()) {
    echo "Paso 1: cantidad_personas actualizado a 2 para la reserva $reserva_id.\n";
} else {
    echo "ERROR en Paso 1: " . $conn->error . "\n";
}

// 2. Verificar si Fernando (10045) ya está en la reserva
$stmt2 = $conn->prepare("SELECT id FROM reserva_jugadores WHERE reserva_id = ? AND jugador_id = ?");
$stmt2->bind_param("ii", $reserva_id, $jugador_fernando);
$stmt2->execute();
$res2 = $stmt2->get_result();

if ($res2->num_rows === 0) {
    // 3. Insertar a Fernando en la reserva
    $stmt3 = $conn->prepare("INSERT INTO reserva_jugadores (reserva_id, jugador_id) VALUES (?, ?)");
    $stmt3->bind_param("ii", $reserva_id, $jugador_fernando);
    if ($stmt3->execute()) {
        echo "Paso 2: Jugador Fernando (10045) agregado exitosamente a la reserva $reserva_id.\n";
    } else {
        echo "ERROR en Paso 2: " . $conn->error . "\n";
    }
} else {
    echo "Info: Fernando ya estaba registrado en esta reserva.\n";
}

echo "--- Proceso finalizado ---\n";
?>
