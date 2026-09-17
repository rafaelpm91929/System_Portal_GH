<?php
// Helper de Permisos y Control de Acceso Granular (RBAC)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
        // En caso de falla en DB, no bloquear la sesión básica
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
