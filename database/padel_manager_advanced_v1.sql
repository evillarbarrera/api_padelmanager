-- PadelManager Advanced Schema Update - Versión Compatible
-- Actualización para Soporte de Cabezas de Serie (Seeding) y Restricciones Horarias

-- 1. Ampliar tabla de inscripciones/participantes
-- NOTA: Si alguna columna ya existe, MySQL dará error. Puedes ejecutar las que falten.
ALTER TABLE torneo_participantes ADD COLUMN es_semilla BOOLEAN DEFAULT FALSE;
ALTER TABLE torneo_participantes ADD COLUMN nro_siembra INT NULL;
ALTER TABLE torneo_participantes ADD COLUMN ranking_puntos INT DEFAULT 0;

-- 2. Tabla de Restricciones Horarias por Pareja
CREATE TABLE IF NOT EXISTS torneo_restricciones_horarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inscripcion_id INT NOT NULL,
    dia DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    motivo VARCHAR(255),
    FOREIGN KEY (inscripcion_id) REFERENCES torneo_participantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ampliar tabla de categorías con preferencias de scheduling
ALTER TABLE torneo_categorias ADD COLUMN preferencia_horario ENUM('Indiferente', 'Mañana', 'Tarde', 'Noche') DEFAULT 'Indiferente';
ALTER TABLE torneo_categorias ADD COLUMN frecuencia_partidos ENUM('Diario', 'Dia por medio') DEFAULT 'Diario';

-- 4. Ampliar tabla de partidos para reportar conflictos
ALTER TABLE torneo_partidos ADD COLUMN tiene_conflicto BOOLEAN DEFAULT FALSE;
ALTER TABLE torneo_partidos ADD COLUMN msg_conflicto TEXT NULL;
