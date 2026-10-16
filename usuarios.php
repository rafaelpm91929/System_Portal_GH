<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión y Rol de Administración
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

if ($pdo) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
}
requerirPermiso('usuarios', 'puede_ver');

$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esSuperAdmin = ($rolActual === 'superadmin');

$mensaje = '';
$error = '';

// ----------------------------------------------------
// PROCESAMIENTO DE ACCIONES (POST)
// ----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. GUARDAR / CREAR / EDITAR USUARIO CON MÓDULOS DE ACCESO
    if ($accion === 'guardar_usuario') {
        $id = intval($_POST['user_id'] ?? 0);
        $usuario = trim($_POST['usuario'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $rol = $_POST['rol'] ?? 'Usuario';
        $agencia = $_POST['agencia'] ?? 'Divol La Villa';
        $activo = isset($_POST['activo']) ? 1 : 0;
        $modulosPermitidos = $_POST['modulos_permitidos'] ?? []; // Claves de módulos autorizados

        if (empty($usuario) || empty($nombre) || empty($email)) {
            $error = "Por favor completa los campos obligatorios (Usuario, Nombre, Email).";
        } elseif (strtolower($rol) === 'superadmin' && !$esSuperAdmin) {
            $error = "Acceso denegado: Solo un usuario con rol SuperAdmin puede crear o asignar el rol SuperAdmin.";
        } else {
            try {
                if ($id > 0) {
                    $stmtTargetRol = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
                    $stmtTargetRol->execute([$id]);
                    $targetRolDb = strtolower($stmtTargetRol->fetchColumn() ?: '');
                    if ($targetRolDb === 'superadmin' && !$esSuperAdmin) {
                        $error = "Acceso denegado: Solo un usuario SuperAdmin puede modificar a otro SuperAdmin.";
                    }
                }

                if (empty($error)) {
                    $targetUserId = 0;
                    if ($id > 0) {
                    // Procesar configuración de áreas autorizadas de tickets
                    $areasPost = $_POST['areas_tickets'] ?? [];
                    $areasFinal = 'TODOS';
                    if (strtolower($rol) === 'superadmin') {
                        $areasFinal = 'TODOS';
                    } elseif (in_array('tickets', $modulosPermitidos)) {
                        if (in_array('TODOS', $areasPost) || count($areasPost) >= 6) {
                            $areasFinal = 'TODOS';
                        } elseif (!empty($areasPost)) {
                            $areasFinal = implode(',', array_map('strtoupper', $areasPost));
                        } else {
                            $areasFinal = 'NINGUNA';
                        }
                    } else {
                        $areasFinal = '';
                    }

                    // Actualizar Usuario existente
                    if (!empty($password)) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE usuarios SET usuario=?, nombre=?, email=?, password=?, rol=?, agencia=?, activo=?, areas_tickets=? WHERE id=?");
                        $stmt->execute([$usuario, $nombre, $email, $hash, $rol, $agencia, $activo, $areasFinal, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE usuarios SET usuario=?, nombre=?, email=?, rol=?, agencia=?, activo=?, areas_tickets=? WHERE id=?");
                        $stmt->execute([$usuario, $nombre, $email, $rol, $agencia, $activo, $areasFinal, $id]);
                    }
                    $targetUserId = $id;
                    $mensaje = "Usuario <strong>".htmlspecialchars($usuario)."</strong> y permisos de módulos actualizados con éxito.";
                } else {
                    // Crear nuevo Usuario
                    if (empty($password)) {
                        $error = "La contraseña es obligatoria para un usuario nuevo.";
                    } else {
                        $areasPost = $_POST['areas_tickets'] ?? [];
                        $areasFinal = 'TODOS';
                        if (strtolower($rol) === 'superadmin') {
                            $areasFinal = 'TODOS';
                        } elseif (in_array('tickets', $modulosPermitidos)) {
                            if (in_array('TODOS', $areasPost) || count($areasPost) >= 6) {
                                $areasFinal = 'TODOS';
                            } elseif (!empty($areasPost)) {
                                $areasFinal = implode(',', array_map('strtoupper', $areasPost));
                            } else {
                                $areasFinal = 'NINGUNA';
                            }
                        } else {
                            $areasFinal = '';
                        }

                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, email, password, rol, agencia, activo, areas_tickets) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$usuario, $nombre, $email, $hash, $rol, $agencia, $activo, $areasFinal]);
                        $targetUserId = (int)$pdo->lastInsertId();
                        $mensaje = "Nuevo usuario <strong>".htmlspecialchars($usuario)."</strong> creado correctamente con sus módulos de acceso configurados.";
                    }
                }

                // Guardar permisos para cada módulo en usuario_permisos (Compatible con SQLite y MySQL)
                if ($targetUserId > 0) {
                    $modulosCatalog = array_column(obtenerCatalogoModulos($pdo), 'clave');

                    $stmtDel = $pdo->prepare("DELETE FROM usuario_permisos WHERE usuario_id = ?");
                    $stmtDel->execute([$targetUserId]);

                    $stmtSave = $pdo->prepare("
                        INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($modulosCatalog as $mClave) {
                        if (strtolower($rol) === 'superadmin') {
                            $pVer = 1; $pCrear = 1; $pEditar = 1; $pEliminar = 1; $pExportar = 1;
                        } else {
                            $tieneAcceso = in_array($mClave, $modulosPermitidos);
                            $pVer = $tieneAcceso ? 1 : 0;
                            $pCrear = $tieneAcceso ? 1 : 0;
                            $pEditar = $tieneAcceso ? 1 : 0;
                            $pEliminar = ($tieneAcceso && strtolower($rol) === 'admin') ? 1 : 0;
                            $pExportar = $tieneAcceso ? 1 : 0;
                        }
                        $stmtSave->execute([$targetUserId, $mClave, $pVer, $pCrear, $pEditar, $pEliminar, $pExportar]);
                    }

                    // Refrescar permisos si se modificó a sí mismo
                    if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] == $targetUserId) {
                        cargarPermisosSesion($pdo, $targetUserId);
                    }
                }
            }
        } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    $error = "El nombre de usuario o correo ya existe en el sistema.";
                } else {
                    $error = "Error al guardar usuario: " . $e->getMessage();
                }
            }
        }
    }

    // 2. GUARDAR MATRIZ DE PERMISOS GRANULARES
    elseif ($accion === 'guardar_permisos') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        $permisos_posted = $_POST['permisos'] ?? []; // Array [modulo_clave => [puede_ver, puede_crear...]]

        if ($target_user_id > 0) {
            try {
                $stmtTargetRol = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
                $stmtTargetRol->execute([$target_user_id]);
                $targetRolDb = strtolower($stmtTargetRol->fetchColumn() ?: '');

                if ($targetRolDb === 'superadmin' && !$esSuperAdmin) {
                    $error = "Acceso denegado: Solo un usuario SuperAdmin puede modificar los permisos de un SuperAdmin.";
                } else {
                    // Obtener todos los módulos activos del catálogo garantizado
                    $modulosCatalog = array_column(obtenerCatalogoModulos($pdo), 'clave');

                    $stmtDel = $pdo->prepare("DELETE FROM usuario_permisos WHERE usuario_id = ?");
                    $stmtDel->execute([$target_user_id]);

                    $stmtSave = $pdo->prepare("
                        INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($modulosCatalog as $mClave) {
                        $pVer      = isset($permisos_posted[$mClave]['puede_ver']) ? 1 : 0;
                        $pCrear    = isset($permisos_posted[$mClave]['puede_crear']) ? 1 : 0;
                        $pEditar   = isset($permisos_posted[$mClave]['puede_editar']) ? 1 : 0;
                        $pEliminar = isset($permisos_posted[$mClave]['puede_eliminar']) ? 1 : 0;
                        $pExportar = isset($permisos_posted[$mClave]['puede_exportar']) ? 1 : 0;

                        $stmtSave->execute([$target_user_id, $mClave, $pVer, $pCrear, $pEditar, $pEliminar, $pExportar]);
                    }

                    $mensaje = "Matriz de permisos granulares actualizada correctamente.";
                }
            } catch (PDOException $e) {
                $error = "Error al actualizar la matriz de permisos: " . $e->getMessage();
            }
        }
    }

    // 3. CAMBIAR ESTATUS (ACTIVAR/DESACTIVAR)
    elseif ($accion === 'toggle_status') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $nuevo_estatus = intval($_POST['nuevo_estatus'] ?? 1);
        if ($user_id > 0) {
            try {
                $stmtTargetRol = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
                $stmtTargetRol->execute([$user_id]);
                $targetRolDb = strtolower($stmtTargetRol->fetchColumn() ?: '');

                if ($targetRolDb === 'superadmin' && !$esSuperAdmin) {
                    $error = "Acceso denegado: Solo un usuario SuperAdmin puede modificar a otro SuperAdmin.";
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
                    $stmt->execute([$nuevo_estatus, $user_id]);
                    $mensaje = "Estatus del usuario modificado correctamente.";
                }
            } catch (PDOException $e) {
                $error = "Error al actualizar estatus: " . $e->getMessage();
            }
        }
    }

    // 4. ELIMINAR USUARIO
    elseif ($accion === 'eliminar_usuario') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            $error = "ID de usuario inválido para eliminar.";
        } elseif ($user_id === (int)($_SESSION['usuario_id'] ?? 0)) {
            $error = "No puedes eliminar tu propia cuenta de usuario.";
        } else {
            try {
                $stmtTarget = $pdo->prepare("SELECT id, rol, nombre, usuario FROM usuarios WHERE id = ?");
                $stmtTarget->execute([$user_id]);
                $targetUsr = $stmtTarget->fetch(PDO::FETCH_ASSOC);

                if (!$targetUsr) {
                    $error = "El usuario seleccionado no existe.";
                } else {
                    $targetRol = strtolower($targetUsr['rol'] ?? '');
                    if ($targetRol === 'superadmin' && !$esSuperAdmin) {
                        $error = "Acceso denegado: Solo un usuario SuperAdmin puede eliminar a otro SuperAdmin.";
                    } else {
                        $stmtDelP = $pdo->prepare("DELETE FROM usuario_permisos WHERE usuario_id = ?");
                        $stmtDelP->execute([$user_id]);

                        $stmtDelU = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                        $stmtDelU->execute([$user_id]);

                        $nomMostrar = !empty($targetUsr['nombre']) ? $targetUsr['nombre'] : $targetUsr['usuario'];
                        $mensaje = "El usuario <strong>" . htmlspecialchars($nomMostrar) . "</strong> ha sido eliminado exitosamente.";
                    }
                }
            } catch (PDOException $eDel) {
                $error = "Error al eliminar el usuario: " . $eDel->getMessage();
            }
        }
    }
}

