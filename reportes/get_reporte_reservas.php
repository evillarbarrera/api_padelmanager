<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$club_id = $_GET['club_id'] ?? null;

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

try {
    /** @var PDO $pdo */
    // 1. Daily reservations (last 30 days)
    $stmtDay = $pdo->prepare("SELECT DATE(r.fecha) as label, COALESCE(COUNT(*), 0) as value 
                               FROM reservas_cancha r
                               JOIN canchas c ON r.cancha_id = c.id
                               WHERE c.club_id = ? AND r.estado != 'Cancelada' AND DATE(r.fecha) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                               GROUP BY DATE(r.fecha) 
                               ORDER BY DATE(r.fecha) ASC");
    $stmtDay->execute([$club_id]);
    $daily = $stmtDay->fetchAll();

    // 2. Weekly reservations (last 12 weeks)
    $stmtWeek = $pdo->prepare("SELECT DATE(DATE_SUB(r.fecha, INTERVAL WEEKDAY(r.fecha) DAY)) as label, COALESCE(COUNT(*), 0) as value 
                                FROM reservas_cancha r
                                JOIN canchas c ON r.cancha_id = c.id
                                WHERE c.club_id = ? AND r.estado != 'Cancelada' AND DATE(r.fecha) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                GROUP BY label
                                ORDER BY label ASC");
    $stmtWeek->execute([$club_id]);
    $weekly = $stmtWeek->fetchAll();

    // 3. Monthly reservations (last 12 months)
    $stmtMonth = $pdo->prepare("SELECT DATE_FORMAT(r.fecha, '%Y-%m') as label, COALESCE(COUNT(*), 0) as value 
                                 FROM reservas_cancha r
                                 JOIN canchas c ON r.cancha_id = c.id
                                 WHERE c.club_id = ? AND r.estado != 'Cancelada' AND DATE(r.fecha) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 GROUP BY DATE_FORMAT(r.fecha, '%Y-%m') 
                                 ORDER BY DATE_FORMAT(r.fecha, '%Y-%m') ASC");
    $stmtMonth->execute([$club_id]);
    $monthly = $stmtMonth->fetchAll();

    echo json_encode([
        "success" => true,
        "daily" => $daily,
        "weekly" => $weekly,
        "monthly" => $monthly
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
