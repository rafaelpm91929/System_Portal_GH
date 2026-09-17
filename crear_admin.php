<?php
// Script Creador / Reseteador Universal de Usuarios Administradores - Grupo Huerta
require_once 'conexion.php';

header('Content-Type: text/html; charset=utf-8');

if (!$pdo) {
    die("
    <div style='font-family: sans-serif; padding: 30px; background: #1e1e2e; color: #ff5555;'>
        <h2>❌ Error: No hay conexión a la base de datos MySQL en conexion.php</h2>
        <p>Verifica que el archivo <code>.env</code> o <code>config_env.php</code> tenga las credenciales correctas de cPanel.</p>
        <p>Detalle técnico: " . htmlspecialchars($GLOBALS['conexion_error'] ?? 'Desconocido') . "</p>
    </div>");
}

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <title>Creador de Usuarios Admin - Portal de Sistemas Grupo Huerta</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css'>
</head>
<body class='bg-dark text-white p-5'>
<div class='container' style='max-width: 700px;'>
    <div class='card bg-secondary bg-opacity-10 border-primary p-4 rounded-4 shadow-lg text-white'>
        <h3 class='fw-bold text-primary mb-3'><i class='bi bi-shield-check me-2'></i> Creador de Usuarios Admin - Grupo Huerta</h3>
        <p class='text-secondary'>Generando usuarios administradores principales e inyectando permisos completos en la base de datos MySQL...</p>
        <hr class='border-secondary mb-4'>";

try {
    // 1. Asegurar estructura de tablas
    if (file_exists('permisos_helper.php')) {
        require_once 'permisos_helper.php';
        asegurarTablasPermisos($pdo);
    }

    // 2. Definición de Usuarios Administradores a Crear/Resetear
    $adminsToCreate = [
        [
            'usuario' => 'admin',
            'nombre'  => 'Administrador Central Grupo Huerta',
            'email'   => 'admin@grupohuerta.mx',
            'password'=> 'Admin123!',
            'rol'     => 'SuperAdmin'
        ],
        [
            'usuario' => 'sistemas',
            'nombre'  => 'Sistemas Grupo Huerta',
            'email'   => 'sistemas@grupohuerta.mx',
            'password'=> 'Admin123!',
            'rol'     => 'Admin'
        ],
        [
            'usuario' => 'soporte',
            'nombre'  => 'Soporte Técnico Agencia',
            'email'   => 'soporte@grupohuerta.mx',
            'password'=> 'Admin123!',
            'rol'     => 'Admin'
        ]
    ];

    $stmtMod = $pdo->query("SELECT clave FROM modulos WHERE estatus = 1");
    $modulos = $stmtMod->fetchAll(PDO::FETCH_COLUMN);

    $stmtUser = $pdo->prepare("
        INSERT INTO usuarios (usuario, nombre, email, password, agencia, rol, activo)
        VALUES (?, ?, ?, ?, 'Agencia Grupo Huerta', ?, 1)
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            email = VALUES(email),
            password = VALUES(password),
            rol = VALUES(rol),
            activo = 1
    ");

    $stmtPerm = $pdo->prepare("
        INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
        VALUES (?, ?, 1, 1, 1, 1, 1)
        ON DUPLICATE KEY UPDATE
            puede_ver = 1, puede_crear = 1, puede_editar = 1, puede_eliminar = 1, puede_exportar = 1
    ");

    echo "<ul class='list-group mb-4 border-0'>";
    foreach ($adminsToCreate as $adm) {
        $hash = password_hash($adm['password'], PASSWORD_DEFAULT);
        $stmtUser->execute([$adm['usuario'], $adm['nombre'], $adm['email'], $hash, $adm['rol']]);

        // Obtener ID del usuario
        $stmtFind = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmtFind->execute([$adm['usuario']]);
        $uId = $stmtFind->fetchColumn();

        if ($uId) {
            foreach ($modulos as $modClave) {
                $stmtPerm->execute([$uId, $modClave]);
            }
        }

        echo "<li class='list-group-item bg-dark text-white border-secondary d-flex justify-content-between align-items-center'>
                <div>
                    <i class='bi bi-person-check-fill text-success me-2 fs-5'></i>
                    <strong>{$adm['usuario']}</strong> ({$adm['nombre']}) - <code>{$adm['email']}</code>
                </div>
                <div>
                    <span class='badge bg-primary me-2'>{$adm['rol']}</span>
                    <span class='badge bg-success'>Clave: {$adm['password']}</span>
                </div>
              </li>";
    }
    echo "</ul>";

    echo "<div class='alert alert-success border-0 rounded-3 text-dark mb-4'>
            <i class='bi bi-check-circle-fill me-2 fs-5'></i> <strong>¡Proceso completado exitosamente!</strong><br>
            Los usuarios administradores han sido creados y configurados con acceso total.
          </div>";

    echo "<div class='d-flex gap-3'>
            <a href='login.php' class='btn btn-primary btn-lg rounded-3 px-4 fw-semibold'><i class='bi bi-box-arrow-in-right me-2'></i> Ir a Iniciar Sesión</a>
            <a href='usuarios.php' class='btn btn-outline-light btn-lg rounded-3 px-4 me-2'><i class='bi bi-people me-2'></i> Ir a Gestión de Usuarios</a>
          </div>";

} catch (PDOException $e) {
    echo "<div class='alert alert-danger border-0 rounded-3'>
            <i class='bi bi-exclamation-triangle-fill me-2'></i> <strong>Error al crear usuarios:</strong> " . htmlspecialchars($e->getMessage()) . "
          </div>";
}

echo "</div></div></body></html>";
?>
