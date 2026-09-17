<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión y Permisos
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

requerirPermiso('equipos', 'puede_ver');

// Auto-asegurar existencia de las 10 tablas en MySQL
if ($pdo) {
    asegurarTablasEquipos($pdo);
}

$seccion_activa = $_GET['sec'] ?? 'equipos_vw';
$mensaje = '';
$error = '';

// Definición Maestra de Secciones y Configuración de Campos
$SECCIONES = [
    'equipos_vw' => [
        'nombre' => 'Inventario Equipos VW',
        'tabla' => 'inv_equipos_vw',
        'icono' => 'bi-display-fill',
        'descripcion' => 'Equipos de cómputo activos (Desktop y Laptops) con expediente y credenciales.',
        'columnas' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo', 'serie', 'procesador', 'ram', 'sistema_op', 'no_break']
    ],
    'equipos_baja' => [
        'nombre' => 'Equipos Baja',
        'tabla' => 'inv_equipos_baja',
        'icono' => 'bi-trash-fill',
        'descripcion' => 'Histórico de computadoras dadas de baja en la sucursal.',
        'columnas' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo', 'serie', 'procesador', 'ram', 'fecha_compra']
    ],
    'nobreak_baja' => [
        'nombre' => 'Nobreak BAJA',
        'tabla' => 'inv_nobreak_baja',
        'icono' => 'bi-lightning-charge-fill',
        'descripcion' => 'Registro de reguladores y No-Breaks dados de baja.',
        'columnas' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'modelo_nobreak', 'serie_nobreak']
    ],
    'equipos_corp' => [
        'nombre' => 'Equipos Corporativo',
        'tabla' => 'inv_equipos_corp',
        'icono' => 'bi-building-fill',
        'descripcion' => 'Inventario de computadoras asignadas a la oficina corporativa.',
        'columnas' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo', 'serie', 'procesador', 'ram', 'correo']
    ],
    'moviles' => [
        'nombre' => 'Celulares-Tablets-Pantallas',
        'tabla' => 'inv_dispositivos_moviles',
        'icono' => 'bi-phone-fill',
        'descripcion' => 'Control de líneas móviles, tabletas de asesores y pantallas corporativas.',
        'columnas' => ['id', 'departamento', 'puesto', 'nombre', 'celular', 'tablet', 'pantalla', 'serie', 'mac', 'plan', 'proveedor']
    ],
    'archivo' => [
        'nombre' => 'Archivo',
        'tabla' => 'inv_archivo',
        'icono' => 'bi-archive-fill',
        'descripcion' => 'Resguardo de componentes y equipos en bodega/archivo.',
        'columnas' => ['id', 'departamento', 'puesto', 'nombre', 'monitor', 'pulgadas', 'serie']
    ],
    'monitores' => [
        'nombre' => 'Monitores',
        'tabla' => 'inv_monitores',
        'icono' => 'bi-aspect-ratio-fill',
        'descripcion' => 'Catálogo de pantallas y monitores secundarios asignados.',
        'columnas' => ['id', 'departamento', 'puesto', 'nombre', 'monitor', 'pulgadas', 'serie']
    ],
    'dvr' => [
        'nombre' => 'DVR / Cámaras',
        'tabla' => 'inv_dvr_camaras',
        'icono' => 'bi-camera-video-fill',
        'descripcion' => 'Control de grabadores DVR, NVR y cámaras de circuito cerrado (CCTV).',
        'columnas' => ['id', 'nombre', 'dvr', 'ip', 'numero_serie', 'almacenamiento', 'cam_totales_ip', 'disponibles_ip', 'modelo']
    ],
    'site_vw' => [
        'nombre' => 'Inventario SITE VW',
        'tabla' => 'inv_site_vw',
        'icono' => 'bi-hdd-rack-fill',
        'descripcion' => 'Servidores, switches, routers y racks alojados en el SITE principal.',
        'columnas' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'equipo', 'modelo', 'serie', 'fecha_compra', 'folio_factura']
    ],
    'licencias_office' => [
        'nombre' => 'Licencias Office',
        'tabla' => 'inv_licencias_office',
        'icono' => 'bi-key-fill',
        'descripcion' => 'Matriz de asignación de licencias de Microsoft Office.',
        'columnas' => ['id', 'licencia', 'area', 'puesto', 'nombre', 'nombre_equipo', 'serie_licencia', 'factura']
    ]
];

if (!isset($SECCIONES[$seccion_activa])) {
    $seccion_activa = 'equipos_vw';
}

$infoSeccion = $SECCIONES[$seccion_activa];
$tablaActual = $infoSeccion['tabla'];

