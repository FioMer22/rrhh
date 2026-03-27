-- Ejecutar en phpMyAdmin con la BD d046530d seleccionada
ALTER TABLE usuarios 
ADD COLUMN ci VARCHAR(20) NULL AFTER apellido;
