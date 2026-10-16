<?php
// ====================================================================
// Archivo de Configuración de Credenciales MySQL para cPanel
// Si existe un archivo .env en el servidor, .env tiene MÁXIMA prioridad.
// Estas variables solo se usan como respaldo si no existe .env.
// ====================================================================

if (empty($DB_HOST)) $DB_HOST = getenv('MYSQL_HOST') ?: 'localhost';
if (empty($DB_NAME)) $DB_NAME = getenv('MYSQL_DB')   ?: 'divolavi_sistemas';
if (empty($DB_USER)) $DB_USER = getenv('MYSQL_USER') ?: 'divolavi_user';
if (empty($DB_PASS)) $DB_PASS = getenv('MYSQL_PASS') ?: 'SecuredPass2026!';

