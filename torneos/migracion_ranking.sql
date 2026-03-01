-- SCRIPT DE ACTUALIZACIÓN DE BASE DE DATOS - PADEL ACADEMY
-- Este script agrega soporte para categorías y ranking por categorías.

-- 1. Agregar columna de categoría a los torneos americanos
ALTER TABLE torneos_americanos 
ADD COLUMN categoria VARCHAR(50) DEFAULT 'Cuarta';

-- 2. Asegurar que las columnas de puntos existan en torneos_americanos
ALTER TABLE torneos_americanos 
ADD COLUMN puntos_1er_lugar INT DEFAULT 100,
ADD COLUMN puntos_2do_lugar INT DEFAULT 60,
ADD COLUMN puntos_3er_lugar INT DEFAULT 40,
ADD COLUMN puntos_4to_lugar INT DEFAULT 20,
ADD COLUMN puntos_participacion INT DEFAULT 5;

-- 3. Crear la tabla de ranking por categorías
CREATE TABLE IF NOT EXISTS ranking_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    puntos INT DEFAULT 0,
    UNIQUE KEY (usuario_id, categoria),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 4. Asegurar que la tabla usuarios tenga la columna de puntos globales (legacy)
ALTER TABLE usuarios 
ADD COLUMN puntos_ranking INT DEFAULT 0;
