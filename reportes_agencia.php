<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Verificación de Sesión y Control de Acceso
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

if ($pdo) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
    asegurarTablasReportesAgencia($pdo);
}

requerirPermiso('reportes_agencia', 'puede_ver');

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$agenciaUsuario = $_SESSION['agencia'] ?? 'Agencia';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esAdmin = in_array($rolActual, ['superadmin', 'admin', 'administrador']);

$puedeCrear    = tienePermiso('reportes_agencia', 'puede_crear') || $esAdmin;
$puedeEditar   = tienePermiso('reportes_agencia', 'puede_editar') || $esAdmin;
$puedeEliminar = tienePermiso('reportes_agencia', 'puede_eliminar') || $esAdmin;
$puedeExportar = tienePermiso('reportes_agencia', 'puede_exportar') || $esAdmin;

// Cargar Ficha de la Agencia
$stmtAg = $pdo ? $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1") : null;
$agenciaData = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : [];
$agenciaNombre = !empty($agenciaData['nombre']) ? $agenciaData['nombre'] : $agenciaUsuario;
$logoAgencia = (!empty($agenciaData['logo_url']) && file_exists(__DIR__ . '/' . $agenciaData['logo_url'])) ? $agenciaData['logo_url'] : '';
$ultimaSync = $agenciaData['local_ultima_sincronizacion'] ?? null;

// Reporte activo seleccionado (?reporte=inventario_seminuevos)
$reporteActivo = trim($_GET['reporte'] ?? '');

// LIMPIEZA: Eliminar cualquier dato estático demo o de prueba previo para mostrar ÚNICAMENTE datos reales enviados por la API
if ($pdo) {
    try {
        $pdo->exec("DELETE FROM reportes_inventario_autos WHERE inventario LIKE 'SEM-2026-%' OR chasis LIKE 'TEST%' OR chasis = 'TEST1234567890' OR descripcion = 'Auto de Prueba'");
    } catch (Throwable $tClean) {}
}

