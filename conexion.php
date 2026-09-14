<?php
// Configuración de Conexión a MySQL en cPanel
$db_host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$db_name = getenv('MYSQL_DB')   ?: 'divolavi_sistemas'; // Reemplazar con el nombre de tu BD en cPanel
$db_user = getenv('MYSQL_USER') ?: 'divolavi_user';     // Reemplazar con tu usuario de BD cPanel
$db_pass = getenv('MYSQL_PASS') ?: 'SecuredPass2026!';  // Reemplazar con tu clave de BD cPanel

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // Si la BD no está configurada aún, permitir fallback local o mostrar mensaje controlado
    $pdo = null;
}
?>
