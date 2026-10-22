-- =============================================================================
-- BASE DE DATOS LIMPIA PARA NUEVA AGENCIA (PORTAL AGENCIA)
-- Grupo Huerta - Sistemas
-- Compatible con: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+ (cPanel / phpMyAdmin)
-- Codificación: utf8mb4_unicode_ci
-- Fecha de generación: 2026-10-22
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- =============================================================================
-- 1. ELIMINACIÓN PREVIA DE TABLAS (SI YA EXISTIERAN EN LA BD DESTINO)
-- =============================================================================
DROP TABLE IF EXISTS `notificaciones_lecturas`;
DROP TABLE IF EXISTS `notificaciones`;
DROP TABLE IF EXISTS `compliance_lecturas`;
DROP TABLE IF EXISTS `compliance_documentos`;
DROP TABLE IF EXISTS `politicas_terminos_aceptados`;
DROP TABLE IF EXISTS `politicas_lecturas`;
DROP TABLE IF EXISTS `politicas_corporativas`;
DROP TABLE IF EXISTS `citas_soporte`;
DROP TABLE IF EXISTS `tickets_mensajes`;
DROP TABLE IF EXISTS `tickets_soporte`;
DROP TABLE IF EXISTS `directorio_telefonico`;
DROP TABLE IF EXISTS `reportes_ordenes_servicio`;
DROP TABLE IF EXISTS `reportes_inventario_autos`;
DROP TABLE IF EXISTS `reportes_agencia_datos`;
DROP TABLE IF EXISTS `infra_switch_puertos`;
DROP TABLE IF EXISTS `infra_nodos`;
DROP TABLE IF EXISTS `infra_planos_2d`;
DROP TABLE IF EXISTS `infra_red_idf`;
DROP TABLE IF EXISTS `infra_site`;
DROP TABLE IF EXISTS `infra_vlans`;
DROP TABLE IF EXISTS `infra_diagramas`;
DROP TABLE IF EXISTS `inv_telefonos_poe`;
DROP TABLE IF EXISTS `inv_site_vw`;
DROP TABLE IF EXISTS `inv_nobreak_baja`;
DROP TABLE IF EXISTS `inv_monitores`;
DROP TABLE IF EXISTS `inv_licencias_office`;
DROP TABLE IF EXISTS `inv_equipos_vw`;
DROP TABLE IF EXISTS `inv_equipos_corp`;
DROP TABLE IF EXISTS `inv_equipos_baja`;
DROP TABLE IF EXISTS `inv_dvr_camaras`;
DROP TABLE IF EXISTS `inv_dispositivos_moviles`;
DROP TABLE IF EXISTS `inv_archivo`;
DROP TABLE IF EXISTS `usuario_permisos`;
DROP TABLE IF EXISTS `modulos`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `agencia_areas`;
DROP TABLE IF EXISTS `agencias`;

-- =============================================================================
-- 2. ESTRUCTURA DE TABLAS: CONFIGURACIÓN, USUARIOS Y PERMISOS
-- =============================================================================

-- Tabla: `agencias` (Ficha técnica de la sucursal)
CREATE TABLE `agencias` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(150) NOT NULL,
  `razon_social` VARCHAR(200) NULL,
  `rfc` VARCHAR(30) NULL,
  `direccion` TEXT NULL,
  `encargado_sistemas` VARCHAR(150) NULL,
  `telefono_sistemas` VARCHAR(50) NULL,
  `correo_sistemas` VARCHAR(150) NULL,
  `logo_url` VARCHAR(255) NULL,
  `foto_url` VARCHAR(255) NULL,
  `cpanel` VARCHAR(150) NULL,
  `subdominio` VARCHAR(200) NULL,
  `local_api_token` VARCHAR(100) DEFAULT 'GH_SISTEMAS_TICKETS_2026!',
  `token` VARCHAR(100) DEFAULT 'GH_SISTEMAS_TICKETS_2026!',
  `maps_url` TEXT NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `agencia_areas` (Departamentos de la agencia)
CREATE TABLE `agencia_areas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `agencia_id` INT DEFAULT 1,
  `nombre` VARCHAR(100) NOT NULL,
  `estatus` INT DEFAULT 1,
  INDEX `idx_area_agencia` (`agencia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `usuarios` (Colaboradores y accesos)
CREATE TABLE `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(50) NOT NULL,
  `nombre` VARCHAR(100) NULL,
  `email` VARCHAR(100) NULL,
  `password` VARCHAR(255) NULL,
  `agencia` VARCHAR(100) NULL,
  `rol` VARCHAR(20) NULL,
  `activo` INT DEFAULT 1,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `acceso_portal` INT DEFAULT 1,
  `area` VARCHAR(100) NULL,
  `puesto` VARCHAR(100) NULL,
  `telefono` VARCHAR(50) NULL,
  `extension` VARCHAR(50) NULL,
  `contrasena_correo` VARCHAR(255) NULL,
  `ip_asignada` VARCHAR(50) NULL,
  `foto_url` VARCHAR(255) NULL,
  `areas_tickets` VARCHAR(255) DEFAULT 'TODOS',
  UNIQUE KEY `uq_usuarios_usuario` (`usuario`),
  INDEX `idx_usr_rol` (`rol`),
  INDEX `idx_usr_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `modulos` (Catálogo de módulos disponibles en el portal)