// ----------------------------------------------------
// PROCESAMIENTO DE ACCIONES (CREAR / EDITAR / ELIMINAR)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar_registro') {
        $id = intval($_POST['registro_id'] ?? 0);
        $fields = $_POST['f'] ?? [];

        if (!empty($fields)) {
            try {
                if ($id > 0) {
                    // Update
                    $setPairs = [];
                    $params = [];
                    foreach ($fields as $col => $val) {
                        $setPairs[] = "`$col` = ?";
                        $params[] = trim($val);
                    }
                    $params[] = $id;
                    $sql = "UPDATE `$tablaActual` SET " . implode(', ', $setPairs) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $mensaje = "Registro actualizado correctamente.";
                } else {
                    // Insert
                    $cols = array_keys($fields);
                    $placeholders = array_fill(0, count($cols), '?');
                    $sql = "INSERT INTO `$tablaActual` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_values($fields));
                    $mensaje = "Registro guardado exitosamente.";
                }
            } catch (PDOException $e) {
                $error = "Error al procesar el registro: " . $e->getMessage();
            }
        }
    } elseif ($accion === 'eliminar_registro') {
        $id = intval($_POST['registro_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM `$tablaActual` WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = "Registro eliminado correctamente.";
            } catch (PDOException $e) {
                $error = "Error al eliminar registro: " . $e->getMessage();
            }
        }
    }
}

