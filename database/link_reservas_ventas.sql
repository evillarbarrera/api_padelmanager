/* SQL compatible con versiones anteriores de MySQL (Sin IF NOT EXISTS en ALTER TABLE) */

/* 1. Agregar referencia a reserva en las ventas */
ALTER TABLE inventario_ventas ADD COLUMN reserva_id INT NULL;

/* 2. Agregar flag en reservas para saber si ya se procesó la venta */
ALTER TABLE reservas_cancha ADD COLUMN venta_generada TINYINT(1) DEFAULT 0;