CREATE TABLE `modulos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clave` VARCHAR(50) NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `descripcion` TEXT NULL,
  `icono` VARCHAR(50) DEFAULT 'bi-app',
  `orden` INT DEFAULT 0,
  `estatus` INT DEFAULT 1,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_modulo_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `usuario_permisos` (Matriz de permisos granulares)
CREATE TABLE `usuario_permisos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NULL,
  `modulo_clave` VARCHAR(50) NOT NULL,
  `puede_ver` INT DEFAULT 1,
  `puede_crear` INT DEFAULT 1,
  `puede_editar` INT DEFAULT 1,
  `puede_eliminar` INT DEFAULT 1,
  `puede_exportar` INT DEFAULT 1,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_usuario_permiso` (`usuario_id`, `modulo_clave`),
  INDEX `idx_perm_usuario` (`usuario_id`),
  INDEX `idx_perm_modulo` (`modulo_clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. ESTRUCTURA DE TABLAS: TICKETS Y MESA DE AYUDA
-- =============================================================================

-- Tabla: `tickets_soporte`
CREATE TABLE `tickets_soporte` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `folio` VARCHAR(50) NOT NULL,
  `solicitante_id` INT NULL,
  `solicitante_nombre` VARCHAR(150) NOT NULL,
  `solicitante_usuario` VARCHAR(100) NOT NULL,
  `solicitante_agencia` VARCHAR(100) NOT NULL,
  `solicitante_ip` VARCHAR(50) NULL,
  `area_asignada` VARCHAR(100) NOT NULL,
  `titulo` VARCHAR(255) NOT NULL,
  `descripcion` TEXT NOT NULL,
  `prioridad` VARCHAR(20) DEFAULT 'Media',
  `estatus` VARCHAR(30) DEFAULT 'Abierto',
  `categoria` VARCHAR(100) NULL,
  `adjunto_url` VARCHAR(255) NULL,
  `responsable_nombre` VARCHAR(150) NULL,
  `responsable_usuario` VARCHAR(100) NULL,
  `fecha_cierre` DATETIME NULL,
  `motivo_cierre` TEXT NULL,
  `calificacion` INT NULL,
  `comentario_calificacion` TEXT NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ticket_folio` (`folio`),
  INDEX `idx_ticket_solic` (`solicitante_usuario`),
  INDEX `idx_ticket_area` (`area_asignada`),
  INDEX `idx_ticket_status` (`estatus`),
  INDEX `idx_ticket_fecha` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `tickets_mensajes`
CREATE TABLE `tickets_mensajes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `remitente_id` INT NULL,
  `remitente_nombre` VARCHAR(150) NOT NULL,
  `remitente_usuario` VARCHAR(100) NOT NULL,
  `remitente_rol` VARCHAR(50) NOT NULL,
  `mensaje` TEXT NOT NULL,
  `adjunto_url` VARCHAR(255) NULL,
  `es_interno` INT DEFAULT 0,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_msj_ticket` (`ticket_id`),
  INDEX `idx_msj_fecha` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `citas_soporte`
CREATE TABLE `citas_soporte` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `folio` VARCHAR(50) NOT NULL,
  `ticket_id` INT NULL,
  `solicitante_nombre` VARCHAR(150) NOT NULL,
  `solicitante_usuario` VARCHAR(100) NOT NULL,
  `solicitante_agencia` VARCHAR(100) NOT NULL,
  `tecnico_nombre` VARCHAR(150) NULL,
  `tecnico_usuario` VARCHAR(100) NULL,
  `asunto` VARCHAR(255) NOT NULL,
  `tipo_soporte` VARCHAR(100) DEFAULT 'Presencial en Sitio',
  `fecha_cita` DATE NOT NULL,
  `hora_inicio` TIME NOT NULL,
  `hora_fin` TIME NOT NULL,
  `estatus` VARCHAR(50) DEFAULT 'Programada',
  `notas` TEXT NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_cita_ticket` (`ticket_id`),
  INDEX `idx_cita_fecha` (`fecha_cita`),
  INDEX `idx_cita_status` (`estatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. ESTRUCTURA DE TABLAS: POLÍTICAS CORPORATIVAS Y MARCA DE AGUA FORENSE
-- =============================================================================

-- Tabla: `politicas_corporativas` (Normativas institucionales)
CREATE TABLE `politicas_corporativas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `titulo` VARCHAR(255) NOT NULL,
  `descripcion` TEXT NULL,
  `area` VARCHAR(100) NULL,
  `subarea` VARCHAR(100) NULL,
  `categoria` VARCHAR(100) DEFAULT 'General',
  `archivo_pdf` VARCHAR(255) NOT NULL,
  `version` VARCHAR(20) DEFAULT '1.0',
  `fecha_vigencia` DATE NULL,
  `obligatorio_lectura` TINYINT(1) DEFAULT 0,
  `estatus` TINYINT(1) DEFAULT 1,
  `creado_por` VARCHAR(100) NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_pol_area` (`area`),
  INDEX `idx_pol_subarea` (`subarea`),
  INDEX `idx_pol_estatus` (`estatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `politicas_lecturas` (Trazabilidad y auditoría forense de lectura)
CREATE TABLE `politicas_lecturas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `politica_id` INT NOT NULL,
  `politica_titulo` VARCHAR(255) NULL,
  `usuario_id` INT NULL,
  `usuario_nombre` VARCHAR(150) NOT NULL,
  `usuario_login` VARCHAR(100) NOT NULL,
  `agencia` VARCHAR(150) NOT NULL,
  `ip` VARCHAR(50) NOT NULL,
  `origen` VARCHAR(100) DEFAULT 'Portal Agencia',
  `fecha_lectura` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `fecha_fin` DATETIME NULL,
  `duracion_segundos` INT DEFAULT 0,
  INDEX `idx_pol_lec_id` (`politica_id`),
  INDEX `idx_pol_lec_user` (`usuario_login`),
  INDEX `idx_pol_lec_fecha` (`fecha_lectura`),
  INDEX `idx_pol_lec_agencia` (`agencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `politicas_terminos_aceptados` (Firma digital de Aviso de Privacidad y Términos)
CREATE TABLE `politicas_terminos_aceptados` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NULL,
  `usuario_nombre` VARCHAR(150) NOT NULL,
  `usuario_login` VARCHAR(100) NOT NULL,
  `agencia` VARCHAR(150) NOT NULL,
  `ip` VARCHAR(50) NOT NULL,
  `origen` VARCHAR(100) DEFAULT 'Portal Agencia',
  `version_terminos` VARCHAR(20) DEFAULT '1.0',
  `fecha_aceptacion` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `user_agent` TEXT NULL,
  INDEX `idx_terminos_login` (`usuario_login`),
  INDEX `idx_terminos_agencia` (`agencia`),
  INDEX `idx_terminos_fecha` (`fecha_aceptacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. ESTRUCTURA DE TABLAS: COMPLIANCE, NOTIFICACIONES Y DIRECTORIO
-- =============================================================================

-- Tabla: `compliance_documentos`
CREATE TABLE `compliance_documentos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `titulo` VARCHAR(255) NOT NULL,
  `descripcion` TEXT NULL,
  `tipo_documento` VARCHAR(100) DEFAULT 'Circular',
  `archivo_pdf` VARCHAR(255) NOT NULL,
  `version` VARCHAR(20) DEFAULT '1.0',
  `fecha_emision` DATE NULL,
  `obligatorio` TINYINT(1) DEFAULT 1,
  `estatus` TINYINT(1) DEFAULT 1,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `compliance_lecturas`
CREATE TABLE `compliance_lecturas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `documento_id` INT NOT NULL,
  `usuario_id` INT NOT NULL,
  `usuario_login` VARCHAR(100) NULL,
  `fecha_lectura` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `ip_acceso` VARCHAR(50) NULL,
  UNIQUE KEY `uq_comp_lec` (`documento_id`, `usuario_id`),
  INDEX `idx_comp_usr` (`usuario_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `notificaciones`
CREATE TABLE `notificaciones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `titulo` VARCHAR(200) NOT NULL,
  `mensaje` TEXT NOT NULL,
  `tipo` VARCHAR(50) DEFAULT 'informativa',
  `agencia_destino` VARCHAR(100) DEFAULT 'TODAS',
  `usuario_destino_id` INT NULL,
  `creado_por` VARCHAR(100) NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notif_agencia` (`agencia_destino`),
  INDEX `idx_notif_usr` (`usuario_destino_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `notificaciones_lecturas`
CREATE TABLE `notificaciones_lecturas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `notificacion_id` INT NOT NULL,
  `usuario_id` INT NOT NULL,
  `leido_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_notif_user` (`notificacion_id`, `usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `directorio_telefonico`
CREATE TABLE `directorio_telefonico` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `agencia_id` INT DEFAULT 1,
  `nombre` VARCHAR(150) NOT NULL,
  `departamento` VARCHAR(100) NULL,
  `puesto` VARCHAR(100) NULL,
  `extension` VARCHAR(20) NULL,
  `telefono_directo` VARCHAR(50) NULL,
  `celular` VARCHAR(50) NULL,
  `correo` VARCHAR(150) NULL,
  `ubicacion` VARCHAR(100) NULL,
  `orden` INT DEFAULT 0,
  `estatus` INT DEFAULT 1,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_dir_agencia` (`agencia_id`),
  INDEX `idx_dir_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. ESTRUCTURA DE TABLAS: INFRAESTRUCTURA, PLANOS 3D/2D Y RACKS
-- =============================================================================

-- Tabla: `infra_site`
CREATE TABLE `infra_site` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre_site` VARCHAR(150) NOT NULL,
  `ubicacion` VARCHAR(200) NULL,
  `tipo_espacio` VARCHAR(100) DEFAULT 'SITE Principal',
  `temperatura_objetivo` VARCHAR(100) NULL,
  `aire_acondicionado` VARCHAR(150) NULL,
  `btu` VARCHAR(50) NULL,
  `aire_marca` VARCHAR(100) NULL,
  `aire_modelo` VARCHAR(100) NULL,
  `puerta_acceso` VARCHAR(100) NULL,
  `tipo_cerradura` VARCHAR(100) NULL,
  `camaras_monitoreo` VARCHAR(150) NULL,
  `detector_humo` VARCHAR(100) NULL,
  `tipo_extintor` VARCHAR(100) NULL,
  `capacidad_extintor` VARCHAR(100) NULL,
  `fecha_recarga_extintor` VARCHAR(100) NULL,
  `canaletas` VARCHAR(150) NULL,
  `rack_marca` VARCHAR(100) NULL,
  `rack_unidades` VARCHAR(50) NULL,
  `pdu` VARCHAR(100) NULL,
  `piso_falso` VARCHAR(100) NULL,
  `bitacora_notas` TEXT NULL,
  `foto_panoramica` VARCHAR(255) NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `infra_red_idf`
CREATE TABLE `infra_red_idf` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_idf` VARCHAR(100) NOT NULL,
  `nombre_idf` VARCHAR(150) NULL,
  `ubicacion` VARCHAR(200) NULL,
  `tipo_rack` VARCHAR(100) NULL,
  `unidades_rack` VARCHAR(50) NULL,
  `switches_instalados` TEXT NULL,
  `patch_panels` TEXT NULL,
  `puertos_totales` INT DEFAULT 0,
  `puertos_ocupados` INT DEFAULT 0,
  `puertos_disponibles` INT DEFAULT 0,
  `ups_modelo` VARCHAR(150) NULL,
  `ups_capacidad` VARCHAR(100) NULL,
  `fibra_tipo` VARCHAR(100) NULL,
  `fibra_hilos` VARCHAR(50) NULL,
  `cerradura` VARCHAR(100) NULL,
  `extintor_cercano` VARCHAR(100) NULL,
  `notas` TEXT NULL,
  `foto_idf` VARCHAR(255) NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `infra_planos_2d` (Soporta planos interactivos 2D y 3D en LONGTEXT)
CREATE TABLE `infra_planos_2d` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre_plano` VARCHAR(150) NOT NULL,
  `imagen_fondo_url` VARCHAR(255) NULL,
  `ancho_canvas` INT DEFAULT 1200,
  `alto_canvas` INT DEFAULT 800,
  `elementos_json` LONGTEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `infra_nodos`
CREATE TABLE `infra_nodos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_nodo` VARCHAR(100) NOT NULL,
  `plano_id` INT NULL,
  `tipo_nodo` VARCHAR(50) DEFAULT 'Datos',
  `area` VARCHAR(100) NULL,
  `ubicacion_exacta` VARCHAR(200) NULL,
  `origen_tipo` VARCHAR(50) NULL,
  `origen_id` INT NULL,
  `switch_nombre` VARCHAR(150) NULL,
  `puerto_switch` VARCHAR(50) NULL,
  `vlan` VARCHAR(50) NULL,
  `ip_asignada` VARCHAR(50) NULL,
  `equipo_conectado` VARCHAR(200) NULL,
  `usuario_responsable` VARCHAR(150) NULL,
  `categoria_cable` VARCHAR(50) DEFAULT 'Cat 6',
  `color_cable` VARCHAR(50) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Activo',
  `pos_x` DECIMAL(10,2) DEFAULT 0.00,
  `pos_y` DECIMAL(10,2) DEFAULT 0.00,
  `notas` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_nodo_plano` (`plano_id`),
  INDEX `idx_nodo_cod` (`codigo_nodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `infra_vlans`
CREATE TABLE `infra_vlans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_vlan` INT NOT NULL,
  `nombre_vlan` VARCHAR(100) NOT NULL,
  `subred` VARCHAR(100) NULL,
  `mascara` VARCHAR(50) NULL,
  `gateway` VARCHAR(50) NULL,
  `rango_dhcp` VARCHAR(150) NULL,
  `dns_primario` VARCHAR(50) NULL,
  `dns_secundario` VARCHAR(50) NULL,
  `descripcion` TEXT NULL,
  `estatus` VARCHAR(50) DEFAULT 'Activa',
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_vlan_id` (`id_vlan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `infra_switch_puertos`
CREATE TABLE `infra_switch_puertos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `switch_id` INT NULL,
  `numero_puerto` INT NOT NULL,
  `nombre_interfaz` VARCHAR(50) NULL,
  `tipo_puerto` VARCHAR(50) DEFAULT '1Gbps',
  `vlan_id` INT NULL,
  `modo` VARCHAR(50) DEFAULT 'Access',
  `poe` VARCHAR(20) DEFAULT 'No',
  `nodo_conectado` VARCHAR(100) NULL,
  `dispositivo_conectado` VARCHAR(200) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Libre',
  `descripcion` TEXT NULL,
  INDEX `idx_sw_puerto` (`switch_id`, `numero_puerto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `infra_diagramas`
CREATE TABLE `infra_diagramas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `titulo` VARCHAR(200) NOT NULL,
  `tipo_diagrama` VARCHAR(100) DEFAULT 'Red Lógica',
  `archivo_url` VARCHAR(255) NOT NULL,
  `version` VARCHAR(50) DEFAULT '1.0',
  `descripcion` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. ESTRUCTURA DE TABLAS: INVENTARIOS DE TECNOLOGÍA
-- =============================================================================

-- Tabla: `inv_equipos_vw` (Inventario de equipos de cómputo)
CREATE TABLE `inv_equipos_vw` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `serie` VARCHAR(100) NULL,
  `tipo_equipo` VARCHAR(100) NOT NULL,
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(150) NULL,
  `procesador` VARCHAR(150) NULL,
  `memoria_ram` VARCHAR(50) NULL,
  `almacenamiento` VARCHAR(150) NULL,
  `sistema_operativo` VARCHAR(150) NULL,
  `nombre_equipo` VARCHAR(100) NULL,
  `ip_asignada` VARCHAR(50) NULL,
  `mac_ethernet` VARCHAR(50) NULL,
  `mac_wifi` VARCHAR(50) NULL,
  `usuario_responsable` VARCHAR(150) NULL,
  `area` VARCHAR(100) NULL,
  `puesto` VARCHAR(100) NULL,
  `antivirus` VARCHAR(100) NULL,
  `office_licencia` VARCHAR(150) NULL,
  `anydesk_id` VARCHAR(50) NULL,
  `monitor_serie` VARCHAR(100) NULL,
  `teclado_mouse` VARCHAR(100) NULL,
  `ups_serie` VARCHAR(100) NULL,
  `nodo_red` VARCHAR(100) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Operativo',
  `fecha_compra` DATE NULL,
  `garantia_vence` DATE NULL,
  `notas` TEXT NULL,
  `foto_url` VARCHAR(255) NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_eq_usuario` (`usuario_responsable`),
  INDEX `idx_eq_area` (`area`),
  INDEX `idx_eq_status` (`estatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_dispositivos_moviles` (Celulares y tablets)
CREATE TABLE `inv_dispositivos_moviles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `marca` VARCHAR(100) NOT NULL,
  `modelo` VARCHAR(150) NOT NULL,
  `color` VARCHAR(50) NULL,
  `imei` VARCHAR(50) NULL,
  `imei2` VARCHAR(50) NULL,
  `numero_telefonico` VARCHAR(50) NULL,
  `cuenta_correo_configurada` VARCHAR(150) NULL,
  `password_dispositivo` VARCHAR(100) NULL,
  `cuenta_gmail_icloud` VARCHAR(150) NULL,
  `password_cuenta` VARCHAR(100) NULL,
  `plan_tarifario` VARCHAR(100) NULL,
  `renta_mensual` DECIMAL(10,2) DEFAULT 0.00,
  `plazo_forzoso_vence` DATE NULL,
  `usuario_responsable` VARCHAR(150) NULL,
  `area` VARCHAR(100) NULL,
  `puesto` VARCHAR(100) NULL,
  `carta_responsiva_url` VARCHAR(255) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Asignado',
  `accesorios_entregados` TEXT NULL,
  `notas` TEXT NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_cel_usuario` (`usuario_responsable`),
  INDEX `idx_cel_numero` (`numero_telefonico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_site_vw` (Equipos de infraestructura en SITE)
CREATE TABLE `inv_site_vw` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `nombre_dispositivo` VARCHAR(150) NOT NULL,
  `tipo_dispositivo` VARCHAR(100) NOT NULL,
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(150) NULL,
  `serie` VARCHAR(100) NULL,
  `ip_gestion` VARCHAR(50) NULL,
  `mac_address` VARCHAR(50) NULL,
  `rack_ubicacion` VARCHAR(100) NULL,
  `unidad_rack_u` VARCHAR(50) NULL,
  `puertos_totales` INT DEFAULT 0,
  `alimentacion_ups` VARCHAR(100) NULL,
  `firmware_version` VARCHAR(100) NULL,
  `login_usuario` VARCHAR(100) NULL,
  `estatus` VARCHAR(50) DEFAULT 'En Producción',
  `notas` TEXT NULL,
  `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_monitores`
CREATE TABLE `inv_monitores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `marca` VARCHAR(100) NOT NULL,
  `modelo` VARCHAR(150) NULL,
  `serie` VARCHAR(100) NULL,
  `pulgadas` VARCHAR(50) NULL,
  `tipo_pantalla` VARCHAR(100) NULL,
  `entradas_video` VARCHAR(150) NULL,
  `usuario_responsable` VARCHAR(150) NULL,
  `area` VARCHAR(100) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Asignado',
  `notas` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_telefonos_poe`
CREATE TABLE `inv_telefonos_poe` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `marca` VARCHAR(100) NOT NULL,
  `modelo` VARCHAR(150) NULL,
  `serie` VARCHAR(100) NULL,
  `mac_address` VARCHAR(50) NULL,
  `extension` VARCHAR(50) NULL,
  `ip_asignada` VARCHAR(50) NULL,
  `usuario_responsable` VARCHAR(150) NULL,
  `area` VARCHAR(100) NULL,
  `nodo_red` VARCHAR(100) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Operativo',
  `notas` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_dvr_camaras`
CREATE TABLE `inv_dvr_camaras` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `nombre_equipo` VARCHAR(150) NOT NULL,
  `tipo` VARCHAR(100) DEFAULT 'DVR / NVR',
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(150) NULL,
  `serie` VARCHAR(100) NULL,
  `ip_acceso` VARCHAR(50) NULL,
  `puerto_web` VARCHAR(50) NULL,
  `canales_totales` INT DEFAULT 16,
  `canales_ocupados` INT DEFAULT 0,
  `capacidad_disco` VARCHAR(100) NULL,
  `dias_grabacion` INT DEFAULT 30,
  `ubicacion_fisica` VARCHAR(200) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Grabando',
  `notas` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_licencias_office`
CREATE TABLE `inv_licencias_office` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cuenta_correo` VARCHAR(150) NOT NULL,
  `tipo_licencia` VARCHAR(100) NOT NULL,
  `clave_producto` VARCHAR(150) NULL,
  `usuario_asignado` VARCHAR(150) NULL,
  `area` VARCHAR(100) NULL,
  `fecha_asignacion` DATE NULL,
  `fecha_vencimiento` DATE NULL,
  `estatus` VARCHAR(50) DEFAULT 'Activa',
  `costo_anual` DECIMAL(10,2) DEFAULT 0.00,
  `notas` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_equipos_corp`
CREATE TABLE `inv_equipos_corp` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `tipo_equipo` VARCHAR(100) NOT NULL,
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(150) NULL,
  `serie` VARCHAR(100) NULL,
  `usuario_responsable` VARCHAR(150) NULL,
  `area` VARCHAR(100) NULL,
  `estatus` VARCHAR(50) DEFAULT 'Operativo',
  `notas` TEXT NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_equipos_baja`
CREATE TABLE `inv_equipos_baja` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `tipo_equipo` VARCHAR(100) NOT NULL,
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(150) NULL,
  `serie` VARCHAR(100) NULL,
  `ultimo_usuario` VARCHAR(150) NULL,
  `area` VARCHAR(100) NULL,
  `motivo_baja` TEXT NOT NULL,
  `fecha_baja` DATE NOT NULL,
  `dictamen_tecnico` TEXT NULL,
  `acta_baja_url` VARCHAR(255) NULL,
  `registrado_por` VARCHAR(100) NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_nobreak_baja`
CREATE TABLE `inv_nobreak_baja` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_activo` VARCHAR(100) NULL,
  `marca` VARCHAR(100) NOT NULL,
  `modelo` VARCHAR(150) NULL,
  `serie` VARCHAR(100) NULL,
  `capacidad_va` VARCHAR(50) NULL,
  `motivo_baja` TEXT NOT NULL,
  `fecha_baja` DATE NOT NULL,
  `registrado_por` VARCHAR(100) NULL,
  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `inv_archivo`
CREATE TABLE `inv_archivo` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `modulo_origen` VARCHAR(100) NOT NULL,
  `elemento_id` INT NULL,
  `nombre_archivo` VARCHAR(255) NOT NULL,
  `ruta_archivo` VARCHAR(255) NOT NULL,
  `tipo_mime` VARCHAR(100) NULL,
  `peso_bytes` INT DEFAULT 0,
  `subido_por` VARCHAR(100) NULL,
  `subido_en` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. ESTRUCTURA DE TABLAS: REPORTES Y SINCRONIZACIÓN DE AGENCIA
-- =============================================================================

-- Tabla: `reportes_agencia_datos`
CREATE TABLE `reportes_agencia_datos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tipo_reporte` VARCHAR(100) NOT NULL,
  `folio_referencia` VARCHAR(100) NULL,
  `titulo` VARCHAR(255) NOT NULL,
  `datos_json` LONGTEXT NULL,
  `resumen` TEXT NULL,
  `monto` DECIMAL(14,2) DEFAULT 0.00,
  `fecha_documento` DATE NULL,
  `estatus` VARCHAR(50) DEFAULT 'Activo',
  `sincronizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_rep_tipo` (`tipo_reporte`),
  INDEX `idx_rep_folio` (`folio_referencia`),
  INDEX `idx_rep_fecha` (`fecha_documento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `reportes_inventario_autos`
CREATE TABLE `reportes_inventario_autos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cve_almacen` VARCHAR(50) NULL,
  `nombre_almacen` VARCHAR(150) NULL,
  `inventario` VARCHAR(50) NULL,
  `descripcion` VARCHAR(255) NULL,
  `chasis` VARCHAR(100) NULL,
  `color` VARCHAR(50) NULL,
  `motor` VARCHAR(100) NULL,
  `equipamiento` TEXT NULL,
  `marca` VARCHAR(50) NULL,
  `anio` VARCHAR(20) NULL,
  `status` VARCHAR(50) DEFAULT 'Disponible',
  `precio_venta` DECIMAL(14,2) DEFAULT 0.00,
  `costo_inventario` DECIMAL(14,2) DEFAULT 0.00,
  `importe_inventario` DECIMAL(14,2) DEFAULT 0.00,
  `fecha_alta` DATETIME NULL,
  `datos_json` LONGTEXT NULL,
  `sincronizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_chasis` (`chasis`),
  INDEX `idx_inv_alm` (`cve_almacen`),
  INDEX `idx_inv_marca` (`marca`),
  INDEX `idx_inv_anio` (`anio`),
  INDEX `idx_inv_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla: `reportes_ordenes_servicio`
CREATE TABLE `reportes_ordenes_servicio` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `almacen` VARCHAR(50) NULL,
  `no_orden` VARCHAR(50) NOT NULL,
  `cve_estatus` VARCHAR(20) NULL,
  `descripcion_estatus` VARCHAR(100) NULL,
  `cve_tipo_orden` VARCHAR(20) NULL,
  `tipo_orden` VARCHAR(100) NULL,
  `tipo_pago` VARCHAR(50) NULL,
  `no_factura` VARCHAR(50) NULL,
  `fecha_alta` DATETIME NULL,
  `hora_inicio` VARCHAR(20) NULL,
  `fecha_promesa` DATETIME NULL,
  `fecha_entrega` DATETIME NULL,
  `hora_fin` VARCHAR(20) NULL,
  `num_cliente` VARCHAR(50) NULL,
  `cliente` VARCHAR(255) NULL,
  `rfc` VARCHAR(30) NULL,
  `telefono` VARCHAR(50) NULL,
  `vin_chasis` VARCHAR(100) NULL,
  `placas` VARCHAR(50) NULL,
  `modelo` VARCHAR(150) NULL,
  `ano` VARCHAR(20) NULL,
  `color` VARCHAR(50) NULL,
  `kilometraje` VARCHAR(50) NULL,
  `cve_asesor` VARCHAR(50) NULL,
  `nombre_asesor` VARCHAR(200) NULL,
  `usuario_registro` VARCHAR(100) NULL,
  `dias_abierta` INT DEFAULT 0,
  `estatus_tiempo` VARCHAR(50) NULL,
  `datos_json` LONGTEXT NULL,
  `sincronizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_no_orden` (`no_orden`),
  INDEX `idx_ord_alm` (`almacen`),
  INDEX `idx_ord_status` (`cve_estatus`),
  INDEX `idx_ord_tipo` (`cve_tipo_orden`),
  INDEX `idx_ord_fecha` (`fecha_alta`),
  INDEX `idx_ord_asesor` (`cve_asesor`),
  INDEX `idx_ord_dias` (`dias_abierta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. DATOS SEMILLA (INICIALIZACIÓN IMPRESCINDIBLE PARA EL SISTEMA)
-- Solo se inserta la agencia base, áreas clave, módulos y usuarios administradores
-- Todos los inventarios, tickets, políticas y bitácoras quedan 100% LIMPIOS.
-- =============================================================================

-- A. Registro de la Agencia inicial (ID = 1)
INSERT INTO `agencias` (`id`, `nombre`, `razon_social`, `rfc`, `direccion`, `encargado_sistemas`, `telefono_sistemas`, `correo_sistemas`, `logo_url`, `foto_url`, `cpanel`, `subdominio`, `local_api_token`, `token`, `maps_url`) VALUES
(1, 'Nueva Agencia', 'Razón Social de la Agencia S.A. de C.V.', 'XAXX010101000', 'Dirección de la nueva sucursal...', 'Encargado de Sistemas', '55 0000 0000', 'sistemas@agencia.com', '', '', 'cpanel.nuevaagencia.com', 'https://portal.nuevaagencia.com', 'GH_SISTEMAS_TICKETS_2026!', 'GH_SISTEMAS_TICKETS_2026!', '');

-- B. Áreas iniciales de la Agencia
INSERT INTO `agencia_areas` (`id`, `agencia_id`, `nombre`, `estatus`) VALUES
(1, 1, 'Administración', 1),
(2, 1, 'Sistemas', 1),
(3, 1, 'Ventas', 1),
(4, 1, 'Servicio', 1),
(5, 1, 'Refacciones', 1);

-- C. Catálogo oficial de Módulos del Portal
INSERT INTO `modulos` (`id`, `clave`, `nombre`, `descripcion`, `icono`, `orden`, `estatus`, `creado_en`) VALUES
(1, 'agencia', 'Datos de la Agencia', 'Ficha oficial de la sucursal: Nombre, Razón Social, RFC, Dirección, Foto y Encargado de Sistemas', 'bi-building-fill', 1, 1, NOW()),
(2, 'usuarios', 'Gestión de Usuarios', 'Administración de usuarios, roles y permisos de acceso', 'bi-people-fill', 2, 1, NOW()),
(3, 'tickets', 'Tickets Soporte Dirección Sistemas', 'Recepción y seguimiento de tickets para las áreas de Sistemas', 'bi-ticket-detailed-fill', 3, 1, NOW()),
(4, 'equipos', 'Inventario de Equipos', 'Control de PCs, laptops, servidores e impresoras', 'bi-display-fill', 4, 1, NOW()),
(5, 'celulares', 'Inventario de Celulares', 'Control de equipos móviles y líneas celulares', 'bi-phone-fill', 5, 1, NOW()),
(6, 'licencias', 'Licencias de Software', 'Matriz de licenciamiento corporativo y vencimientos', 'bi-key-fill', 6, 1, NOW()),
(7, 'infraestructura', 'Infraestructura (SITE / IDF)', 'Control de racks, cableado estructurado, IDF y planos', 'bi-hdd-rack-fill', 7, 1, NOW()),
(8, 'directorio', 'Directorio de Personal', 'Directorio telefónico y correos institucionales de la agencia', 'bi-person-lines-fill', 8, 1, NOW()),
(9, 'ordenes_servicio', 'Órdenes de Servicio', 'Resumen de órdenes de servicio automotriz y reportes', 'bi-file-earmark-bar-graph', 9, 1, NOW()),
(10, 'politicas', 'Políticas Corporativas', 'Políticas institucionales, reglamentos y normativas en visor blindado', 'bi-shield-shaded', 10, 1, NOW()),
(11, 'compliance', 'Cumplimiento y Avisos', 'Avisos oficiales y circulares institucionales de compliance', 'bi-award-fill', 11, 1, NOW()),
(12, 'reportes_agencia', 'Reportes Ejecutivos', 'Métricas consolidadas de inventarios y sistemas de la agencia', 'bi-graph-up-arrow', 12, 1, NOW());

-- D. Usuarios Administradores Iniciales
-- Contraseña por defecto para ambos usuarios: Admin123!
INSERT INTO `usuarios` (`id`, `usuario`, `nombre`, `email`, `password`, `agencia`, `rol`, `activo`, `acceso_portal`, `area`, `puesto`, `areas_tickets`) VALUES
(1, 'admin', 'Administrador de Sucursal', 'admin@agencia.com', '$2y$10$e7mEXpv7UHRZMZDStyia1ud3XDidSdBSt8J1ynnGu9ZBWSH0bA0le', 'Nueva Agencia', 'admin', 1, 1, 'Sistemas', 'Encargado de Sistemas', 'TODOS'),
(2, 'superadmin', 'Super Administrador', 'superadmin@grupohuerta.mx', '$2y$10$e7mEXpv7UHRZMZDStyia1ud3XDidSdBSt8J1ynnGu9ZBWSH0bA0le', 'Nueva Agencia', 'superadmin', 1, 1, 'Sistemas', 'Dirección de Sistemas', 'TODOS');

-- E. Permisos Completos para el Administrador (Todos los módulos con acceso total)
INSERT INTO `usuario_permisos` (`usuario_id`, `modulo_clave`, `puede_ver`, `puede_crear`, `puede_editar`, `puede_eliminar`, `puede_exportar`) VALUES
(1, 'agencia', 1, 1, 1, 1, 1),
(1, 'usuarios', 1, 1, 1, 1, 1),
(1, 'tickets', 1, 1, 1, 1, 1),
(1, 'equipos', 1, 1, 1, 1, 1),
(1, 'celulares', 1, 1, 1, 1, 1),
(1, 'licencias', 1, 1, 1, 1, 1),
(1, 'infraestructura', 1, 1, 1, 1, 1),
(1, 'directorio', 1, 1, 1, 1, 1),
(1, 'ordenes_servicio', 1, 1, 1, 1, 1),
(1, 'politicas', 1, 1, 1, 1, 1),
(1, 'compliance', 1, 1, 1, 1, 1),
(1, 'reportes_agencia', 1, 1, 1, 1, 1);

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- FIN DEL SCRIPT: BASE DE DATOS LIMPIA GENERADA CON ÉXITO
-- =============================================================================
