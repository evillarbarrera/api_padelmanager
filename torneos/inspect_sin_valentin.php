<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "../db.php";

$db = new mysqli($servername, $username, $password, $dbname);
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// 1. Find the tournament
$stmt = $db->prepare("SELECT id, nombre FROM torneos_americanos WHERE nombre LIKE '%sin valentin%'");
$stmt->execute();
$res = $stmt->get_result();
$torneos = [];
while ($row = $res->fetch_assoc()) {
    $torneos[] = $row;
}

if (empty($torneos)) {
    echo json_encode(["status" => "error", "message" => "No tournament found matching 'sin valentin'"]);
    exit;
}

$torneo_id = $torneos[0]['id']; // Take the first one

// 2. Find couples for this tournament
$stmt = $db->prepare("SELECT id, jugador1_nombre, jugador2_nombre FROM torneo_participantes WHERE torneo_id = ?");
$stmt->bind_param("i", $torneo_id);
$stmt->execute();
$res = $stmt->get_result();
$parejas = [];
while ($row = $res->fetch_assoc()) {
    $parejas[] = $row;
}

// 3. Count matches for each couple
// Matches are linked via couple IDs in jugador1_id, jugador3_id, etc. ?
// No, the matches likely use participant IDs or player IDs.
// Let's check `torneo_partidos` structure if possible or assume logic.
// Usually `torneo_partidos` has `pareja1_id` and `pareja2_id`.

// Let's assume table `torneo_partidos` with `pareja1_id` and `pareja2_id`.
$matches_by_pareja = [];
foreach ($parejas as $p) {
    $matches_by_pareja[$p['id']] = 0;
}

// Query matches
$stmt = $db->prepare("SELECT pareja1_id, pareja2_id FROM torneo_partidos WHERE torneo_id = ?");
$stmt->bind_param("i", $torneo_id);
$stmt->execute();
$res = $stmt->get_result();
$partidos = [];
while ($row = $res->fetch_assoc()) {
    $partidos[] = $row;
    if (isset($matches_by_pareja[$row['pareja1_id']])) $matches_by_pareja[$row['pareja1_id']]++;
    if (isset($matches_by_pareja[$row['pareja2_id']])) $matches_by_pareja[$row['pareja2_id']]++;
}

// Build result for inspection
$result = [];
foreach ($parejas as $p) {
    $count = $matches_by_pareja[$p['id']] ?? 0;
    $result[] = [
        "id" => $p['id'],
        "nom1" => $p['jugador1_nombre'],
        "nom2" => $p['jugador2_nombre'],
        "matches_played" => $count
    ];
}

echo json_encode([
    "tournament" => $torneos[0],
    "couples_status" => $result,
    "raw_matches_count" => count($partidos)
]);

$db->close();
?>
