/* Tabla para registrar consumos pendientes por jugador en una reserva */
CREATE TABLE IF NOT EXISTS reservas_consumos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reserva_id INT NOT NULL,
    jugador_n INT NOT NULL DEFAULT 1, -- Identifica si es el Jugador 1, 2, 3 o 4 de la reserva
    producto_id INT NOT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    precio_unitario DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    pagado TINYINT(1) DEFAULT 0, -- 0: Pendiente, 1: Pagado
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reserva (reserva_id),
    INDEX idx_producto (producto_id)
);
