-- 1. Arreglar tabla torneo_participantes
-- Primero aseguramos que la estructura sea correcta y soporte jugadores externos
ALTER TABLE torneo_participantes 
    CHANGE COLUMN usuario_id jugador_id INT NULL,
    ADD COLUMN jugador2_id INT NULL,
    ADD COLUMN nombre_pareja VARCHAR(255) NULL,
    ADD COLUMN nombre_externo_1 VARCHAR(255) NULL,
    ADD COLUMN nombre_externo_2 VARCHAR(255) NULL;

-- 2. Asegurar FKs
ALTER TABLE torneo_participantes
    ADD CONSTRAINT fk_tp_j1 FOREIGN KEY (jugador_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tp_j2 FOREIGN KEY (jugador2_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- 3. Arreglar tabla torneo_partidos para guardar nombres externos si es necesario
ALTER TABLE torneo_partidos
    ADD COLUMN nombre_externo_1 VARCHAR(255) NULL,
    ADD COLUMN nombre_externo_2 VARCHAR(255) NULL,
    ADD COLUMN nombre_externo_3 VARCHAR(255) NULL,
    ADD COLUMN nombre_externo_4 VARCHAR(255) NULL;
