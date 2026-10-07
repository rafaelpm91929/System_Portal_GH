<?php
// Helper de Permisos y Control de Acceso Granular (RBAC)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Auto-Instala o asegura la existencia de las tablas de permisos dinámicos en MySQL
 */
function asegurarTablasPermisos($pdo) {
    if (!$pdo) return;

    try {
        // 1. Tabla usuarios
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS `usuarios` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `usuario` VARCHAR(50) NOT NULL UNIQUE,
          `nombre` VARCHAR(100) NOT NULL,
          `email` VARCHAR(100) NOT NULL UNIQUE,
          `password` VARCHAR(255) NOT NULL,
          `agencia` VARCHAR(100) DEFAULT 'Agencia Grupo Huerta',
          `area` VARCHAR(100),
          `puesto` VARCHAR(100),
          `telefono` VARCHAR(50),
          `foto_url` VARCHAR(255),
          `rol` ENUM('SuperAdmin', 'Admin', 'Usuario') DEFAULT 'Admin',
          `activo` TINYINT(1) DEFAULT 1,
          `acceso_portal` TINYINT(1) DEFAULT 1,
          `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Migración automática para agregar nuevas columnas si no existen
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN acceso_portal TINYINT(1) DEFAULT 1;");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN area VARCHAR(100);");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN puesto VARCHAR(100);");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(50);");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_url VARCHAR(255);");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN contrasena_correo VARCHAR(255);");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN extension VARCHAR(50);");
        } catch (Throwable $e) {}

        // 2. Tabla modulos
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS `modulos` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `clave` VARCHAR(50) NOT NULL UNIQUE,
          `nombre` VARCHAR(100) NOT NULL,
          `descripcion` TEXT,
          `icono` VARCHAR(50) DEFAULT 'bi-app-indicator',
          `orden` INT DEFAULT 0,
          `estatus` TINYINT(1) DEFAULT 1,
          `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. Tabla usuario_permisos
        $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. Tabla agencias
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS `agencias` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `nombre` VARCHAR(100) NOT NULL UNIQUE,
          `razon_social` VARCHAR(150),
          `rfc` VARCHAR(20),
          `direccion` TEXT,
          `encargado_sistemas` VARCHAR(100),
          `telefono_sistemas` VARCHAR(50),
          `correo_sistemas` VARCHAR(100),
          `logo_url` VARCHAR(255),
          `foto_url` VARCHAR(255),
          `maps_url` TEXT,
          `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        try {
            $pdo->exec("ALTER TABLE agencias ADD COLUMN maps_url TEXT;");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE agencias ADD COLUMN color_tema VARCHAR(50) DEFAULT 'azul';");
        } catch (Throwable $e) {}

        // 5. Tabla agencia_areas
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS `agencia_areas` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `agencia_id` INT NOT NULL,
          `nombre` VARCHAR(100) NOT NULL,
          `estatus` TINYINT(1) DEFAULT 1,
          `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uq_agencia_area` (`agencia_id`, `nombre`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. Sembrado Inicial
        $pdo->exec("
        INSERT INTO `modulos` (`clave`, `nombre`, `descripcion`, `icono`, `orden`, `estatus`) VALUES
        ('agencia', 'Datos de la Agencia', 'Ficha oficial de la sucursal: Nombre, Razón Social, RFC, Dirección, Foto y Encargado de Sistemas', 'bi-building-fill', 0, 1),
        ('usuarios', 'Gestión de Usuarios', 'Administración de usuarios, roles y permisos del portal', 'bi-people-fill', 1, 1),
        ('ordenes_servicio', 'Órdenes de Servicio', 'Resumen de órdenes abiertas, cerradas y montos acumulados', 'bi-file-earmark-bar-graph', 2, 1),
        ('equipos', 'Inventario de Equipos', 'Control de PCs, laptops, servidores e impresoras', 'bi-display-fill', 3, 1),
        ('celulares', 'Inventario de Celulares', 'Control de equipos móviles y líneas corporativas', 'bi-phone-fill', 4, 1),
        ('licencias', 'Licencias de Software', 'Matriz de licenciamiento corporativo y vencimientos', 'bi-key-fill', 5, 1),
        ('infraestructura', 'Infraestructura (SITE / IDF)', 'Control de racks, switches y cableado estructurado', 'bi-hdd-rack-fill', 6, 1),
        ('directorio', 'Directorio de Personal', 'Directorio telefónico y correos institucionales de la agencia', 'bi-person-lines-fill', 7, 1),
        ('tickets', 'Tickets Soporte Dirección Sistemas', 'Recepción y seguimiento de tickets para las áreas de Sistemas', 'bi-ticket-detailed-fill', 8, 1),
        ('politicas', 'Políticas Corporativas', 'Políticas institucionales, reglamentos y normativas de seguridad en visor blindado', 'bi-shield-shaded', 9, 1),
        ('compliance', 'Compliance', 'Formatos oficiales, manuales de procedimientos y avisos institucionales', 'bi-shield-check', 10, 1)
        ON DUPLICATE KEY UPDATE `nombre`=VALUES(`nombre`), `icono`=VALUES(`icono`), `orden`=VALUES(`orden`), `estatus`=VALUES(`estatus`);");

    } catch (Throwable $e) {
        // Evitar interrumpir flujo si falla parcialmente
    }
}

/**
 * Auto-Instala o asegura la existencia de las 10 tablas de inventario en MySQL (cPanel) o SQLite (Local)
 */
function asegurarTablasEquipos($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $driver = '';
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $drvErr) {}

        if ($driver === 'sqlite') {
            // Tablas SQLite Directas para Entorno Local
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS inv_equipos_vw (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, usuario TEXT, nombre_equipo TEXT,
                    estado TEXT DEFAULT 'Activo', tipo_equipo TEXT DEFAULT 'Desktop', expediente_completo TEXT, dominio TEXT,
                    logmein TEXT, serie TEXT, dd TEXT, procesador TEXT, ghz TEXT, ram TEXT, direccion_mac TEXT, mac_wifi TEXT,
                    mac_ethernet TEXT, sistema_op TEXT, fecha_compra DATE, folio_factura TEXT, inicio_garantia DATE,
                    fin_garantia DATE, garantia2 TEXT, renovacion_equipo TEXT, usuario_equipo_dominio TEXT, contrasena TEXT,
                    office TEXT, serie_office TEXT, clave_candado TEXT, gds TEXT, por_usuario TEXT, equipo TEXT, remoto TEXT,
                    usuario_gds TEXT, correo TEXT, correo_oficial_vw TEXT, correo_oficial_seat TEXT, extension TEXT,
                    power_pb TEXT, contrasena_pb TEXT, poc TEXT, contrasena_poc TEXT, msqp TEXT, contrasena_msqp TEXT,
                    grp TEXT, contrasena_grp TEXT, etka TEXT, contrasena_etka TEXT, facebook TEXT, contrasena_facebook TEXT,
                    instagram TEXT, contrasena_instagram TEXT, wish TEXT, contrasena_wish TEXT, marketing_cloud TEXT,
                    contrasena_marketing_cloud TEXT, sales_cloud_force TEXT, contrasena_sales_cloud_force TEXT,
                    pagina_la_villa TEXT, contrasena_pagina_la_villa TEXT, urban_science TEXT, contrasena_urban_science TEXT,
                    canva TEXT, contrasena_canva TEXT, cuenta_integral TEXT, contrasena_cuenta_integral TEXT, compra TEXT,
                    proveedor TEXT, antivirus TEXT, dia_respaldo TEXT, hora_respaldo TEXT, no_break TEXT, modelo_nobreak TEXT,
                    serie_nobreak TEXT, columna1 TEXT, columna2 TEXT, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_equipos_baja (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, usuario TEXT, nombre_equipo TEXT,
                    estado TEXT DEFAULT 'Baja', tipo_equipo TEXT, dominio TEXT, logmein TEXT, serie TEXT, dd TEXT,
                    procesador TEXT, ghz TEXT, ram TEXT, direccion_mac TEXT, mac_wifi TEXT, mac_ethernet TEXT,
                    sistema_op TEXT, fecha_compra DATE, folio_factura TEXT, inicio_garantia DATE, fin_garantia DATE,
                    garantia TEXT, renovacion_equipo TEXT, usuario_equipo TEXT, contrasena TEXT, office TEXT,
                    serie_office TEXT, clave_candado TEXT, gds TEXT, correo TEXT, correo_oficial_vw TEXT,
                    correo_oficial_seat TEXT, extension TEXT, compra TEXT, proveedor TEXT, antivirus TEXT,
                    dia_respaldo TEXT, hora_respaldo TEXT, no_break TEXT, modelo_nobreak TEXT, serie_nobreak TEXT,
                    expediente_completo TEXT, columna1 TEXT, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_nobreak_baja (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, usuario TEXT, nombre_equipo TEXT,
                    estado TEXT DEFAULT 'Baja', modelo TEXT, serie_equipo TEXT, modelo_nobreak TEXT, serie_nobreak TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_equipos_corp (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, usuario TEXT, nombre_equipo TEXT,
                    estado TEXT DEFAULT 'Activo', tipo_equipo TEXT, dominio TEXT, logmein TEXT, serie TEXT, dd TEXT,
                    procesador TEXT, ghz TEXT, ram TEXT, direccion_mac TEXT, mac_wifi TEXT, mac_ethernet TEXT,
                    sistema_op TEXT, fecha_compra DATE, folio_factura TEXT, inicio_garantia DATE, fin_garantia DATE,
                    garantia TEXT, renovacion_equipo TEXT, usuario_equipo TEXT, contrasena TEXT, office TEXT,
                    serie_office TEXT, clave_candado TEXT, gds TEXT, correo TEXT, correo_oficial_vw TEXT,
                    correo_oficial_seat TEXT, extension TEXT, compra TEXT, proveedor TEXT, antivirus TEXT,
                    dia_respaldo TEXT, hora_respaldo TEXT, no_break TEXT, modelo_nobreak TEXT, serie_nobreak TEXT,
                    expediente_completo TEXT, columna1 TEXT, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_dispositivos_moviles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, nombre TEXT, tablet TEXT,
                    celular TEXT, pantalla TEXT, contrasena TEXT, serie TEXT, rom TEXT, ram TEXT, android TEXT,
                    mail TEXT, mac TEXT, plan TEXT, factura TEXT, fecha_compra DATE, proveedor TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_archivo (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, nombre TEXT, monitor TEXT,
                    pulgadas TEXT, serie TEXT, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_monitores (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, nombre TEXT, monitor TEXT,
                    pulgadas TEXT, serie TEXT, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_dvr_camaras (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, camaras TEXT, dvr TEXT, ip TEXT, numero_serie TEXT,
                    almacenamiento TEXT, contrasena_dvr TEXT, contrasena_camara TEXT, cam_totales_ip INTEGER DEFAULT 0,
                    disponibles_ip INTEGER DEFAULT 0, cam_totales_analogicas INTEGER DEFAULT 0, disponibles INTEGER DEFAULT 0,
                    modelo TEXT, nombre TEXT, capacidad_actual TEXT, cotizar TEXT, maximo_por_disco TEXT,
                    capacidad_discos TEXT, tiene_actual TEXT, numero_discos TEXT, usuario_dvr TEXT, dias_grabacion TEXT,
                    tipo_registro TEXT, dvr_vinculado TEXT, costo TEXT, foto_equipo TEXT, columna1 TEXT, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_site_vw (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, departamento TEXT, puesto TEXT, usuario TEXT, nombre_equipo TEXT,
                    estado TEXT DEFAULT 'Activo', equipo TEXT, modelo TEXT, serie TEXT, fecha_compra DATE,
                    folio_factura TEXT, actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_licencias_office (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, licencia TEXT, area TEXT, puesto TEXT, nombre TEXT,
                    serie_equipo TEXT, nombre_equipo TEXT, serie_licencia TEXT, factura TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS inv_telefonos_poe (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    usuario TEXT, area TEXT, modelo TEXT, serie TEXT, mac TEXT, ip TEXT,
                    numero_telefonico TEXT, extension TEXT, tipo_licencia TEXT, correo TEXT, portabilidad TEXT,
                    folio_factura TEXT, numero_nodo TEXT, puerto_sw TEXT, switch_nombre TEXT,
                    foto TEXT, factura_pdf TEXT, estado TEXT DEFAULT 'Activo',
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            // MySQL cPanel execution
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `inv_telefonos_poe` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `usuario` VARCHAR(150),
                  `area` VARCHAR(100),
                  `modelo` VARCHAR(100),
                  `serie` VARCHAR(100),
                  `mac` VARCHAR(50),
                  `ip` VARCHAR(50),
                  `numero_telefonico` VARCHAR(50),
                  `extension` VARCHAR(20),
                  `tipo_licencia` VARCHAR(100),
                  `correo` VARCHAR(150),
                  `portabilidad` VARCHAR(50),
                  `folio_factura` VARCHAR(100),
                  `numero_nodo` VARCHAR(50),
                  `puerto_sw` VARCHAR(50),
                  `switch_nombre` VARCHAR(100),
                  `foto` VARCHAR(255),
                  `factura_pdf` VARCHAR(255),
                  `estado` VARCHAR(50) DEFAULT 'Activo',
                  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $sqlPath = __DIR__ . '/database/schema_equipos.sql';
            if (file_exists($sqlPath)) {
                $sql = file_get_contents($sqlPath);
                $queries = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($queries as $q) {
                    if (!empty($q)) {
                        try {
                            $pdo->exec($q);
                        } catch (Throwable $qErr) {}
                    }
                }
            }
        }

        // Asegurar la existencia de las nuevas columnas para compatibilidad
        $nuevasColumnas = ['garantia2', 'garantia', 'contrasena_remoto', 'contrasena_gds', 'correo_oficial_planta', 'motivo_baja', 'puerto_patch_panel', 'puerto_sw', 'numero_nodo', 'factura_url', 'responsiva_url', 'costo', 'foto_equipo', 'dvr_vinculado', 'tipo_registro', 'dias_grabacion', 'usuario_dvr', 'numero_discos', 'canal_analogico', 'canal_ip', 'ubicacion', 'foto_vista_camara', 'subtipo_camara', 'fabricante', 'folio_factura', 'mac_ethernet', 'mac_wifi', 'cantidad_puertos', 'velocidad_puertos', 'tipo_switch', 'firmware_version', 'posicion_rack', 'sistema_op', 'procesador', 'ram', 'almacenamiento', 'cantidad_discos', 'tipo_servidor', 'funcion_servicio', 'ambiente', 'criticidad', 'bahias_nas', 'capacidad_disco_ind', 'capacidad_disponible', 'config_raid', 'tipo_discos', 'protocolos_nas', 'proveedor', 'tipo_enlace', 'ancho_banda', 'simetria_enlace', 'tipo_conexion', 'numero_contrato', 'numero_cuenta', 'circuit_id', 'soporte_contacto', 'ip_publica', 'ip_local', 'unifi_os_ver', 'controller_ver', 'velocidad_enlace', 'ssids', 'vlans', 'poe', 'controlador_ap', 'capacidad_va', 'capacidad_w', 'tipo_ups', 'voltaje_entrada', 'voltaje_salida', 'cant_baterias', 'specs_baterias', 'fecha_bateria', 'autonomia', 'imei_1', 'imei_2', 'numero_telefonico', 'color', 'accesorios', 'fecha_asignacion', 'tiene_plan_celular', 'vencimiento_plan', 'proveedor_plan', 'numero_contrato_plan', 'estado_fisico', 'tamano_pantalla', 'resolucion', 'subtipo_dispositivo', 'especificaciones', 'observaciones', 'marca', 'modelo_exacto', 'fecha_adquisicion', 'contrato', 'usuario_impresora', 'contrasena_impresora', 'usuario_impresora_web', 'contrasena_impresora_web', 'ip'];
        $todasLasTablasInv = ['inv_equipos_vw', 'inv_equipos_baja', 'inv_equipos_corp', 'inv_nobreak_baja', 'inv_site_vw', 'inv_dispositivos_moviles', 'inv_archivo', 'inv_monitores', 'inv_dvr_camaras', 'inv_licencias_office'];
        foreach ($todasLasTablasInv as $tbl) {
            foreach ($nuevasColumnas as $col) {
                try {
                    $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` TEXT");
                } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {
        // Fallback silencioso
    }
}

