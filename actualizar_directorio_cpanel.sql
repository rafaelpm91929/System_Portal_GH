-- ========================================================
-- SCRIPT SQL: INSTALACIÓN DEL MÓDULO DIRECTORIO EN CPANEL
-- Base de datos: MySQL (phpMyAdmin)
-- ========================================================

-- 1. Agregar columnas para Extensión y Contraseña de Correo en la tabla usuarios (si no existen)
SET @dbname = DATABASE();
SET @tablename = "usuarios";
SET @columnname = "contrasena_correo";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `usuarios` ADD COLUMN `contrasena_correo` VARCHAR(255) NULL AFTER `telefono`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = "extension";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `usuarios` ADD COLUMN `extension` VARCHAR(50) NULL AFTER `telefono`;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Registrar el nuevo módulo Directorio en el catálogo de módulos
INSERT INTO `modulos` (`clave`, `nombre`, `descripcion`, `icono`, `orden`, `estatus`)
VALUES ('directorio', 'Directorio de Personal', 'Directorio oficial de la sucursal: usuarios, áreas, correos institucionales, extensiones y contraseñas de correo', 'bi-person-lines-fill', 7, 1)
ON DUPLICATE KEY UPDATE 
  `nombre` = VALUES(`nombre`),
  `descripcion` = VALUES(`descripcion`),
  `icono` = VALUES(`icono`),
  `estatus` = 1;
