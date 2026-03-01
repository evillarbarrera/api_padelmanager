CREATE TABLE IF NOT EXISTS evaluaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jugador_id INT NOT NULL,
    entrenador_id INT NOT NULL,
    fecha DATE,
    scores JSON NOT NULL COMMENT 'Matriz de 11 golpes x 4 metricas',
    promedio_general FLOAT DEFAULT 0,
    comentarios TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (jugador_id) REFERENCES usuarios(id),
    FOREIGN KEY (entrenador_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
