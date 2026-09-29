<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión y Rol (SuperAdmin / Admin)
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
if (!in_array($rolActual, ['superadmin', 'admin'])) {
    die("Acceso restringido: Solo los administradores de sistemas pueden gestionar la base de datos.");
}

// Determinar el motor de BD activo
$engine = 'MySQL';
$dbInfoStr = '';
$driver = 'sqlite';
if ($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $engine = 'SQLite Local';
        $dbFile = __DIR__ . '/database/local_dev.sqlite';
        $dbInfoStr = file_exists($dbFile) ? realpath($dbFile) : 'database/local_dev.sqlite';
    } else {
        $engine = 'MySQL (cPanel / Servidor)';
        $dbInfoStr = getenv('MYSQL_HOST') ?: 'localhost / Remote MySQL';
    }
}

// Obtener la lista de todas las tablas en la base de datos
$tablas = [];
$conteoTablas = [];
if ($pdo) {
    try {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
            $tablas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $pdo->query("SHOW TABLES");
            $tablas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach ($tablas as $tName) {
            try {
                $c = $pdo->query("SELECT COUNT(*) FROM `$tName`")->fetchColumn();
                $conteoTablas[$tName] = intval($c);
            } catch (Throwable $t) {
                $conteoTablas[$tName] = 0;
            }
        }
    } catch (Throwable $e) {
        $tablas = [];
    }
}

$tablaSeleccionada = $_GET['tabla'] ?? ($tablas[0] ?? '');
if (!in_array($tablaSeleccionada, $tablas) && !empty($tablas)) {
    $tablaSeleccionada = $tablas[0];
}

$mensaje = '';
$errorQuery = '';
$sqlEjecutadoMsg = '';

// PROCESAMIENTO DE ACCIONES DE GESTIÓN (EDITAR, INSERTAR, ELIMINAR, SQL DIRECTO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && !empty($tablaSeleccionada)) {
    $accion = $_POST['accion'] ?? '';

    // ACCIÓN 1: EDITAR REGISTRO
    if ($accion === 'editar_registro') {
        $pkCol = $_POST['pk_col'] ?? 'id';
        $pkVal = $_POST['pk_val'] ?? '';
        $camposVal = $_POST['campos'] ?? [];

        if (!empty($pkCol) && $pkVal !== '') {
            $setParts = [];
            $params = [];
            foreach ($camposVal as $cName => $cVal) {
                if ($cName === $pkCol) continue;
                $setParts[] = "`$cName` = ?";
                $params[] = ($cVal === '' ? null : $cVal);
            }
            if (!empty($setParts)) {
                $params[] = $pkVal;
                $sqlUpd = "UPDATE `$tablaSeleccionada` SET " . implode(', ', $setParts) . " WHERE `$pkCol` = ?";
                try {
                    $stmtUpd = $pdo->prepare($sqlUpd);
                    $stmtUpd->execute($params);
                    $mensaje = "¡Registro #<strong>" . htmlspecialchars($pkVal) . "</strong> en la tabla <strong>" . htmlspecialchars($tablaSeleccionada) . "</strong> actualizado con éxito!";
                } catch (Throwable $t) {
                    $errorQuery = "Error al actualizar registro: " . $t->getMessage();
                }
            }
        }
    }

    // ACCIÓN 2: INSERTAR NUEVO REGISTRO
    elseif ($accion === 'insertar_registro') {
        $camposVal = $_POST['campos'] ?? [];
        $colNames = [];
        $colPlaceholders = [];
        $params = [];

        foreach ($camposVal as $cName => $cVal) {
            if ($cVal !== '') {
                $colNames[] = "`$cName`";
                $colPlaceholders[] = "?";
                $params[] = $cVal;
            }
        }

        if (!empty($colNames)) {
            $sqlIns = "INSERT INTO `$tablaSeleccionada` (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $colPlaceholders) . ")";
            try {
                $stmtIns = $pdo->prepare($sqlIns);
                $stmtIns->execute($params);
                $mensaje = "¡Nuevo registro agregado exitosamente a la tabla <strong>" . htmlspecialchars($tablaSeleccionada) . "</strong>!";
            } catch (Throwable $t) {
                $errorQuery = "Error al insertar registro: " . $t->getMessage();
            }
        } else {
            $errorQuery = "Por favor ingresa al menos un campo para insertar el registro.";
        }
    }

    // ACCIÓN 3: ELIMINAR REGISTRO
    elseif ($accion === 'eliminar_registro') {
        $pkCol = $_POST['pk_col'] ?? 'id';
        $pkVal = $_POST['pk_val'] ?? '';

        if (!empty($pkCol) && $pkVal !== '') {
            try {
                $stmtDel = $pdo->prepare("DELETE FROM `$tablaSeleccionada` WHERE `$pkCol` = ?");
                $stmtDel->execute([$pkVal]);
                $mensaje = "Registro #<strong>" . htmlspecialchars($pkVal) . "</strong> eliminado correctamente de la tabla <strong>" . htmlspecialchars($tablaSeleccionada) . "</strong>.";
            } catch (Throwable $t) {
                $errorQuery = "Error al eliminar registro: " . $t->getMessage();
            }
        }
    }

    // ACCIÓN 4: EJECUTAR COMANDO SQL DIRECTO
    elseif ($accion === 'ejecutar_sql') {
        $sqlQuery = trim($_POST['sql_query'] ?? '');
        if (!empty($sqlQuery)) {
            try {
                $stmtQuery = $pdo->query($sqlQuery);
                if (stripos($sqlQuery, 'SELECT') === 0) {
                    $resRows = $stmtQuery->fetchAll(PDO::FETCH_ASSOC);
                    $sqlEjecutadoMsg = "Consulta SELECT ejecutada con éxito. Registros obtenidos: " . count($resRows);
                } else {
                    $affected = $stmtQuery->rowCount();
                    $mensaje = "Sentencia SQL ejecutada correctamente. Filas afectadas: " . $affected;
                }
            } catch (Throwable $t) {
                $errorQuery = "Error al ejecutar consulta SQL: " . $t->getMessage();
            }
        }
    }
}