// ----------------------------------------------------
// CONSULTA DE DATOS PARA LA VISTA
// ----------------------------------------------------

$usuarios = [];
$modulosCat = [];
$permisosMap = []; // [user_id][modulo_clave] => array(...)

if ($pdo) {
    asegurarTablasPermisos($pdo);
    try {
        // Obtener usuarios
        $sqlU = (!$esSuperAdmin) ? "SELECT * FROM usuarios WHERE LOWER(rol) != 'superadmin' ORDER BY id DESC" : "SELECT * FROM usuarios ORDER BY id DESC";
        $stmtU = $pdo->query($sqlU);
        $usuarios = $stmtU->fetchAll(PDO::FETCH_ASSOC);

        // Obtener catálogo de módulos garantizado
        $modulosCat = obtenerCatalogoModulos($pdo);

        // Obtener permisos existentes
        $stmtP = $pdo->query("SELECT * FROM usuario_permisos");
        $allPerms = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allPerms as $pm) {
            $permisosMap[$pm['usuario_id']][$pm['modulo_clave']] = $pm;
        }
    } catch (PDOException $e) {
        $error = "Error consultando datos: " . $e->getMessage();
    }
}

if (empty($modulosCat)) {
    $modulosCat = obtenerCatalogoModulos($pdo);
}

