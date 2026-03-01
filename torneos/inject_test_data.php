<?php
header("Content-Type: application/json");
require_once "../db.php";

$torneo_id = 1;

// 1. Crear categoría de prueba si no hay
$checkCat = $conn->query("SELECT id FROM torneo_categorias WHERE torneo_id = $torneo_id LIMIT 1");
if ($checkCat->num_rows == 0) {
    $conn->query("INSERT INTO torneo_categorias (torneo_id, nombre, max_parejas, puntos_repartir) VALUES ($torneo_id, 'Open Prueba', 8, 500)");
    $cat_id = $conn->insert_id;
} else {
    $cat_id = $checkCat->fetch_assoc()['id'];
}

// 2. Crear 4 parejas ficticias (si no hay suficientes)
$checkIns = $conn->query("SELECT count(*) as total FROM torneo_inscripciones WHERE categoria_id = $cat_id")->fetch_assoc()['total'];

if ($checkIns < 4) {
    $parejas = [
        ['j1' => 'Juan Perez', 'j2' => 'Diego Sosa', 'nombre' => 'Los Galacticos'],
        ['j1' => 'Carlos Ruiz', 'j2' => 'Beto Cuevas', 'nombre' => 'Team Padel'],
        ['j1' => 'Matias Fernandez', 'j2' => 'Jorge Valdivia', 'nombre' => 'Magos de la Pala'],
        ['j1' => 'Alexis Sanchez', 'j2' => 'Arturo Vidal', 'nombre' => 'Generacion Dorada']
    ];

    foreach ($parejas as $p) {
        // Crear pareja manual
        $sqlP = "INSERT INTO torneo_parejas (jugador1_nombre_manual, jugador2_nombre_manual, nombre_pareja) VALUES (?, ?, ?)";
        $stmtP = $conn->prepare($sqlP);
        $stmtP->bind_param("sss", $p['j1'], $p['j2'], $p['nombre']);
        $stmtP->execute();
        $pareja_id = $conn->insert_id;

        // Inscribir
        $conn->query("INSERT INTO torneo_inscripciones (categoria_id, pareja_id, pagado, validado) VALUES ($cat_id, $pareja_id, 1, 1)");
    }
}

echo json_encode(["success" => true, "mensaje" => "Datos de prueba inyectados en torneo 1, categoria $cat_id"]);
?>
