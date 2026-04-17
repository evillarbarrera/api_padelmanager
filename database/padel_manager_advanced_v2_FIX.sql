-- CORRECCIÓN: Aplicar esquema avanzado a las tablas de Torneos por Categorías (V2)

-- 1. Ampliar tabla de INSCRIPCIONES (la que usa el sistema de Grupos + Playoffs)
ALTER TABLE torneo_inscripciones ADD COLUMN es_semilla BOOLEAN DEFAULT FALSE;
ALTER TABLE torneo_inscripciones ADD COLUMN nro_siembra INT NULL;
ALTER TABLE torneo_inscripciones ADD COLUMN ranking_puntos INT DEFAULT 0;

-- 2. Asegurar que las restricciones horarias apunten a torneo_inscripciones
-- Eliminamos la tabla previa si se creó apuntando a la tabla equivocada
DROP TABLE IF EXISTS torneo_restricciones_horarias;

CREATE TABLE torneo_restricciones_horarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inscripcion_id INT NOT NULL,
    dia DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    motivo VARCHAR(255),
    FOREIGN KEY (inscripcion_id) REFERENCES torneo_inscripciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ampliar tabla de categorías con preferencias de scheduling
-- (Esta tabla suele ser la misma, pero aseguramos las columnas)
ALTER TABLE torneo_categorias ADD COLUMN preferencia_horario ENUM('Indiferente', 'Mañana', 'Tarde', 'Noche') DEFAULT 'Indiferente';
ALTER TABLE torneo_categorias ADD COLUMN frecuencia_partidos ENUM('Diario', 'Dia por medio') DEFAULT 'Diario';

-- 4. Ampliar tabla de partidos para reportar conflictos
ALTER TABLE torneo_partidos ADD COLUMN tiene_conflicto BOOLEAN DEFAULT FALSE;
ALTER TABLE torneo_partidos ADD COLUMN msg_conflicto TEXT NULL;