$areasCatalogoSistemas = [
    'DESARROLLO'         => ['icono' => 'bi-code-slash', 'color' => '#38bdf8', 'desc' => 'Desarrollo de software y portales'],
    'CYBERSEGURIDAD'     => ['icono' => 'bi-shield-lock-fill', 'color' => '#f43f5e', 'desc' => 'Seguridad perimetral y accesos'],
    'INFRAESTRUCTURA'    => ['icono' => 'bi-hdd-rack-fill', 'color' => '#10b981', 'desc' => 'Servidores, enlaces y site'],
    'REDES SOCIALES'     => ['icono' => 'bi-share-fill', 'color' => '#a855f7', 'desc' => 'Gestión de redes y perfiles'],
    'AUDITORIA'          => ['icono' => 'bi-clipboard-check-fill', 'color' => '#f59e0b', 'desc' => 'Revisiones, normas e inventarios'],
    'CORPORATIVO'        => ['icono' => 'bi-building-fill', 'color' => '#6366f1', 'desc' => 'Mesa de ayuda corporativa'],
    'DIRECCION SISTEMAS' => ['icono' => 'bi-diagram-3-fill', 'color' => '#06b6d4', 'desc' => 'Gestión, proyectos y dirección técnica TI']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios y Permisos - Systems Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #040d1a;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 50px;
        }
        /* Corrección de contraste global */
        .text-secondary, .text-muted {
            color: #94a3b8 !important;
        }
        .form-label {
            color: #cbd5e1 !important;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .form-control::placeholder {
            color: #64748b;
        }

        .top-navbar {
            background: rgba(10, 25, 46, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 15px 35px;
        }
        .card-custom {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 24px;
        }
        .table-custom {
            color: #f1f5f9;
        }
        .table-custom th {
            background-color: #0b1f38 !important;
            color: #38bdf8 !important;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 2px solid rgba(56, 189, 248, 0.3) !important;
            padding: 13px 14px;
        }
        .table-custom td {
            vertical-align: middle;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            padding: 13px 14px;
            color: #e2e8f0;
        }

        /* Fila de usuario interactiva y con contraste */
        .usuario-row {
            cursor: pointer;
            transition: all 0.15s ease-in-out;
        }
        .usuario-row:hover td {
            background-color: rgba(56, 189, 248, 0.09) !important;
            color: #ffffff !important;
        }
        .usuario-row:hover .badge-user-tag {
            border-color: #38bdf8;
            background: rgba(14, 165, 233, 0.22);
            color: #ffffff;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.3);
        }

        /* Badges de alto contraste */
        .badge-user-tag {
            background: rgba(14, 165, 233, 0.14);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.38);
            font-family: monospace;
            font-size: 0.86rem;
            padding: 4px 9px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        }
        .badge-role-superadmin {
            background: rgba(239, 68, 68, 0.18) !important;
            color: #fda4af !important;
            border: 1px solid rgba(239, 68, 68, 0.5) !important;
            font-weight: 700;
        }
        .badge-role-admin {
            background: rgba(14, 165, 233, 0.18) !important;
            color: #7dd3fc !important;
            border: 1px solid rgba(56, 189, 248, 0.5) !important;
            font-weight: 700;
        }
        .badge-role-usuario {
            background: rgba(148, 163, 184, 0.15) !important;
            color: #e2e8f0 !important;
            border: 1px solid rgba(148, 163, 184, 0.35) !important;
            font-weight: 600;
        }
        .badge-status-active {
            background: rgba(16, 185, 129, 0.18) !important;
            color: #6ee7b7 !important;
            border: 1px solid rgba(16, 185, 129, 0.45) !important;
            font-weight: 600;
        }
        .badge-status-inactive {
            background: rgba(239, 68, 68, 0.18) !important;
            color: #fca5a5 !important;
            border: 1px solid rgba(239, 68, 68, 0.4) !important;
            font-weight: 600;
        }
        .badge-mod-tag {
            font-size: 0.74rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin: 2px 2px;
            background: rgba(30, 58, 138, 0.35);
            color: #93c5fd;
            border: 1px solid rgba(96, 165, 250, 0.35);
        }

        /* Ficha Flotante del Usuario */
        .ficha-avatar {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            background: linear-gradient(135deg, #0284c7 0%, #3b82f6 50%, #6366f1 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 800;
            color: #ffffff;
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.35);
            border: 2px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
        }
        .ficha-info-box {
            background: rgba(15, 34, 61, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px 14px;
            transition: all 0.2s ease;
            height: 100%;
        }
        .ficha-info-box:hover {
            border-color: rgba(56, 189, 248, 0.35);
            background: rgba(15, 34, 61, 0.9);
        }
        .ficha-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .ficha-val {
            font-size: 0.92rem;
            font-weight: 600;
            color: #ffffff;
            word-break: break-word;
        }

        .modal-content {
            background-color: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 16px;
        }
        .modal-header, .modal-footer {
            border-color: rgba(255, 255, 255, 0.08);
        }
        .form-control, .form-select {
            background-color: #0f223d;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }
        .form-control:focus, .form-select:focus {
            background-color: #132a4b;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.25);
        }
        .perm-checkbox {
            width: 1.2em;
            height: 1.2em;
            cursor: pointer;
        }
        .perm-module-card {
            background: #061325;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 12px 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .perm-module-card:hover {
            border-color: #38bdf8;
            background: #091f3b;
            transform: translateY(-2px);
        }
        .perm-module-card.active {
            border-color: #0284c7;
            background: rgba(2, 132, 199, 0.16);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
        }
        .perm-module-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Volver al Menú
        </a>
        <span class="fw-bold fs-5">PORTAL SISTEMAS <span class="text-primary">| Gestión de Usuarios y Permisos</span></span>
    </div>
    <div>
        <span class="badge bg-primary p-2 fs-6"><i class="bi bi-shield-lock me-1"></i> Control RBAC Dinámico</span>
    </div>
</div>

<div class="container-fluid px-4">

    <!-- Mensajes de Alerta -->
    <?php if ($mensaje): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Encabezado de Acción y Búsqueda -->
    <div class="card-custom mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i> Usuarios del Sistema</h4>
                <p class="text-secondary small mb-0">Haz clic en cualquier usuario para ver su <strong>ficha flotante completa</strong> con detalles de contacto, módulos y permisos.</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary rounded-3 px-4 fw-semibold shadow-sm" onclick="abrirModalNuevoUsuario()">
                    <i class="bi bi-person-plus-fill me-2"></i> Nuevo Usuario
                </button>
            </div>
        </div>

        <div class="row g-2 mt-3 pt-3 border-top border-secondary border-opacity-25 align-items-center">
            <div class="col-md-6 col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-secondary text-info"><i class="bi bi-search"></i></span>
                    <input type="text" id="filtroUsuarioInput" class="form-control" placeholder="Buscar por nombre, usuario, correo o agencia..." onkeyup="filtrarTablaUsuarios()">
                </div>
            </div>
            <div class="col-md-6 col-lg-7 d-flex justify-content-md-end align-items-center gap-2">
                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 py-2 px-3 small">
                    <i class="bi bi-cursor-fill me-1"></i> Tip: Haz clic en cualquier fila para abrir la <strong>Ficha del Usuario</strong>
                </span>
            </div>
        </div>
    </div>

    <!-- Tabla de Usuarios -->
    <div class="card-custom">
        <div class="table-responsive">
            <table class="table table-custom table-hover mb-0" id="tablaUsuarios">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th>Usuario</th>
                        <th>Nombre Completo</th>
                        <th>Correo Electrónico</th>
                        <th>Rol</th>
                        <th>Módulos Autorizados</th>
                        <th>Estatus</th>
                        <th class="text-end" style="min-width: 170px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-secondary">
                                <i class="bi bi-info-circle fs-4 d-block mb-2"></i> No se encontraron usuarios en la base de datos. Usa el botón 'Nuevo Usuario' para registrar el primero.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                            <tr class="usuario-row" onclick='abrirFichaUsuario(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8"); ?>)' title="Haz clic para ver la ficha flotante de este usuario">
                                <td class="fw-bold text-info">#<?php echo $u['id']; ?></td>
                                <td>
                                    <span class="badge-user-tag">
                                        <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($u['usuario']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-white"><?php echo htmlspecialchars($u['nombre']); ?></div>
                                    <?php if (!empty($u['agencia'])): ?>
                                        <div class="small text-secondary" style="font-size: 0.73rem;">
                                            <i class="bi bi-geo-alt me-1 text-info"></i> <?php echo htmlspecialchars($u['agencia']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-info-subtle">
                                        <i class="bi bi-envelope me-1 text-secondary"></i> <?php echo htmlspecialchars($u['email']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['rol'] === 'SuperAdmin'): ?>
                                        <span class="badge badge-role-superadmin rounded-pill px-3 py-1"><i class="bi bi-shield-fill-check me-1"></i> SuperAdmin</span>
                                    <?php elseif ($u['rol'] === 'Admin'): ?>
                                        <span class="badge badge-role-admin rounded-pill px-3 py-1"><i class="bi bi-shield-check me-1"></i> Admin</span>
                                    <?php else: ?>
                                        <span class="badge badge-role-usuario rounded-pill px-3 py-1"><i class="bi bi-person me-1"></i> Usuario</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['rol'] === 'SuperAdmin'): ?>
                                        <span class="badge bg-danger bg-opacity-20 text-danger border border-danger border-opacity-40 rounded-pill px-3 py-1">
                                            <i class="bi bi-shield-check me-1"></i> Todos los módulos
                                        </span>
                                    <?php else: 
                                        $uPerms = $permisosMap[$u['id']] ?? [];
                                        $modsActivos = [];
                                        foreach ($modulosCat as $mc) {
                                            if (!empty($uPerms[$mc['clave']]['puede_ver'])) {
                                                $modsActivos[] = $mc;
                                            }
                                        }
                                    ?>
                                        <?php if (!empty($modsActivos)): ?>
                                            <div class="d-flex flex-wrap gap-1" style="max-width: 320px;">
                                                <?php foreach ($modsActivos as $ma): ?>
                                                    <span class="badge-mod-tag" title="<?php echo htmlspecialchars($ma['descripcion']); ?>">
                                                        <i class="bi <?php echo $ma['icono']; ?> text-info"></i> <?php echo htmlspecialchars($ma['nombre']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php 
                                            // Si tiene módulo tickets, mostrar resumen de áreas autorizadas
                                            $tieneTickets = false;
                                            foreach ($modsActivos as $ma) {
                                                if ($ma['clave'] === 'tickets') { $tieneTickets = true; break; }
                                            }
                                            if ($tieneTickets): 
                                                $uAreas = trim($u['areas_tickets'] ?? 'TODOS');
                                            ?>
                                                <div class="mt-1">
                                                    <?php if (strtoupper($uAreas) === 'TODOS' || $uAreas === '*'): ?>
                                                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-40" style="font-size:0.69rem;" title="Acceso total a todas las áreas de tickets">
                                                            <i class="bi bi-check-all"></i> Áreas TI: TODAS
                                                        </span>
                                                    <?php elseif (empty($uAreas) || strtoupper($uAreas) === 'NINGUNA'): ?>
                                                        <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-40" style="font-size:0.69rem;">
                                                            <i class="bi bi-slash-circle"></i> Áreas TI: Ninguna
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-40" style="font-size:0.69rem;" title="Áreas autorizadas: <?php echo htmlspecialchars($uAreas); ?>">
                                                            <i class="bi bi-diagram-3"></i> Áreas TI: <?php echo htmlspecialchars($uAreas); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-25 text-warning border border-warning border-opacity-25 rounded-pill px-2 py-1">
                                                <i class="bi bi-slash-circle me-1"></i> Sin acceso a módulos
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['activo']): ?>
                                        <span class="badge badge-status-active rounded-pill px-3 py-1"><i class="bi bi-check-circle-fill me-1"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge badge-status-inactive rounded-pill px-3 py-1"><i class="bi bi-x-circle me-1"></i> Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" onclick="event.stopPropagation();">
                                    <div class="btn-group">
                                        <!-- Ver Ficha Flotante -->
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-2 me-1" onclick='event.stopPropagation(); abrirFichaUsuario(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8"); ?>)' title="Ver Ficha Técnica Flotante">
                                            <i class="bi bi-eye-fill"></i>
                                        </button>

                                        <!-- Editar Datos y Módulos -->
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-2 me-1" onclick='event.stopPropagation(); abrirModalEditarUsuario(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8"); ?>)' title="Editar datos y módulos permitidos">
                                            <i class="bi bi-pencil-square me-1"></i> Editar
                                        </button>

                                        <!-- Configurar Permisos Granulares Detallados -->
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-2 me-1" onclick='event.stopPropagation(); abrirModalPermisos(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8"); ?>)' title="Configurar Acciones Granulares (Crear, Editar, Borrar)">
                                            <i class="bi bi-shield-lock-fill"></i>
                                        </button>

                                        <!-- Alternar Estatus -->
                                        <form method="POST" class="d-inline" onsubmit="event.stopPropagation(); return confirm('¿Deseas cambiar el estatus de este usuario?');">
                                            <input type="hidden" name="accion" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="nuevo_estatus" value="<?php echo $u['activo'] ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $u['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> rounded-2" title="<?php echo $u['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                                <i class="bi <?php echo $u['activo'] ? 'bi-person-x-fill' : 'bi-person-check-fill'; ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Eliminar Usuario -->
                                        <?php if ($u['id'] != ($_SESSION['usuario_id'] ?? 0) && ($esSuperAdmin || strtolower($u['rol'] ?? '') !== 'superadmin')): ?>
                                        <form method="POST" class="d-inline ms-1" onsubmit="event.stopPropagation(); return confirm('¿Estás seguro de que deseas eliminar permanentemente al usuario \'<?php echo addslashes(htmlspecialchars($u['nombre'] ?? $u['usuario'])); ?>\'? Esta acción no se puede deshacer.');">
                                            <input type="hidden" name="accion" value="eliminar_usuario">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-2" title="Eliminar Usuario">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: FICHA TÉCNICA FLOTANTE DEL USUARIO           -->
