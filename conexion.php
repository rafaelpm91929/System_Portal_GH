<?php
// Configuración de Conexión a MySQL en cPanel con Multi-Fallback de Socket y Detección de Errores
date_default_timezone_set('America/Mexico_City');
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
$db_name = getenv('MYSQL_DB')   ?: (getenv('DB_NAME') ?: 'grupohue_sistemas');
$db_user = getenv('MYSQL_USER') ?: (getenv('DB_USER') ?: 'grupohue_user');
$db_pass = getenv('MYSQL_PASS') ?: (getenv('DB_PASS') ?: 'SecuredPass2026!');

$candidates_hosts = [$db_host, 'localhost', '127.0.0.1'];
$candidates_hosts = array_unique($candidates_hosts);

foreach ($candidates_hosts as $h) {
    try {
        if (class_exists('PDO')) {
            $pdo = new PDO("mysql:host=$h;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            try { $pdo->exec("SET time_zone = '-06:00'"); } catch (Throwable $tzE) {}
            $conexion_error = null;
            break; // Conexión exitosa
        }
    } catch (Throwable $e) {
        $conexion_error = "Error al conectar a MySQL en [$h] (BD: $db_name, Usuario: $db_user): " . $e->getMessage();
        $pdo = null;
    }
}

// 2. Fallback a MySQL local (XAMPP root) si no se pudo conectar con las credenciales de cPanel
if (!$pdo && (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || php_sapi_name() === 'cli-server' || php_sapi_name() === 'cli')) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $pdo->exec("USE `$db_name`;");
        $conexion_error = null;
    } catch (Throwable $eLocalMysql) {
        $pdo = null;
    }
}

// 3. Fallback a SQLite (Zero Config) para garantizar funcionamiento si MySQL no responde
if (!$pdo) {
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

        // Esquema base SQLite
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario VARCHAR(50) UNIQUE,
                nombre VARCHAR(100),
                email VARCHAR(100) UNIQUE,
                password VARCHAR(255),
                agencia VARCHAR(100) DEFAULT 'Oficina Central Grupo Huerta',
                rol VARCHAR(20) DEFAULT 'SuperAdmin',
                activo INTEGER DEFAULT 1,
                creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
            );

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
        ");

        $passHash = password_hash('Admin123!', PASSWORD_DEFAULT);
        $pdo->exec("
            INSERT OR IGNORE INTO usuarios (usuario, nombre, email, password, agencia, rol, activo) VALUES
            ('admin', 'SuperAdmin Grupo Huerta', 'admin@grupohuerta.mx', '$passHash', 'Oficina Central Grupo Huerta', 'SuperAdmin', 1),
            ('tilavilla', 'Sistemas La Villa', 'sistemas@divolavilla.com', '$passHash', 'VW Divol La Villa', 'SuperAdmin', 1);
        ");
    } catch (Throwable $sqliteErr) {
        $conexion_error .= " | Error SQLite Local: " . $sqliteErr->getMessage();
        $pdo = null;
    }
}
?>
