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
        // 1. Tabla usuarios
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS `usuarios` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `usuario` VARCHAR(50) NOT NULL UNIQUE,
          `nombre` VARCHAR(100) NOT NULL,
          `email` VARCHAR(100) NOT NULL UNIQUE,
          `password` VARCHAR(255) NOT NULL,
          `agencia` VARCHAR(100) DEFAULT 'Agencia Grupo Huerta',
          `rol` ENUM('SuperAdmin', 'Admin', 'Usuario') DEFAULT 'Admin',
          `activo` TINYINT(1) DEFAULT 1,
          `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. Tabla modulos
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

        // 3. Tabla usuario_permisos
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
          FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`modulo_clave`) REFERENCES `modulos`(`clave`) ON DELETE CASCADE ON UPDATE CASCADE,
          UNIQUE KEY `uq_usuario_modulo` (`usuario_id`, `modulo_clave`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. Sembrado Inicial
        $pdo->exec("
        INSERT INTO `modulos` (`clave`, `nombre`, `descripcion`, `icono`, `orden`, `estatus`) VALUES
        ('usuarios', 'Gestión de Usuarios', 'Administración de usuarios, roles y permisos del portal', 'bi-people-fill', 1, 1),
        ('ordenes_servicio', 'Órdenes de Servicio', 'Resumen de órdenes abiertas, cerradas y montos acumulados', 'bi-file-earmark-bar-graph', 2, 1),
        ('equipos', 'Inventario de Equipos', 'Control de PCs, laptops, servidores e impresoras', 'bi-display-fill', 3, 1),
        ('celulares', 'Inventario de Celulares', 'Control de equipos móviles y líneas corporativas', 'bi-phone-fill', 4, 1),
        ('licencias', 'Licencias de Software', 'Matriz de licenciamiento corporativo y vencimientos', 'bi-key-fill', 5, 1),
        ('infraestructura', 'Infraestructura (SITE / IDF)', 'Control de racks, switches y cableado estructurado', 'bi-hdd-rack-fill', 6, 1)
        ON DUPLICATE KEY UPDATE `nombre`=VALUES(`nombre`), `icono`=VALUES(`icono`), `orden`=VALUES(`orden`);");

    } catch (Throwable $e) {
        // Evitar interrumpir flujo si falla parcialmente
    }
}

/**
 * Carga los permisos del usuario en la sesión desde la base de datos
 */
function cargarPermisosSesion($pdo, $usuario_id) {
    if (!$pdo || !$usuario_id) return;

    try {
        $stmt = $pdo->prepare("
            SELECT modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar 
            FROM usuario_permisos 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$usuario_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $_SESSION['permisos'] = [];
        foreach ($rows as $row) {
            $_SESSION['permisos'][$row['modulo_clave']] = [
                'puede_ver'      => (int)$row['puede_ver'],
                'puede_crear'    => (int)$row['puede_crear'],
                'puede_editar'   => (int)$row['puede_editar'],
                'puede_eliminar' => (int)$row['puede_eliminar'],
                'puede_exportar' => (int)$row['puede_exportar']
            ];
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

    // Los roles SuperAdmin y Admin tienen permiso total implícito
    $rol = $_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'Usuario';
    if (in_array(strtolower($rol), ['superadmin', 'admin'])) {
        return true;
    }

    // Para el módulo de gestión de usuarios, solo Admin o SuperAdmin pueden ingresar
    if ($modulo_clave === 'usuarios') {
        return false;
    }

    // Verificar en la matriz de permisos de la sesión
    $permisos = $_SESSION['permisos'][$modulo_clave] ?? null;
    if (!$permisos) {
        return false;
    }

    return !empty($permisos[$accion]);
}

/**
 * Exige un permiso o bloquea la ejecución con mensaje de Acceso Denegado
 */
function requerirPermiso($modulo_clave, $accion = 'puede_ver') {
    if (!tienePermiso($modulo_clave, $accion)) {
        header("HTTP/1.1 403 Forbidden");
        echo '<!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Acceso Denegado - 403</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        </head>
        <body class="bg-dark text-white d-flex align-items-center justify-content-center vh-100 text-center">
            <div class="card bg-secondary bg-opacity-25 border-danger p-5 rounded-4 shadow-lg text-white" style="max-width: 500px;">
                <i class="bi bi-shield-lock-fill text-danger display-1 mb-3"></i>
                <h3 class="fw-bold text-danger">Acceso restringido</h3>
                <p class="text-light">No posees los permisos necesarios para acceder o realizar acciones en el módulo <code>'.htmlspecialchars($modulo_clave).'</code>.</p>
                <a href="modulos.php" class="btn btn-primary rounded-3 px-4 mt-2"><i class="bi bi-arrow-left me-2"></i>Volver al Menú Principal</a>
            </div>
        </body>
        </html>';
        exit();
    }
}
?>