<!-- =================================================== -->
<div class="modal fade" id="modalFichaUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-info border-opacity-25" style="box-shadow: 0 10px 40px rgba(0,0,0,0.85);">
            <div class="modal-header border-secondary border-opacity-25 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="ficha-avatar" id="ficha_avatar">
                        U
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h4 class="fw-bold mb-0 text-white" id="ficha_nombre">Nombre del Usuario</h4>
                            <span id="ficha_badge_rol" class="badge badge-role-admin rounded-pill px-3 py-1">Admin</span>
                            <span id="ficha_badge_status" class="badge badge-status-active rounded-pill px-3 py-1">Activo</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span class="badge-user-tag" id="ficha_usuario_tag"><i class="bi bi-person-badge"></i> @usuario</span>
                            <span class="text-secondary small" id="ficha_agencia_text"><i class="bi bi-geo-alt me-1 text-info"></i> Sucursal</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Sección 1: Información General -->
                <h6 class="text-info fw-bold text-uppercase small mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-person-lines-fill text-warning"></i> Datos Generales de la Cuenta
                </h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-person text-info me-1"></i> Usuario / Login</div>
                            <div class="ficha-val text-info font-monospace" id="ficha_val_usuario">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-envelope text-info me-1"></i> Correo Electrónico</div>
                            <div class="ficha-val text-truncate" id="ficha_val_email">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-building text-warning me-1"></i> Sucursal / Agencia</div>
                            <div class="ficha-val" id="ficha_val_agencia">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-diagram-2 text-success me-1"></i> Área / Departamento</div>
                            <div class="ficha-val" id="ficha_val_area">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-briefcase text-secondary me-1"></i> Puesto / Cargo</div>
                            <div class="ficha-val" id="ficha_val_puesto">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-telephone text-info me-1"></i> Teléfono / Ext</div>
                            <div class="ficha-val" id="ficha_val_telefono">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-calendar3 text-warning me-1"></i> Fecha de Registro</div>
                            <div class="ficha-val text-secondary small" id="ficha_val_creado">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-key text-primary me-1"></i> ID Registro</div>
                            <div class="ficha-val text-secondary font-monospace" id="ficha_val_id">---</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="ficha-info-box">
                            <div class="ficha-label"><i class="bi bi-shield-check text-success me-1"></i> Nivel de Acceso</div>
                            <div class="ficha-val text-light small" id="ficha_val_rol_nivel">---</div>
                        </div>
                    </div>
                </div>

                <!-- Sección 2: Módulos Autorizados -->
                <h6 class="text-info fw-bold text-uppercase small mb-2 d-flex align-items-center gap-2">
                    <i class="bi bi-grid-fill text-info"></i> Módulos Autorizados en el Portal
                </h6>
                <div id="ficha_modulos_container" class="mb-4">
                    <!-- Dinámico -->
                </div>

                <!-- Sección 3: Áreas de Tickets Asignadas -->
                <div id="ficha_areas_tickets_section" class="mb-4">
                    <h6 class="text-warning fw-bold text-uppercase small mb-2 d-flex align-items-center gap-2">
                        <i class="bi bi-diagram-3-fill"></i> Áreas TI Autorizadas (Módulo Tickets)
                    </h6>
                    <div id="ficha_areas_tickets_container" class="p-3 rounded-3" style="background: rgba(14, 36, 68, 0.65); border: 1px solid rgba(56, 189, 248, 0.25);">
                        <!-- Dinámico -->
                    </div>
                </div>

                <!-- Sección 4: Resumen de Permisos Granulares -->
                <h6 class="text-info fw-bold text-uppercase small mb-2 d-flex align-items-center gap-2">
                    <i class="bi bi-shield-lock-fill text-warning"></i> Privilegios Granulares por Módulo
                </h6>
                <div class="table-responsive rounded-3 border border-secondary border-opacity-25">
                    <table class="table table-custom table-sm mb-0 align-middle">
                        <thead>
                            <tr class="text-center">
                                <th class="text-start">Módulo Corporativo</th>
                                <th style="width: 80px;">Ver</th>
                                <th style="width: 80px;">Crear</th>
                                <th style="width: 80px;">Editar</th>
                                <th style="width: 80px;">Eliminar</th>
                                <th style="width: 80px;">Exportar</th>
                            </tr>
                        </thead>
                        <tbody id="ficha_tabla_permisos_body">
                            <!-- Dinámico -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25 justify-content-between">
                <div>
                    <button type="button" class="btn btn-outline-secondary rounded-3 text-light" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cerrar
                    </button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-info rounded-3 text-info fw-semibold" id="ficha_btn_permisos">
                        <i class="bi bi-shield-lock me-1"></i> Configurar Permisos
                    </button>
                    <button type="button" class="btn btn-warning rounded-3 fw-bold shadow-sm" id="ficha_btn_editar">
                        <i class="bi bi-pencil-square me-1"></i> Editar este Usuario
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- =================================================== -->
<!-- MODAL: CREAR / EDITAR USUARIO Y CONFIGURAR MÓDULOS -->
<!-- =================================================== -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_usuario">
                <input type="hidden" name="user_id" id="form_user_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalUsuarioLabel"><i class="bi bi-person-plus text-primary me-2"></i> Nuevo Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- SECCIÓN 1: DATOS Y CREDENCIALES DEL USUARIO -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Nombre de Usuario (Login) *</label>
                            <input type="text" name="usuario" id="form_usuario" class="form-control" placeholder="ej. jrodriguez" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Nombre Completo *</label>
                            <input type="text" name="nombre" id="form_nombre" class="form-control" placeholder="ej. Juan Rodríguez" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Correo Institucional *</label>
                            <input type="email" name="email" id="form_email" class="form-control" placeholder="ej. juan.rodriguez@divolavilla.com" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Contraseña *</label>
                            <input type="password" name="password" id="form_password" class="form-control" placeholder="Contraseña de acceso">
                            <small class="text-muted fs-7" id="pass_help">Obligatoria al crear un nuevo usuario.</small>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Rol en el Portal</label>
                            <select name="rol" id="form_rol" class="form-select" onchange="onRolChange()">
                                <option value="Usuario" selected>Usuario (Permisos Restringidos a Módulos)</option>
                                <option value="Admin">Admin (Administrador)</option>
                                <?php if ($esSuperAdmin): ?>
                                <option value="SuperAdmin">SuperAdmin (Acceso Maestro Total)</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Sucursal / Agencia</label>
                            <input type="text" name="agencia" id="form_agencia" class="form-control" value="Divol La Villa">
                        </div>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="activo" id="form_activo" value="1" checked>
                        <label class="form-check-label text-light fw-semibold" for="form_activo">Usuario Activo en el Sistema</label>
                    </div>

                    <!-- SECCIÓN 2: CONTROL ESTRICTO DE ACCESO A MÓDULOS -->
                    <div class="p-3 rounded-4" style="background: rgba(4, 13, 26, 0.7); border: 1px solid rgba(56, 189, 248, 0.2);">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <h6 class="fw-bold text-info mb-1 d-flex align-items-center gap-2">
                                    <i class="bi bi-shield-lock-fill text-warning"></i> Configuración de Módulos Autorizados
                                </h6>
                                <div class="small text-secondary">
                                    Selecciona los módulos a los que este usuario tendrá acceso. <strong>Si un módulo no está marcado, el usuario NO podrá verlo ni acceder a él bajo ninguna circunstancia.</strong>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-1" id="presetsBar">
                                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1" onclick="aplicarPresetModulos('todos')"><i class="bi bi-check-all me-1"></i> Todos</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 text-light" onclick="aplicarPresetModulos('ninguno')"><i class="bi bi-x me-1"></i> Ninguno</button>
                                <button type="button" class="btn btn-outline-info btn-sm rounded-pill px-3 py-1" onclick="aplicarPresetModulos('tickets')"><i class="bi bi-ticket-detailed-fill me-1"></i> Solo Tickets</button>
                                <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 py-1" onclick="aplicarPresetModulos('agencias')"><i class="bi bi-buildings-fill me-1"></i> Solo Agencias</button>
                                <button type="button" class="btn btn-outline-warning btn-sm rounded-pill px-3 py-1" onclick="aplicarPresetModulos('soporte')"><i class="bi bi-headset me-1"></i> Tickets + Agencias</button>
                            </div>
                        </div>

                        <div id="superadmin_notice" class="alert alert-warning border-0 rounded-3 py-2 small d-none mb-3">
                            <i class="bi bi-shield-fill-check me-2 fs-5"></i> <strong>Modo SuperAdmin:</strong> Este rol cuenta con acceso total e ilimitado a todos los módulos automáticamente.
                        </div>

                        <!-- Grid de tarjetas de módulos (3 módulos vigentes) -->
                        <div class="row g-2" id="gridModulosUsuario">
                            <?php foreach ($modulosCat as $mod): ?>
                                <div class="col-12 col-md-4">
                                    <label class="perm-module-card w-100" id="card_mod_<?php echo $mod['clave']; ?>" for="mod_check_<?php echo $mod['clave']; ?>">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden flex-grow-1">
                                            <div class="perm-module-icon bg-primary bg-opacity-20 text-info">
                                                <i class="bi <?php echo $mod['icono']; ?>"></i>
                                            </div>
                                            <div class="overflow-hidden">
                                                <div class="fw-bold text-white small text-truncate"><?php echo htmlspecialchars($mod['nombre']); ?></div>
                                                <div class="text-secondary" style="font-size: 0.7rem; line-height: 1.2;"><?php echo htmlspecialchars($mod['descripcion']); ?></div>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch m-0 ms-2">
                                            <input class="form-check-input mod-perm-check" type="checkbox" name="modulos_permitidos[]" value="<?php echo $mod['clave']; ?>" id="mod_check_<?php echo $mod['clave']; ?>" onchange="onModuloCheckboxChange('<?php echo $mod['clave']; ?>')">
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Sub-panel: Configuración de Áreas de Sistemas Autorizadas para Tickets -->
                        <div id="panelAreasTickets" class="mt-3 p-3 rounded-3" style="background: rgba(14, 36, 68, 0.75); border: 1px solid rgba(56, 189, 248, 0.4); display: none;">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 pb-2 border-bottom border-secondary border-opacity-25">
                                <div>
                                    <h6 class="fw-bold text-warning mb-0 d-flex align-items-center gap-2">
                                        <i class="bi bi-diagram-3-fill"></i> Áreas de Sistemas Autorizadas para Tickets
                                    </h6>
                                    <div class="text-secondary" style="font-size: 0.75rem;">
                                        Selecciona las áreas de tickets que este usuario puede ver. <strong>Si un área no está seleccionada, NO podrá verla por nada del mundo.</strong>
                                    </div>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2 rounded-pill fw-semibold" style="font-size: 0.72rem;" onclick="marcarTodasAreasTickets(true)"><i class="bi bi-check-all me-1"></i> Marcar TODAS</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 rounded-pill text-light" style="font-size: 0.72rem;" onclick="marcarTodasAreasTickets(false)"><i class="bi bi-x me-1"></i> Desmarcar</button>
                                </div>
                            </div>

                            <!-- Interruptor TODOS -->
                            <div class="mb-2 p-2 rounded-2" style="background: rgba(245, 158, 11, 0.1); border: 1px dashed rgba(245, 158, 11, 0.35);">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="check_area_todos" onchange="onToggleTodosAreas(this.checked)">
                                    <label class="form-check-label fw-bold text-warning small ms-1" for="check_area_todos" style="cursor: pointer;">
                                        <i class="bi bi-asterisk me-1"></i> TODOS (Acceso total a tickets de cualquier área de Sistemas)
                                    </label>
                                </div>
                            </div>

                            <!-- Grid de áreas oficiales -->
                            <div class="row g-2">
                                <?php 
                                $areasCatalogoSistemas = [
                                    'DESARROLLO'         => ['icono' => 'bi-code-slash', 'color' => '#38bdf8', 'desc' => 'Desarrollo de software y portales'],
                                    'CYBERSEGURIDAD'     => ['icono' => 'bi-shield-lock-fill', 'color' => '#f43f5e', 'desc' => 'Seguridad perimetral y accesos'],
                                    'INFRAESTRUCTURA'    => ['icono' => 'bi-hdd-rack-fill', 'color' => '#10b981', 'desc' => 'Servidores, enlaces y site'],
                                    'REDES SOCIALES'     => ['icono' => 'bi-share-fill', 'color' => '#a855f7', 'desc' => 'Gestión de redes y perfiles'],
                                    'AUDITORIA'          => ['icono' => 'bi-clipboard-check-fill', 'color' => '#f59e0b', 'desc' => 'Revisiones, normas e inventarios'],
                                    'CORPORATIVO'        => ['icono' => 'bi-building-fill', 'color' => '#6366f1', 'desc' => 'Mesa de ayuda corporativa'],
                                    'DIRECCION SISTEMAS' => ['icono' => 'bi-diagram-3-fill', 'color' => '#06b6d4', 'desc' => 'Gestión, proyectos y dirección técnica TI']
                                ];
                                foreach ($areasCatalogoSistemas as $ak => $ainfo):
                                    $idChk = 'area_check_' . preg_replace('/[^a-zA-Z0-9]/', '_', $ak);
                                ?>
                                    <div class="col-6 col-md-4">
                                        <label class="d-flex align-items-center gap-2 p-2 rounded-2 border border-secondary border-opacity-25 w-100 mb-0 h-100" style="background: #081a33; cursor: pointer;" for="<?php echo $idChk; ?>">
                                            <input type="checkbox" class="form-check-input m-0 check-area-ticket flex-shrink-0" name="areas_tickets[]" value="<?php echo $ak; ?>" id="<?php echo $idChk; ?>" onchange="onIndividualAreaChange()">
                                            <i class="bi <?php echo $ainfo['icono']; ?> fs-5" style="color: <?php echo $ainfo['color']; ?>;"></i>
                                            <div class="overflow-hidden">
                                                <div class="fw-bold text-white text-truncate" style="font-size: 0.8rem;"><?php echo $ak; ?></div>
                                                <div class="text-secondary text-truncate" style="font-size: 0.65rem;"><?php echo $ainfo['desc']; ?></div>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold"><i class="bi bi-save me-1"></i> Guardar Usuario y Módulos</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: MATRIZ DE PERMISOS GRANULARES POR MÓDULO     -->
