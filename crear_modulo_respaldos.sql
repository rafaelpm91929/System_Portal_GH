-- =============================================================================
-- SCRIPT SQL: INSTALACIÓN DEL MÓDULO DE MONITOREO DE RESPALDOS (NAS)
-- Para ejecutar manualmente en phpMyAdmin en otros cPanels
-- =============================================================================

-- 1. Registrar el módulo en la tabla de módulos del portal (si no existe)
INSERT INTO `modulos` (`clave`, `nombre`, `descripcion`, `icono`, `orden`, `estatus`)
SELECT 'respaldos', 'Monitoreo de Respaldos (NAS)', 'Monitoreo en tiempo real de respaldos en NAS locales con semáforo y auditoría', 'bi-cloud-arrow-up-fill', 13, 1
WHERE NOT EXISTS (SELECT 1 FROM `modulos` WHERE `clave` = 'respaldos');

-- 2. Tabla de Dispositivos NAS (QNAP, Buffalo, Synology, Windows Server, etc.)
CREATE TABLE IF NOT EXISTS `respaldos_nas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `ip_ruta_red` VARCHAR(255) NOT NULL,
  `usuario_smb` VARCHAR(100) NULL,
  `password_smb` VARCHAR(255) NULL,
  `marca` VARCHAR(50) DEFAULT 'QNAP',
  `estatus` TINYINT(1) DEFAULT 1,
  `notas` TEXT NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Carpetas Compartidas por NAS y Mapeo por Área
CREATE TABLE IF NOT EXISTS `respaldos_carpetas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nas_id` INT NOT NULL,
  `nombre_carpeta` VARCHAR(255) NOT NULL,
  `ruta_relativa` VARCHAR(255) NULL,
  `area_nombre` VARCHAR(100) DEFAULT 'General',
  `monitorear` TINYINT(1) DEFAULT 1,
  `es_sistema` TINYINT(1) DEFAULT 0,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_nas_monitorear` (`nas_id`, `monitorear`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla de Equipos y Estado del Semáforo (150+ máquinas)
CREATE TABLE IF NOT EXISTS `respaldos_equipos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre_equipo` VARCHAR(100) NOT NULL,
  `usuario_responsable` VARCHAR(150) NULL,
  `area_nombre` VARCHAR(100) DEFAULT 'General',
  `nas_id` INT NULL,
  `carpeta_id` INT NULL,
  `ruta_origen` VARCHAR(255) NULL,
  `ultimo_respaldo` DATETIME NULL,
  `archivo_reciente` VARCHAR(255) NULL,
  `tamano_bytes` BIGINT DEFAULT 0,
  `estado` ENUM('exitoso', 'vencido', 'critico', 'incompleto') DEFAULT 'critico',
  `dias_sin_respaldo` INT DEFAULT 0,
  `tiene_temporales` TINYINT(1) DEFAULT 0,
  `detalles_temporales` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_nombre_equipo` (`nombre_equipo`),
  INDEX `idx_estado` (`estado`),
  INDEX `idx_area` (`area_nombre`),
  INDEX `idx_nas` (`nas_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabla de Historial Cronológico de Auditoría (90 días y Heatmap)
CREATE TABLE IF NOT EXISTS `respaldos_historial` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `equipo_id` INT NULL,
  `nombre_equipo` VARCHAR(100) NOT NULL,
  `nas_id` INT NULL,
  `fecha` DATE NOT NULL,
  `fecha_hora` DATETIME NOT NULL,
  `estado` ENUM('exitoso', 'vencido', 'critico', 'incompleto') NOT NULL,
  `archivo_nombre` VARCHAR(255) NULL,
  `tamano_bytes` BIGINT DEFAULT 0,
  `duracion_seg` INT DEFAULT 0,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_historial_equipo` (`nombre_equipo`, `fecha`),
  INDEX `idx_historial_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabla de Parámetros y Token de Autenticación de la API
CREATE TABLE IF NOT EXISTS `respaldos_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clave` VARCHAR(50) NOT NULL UNIQUE,
  `valor` TEXT NOT NULL,
  `descripcion` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Valores de Configuración Inicial
INSERT INTO `respaldos_config` (`clave`, `valor`, `descripcion`) VALUES
('token_api_colector', MD5(RAND()), 'Token secreto Bearer para autenticación de scripts colectores LAN')
ON DUPLICATE KEY UPDATE `id` = `id`;

INSERT INTO `respaldos_config` (`clave`, `valor`, `descripcion`) VALUES
('umbral_alerta_horas', '24', 'Horas transcurridas para considerar un respaldo como vencido (Amarillo)'),
('umbral_critico_horas', '48', 'Horas transcurridas para considerar un respaldo como crítico (Rojo)'),
('tamano_minimo_valido_mb', '1', 'Tamaño mínimo en MB para validar que un archivo no está vacío o corrupto'),
('patrones_descarte', '@Recycle,#snapshot,@Recently-Snapshot,.dropbox.cache,aquota.user,aquota.group,Thumbs.db,desktop.ini,.DS_Store', 'Carpetas y archivos del sistema a descartar automáticamente')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- 8. Asignar Permisos a los Administradores Activos
INSERT INTO `usuario_permisos` (`usuario_id`, `modulo_clave`, `puede_ver`, `puede_crear`, `puede_editar`, `puede_eliminar`, `puede_exportar`)
SELECT `id`, 'respaldos', 1, 1, 1, 1, 1
FROM `usuarios`
WHERE `rol` IN ('SuperAdmin', 'Admin')
ON DUPLICATE KEY UPDATE 
    `puede_ver` = 1, 
    `puede_crear` = 1, 
    `puede_editar` = 1, 
    `puede_eliminar` = 1, 
    `puede_exportar` = 1;
