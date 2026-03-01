<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$accion = $data['accion'] ?? '';

if ($accion === 'update_reset_columns') {
    $sql = "ALTER TABLE usuarios 
            ADD COLUMN IF NOT EXISTS reset_token VARCHAR(100) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS reset_expires DATETIME DEFAULT NULL";
    
    // IF NOT EXISTS no funciona directo en ALTER TABLE en todas las versiones de MariaDB/MySQL
    // Así que intentamos y capturamos error de duplicado
    try {
        if ($conn->query($sql)) {
            echo json_encode(["success" => true, "message" => "Base de datos actualizada correctamente"]);
        } else {
            // Si el error es 'Duplicate column', lo tratamos como éxito
            if ($conn->errno == 1060) {
                echo json_encode(["success" => true, "message" => "Las columnas ya existen en la base de datos"]);
            } else {
                throw new Exception($conn->error);
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
} elseif ($accion === 'update_invitation_columns') {
    try {
        // Columna estado
        $conn->query("ALTER TABLE pack_jugadores_adicionales ADD COLUMN IF NOT EXISTS estado ENUM('pendiente', 'aceptado') DEFAULT 'pendiente'");
        // Columna token
        $conn->query("ALTER TABLE pack_jugadores_adicionales ADD COLUMN IF NOT EXISTS token VARCHAR(100) DEFAULT NULL");
        // Actualizar existentes
        $conn->query("UPDATE pack_jugadores_adicionales SET estado = 'aceptado' WHERE estado IS NULL OR estado = ''");

        echo json_encode(["success" => true, "message" => "Sistema de invitaciones habilitado correctamente"]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error DB: " . $e->getMessage()]);
    }
} elseif ($accion === 'add_categoria_column') {
    try {
        // Intentar agregar la columna categoria
        $conn->query("ALTER TABLE usuarios ADD COLUMN categoria VARCHAR(50) DEFAULT NULL");
        echo json_encode(["success" => true, "message" => "Columna 'categoria' agregada correctamente"]);
    } catch (Exception $e) {
        if ($conn->errno == 1060) {
            echo json_encode(["success" => true, "message" => "La columna 'categoria' ya existe"]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Error DB: " . $e->getMessage()]);
        }
    }
} elseif ($accion === 'add_recurrence_support') {
    try {
        $conn->query("ALTER TABLE reservas ADD COLUMN serie_id VARCHAR(50) DEFAULT NULL");
        echo json_encode(["success" => true, "message" => "Soporte para recurrencia activado (serie_id agregado)"]);
    } catch (Exception $e) {
        if ($conn->errno == 1060) {
            echo json_encode(["success" => true, "message" => "La columna 'serie_id' ya existe"]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Error DB: " . $e->getMessage()]);
        }
    }
} elseif ($accion === 'add_videos_table') {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS entrenamiento_videos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jugador_id INT NOT NULL,
            entrenador_id INT NOT NULL,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
            video_url VARCHAR(255) NOT NULL,
            miniatura_url VARCHAR(255) DEFAULT NULL,
            titulo VARCHAR(100) DEFAULT NULL,
            comentario TEXT DEFAULT NULL,
            FOREIGN KEY (jugador_id) REFERENCES usuarios(id),
            FOREIGN KEY (entrenador_id) REFERENCES usuarios(id)
        )";
        $conn->query($sql);
        echo json_encode(["success" => true, "message" => "Tabla 'entrenamiento_videos' creada correctamente"]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error DB: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Acción no reconocida"]);
}
?>
