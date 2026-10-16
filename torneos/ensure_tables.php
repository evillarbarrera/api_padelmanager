<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$sql = "CREATE TABLE IF NOT EXISTS `torneo_partidos_cuadro` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `nivel` int(11) NOT NULL COMMENT '1: Final, 2: Semi, 3: Cuartos, 4: Octavos',
  `lado_cuadro` varchar(10) NOT NULL COMMENT 'izq, der',
  `posicion` int(11) NOT NULL,
  `pareja1_id` int(11) DEFAULT NULL,
  `pareja2_id` int(11) DEFAULT NULL,
  `ganador_id` int(11) DEFAULT NULL,
  `score` varchar(50) DEFAULT NULL,
  `fecha_inicio` datetime DEFAULT NULL,
  `cancha_id` int(11) DEFAULT NULL,
  `siguiente_partido_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "Table torneo_partidos_cuadro ensured."]);
} else {
    echo json_encode(["success" => false, "error" => $conn->error]);
}
?>
