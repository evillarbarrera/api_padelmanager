-- SQL PARA MÓDULO DE CLUBES, CANCHAS Y TORNEOS AMERICANOS

-- 1. TABLA DE CLUBES
CREATE TABLE IF NOT EXISTS clubes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    direccion TEXT,
    telefono VARCHAR(50),
    instagram VARCHAR(100),
    email VARCHAR(100),
    logo VARCHAR(255),
    admin_id INT, -- Referencia al administrador del club (usuario con rol 'administrador_club')
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. TABLA DE CANCHAS
CREATE TABLE IF NOT EXISTS canchas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    tipo ENUM('Indoor', 'Outdoor', 'Cubierta') DEFAULT 'Outdoor',
    superficie VARCHAR(50) DEFAULT 'Césped Sintético',
    precio_hora DECIMAL(10, 2) DEFAULT 0.00,
    activa BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (club_id) REFERENCES clubes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. CONFIGURACIÓN DE HORARIOS DE CANCHAS (Disponibilidad base semanal)
CREATE TABLE IF NOT EXISTS cancha_horarios_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cancha_id INT NOT NULL,
    dia_semana INT NOT NULL COMMENT '0=Domingo, 1=Lunes, ..., 6=Sábado',
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    duracion_bloque INT DEFAULT 90 COMMENT 'minutos por bloque de reserva',
    FOREIGN KEY (cancha_id) REFERENCES canchas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. RESERVAS DE CANCHAS
CREATE TABLE IF NOT EXISTS reservas_cancha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cancha_id INT NOT NULL,
    usuario_id INT NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    precio DECIMAL(10, 2),
    estado ENUM('Pendiente', 'Confirmada', 'Pagada', 'Cancelada') DEFAULT 'Pendiente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cancha_id) REFERENCES canchas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. TORNEOS AMERICANOS
CREATE TABLE IF NOT EXISTS torneos_americanos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    num_canchas INT DEFAULT 1,
    tiempo_por_partido INT DEFAULT 20 COMMENT 'minutos',
    puntos_ganado INT DEFAULT 3,
    puntos_empate INT DEFAULT 1,
    puntos_1er_lugar INT DEFAULT 100,
    puntos_2do_lugar INT DEFAULT 50,
    puntos_3er_lugar INT DEFAULT 25,
    estado ENUM('Abierto', 'En Curso', 'Finalizado') DEFAULT 'Abierto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES clubes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. PARTICIPANTES DEL TORNEO (Ranking y progreso)
CREATE TABLE IF NOT EXISTS torneo_participantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    torneo_id INT NOT NULL,
    usuario_id INT NOT NULL,
    puntos_acumulados INT DEFAULT 0,
    games_favor INT DEFAULT 0,
    games_contra INT DEFAULT 0,
    posicion_final INT DEFAULT NULL,
    FOREIGN KEY (torneo_id) REFERENCES torneos_americanos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. PARTIDOS DEL TORNEO (Registro de resultados)
CREATE TABLE IF NOT EXISTS torneo_partidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    torneo_id INT NOT NULL,
    cancha_id INT,
    ronda INT,
    jugador1_id INT,
    jugador2_id INT,
    jugador3_id INT,
    jugador4_id INT,
    score_pareja1 INT DEFAULT 0,
    score_pareja2 INT DEFAULT 0,
    finalizado BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (torneo_id) REFERENCES torneos_americanos(id) ON DELETE CASCADE,
    FOREIGN KEY (cancha_id) REFERENCES canchas(id) ON DELETE SET NULL,
    FOREIGN KEY (jugador1_id) REFERENCES usuarios(id),
    FOREIGN KEY (jugador2_id) REFERENCES usuarios(id),
    FOREIGN KEY (jugador3_id) REFERENCES usuarios(id),
    FOREIGN KEY (jugador4_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
