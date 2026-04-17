/* 1. Tabla de Productos */
CREATE TABLE IF NOT EXISTS inventario_productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    categoria ENUM('Bebidas', 'Pelotas', 'Indumentaria', 'Accesorios', 'Otros') DEFAULT 'Otros',
    precio_costo DECIMAL(10,2) DEFAULT 0.00,
    precio_venta DECIMAL(10,2) NOT NULL,
    stock_actual INT DEFAULT 0,
    stock_minimo INT DEFAULT 5,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_club_producto (club_id, activo)
);

/* 2. Registro de Ventas (Cabecera) */
CREATE TABLE IF NOT EXISTS inventario_ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    usuario_id INT NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    metodo_pago ENUM('Efectivo', 'Tarjeta', 'Transferencia', 'Cuenta Corriente') DEFAULT 'Efectivo',
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_club_ventas (club_id, fecha)
);

/* 3. Detalle de la Venta */
CREATE TABLE IF NOT EXISTS inventario_venta_detalles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (venta_id) REFERENCES inventario_ventas(id) ON DELETE CASCADE
);

/* 4. Historial de Movimientos (Kardex) */
CREATE TABLE IF NOT EXISTS inventario_movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    producto_id INT NOT NULL,
    tipo ENUM('entrada', 'salida') NOT NULL,
    cantidad INT NOT NULL,
    motivo VARCHAR(255),
    referencia_id INT NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_club_movs (club_id, producto_id)
);
