-- =============================================
-- ESQUEMA DE PERMISOS DINÁMICOS Y MÓDULOS (RBAC)
-- Portal de Sistemas - Grupo Huerta / Divolavilla
-- =============================================

-- 1. Asegurar tabla usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(50) NOT NULL UNIQUE,
  `nombre` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `agencia` VARCHAR(100) DEFAULT 'VW Divol La Villa',
  `rol` ENUM('SuperAdmin', 'Admin', 'Usuario') DEFAULT 'Admin',
  `activo` TINYINT(1) DEFAULT 1,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Catálogo de Módulos (Auto-Extensible)
CREATE TABLE IF NOT EXISTS `modulos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clave` VARCHAR(50) NOT NULL UNIQUE,
  `nombre` VARCHAR(100) NOT NULL,
  `descripcion` TEXT,
  `icono` VARCHAR(50) DEFAULT 'bi-app-indicator',
  `orden` INT DEFAULT 0,
  `estatus` TINYINT(1) DEFAULT 1,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla Granular de Permisos por Usuario y Módulo
CREATE TABLE IF NOT EXISTS `usuario_permisos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NOT NULL,
  `modulo_clave` VARCHAR(50) NOT NULL,
  `puede_ver` TINYINT(1) DEFAULT 0,
  `puede_crear` TINYINT(1) DEFAULT 0,
  `puede_editar` TINYINT(1) DEFAULT 0,
  `puede_eliminar` TINYINT(1) DEFAULT 0,
  `puede_exportar` TINYINT(1) DEFAULT 0,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`modulo_clave`) REFERENCES `modulos`(`clave`) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY `uq_usuario_modulo` (`usuario_id`, `modulo_clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Sembrado Inicial de Módulos Registrados
INSERT INTO `modulos` (`clave`, `nombre`, `descripcion`, `icono`, `orden`, `estatus`) VALUES
('usuarios', 'Gestión de Usuarios', 'Administración de usuarios, roles y permisos del portal', 'bi-people-fill', 1, 1),
('ordenes_servicio', 'Órdenes de Servicio', 'Resumen de órdenes abiertas, cerradas y montos acumulados', 'bi-file-earmark-bar-graph', 2, 1),
('equipos', 'Inventario de Equipos', 'Control de PCs, laptops, servidores e impresoras', 'bi-display-fill', 3, 1),
('celulares', 'Inventario de Celulares', 'Control de equipos móviles y líneas corporativas', 'bi-phone-fill', 4, 1),
('licencias', 'Licencias de Software', 'Matriz de licenciamiento corporativo y vencimientos', 'bi-key-fill', 5, 1),
('infraestructura', 'Infraestructura (SITE / IDF)', 'Control de racks, switches y cableado estructurado', 'bi-hdd-rack-fill', 6, 1)
ON DUPLICATE KEY UPDATE `nombre`=VALUES(`nombre`), `icono`=VALUES(`icono`), `orden`=VALUES(`orden`);