// ----------------------------------------------------
// CONSULTA DE REGISTROS DE LA SECCIÓN ACTIVA
// ----------------------------------------------------
$registros = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM `$tablaActual` ORDER BY id DESC");
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error al consultar la sección " . htmlspecialchars($infoSeccion['nombre']) . ": " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($infoSeccion['nombre']); ?> - Inventario de Equipos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #040d1a;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }
        .top-navbar {
            background: rgba(10, 25, 46, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 30px;
        }
        .sidebar-wrapper {
            background: #0a192e;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            min-height: calc(100vh - 65px);
            padding: 20px 12px;
        }
        .nav-link-custom {
            color: #94a3b8;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 4px;
            font-size: 0.88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .nav-link-custom:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }
        .nav-link-custom.active {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
        }
        .content-wrapper {
            padding: 30px;
        }
        .card-custom {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 24px;
        }
        .table-custom {
            color: #e2e8f0;
            font-size: 0.88rem;
        }
        .table-custom th {
            background-color: #0f223d;
            color: #94a3b8;
            font-size: 0.78rem;
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
            font-size: 0.9rem;
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

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Menú Principal
        </a>
        <span class="fw-bold fs-5">PORTAL DE SISTEMAS <span class="text-primary">| Inventario de Equipos</span></span>
    </div>
    <div>
        <span class="badge bg-primary p-2 fs-6"><i class="bi bi-display me-1"></i> Control de Hardware</span>
    </div>
</div>

<div class="container-fluid p-0">
    <div class="row g-0">

        <!-- BARRA LATERAL IZQUIERDA (SIDEBAR) -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper">
            <div class="small fw-bold text-secondary text-uppercase tracking-wider px-3 mb-3">Secciones de Inventario</div>
            <nav class="nav flex-column">
                <?php foreach ($SECCIONES as $key => $sec): ?>
                    <a href="equipos.php?sec=<?php echo $key; ?>" class="nav-link-custom <?php echo ($seccion_activa === $key) ? 'active' : ''; ?>">
                        <i class="bi <?php echo $sec['icono']; ?> fs-5"></i>
                        <span class="text-truncate"><?php echo htmlspecialchars($sec['nombre']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- PANEL DE CONTENIDO PRINCIPAL -->
        <div class="col-md-9 col-lg-10 content-wrapper">

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

            <!-- Encabezado de la Sección Activa -->
            <div class="card-custom mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h4 class="fw-bold mb-1">
                            <i class="bi <?php echo $infoSeccion['icono']; ?> text-primary me-2"></i>
                            <?php echo htmlspecialchars($infoSeccion['nombre']); ?>
                        </h4>
                        <p class="text-secondary small mb-0"><?php echo htmlspecialchars($infoSeccion['descripcion']); ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <input type="text" id="busquedaTabla" class="form-control form-control-sm px-3" placeholder="🔍 Buscar registro..." style="width: 240px;" onkeyup="filtrarTabla()">
                        <?php if (tienePermiso('equipos', 'puede_crear')): ?>
                            <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold" onclick="abrirModalNuevo()">
                                <i class="bi bi-plus-lg me-1"></i> Nuevo Registro
                            </button>
                        <?php endif; ?>
                        <?php if (tienePermiso('equipos', 'puede_exportar')): ?>
                            <button type="button" class="btn btn-outline-success btn-sm rounded-3 px-3" onclick="exportarTablaCSV()">
                                <i class="bi bi-file-earmark-excel me-1"></i> Exportar CSV
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tabla de Registros -->
            <div class="card-custom">
                <div class="table-responsive">
                    <table class="table table-custom table-hover align-middle mb-0" id="tablaEquipos">
                        <thead>
                            <tr>
                                <?php foreach ($infoSeccion['columnas'] as $col): ?>
                                    <th><?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($col))); ?></th>
                                <?php endforeach; ?>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($registros)): ?>
                                <tr>
                                    <td colspan="<?php echo count($infoSeccion['columnas']) + 1; ?>" class="text-center py-4 text-secondary">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i> No hay registros capturados en la sección <strong><?php echo htmlspecialchars($infoSeccion['nombre']); ?></strong>.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($registros as $reg): ?>
                                    <tr>
                                        <?php foreach ($infoSeccion['columnas'] as $col): ?>
                                            <td>
                                                <?php
                                                $val = $reg[$col] ?? '';
                                                if ($col === 'id') {
                                                    echo '<span class="fw-bold text-secondary">#' . $val . '</span>';
                                                } elseif ($col === 'estado') {
                                                    echo ($val === 'Activo') ? '<span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-2 py-1">Activo</span>' : '<span class="badge bg-danger bg-opacity-25 text-danger border border-danger rounded-pill px-2 py-1">' . htmlspecialchars($val) . '</span>';
                                                } else {
                                                    echo htmlspecialchars($val);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-warning rounded-2 me-1" onclick='abrirModalEditar(<?php echo json_encode($reg); ?>)' title="Editar">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <?php if (tienePermiso('equipos', 'puede_eliminar')): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas eliminar este registro?');">
                                                        <input type="hidden" name="accion" value="eliminar_registro">
                                                        <input type="hidden" name="registro_id" value="<?php echo $reg['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-2" title="Eliminar">
                                                            <i class="bi bi-trash-fill"></i>
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
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL DE CAPTURA / EDICIÓN DINÁMICO                 -->
<!-- =================================================== -->
<div class="modal fade" id="modalRegistro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_registro">
                <input type="hidden" name="registro_id" id="form_registro_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalRegistroLabel"><i class="bi bi-plus-circle text-primary me-2"></i> Nuevo Registro</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3" id="contenedorCamposForm">
                        <?php foreach ($infoSeccion['columnas'] as $col): ?>
                            <?php if ($col === 'id') continue; ?>
                            <div class="col-md-6">
                                <label class="form-label text-capitalize small fw-semibold text-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', $col)); ?></label>
                                <input type="text" name="f[<?php echo $col; ?>]" id="field_<?php echo $col; ?>" class="form-control form-control-sm" placeholder="Ingresa <?php echo htmlspecialchars(str_replace('_', ' ', $col)); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4"><i class="bi bi-save me-1"></i> Guardar Registro</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function abrirModalNuevo() {
        document.getElementById('modalRegistroLabel').innerHTML = '<i class="bi bi-plus-circle text-primary me-2"></i> Nuevo Registro - <?php echo htmlspecialchars($infoSeccion['nombre']); ?>';
        document.getElementById('form_registro_id').value = '0';
        
        const inputs = document.querySelectorAll('#contenedorCamposForm input');
        inputs.forEach(inp => inp.value = '');

        const modal = new bootstrap.Modal(document.getElementById('modalRegistro'));
        modal.show();
    }

    function abrirModalEditar(reg) {
        document.getElementById('modalRegistroLabel').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Registro #' + reg.id;
        document.getElementById('form_registro_id').value = reg.id;

        for (const col in reg) {
            const el = document.getElementById('field_' + col);
            if (el) {
                el.value = reg[col] || '';
            }
        }

        const modal = new bootstrap.Modal(document.getElementById('modalRegistro'));
        modal.show();
    }

    function filtrarTabla() {
        const query = document.getElementById('busquedaTabla').value.toLowerCase();
        const rows = document.querySelectorAll('#tablaEquipos tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }

    function exportarTablaCSV() {
        const table = document.getElementById('tablaEquipos');
        let csv = [];
        for (let i = 0; i < table.rows.length; i++) {
            let row = [], cols = table.rows[i].querySelectorAll('td, th');
            for (let j = 0; j < cols.length - 1; j++) {
                row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
            }
            csv.push(row.join(','));
        }
        const csvFile = new Blob([csv.join('\n')], {type: 'text/csv;charset=utf-8;'});
        const downloadLink = document.createElement('a');
        downloadLink.download = '<?php echo $seccion_activa; ?>_inventario.csv';
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = 'none';
        document.body.appendChild(downloadLink);
        downloadLink.click();
    }
</script>
</body>
</html>
