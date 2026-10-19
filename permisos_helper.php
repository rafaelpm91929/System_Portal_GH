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

        // Asegurar columnas foto, cargo y telefono en usuarios
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto VARCHAR(255) NULL");
        } catch (Throwable $eFoto) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN cargo VARCHAR(100) NULL");
        } catch (Throwable $eCargo) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(50) NULL");
        } catch (Throwable $eTel) {}

        // Desactivar módulos obsoletos que ya no forman parte del portal
        try {
            $pdo->exec("UPDATE modulos SET estatus = 0 WHERE clave IN ('equipos', 'infraestructura', 'directorio', 'ordenes_servicio', 'celulares', 'licencias')");
        } catch (Throwable $tObs) {}

        // Sincronización y unificación de la clave oficial 'agencias'
        try {
            $pdo->exec("UPDATE modulos SET clave = 'agencias' WHERE clave = 'agencia'");
            $pdo->exec("UPDATE usuario_permisos SET modulo_clave = 'agencias' WHERE modulo_clave = 'agencia'");
        } catch (Throwable $tMig) {}

        // 4. Catálogo oficial y vigente de módulos del portal Grupo Huerta (6 módulos reales)
        $modulosBase = [
            ['usuarios',   'Gestión de Usuarios',                'Administración de cuentas, roles y permisos de acceso al portal',                'bi-people-fill',              1, 1],
            ['agencias',   'Agencias',                           'Catálogo de sucursales Grupo Huerta, bases de datos cPanel y encargados TI',     'bi-buildings-fill',           2, 1],
            ['tickets',    'Tickets Soporte Dirección Sistemas', 'Recepción y seguimiento de tickets para las áreas de Sistemas',                  'bi-ticket-detailed-fill',     3, 1],
            ['politicas',  'Políticas Corporativas',             'Políticas institucionales, reglamentos y normativas en visor blindado',          'bi-file-earmark-lock2-fill',  4, 1],
            ['compliance', 'Compliance',                         'Formatos oficiales, manuales operativos y avisos institucionales',               'bi-journal-check',            5, 1],
            ['area404',    'Área 404 (Gaming & Recreación)',     'Zona recreativa Grupo Huerta: Carreras 3D Gran Premio GH y Super Taller Run 2D', 'bi-controller',               6, 1]
        ];

        foreach ($modulosBase as $m) {
            $stmtCheck = $pdo->prepare("SELECT id FROM modulos WHERE clave = ?");
            $stmtCheck->execute([$m[0]]);
            if ($stmtCheck->fetch()) {
                $stmtUpd = $pdo->prepare("UPDATE modulos SET nombre=?, descripcion=?, icono=?, orden=?, estatus=1 WHERE clave=?");
                $stmtUpd->execute([$m[1], $m[2], $m[3], $m[4], $m[0]]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO modulos (clave, nombre, descripcion, icono, orden, estatus) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$m[0], $m[1], $m[2], $m[3], $m[4], $m[5]]);
            }
        }

    } catch (Throwable $e) {
        // Evitar interrumpir flujo si falla parcialmente
    }
}

/**
 * Obtiene el catálogo oficial de módulos del sistema, garantizando datos siempre.
 */
