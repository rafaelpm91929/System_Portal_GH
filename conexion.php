<?php
// Configuración de Conexión a MySQL en cPanel para Grupo Huerta
$pdo = null;

try {
    $db_host = getenv('MYSQL_HOST') ?: '127.0.0.1';
    $db_name = getenv('MYSQL_DB')   ?: 'grupohue_sistemas';
    $db_user = getenv('MYSQL_USER') ?: 'grupohue_admin';
    $db_pass = getenv('MYSQL_PASS') ?: 'admin';

    if (class_exists('PDO')) {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
} catch (Throwable $e) {
    // Si la conexión falla, se mantiene $pdo = null para permitir fallback controlado
    $pdo = null;
}
?>