// =========================================================================
// LÓGICA ESPECÍFICA PARA EL REPORTE: INVENTARIO DE SEMINUEVOS
// =========================================================================
if ($reporteActivo === 'inventario_seminuevos') {

    $filtroQ        = trim($_GET['q'] ?? '');
    $filtroAlmacen  = trim($_GET['almacen'] ?? '');
    $filtroMarca    = trim($_GET['marca'] ?? '');
    $filtroAnio     = trim($_GET['anio'] ?? '');
    $filtroColor    = trim($_GET['color'] ?? '');
    $filtroFechaI   = trim($_GET['fecha_desde'] ?? '');
    $filtroFechaF   = trim($_GET['fecha_hasta'] ?? '');

    $where = ["status = 'Disponible'"];
    $params = [];

    if (!empty($filtroQ)) {
        $where[] = "(chasis LIKE ? OR inventario LIKE ? OR descripcion LIKE ? OR motor LIKE ? OR cve_almacen LIKE ?)";
        $qParam = "%$filtroQ%";
        $params[] = $qParam;
        $params[] = $qParam;
        $params[] = $qParam;
        $params[] = $qParam;
        $params[] = $qParam;
    }
    if (!empty($filtroAlmacen)) {
        $where[] = "cve_almacen = ?";
        $params[] = $filtroAlmacen;
    }
    if (!empty($filtroMarca)) {
        $where[] = "marca = ?";
        $params[] = $filtroMarca;
    }
    if (!empty($filtroAnio)) {
        $where[] = "anio = ?";
        $params[] = $filtroAnio;
    }
    if (!empty($filtroColor)) {
        $where[] = "color = ?";
        $params[] = $filtroColor;
    }
    if (!empty($filtroFechaI)) {
        $where[] = "fecha_alta >= ?";
        $params[] = $filtroFechaI . ' 00:00:00';
    }
    if (!empty($filtroFechaF)) {
        $where[] = "fecha_alta <= ?";
        $params[] = $filtroFechaF . ' 23:59:59';
    }

    $whereSql = implode(" AND ", $where);

    // EXPORTACIÓN A EXCEL
    if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel' && $puedeExportar && $pdo) {
        $sqlExp = "SELECT * FROM reportes_inventario_autos WHERE $whereSql ORDER BY cve_almacen ASC, fecha_alta DESC";
        $stmtExp = $pdo->prepare($sqlExp);
        $stmtExp->execute($params);
        $rowsExp = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="Inventario_Seminuevos_' . date('Ymd_His') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Inventario Seminuevos</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
        echo '<table border="1" style="font-family: Arial, sans-serif; border-collapse: collapse;">';
        echo '<tr style="background: #0f223d; color: #ffffff; font-weight: bold; text-align: center;">';
        echo '<th>CveAlmacen</th><th>NombreAlmacen</th><th>Inventario</th><th>Descripcion</th><th>Chasis (VIN)</th><th>Color</th><th>Motor</th><th>Equipamiento</th><th>Marca</th><th>Año</th><th>Status</th><th>Precio de Venta</th><th>Costo Inventario</th><th>Importe Inventario</th><th>Fecha Alta</th>';
        echo '</tr>';

        foreach ($rowsExp as $r) {
            echo '<tr>';
            echo '<td align="center">' . htmlspecialchars($r['cve_almacen'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['nombre_almacen'] ?? '') . '</td>';
            echo '<td align="center"><b>' . htmlspecialchars($r['inventario'] ?? '') . '</b></td>';
            echo '<td>' . htmlspecialchars($r['descripcion'] ?? '') . '</td>';
            echo '<td align="center"><code>' . htmlspecialchars($r['chasis'] ?? '') . '</code></td>';
            echo '<td>' . htmlspecialchars($r['color'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['motor'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['equipamiento'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['marca'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['anio'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['status'] ?? 'Disponible') . '</td>';
            echo '<td align="right">' . number_format(floatval($r['precio_venta'] ?? 0), 2) . '</td>';
            echo '<td align="right">' . number_format(floatval($r['costo_inventario'] ?? 0), 2) . '</td>';
            echo '<td align="right">' . number_format(floatval($r['importe_inventario'] ?? 0), 2) . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['fecha_alta'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit();
    }

    // Consultas de datos
    $unidades = [];
    $totalUnidades = 0;
    $totalAlmacenes = 0;
    $totalImporteInventario = 0.0;
    $totalPrecioVenta = 0.0;
    $catalogoAlmacenes = [];
    $catalogoMarcas = [];
    $catalogoAnios = [];
    $catalogoColores = [];

    if ($pdo) {
        try {
            $stmtAlm = $pdo->query("SELECT DISTINCT cve_almacen, nombre_almacen FROM reportes_inventario_autos WHERE status = 'Disponible' ORDER BY cve_almacen ASC");
            $catalogoAlmacenes = $stmtAlm ? $stmtAlm->fetchAll(PDO::FETCH_ASSOC) : [];

            $stmtMar = $pdo->query("SELECT DISTINCT marca FROM reportes_inventario_autos WHERE status = 'Disponible' AND marca IS NOT NULL AND marca != '' ORDER BY marca ASC");
            $catalogoMarcas = $stmtMar ? $stmtMar->fetchAll(PDO::FETCH_COLUMN) : [];

            $stmtAni = $pdo->query("SELECT DISTINCT anio FROM reportes_inventario_autos WHERE status = 'Disponible' AND anio IS NOT NULL AND anio != '' ORDER BY anio DESC");
            $catalogoAnios = $stmtAni ? $stmtAni->fetchAll(PDO::FETCH_COLUMN) : [];

            $stmtCol = $pdo->query("SELECT DISTINCT color FROM reportes_inventario_autos WHERE status = 'Disponible' AND color IS NOT NULL AND color != '' ORDER BY color ASC");
            $catalogoColores = $stmtCol ? $stmtCol->fetchAll(PDO::FETCH_COLUMN) : [];

            $stmtKpi = $pdo->query("
                SELECT 
                    COUNT(*) as total_autos,
                    COUNT(DISTINCT cve_almacen) as total_almacenes,
                    COALESCE(SUM(importe_inventario), 0) as suma_importe,
                    COALESCE(SUM(precio_venta), 0) as suma_venta
                FROM reportes_inventario_autos 
                WHERE status = 'Disponible'
            ");
            $kpis = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : [];
            $totalUnidades = intval($kpis['total_autos'] ?? 0);
            $totalAlmacenes = intval($kpis['total_almacenes'] ?? 0);
            $totalImporteInventario = floatval($kpis['suma_importe'] ?? 0);
            $totalPrecioVenta = floatval($kpis['suma_venta'] ?? 0);

            $sqlList = "SELECT * FROM reportes_inventario_autos WHERE $whereSql ORDER BY cve_almacen ASC, fecha_alta DESC LIMIT 300";
            $stmtList = $pdo->prepare($sqlList);
            $stmtList->execute($params);
            $unidades = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($reporteActivo === 'inventario_seminuevos') ? 'Inventario de Seminuevos' : 'Reportes Agencia'; ?> - Portal de Sistemas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?php include_once 'pwa_head.php'; ?>
    <style>
        :root {
            --bg-deep: #030814;
            --bg-card: #081528;
            --bg-card-hover: #0c1f3b;
            --accent-green: #10b981;
            --accent-green-light: #34d399;
            --border-subtle: rgba(255, 255, 255, 0.08);
            --border-green: rgba(16, 185, 129, 0.3);
        }
        body {
            background-color: var(--bg-deep);
            background-image: radial-gradient(rgba(16, 185, 129, 0.06) 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 70px;
        }
        .top-navbar {
            background: rgba(4, 13, 26, 0.96);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-subtle);
            padding: 14px 35px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .hero-panel {
            background: linear-gradient(135deg, rgba(8, 21, 40, 0.95) 0%, rgba(5, 38, 28, 0.6) 100%);
            border: 1px solid var(--border-green);
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5), 0 0 20px rgba(16, 185, 129, 0.08);
            position: relative;
            overflow: hidden;
        }
        .card-kpi {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 20px;
            transition: all 0.25s ease;
        }
        .card-kpi:hover {
            transform: translateY(-3px);
            border-color: rgba(16, 185, 129, 0.4);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        .filter-box {
            background: #071324;
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 25px;
        }
        .report-box-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 18px;
            padding: 30px 24px;
            transition: all 0.28s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
        }
        .report-box-card:hover {
            transform: translateY(-6px);
            border-color: rgba(16, 185, 129, 0.45);
            box-shadow: 0 14px 35px rgba(0, 0, 0, 0.5), 0 0 20px rgba(16, 185, 129, 0.15);
            background: #0a1c36;
        }
        .table-custom {
            background: #081528 !important;
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }
        .table-custom table {
            margin-bottom: 0;
            color: #ffffff !important;
            background: transparent !important;
        }
        .table-custom th {
            background: #040c17 !important;
            color: #94a3b8 !important;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
            white-space: nowrap;
        }
        .table-custom td {
            background: transparent !important;
            color: #f1f5f9 !important;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
            vertical-align: middle;
            font-size: 0.88rem;
        }
        .table-custom tbody tr:hover td {
            background: #0c1e36 !important;
        }
        .form-control, .form-select {
            background: #050f1c !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            color: #ffffff !important;
            border-radius: 8px;
            font-size: 0.88rem;
        }
        .form-control:focus, .form-select:focus {
            background: #08172c !important;
            border-color: var(--accent-green) !important;
            color: #ffffff !important;
            box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.2) !important;
        }
        .badge-status-disponible {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <?php if (!empty($reporteActivo)): ?>
            <a href="reportes_agencia.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
                <i class="bi bi-arrow-left me-1"></i> Volver a Recuadros de Reportes
            </a>
        <?php else: ?>
            <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
                <i class="bi bi-arrow-left me-1"></i> Menú Principal
            </a>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($logoAgencia)): ?>
                <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="Logo" style="max-height: 28px; max-width: 100px; object-fit: contain;">
            <?php endif; ?>
            <span class="fw-bold fs-5">
                PORTAL DE SISTEMAS 
                <span class="text-success">
                    | <?php echo ($reporteActivo === 'inventario_seminuevos') ? 'Inventario de Seminuevos' : 'Reportes Agencia'; ?>
                </span>
            </span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if ($esAdmin): ?>
            <a href="agencia.php#servidor_local" class="btn btn-outline-success btn-sm rounded-3 px-3 text-white">
                <i class="bi bi-sliders me-1 text-success"></i> Parámetros Servidor Local
            </a>
        <?php endif; ?>
        <span class="badge bg-dark border border-secondary text-secondary p-2 small font-monospace">
            <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($agenciaNombre); ?>
        </span>
    </div>
</div>

<div class="container-fluid px-4" style="max-width: 1550px;">

    <?php if (empty($reporteActivo)): ?>
    <!-- ===================================================================== -->
    <!-- VISTA 1: CATÁLOGO DE REPORTES EN RECUADROS (GRID DE REPORTES)         -->
    <!-- ===================================================================== -->

    <!-- HERO PANEL HUB DE REPORTES -->
    <div class="hero-panel">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge bg-success bg-opacity-25 text-success border border-success px-3 py-1 rounded-pill">
                        <i class="bi bi-grid-fill me-1"></i> Centro de Reportes Operativos
                    </span>
                    <span class="badge bg-dark border border-secondary text-secondary px-3 py-1 rounded-pill small">
                        <i class="bi bi-shield-lock-fill text-warning me-1"></i> Cero Puertos Abiertos (Push HTTPS Saliente)
                    </span>
                </div>
                <h2 class="fw-bold text-white mb-2">Módulo de Reportes de Agencia</h2>
                <p class="text-secondary mb-0" style="max-width: 720px;">
                    Selecciona en los recuadros inferiores el reporte que deseas consultar y analizar en tiempo real. La información es alimentada de forma segura por la API local conectada al DMS/ERP.
                </p>
            </div>

            <!-- Estado Conector -->
            <div class="d-flex flex-column align-items-lg-end gap-2 w-100 w-lg-auto">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge p-2 px-3 rounded-pill <?php echo (!empty($ultimaSync)) ? 'bg-success bg-opacity-25 text-success border border-success' : 'bg-warning bg-opacity-25 text-warning border border-warning'; ?>">
                        <i class="bi <?php echo (!empty($ultimaSync)) ? 'bi-check-circle-fill' : 'bi-clock-fill'; ?> me-1"></i>
                        <?php echo (!empty($ultimaSync)) ? 'Conector Sincronizado' : 'Esperando Conexión'; ?>
                    </span>
                    <a href="agencia.php?descargar_conector_local=1" class="btn btn-success text-dark fw-bold btn-sm rounded-3 px-3">
                        <i class="bi bi-cloud-arrow-down-fill me-1"></i> Descargar Conector API (.php)
                    </a>
                </div>
                <div class="text-secondary small font-monospace">
                    Última Sincronización: <span class="text-info"><?php echo !empty($ultimaSync) ? htmlspecialchars($ultimaSync) : 'Sin transmisión previa'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- GRID DE RECUADROS DE REPORTES -->
    <div class="row g-4">

        <!-- RECUADRO 1: INVENTARIO DE SEMINUEVOS (ACTIVO Y LISTO) -->
        <div class="col-md-6 col-lg-4">
            <div class="report-box-card" style="border-color: rgba(16, 185, 129, 0.4);">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="p-3 rounded-3" style="background: rgba(16, 185, 129, 0.15); color: #34d399; font-size: 1.8rem;">
                            <i class="bi bi-car-front-fill"></i>
                        </div>
                        <span class="badge bg-success bg-opacity-25 text-success border border-success px-3 py-1 rounded-pill">
                            <i class="bi bi-check-circle-fill me-1"></i> DISPONIBLE
                        </span>
                    </div>
                    <h4 class="fw-bold text-white mb-2">Inventario de Seminuevos</h4>
                    <p class="text-secondary small mb-3">
                        Listado de unidades seminuevas y disponibles extraídas de la base de datos oficial <strong>GEDAS (AUAUTOS / GNCATMA)</strong> por almacén, chasis (VIN), color, motor y valor comercial.
                    </p>
                    <div class="p-3 rounded-3 mb-3" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06);">
                        <div class="row g-2 text-center">
                            <div class="col-6 border-end border-secondary border-opacity-25">
                                <div class="text-secondary" style="font-size: 0.72rem;">ESTATUS</div>
                                <div class="fw-bold text-success small font-monospace">Disponible</div>
                            </div>
                            <div class="col-6">
                                <div class="text-secondary" style="font-size: 0.72rem;">ORIGEN</div>
                                <div class="fw-bold text-info small font-monospace">GEDAS DMS</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <a href="reportes_agencia.php?reporte=inventario_seminuevos" class="btn btn-success text-dark fw-bold w-100 rounded-3 py-2 shadow-sm d-flex align-items-center justify-content-center gap-2">
                        <span>Ingresar al Reporte</span> <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- RECUADRO 2: ÓRDENES DE SERVICIO & TALLER (PRÓXIMAMENTE) -->
        <div class="col-md-6 col-lg-4">
            <div class="report-box-card" style="opacity: 0.85;">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="p-3 rounded-3" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; font-size: 1.8rem;">
                            <i class="bi bi-tools"></i>
                        </div>
                        <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary px-3 py-1 rounded-pill">
                            <i class="bi bi-hourglass-split me-1"></i> PRÓXIMAMENTE
                        </span>
                    </div>
                    <h4 class="fw-bold text-white mb-2">Órdenes de Servicio</h4>
                    <p class="text-secondary small mb-3">
                        Monitoreo de citas, autos en rampa, diagnósticos, paquetes de mantenimiento y estatus de entrega en taller mecánico.
                    </p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-secondary w-100 rounded-3 py-2 text-secondary" disabled>
                        En proceso de integración
                    </button>
                </div>
            </div>
        </div>

        <!-- RECUADRO 3: VENTAS Y FACTURACIÓN (PRÓXIMAMENTE) -->
        <div class="col-md-6 col-lg-4">
            <div class="report-box-card" style="opacity: 0.85;">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="p-3 rounded-3" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; font-size: 1.8rem;">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary px-3 py-1 rounded-pill">
                            <i class="bi bi-hourglass-split me-1"></i> PRÓXIMAMENTE
                        </span>
                    </div>
                    <h4 class="fw-bold text-white mb-2">Ventas y Facturación</h4>
                    <p class="text-secondary small mb-3">
                        Resumen de unidades entregadas, facturas emitidas, márgenes por asesor de ventas y formas de financiamiento.
                    </p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-secondary w-100 rounded-3 py-2 text-secondary" disabled>
                        En proceso de integración
                    </button>
                </div>
            </div>
        </div>

    </div>

    <?php else: ?>
    <!-- ===================================================================== -->
    <!-- VISTA 2: REPORTE EN DETALLE (INVENTARIO DE SEMINUEVOS)                 -->
    <!-- ===================================================================== -->

    <!-- HERO PANEL REPORTE DETALLE -->
    <div class="hero-panel">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge bg-success bg-opacity-25 text-success border border-success px-3 py-1 rounded-pill">
                        <i class="bi bi-car-front-fill me-1"></i> Consulta Oficial GEDAS: AUAUTOS & GNCATMA
                    </span>
                    <span class="badge bg-dark border border-secondary text-secondary px-3 py-1 rounded-pill small">
                        <i class="bi bi-shield-lock-fill text-warning me-1"></i> Sin Apertura de Puertos (Push HTTPS)
                    </span>
                </div>
                <h2 class="fw-bold text-white mb-2">Inventario de Seminuevos</h2>
                <p class="text-secondary mb-0" style="max-width: 720px;">
                    Listado actualizado de vehículos seminuevos con estatus <code>Disponible</code> por almacén, chasis (VIN), color, motor y valor de inventario.
                </p>
            </div>

            <!-- Botonera y Estado -->
            <div class="d-flex flex-column align-items-lg-end gap-2 w-100 w-lg-auto">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge p-2 px-3 rounded-pill <?php echo (!empty($ultimaSync)) ? 'bg-success bg-opacity-25 text-success border border-success' : 'bg-warning bg-opacity-25 text-warning border border-warning'; ?>">
                        <i class="bi <?php echo (!empty($ultimaSync)) ? 'bi-check-circle-fill' : 'bi-clock-fill'; ?> me-1"></i>
                        <?php echo (!empty($ultimaSync)) ? 'Sincronizado' : 'Esperando Conexión'; ?>
                    </span>
                    <a href="agencia.php?descargar_conector_local=1" class="btn btn-success text-dark fw-bold btn-sm rounded-3 px-3">
                        <i class="bi bi-cloud-arrow-down-fill me-1"></i> Descargar Conector API (.php)
                    </a>
                </div>
                <div class="text-secondary small font-monospace">
                    Última Sincronización: <span class="text-info"><?php echo !empty($ultimaSync) ? htmlspecialchars($ultimaSync) : 'Sin transmisión previa'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- TARJETAS DE KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Unidades Disponibles</span>
                    <div class="p-2 rounded-2 bg-success bg-opacity-15 text-success fs-5">
                        <i class="bi bi-car-front"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-white"><?php echo number_format($totalUnidades); ?></div>
                <small class="text-secondary">Autos listos para venta</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Almacenes con Stock</span>
                    <div class="p-2 rounded-2 bg-info bg-opacity-15 text-info fs-5">
                        <i class="bi bi-building-check"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-white"><?php echo number_format($totalAlmacenes); ?></div>
                <small class="text-secondary">Concesionarios y patios activos</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Importe Inventario</span>
                    <div class="p-2 rounded-2 bg-warning bg-opacity-15 text-warning fs-5">
                        <i class="bi bi-box-seam-fill"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-warning font-monospace">$<?php echo number_format($totalImporteInventario, 2); ?></div>
                <small class="text-secondary">Costo acumulado de unidades</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Valor Potencial Venta</span>
                    <div class="p-2 rounded-2 bg-success bg-opacity-15 text-success fs-5">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-success font-monospace">$<?php echo number_format($totalPrecioVenta, 2); ?></div>
                <small class="text-secondary">Precio de venta comercial</small>
            </div>
        </div>
    </div>

    <!-- BARRA DE FILTROS APLICADOS -->
    <div class="filter-box">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="reporte" value="inventario_seminuevos">

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary">Búsqueda Rápida</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Chasis, VIN, inventario, modelo..." value="<?php echo htmlspecialchars($filtroQ); ?>">
                </div>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Almacén / Concesionario</label>
                <select name="almacen" class="form-select">
                    <option value="">Todos los almacenes</option>
                    <?php foreach ($catalogoAlmacenes as $alm): ?>
                        <option value="<?php echo htmlspecialchars($alm['cve_almacen']); ?>" <?php echo ($filtroAlmacen === $alm['cve_almacen']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($alm['cve_almacen'] . ' - ' . $alm['nombre_almacen']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Marca</label>
                <select name="marca" class="form-select">
                    <option value="">Todas las marcas</option>
                    <?php foreach ($catalogoMarcas as $m): ?>
                        <option value="<?php echo htmlspecialchars($m); ?>" <?php echo ($filtroMarca === $m) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <label class="form-label small fw-bold text-secondary">Año</label>
                <select name="anio" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($catalogoAnios as $an): ?>
                        <option value="<?php echo htmlspecialchars($an); ?>" <?php echo ($filtroAnio === $an) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($an); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Color</label>
                <select name="color" class="form-select">
                    <option value="">Todos los colores</option>
                    <?php foreach ($catalogoColores as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($filtroColor === $c) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-success text-dark fw-bold w-100 rounded-3">
                    <i class="bi bi-funnel-fill me-1"></i> Filtrar
                </button>
                <a href="reportes_agencia.php?reporte=inventario_seminuevos" class="btn btn-outline-secondary rounded-3 text-white" title="Limpiar Filtros">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-secondary border-opacity-25 flex-wrap gap-2">
            <div class="text-secondary small">
                Mostrando <strong class="text-white"><?php echo count($unidades); ?></strong> de <strong class="text-white"><?php echo $totalUnidades; ?></strong> unidades disponibles.
            </div>
            <div class="d-flex gap-2">
                <?php if ($puedeExportar): ?>
                    <a href="reportes_agencia.php?<?php echo http_build_query(array_merge($_GET, ['reporte' => 'inventario_seminuevos', 'exportar' => 'excel'])); ?>" class="btn btn-outline-success btn-sm rounded-3">
                        <i class="bi bi-file-earmark-excel-fill text-success me-1"></i> Exportar a Excel (.xls)
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TABLA DE INVENTARIO DISPONIBLE (100% DARK THEME) -->
    <div class="table-custom">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Almacén</th>
                        <th style="width: 110px;">Inventario</th>
                        <th>Descripción de Unidad</th>
                        <th style="width: 170px;">Chasis (VIN)</th>
                        <th style="width: 120px;">Color</th>
                        <th style="width: 130px;">Motor</th>
                        <th style="width: 110px; text-align: center;">Marca / Año</th>
                        <th style="width: 110px; text-align: center;">Estatus</th>
                        <th style="width: 130px; text-align: right;">Precio Venta</th>
                        <th style="width: 130px; text-align: right;">Costo Inv.</th>
                        <th style="width: 110px; text-align: center;">Fecha Alta</th>
                        <th style="width: 70px; text-align: center;">Ficha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unidades)): ?>
                        <tr>
                            <td colspan="12" class="text-center py-5 text-secondary">
                                <i class="bi bi-car-front display-4 d-block mb-2 opacity-50 text-secondary"></i>
                                <h5 class="fw-bold text-white mb-1">No hay unidades en inventario</h5>
                                <p class="small mb-0">No se encontraron vehículos disponibles con los filtros actuales.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($unidades as $u): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-dark border border-secondary text-info font-monospace small">
                                        <?php echo htmlspecialchars($u['cve_almacen']); ?>
                                    </span>
                                    <div class="text-white small fw-semibold text-truncate" style="max-width: 130px;" title="<?php echo htmlspecialchars($u['nombre_almacen']); ?>">
                                        <?php echo htmlspecialchars($u['nombre_almacen'] ?: 'Almacén ' . $u['cve_almacen']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-info font-monospace fw-bold small">
                                        <?php echo htmlspecialchars($u['inventario']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-white"><?php echo htmlspecialchars($u['descripcion']); ?></div>
                                    <?php if (!empty($u['equipamiento'])): ?>
                                        <div class="text-secondary small text-truncate" style="max-width: 280px;" title="<?php echo htmlspecialchars($u['equipamiento']); ?>">
                                            <i class="bi bi-gear-wide me-1"></i><?php echo htmlspecialchars($u['equipamiento']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="font-monospace text-warning fw-bold small">
                                        <?php echo htmlspecialchars($u['chasis']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-light small"><?php echo htmlspecialchars($u['color']); ?></span>
                                </td>
                                <td>
                                    <span class="font-monospace text-secondary small"><?php echo htmlspecialchars($u['motor']); ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge bg-secondary bg-opacity-25 text-light small">
                                        <?php echo htmlspecialchars($u['marca'] . ' ' . $u['anio']); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge-status-disponible">
                                        <i class="bi bi-check-circle-fill"></i> Disponible
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="fw-bold font-monospace text-success">
                                        $<?php echo number_format(floatval($u['precio_venta']), 2); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="font-monospace text-secondary small">
                                        $<?php echo number_format(floatval($u['importe_inventario'] ?: $u['costo_inventario']), 2); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="small text-secondary">
                                        <?php echo date('d/m/Y', strtotime($u['fecha_alta'])); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-sm btn-outline-info rounded-3 p-1 px-2 lh-1" onclick='abrirFichaAuto(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="Ver Ficha Técnica Completa">
                                        <i class="bi bi-eye-fill"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL: FICHA TÉCNICA DEL VEHÍCULO -->
    <div class="modal fade" id="modalFichaAuto" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background: #081528; border: 1px solid rgba(16, 185, 129, 0.4); color: #fff;">
                <div class="modal-header border-secondary border-opacity-25">
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="fa_descripcion">Ficha de Unidad</h5>
                        <small class="text-secondary font-monospace" id="fa_chasis">VIN: ---</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08);">
                                <div class="text-secondary small fw-bold">PRECIO DE VENTA</div>
                                <div class="fw-bold text-success font-monospace fs-5" id="fa_precio_venta">$0.00</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08);">
                                <div class="text-secondary small fw-bold">COSTO INVENTARIO</div>
                                <div class="fw-bold text-warning font-monospace fs-5" id="fa_costo">$0.00</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08);">
                                <div class="text-secondary small fw-bold">MARGEN BRUTO EST.</div>
                                <div class="fw-bold text-info font-monospace fs-5" id="fa_margen">$0.00</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <div class="small text-secondary"><strong>Almacén:</strong> <span class="text-light" id="fa_almacen">---</span></div>
                            <div class="small text-secondary mt-2"><strong>Clave Inventario:</strong> <span class="text-info font-monospace fw-bold" id="fa_inventario">---</span></div>
                            <div class="small text-secondary mt-2"><strong>Marca / Año:</strong> <span class="text-light" id="fa_marca_anio">---</span></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="small text-secondary"><strong>Color Exterior:</strong> <span class="text-light" id="fa_color">---</span></div>
                            <div class="small text-secondary mt-2"><strong>Número de Motor:</strong> <span class="text-light font-monospace" id="fa_motor">---</span></div>
                            <div class="small text-secondary mt-2"><strong>Fecha de Alta:</strong> <span class="text-light" id="fa_fecha">---</span></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Equipamiento Opcional y Paquete</label>
                        <div class="p-3 rounded-3 text-light" style="background: #050d1a; border: 1px solid rgba(255,255,255,0.08);" id="fa_equipamiento">
                            ---
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function abrirFichaAuto(item) {
    document.getElementById('fa_descripcion').textContent = item.descripcion || 'Ficha de Unidad';
    document.getElementById('fa_chasis').textContent = 'Chasis (VIN): ' + (item.chasis || '---');
    
    const prcVta = Number(item.precio_venta || 0);
    const cstInv = Number(item.costo_inventario || item.importe_inventario || 0);
    const margen = prcVta - cstInv;

    document.getElementById('fa_precio_venta').textContent = '$' + prcVta.toLocaleString('es-MX', { minimumFractionDigits: 2 });
    document.getElementById('fa_costo').textContent = '$' + cstInv.toLocaleString('es-MX', { minimumFractionDigits: 2 });
    document.getElementById('fa_margen').textContent = '$' + margen.toLocaleString('es-MX', { minimumFractionDigits: 2 });

    document.getElementById('fa_almacen').textContent = (item.cve_almacen || '') + ' - ' + (item.nombre_almacen || '');
    document.getElementById('fa_inventario').textContent = item.inventario || '---';
    document.getElementById('fa_marca_anio').textContent = (item.marca || '') + ' ' + (item.anio || '');
    document.getElementById('fa_color').textContent = item.color || '---';
    document.getElementById('fa_motor').textContent = item.motor || '---';
    document.getElementById('fa_fecha').textContent = item.fecha_alta || '---';
    document.getElementById('fa_equipamiento').textContent = item.equipamiento || 'Sin equipamiento adicional especificado.';

    const modal = new bootstrap.Modal(document.getElementById('modalFichaAuto'));
    modal.show();
}
</script>
</body>
</html>
