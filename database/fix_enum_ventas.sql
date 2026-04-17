/* Actualizar ENUM de métodos de pago para soportar ventas automáticas desde reservas */

ALTER TABLE inventario_ventas MODIFY COLUMN metodo_pago ENUM('Efectivo', 'Tarjeta', 'Transferencia', 'Cuenta Corriente', 'Reserva', 'Varios') DEFAULT 'Efectivo';
