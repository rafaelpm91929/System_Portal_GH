<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión y Rol de Administración
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
if (!in_array($rolActual, ['superadmin', 'admin'])) {
    requerirPermiso('usuarios', 'puede_ver');
}

$mensaje = '';
$error = '';

// Auto-asegurar existencia de las tablas en MySQL para la agencia Divol La Villa
if ($pdo) {
    asegurarTablasPermisos($pdo);
}

// ----------------------------------------------------
// PROCESAMIENTO DE FORMULARIOS (POST) - DIVOL LA VILLA
// ----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. GUARDAR / CREAR / EDITAR USUARIOS Y ASIGNAR ROLES
    if ($accion === 'guardar_usuario') {
        $id = intval($_POST['user_id'] ?? 0);
        $usuario = trim($_POST['usuario'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $rol = $_POST['rol'] ?? 'Usuario';
        $agencia = 'VW Divol La Villa';
        $activo = isset($_POST['activo']) ? 1 : 0;

        if (empty($usuario) || empty($nombre) || empty($email)) {
            $error = "Por favor completa los campos obligatorios: Usuario, Nombre y Correo.";
        } else {
            try {
                if ($id > 0) {
                    // Actualizar usuario existente en Divol La Villa
                    if (!empty($password)) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE usuarios SET usuario=?, nombre=?, email=?, password=?, rol=?, agencia=?, activo=? WHERE id=?");
                        $stmt->execute([$usuario, $nombre, $email, $hash, $rol, $agencia, $activo, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE usuarios SET usuario=?, nombre=?, email=?, rol=?, agencia=?, activo=? WHERE id=?");
                        $stmt->execute([$usuario, $nombre, $email, $rol, $agencia, $activo, $id]);
                    }
                    $mensaje = "Usuario <strong>".htmlspecialchars($nombre)."</strong> ($usuario) actualizado correctamente con rol <strong>$rol</strong>.";
                    $nuevo_user_id = $id;
                } else {
                    // Crear nuevo usuario en Divol La Villa
                    if (empty($password)) {
                        $error = "La contraseña es obligatoria para registrar un nuevo usuario.";
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, email, password, rol, agencia, activo) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$usuario, $nombre, $email, $hash, $rol, $agencia, $activo]);
                        $nuevo_user_id = $pdo->lastInsertId();
                        $mensaje = "¡Usuario <strong>".htmlspecialchars($nombre)."</strong> ($usuario) registrado exitosamente con rol <strong>$rol</strong>!";
                    }
                }

                // Asignación automática de permisos por defecto según el rol seleccionado
                if (isset($nuevo_user_id) && $nuevo_user_id > 0) {
                    $stmtMod = $pdo->query("SELECT clave FROM modulos WHERE estatus = 1");
                    $modulos = $stmtMod->fetchAll(PDO::FETCH_COLUMN);

                    $stmtPerm = $pdo->prepare("
                        INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE puede_ver=VALUES(puede_ver), puede_crear=VALUES(puede_crear), puede_editar=VALUES(puede_editar), puede_exportar=VALUES(puede_exportar)
                    ");

                    foreach ($modulos as $modClave) {
                        if (in_array(strtolower($rol), ['superadmin', 'admin'])) {
                            // Admins tienen permisos totales habilitados por defecto
                            $stmtPerm->execute([$nuevo_user_id, $modClave, 1, 1, 1, 1, 1]);
                        } else {
                            // Rol Usuario: Acceso a consulta de Órdenes e Inventario de Equipos
                            $puedeVer = in_array($modClave, ['ordenes_servicio', 'equipos']) ? 1 : 0;
                            $stmtPerm->execute([$nuevo_user_id, $modClave, $puedeVer, 0, 0, 0, 0]);
                        }
                    }
                }

            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "El usuario o correo electrónico ya se encuentra registrado en Divol La Villa.";
                } else {
                    $error = "Error al guardar el usuario: " . $e->getMessage();
                }
            }
        }
    }

    // 2. ACTUALIZAR ROLES RÁPIDAMENTE
    elseif ($accion === 'cambiar_rol') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $nuevo_rol = $_POST['nuevo_rol'] ?? 'Usuario';

        if ($user_id > 0) {
            $stmt = $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
            $stmt->execute([$nuevo_rol, $user_id]);
            $mensaje = "Rol del usuario actualizado a <strong>$nuevo_rol</strong>.";
        }
    }

    // 3. CAMBIAR ESTATUS (ACTIVAR / DESACTIVAR)
    elseif ($accion === 'toggle_status') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $nuevo_estatus = intval($_POST['nuevo_estatus'] ?? 1);
        if ($user_id > 0) {
            $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
            $stmt->execute([$nuevo_estatus, $user_id]);
            $mensaje = "Estatus de usuario modificado correctamente.";
        }
    }

    // 4. GUARDAR PERMISOS POR MÓDULO (SI REQUIERE AJUSTES DETALLADOS)
    elseif ($accion === 'guardar_permisos') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        $permisos_posted = $_POST['permisos'] ?? [];

        if ($target_user_id > 0) {
            try {
                $stmtMod = $pdo->query("SELECT clave FROM modulos WHERE estatus = 1");
                $modulosCatalog = $stmtMod->fetchAll(PDO::FETCH_COLUMN);

                $stmtSave = $pdo->prepare("
                    INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        puede_ver = VALUES(puede_ver),
                        puede_crear = VALUES(puede_crear),
                        puede_editar = VALUES(puede_editar),
                        puede_eliminar = VALUES(puede_eliminar),
                        puede_exportar = VALUES(puede_exportar)
                ");

                foreach ($modulosCatalog as $mClave) {
                    $pVer      = isset($permisos_posted[$mClave]['puede_ver']) ? 1 : 0;
                    $pCrear    = isset($permisos_posted[$mClave]['puede_crear']) ? 1 : 0;
                    $pEditar   = isset($permisos_posted[$mClave]['puede_editar']) ? 1 : 0;
                    $pEliminar = isset($permisos_posted[$mClave]['puede_eliminar']) ? 1 : 0;
                    $pExportar = isset($permisos_posted[$mClave]['puede_exportar']) ? 1 : 0;

                    $stmtSave->execute([$target_user_id, $mClave, $pVer, $pCrear, $pEditar, $pEliminar, $pExportar]);
                }

                $mensaje = "Permisos por módulo guardados correctamente.";
            } catch (PDOException $e) {
                $error = "Error al actualizar los permisos: " . $e->getMessage();
            }
        }
    }
}