// Obtener estructura de columnas y Primary Key de la tabla seleccionada
$columnasInfo = [];
$primaryKey = 'id';
if ($pdo && !empty($tablaSeleccionada)) {
    try {
        if ($driver === 'sqlite') {
            $stmtCols = $pdo->query("PRAGMA table_info(`$tablaSeleccionada`)");
            $colsData = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
            foreach ($colsData as $cd) {
                $isPk = intval($cd['pk']) > 0;
                if ($isPk) $primaryKey = $cd['name'];
                $columnasInfo[] = [
                    'nombre' => $cd['name'],
                    'tipo'   => $cd['type'],
                    'is_pk'  => $isPk
                ];
            }
        } else {
            $stmtCols = $pdo->query("DESCRIBE `$tablaSeleccionada`");
            $colsData = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
            foreach ($colsData as $cd) {
                $isPk = strtolower($cd['Key']) === 'pri';
                if ($isPk) $primaryKey = $cd['Field'];
                $columnasInfo[] = [
                    'nombre' => $cd['Field'],
                    'tipo'   => $cd['Type'],
                    'is_pk'  => $isPk
                ];
            }
        }
    } catch (Throwable $e) {}
}

// Obtener registros actualizados (límite 100)
$columnas = array_column($columnasInfo, 'nombre');
$registros = [];
$totalFilas = 0;
if ($pdo && !empty($tablaSeleccionada)) {
    try {
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM `$tablaSeleccionada`");
        $totalFilas = intval($stmtCount->fetchColumn());

        $stmtRows = $pdo->query("SELECT * FROM `$tablaSeleccionada` LIMIT 100");
        $registros = $stmtRows->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if (empty($errorQuery)) $errorQuery = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Base de Datos - Portal de Sistemas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #040d1a;
            color: #f8fafc;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }
        .top-navbar {
            background: rgba(10, 25, 46, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 28px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .sidebar-tables {
            background: rgba(10, 25, 46, 0.7);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            min-height: calc(100vh - 65px);
            padding: 20px 14px;
        }
        .nav-table-link {
            color: #94a3b8;
            border-radius: 10px;
            padding: 9px 12px;
            margin-bottom: 4px;
            font-size: 0.84rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .nav-table-link:hover {
            color: #ffffff;
            background: rgba(37, 99, 235, 0.15);
            border-color: rgba(59, 130, 246, 0.2);
        }
        .nav-table-link.active {
            color: #ffffff;
            background: #2563eb;
            border-color: #2563eb;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
        }
        .card-custom {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 20px;
        }
        .table-custom {
            color: #e2e8f0;
            font-size: 0.82rem;
            margin-bottom: 0;
        }
        .table-custom thead th {
            background: #0f223d;
            color: #38bdf8;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 10px 14px;
            white-space: nowrap;
        }
        .table-custom tbody td {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 9px 12px;
            white-space: nowrap;
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table-custom tbody tr:hover {
            background-color: rgba(37, 99, 235, 0.1) !important;
        }
        .badge-engine {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
            font-size: 0.78rem;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
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
            font-size: 0.85rem;
        }
        .form-control:focus, .form-select:focus {
            background-color: #132a4b;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.25);
        }
    </style>
</head>
<body>

<!-- Navbar Superior -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Volver al Menú
        </a>
        <span class="fw-bold fs-5">PORTAL DE SISTEMAS <span class="text-primary">| Administrador de Base de Datos</span></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <button type="button" class="btn btn-outline-info btn-sm rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#consolaSqlCollapse">
            <i class="bi bi-terminal me-1"></i> Consola SQL
        </button>
        <span class="badge-engine">
            <i class="bi bi-database-fill me-1"></i> Engine: <strong><?php echo htmlspecialchars($engine); ?></strong>
        </span>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar de Tablas -->
        <div class="col-md-3 col-lg-2 px-0 sidebar-tables">
            <div class="px-2 mb-3 d-flex justify-content-between align-items-center">
                <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.08em; font-size: 0.7rem;">Tablas del Sistema (<?php echo count($tablas); ?>)</span>
            </div>
            <div class="nav flex-column">
                <?php foreach ($tablas as $tName): ?>
                    <?php $cantF = $conteoTablas[$tName] ?? 0; ?>
                    <a href="db_explorer.php?tabla=<?php echo urlencode($tName); ?>" class="nav-table-link <?php echo ($tName === $tablaSeleccionada) ? 'active' : ''; ?>">
                        <span class="text-truncate"><i class="bi bi-table me-2 opacity-75"></i> <?php echo htmlspecialchars($tName); ?></span>
                        <span class="badge bg-dark text-info border border-secondary px-2 py-0.5 rounded-pill" style="font-size: 0.7rem;"><?php echo $cantF; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Contenido Principal -->
        <div class="col-md-9 col-lg-10 p-4">

            <!-- Consola SQL Plegable -->
            <div class="collapse mb-4" id="consolaSqlCollapse">
                <div class="card-custom border-info">
                    <h6 class="fw-bold text-info mb-2"><i class="bi bi-terminal-fill me-2"></i> Ejecutar Sentencia SQL Directa</h6>
                    <form method="POST">
                        <input type="hidden" name="accion" value="ejecutar_sql">
                        <textarea name="sql_query" class="form-control font-monospace mb-2" rows="3" placeholder="SELECT * FROM agencias;  o  UPDATE usuarios SET ...;"></textarea>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#consolaSqlCollapse">Cerrar</button>
                            <button type="submit" class="btn btn-sm btn-info text-dark fw-bold px-3"><i class="bi bi-play-fill me-1"></i> Ejecutar SQL</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Notificaciones -->
            <?php if ($mensaje): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($errorQuery): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($errorQuery); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Banner Encabezado -->
            <div class="card-custom mb-4" style="border-left: 4px solid #2563eb;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold text-white mb-1"><i class="bi bi-database-gear text-primary me-2"></i> Tabla: <span class="text-primary"><?php echo htmlspecialchars($tablaSeleccionada); ?></span></h4>
                        <span class="text-secondary small">
                            <i class="bi bi-folder2-open me-1"></i> Ruta / Conexión: <code class="text-info"><?php echo htmlspecialchars($dbInfoStr); ?></code>
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-success btn-sm rounded-3 px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#modalInsertarRegistro">
                            <i class="bi bi-plus-lg me-1"></i> Agregar Registro a <?php echo htmlspecialchars($tablaSeleccionada); ?>
                        </button>
                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary px-3 py-2 fs-6 rounded-pill fw-bold">
                            <?php echo $totalFilas; ?> Filas
                        </span>
                    </div>
                </div>
            </div>

            <!-- Buscador Dinámico de Registros -->
            <div class="card-custom mb-3 p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-search text-secondary"></i>
                    <input type="text" id="filtroTabla" class="form-control form-control-sm bg-dark text-white border-secondary px-3" placeholder="🔍 Filtrar registros de la tabla..." style="width: 280px;" onkeyup="filtrarRegistros()">
                </div>
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i> Haz clic en 'Editar' o 'Eliminar' para gestionar directamente cualquier fila.</small>
            </div>

            <!-- Tabla de Registros Interactiva -->
            <div class="card-custom">
                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                    <table class="table table-custom table-hover align-middle mb-0" id="tablaDatos">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 110px;">Acciones</th>
                                <?php foreach ($columnasInfo as $cInf): ?>
                                    <th>
                                        <?php echo htmlspecialchars($cInf['nombre']); ?>
                                        <?php if ($cInf['is_pk']): ?>
                                            <i class="bi bi-key-fill text-warning ms-1" title="Primary Key"></i>
                                        <?php endif; ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($registros)): ?>
                                <tr>
                                    <td colspan="<?php echo count($columnasInfo) + 1; ?>" class="text-center py-5 text-secondary">
                                        <i class="bi bi-inbox fs-2 d-block mb-2"></i> La tabla <strong><?php echo htmlspecialchars($tablaSeleccionada); ?></strong> está vacía. Usa el botón 'Agregar Registro' para insertar la primera fila.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($registros as $row): ?>
                                    <?php $pkValRow = $row[$primaryKey] ?? reset($row); ?>
                                    <tr>
                                        <!-- Botones de Gestión Editar y Eliminar -->
                                        <td class="text-center">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-warning p-1 px-2" onclick='abrirModalEditarRegistro(<?php echo json_encode($row); ?>)' title="Editar esta fila">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Confirmas eliminar el registro #<?php echo htmlspecialchars($pkValRow); ?> de la tabla <?php echo htmlspecialchars($tablaSeleccionada); ?>?');">
                                                    <input type="hidden" name="accion" value="eliminar_registro">
                                                    <input type="hidden" name="pk_col" value="<?php echo htmlspecialchars($primaryKey); ?>">
                                                    <input type="hidden" name="pk_val" value="<?php echo htmlspecialchars($pkValRow); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger p-1 px-2" title="Eliminar esta fila">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <?php foreach ($columnas as $col): ?>
                                            <td>
                                                <?php 
                                                $val = $row[$col] ?? null;
                                                if (is_null($val)) {
                                                    echo '<span class="text-muted small">NULL</span>';
                                                } elseif ($val === '') {
                                                    echo '<span class="text-muted small">(Vacío)</span>';
                                                } else {
                                                    echo htmlspecialchars(mb_strimwidth(strval($val), 0, 80, '...'));
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: EDITAR REGISTRO -->
<!-- =================================================== -->
<div class="modal fade" id="modalEditarRegistro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="editar_registro">
                <input type="hidden" name="pk_col" value="<?php echo htmlspecialchars($primaryKey); ?>">
                <input type="hidden" name="pk_val" id="edit_pk_val" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-warning"><i class="bi bi-pencil-square me-2"></i> Editar Fila en [<?php echo htmlspecialchars($tablaSeleccionada); ?>]</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalEditFieldsBody">
                    <!-- Los campos dinámicos se generan por JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning fw-bold px-4">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: INSERTAR NUEVO REGISTRO -->
<!-- =================================================== -->
<div class="modal fade" id="modalInsertarRegistro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="insertar_registro">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-success"><i class="bi bi-plus-circle me-2"></i> Agregar Registro a [<?php echo htmlspecialchars($tablaSeleccionada); ?>]</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <?php foreach ($columnasInfo as $cInf): ?>
                            <?php if ($cInf['is_pk'] && strpos(strtolower($cInf['tipo']), 'int') !== false): ?>
                                <!-- Saltar Autoincrement PK int -->
                            <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label text-secondary small fw-bold mb-1"><?php echo htmlspecialchars($cInf['nombre']); ?></label>
                                    <input type="text" name="campos[<?php echo htmlspecialchars($cInf['nombre']); ?>]" class="form-control form-control-sm" placeholder="Valor para <?php echo htmlspecialchars($cInf['nombre']); ?>">
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">Insertar Registro</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const primaryKeyCol = "<?php echo htmlspecialchars($primaryKey); ?>";

function abrirModalEditarRegistro(rowObj) {
    document.getElementById('edit_pk_val').value = rowObj[primaryKeyCol] || '';
    const container = document.getElementById('modalEditFieldsBody');
    let html = '<div class="row g-3">';

    for (const [key, val] of Object.entries(rowObj)) {
        const isPk = (key === primaryKeyCol);
        const displayVal = (val === null) ? '' : val;
        html += `
            <div class="col-md-6">
                <label class="form-label text-secondary small fw-bold mb-1">
                    ${key} ${isPk ? '<span class="badge bg-warning text-dark ms-1">PK</span>' : ''}
                </label>
                <input type="text" name="campos[${key}]" class="form-control form-control-sm" value="${displayVal.replace(/"/g, '&quot;')}" ${isPk ? 'readonly style="opacity: 0.6;"' : ''}>
            </div>
        `;
    }
    html += '</div>';
    container.innerHTML = html;

    const modal = new bootstrap.Modal(document.getElementById('modalEditarRegistro'));
    modal.show();
}

function filtrarRegistros() {
    const input = document.getElementById('filtroTabla');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('tablaDatos');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let visible = false;
        const td = tr[i].getElementsByTagName('td');
        for (let j = 0; j < td.length; j++) {
            if (td[j]) {
                const txtValue = td[j].textContent || td[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    visible = true;
                    break;
                }
            }
        }
        tr[i].style.display = visible ? '' : 'none';
    }
}
</script>

</body>
</html>
