<?php
$host = "localhost";
$user = "c2632100_manager";
$pass = "boBUraze40";
$dbname = "c2632100_manager";

// Configurar zona horaria (Santiago, Chile)
date_default_timezone_set('America/Santiago');
$santiago_offset = date('P'); // Retorna dinámicamente '-04:00' o '-03:00' según Horario de Invierno/Verano

$conn = new mysqli($host, $user, $pass, $dbname);
$conn->set_charset("utf8mb4");

// Sincronizar zona horaria de MySQL dinámicamente con Chile
@$conn->query("SET time_zone = '$santiago_offset'");

if ($conn->connect_error) {
    die(json_encode(["error" => "Error en conexión: " . $conn->connect_error]));
}

// 🌐 ADD PDO SUPPORT (for legacy/mesh compatibility)
$pdo = null;
try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    @$pdo->exec("SET time_zone = '$santiago_offset'");
} catch (PDOException $e) {
    // We don't die here because some scripts might only need $conn
}

// La base de datos debe ser actualizada mediante scripts SQL independientes para evitar errores de sintaxis en tiempo de ejecución.


// Reset automático de inscripciones grupales (Packs Grupales)
// Las inscripciones realizadas antes de un domingo (fin de semana anterior) expiran.
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_pack_related = (strpos($request_uri, 'pack') !== false || 
                    strpos($request_uri, 'alumno') !== false || 
                    strpos($request_uri, 'entrenador') !== false ||
                    strpos($request_uri, 'reserva') !== false);

/*
if ($is_pack_related) {
    // 1. Expirar inscripciones solo cuando el pack ya no esté activo o haya pasado mucho tiempo (ej: 6 meses)
    // No lo haremos cada domingo para evitar que los alumnos pierdan su cupo si el entrenador no renovó el bloque a tiempo.
    // Solo expiramos si el pack explícitamente se marcó como inactivo o si la fecha de fin del bloque (si existe) ya pasó.
    
    $sql_exp = "UPDATE inscripciones_grupales ig
                JOIN packs p ON ig.pack_id = p.id
                SET ig.estado = 'expirado'
                WHERE ig.estado = 'activo'
                AND (p.activo = 0 OR ig.fecha_inscripcion < DATE_SUB(NOW(), INTERVAL 6 MONTH))";
    $conn->query($sql_exp);
    
    // 2. Sincronizar cupos_ocupados basándose SOLO en inscripciones activas
    $sql_sync = "UPDATE packs p 
                 SET p.cupos_ocupados = (
                     SELECT COUNT(*) 
                     FROM inscripciones_grupales ig 
                     WHERE ig.pack_id = p.id AND ig.estado = 'activo'
                 ) 
                 WHERE p.tipo = 'grupal'";
    $conn->query($sql_sync);
}
*/
