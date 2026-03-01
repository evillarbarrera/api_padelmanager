-- Solo ejecutar las líneas de columnas que falten.
-- Si ya tienes jugador_id, solo asegurate que permita NULL

-- 1. Modificar jugador_id para acepar NULL (asumiendo que se llama jugador_id y no usuario_id)
ALTER TABLE torneo_participantes MODIFY COLUMN jugador_id INT NULL;

-- 2. Agregar columnas externas si no existen
ALTER TABLE torneo_participantes ADD COLUMN IF NOT EXISTS nombre_externo_1 VARCHAR(255) NULL;
ALTER TABLE torneo_participantes ADD COLUMN IF NOT EXISTS nombre_externo_2 VARCHAR(255) NULL;

-- (Opcional) Si no tenias jugador2_id o nombre_pareja
ALTER TABLE torneo_participantes ADD COLUMN IF NOT EXISTS jugador2_id INT NULL;
ALTER TABLE torneo_participantes ADD COLUMN IF NOT EXISTS nombre_pareja VARCHAR(255) NULL;

-- 3. Tabla de partidos
ALTER TABLE torneo_partidos ADD COLUMN IF NOT EXISTS nombre_externo_1 VARCHAR(255) NULL;
ALTER TABLE torneo_partidos ADD COLUMN IF NOT EXISTS nombre_externo_2 VARCHAR(255) NULL;
ALTER TABLE torneo_partidos ADD COLUMN IF NOT EXISTS nombre_externo_3 VARCHAR(255) NULL;
ALTER TABLE torneo_partidos ADD COLUMN IF NOT EXISTS nombre_externo_4 VARCHAR(255) NULL;