// ----------------------------------------------------
// CONSULTA DE USUARIOS Y MÓDULOS DE DIVOL LA VILLA
// ----------------------------------------------------

$usuarios = [];
$modulosCat = [];
$permisosMap = [];

if ($pdo) {
    try {
        $stmtU = $pdo->query("SELECT * FROM usuarios ORDER BY id DESC");
        $usuarios = $stmtU->fetchAll(PDO::FETCH_ASSOC);

        $stmtM = $pdo->query("SELECT * FROM modulos WHERE estatus = 1 ORDER BY orden ASC");
        $modulosCat = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        $stmtP = $pdo->query("SELECT * FROM usuario_permisos");
        $allPerms = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allPerms as $pm) {
            $permisosMap[$pm['usuario_id']][$pm['modulo_clave']] = $pm;
        }
    } catch (PDOException $e) {
        $error = "Error al consultar los usuarios de Divol La Villa: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios y Roles - VW Divol La Villa</title>
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
            color: #e2e8f0;
        }
        .table-custom th {
            background-color: #0f223d;
            color: #94a3b8;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .table-custom td {
            vertical-align: middle;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
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
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Volver al Menú Principal
        </a>
        <span class="fw-bold fs-5">DIVOL LA VILLA <span class="text-primary">| Control de Usuarios y Roles</span></span>
    </div>
    <div>
        <span class="badge bg-primary p-2 fs-6"><i class="bi bi-shield-check me-1"></i> VW Divol La Villa</span>
    </div>
</div>

<div class="container-fluid px-4">

    <!-- Mensajes de Notificación -->
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

    <!-- Encabezado de Acción -->
    <div class="card-custom mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i> Usuarios de Divol La Villa</h4>
                <p class="text-secondary small mb-0">Crea usuarios para la agencia y asigna su rol de acceso (SuperAdmin, Admin o Usuario).</p>
            </div>
            <button type="button" class="btn btn-primary rounded-3 px-4 fw-semibold" onclick="abrirModalNuevoUsuario()">
                <i class="bi bi-person-plus-fill me-2"></i> Crear Nuevo Usuario
            </button>
        </div>
    </div>

    <!-- Tabla de Usuarios Registrados -->
    <div class="card-custom">
        <div class="table-responsive">
            <table class="table table-custom table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario (Login)</th>
                        <th>Nombre Completo</th>
                        <th>Correo Electrónico</th>
                        <th>Rol Asignado</th>
                        <th>Estatus</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-secondary">
                                <i class="bi bi-info-circle fs-4 d-block mb-2"></i> No hay usuarios registrados aún. Haz clic en 'Crear Nuevo Usuario' para registrar el primero.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td class="fw-bold text-secondary">#<?php echo $u['id']; ?></td>
                                <td><span class="badge bg-dark border text-info font-monospace fs-6 px-3 py-2"><?php echo htmlspecialchars($u['usuario']); ?></span></td>
                                <td class="fw-semibold text-white"><?php echo htmlspecialchars($u['nombre']); ?></td>
                                <td class="text-secondary"><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <!-- Formulario inline para cambio de Rol -->
                                    <form method="POST" class="d-inline-flex align-items-center gap-1">
                                        <input type="hidden" name="accion" value="cambiar_rol">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <select name="nuevo_rol" class="form-select form-select-sm bg-dark text-white border-secondary rounded-2" onchange="this.form.submit()" style="width: 140px;">
                                            <option value="Usuario" <?php echo ($u['rol'] === 'Usuario') ? 'selected' : ''; ?>>👤 Usuario</option>
                                            <option value="Admin" <?php echo ($u['rol'] === 'Admin') ? 'selected' : ''; ?>>🛡️ Admin</option>
                                            <option value="SuperAdmin" <?php echo ($u['rol'] === 'SuperAdmin') ? 'selected' : ''; ?>>👑 SuperAdmin</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($u['activo']): ?>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-3 py-2"><i class="bi bi-circle-fill me-1" style="font-size: 0.45rem;"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary rounded-pill px-3 py-2"><i class="bi bi-x-circle me-1"></i> Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <!-- Configurar Permisos Detallados -->
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-2 me-1" onclick='abrirModalPermisos(<?php echo json_encode($u); ?>)' title="Ver/Ajustar Permisos de Módulos">
                                            <i class="bi bi-shield-lock-fill me-1"></i> Permisos
                                        </button>

                                        <!-- Editar Datos / Clave -->
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-2 me-1" onclick='abrirModalEditarUsuario(<?php echo json_encode($u); ?>)' title="Editar nombre, correo o contraseña">
                                            <i class="bi bi-pencil-square me-1"></i> Editar
                                        </button>

                                        <!-- Alternar Activo / Inactivo -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas cambiar el estatus de este usuario?');">
                                            <input type="hidden" name="accion" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="nuevo_estatus" value="<?php echo $u['activo'] ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $u['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> rounded-2" title="<?php echo $u['activo'] ? 'Desactivar Usuario' : 'Activar Usuario'; ?>">
                                                <i class="bi <?php echo $u['activo'] ? 'bi-person-x-fill' : 'bi-person-check-fill'; ?>"></i>
                                            </button>
                                        </form>
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
<!-- MODAL: CREAR / EDITAR USUARIO DE DIVOL LA VILLA     -->
<!-- =================================================== -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_usuario">
                <input type="hidden" name="user_id" id="form_user_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalUsuarioLabel"><i class="bi bi-person-plus text-primary me-2"></i> Crear Usuario en Divol La Villa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Nombre de Usuario (Para Iniciar Sesión)</label>
                        <input type="text" name="usuario" id="form_usuario" class="form-control" placeholder="ej. tilavilla, jperez" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Nombre Completo del Usuario</label>
                        <input type="text" name="nombre" id="form_nombre" class="form-control" placeholder="ej. Juan Pérez" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Correo Electrónico Corporativo</label>
                        <input type="email" name="email" id="form_email" class="form-control" placeholder="ej. sistemas@divolavilla.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Contraseña de Acceso</label>
                        <input type="password" name="password" id="form_password" class="form-control" placeholder="Asigna una contraseña segura">
                        <small class="text-muted fs-7" id="pass_help">Requerida al registrar un nuevo usuario.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Selecciona el Rol de Usuario</label>
                        <select name="rol" id="form_rol" class="form-select border-primary fw-semibold">
                            <option value="Usuario">👤 Usuario (Acceso limitado por módulo)</option>
                            <option value="Admin">🛡️ Admin (Acceso total a Divol La Villa)</option>
                            <option value="SuperAdmin">👑 SuperAdmin (Administrador Central)</option>
                        </select>
                    </div>

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" name="activo" id="form_activo" value="1" checked>
                        <label class="form-check-label text-light" for="form_activo">Usuario Activo (Permite iniciar sesión)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4"><i class="bi bi-save me-1"></i> Guardar Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: PERMISOS DETALLADOS POR MÓDULO               -->
<!-- =================================================== -->
<div class="modal fade" id="modalPermisos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_permisos">
                <input type="hidden" name="target_user_id" id="perm_target_user_id" value="0">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-info mb-0"><i class="bi bi-shield-lock-fill me-2"></i> Permisos de Módulos</h5>
                        <small class="text-secondary">Usuario: <strong class="text-white" id="perm_target_username">---</strong></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-custom table-bordered align-middle">
                            <thead>
                                <tr class="text-center">
                                    <th class="text-start">Módulo</th>
                                    <th>Ver / Ingresar</th>
                                    <th>Crear</th>
                                    <th>Editar</th>
                                    <th>Eliminar</th>
                                    <th>Exportar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modulosCat as $mod): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi <?php echo $mod['icono']; ?> text-primary fs-5"></i>
                                                <div class="fw-bold text-white"><?php echo htmlspecialchars($mod['nombre']); ?></div>
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
                    <button type="submit" class="btn btn-info rounded-3 px-4 text-dark fw-bold"><i class="bi bi-shield-check me-1"></i> Guardar Permisos</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const permisosMapGlobal = <?php echo json_encode($permisosMap); ?>;

    function abrirModalNuevoUsuario() {
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-person-plus text-primary me-2"></i> Crear Usuario en Divol La Villa';
        document.getElementById('form_user_id').value = '0';
        document.getElementById('form_usuario').value = '';
        document.getElementById('form_nombre').value = '';
        document.getElementById('form_email').value = '';
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = 'Usuario';
        document.getElementById('form_activo').checked = true;
        document.getElementById('pass_help').textContent = 'Requerida al registrar un nuevo usuario.';

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalEditarUsuario(u) {
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Usuario - Divol La Villa';
        document.getElementById('form_user_id').value = u.id;
        document.getElementById('form_usuario').value = u.usuario;
        document.getElementById('form_nombre').value = u.nombre;
        document.getElementById('form_email').value = u.email;
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = u.rol;
        document.getElementById('form_activo').checked = (parseInt(u.activo) === 1);
        document.getElementById('pass_help').textContent = 'Dejar en blanco para mantener la contraseña actual.';

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalPermisos(u) {
        document.getElementById('perm_target_user_id').value = u.id;
        document.getElementById('perm_target_username').textContent = u.nombre + ' (' + u.usuario + ') - Rol: ' + u.rol;

        const checkboxes = document.querySelectorAll('.perm-checkbox');
        checkboxes.forEach(cb => cb.checked = false);

        if (u.rol === 'SuperAdmin' || u.rol === 'Admin') {
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
