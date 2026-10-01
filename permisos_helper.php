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
        $driver = '';
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $ed) {}

        if ($driver === 'sqlite') {
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              usuario VARCHAR(50) NOT NULL UNIQUE,
              nombre VARCHAR(100) NOT NULL,
              email VARCHAR(100) NOT NULL UNIQUE,
              password VARCHAR(255) NOT NULL,
              agencia VARCHAR(100) DEFAULT 'VW Divol La Villa',
              rol VARCHAR(20) DEFAULT 'Admin',
              activo INTEGER DEFAULT 1,
              areas_tickets VARCHAR(255) DEFAULT 'TODOS',
              creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS modulos (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              clave VARCHAR(50) NOT NULL UNIQUE,
              nombre VARCHAR(100) NOT NULL,
              descripcion TEXT,
              icono VARCHAR(50) DEFAULT 'bi-app-indicator',
              orden INTEGER DEFAULT 0,
              estatus INTEGER DEFAULT 1,
              creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS usuario_permisos (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              usuario_id INTEGER NOT NULL,
              modulo_clave VARCHAR(50) NOT NULL,
              puede_ver INTEGER DEFAULT 0,
              puede_crear INTEGER DEFAULT 0,
              puede_editar INTEGER DEFAULT 0,
              puede_eliminar INTEGER DEFAULT 0,
              puede_exportar INTEGER DEFAULT 0,
              actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE (usuario_id, modulo_clave)
            );");
        } else {
            // 1. Tabla usuarios MySQL
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS `usuarios` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `usuario` VARCHAR(50) NOT NULL UNIQUE,
              `nombre` VARCHAR(100) NOT NULL,
              `email` VARCHAR(100) NOT NULL UNIQUE,
              `password` VARCHAR(255) NOT NULL,
              `agencia` VARCHAR(100) DEFAULT 'VW Divol La Villa',
              `rol` ENUM('SuperAdmin', 'Admin', 'Usuario') DEFAULT 'Admin',
              `activo` TINYINT(1) DEFAULT 1,
              `areas_tickets` VARCHAR(255) DEFAULT 'TODOS',
              `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // 2. Tabla modulos MySQL
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

            // 3. Tabla usuario_permisos MySQL
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
              UNIQUE KEY `uq_usuario_modulo` (`usuario_id`, `modulo_clave`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }

        // Asegurar columna areas_tickets en usuarios existentes
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN areas_tickets VARCHAR(255) DEFAULT 'TODOS'");
        } catch (Throwable $eAlter) {}

        // 4. Sembrado Inicial de Módulos (Solo los 3 activos en esta fase)
        $modulosBase = [
            ['tickets',          'Tickets Soporte Dirección Sistemas', 'Recepción y seguimiento de tickets para las áreas de Sistemas', 'bi-ticket-detailed-fill', 1, 1],
            ['agencias',         'Agencias (Catálogo cPanel)',         'Monitoreo y administración de sucursales cPanel',            'bi-buildings-fill',       2, 1],
            ['usuarios',         'Gestión de Usuarios',                'Administración de usuarios, roles y permisos del portal',   'bi-people-fill',          3, 1],
            ['ordenes_servicio', 'Órdenes de Servicio',               'Resumen de órdenes abiertas, cerradas y montos acumulados', 'bi-file-earmark-bar-graph', 4, 0],
            ['equipos',          'Inventario de Equipos',              'Control de PCs, laptops, servidores e impresoras',          'bi-display-fill',            5, 0],
            ['celulares',        'Inventario de Celulares',            'Control de equipos móviles y líneas corporativas',          'bi-phone-fill',              6, 0],
            ['licencias',        'Licencias de Software',             'Matriz de licenciamiento corporativo y vencimientos',        'bi-key-fill',                7, 0],
            ['infraestructura',   'Infraestructura (SITE / IDF)',       'Control de racks, switches y cableado estructurado',        'bi-hdd-rack-fill',           8, 0]
        ];

        foreach ($modulosBase as $m) {
            $stmtCheck = $pdo->prepare("SELECT id FROM modulos WHERE clave = ?");
            $stmtCheck->execute([$m[0]]);
            if ($stmtCheck->fetch()) {
                $stmtUpd = $pdo->prepare("UPDATE modulos SET nombre=?, descripcion=?, icono=?, orden=?, estatus=? WHERE clave=?");
                $stmtUpd->execute([$m[1], $m[2], $m[3], $m[4], $m[5], $m[0]]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO modulos (clave, nombre, descripcion, icono, orden, estatus) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$m[0], $m[1], $m[2], $m[3], $m[4], $m[5]]);
            }
        }

        // Mantener activos únicamente los 3 módulos vigentes
        try {
            $pdo->exec("UPDATE modulos SET estatus = 1 WHERE clave IN ('tickets', 'agencias', 'usuarios');");
            $pdo->exec("UPDATE modulos SET estatus = 0 WHERE clave NOT IN ('tickets', 'agencias', 'usuarios');");
        } catch (Throwable $eUpd) {}

    } catch (Throwable $e) {
        // Evitar interrumpir flujo si falla parcialmente
    }
}

/**
 * Obtiene el catálogo oficial de módulos del sistema, garantizando datos siempre.
 */
function obtenerCatalogoModulos($pdo = null) {
    $modulosDefault = [
        ['clave' => 'tickets',          'nombre' => 'Tickets Soporte Dirección Sistemas', 'descripcion' => 'Recepción y seguimiento de tickets para las áreas de Sistemas', 'icono' => 'bi-ticket-detailed-fill', 'orden' => 1],
        ['clave' => 'agencias',         'nombre' => 'Agencias (Catálogo cPanel)',         'descripcion' => 'Monitoreo y administración de sucursales cPanel',            'icono' => 'bi-buildings-fill',       'orden' => 2],
        ['clave' => 'usuarios',         'nombre' => 'Gestión de Usuarios',                'descripcion' => 'Administración de usuarios, roles y permisos del portal',   'icono' => 'bi-people-fill',          'orden' => 3, 'estatus' => 1]
    ];

    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM modulos WHERE estatus = 1 ORDER BY orden ASC");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if (!empty($rows)) {
                return $rows;
            }
        } catch (Throwable $e) {}
    }
    return $modulosDefault;
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

    // SuperAdmin tiene acceso total a todos los módulos
    if ($rol === 'superadmin') {
        return true;
    }

    // Verificar en la matriz de permisos de la sesión
    $permisos = $_SESSION['permisos'] ?? [];
    if (isset($permisos[$modulo_clave])) {
        return !empty($permisos[$modulo_clave][$accion]);
    }

    // Si es Admin pero aún no tiene configuración explícita guardada
    if ($rol === 'admin') {
        return true;
    }

    // Si es Usuario y no tiene el permiso explícito: DENEGADO
    return false;
}

/**
 * Exige un permiso o bloquea la ejecución con mensaje de Acceso Denegado (403)
 */
function requerirPermiso($modulo_clave, $accion = 'puede_ver') {
    if (!tienePermiso($modulo_clave, $accion)) {
        header("HTTP/1.1 403 Forbidden");
        echo '<!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Acceso Restringido - 403 Forbidden</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
            <style>
                body {
                    background-color: #040d1a;
                    background-image: radial-gradient(#0b223e 1px, transparent 1px);
                    background-size: 28px 28px;
                    color: #ffffff;
                    font-family: \'Segoe UI\', system-ui, sans-serif;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .error-card {
                    background: #0a192e;
                    border: 1px solid rgba(239, 68, 68, 0.4);
                    border-radius: 24px;
                    padding: 45px 35px;
                    max-width: 520px;
                    text-align: center;
                    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.7);
                }
            </style>
        </head>
        <body>
            <div class="error-card">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-15 text-danger p-3" style="width: 86px; height: 86px; font-size: 2.8rem;">
                        <i class="bi bi-shield-lock-fill"></i>
                    </span>
                </div>
                <h3 class="fw-bold text-white mb-2">Acceso No Autorizado (403)</h3>
                <div class="badge bg-danger bg-opacity-25 text-danger border border-danger px-3 py-1 mb-3">Módulo: ' . htmlspecialchars($modulo_clave) . '</div>
                <p class="text-secondary small mb-4">
                    Tu usuario no tiene permisos asignados para ver o utilizar este módulo. Si requieres acceso para tus funciones, solicita la autorización al <strong>Administrador de Sistemas</strong> en Gestión de Usuarios.
                </p>
                <div class="d-grid gap-2">
                    <a href="menu.php" class="btn btn-primary rounded-3 py-2 fw-semibold">
                        <i class="bi bi-arrow-left me-2"></i>Volver al Menú Principal
                    </a>
                    <a href="logout.php" class="btn btn-outline-secondary rounded-3 py-2 text-white">
                        <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                    </a>
                </div>
            </div>
        </body>
        </html>';
        exit();
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
    } catch (Throwable $e) {}
}

/**
 * Asegura la existencia de la tabla citas_soporte
 */
function asegurarTablaCitas($pdo = null) {
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
                CREATE TABLE IF NOT EXISTS citas_soporte (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    folio TEXT,
                    agencia TEXT NOT NULL,
                    area_sistemas TEXT DEFAULT 'INFRAESTRUCTURA',
                    tipo_cita TEXT DEFAULT 'Presencial',
                    asunto TEXT NOT NULL,
                    descripcion TEXT,
                    solicitante_nombre TEXT,
                    solicitante_usuario TEXT,
                    solicitante_email TEXT,
                    tecnico_asignado TEXT,
                    fecha_cita DATE NOT NULL,
                    hora_cita TEXT NOT NULL,
                    estado TEXT DEFAULT 'Programada',
                    notas_atencion TEXT,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `citas_soporte` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `folio` VARCHAR(50),
                    `agencia` VARCHAR(100) NOT NULL,
                    `area_sistemas` VARCHAR(100) DEFAULT 'INFRAESTRUCTURA',
                    `tipo_cita` VARCHAR(50) DEFAULT 'Presencial',
                    `asunto` VARCHAR(255) NOT NULL,
                    `descripcion` TEXT,
                    `solicitante_nombre` VARCHAR(150),
                    `solicitante_usuario` VARCHAR(100),
                    `solicitante_email` VARCHAR(150),
                    `tecnico_asignado` VARCHAR(150),
                    `fecha_cita` DATE NOT NULL,
                    `hora_cita` VARCHAR(20) NOT NULL,
                    `estado` VARCHAR(50) DEFAULT 'Programada',
                    `notas_atencion` TEXT,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    } catch (Throwable $e) {}
}
?>
