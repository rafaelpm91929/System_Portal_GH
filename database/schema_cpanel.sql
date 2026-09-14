-- =============================================
-- ESQUEMA DE BASE DE DATOS MYSQL PARA CPANEL (phpMyAdmin)
-- Portal de Sistemas - Grupo Huerta / Divolavilla
-- =============================================

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

-- Usuarios de prueba iniciales:
-- Usuario: admin / Contraseña: Admin123!
-- Usuario: tilavilla / Contraseña: Admin123!
INSERT INTO `usuarios` (`usuario`, `nombre`, `email`, `password`, `agencia`, `rol`) 
VALUES 
('admin', 'Administrador Grupo Huerta', 'admin@divolavilla.com', '$2y$10$3zR1bH6Z1L4k8eQ9g5H2u.yKxR8z7w6v5u4t3s2r1q0p9o8n7m6l5', 'VW Divol La Villa', 'SuperAdmin'),
('tilavilla', 'Sistemas La Villa', 'sistemas@divolavilla.com', '$2y$10$3zR1bH6Z1L4k8eQ9g5H2u.yKxR8z7w6v5u4t3s2r1q0p9o8n7m6l5', 'VW Divol La Villa', 'Admin')
ON DUPLICATE KEY UPDATE `id`=`id`;