<!-- =================================================== -->
<div class="modal fade" id="modalPermisos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_permisos">
                <input type="hidden" name="target_user_id" id="perm_target_user_id" value="0">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-info mb-0"><i class="bi bi-shield-lock-fill me-2"></i> Acciones Granulares por Módulo</h5>
                        <small class="text-secondary">Usuario: <strong class="text-white" id="perm_target_username">---</strong></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0 rounded-3 mb-3 py-2 text-dark">
                        <i class="bi bi-info-circle-fill me-2"></i> Configura privilegios específicos dentro de cada módulo (Crear registros, Editar, Eliminar o Exportar a Excel).
                    </div>

                    <div class="table-responsive">
                        <table class="table table-custom table-bordered align-middle">
                            <thead>
                                <tr class="text-center">
                                    <th class="text-start">Módulo Corporativo</th>
                                    <th><i class="bi bi-eye text-info d-block"></i> Ver / Ingresar</th>
                                    <th><i class="bi bi-plus-square text-success d-block"></i> Crear</th>
                                    <th><i class="bi bi-pencil-square text-warning d-block"></i> Editar</th>
                                    <th><i class="bi bi-trash text-danger d-block"></i> Eliminar</th>
                                    <th><i class="bi bi-file-earmark-excel text-primary d-block"></i> Exportar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modulosCat as $mod): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi <?php echo $mod['icono']; ?> text-primary fs-5"></i>
                                                <div>
                                                    <div class="fw-bold text-white"><?php echo htmlspecialchars($mod['nombre']); ?></div>
                                                    <div class="small text-secondary" style="font-size: 0.75rem;"><?php echo htmlspecialchars($mod['descripcion']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-ver" name="permisos[<?php echo $mod['clave']; ?>][puede_ver]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-crear" name="permisos[<?php echo $mod['clave']; ?>][puede_crear]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-editar" name="permisos[<?php echo $mod['clave']; ?>][puede_editar]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-eliminar" name="permisos[<?php echo $mod['clave']; ?>][puede_eliminar]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-exportar" name="permisos[<?php echo $mod['clave']; ?>][puede_exportar]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info rounded-3 px-4 text-dark fw-bold"><i class="bi bi-shield-check me-1"></i> Guardar Permisos Detallados</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const permisosMapGlobal = <?php echo json_encode($permisosMap); ?>;
    const modulosCatGlobal = <?php echo json_encode($modulosCat); ?>;
    const areasCatalogoSistemas = <?php echo json_encode($areasCatalogoSistemas ?? []); ?>;
    let usuarioSeleccionadoActual = null;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Filtro en tiempo real para la tabla de usuarios
    function filtrarTablaUsuarios() {
        const query = (document.getElementById('filtroUsuarioInput')?.value || '').toLowerCase().trim();
        const filas = document.querySelectorAll('#tablaUsuarios tbody tr.usuario-row');
        filas.forEach(f => {
            const texto = f.innerText.toLowerCase();
            f.style.display = texto.includes(query) ? '' : 'none';
        });
    }

    // =========================================================
    // MODAL FLOTANTE: VER FICHA COMPLETA DEL USUARIO
    // =========================================================
    function abrirFichaUsuario(u) {
        usuarioSeleccionadoActual = u;

        // Iniciales para avatar visual
        const partes = (u.nombre || u.usuario || 'U').trim().split(/\s+/);
        let initials = partes[0] ? partes[0][0].toUpperCase() : 'U';
        if (partes.length > 1 && partes[1]) {
            initials += partes[1][0].toUpperCase();
        }
        document.getElementById('ficha_avatar').textContent = initials;

        // Nombre y etiquetas
        document.getElementById('ficha_nombre').textContent = u.nombre || u.usuario;
        document.getElementById('ficha_usuario_tag').innerHTML = '<i class="bi bi-person-badge me-1"></i> @' + escapeHtml(u.usuario);
        document.getElementById('ficha_agencia_text').innerHTML = '<i class="bi bi-geo-alt me-1 text-info"></i> ' + escapeHtml(u.agencia || 'Divol La Villa');

        // Badge Rol
        const badgeRol = document.getElementById('ficha_badge_rol');
        const rolLower = (u.rol || '').toLowerCase();
        if (rolLower === 'superadmin') {
            badgeRol.className = 'badge badge-role-superadmin rounded-pill px-3 py-1';
            badgeRol.innerHTML = '<i class="bi bi-shield-fill-check me-1"></i> SuperAdmin';
        } else if (rolLower === 'admin') {
            badgeRol.className = 'badge badge-role-admin rounded-pill px-3 py-1';
            badgeRol.innerHTML = '<i class="bi bi-shield-check me-1"></i> Admin';
        } else {
            badgeRol.className = 'badge badge-role-usuario rounded-pill px-3 py-1';
            badgeRol.innerHTML = '<i class="bi bi-person me-1"></i> Usuario';
        }

        // Badge Estatus
        const badgeStatus = document.getElementById('ficha_badge_status');
        const esActivo = parseInt(u.activo) === 1;
        if (esActivo) {
            badgeStatus.className = 'badge badge-status-active rounded-pill px-3 py-1';
            badgeStatus.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Activo';
        } else {
            badgeStatus.className = 'badge badge-status-inactive rounded-pill px-3 py-1';
            badgeStatus.innerHTML = '<i class="bi bi-x-circle me-1"></i> Inactivo';
        }

        // Valores Generales
        document.getElementById('ficha_val_usuario').textContent = u.usuario;
        document.getElementById('ficha_val_email').innerHTML = u.email ? `<a href="mailto:${escapeHtml(u.email)}" class="text-info text-decoration-none" title="Enviar correo"><i class="bi bi-box-arrow-up-right me-1 small"></i>${escapeHtml(u.email)}</a>` : '<span class="text-secondary">Sin correo</span>';
        document.getElementById('ficha_val_agencia').textContent = u.agencia || 'Divol La Villa';
        document.getElementById('ficha_val_area').textContent = u.area || 'Sistemas / TI';
        document.getElementById('ficha_val_puesto').textContent = u.puesto || u.cargo || 'Colaborador';
        let telStr = u.telefono || 'Sin registrar';
        if (u.extension) telStr += ` (Ext: ${u.extension})`;
        document.getElementById('ficha_val_telefono').textContent = telStr;
        document.getElementById('ficha_val_creado').textContent = u.creado_en || 'Previamente registrado';
        document.getElementById('ficha_val_id').textContent = '#' + u.id;
        document.getElementById('ficha_val_rol_nivel').textContent = rolLower === 'superadmin' ? 'Acceso Maestro Ilimitado' : (rolLower === 'admin' ? 'Administrador Corporativo' : 'Usuario con Módulos Específicos');

        // Contenedor de Módulos Autorizados
        const modContainer = document.getElementById('ficha_modulos_container');
        const uPerms = permisosMapGlobal[u.id] || {};
        let modsHtml = '';
        if (rolLower === 'superadmin') {
            modsHtml = `
                <div class="alert alert-danger bg-opacity-10 border border-danger border-opacity-30 rounded-3 py-2 px-3 small d-flex align-items-center gap-2 mb-0">
                    <i class="bi bi-shield-lock-fill fs-5 text-danger"></i>
                    <div>
                        <strong class="text-white">Acceso Total Maestro (SuperAdmin):</strong>
                        <div class="text-secondary">Este perfil dispone de acceso irrestricto y privilegios automáticos a todos los módulos y funciones del portal.</div>
                    </div>
                </div>
            `;
        } else {
            let modsActivos = [];
            modulosCatGlobal.forEach(m => {
                if (uPerms[m.clave] && parseInt(uPerms[m.clave].puede_ver) === 1) {
                    modsActivos.push(m);
                }
            });

            if (modsActivos.length > 0) {
                modsHtml = '<div class="row g-2">';
                modsActivos.forEach(m => {
                    modsHtml += `
                        <div class="col-md-4">
                            <div class="p-2 rounded-3 border border-info border-opacity-25 d-flex align-items-center gap-2" style="background: rgba(14, 165, 233, 0.08);">
                                <div class="rounded-2 p-2 bg-info bg-opacity-20 text-info d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                                    <i class="bi ${m.icono} fs-5"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <div class="fw-bold text-white small text-truncate">${escapeHtml(m.nombre)}</div>
                                    <div class="text-secondary text-truncate" style="font-size: 0.7rem;">${escapeHtml(m.descripcion)}</div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                modsHtml += '</div>';
            } else {
                modsHtml = `
                    <div class="alert alert-warning bg-opacity-10 border border-warning border-opacity-30 rounded-3 py-2 px-3 small text-warning mb-0">
                        <i class="bi bi-slash-circle me-1"></i> Este usuario actualmente no tiene ningún módulo asignado.
                    </div>
                `;
            }
        }
        modContainer.innerHTML = modsHtml;

        // Áreas de Tickets Asignadas
        const sectionAreas = document.getElementById('ficha_areas_tickets_section');
        const containerAreas = document.getElementById('ficha_areas_tickets_container');
        const tieneAccesoTickets = (rolLower === 'superadmin') || (uPerms['tickets'] && parseInt(uPerms['tickets'].puede_ver) === 1);

        if (tieneAccesoTickets) {
            sectionAreas.style.display = 'block';
            const areasStr = (u.areas_tickets || 'TODOS').trim().toUpperCase();
            let areasHtml = '';

            if (areasStr === 'TODOS' || areasStr === '*' || rolLower === 'superadmin') {
                areasHtml = `
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-40 rounded-pill px-3 py-2 fs-6">
                            <i class="bi bi-check-all me-1"></i> TODAS LAS ÁREAS DE SISTEMAS
                        </span>
                        <span class="text-secondary small">Puede visualizar, atender y filtrar tickets de cualquier área técnica de Sistemas.</span>
                    </div>
                `;
            } else if (areasStr === 'NINGUNA' || areasStr === '') {
                areasHtml = `
                    <span class="badge bg-danger bg-opacity-20 text-danger border border-danger border-opacity-40 rounded-pill px-3 py-2">
                        <i class="bi bi-slash-circle me-1"></i> NINGUNA ÁREA ASIGNADA (Acceso restringido)
                    </span>
                `;
            } else {
                const userAreasList = areasStr.split(',').map(s => s.trim().toUpperCase());
                areasHtml = '<div class="d-flex flex-wrap gap-2">';
                userAreasList.forEach(areaName => {
                    const areaInfo = areasCatalogoSistemas[areaName] || { icono: 'bi-diagram-3-fill', color: '#f59e0b', desc: '' };
                    areasHtml += `
                        <span class="badge rounded-pill px-3 py-2 border d-inline-flex align-items-center gap-2" style="background: rgba(15, 34, 61, 0.9); border-color: ${areaInfo.color} !important; color: #ffffff; font-size: 0.82rem;">
                            <i class="bi ${areaInfo.icono}" style="color: ${areaInfo.color};"></i>
                            <strong style="color: ${areaInfo.color};">${escapeHtml(areaName)}</strong>
                        </span>
                    `;
                });
                areasHtml += '</div>';
            }
            containerAreas.innerHTML = areasHtml;
        } else {
            sectionAreas.style.display = 'none';
        }

        // Resumen de Permisos Granulares
        const tbodyPerms = document.getElementById('ficha_tabla_permisos_body');
        let rowsPermsHtml = '';
        modulosCatGlobal.forEach(m => {
            const p = uPerms[m.clave] || {};
            const isSA = (rolLower === 'superadmin');
            const checkIcon = '<i class="bi bi-check-circle-fill text-success fs-5"></i>';
            const crossIcon = '<i class="bi bi-dash-circle text-secondary opacity-25 fs-5"></i>';

            const canVer = isSA || (parseInt(p.puede_ver) === 1);
            const canCrear = isSA || (parseInt(p.puede_crear) === 1);
            const canEditar = isSA || (parseInt(p.puede_editar) === 1);
            const canEliminar = isSA || (parseInt(p.puede_eliminar) === 1);
            const canExportar = isSA || (parseInt(p.puede_exportar) === 1);

            rowsPermsHtml += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi ${m.icono} text-info fs-5"></i>
                            <div>
                                <div class="fw-bold text-white small">${escapeHtml(m.nombre)}</div>
                                <div class="text-secondary" style="font-size: 0.7rem;">${escapeHtml(m.descripcion)}</div>
                            </div>
                        </div>
                    </td>
                    <td class="text-center">${canVer ? checkIcon : crossIcon}</td>
                    <td class="text-center">${canCrear ? checkIcon : crossIcon}</td>
                    <td class="text-center">${canEditar ? checkIcon : crossIcon}</td>
                    <td class="text-center">${canEliminar ? checkIcon : crossIcon}</td>
                    <td class="text-center">${canExportar ? checkIcon : crossIcon}</td>
                </tr>
            `;
        });
        tbodyPerms.innerHTML = rowsPermsHtml;

        // Botones de acción en pie de ficha
        document.getElementById('ficha_btn_editar').onclick = function() {
            const modalEl = document.getElementById('modalFichaUsuario');
            const modalInst = bootstrap.Modal.getInstance(modalEl);
            if (modalInst) modalInst.hide();
            setTimeout(() => abrirModalEditarUsuario(u), 350);
        };

        document.getElementById('ficha_btn_permisos').onclick = function() {
            const modalEl = document.getElementById('modalFichaUsuario');
            const modalInst = bootstrap.Modal.getInstance(modalEl);
            if (modalInst) modalInst.hide();
            setTimeout(() => abrirModalPermisos(u), 350);
        };

        // Desplegar modal flotante
        const modalFicha = new bootstrap.Modal(document.getElementById('modalFichaUsuario'));
        modalFicha.show();
    }

    function actualizarCardStyle(modClave) {
        const cb = document.getElementById('mod_check_' + modClave);
        const card = document.getElementById('card_mod_' + modClave);
        if (cb && card) {
            if (cb.checked) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        }
    }

    function actualizarVisibilidadPanelAreas() {
        const cbTickets = document.getElementById('mod_check_tickets');
        const panel = document.getElementById('panelAreasTickets');
        if (!panel) return;
        if (cbTickets && cbTickets.checked) {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    }

    function marcarTodasAreasTickets(marcar) {
        const checks = document.querySelectorAll('.check-area-ticket');
        checks.forEach(c => { c.checked = marcar; });
        const cbTodos = document.getElementById('check_area_todos');
        if (cbTodos) cbTodos.checked = marcar;
    }

    function onToggleTodosAreas(checked) {
        marcarTodasAreasTickets(checked);
    }

    function onIndividualAreaChange() {
        const checks = document.querySelectorAll('.check-area-ticket');
        const allChecked = Array.from(checks).length > 0 && Array.from(checks).every(c => c.checked);
        const cbTodos = document.getElementById('check_area_todos');
        if (cbTodos) cbTodos.checked = allChecked;
    }

    function onModuloCheckboxChange(modClave) {
        const rol = document.getElementById('form_rol').value;
        const cb = document.getElementById('mod_check_' + modClave);
        if (rol === 'SuperAdmin' && cb) {
            cb.checked = true; // SuperAdmin siempre tiene todos
        }
        actualizarCardStyle(modClave);
        if (modClave === 'tickets') {
            actualizarVisibilidadPanelAreas();
        }
    }

    function toggleModuloCard(modClave) {
        const rol = document.getElementById('form_rol').value;
        if (rol === 'SuperAdmin') return;

        const cb = document.getElementById('mod_check_' + modClave);
        if (cb) {
            cb.checked = !cb.checked;
            actualizarCardStyle(modClave);
            if (modClave === 'tickets') {
                actualizarVisibilidadPanelAreas();
            }
        }
    }

    function aplicarPresetModulos(preset) {
        const checkboxes = document.querySelectorAll('.mod-perm-check');
        checkboxes.forEach(cb => {
            const clave = cb.value;
            if (preset === 'todos') {
                cb.checked = true;
            } else if (preset === 'ninguno') {
                cb.checked = false;
            } else if (preset === 'tickets') {
                cb.checked = (clave === 'tickets');
            } else if (preset === 'agencias') {
                cb.checked = (clave === 'agencias');
            } else if (preset === 'soporte') {
                cb.checked = (clave === 'tickets' || clave === 'agencias');
            }
            actualizarCardStyle(clave);
        });
        actualizarVisibilidadPanelAreas();
    }

    function onRolChange() {
        const rol = document.getElementById('form_rol').value;
        const notice = document.getElementById('superadmin_notice');
        const checkboxes = document.querySelectorAll('.mod-perm-check');

        if (rol === 'SuperAdmin') {
            if (notice) notice.classList.remove('d-none');
            checkboxes.forEach(cb => {
                cb.checked = true;
                actualizarCardStyle(cb.value);
            });
            marcarTodasAreasTickets(true);
        } else {
            if (notice) notice.classList.add('d-none');
        }
        actualizarVisibilidadPanelAreas();
    }

    function abrirModalNuevoUsuario() {
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-person-plus text-primary me-2"></i> Registrar Nuevo Usuario y Permisos';
        document.getElementById('form_user_id').value = '0';
        document.getElementById('form_usuario').value = '';
        document.getElementById('form_nombre').value = '';
        document.getElementById('form_email').value = '';
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = 'Usuario';
        document.getElementById('form_agencia').value = 'Divol La Villa';
        document.getElementById('form_activo').checked = true;
        document.getElementById('pass_help').textContent = 'Obligatoria al crear un nuevo usuario.';

        // Preset inicial por defecto: Tickets de soporte habilitado y todas sus áreas autorizadas
        aplicarPresetModulos('tickets');
        marcarTodasAreasTickets(true);
        onRolChange();
        actualizarVisibilidadPanelAreas();

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalEditarUsuario(u) {
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Usuario y Módulos de Acceso';
        document.getElementById('form_user_id').value = u.id;
        document.getElementById('form_usuario').value = u.usuario;
        document.getElementById('form_nombre').value = u.nombre;
        document.getElementById('form_email').value = u.email;
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = u.rol;
        document.getElementById('form_agencia').value = u.agencia || 'Divol La Villa';
        document.getElementById('form_activo').checked = (parseInt(u.activo) === 1);
        document.getElementById('pass_help').textContent = 'Dejar en blanco si deseas mantener la clave actual.';

        // Cargar módulos autorizados para este usuario
        const checkboxes = document.querySelectorAll('.mod-perm-check');
        if (u.rol === 'SuperAdmin') {
            checkboxes.forEach(cb => {
                cb.checked = true;
                actualizarCardStyle(cb.value);
            });
            marcarTodasAreasTickets(true);
        } else {
            const uPerms = permisosMapGlobal[u.id] || {};
            checkboxes.forEach(cb => {
                const clave = cb.value;
                const tieneAcceso = (uPerms[clave] && parseInt(uPerms[clave].puede_ver) === 1);
                cb.checked = Boolean(tieneAcceso);
                actualizarCardStyle(clave);
            });

            // Cargar áreas de tickets autorizadas
            const areasStr = (u.areas_tickets || 'TODOS').trim().toUpperCase();
            if (areasStr === 'TODOS' || areasStr === '*') {
                marcarTodasAreasTickets(true);
            } else if (areasStr === 'NINGUNA' || areasStr === '') {
                marcarTodasAreasTickets(false);
            } else {
                marcarTodasAreasTickets(false);
                const arrAreas = areasStr.split(',').map(s => s.trim().toUpperCase());
                const checks = document.querySelectorAll('.check-area-ticket');
                checks.forEach(c => {
                    if (arrAreas.includes(c.value.toUpperCase())) {
                        c.checked = true;
                    }
                });
                onIndividualAreaChange();
            }
        }

        onRolChange();
        actualizarVisibilidadPanelAreas();

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalPermisos(u) {
        document.getElementById('perm_target_user_id').value = u.id;
        document.getElementById('perm_target_username').textContent = u.nombre + ' (' + u.usuario + ')';

        // Resetear todos los checkboxes
        const checkboxes = document.querySelectorAll('.perm-checkbox');
        checkboxes.forEach(cb => cb.checked = false);

        // Si es SuperAdmin, marcar todos
        if (u.rol === 'SuperAdmin') {
            checkboxes.forEach(cb => cb.checked = true);
        } else {
            const uPerms = permisosMapGlobal[u.id] || {};
            for (const moduloClave in uPerms) {
                const pm = uPerms[moduloClave];
                if (pm.puede_ver == 1) marcarCheck(moduloClave, 'perm-ver');
                if (pm.puede_crear == 1) marcarCheck(moduloClave, 'perm-crear');
                if (pm.puede_editar == 1) marcarCheck(moduloClave, 'perm-editar');
                if (pm.puede_eliminar == 1) marcarCheck(moduloClave, 'perm-eliminar');
                if (pm.puede_exportar == 1) marcarCheck(moduloClave, 'perm-exportar');
            }
        }

        const modal = new bootstrap.Modal(document.getElementById('modalPermisos'));
        modal.show();
    }

    function marcarCheck(modClave, claseCheck) {
        const el = document.querySelector('.' + claseCheck + '[data-mod="' + modClave + '"]');
        if (el) el.checked = true;
    }
</script>
</body>
</html>
