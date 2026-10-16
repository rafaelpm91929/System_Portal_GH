<?php
// ====================================================================
// Archivo de Configuración de Credenciales MySQL para cPanel
// Puedes editar las variables $DB_* directamente aquí
// ====================================================================

$DB_HOST = 'localhost';
$DB_NAME = 'divolavi_sistemas'; // IMPORTANTE: En cPanel incluye el prefijo (ej: cpaneluser_sistemas)
$DB_USER = 'divolavi_user';     // IMPORTANTE: En cPanel incluye el prefijo (ej: cpaneluser_dbuser)
$DB_PASS = 'SecuredPass2026!';

// Compatibilidad con getenv() y variables de entorno
putenv("MYSQL_HOST=$DB_HOST");
putenv("MYSQL_DB=$DB_NAME");
putenv("MYSQL_USER=$DB_USER");
putenv("MYSQL_PASS=$DB_PASS");
$_ENV['MYSQL_HOST'] = $DB_HOST;
$_ENV['MYSQL_DB']   = $DB_NAME;
$_ENV['MYSQL_USER'] = $DB_USER;
$_ENV['MYSQL_PASS'] = $DB_PASS;

