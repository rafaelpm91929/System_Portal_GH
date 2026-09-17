<?php
// Configuración de Conexión a MySQL en cPanel con Multi-Fallback de Socket y Detección de Errores
$pdo = null;
$conexion_error = null;

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

foreach ($candidates_hosts as $h) {
    try {
        if (class_exists('PDO')) {
            $pdo = new PDO("mysql:host=$h;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $conexion_error = null;
            break; // Conexión exitosa
        }
    } catch (Throwable $e) {
        $conexion_error = "Error al conectar a MySQL en [$h] (BD: $db_name, Usuario: $db_user): " . $e->getMessage();
        $pdo = null;
    }
}
?>