/**
 * Asegura la existencia de la tabla tickets_soporte
 */
function asegurarTablaTickets($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $driver = '';
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $t) {}

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS tickets_soporte (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    folio TEXT,
                    area_sistemas TEXT NOT NULL,
                    titulo TEXT NOT NULL,
                    descripcion TEXT NOT NULL,
                    prioridad TEXT DEFAULT 'Media',
                    estado TEXT DEFAULT 'Abierto',
                    solicitante_id INTEGER,
                    solicitante_usuario TEXT,
                    solicitante_nombre TEXT,
                    solicitante_email TEXT,
                    solicitante_agencia TEXT,
                    asignado_a TEXT,
                    archivo_adjunto TEXT,
                    notas_resolucion TEXT,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `tickets_soporte` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `folio` VARCHAR(50),
                    `area_sistemas` VARCHAR(100) NOT NULL,
                    `titulo` VARCHAR(255) NOT NULL,
                    `descripcion` TEXT NOT NULL,
                    `prioridad` VARCHAR(50) DEFAULT 'Media',
                    `estado` VARCHAR(50) DEFAULT 'Abierto',
                    `solicitante_id` INT NULL,
                    `solicitante_usuario` VARCHAR(100) NULL,
                    `solicitante_nombre` VARCHAR(150),
                    `solicitante_email` VARCHAR(150),
                    `solicitante_agencia` VARCHAR(100),
                    `asignado_a` VARCHAR(150),
                    `archivo_adjunto` VARCHAR(255),
                    `notas_resolucion` TEXT,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // Migración automática de columna solicitante_usuario
        try {
            $pdo->exec("ALTER TABLE tickets_soporte ADD COLUMN solicitante_usuario VARCHAR(100) NULL;");
        } catch (Throwable $eAlt) {}

        asegurarTablaTicketsMensajes($pdo);
    } catch (Throwable $e) {}
}

