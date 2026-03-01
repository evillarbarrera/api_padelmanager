<?php
header("Content-Type: application/json");
require_once "../db.php";

$torneo_id = 1;
$data = [];

// Categorías
$resCat = $conn->query("SELECT * FROM torneo_categorias WHERE torneo_id = $torneo_id");
$data['categorias'] = [];
while($row = $resCat->fetch_assoc()) $data['categorias'][] = $row;

// Inscripciones por categoría
foreach ($data['categorias'] as &$cat) {
    $cid = $cat['id'];
    $resIns = $conn->query("SELECT i.*, p.nombre_pareja, 
                            COALESCE(u1.nombre, p.jugador1_nombre_manual) as j1,
                            COALESCE(u2.nombre, p.jugador2_nombre_manual) as j2
                            FROM torneo_inscripciones i 
                            JOIN torneo_parejas p ON i.pareja_id = p.id
                            LEFT JOIN usuarios u1 ON p.jugador1_id = u1.id
                            LEFT JOIN usuarios u2 ON p.jugador2_id = u2.id
                            WHERE i.categoria_id = $cid");
    $cat['inscripciones'] = [];
    while($row = $resIns->fetch_assoc()) $cat['inscripciones'][] = $row;
}

// Grupos
$resGrupos = $conn->query("SELECT g.* FROM torneo_grupos g 
                           JOIN torneo_categorias c ON g.categoria_id = c.id 
                           WHERE c.torneo_id = $torneo_id");
$data['grupos'] = [];
while($row = $resGrupos->fetch_assoc()) $data['grupos'][] = $row;

echo json_encode($data);
?>
