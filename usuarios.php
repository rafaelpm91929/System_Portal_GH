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

// ----------------------------------------------------
// PROCESAMIENTO DE ACCIONES (POST)
// ----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. GUARDAR / CREAR / EDITAR USUARIO
    if ($accion === 'guardar_usuario') {
        $id = intval($_POST['user_id'] ?? 0);
        $usuario = trim($_POST['usuario'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $rol = $_POST['rol'] ?? 'Usuario';
        $agencia = $_POST['agencia'] ?? 'VW Divol La Villa';
        $activo = isset($_POST['activo']) ? 1 : 0;

        if (empty($usuario) || empty($nombre) || empty($email)) {
            $error = "Por favor completa los campos obligatorios (Usuario, Nombre, Email).";
        } else {
            try {
                if ($id > 0) {
                    // Actualizar Usuario existente
                    if (!empty($password)) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE usuarios SET usuario=?, nombre=?, email=?, password=?, rol=?, agencia=?, activo=? WHERE id=?");
                        $stmt->execute([$usuario, $nombre, $email, $hash, $rol, $agencia, $activo, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE usuarios SET usuario=?, nombre=?, email=?, rol=?, agencia=?, activo=? WHERE id=?");
                        $stmt->execute([$usuario, $nombre, $email, $rol, $agencia, $activo, $id]);
                    }
                    $mensaje = "Usuario <strong>".htmlspecialchars($usuario)."</strong> actualizado con éxito.";
                } else {
                    // Crear nuevo Usuario
                    if (empty($password)) {
                        $error = "La contraseña es obligatoria para un usuario nuevo.";
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, email, password, rol, agencia, activo) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$usuario, $nombre, $email, $hash, $rol, $agencia, $activo]);
                        $mensaje = "Nuevo usuario <strong>".htmlspecialchars($usuario)."</strong> creado correctamente.";
                    }
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
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
                // Obtener todos los módulos activos del catálogo
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

                $mensaje = "Matriz de permisos granulares actualizada correctamente.";
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
            $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
            $stmt->execute([$nuevo_estatus, $user_id]);
            $mensaje = "Estatus del usuario modificado correctamente.";
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
    try {
        // Obtener usuarios
        $stmtU = $pdo->query("SELECT * FROM usuarios ORDER BY id DESC");
        $usuarios = $stmtU->fetchAll(PDO::FETCH_ASSOC);

        // Obtener catálogo de módulos
        $stmtM = $pdo->query("SELECT * FROM modulos WHERE estatus = 1 ORDER BY orden ASC");
        $modulosCat = $stmtM->fetchAll(PDO::FETCH_ASSOC);

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

    <!-- Encabezado de Acción -->
    <div class="card-custom mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i> Usuarios del Sistema</h4>
                <p class="text-secondary small mb-0">Crea nuevos usuarios y asigna permisos dinámicos por módulo y tipo de acción (Ver, Crear, Editar, Eliminar, Exportar).</p>
            </div>
            <button type="button" class="btn btn-primary rounded-3 px-4 fw-semibold" onclick="abrirModalNuevoUsuario()">
                <i class="bi bi-person-plus-fill me-2"></i> Nuevo Usuario
            </button>
        </div>
    </div>

    <!-- Tabla de Usuarios -->
    <div class="card-custom">
        <div class="table-responsive">
            <table class="table table-custom table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Nombre Completo</th>
                        <th>Correo Electrónico</th>
                        <th>Rol</th>
                        <th>Estatus</th>
                        <th class="text-end">Acciones / Permisos Granulares</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-secondary">
                                <i class="bi bi-info-circle fs-4 d-block mb-2"></i> No se encontraron usuarios en la base de datos. Usa el botón 'Nuevo Usuario' para registrar el primero.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td class="fw-bold text-secondary">#<?php echo $u['id']; ?></td>
                                <td><span class="badge bg-dark border text-light font-monospace fs-6"><?php echo htmlspecialchars($u['usuario']); ?></span></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($u['nombre']); ?></td>
                                <td class="text-secondary"><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <?php if ($u['rol'] === 'SuperAdmin'): ?>
                                        <span class="badge bg-danger rounded-pill px-3">SuperAdmin</span>
                                    <?php elseif ($u['rol'] === 'Admin'): ?>
                                        <span class="badge bg-primary rounded-pill px-3">Admin</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill px-3">Usuario</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['activo']): ?>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-3"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary rounded-pill px-3"><i class="bi bi-x-circle me-1"></i> Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <!-- Configurar Permisos Granulares -->
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-2 me-1" onclick='abrirModalPermisos(<?php echo json_encode($u); ?>)' title="Configurar Permisos por Módulo">
                                            <i class="bi bi-shield-lock-fill me-1"></i> Permisos
                                        </button>

                                        <!-- Editar Datos -->
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-2 me-1" onclick='abrirModalEditarUsuario(<?php echo json_encode($u); ?>)' title="Editar datos del usuario">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- Alternar Estatus -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas cambiar el estatus de este usuario?');">
                                            <input type="hidden" name="accion" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="nuevo_estatus" value="<?php echo $u['activo'] ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $u['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> rounded-2" title="<?php echo $u['activo'] ? 'Desactivar' : 'Activar'; ?>">
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
<!-- MODAL: CREAR / EDITAR USUARIO                       -->
<!-- =================================================== -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_usuario">
                <input type="hidden" name="user_id" id="form_user_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalUsuarioLabel"><i class="bi bi-person-plus text-primary me-2"></i> Nuevo Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Nombre de Usuario (Login)</label>
                        <input type="text" name="usuario" id="form_usuario" class="form-control" placeholder="ej. tilavilla" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Nombre Completo</label>
                        <input type="text" name="nombre" id="form_nombre" class="form-control" placeholder="ej. Juan Pérez" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Correo Electrónico</label>
                        <input type="email" name="email" id="form_email" class="form-control" placeholder="ej. sistemas@divolavilla.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Contraseña</label>
                        <input type="password" name="password" id="form_password" class="form-control" placeholder="Dejar en blanco si no deseas cambiarla">
                        <small class="text-muted fs-7" id="pass_help">Requerida al crear un usuario nuevo.</small>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Rol del Sistema</label>
                            <select name="rol" id="form_rol" class="form-select">
                                <option value="Usuario">Usuario (Limitado por Permisos)</option>
                                <option value="Admin">Admin (Acceso Total)</option>
                                <option value="SuperAdmin">SuperAdmin (Grupo Huerta)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Agencia</label>
                            <input type="text" name="agencia" id="form_agencia" class="form-control" value="VW Divol La Villa">
                        </div>
                    </div>

                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="activo" id="form_activo" value="1" checked>
                        <label class="form-check-label text-light" for="form_activo">Usuario Activo</label>
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
                        <h5 class="modal-title fw-bold text-info mb-0"><i class="bi bi-shield-lock-fill me-2"></i> Permisos Granulares por Módulo</h5>
                        <small class="text-secondary">Usuario: <strong class="text-white" id="perm_target_username">---</strong></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info border-0 rounded-3 mb-3 py-2 text-dark">
                        <i class="bi bi-info-circle-fill me-2"></i> Marca las acciones permitidas para este usuario en cada módulo. Si se registra un módulo nuevo a futuro, aparecerá automáticamente en esta tabla.
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
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-person-plus text-primary me-2"></i> Nuevo Usuario';
        document.getElementById('form_user_id').value = '0';
        document.getElementById('form_usuario').value = '';
        document.getElementById('form_nombre').value = '';
        document.getElementById('form_email').value = '';
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = 'Usuario';
        document.getElementById('form_agencia').value = 'VW Divol La Villa';
        document.getElementById('form_activo').checked = true;
        document.getElementById('pass_help').textContent = 'Requerida al crear un usuario nuevo.';

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalEditarUsuario(u) {
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Usuario';
        document.getElementById('form_user_id').value = u.id;
        document.getElementById('form_usuario').value = u.usuario;
        document.getElementById('form_nombre').value = u.nombre;
        document.getElementById('form_email').value = u.email;
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = u.rol;
        document.getElementById('form_agencia').value = u.agencia || 'VW Divol La Villa';
        document.getElementById('form_activo').checked = (parseInt(u.activo) === 1);
        document.getElementById('pass_help').textContent = 'Dejar en blanco si deseas mantener la clave actual.';

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalPermisos(u) {
        document.getElementById('perm_target_user_id').value = u.id;
        document.getElementById('perm_target_username').textContent = u.nombre + ' (' + u.usuario + ')';

        // Resetear todos los checkboxes
        const checkboxes = document.querySelectorAll('.perm-checkbox');
        checkboxes.forEach(cb => cb.checked = false);

        // Si es Admin o SuperAdmin, marcar todos por cortesía visual
        if (u.rol === 'SuperAdmin' || u.rol === 'Admin') {
            checkboxes.forEach(cb => cb.checked = true);
        } else {
            // Cargar permisos específicos del objeto JS
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
