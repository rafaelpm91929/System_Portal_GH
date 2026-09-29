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
        ('infraestructura', 'Infraestructura (SITE / IDF)', 'Control de racks, switches y cableado estructurado', 'bi-hdd-rack-fill', 6, 1)
        ON DUPLICATE KEY UPDATE `nombre`=VALUES(`nombre`), `icono`=VALUES(`icono`), `orden`=VALUES(`orden`);");

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
 * Carga los permisos del usuario en la sesión desde la base de datos
 */
function cargarPermisosSesion($pdo, $usuario_id) {
    if (!$pdo || !$usuario_id) return;

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

    // Los roles SuperAdmin y Admin tienen permiso total implícito
    $rol = $_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'Usuario';
    if (in_array(strtolower($rol), ['superadmin', 'admin'])) {
        return true;
    }

    // Para el módulo de gestión de usuarios, solo Admin o SuperAdmin pueden ingresar
    if ($modulo_clave === 'usuarios') {
        return false;
    }

    // Verificar en la matriz de permisos de la sesión
    $permisos = $_SESSION['permisos'][$modulo_clave] ?? null;
    if (!$permisos) {
        return false;
    }

    return !empty($permisos[$accion]);
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
                <p class="text-light">No posees los permisos necesarios para acceder o realizar acciones en el módulo <code>'.htmlspecialchars($modulo_clave).'</code>.</p>
                <a href="modulos.php" class="btn btn-primary rounded-3 px-4 mt-2"><i class="bi bi-arrow-left me-2"></i>Volver al Menú Principal</a>
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
    } catch (Throwable $e) {}
}
?>