/**
 * Asegura la existencia de la tabla tickets_mensajes para el hilo de conversación y respuestas
 */
function asegurarTablaTicketsMensajes($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $driver = '';
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $t) {}

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS tickets_mensajes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ticket_id INTEGER NOT NULL,
                    folio TEXT,
                    autor_id INTEGER,
                    autor_nombre TEXT,
                    autor_usuario TEXT,
                    autor_rol TEXT DEFAULT 'usuario',
                    mensaje TEXT NOT NULL,
                    archivo_adjunto TEXT,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `tickets_mensajes` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `ticket_id` INT NOT NULL,
                    `folio` VARCHAR(50) NULL,
                    `autor_id` INT NULL,
                    `autor_nombre` VARCHAR(150),
                    `autor_usuario` VARCHAR(100),
                    `autor_rol` VARCHAR(50) DEFAULT 'usuario',
                    `mensaje` TEXT NOT NULL,
                    `archivo_adjunto` VARCHAR(255) NULL,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (`ticket_id`),
                    INDEX (`folio`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    } catch (Throwable $e) {}
}

/**
 * Auto-Instala o asegura la existencia de la tabla politicas_corporativas en MySQL o SQLite
 */
function asegurarTablaPoliticas($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $driver = '';
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $t) {}

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS politicas_corporativas (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    titulo TEXT NOT NULL,
                    descripcion TEXT,
                    categoria TEXT DEFAULT 'General',
                    archivo_pdf TEXT NOT NULL,
                    version TEXT DEFAULT '1.0',
                    fecha_vigencia DATE,
                    obligatorio_lectura INTEGER DEFAULT 0,
                    estatus INTEGER DEFAULT 1,
                    creado_por TEXT,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS politicas_lecturas (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    politica_id INTEGER NOT NULL,
                    politica_titulo TEXT NOT NULL,
                    usuario_id INTEGER,
                    usuario_nombre TEXT NOT NULL,
                    usuario_login TEXT NOT NULL,
                    agencia TEXT NOT NULL,
                    ip TEXT NOT NULL,
                    origen TEXT DEFAULT 'Portal Agencia',
                    fecha_lectura DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
            // Auto-migración SQLite: Asegurar columnas en caso de tabla preexistente
            $colsSqlite = ['area', 'subarea', 'categoria', 'version', 'fecha_vigencia', 'obligatorio_lectura', 'creado_por', 'estatus'];
            foreach ($colsSqlite as $col) {
                try {
                    $pdo->exec("ALTER TABLE politicas_corporativas ADD COLUMN {$col} TEXT");
                } catch (Throwable $t) {}
            }
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `politicas_corporativas` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `titulo` VARCHAR(255) NOT NULL,
                    `descripcion` TEXT NULL,
                    `categoria` VARCHAR(100) DEFAULT 'General',
                    `archivo_pdf` VARCHAR(255) NOT NULL,
                    `version` VARCHAR(20) DEFAULT '1.0',
                    `fecha_vigencia` DATE NULL,
                    `obligatorio_lectura` TINYINT(1) DEFAULT 0,
                    `estatus` TINYINT(1) DEFAULT 1,
                    `creado_por` VARCHAR(100) NULL,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `politicas_lecturas` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `politica_id` INT NOT NULL,
                    `politica_titulo` VARCHAR(255) NOT NULL,
                    `usuario_id` INT NULL,
                    `usuario_nombre` VARCHAR(150) NOT NULL,
                    `usuario_login` VARCHAR(100) NOT NULL,
                    `agencia` VARCHAR(100) NOT NULL,
                    `ip` VARCHAR(50) NOT NULL,
                    `origen` VARCHAR(50) DEFAULT 'Portal Agencia',
                    `fecha_lectura` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_politica` (`politica_id`),
                    INDEX `idx_usuario` (`usuario_login`),
                    INDEX `idx_fecha` (`fecha_lectura`)
            ");
            try { $pdo->exec("ALTER TABLE `politicas_corporativas` ADD COLUMN `area` VARCHAR(100) NULL"); } catch (Throwable $t) {}
            try { $pdo->exec("ALTER TABLE `politicas_corporativas` ADD COLUMN `subarea` VARCHAR(100) NULL"); } catch (Throwable $t) {}
        }
    } catch (Throwable $e) {}
}

/**
 * Auto-Instala o asegura la existencia de las tablas de compliance en MySQL o SQLite
 */
function asegurarTablaCompliance($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $driver = '';
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $t) {}

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS compliance_documentos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tipo TEXT NOT NULL,
                    codigo TEXT,
                    titulo TEXT NOT NULL,
                    descripcion TEXT,
                    categoria TEXT DEFAULT 'General',
                    archivo_url TEXT,
                    archivo_tipo TEXT DEFAULT 'pdf',
                    archivo_tamano TEXT,
                    version TEXT DEFAULT '1.0',
                    fecha_publicacion DATE,
                    fecha_vigencia DATE,
                    prioridad TEXT DEFAULT 'Normal',
                    obligatorio_lectura INTEGER DEFAULT 0,
                    estatus INTEGER DEFAULT 1,
                    creado_por TEXT,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS compliance_lecturas (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    documento_id INTEGER NOT NULL,
                    documento_titulo TEXT NOT NULL,
                    usuario_id INTEGER,
                    usuario_nombre TEXT NOT NULL,
                    usuario_login TEXT NOT NULL,
                    agencia TEXT NOT NULL,
                    ip TEXT NOT NULL,
                    origen TEXT DEFAULT 'Portal Agencia',
                    fecha_lectura DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `compliance_documentos` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `tipo` VARCHAR(50) NOT NULL,
                    `codigo` VARCHAR(50) NULL,
                    `titulo` VARCHAR(255) NOT NULL,
                    `descripcion` TEXT NULL,
                    `categoria` VARCHAR(100) DEFAULT 'General',
                    `archivo_url` VARCHAR(255) NULL,
                    `archivo_tipo` VARCHAR(20) DEFAULT 'pdf',
                    `archivo_tamano` VARCHAR(50) NULL,
                    `version` VARCHAR(20) DEFAULT '1.0',
                    `fecha_publicacion` DATE NULL,
                    `fecha_vigencia` DATE NULL,
                    `prioridad` VARCHAR(20) DEFAULT 'Normal',
                    `obligatorio_lectura` TINYINT(1) DEFAULT 0,
                    `estatus` TINYINT(1) DEFAULT 1,
                    `creado_por` VARCHAR(100) NULL,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_comp_tipo` (`tipo`),
                    INDEX `idx_comp_cat` (`categoria`),
                    INDEX `idx_comp_est` (`estatus`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `compliance_lecturas` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `documento_id` INT NOT NULL,
                    `documento_titulo` VARCHAR(255) NOT NULL,
                    `usuario_id` INT NULL,
                    `usuario_nombre` VARCHAR(150) NOT NULL,
                    `usuario_login` VARCHAR(100) NOT NULL,
                    `agencia` VARCHAR(100) NOT NULL,
                    `ip` VARCHAR(50) NOT NULL,
                    `origen` VARCHAR(50) DEFAULT 'Portal Agencia',
                    `fecha_lectura` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_comp_doc` (`documento_id`),
                    INDEX `idx_comp_usr` (`usuario_login`),
                    INDEX `idx_comp_fec` (`fecha_lectura`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // Sembrado inicial de documentos oficiales de compliance si la tabla está vacía
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM compliance_documentos");
        $totalDocs = $stmtCount ? (int)$stmtCount->fetchColumn() : 0;
        if ($totalDocs === 0) {
            $semillas = [
                // FORMATOS
                [
                    'formato', 'FR-TI-01', 'Carta Responsiva de Asignación de Equipo de Cómputo y Telefonía',
                    'Documento legal y administrativo para la entrega formal de equipos de cómputo, laptops, periféricos y líneas móviles a colaboradores con compromiso de resguardo y buen uso.',
                    'Tecnologías de la Información', 'uploads/compliance/FR-TI-01_Responsiva_Equipos.pdf', 'pdf', '480 KB', '2.1', date('Y-01-15'), '2027-12-31', 'Alta', 1, 'Dirección de Sistemas'
                ],
                [
                    'formato', 'FR-TI-02', 'Solicitud de Cuentas, Permisos y Accesos a Sistemas',
                    'Formato oficial para requerir altas de correos institucionales, accesos a ERP/DMS, carpetas compartidas y plataformas operativas con autorización del titular de área.',
                    'Tecnologías de la Información', 'uploads/compliance/FR-TI-02_Solicitud_Cuentas.pdf', 'pdf', '320 KB', '1.5', date('Y-02-01'), '2027-12-31', 'Normal', 0, 'Seguridad TI'
                ],
                [
                    'formato', 'FR-RH-01', 'Notificación Oficial de Altas, Bajas y Movimientos de Personal',
                    'Control y formalización de altas y bajas laborales para sincronización inmediata de nómina, entrega de credenciales y cancelación de credenciales lógicas y físicas.',
                    'Recursos Humanos', 'uploads/compliance/FR-RH-01_Altas_Bajas_Personal.pdf', 'pdf', '395 KB', '2.0', date('Y-01-10'), '2027-12-31', 'Alta', 1, 'Dirección de Capital Humano'
                ],
                [
                    'formato', 'FR-LEG-01', 'Solicitud para Ejercicio de Derechos ARCO (Datos Personales)',
                    'Formato regulatorio para la atención de solicitudes de Acceso, Rectificación, Cancelación u Oposición conforme a la legislación de protección de datos personales.',
                    'Legal & Cumplimiento', 'uploads/compliance/FR-LEG-01_Derechos_ARCO.pdf', 'pdf', '285 KB', '1.2', date('Y-03-01'), '2027-12-31', 'Alta', 0, 'Comité de Cumplimiento'
                ],
                [
                    'formato', 'FR-SG-01', 'Bitácora y Pase de Salida Temporal de Activos y Equipamiento',
                    'Autorización formal para la salida temporal de activos de la agencia con fines de mantenimiento, eventos externos o trabajo remoto supervisado.',
                    'Seguridad Patrimonial', 'uploads/compliance/FR-SG-01_Pase_Salida_Activos.pdf', 'pdf', '210 KB', '1.1', date('Y-02-20'), '2027-12-31', 'Normal', 0, 'Seguridad Patrimonial'
                ],

                // MANUALES
                [
                    'manual', 'MN-TI-01', 'Manual Institucional de Uso del Portal de Sistemas Grupo Huerta',
                    'Guía integral para usuarios y administradores sobre navegación, gestión de inventarios, reportes de infraestructura, catálogo de sucursales y permisos RBAC.',
                    'Tecnologías de la Información', 'uploads/compliance/MN-TI-01_Manual_Portal_Sistemas.pdf', 'pdf', '1.4 MB', '2.0', date('Y-01-20'), '2027-12-31', 'Normal', 0, 'Dirección de Sistemas'
                ],
                [
                    'manual', 'MN-CY-01', 'Manual y Buenas Prácticas de Ciberseguridad y Manejo de Contraseñas',
                    'Manual corporativo para la prevención de intrusiones, creación de contraseñas robustas, doble factor de autenticación (2FA) y detección de correos maliciosos.',
                    'Ciberseguridad', 'uploads/compliance/MN-CY-01_Manual_Ciberseguridad.pdf', 'pdf', '2.1 MB', '3.0', date('Y-01-05'), '2027-12-31', 'Alta', 1, 'Oficina de Ciberseguridad'
                ],
                [
                    'manual', 'MN-OP-01', 'Manual Operativo del Sistema de Tickets y Mesa de Ayuda',
                    'Procedimiento institucional para el levantamiento de tickets, priorización de incidentes, niveles de servicio (SLA) y flujo de resolución en sucursales.',
                    'Operaciones & Soporte', 'uploads/compliance/MN-OP-01_Manual_Tickets_MesaAyuda.pdf', 'pdf', '980 KB', '1.8', date('Y-02-15'), '2027-12-31', 'Normal', 0, 'Mesa de Ayuda Central'
                ],
                [
                    'manual', 'MN-BC-01', 'Protocolo de Continuidad de Negocio y Respaldo de Información Crítica',
                    'Manual de contingencia operativa y esquemas de respaldo local y remoto de bases de datos, configuraciones de red y expedientes digitales.',
                    'Infraestructura', 'uploads/compliance/MN-BC-01_Continuidad_Negocio_Respaldos.pdf', 'pdf', '1.8 MB', '1.3', date('Y-03-10'), '2027-12-31', 'Alta', 0, 'Dirección de Sistemas'
                ],

                // AVISOS
                [
                    'aviso', 'AV-01', 'Aviso de Privacidad Integral Institucional para Colaboradores y Clientes',
                    'Lineamiento maestro del tratamiento, confidencialidad, resguardo y transferencia legítima de datos personales y sensibles dentro de las entidades de Grupo Huerta.',
                    'Legal & Cumplimiento', 'uploads/compliance/AV-01_Aviso_Privacidad_Integral.pdf', 'pdf', '540 KB', '2026.1', date('Y-01-01'), '2027-12-31', 'Alta', 1, 'Comité de Cumplimiento'
                ],
                [
                    'aviso', 'AV-02', 'Alerta de Ciberseguridad: Protocolo Preventivo Anti-Phishing y Suplantación',
                    'Comunicado oficial sobre vectores de ataque recientes por correo electrónico, SMS y WhatsApp. Se recuerda la prohibición de compartir claves por cualquier medio.',
                    'Ciberseguridad', 'uploads/compliance/AV-02_Alerta_AntiPhishing.pdf', 'pdf', '410 KB', '2026.2', date('Y-03-15'), '2027-12-31', 'Alta', 1, 'Oficina de Ciberseguridad'
                ],
                [
                    'aviso', 'AV-03', 'Lineamientos Institucionales para Uso de Redes WiFi e Internet Corporativo',
                    'Disposiciones de navegación segura, prohibición de descargas no autorizadas, uso de ancho de banda y monitoreo preventivo de la red de datos.',
                    'Auditoría TI', 'uploads/compliance/AV-03_Lineamientos_Uso_Internet.pdf', 'pdf', '365 KB', '1.4', date('Y-02-01'), '2027-12-31', 'Media', 0, 'Dirección de Sistemas'
                ],
                [
                    'aviso', 'AV-04', 'Circular de Alta Dirección: Resguardo de Información Confidencial del Negocio',
                    'Disposición de confidencialidad estricta respecto a cifras comerciales, bases de clientes, costos y estrategias operativas del consorcio.',
                    'Dirección General', 'uploads/compliance/AV-04_Circular_Confidencialidad.pdf', 'pdf', '290 KB', '1.0', date('Y-01-02'), '2027-12-31', 'Alta', 1, 'Presidencia & Dirección General'
                ]
            ];

            $stmtIns = $pdo->prepare("
                INSERT INTO compliance_documentos 
                (tipo, codigo, titulo, descripcion, categoria, archivo_url, archivo_tipo, archivo_tamano, version, fecha_publicacion, fecha_vigencia, prioridad, obligatorio_lectura, estatus, creado_por)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");

            foreach ($semillas as $s) {
                $stmtIns->execute($s);
            }
        }
    } catch (Throwable $e) {}
}

/**
 * Carga los permisos del usuario en la sesión desde la base de datos
 */
function cargarPermisosSesion($pdo, $usuario_id) {
    if (!$pdo || !$usuario_id) return;

    try {
        // Cargar datos de perfil, rol y áreas autorizadas de tickets
        $stmtU = $pdo->prepare("SELECT usuario, nombre, email, rol, agencia, areas_tickets FROM usuarios WHERE id = ?");
        $stmtU->execute([$usuario_id]);
        $uInfo = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($uInfo) {
            if (!empty($uInfo['usuario'])) $_SESSION['usuario_login'] = $uInfo['usuario'];
            if (!empty($uInfo['nombre']))  $_SESSION['usuario_nombre'] = $uInfo['nombre'];
            if (!empty($uInfo['email']))   $_SESSION['usuario_email']  = $uInfo['email'];
            if (!empty($uInfo['agencia'])) $_SESSION['agencia']        = $uInfo['agencia'];
            $_SESSION['usuario_rol'] = $uInfo['rol'] ?? ($_SESSION['usuario_rol'] ?? 'Usuario');
            $_SESSION['areas_tickets'] = $uInfo['areas_tickets'] ?? 'TODOS';
        }
    } catch (Throwable $eU) {}

    try {
        $stmt = $pdo->prepare("
            SELECT modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar 
            FROM usuario_permisos 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuario_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $_SESSION['permisos'] = [];
        foreach ($rows as $row) {
            $_SESSION['permisos'][$row['modulo_clave']] = [
                'puede_ver'      => (int)$row['puede_ver'],
                'puede_crear'    => (int)$row['puede_crear'],
                'puede_editar'   => (int)$row['puede_editar'],
                'puede_eliminar' => (int)$row['puede_eliminar'],
                'puede_exportar' => (int)$row['puede_exportar']
            ];
        }
    } catch (Throwable $e) {
        // En caso de que las tablas aún no existan, auto-crearlas
        asegurarTablasPermisos($pdo);
        $_SESSION['permisos'] = [];
    }
}

/**
 * Verifica si el usuario actual tiene un permiso específico sobre un módulo
 */
function tienePermiso($modulo_clave, $accion = 'puede_ver') {
    // Si no hay sesión iniciada, denegar
    if (!isset($_SESSION['usuario_id'])) {
        return false;
    }

    $rol = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');

    // 1. Si el usuario tiene permisos configurados en la matriz (usuario_permisos), respetarlos SIEMPRE
    if (isset($_SESSION['permisos']) && is_array($_SESSION['permisos']) && isset($_SESSION['permisos'][$modulo_clave])) {
        $permisosMod = $_SESSION['permisos'][$modulo_clave];
        return !empty($permisosMod[$accion]);
    }

    // 2. Si no se han configurado permisos específicos en la matriz aún (fallback inicial):
    // SuperAdmin y Admin tienen acceso total por defecto
    if (in_array($rol, ['superadmin', 'admin'])) {
        return true;
    }

    // Para rol Usuario general sin permisos configurados, denegar por seguridad
    return false;
}

/**
 * Exige un permiso o bloquea la ejecución con mensaje de Acceso Denegado
 */
function requerirPermiso($modulo_clave, $accion = 'puede_ver') {
    if (!tienePermiso($modulo_clave, $accion)) {
        header("HTTP/1.1 403 Forbidden");
        echo '<!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Acceso Denegado - 403</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        </head>
        <body class="bg-dark text-white d-flex align-items-center justify-content-center vh-100 text-center">
            <div class="card bg-secondary bg-opacity-25 border-danger p-5 rounded-4 shadow-lg text-white" style="max-width: 500px;">
                <i class="bi bi-shield-lock-fill text-danger display-1 mb-3"></i>
                <h3 class="fw-bold text-danger">Acceso restringido</h3>
                <p class="text-light">No posees los permisos necesarios para acceder o visualizar el módulo <code>'.htmlspecialchars($modulo_clave).'</code>.</p>
                <a href="menu.php" class="btn btn-primary rounded-3 px-4 mt-2"><i class="bi bi-arrow-left me-2"></i>Volver al Menú Principal</a>
            </div>
        </body>
        </html>';
        exit();
    }
}

/**
 * Auto-Instala o asegura la existencia de las tablas de infraestructura (diagramas, site, red_idf)
 */
function asegurarTablasInfraestructura($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $driver = '';
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $drvErr) {}

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS infra_diagramas (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    titulo TEXT NOT NULL,
                    tipo TEXT DEFAULT 'Red / Topología',
                    version TEXT DEFAULT '1.0',
                    archivo_url TEXT NOT NULL,
                    descripcion TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS infra_site (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nombre_site TEXT NOT NULL,
                    ubicacion TEXT,
                    tipo_espacio TEXT DEFAULT 'SITE Principal',
                    temperatura_objetivo TEXT,
                    aire_acondicionado TEXT,
                    btu TEXT,
                    aire_estatus TEXT DEFAULT 'Operativo',
                    ups_principal TEXT,
                    cap_ups TEXT,
                    planta_luz TEXT,
                    control_acceso TEXT,
                    contra_incendio TEXT,
                    foto_site TEXT,
                    observaciones TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS infra_red_idf (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nombre_idf TEXT NOT NULL,
                    ubicacion TEXT,
                    rango_ips TEXT,
                    vlans TEXT,
                    switch_principal TEXT,
                    no_puertos TEXT,
                    no_racks TEXT,
                    estatus TEXT DEFAULT 'Activo',
                    foto_idf TEXT,
                    notas TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS infra_planos_2d (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nombre_plano TEXT NOT NULL,
                    imagen_fondo_url TEXT,
                    ancho_canvas INTEGER DEFAULT 1200,
                    alto_canvas INTEGER DEFAULT 800,
                    elementos_json TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS infra_nodos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    codigo_nodo TEXT NOT NULL,
                    tipo_nodo TEXT DEFAULT 'Voz y Datos',
                    ubicacion TEXT,
                    patch_panel TEXT,
                    switch_puerto TEXT,
                    vlan TEXT,
                    estatus TEXT DEFAULT 'Activo',
                    notas TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS infra_vlans (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    vlan_id INTEGER NOT NULL,
                    nombre_vlan TEXT NOT NULL,
                    subred TEXT,
                    gateway TEXT,
                    dhcp_rango TEXT,
                    descripcion TEXT,
                    estatus TEXT DEFAULT 'Activa',
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `infra_diagramas` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `titulo` VARCHAR(150) NOT NULL,
                  `tipo` VARCHAR(100) DEFAULT 'Red / Topología',
                  `version` VARCHAR(50) DEFAULT '1.0',
                  `archivo_url` VARCHAR(255) NOT NULL,
                  `descripcion` TEXT,
                  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `infra_site` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `nombre_site` VARCHAR(150) NOT NULL,
                  `ubicacion` VARCHAR(200),
                  `tipo_espacio` VARCHAR(100) DEFAULT 'SITE Principal',
                  `temperatura_objetivo` VARCHAR(100),
                  `aire_acondicionado` VARCHAR(150),
                  `btu` VARCHAR(50),
                  `aire_estatus` VARCHAR(50) DEFAULT 'Operativo',
                  `ups_principal` VARCHAR(150),
                  `cap_ups` VARCHAR(50),
                  `planta_luz` VARCHAR(150),
                  `control_acceso` VARCHAR(150),
                  `contra_incendio` VARCHAR(150),
                  `foto_site` VARCHAR(255),
                  `observaciones` TEXT,
                  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `infra_red_idf` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `nombre_idf` VARCHAR(150) NOT NULL,
                  `ubicacion` VARCHAR(200),
                  `rango_ips` VARCHAR(150),
                  `vlans` VARCHAR(200),
                  `switch_principal` VARCHAR(150),
                  `no_puertos` VARCHAR(50),
                  `no_racks` VARCHAR(50),
                  `estatus` VARCHAR(50) DEFAULT 'Activo',
                  `foto_idf` VARCHAR(255),
                  `notas` TEXT,
                  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `infra_planos_2d` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `nombre_plano` VARCHAR(150) NOT NULL,
                  `imagen_fondo_url` VARCHAR(255),
                  `ancho_canvas` INT DEFAULT 1200,
                  `alto_canvas` INT DEFAULT 800,
                  `elementos_json` LONGTEXT,
                  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `infra_nodos` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `codigo_nodo` VARCHAR(100) NOT NULL,
                  `tipo_nodo` VARCHAR(100) DEFAULT 'Voz y Datos',
                  `ubicacion` VARCHAR(200),
                  `patch_panel` VARCHAR(150),
                  `switch_puerto` VARCHAR(150),
                  `vlan` VARCHAR(100),
                  `estatus` VARCHAR(50) DEFAULT 'Activo',
                  `notas` TEXT,
                  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `infra_vlans` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `vlan_id` INT NOT NULL,
                  `nombre_vlan` VARCHAR(150) NOT NULL,
                  `subred` VARCHAR(100),
                  `gateway` VARCHAR(100),
                  `dhcp_rango` VARCHAR(150),
                  `descripcion` TEXT,
                  `estatus` VARCHAR(50) DEFAULT 'Activa',
                  `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // Migraciones automáticas para infra_nodos y telefonía PoE
        try {
            $pdo->exec("ALTER TABLE `infra_nodos` ADD COLUMN `tiene_telefono_poe` TINYINT(1) DEFAULT 0;");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `infra_nodos` ADD COLUMN `telefono_poe_id` INT DEFAULT 0;");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `infra_planos_2d` MODIFY COLUMN `elementos_json` LONGTEXT;");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `infra_site` MODIFY COLUMN `observaciones` LONGTEXT;");
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/**
 * Catálogo oficial de temas OBSCUROS para el Portal de la Agencia
 */
function obtenerCatalogoTemasOscuros() {
    return [
        'azul' => [
            'clave' => 'azul',
            'nombre' => 'Azul Obscuro (Medianoche)',
            'bg_dark' => '#040d1a',
            'bg_deep' => '#02070e',
            'bg_card' => '#08162a',
            'primary' => '#0284c7',
            'accent' => '#38bdf8',
            'secondary' => '#2563eb',
            'glow' => 'rgba(2, 132, 199, 0.45)',
            'glow_soft' => 'rgba(56, 189, 248, 0.22)',
            'border' => 'rgba(56, 189, 248, 0.25)',
            'three_p1' => '0x38bdf8',
            'three_p2' => '0x2563eb',
            'three_p3' => '0x818cf8',
            'hex_swatch' => '#0284c7'
        ],
        'rojo' => [
            'clave' => 'rojo',
            'nombre' => 'Rojo Obscuro (Borgoña / Carmesí)',
            'bg_dark' => '#140306',
            'bg_deep' => '#0a0103',
            'bg_card' => '#20080e',
            'primary' => '#e11d48',
            'accent' => '#fb7185',
            'secondary' => '#be123c',
            'glow' => 'rgba(225, 29, 72, 0.45)',
            'glow_soft' => 'rgba(251, 113, 133, 0.22)',
            'border' => 'rgba(251, 113, 133, 0.25)',
            'three_p1' => '0xfb7185',
            'three_p2' => '0xe11d48',
            'three_p3' => '0xf43f5e',
            'hex_swatch' => '#e11d48'
        ],
        'verde' => [
            'clave' => 'verde',
            'nombre' => 'Verde Obscuro (Esmeralda / Bosque)',
            'bg_dark' => '#03140c',
            'bg_deep' => '#010a06',
            'bg_card' => '#071f14',
            'primary' => '#059669',
            'accent' => '#34d399',
            'secondary' => '#047857',
            'glow' => 'rgba(5, 150, 105, 0.45)',
            'glow_soft' => 'rgba(52, 211, 153, 0.22)',
            'border' => 'rgba(52, 211, 153, 0.25)',
            'three_p1' => '0x34d399',
            'three_p2' => '0x10b981',
            'three_p3' => '0x059669',
            'hex_swatch' => '#059669'
        ],
        'morado' => [
            'clave' => 'morado',
            'nombre' => 'Morado Obscuro (Púrpura / Amatista)',
            'bg_dark' => '#0e041c',
            'bg_deep' => '#07010e',
            'bg_card' => '#170a2c',
            'primary' => '#7c3aed',
            'accent' => '#a78bfa',
            'secondary' => '#6d28d9',
            'glow' => 'rgba(124, 58, 237, 0.45)',
            'glow_soft' => 'rgba(167, 139, 250, 0.22)',
            'border' => 'rgba(167, 139, 250, 0.25)',
            'three_p1' => '0xa78bfa',
            'three_p2' => '0x8b5cf6',
            'three_p3' => '0xc084fc',
            'hex_swatch' => '#7c3aed'
        ],
        'ambar' => [
            'clave' => 'ambar',
            'nombre' => 'Ámbar Obscuro (Bronce / Oro Ejecutivo)',
            'bg_dark' => '#140d02',
            'bg_deep' => '#0a0601',
            'bg_card' => '#211606',
            'primary' => '#d97706',
            'accent' => '#fbbf24',
            'secondary' => '#b45309',
            'glow' => 'rgba(217, 119, 6, 0.45)',
            'glow_soft' => 'rgba(251, 191, 36, 0.22)',
            'border' => 'rgba(251, 191, 36, 0.25)',
            'three_p1' => '0xfbbf24',
            'three_p2' => '0xf59e0b',
            'three_p3' => '0xd97706',
            'hex_swatch' => '#d97706'
        ],
        'grafito' => [
            'clave' => 'grafito',
            'nombre' => 'Grafito Obscuro (Obsidiana / Titanio)',
            'bg_dark' => '#0a0c10',
            'bg_deep' => '#040507',
            'bg_card' => '#13161c',
            'primary' => '#475569',
            'accent' => '#94a3b8',
            'secondary' => '#334155',
            'glow' => 'rgba(71, 85, 105, 0.45)',
            'glow_soft' => 'rgba(148, 163, 184, 0.22)',
            'border' => 'rgba(148, 163, 184, 0.25)',
            'three_p1' => '0xe2e8f0',
            'three_p2' => '0x94a3b8',
            'three_p3' => '0x64748b',
            'hex_swatch' => '#475569'
        ]
    ];
}

/**
 * Obtiene el tema activo de la agencia desde BD o sesión
 */
function obtenerTemaColorActivo($pdo = null) {
    if (!$pdo && isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo'];
    }

    $clave = 'azul';
    if (!empty($_SESSION['color_tema'])) {
        $clave = $_SESSION['color_tema'];
    } elseif ($pdo) {
        try {
            try { $pdo->exec("ALTER TABLE agencias ADD COLUMN color_tema VARCHAR(50) DEFAULT 'azul'"); } catch (Throwable $t) {}
            $stmt = $pdo->query("SELECT color_tema FROM agencias ORDER BY id ASC LIMIT 1");
            $col = $stmt ? $stmt->fetchColumn() : null;
            if (!empty($col)) {
                $clave = strtolower(trim($col));
                $_SESSION['color_tema'] = $clave;
            }
        } catch (Throwable $e) {}
    }

    $catalogo = obtenerCatalogoTemasOscuros();
    return $catalogo[$clave] ?? $catalogo['azul'];
}

/**
 * Inyecta los estilos CSS dinámicos para teñir el fondo y los componentes de todos los módulos
 */
function renderizarEstilosTemaGlobal($pdo = null) {
    $t = obtenerTemaColorActivo($pdo);
    ?>
    <style id="estilos-tema-portal-obscuro">
        :root {
            --portal-theme-bg: <?= $t['bg_dark']; ?>;
            --portal-theme-bg-deep: <?= $t['bg_deep']; ?>;
            --portal-theme-card-bg: <?= $t['bg_card']; ?>;
            --portal-theme-primary: <?= $t['primary']; ?>;
            --portal-theme-accent: <?= $t['accent']; ?>;
            --portal-theme-secondary: <?= $t['secondary']; ?>;
            --portal-theme-glow: <?= $t['glow']; ?>;
            --portal-theme-glow-soft: <?= $t['glow_soft']; ?>;
            --portal-theme-border: <?= $t['border']; ?>;
        }

        body {
            background-color: var(--portal-theme-bg) !important;
            background-image: radial-gradient(var(--portal-theme-bg-deep) 1px, transparent 1px) !important;
        }

        .top-navbar, .navbar-custom {
            background: var(--portal-theme-bg-deep) !important;
            border-bottom: 1px solid var(--portal-theme-border) !important;
        }

        .card-custom, .module-card, .portal-card {
            background: var(--portal-theme-card-bg) !important;
            border-color: var(--portal-theme-border) !important;
        }

        .card-custom:hover, .module-card:hover {
            border-color: var(--portal-theme-accent) !important;
            box-shadow: 0 10px 30px var(--portal-theme-glow-soft) !important;
        }

        .hero-banner {
            background: linear-gradient(135deg, var(--portal-theme-card-bg) 0%, var(--portal-theme-bg-deep) 100%) !important;
            border-color: var(--portal-theme-border) !important;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6), 0 0 25px var(--portal-theme-glow-soft) !important;
        }

        .hero-overlay {
            background: linear-gradient(90deg, var(--portal-theme-bg) 0%, rgba(0, 0, 0, 0.65) 100%) !important;
        }

        .logo-container {
            background: var(--portal-theme-bg-deep) !important;
            border-color: var(--portal-theme-border) !important;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--portal-theme-primary) 0%, var(--portal-theme-secondary) 100%) !important;
            border-color: var(--portal-theme-secondary) !important;
            box-shadow: 0 4px 15px var(--portal-theme-glow-soft);
        }

        .btn-primary:hover {
            box-shadow: 0 6px 20px var(--portal-theme-glow);
        }

        .text-primary, .text-info {
            color: var(--portal-theme-accent) !important;
        }

        .badge.bg-primary {
            background-color: var(--portal-theme-primary) !important;
        }

        .badge.bg-info.bg-opacity-25 {
            background-color: var(--portal-theme-glow-soft) !important;
            color: var(--portal-theme-accent) !important;
            border-color: var(--portal-theme-accent) !important;
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--portal-theme-card-bg) !important;
            border-color: var(--portal-theme-accent) !important;
            box-shadow: 0 0 0 0.25rem var(--portal-theme-glow-soft) !important;
        }
    </style>
    <?php
}
?>
