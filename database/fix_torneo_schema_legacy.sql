-- Versión compatible con MySQL antiguo (sin IF NOT EXISTS en columnas)
-- Ejecuta estas líneas una por una.
-- Si alguna te dice "Duplicate column name", IGNORA EL ERROR y pasa a la siguiente.

-- 1. Modificar jugador_id para aceptar NULL
ALTER TABLE torneo_participantes MODIFY COLUMN jugador_id INT NULL;

-- 2. Agregar columnas (si ya existen, dará error, ignorar)
ALTER TABLE torneo_participantes ADD COLUMN nombre_externo_1 VARCHAR(255) NULL;
ALTER TABLE torneo_participantes ADD COLUMN nombre_externo_2 VARCHAR(255) NULL;

-- Asegurar estas también por si acaso
ALTER TABLE torneo_participantes ADD COLUMN jugador2_id INT NULL;
ALTER TABLE torneo_participantes ADD COLUMN nombre_pareja VARCHAR(255) NULL;

-- 3. Tabla de partidos
ALTER TABLE torneo_partidos ADD COLUMN nombre_externo_1 VARCHAR(255) NULL;
ALTER TABLE torneo_partidos ADD COLUMN nombre_externo_2 VARCHAR(255) NULL;
ALTER TABLE torneo_partidos ADD COLUMN nombre_externo_3 VARCHAR(255) NULL;
ALTER TABLE torneo_partidos ADD COLUMN nombre_externo_4 VARCHAR(255) NULL;
