<?php
// Configuración de Conexión PDO (MySQL para cPanel / SQLite Automático para Desarrollo Local)
$pdo = null;
$conexion_error = null;

// Cargar archivo de configuración PHP si existe
if (file_exists(__DIR__ . '/config_env.php')) {
    include_once __DIR__ . '/config_env.php';
}

// Cargar variables de entorno de .env si existe
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!getenv($name)) {
                putenv("$name=$value");
            }
        }
    }
}

$db_host = getenv('MYSQL_HOST') ?: (getenv('DB_HOST') ?: 'localhost');
$db_name = getenv('MYSQL_DB')   ?: (getenv('DB_NAME') ?: 'divolavi_sistemas');
$db_user = getenv('MYSQL_USER') ?: (getenv('DB_USER') ?: 'divolavi_user');
$db_pass = getenv('MYSQL_PASS') ?: (getenv('DB_PASS') ?: 'SecuredPass2026!');

$candidates_hosts = [$db_host, 'localhost', '127.0.0.1'];
$candidates_hosts = array_unique($candidates_hosts);

// 1. Intentar conexión a MySQL (cPanel o XAMPP local si estuviera corriendo)
foreach ($candidates_hosts as $h) {
    try {
        if (class_exists('PDO')) {
            $pdo = new PDO("mysql:host=$h;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $conexion_error = null;
            break;
        }
    } catch (Throwable $e) {
        $conexion_error = "Error al conectar a MySQL en [$h] (BD: $db_name, Usuario: $db_user): " . $e->getMessage();
        $pdo = null;
    }
}

// 2. Si MySQL no está instalado o activo localmente, usar Base de Datos Local SQLite (Zero Config para PC)
if (!$pdo && (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || php_sapi_name() === 'cli-server' || php_sapi_name() === 'cli')) {
    try {
        $dbDir = __DIR__ . '/database';
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }
        $sqliteFile = $dbDir . '/local_dev.sqlite';
        $pdo = new PDO("sqlite:" . $sqliteFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $conexion_error = null;

        // Auto-crear esquema SQLite local si es nuevo
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario VARCHAR(50) UNIQUE,
                nombre VARCHAR(100),
                email VARCHAR(100) UNIQUE,
                password VARCHAR(255),
                agencia VARCHAR(100) DEFAULT 'Agencia Grupo Huerta',
                area VARCHAR(100),
                puesto VARCHAR(100),
                telefono VARCHAR(50),
                foto_url VARCHAR(255),
                rol VARCHAR(20) DEFAULT 'Admin',
                activo INTEGER DEFAULT 1,
                acceso_portal INTEGER DEFAULT 1,
                creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN acceso_portal INTEGER DEFAULT 1;");
        } catch (Throwable $t) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN area VARCHAR(100);");
        } catch (Throwable $t) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN puesto VARCHAR(100);");
        } catch (Throwable $t) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(50);");
        } catch (Throwable $t) {}
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_url VARCHAR(255);");
        } catch (Throwable $t) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS modulos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                clave VARCHAR(50) UNIQUE,
                nombre VARCHAR(100),
                descripcion TEXT,
                icono VARCHAR(50),
                orden INTEGER DEFAULT 0,
                estatus INTEGER DEFAULT 1,
                creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS usuario_permisos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario_id INTEGER,
                modulo_clave VARCHAR(50),
                puede_ver INTEGER DEFAULT 1,
                puede_crear INTEGER DEFAULT 1,
                puede_editar INTEGER DEFAULT 1,
                puede_eliminar INTEGER DEFAULT 1,
                puede_exportar INTEGER DEFAULT 1,
                actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(usuario_id, modulo_clave)
            );

            CREATE TABLE IF NOT EXISTS agencias (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre VARCHAR(100) UNIQUE,
                razon_social VARCHAR(150),
                rfc VARCHAR(20),
                direccion TEXT,
                encargado_sistemas VARCHAR(100),
                telefono_sistemas VARCHAR(50),
                correo_sistemas VARCHAR(100),
                logo_url VARCHAR(255),
                foto_url VARCHAR(255),
                maps_url TEXT,
                actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS agencia_areas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                agencia_id INTEGER NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                estatus INTEGER DEFAULT 1,
                creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(agencia_id, nombre)
            );
        ");

        try {
            $pdo->exec("ALTER TABLE agencias ADD COLUMN maps_url TEXT;");
        } catch (Throwable $t) {}

        // Sembrar módulos iniciales en SQLite
        $pdo->exec("
            INSERT OR IGNORE INTO modulos (clave, nombre, descripcion, icono, orden) VALUES
            ('usuarios', 'Gestión de Usuarios', 'Administración de usuarios, roles y permisos', 'bi-people-fill', 1),
            ('ordenes_servicio', 'Órdenes de Servicio', 'Resumen de órdenes abiertas, cerradas y montos', 'bi-file-earmark-bar-graph', 2),
            ('equipos', 'Inventario de Equipos', 'Control de PCs, laptops, servidores e impresoras', 'bi-display-fill', 3),
            ('celulares', 'Inventario de Celulares', 'Control de equipos móviles y líneas', 'bi-phone-fill', 4),
            ('licencias', 'Licencias de Software', 'Matriz de licenciamiento corporativo', 'bi-key-fill', 5),
            ('infraestructura', 'Infraestructura (SITE / IDF)', 'Control de racks y cableado', 'bi-hdd-rack-fill', 6);
        ");

        // Sembrar usuarios administradores en SQLite local
        $passHash = password_hash('Admin123!', PASSWORD_DEFAULT);
        $pdo->exec("
            INSERT OR IGNORE INTO usuarios (usuario, nombre, email, password, agencia, rol, activo) VALUES
            ('admin', 'Administrador Central', 'admin@grupohuerta.mx', '$passHash', 'Agencia Grupo Huerta', 'SuperAdmin', 1),
            ('sistemas', 'Sistemas Grupo Huerta', 'sistemas@grupohuerta.mx', '$passHash', 'Agencia Grupo Huerta', 'Admin', 1);
        ");

    } catch (Throwable $sqliteErr) {
        $conexion_error .= " | Error SQLite Local: " . $sqliteErr->getMessage();
        $pdo = null;
    }
}
?>
