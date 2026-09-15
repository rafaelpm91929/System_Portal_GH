<?php
// Configuración de Conexión a MySQL en cPanel con prevención total de errores 500
$pdo = null;

try {
    $db_host = getenv('MYSQL_HOST') ?: '127.0.0.1';
    $db_name = getenv('MYSQL_DB')   ?: 'divolavi_sistemas';
    $db_user = getenv('MYSQL_USER') ?: 'divolavi_user';
    $db_pass = getenv('MYSQL_PASS') ?: 'SecuredPass2026!';

    if (class_exists('PDO')) {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
} catch (Throwable $e) {
    // Evitar Error 500 y permitir fallback controlado de la interfaz
    $pdo = null;
}
?>
