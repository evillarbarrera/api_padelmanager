<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$categoria_id = !empty($input['categoria_id']) ? (int)$input['categoria_id'] : null;
$jornada_id = !empty($input['jornada_id']) ? (int)$input['jornada_id'] : null;
$pareja1_id = !empty($input['pareja1_id']) ? (int)$input['pareja1_id'] : null;
$pareja2_id = !empty($input['pareja2_id']) ? (int)$input['pareja2_id'] : null;
$grupo_id = !empty($input['grupo_id']) ? (int)$input['grupo_id'] : null;

if (!$jornada_id || !$pareja1_id || !$pareja2_id) {
    echo json_encode(["success" => false, "error" => "Jornada y ambas parejas son requeridas"]);
    exit;
}

if ($pareja1_id === $pareja2_id) {
    echo json_encode(["success" => false, "error" => "No puedes enfrentar a una pareja contra sí misma"]);
    exit;
}

try {
    if (!$categoria_id) {
        $resJ = $conn->query("SELECT categoria_id FROM liga_jornadas WHERE id = $jornada_id");
        if ($resJ && $rowJ = $resJ->fetch_assoc()) {
            $categoria_id = (int)$rowJ['categoria_id'];
        }
    }

    if (!$grupo_id) {
        $resP = $conn->query("SELECT grupo_id FROM liga_parejas WHERE id = $pareja1_id");
        if ($resP && $rowP = $resP->fetch_assoc()) {
            $grupo_id = !empty($rowP['grupo_id']) ? (int)$rowP['grupo_id'] : null;
        }
    }

    // Comprobar si ya existe un partido previo entre ambas parejas en la categoría
    $chkExist = $conn->query("SELECT id, estado FROM liga_partidos 
                              WHERE ((pareja1_id = $pareja1_id AND pareja2_id = $pareja2_id) OR (pareja1_id = $pareja2_id AND pareja2_id = $pareja1_id))
                                AND (categoria_id = $categoria_id OR categoria_id IS NULL OR categoria_id = 0)
                                AND (es_playoff IS NULL OR es_playoff = 0) LIMIT 1");
    if ($chkExist && $rowEx = $chkExist->fetch_assoc()) {
        $st = $rowEx['estado'] ?? 'Programado';
        echo json_encode(["success" => false, "error" => "Ya existe un partido registrado ($st) entre estas dos parejas en la categoría."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO liga_partidos (categoria_id, jornada_id, grupo_id, pareja1_id, pareja2_id, estado) VALUES (?, ?, ?, ?, ?, 'Programado')");
    $stmt->bind_param("iiiii", $categoria_id, $jornada_id, $grupo_id, $pareja1_id, $pareja2_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Error al insertar partido: " . $stmt->error);
    }

    $nuevoId = $stmt->insert_id;

    echo json_encode([
        "success" => true,
        "partido_id" => $nuevoId,
        "mensaje" => "Partido agregado exitosamente a la jornada."
    ]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