function obtenerCatalogoModulos($pdo = null) {
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM modulos WHERE estatus = 1 ORDER BY orden ASC");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if (!empty($rows)) {
                return $rows;
            }
        } catch (Throwable $e) {}
    }
    return [
        ['clave' => 'usuarios',   'nombre' => 'Gestión de Usuarios',                'descripcion' => 'Administración de cuentas, roles y permisos de acceso al portal',                'icono' => 'bi-people-fill',              'orden' => 1, 'estatus' => 1],
        ['clave' => 'agencias',   'nombre' => 'Agencias',                           'descripcion' => 'Catálogo de sucursales Grupo Huerta, bases de datos cPanel y encargados TI',     'icono' => 'bi-buildings-fill',           'orden' => 2, 'estatus' => 1],
        ['clave' => 'tickets',    'nombre' => 'Tickets Soporte Dirección Sistemas', 'descripcion' => 'Recepción y seguimiento de tickets para las áreas de Sistemas',                  'icono' => 'bi-ticket-detailed-fill',     'orden' => 3, 'estatus' => 1],
        ['clave' => 'politicas',  'nombre' => 'Políticas Corporativas',             'descripcion' => 'Políticas institucionales, reglamentos y normativas en visor blindado',          'icono' => 'bi-file-earmark-lock2-fill',  'orden' => 4, 'estatus' => 1],
        ['clave' => 'compliance', 'nombre' => 'Compliance',                         'descripcion' => 'Formatos oficiales, manuales operativos y avisos institucionales',               'icono' => 'bi-journal-check',            'orden' => 5, 'estatus' => 1],
        ['clave' => 'area404',    'nombre' => 'Área 404 (Gaming & Recreación)',     'descripcion' => 'Zona recreativa Grupo Huerta: Carreras 3D Gran Premio GH y Super Taller Run 2D', 'icono' => 'bi-controller',               'orden' => 6, 'estatus' => 1]
    ];
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
            $pData = [
                'puede_ver'      => (int)$row['puede_ver'],
                'puede_crear'    => (int)$row['puede_crear'],
                'puede_editar'   => (int)$row['puede_editar'],
                'puede_eliminar' => (int)$row['puede_eliminar'],
                'puede_exportar' => (int)$row['puede_exportar']
            ];
            $_SESSION['permisos'][$row['modulo_clave']] = $pData;

            // Aliasing transparente para compatibilidad bidireccional agencia / agencias
            if ($row['modulo_clave'] === 'agencia') {
                $_SESSION['permisos']['agencias'] = $pData;
            } elseif ($row['modulo_clave'] === 'agencias') {
                $_SESSION['permisos']['agencia'] = $pData;
            }
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

    // Normalizar claves si es necesario (ej: agencia / agencias)
    $claves = [$modulo_clave];
    if ($modulo_clave === 'agencia') $claves[] = 'agencias';
    if ($modulo_clave === 'agencias') $claves[] = 'agencia';

    // Verificar en la matriz de permisos de la sesión
    $permisos = $_SESSION['permisos'] ?? [];
    foreach ($claves as $k) {
        if (isset($permisos[$k])) {
            return !empty($permisos[$k][$accion]);
        }
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

        // Migración automática de columna evidencia_resolucion
        try {
            $pdo->exec("ALTER TABLE tickets_soporte ADD COLUMN evidencia_resolucion VARCHAR(255) NULL;");
        } catch (Throwable $eAlt2) {}

        // Migración automática de columnas para Reporte de Servicio y Firma Digital (Infraestructura)
        $columnasReporte = [
            'requiere_firma_reporte'      => "TINYINT(1) DEFAULT 0",
            'reporte_orden_no'            => "VARCHAR(50) NULL",
            'reporte_problema'            => "TEXT NULL",
            'reporte_trabajo_solicitado'  => "TEXT NULL",
            'reporte_observaciones'       => "TEXT NULL",
            'reporte_trabajos_realizados' => "TEXT NULL",
            'reporte_tecnico_nombre'      => "VARCHAR(150) NULL",
            'reporte_firmado'             => "TINYINT(1) DEFAULT 0",
            'reporte_firma_digital'       => ($driver === 'sqlite' ? "TEXT NULL" : "MEDIUMTEXT NULL"),
            'reporte_firmado_por'         => "VARCHAR(150) NULL",
            'reporte_firmado_en'          => "DATETIME NULL"
        ];
        foreach ($columnasReporte as $col => $tipoCol) {
            try {
                $pdo->exec("ALTER TABLE tickets_soporte ADD COLUMN `{$col}` {$tipoCol};");
            } catch (Throwable $eCol) {
                try {
                    $pdo->exec("ALTER TABLE tickets_soporte ADD COLUMN {$col} {$tipoCol};");
                } catch (Throwable $eCol2) {}
            }
        }

        // Tabla de mensajes / contestaciones de chat de soporte
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS tickets_mensajes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ticket_id INTEGER NOT NULL,
                    autor_id INTEGER NULL,
                    autor_usuario TEXT NULL,
                    autor_nombre TEXT NOT NULL,
                    autor_email TEXT NULL,
                    autor_rol TEXT DEFAULT 'usuario',
                    autor_agencia TEXT NULL,
                    mensaje TEXT NOT NULL,
                    archivo_adjunto TEXT NULL,
                    es_nota_interna INTEGER DEFAULT 0,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `tickets_mensajes` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `ticket_id` INT NOT NULL,
                    `autor_id` INT NULL,
                    `autor_usuario` VARCHAR(100) NULL,
                    `autor_nombre` VARCHAR(150) NOT NULL,
                    `autor_email` VARCHAR(150) NULL,
                    `autor_rol` VARCHAR(50) DEFAULT 'usuario',
                    `autor_agencia` VARCHAR(150) NULL,
                    `mensaje` TEXT NOT NULL,
                    `archivo_adjunto` VARCHAR(255) NULL,
                    `es_nota_interna` TINYINT(1) DEFAULT 0,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_tk_msg_id` (`ticket_id`),
                    INDEX `idx_tk_msg_fecha` (`creado_en`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
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
                    ticket_id INTEGER NULL,
                    ticket_folio TEXT NULL,
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
                    motivo_reprogramacion TEXT NULL,
                    historial_cambios TEXT NULL,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `citas_soporte` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `folio` VARCHAR(50),
                    `ticket_id` INT NULL,
                    `ticket_folio` VARCHAR(50) NULL,
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
                    `motivo_reprogramacion` TEXT NULL,
                    `historial_cambios` TEXT NULL,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // Auto-migración si la tabla ya existía previamente
        $cols = [];
        try {
            $stmtCols = $pdo->query("PRAGMA table_info(citas_soporte)");
            if ($stmtCols) {
                while ($r = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = strtolower($r['name'] ?? '');
                }
            }
        } catch (Throwable $eColsSqlite) {
            try {
                $stmtCols = $pdo->query("SHOW COLUMNS FROM citas_soporte");
                if ($stmtCols) {
                    while ($r = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                        $cols[] = strtolower($r['Field'] ?? '');
                    }
                }
            } catch (Throwable $eColsMysql) {}
        }

        if (!empty($cols)) {
            if (!in_array('ticket_id', $cols)) {
                @$pdo->exec("ALTER TABLE citas_soporte ADD COLUMN ticket_id INT NULL");
            }
            if (!in_array('ticket_folio', $cols)) {
                @$pdo->exec("ALTER TABLE citas_soporte ADD COLUMN ticket_folio VARCHAR(50) NULL");
            }
            if (!in_array('motivo_reprogramacion', $cols)) {
                @$pdo->exec("ALTER TABLE citas_soporte ADD COLUMN motivo_reprogramacion TEXT NULL");
            }
            if (!in_array('historial_cambios', $cols)) {
                @$pdo->exec("ALTER TABLE citas_soporte ADD COLUMN historial_cambios TEXT NULL");
            }
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
                    area TEXT,
                    subarea TEXT,
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
                    origen TEXT DEFAULT 'Portal Central GH',
                    fecha_lectura DATETIME DEFAULT CURRENT_TIMESTAMP,
                    fecha_fin DATETIME NULL,
                    duracion_segundos INTEGER DEFAULT 0
                );
            ");
            // Auto-migración SQLite: Asegurar columnas en caso de tabla preexistente
            $colsSqlite = ['area', 'subarea', 'categoria', 'version', 'fecha_vigencia', 'obligatorio_lectura', 'creado_por', 'estatus'];
            foreach ($colsSqlite as $col) {
                try {
                    $pdo->exec("ALTER TABLE politicas_corporativas ADD COLUMN {$col} TEXT");
                } catch (Throwable $t) {}
            }
            try { $pdo->exec("ALTER TABLE politicas_lecturas ADD COLUMN fecha_fin DATETIME NULL"); } catch (Throwable $t) {}
            try { $pdo->exec("ALTER TABLE politicas_lecturas ADD COLUMN duracion_segundos INTEGER DEFAULT 0"); } catch (Throwable $t) {}
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `politicas_corporativas` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `titulo` VARCHAR(255) NOT NULL,
                    `descripcion` TEXT NULL,
                    `categoria` VARCHAR(100) DEFAULT 'General',
                    `area` VARCHAR(100) NULL,
                    `subarea` VARCHAR(100) NULL,
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
                    `origen` VARCHAR(50) DEFAULT 'Portal Central GH',
                    `fecha_lectura` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `fecha_fin` DATETIME NULL,
                    `duracion_segundos` INT DEFAULT 0,
                    INDEX `idx_politica` (`politica_id`),
                    INDEX `idx_usuario` (`usuario_login`),
                    INDEX `idx_fecha` (`fecha_lectura`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            try { $pdo->exec("ALTER TABLE `politicas_corporativas` ADD COLUMN `area` VARCHAR(100) NULL"); } catch (Throwable $t) {}
            try { $pdo->exec("ALTER TABLE `politicas_corporativas` ADD COLUMN `subarea` VARCHAR(100) NULL"); } catch (Throwable $t) {}
            try { $pdo->exec("ALTER TABLE `politicas_lecturas` ADD COLUMN `fecha_fin` DATETIME NULL"); } catch (Throwable $t) {}
            try { $pdo->exec("ALTER TABLE `politicas_lecturas` ADD COLUMN `duracion_segundos` INT DEFAULT 0"); } catch (Throwable $t) {}
        }

        // Auto-ajuste de zona horaria única vez para registros históricos guardados previamente en UTC (+00:00 -> -06:00 CDMX)
        $flagFileTz = __DIR__ . '/.tz_migrated_mx_2026';
        if (!file_exists($flagFileTz)) {
            try {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    $pdo->exec("
                        UPDATE politicas_lecturas 
                        SET fecha_lectura = DATE_SUB(fecha_lectura, INTERVAL 6 HOUR),
                            fecha_fin = CASE WHEN fecha_fin IS NOT NULL THEN DATE_SUB(fecha_fin, INTERVAL 6 HOUR) ELSE NULL END
                        WHERE fecha_lectura IS NOT NULL
                    ");
                } else {
                    $pdo->exec("
                        UPDATE politicas_lecturas 
                        SET fecha_lectura = datetime(fecha_lectura, '-6 hours'),
                            fecha_fin = CASE WHEN fecha_fin IS NOT NULL THEN datetime(fecha_fin, '-6 hours') ELSE NULL END
                        WHERE fecha_lectura IS NOT NULL
                    ");
                }
                @file_put_contents($flagFileTz, date('Y-m-d H:i:s'));
            } catch (Throwable $tzMig) {}
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
                    imagen_url TEXT,
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
                    origen TEXT DEFAULT 'Portal Central GH',
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
                    `imagen_url` VARCHAR(255) NULL,
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
                    `origen` VARCHAR(50) DEFAULT 'Portal Central GH',
                    `fecha_lectura` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_comp_doc` (`documento_id`),
                    INDEX `idx_comp_usr` (`usuario_login`),
                    INDEX `idx_comp_fec` (`fecha_lectura`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        try { $pdo->exec("ALTER TABLE compliance_documentos ADD COLUMN imagen_url VARCHAR(255) NULL"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE compliance_documentos ADD COLUMN estilo_fondo VARCHAR(50) DEFAULT 'negro_oro'"); } catch (Throwable $t) {}
        try { $pdo->exec("ALTER TABLE compliance_documentos CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $t) {}
        try {
            $pdo->exec("UPDATE compliance_documentos SET imagen_url = 'uploads/compliance/aviso_AV-01.svg' WHERE codigo = 'AV-01' AND (imagen_url IS NULL OR imagen_url = '')");
            $pdo->exec("UPDATE compliance_documentos SET imagen_url = 'uploads/compliance/aviso_AV-02.svg' WHERE codigo = 'AV-02' AND (imagen_url IS NULL OR imagen_url = '')");
            $pdo->exec("UPDATE compliance_documentos SET imagen_url = 'uploads/compliance/aviso_AV-03.svg' WHERE codigo = 'AV-03' AND (imagen_url IS NULL OR imagen_url = '')");
            $pdo->exec("UPDATE compliance_documentos SET imagen_url = 'uploads/compliance/aviso_AV-04.svg' WHERE codigo = 'AV-04' AND (imagen_url IS NULL OR imagen_url = '')");
        } catch (Throwable $t) {}

        // Sembrado inicial si está vacía
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
 * Genera una tarjeta gráfica vectorial oficial (SVG 1200x675) para un Aviso o Comunicado Institucional.
 * Convierte el texto y descripción en una imagen institucional de alta resolución con sellos corporativos.
 */
function generarImagenAvisoSVG($titulo, $descripcion, $prioridad = 'Alta', $categoria = 'General', $codigo = 'AV-01', $fecha = null, $emisor = 'Dirección General', $estiloFondo = 'negro_oro') {
    if (!$fecha) $fecha = date('d/m/Y');
    
    $badgeBg = '#ef4444';
    $badgeText = '#ffffff';
    $badgeLabel = 'PRIORIDAD ALTA';
    if ($prioridad === 'Media') {
        $badgeBg = '#f59e0b';
        $badgeLabel = 'PRIORIDAD MEDIA';
    } elseif ($prioridad === 'Normal') {
        $badgeBg = '#0284c7';
        $badgeLabel = 'COMUNICADO OFICIAL';
    } elseif ($prioridad === 'Urgente') {
        $badgeBg = '#b91c1c';
        $badgeLabel = 'COMUNICADO URGENTE';
    }

    // Configuración de Paletas de Fondo Estilo Facebook
    switch ($estiloFondo) {
        case 'azul_zafiro':
            $bgStop1 = '#031024'; $bgStop2 = '#0a254d'; $bgStop3 = '#020914';
            $cardStop1 = 'rgba(10, 37, 77, 0.88)'; $cardStop2 = 'rgba(3, 16, 36, 0.95)';
            $goldColor = '#38bdf8'; $goldLight = '#bae6fd';
            $strokeBorder = 'rgba(56, 189, 248, 0.45)';
            break;
        case 'rojo_rubi':
            $bgStop1 = '#200505'; $bgStop2 = '#450f0f'; $bgStop3 = '#110202';
            $cardStop1 = 'rgba(69, 15, 15, 0.88)'; $cardStop2 = 'rgba(17, 2, 2, 0.95)';
            $goldColor = '#f87171'; $goldLight = '#fecaca';
            $strokeBorder = 'rgba(248, 113, 113, 0.45)';
            break;
        case 'esmeralda':
            $bgStop1 = '#031a11'; $bgStop2 = '#0a3a27'; $bgStop3 = '#020f09';
            $cardStop1 = 'rgba(10, 58, 39, 0.88)'; $cardStop2 = 'rgba(2, 15, 9, 0.95)';
            $goldColor = '#34d399'; $goldLight = '#a7f3d0';
            $strokeBorder = 'rgba(52, 211, 153, 0.45)';
            break;
        case 'purpura':
            $bgStop1 = '#13061d'; $bgStop2 = '#2d1242'; $bgStop3 = '#0a0210';
            $cardStop1 = 'rgba(45, 18, 66, 0.88)'; $cardStop2 = 'rgba(10, 2, 16, 0.95)';
            $goldColor = '#c084fc'; $goldLight = '#f3e8ff';
            $strokeBorder = 'rgba(192, 132, 252, 0.45)';
            break;
        case 'negro_oro':
        default:
            $bgStop1 = '#040d1a'; $bgStop2 = '#081a33'; $bgStop3 = '#020812';
            $cardStop1 = 'rgba(11, 26, 48, 0.88)'; $cardStop2 = 'rgba(4, 13, 26, 0.95)';
            $goldColor = '#d4af37'; $goldLight = '#f5df9e';
            $strokeBorder = 'rgba(212, 175, 55, 0.45)';
            break;
    }

    // Procesar título con salto de línea automático
    $palabrasTit = explode(' ', trim($titulo));
    $tLines = [];
    $currTit = '';
    foreach ($palabrasTit as $w) {
        if ($w === '') continue;
        if (mb_strlen($currTit . ' ' . $w) <= 36) {
            $currTit = trim($currTit . ' ' . $w);
        } else {
            if ($currTit !== '') $tLines[] = $currTit;
            $currTit = $w;
        }
    }
    if ($currTit !== '') $tLines[] = $currTit;
    $tLines = array_slice($tLines, 0, 3);

    // Procesar descripción respetando párrafos y saltos de línea del usuario
    $parrafos = preg_split('/\r\n|\r|\n/', trim($descripcion));
    $dLines = [];
    $maxCharsLine = 66;

    foreach ($parrafos as $p) {
        $p = trim($p);
        if ($p === '') {
            if (!empty($dLines) && end($dLines) !== '') {
                $dLines[] = ''; // Espacio en blanco entre párrafos
            }
            continue;
        }
        $palabras = explode(' ', $p);
        $curr = '';
        foreach ($palabras as $word) {
            if ($word === '') continue;
            if (mb_strlen($curr . ' ' . $word) <= $maxCharsLine) {
                $curr = trim($curr . ' ' . $word);
            } else {
                if ($curr !== '') $dLines[] = $curr;
                $curr = $word;
            }
        }
        if ($curr !== '') $dLines[] = $curr;
    }

    // Ajuste dinámico de tamaño de fuente estilo Facebook
    $cantLineasDesc = count($dLines);
    $dLines = array_slice($dLines, 0, 11);
    
    if ($cantLineasDesc <= 2 && mb_strlen($descripcion) <= 120) {
        $fontSizeDesc = 24;
        $lineHeightDesc = 38;
    } elseif ($cantLineasDesc <= 4) {
        $fontSizeDesc = 21;
        $lineHeightDesc = 34;
    } elseif ($cantLineasDesc > 7) {
        $fontSizeDesc = 16;
        $lineHeightDesc = 26;
    } else {
        $fontSizeDesc = 18;
        $lineHeightDesc = 30;
    }

    // Escapado seguro para SVG/XML
    $xmlEsc = function($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
    };

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 675" width="1200" height="675">' . "\n";
    $svg .= '  <defs>' . "\n";
    $svg .= '    <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">' . "\n";
    $svg .= '      <stop offset="0%" stop-color="' . $bgStop1 . '" />' . "\n";
    $svg .= '      <stop offset="50%" stop-color="' . $bgStop2 . '" />' . "\n";
    $svg .= '      <stop offset="100%" stop-color="' . $bgStop3 . '" />' . "\n";
    $svg .= '    </linearGradient>' . "\n";
    $svg .= '    <linearGradient id="cardGrad" x1="0%" y1="0%" x2="0%" y2="100%">' . "\n";
    $svg .= '      <stop offset="0%" stop-color="' . $cardStop1 . '" />' . "\n";
    $svg .= '      <stop offset="100%" stop-color="' . $cardStop2 . '" />' . "\n";
    $svg .= '    </linearGradient>' . "\n";
    $svg .= '    <linearGradient id="goldGrad" x1="0%" y1="0%" x2="100%" y2="0%">' . "\n";
    $svg .= '      <stop offset="0%" stop-color="' . $goldColor . '" />' . "\n";
    $svg .= '      <stop offset="50%" stop-color="' . $goldLight . '" />' . "\n";
    $svg .= '      <stop offset="100%" stop-color="' . $goldColor . '" />' . "\n";
    $svg .= '    </linearGradient>' . "\n";
    $svg .= '  </defs>' . "\n";

    // Fondo y marcos decorativos
    $svg .= '  <rect width="1200" height="675" fill="url(#bgGrad)" />' . "\n";
    $svg .= '  <rect x="25" y="25" width="1150" height="625" rx="20" fill="none" stroke="' . $strokeBorder . '" stroke-width="2" />' . "\n";
    $svg .= '  <rect x="35" y="35" width="1130" height="605" rx="14" fill="none" stroke="rgba(255, 255, 255, 0.08)" stroke-width="1" />' . "\n";

    // Cantoneras ornamentales
    $svg .= '  <path d="M 25 65 L 25 25 L 65 25" fill="none" stroke="' . $goldColor . '" stroke-width="4" />' . "\n";
    $svg .= '  <path d="M 1175 65 L 1175 25 L 1135 25" fill="none" stroke="' . $goldColor . '" stroke-width="4" />' . "\n";
    $svg .= '  <path d="M 25 610 L 25 650 L 65 650" fill="none" stroke="' . $goldColor . '" stroke-width="4" />' . "\n";
    $svg .= '  <path d="M 1175 610 L 1175 650 L 1135 650" fill="none" stroke="' . $goldColor . '" stroke-width="4" />' . "\n";

    // Membrete Superior: Marca Grupo Huerta
    $svg .= '  <g transform="translate(60, 60)">' . "\n";
    $svg .= '    <path d="M 0 0 L 24 -8 L 48 0 L 48 24 Q 48 48 24 58 Q 0 48 0 24 Z" fill="rgba(255, 255, 255, 0.15)" stroke="' . $goldColor . '" stroke-width="2" />' . "\n";
    $svg .= '    <path d="M 24 16 L 24 40 M 16 28 L 32 28" stroke="' . $goldLight . '" stroke-width="2.5" stroke-linecap="round" />' . "\n";
    $svg .= '    <text x="65" y="24" font-family="Plus Jakarta Sans, sans-serif" font-size="20" font-weight="800" fill="#ffffff" letter-spacing="2">GRUPO HUERTA</text>' . "\n";
    $svg .= '    <text x="65" y="46" font-family="Plus Jakarta Sans, sans-serif" font-size="12" font-weight="600" fill="#94a3b8" letter-spacing="1">GOBIERNO CORPORATIVO &amp; COMPLIANCE INSTITUCIONAL</text>' . "\n";
    $svg .= '  </g>' . "\n";

    // Badge de Prioridad
    $svg .= '  <g transform="translate(860, 60)">' . "\n";
    $svg .= '    <rect x="0" y="0" width="280" height="42" rx="10" fill="' . $badgeBg . '" />' . "\n";
    $svg .= '    <text x="140" y="26" font-family="Plus Jakarta Sans, sans-serif" font-size="14" font-weight="800" fill="' . $badgeText . '" text-anchor="middle" letter-spacing="1">' . $xmlEsc($badgeLabel) . '</text>' . "\n";
    $svg .= '  </g>' . "\n";

    // Divisor
    $svg .= '  <line x1="60" y1="130" x2="1140" y2="130" stroke="url(#goldGrad)" stroke-width="2" />' . "\n";

    // Metadatos: Código, Categoría y Fecha
    $svg .= '  <g transform="translate(60, 160)">' . "\n";
    $svg .= '    <rect x="0" y="0" width="130" height="30" rx="6" fill="rgba(255, 255, 255, 0.1)" stroke="' . $goldColor . '" stroke-width="1" />' . "\n";
    $svg .= '    <text x="65" y="20" font-family="Plus Jakarta Sans, sans-serif" font-size="13" font-weight="800" fill="' . $goldLight . '" text-anchor="middle">' . $xmlEsc($codigo) . '</text>' . "\n";
    $svg .= '    <text x="150" y="20" font-family="Plus Jakarta Sans, sans-serif" font-size="14" font-weight="700" fill="#38bdf8">&#8226; ' . $xmlEsc($categoria) . '</text>' . "\n";
    $svg .= '    <text x="1080" y="20" font-family="Plus Jakarta Sans, sans-serif" font-size="13" font-weight="500" fill="#94a3b8" text-anchor="end">Fecha de Emisión: ' . $xmlEsc($fecha) . '</text>' . "\n";
    $svg .= '  </g>' . "\n";

    // Contenedor interno estilizado para el contenido
    $svg .= '  <rect x="55" y="205" width="1090" height="375" rx="14" fill="url(#cardGrad)" stroke="' . $strokeBorder . '" stroke-width="1.2" />' . "\n";

    // Título Oficial en la Tarjeta
    $yTitulo = 250;
    $svg .= '  <text x="85" y="' . $yTitulo . '" font-family="Plus Jakarta Sans, sans-serif" font-size="30" font-weight="800" fill="#ffffff" letter-spacing="0.3">' . "\n";
    foreach ($tLines as $idx => $lt) {
        $dy = $idx === 0 ? 0 : 38;
        $svg .= '    <tspan x="85" dy="' . $dy . '">' . $xmlEsc($lt) . '</tspan>' . "\n";
    }
    $svg .= '  </text>' . "\n";

    // Texto del Aviso / Descripción
    $yDesc = $yTitulo + (count($tLines) * 38) + 20;
    $svg .= '  <text x="85" y="' . $yDesc . '" font-family="Plus Jakarta Sans, sans-serif" font-size="' . $fontSizeDesc . '" font-weight="400" fill="#e2e8f0" letter-spacing="0.2">' . "\n";
    foreach ($dLines as $idx => $ld) {
        $dy = $idx === 0 ? 0 : $lineHeightDesc;
        if ($ld === '') {
            $svg .= '    <tspan x="85" dy="' . ($lineHeightDesc * 0.6) . '"> </tspan>' . "\n";
        } else {
            $svg .= '    <tspan x="85" dy="' . $dy . '">' . $xmlEsc($ld) . '</tspan>' . "\n";
        }
    }
    $svg .= '  </text>' . "\n";

    // Pie institucional
    $svg .= '  <line x1="60" y1="595" x2="1140" y2="595" stroke="' . $strokeBorder . '" stroke-width="1" />' . "\n";
    $svg .= '  <g transform="translate(60, 625)">' . "\n";
    $svg .= '    <text x="0" y="0" font-family="Plus Jakarta Sans, sans-serif" font-size="13" font-weight="700" fill="' . $goldColor . '">EMISOR: ' . $xmlEsc($emisor) . '</text>' . "\n";
    $svg .= '    <text x="1080" y="0" font-family="Plus Jakarta Sans, sans-serif" font-size="12" font-weight="600" fill="#64748b" text-anchor="end">COMUNICADO OFICIAL AUDITADO &#8226; GRUPO HUERTA</text>' . "\n";
    $svg .= '  </g>' . "\n";

    $svg .= '</svg>' . "\n";
    return $svg;
}

/**
 * Retorna la clase CSS de degradado/color para la tarjeta de aviso según el estilo guardado,
 * inspección de SVG o prioridad corporativa.
 */
function obtenerClaseFondoAviso($aviso) {
    $estilo = trim($aviso['estilo_fondo'] ?? '');
    if ($estilo === 'rojo_rubi' || $estilo === 'rojo') return 'swatch-rojo';
    if ($estilo === 'azul_zafiro' || $estilo === 'azul') return 'swatch-azul';
    if ($estilo === 'esmeralda' || $estilo === 'verde') return 'swatch-verde';
    if ($estilo === 'purpura') return 'swatch-purpura';
    if ($estilo === 'negro_oro' || $estilo === 'negro') return 'swatch-negro';

    // Si tiene archivo SVG generado en disco, leer el color guardado
    $img = $aviso['imagen_url'] ?? '';
    if (!empty($img) && strpos($img, '.svg') !== false && file_exists(__DIR__ . '/' . $img)) {
        $svgRaw = @file_get_contents(__DIR__ . '/' . $img);
        if ($svgRaw) {
            if (strpos($svgRaw, '#400b11') !== false || strpos($svgRaw, '#70131e') !== false) return 'swatch-rojo';
            if (strpos($svgRaw, '#0a1e3f') !== false || strpos($svgRaw, '#0d3b66') !== false) return 'swatch-azul';
            if (strpos($svgRaw, '#092b1a') !== false || strpos($svgRaw, '#0f5132') !== false) return 'swatch-verde';
            if (strpos($svgRaw, '#230c33') !== false || strpos($svgRaw, '#4a154b') !== false) return 'swatch-purpura';
            if (strpos($svgRaw, '#050b14') !== false || strpos($svgRaw, '#101c30') !== false) return 'swatch-negro';
        }
    }

    // Fallback por prioridad y categoría
    $prio = strtolower(trim($aviso['prioridad'] ?? 'normal'));
    if ($prio === 'alta' || $prio === 'urgente') return 'swatch-rojo';
    if ($prio === 'media') return 'swatch-azul';

    $cat = strtolower(trim($aviso['categoria'] ?? ''));
    if (strpos($cat, 'ciber') !== false) return 'swatch-azul';
    if (strpos($cat, 'legal') !== false || strpos($cat, 'alta') !== false) return 'swatch-rojo';
    if (strpos($cat, 'normat') !== false || strpos($cat, 'ecolog') !== false) return 'swatch-verde';

    return 'swatch-negro';
}

/**
 * Auto-Instala o asegura la existencia de la tabla notificaciones y notificaciones_lecturas
 */
function asegurarTablaNotificaciones($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $driver = '';
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: '');
        } catch (Throwable $t) {}

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notificaciones (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    modulo VARCHAR(50) NOT NULL,
                    tipo VARCHAR(50) NOT NULL,
                    titulo VARCHAR(255) NOT NULL,
                    mensaje TEXT NOT NULL,
                    enlace VARCHAR(255) DEFAULT '',
                    icono VARCHAR(50) DEFAULT 'bi-bell-fill',
                    color VARCHAR(30) DEFAULT 'primary',
                    referencia_id INTEGER NULL,
                    usuario_id INTEGER NULL,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    estatus INTEGER DEFAULT 1
                );
                CREATE TABLE IF NOT EXISTS notificaciones_lecturas (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    notificacion_id INTEGER NOT NULL,
                    usuario_id INTEGER NOT NULL,
                    leido_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (notificacion_id, usuario_id)
                );
            ");
            try { $pdo->exec("ALTER TABLE notificaciones ADD COLUMN usuario_id INTEGER NULL"); } catch (Throwable $tCol) {}
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `notificaciones` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `modulo` VARCHAR(50) NOT NULL,
                    `tipo` VARCHAR(50) NOT NULL,
                    `titulo` VARCHAR(255) NOT NULL,
                    `mensaje` TEXT NOT NULL,
                    `enlace` VARCHAR(255) DEFAULT '',
                    `icono` VARCHAR(50) DEFAULT 'bi-bell-fill',
                    `color` VARCHAR(30) DEFAULT 'primary',
                    `referencia_id` INT NULL,
                    `usuario_id` INT NULL,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `estatus` TINYINT(1) DEFAULT 1,
                    INDEX `idx_notif_modulo` (`modulo`),
                    INDEX `idx_notif_estatus` (`estatus`),
                    INDEX `idx_notif_usuario_id` (`usuario_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `notificaciones_lecturas` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `notificacion_id` INT NOT NULL,
                    `usuario_id` INT NOT NULL,
                    `leido_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_notif_usuario` (`notificacion_id`, `usuario_id`),
                    INDEX `idx_notif_usr` (`usuario_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            try { $pdo->exec("ALTER TABLE `notificaciones` ADD COLUMN `usuario_id` INT NULL"); } catch (Throwable $tColMy) {}
            try { $pdo->exec("ALTER TABLE `notificaciones` ADD INDEX `idx_notif_usuario_id` (`usuario_id`)"); } catch (Throwable $tIdxMy) {}
        }

        // Sembrar notificaciones iniciales a partir de políticas existentes si no hay registros
        try {
            $stmtCount = $pdo->query("SELECT COUNT(*) FROM notificaciones WHERE modulo = 'politicas'");
            $totalNotifPol = $stmtCount ? (int)$stmtCount->fetchColumn() : 0;
            if ($totalNotifPol === 0) {
                $stmtPol = $pdo->query("SELECT id, titulo, COALESCE(area, categoria) as area_nom, version, creado_en FROM politicas_corporativas WHERE estatus = 1 ORDER BY id DESC LIMIT 5");
                if ($stmtPol) {
                    $pols = $stmtPol->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($pols as $p) {
                        $fec = !empty($p['creado_en']) ? $p['creado_en'] : date('Y-m-d H:i:s');
                        $stmtInsN = $pdo->prepare("
                            INSERT INTO notificaciones (modulo, tipo, titulo, mensaje, enlace, icono, color, referencia_id, creado_en, estatus)
                            VALUES ('politicas', 'nueva_politica', 'Nueva Política Publicada', ?, 'politicas.php', 'bi-shield-shaded', '#9333ea', ?, ?, 1)
                        ");
                        $stmtInsN->execute([
                            'Se subió nueva política oficial: ' . $p['titulo'] . ' (' . ($p['area_nom'] ?: 'General') . ')',
                            $p['id'],
                            $fec
                        ]);
                    }
                }
            }
        } catch (Throwable $eSeed) {}

    } catch (Throwable $e) {}
}

/**
 * Registra una nueva notificación en el sistema (Global o específica para un usuario)
 */
function crearNotificacion($modulo, $tipo, $titulo, $mensaje, $enlace = '', $icono = 'bi-bell-fill', $color = 'primary', $referenciaId = null, $usuarioId = null, $pdo = null) {
    if ($usuarioId instanceof PDO) {
        $pdo = $usuarioId;
        $usuarioId = null;
    }
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return false;
    asegurarTablaNotificaciones($pdo);
    try {
        $targetUsr = ($usuarioId !== null && (int)$usuarioId > 0) ? (int)$usuarioId : null;
        $stmt = $pdo->prepare("
            INSERT INTO notificaciones (modulo, tipo, titulo, mensaje, enlace, icono, color, referencia_id, usuario_id, creado_en, estatus)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        return $stmt->execute([
            $modulo,
            $tipo,
            $titulo,
            $mensaje,
            $enlace,
            $icono,
            $color,
            $referenciaId,
            $targetUsr,
            date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Obtiene las notificaciones disponibles para el usuario actual respetando sus permisos y notificaciones privadas
 */
function obtenerNotificacionesUsuario($usuarioId = null, $limite = 30, $pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return ['notificaciones' => [], 'total_no_leidas' => 0];
    asegurarTablaNotificaciones($pdo);

    if ($usuarioId === null) {
        $usuarioId = $_SESSION['usuario_id'] ?? 0;
    }
    $usuarioId = (int)$usuarioId;

    try {
        $sql = "
            SELECT n.*, 
                   CASE WHEN nl.id IS NOT NULL THEN 1 ELSE 0 END AS leido,
                   nl.leido_en
            FROM notificaciones n
            LEFT JOIN notificaciones_lecturas nl 
                   ON nl.notificacion_id = n.id AND nl.usuario_id = ?
            WHERE n.estatus = 1
              AND (n.usuario_id IS NULL OR n.usuario_id = ?)
            ORDER BY n.creado_en DESC, n.id DESC
            LIMIT " . (int)$limite;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuarioId, $usuarioId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $permitidas = [];
        $noLeidas = 0;

        foreach ($rows as $r) {
            $modulo = trim($r['modulo'] ?? '');
            $targetUsr = $r['usuario_id'] ?? null;
            
            // VERIFICACIÓN ESTRICTA DE PERMISOS:
            // Si la notificación pertenece a un módulo y el usuario no tiene permiso de ver dicho módulo,
            // pero la notificación va dirigida específicamente a él (ej. su ticket propio), se le permite ver.
            if ($targetUsr === null || $targetUsr === '') {
                if (!empty($modulo) && !tienePermiso($modulo, 'puede_ver')) {
                    continue;
                }
            } else {
                if ((int)$targetUsr !== $usuarioId) {
                    continue;
                }
            }

            $esLeido = !empty($r['leido']);
            if (!$esLeido) {
                $noLeidas++;
            }

            $tiempo = 'Reciente';
            if (!empty($r['creado_en'])) {
                $ts = strtotime($r['creado_en']);
                $diff = time() - $ts;
                if ($diff < 60) {
                    $tiempo = 'Hace un momento';
                } elseif ($diff < 3600) {
                    $mins = max(1, floor($diff / 60));
                    $tiempo = "Hace {$mins} min";
                } elseif ($diff < 86400) {
                    $hrs = floor($diff / 3600);
                    $tiempo = "Hace {$hrs} h";
                } else {
                    $dias = floor($diff / 86400);
                    if ($dias < 7) {
                        $tiempo = "Hace {$dias} d";
                    } else {
                        $tiempo = date('d/m/Y', $ts);
                    }
                }
            }
            $r['tiempo_relativo'] = $tiempo;
            $permitidas[] = $r;
        }

        return [
            'notificaciones' => $permitidas,
            'total_no_leidas' => $noLeidas
        ];
    } catch (Throwable $e) {
        return ['notificaciones' => [], 'total_no_leidas' => 0];
    }
}

/**
 * Marca una notificación como leída para un usuario
 */
function marcarNotificacionLeida($notificacionId, $usuarioId = null, $pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return false;
    asegurarTablaNotificaciones($pdo);

    if ($usuarioId === null) {
        $usuarioId = $_SESSION['usuario_id'] ?? 0;
    }
    $usuarioId = (int)$usuarioId;
    $notificacionId = (int)$notificacionId;
    if ($notificacionId <= 0 || $usuarioId <= 0) return false;

    try {
        $driver = '';
        try { $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: ''); } catch (Throwable $t) {}

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO notificaciones_lecturas (notificacion_id, usuario_id, leido_en) VALUES (?, ?, ?)");
        } else {
            $stmt = $pdo->prepare("INSERT INTO notificaciones_lecturas (notificacion_id, usuario_id, leido_en) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE leido_en = VALUES(leido_en)");
        }
        return $stmt->execute([$notificacionId, $usuarioId, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Marca todas las notificaciones permitidas como leídas para un usuario
 */
function marcarTodasNotificacionesLeidas($usuarioId = null, $pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return false;
    $res = obtenerNotificacionesUsuario($usuarioId, 100, $pdo);
    $notifs = $res['notificaciones'] ?? [];
    foreach ($notifs as $n) {
        if (empty($n['leido'])) {
            marcarNotificacionLeida($n['id'], $usuarioId, $pdo);
        }
    }
    return true;
}

/**
 * Asegura las columnas extendidas para la ficha y foto de los agentes en la tabla usuarios
 */
function asegurarColumnasUsuarios($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return;
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto VARCHAR(255) NULL");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN cargo VARCHAR(100) NULL");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(50) NULL");
    } catch (Throwable $e) {}
}

/**
 * Obtiene el registro completo de un agente de soporte desde la tabla usuarios
 */
function obtenerDatosAgente($pdo, $nombreOAgente) {
    if (!$pdo || empty(trim($nombreOAgente))) return null;
    $termino = trim($nombreOAgente);
    asegurarColumnasUsuarios($pdo);

    // 1. Si es numérico (ID de usuario)
    if (is_numeric($termino) && intval($termino) > 0) {
        $stmt = $pdo->prepare("SELECT id, usuario, nombre, email, rol, agencia, activo, areas_tickets, foto, cargo, telefono, creado_en FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([intval($termino)]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;
    }

    // 2. Coincidencia exacta por nombre, usuario o email
    $stmt = $pdo->prepare("SELECT id, usuario, nombre, email, rol, agencia, activo, areas_tickets, foto, cargo, telefono, creado_en FROM usuarios WHERE nombre = ? OR usuario = ? OR email = ? LIMIT 1");
    $stmt->execute([$termino, $termino, $termino]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) return $u;

    // 3. Coincidencia por LIKE
    $stmt = $pdo->prepare("SELECT id, usuario, nombre, email, rol, agencia, activo, areas_tickets, foto, cargo, telefono, creado_en FROM usuarios WHERE nombre LIKE ? OR usuario LIKE ? LIMIT 1");
    $stmt->execute(['%' . $termino . '%', '%' . $termino . '%']);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) return $u;

    return null;
}

/**
 * Calcula las estadísticas y productividad de tickets y citas para un agente asignado
 */
function obtenerEstadisticasAgente($pdo, $nombreAgente, $usuarioAgente = null) {
    $stats = [
        'total' => 0,
        'abiertos' => 0,
        'en_proceso' => 0,
        'resueltos' => 0,
        'cerrados' => 0,
        'efectividad' => 0,
        'con_reporte_firmado' => 0,
        'citas_total' => 0,
        'citas_realizadas' => 0,
        'por_area' => [],
        'ultimos_tickets' => []
    ];
    if (!$pdo || empty(trim($nombreAgente))) return $stats;

    $termino = trim($nombreAgente);
    $params = [$termino];
    $sqlWhere = "asignado_a = ?";
    if (!empty($usuarioAgente) && strcasecmp($usuarioAgente, $termino) !== 0) {
        $sqlWhere = "(asignado_a = ? OR asignado_a = ?)";
        $params = [$termino, trim($usuarioAgente)];
    }

    // Totales por estado
    try {
        $stmt = $pdo->prepare("SELECT estado, COUNT(*) as cant FROM tickets_soporte WHERE $sqlWhere GROUP BY estado");
        $stmt->execute($params);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as $f) {
            $cant = (int)$f['cant'];
            $est = strtolower(trim($f['estado']));
            $stats['total'] += $cant;
            if ($est === 'abierto') $stats['abiertos'] += $cant;
            elseif ($est === 'en proceso') $stats['en_proceso'] += $cant;
            elseif ($est === 'resuelto') $stats['resueltos'] += $cant;
            elseif ($est === 'cerrado') $stats['cerrados'] += $cant;
        }

        if ($stats['total'] > 0) {
            $solucionados = $stats['resueltos'] + $stats['cerrados'];
            $stats['efectividad'] = round(($solucionados / $stats['total']) * 100, 1);
        }
    } catch (Throwable $t) {}

    // Reportes de servicio firmados digitalmente
    try {
        $stmtRep = $pdo->prepare("SELECT COUNT(*) FROM tickets_soporte WHERE $sqlWhere AND (reporte_firmado = 1 OR reporte_firma_digital IS NOT NULL)");
        $stmtRep->execute($params);
        $stats['con_reporte_firmado'] = (int)$stmtRep->fetchColumn();
    } catch (Throwable $t) {}

    // Citas técnicas vinculadas a este agente
    try {
        $pCitas = [$termino];
        $sqlCitas = "tecnico_asignado = ?";
        if (!empty($usuarioAgente) && strcasecmp($usuarioAgente, $termino) !== 0) {
            $sqlCitas = "(tecnico_asignado = ? OR tecnico_asignado = ?)";
            $pCitas = [$termino, trim($usuarioAgente)];
        }
        $stmtC = $pdo->prepare("SELECT estado, COUNT(*) as cant FROM citas_soporte WHERE $sqlCitas GROUP BY estado");
        $stmtC->execute($pCitas);
        $filasC = $stmtC->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filasC as $fc) {
            $cantC = (int)$fc['cant'];
            $stats['citas_total'] += $cantC;
            if (strtolower(trim($fc['estado'])) === 'realizada') {
                $stats['citas_realizadas'] += $cantC;
            }
        }
    } catch (Throwable $t) {}

    // Distribución por área de sistemas
    try {
        $stmtA = $pdo->prepare("SELECT area_sistemas, COUNT(*) as cant FROM tickets_soporte WHERE $sqlWhere GROUP BY area_sistemas ORDER BY cant DESC");
        $stmtA->execute($params);
        $stats['por_area'] = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // Últimos 6 tickets atendidos
    try {
        $stmtU = $pdo->prepare("SELECT id, folio, titulo, area_sistemas, solicitante_agencia, estado, prioridad, creado_en FROM tickets_soporte WHERE $sqlWhere ORDER BY id DESC LIMIT 6");
        $stmtU->execute($params);
        $stats['ultimos_tickets'] = $stmtU->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    return $stats;
}

/**
 * Obtiene todos los agentes que tienen acceso al módulo de tickets y cuentan con áreas de atención asignadas
 * (Excluye a los SuperAdmin del listado operativo de agentes)
 */
function obtenerAgentesConAccesoTickets($pdo = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) return [];
    asegurarColumnasUsuarios($pdo);
    try {
        $sql = "
            SELECT DISTINCT 
                u.id, u.usuario, u.nombre, u.email, u.rol, u.agencia, u.activo, 
                COALESCE(NULLIF(TRIM(u.areas_tickets), ''), 'TODOS') AS areas_tickets, 
                u.foto, u.cargo, u.telefono, u.creado_en
            FROM usuarios u
            LEFT JOIN usuario_permisos up ON u.id = up.usuario_id AND up.modulo_clave = 'tickets'
            WHERE u.activo = 1
              AND LOWER(u.rol) != 'superadmin'
              AND (
                  LOWER(u.rol) = 'admin'
                  OR up.puede_ver = 1
                  OR (
                      u.areas_tickets IS NOT NULL 
                      AND TRIM(u.areas_tickets) != '' 
                      AND UPPER(u.areas_tickets) != 'NINGUNA'
                      AND LOWER(u.rol) != 'usuario'
                  )
              )
              AND (
                  u.areas_tickets IS NULL 
                  OR (TRIM(u.areas_tickets) != '' AND UPPER(u.areas_tickets) != 'NINGUNA')
              )
            ORDER BY 
                CASE WHEN LOWER(u.rol) = 'admin' THEN 1 ELSE 2 END,
                u.nombre ASC
        ";
        $stmt = $pdo->query($sql);
        $agentes = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!empty($agentes)) {
            return $agentes;
        }

        // Fallback si usuario_permisos aún no tiene registros pero usuarios tienen áreas
        $sqlFallback = "
            SELECT 
                id, usuario, nombre, email, rol, agencia, activo, 
                COALESCE(NULLIF(TRIM(areas_tickets), ''), 'TODOS') AS areas_tickets, 
                foto, cargo, telefono, creado_en
            FROM usuarios
            WHERE activo = 1 
              AND LOWER(rol) != 'superadmin'
              AND (
                  LOWER(rol) = 'admin'
                  OR (
                      areas_tickets IS NOT NULL 
                      AND TRIM(areas_tickets) != '' 
                      AND UPPER(areas_tickets) != 'NINGUNA'
                      AND LOWER(rol) != 'usuario'
                  )
              )
              AND (
                  areas_tickets IS NULL 
                  OR (TRIM(areas_tickets) != '' AND UPPER(areas_tickets) != 'NINGUNA')
              )
            ORDER BY 
                CASE WHEN LOWER(rol) = 'admin' THEN 1 ELSE 2 END,
                nombre ASC
        ";
        $stmtFb = $pdo->query($sqlFallback);
        return $stmtFb ? $stmtFb->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $t) {
        return [];
    }
}
?>
