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

// 1. ACCIÓN: SEMBRAR DATOS DE DEMOSTRACIÓN (Para probar el módulo de inmediato)
if (isset($_POST['accion']) && $_POST['accion'] === 'sembrar_demo' && $puedeCrear && $pdo) {
    $demoItems = [
        [
            'tipo_reporte' => 'ventas',
            'folio_referencia' => 'VTA-2026-0891',
            'titulo' => 'Venta Unidad Nueva - Versa Exclusive CVT 2026',
            'resumen' => 'Cliente: Fernando Morales Ruiz | Asesor: Carlos Vega | Factura: FA-10492',
            'monto' => 418900.00,
            'fecha_documento' => date('Y-m-d', strtotime('-1 days')),
            'estatus' => 'Concluido',
            'datos_json' => [
                'cliente' => 'Fernando Morales Ruiz',
                'vin' => '3N1CN8EV9RL281920',
                'modelo' => 'Versa Exclusive CVT 2026',
                'color' => 'Gris Oxford',
                'forma_pago' => 'Financiamiento CrediNissan',
                'enganche' => 85000.00,
                'plazo_meses' => 48,
                'vendedor' => 'Carlos Vega'
            ]
        ],
        [
            'tipo_reporte' => 'servicios',
            'folio_referencia' => 'OS-10944',
            'titulo' => 'Mantenimiento Preventivo 30,000 KM - Sentra SR',
            'resumen' => 'Cliente: Sofía Mendoza | Asesor Servicio: Ing. Luis Rivas | Paquete Mayor + Alineación',
            'monto' => 4850.00,
            'fecha_documento' => date('Y-m-d'),
            'estatus' => 'En Proceso',
            'datos_json' => [
                'cliente' => 'Sofía Mendoza',
                'placas' => 'PXR-491-B',
                'vehiculo' => 'Sentra SR 2024',
                'kilometraje' => 30420,
                'operaciones' => ['Cambio aceite sintético 0W20', 'Filtro aceite y aire', 'Rotación y alineación', 'Lavado de inyectores'],
                'mecanico_asignado' => 'Téc. Roberto Gómez'
            ]
        ],
        [
            'tipo_reporte' => 'servicios',
            'folio_referencia' => 'OS-10945',
            'titulo' => 'Diagnóstico Eléctrico y Batería - Kicks Advance',
            'resumen' => 'Cliente: Alejandro Domínguez | Reemplazo de acumulador 12V e inspección de alternador',
            'monto' => 3200.00,
            'fecha_documento' => date('Y-m-d'),
            'estatus' => 'Concluido',
            'datos_json' => [
                'cliente' => 'Alejandro Domínguez',
                'placas' => 'UAB-882-C',
                'vehiculo' => 'Kicks Advance 2023',
                'diagnostico' => 'Acumulador con celda en corto, sistema de carga operando a 14.2V',
                'garantia' => 'Batería Original 24 meses'
            ]
        ],
        [
            'tipo_reporte' => 'refacciones',
            'folio_referencia' => 'FAC-REF-4421',
            'titulo' => 'Venta Mostrador Refacciones - Juego Balatas y Discos',
            'resumen' => 'Taller Externo: Frenos y Clutch La Villa | 2 Juegos delanteros + 2 Discos ventilados',
            'monto' => 6400.00,
            'fecha_documento' => date('Y-m-d', strtotime('-2 days')),
            'estatus' => 'Concluido',
            'datos_json' => [
                'comprador' => 'Frenos y Clutch La Villa S.A.',
                'partes' => [
                    ['numero_parte' => 'D1060-4BA0A', 'descripcion' => 'Juego Balatas Delanteras', 'cantidad' => 2, 'subtotal' => 3100.00],
                    ['numero_parte' => '40206-4BA0A', 'descripcion' => 'Rotores / Discos Freno', 'cantidad' => 2, 'subtotal' => 3300.00]
                ]
            ]
        ],
        [
            'tipo_reporte' => 'inventario',
            'folio_referencia' => 'INV-LOTE-03',
            'titulo' => 'Cierre de Inventario de Autos Nuevos en Patio',
            'resumen' => 'Conteo físico de patio: 42 unidades disponibles, 8 asignadas a entrega, 5 en tránsito',
            'monto' => 55,
            'fecha_documento' => date('Y-m-d', strtotime('-3 days')),
            'estatus' => 'Activo',
            'datos_json' => [
                'total_disponibles' => 42,
                'en_demostracion' => 4,
                'entregas_programadas' => 8,
                'en_transito_madrina' => 5,
                'auditor_patio' => 'Lic. Patricia Juárez'
            ]
        ]
    ];

    $stmtIns = $pdo->prepare("
        INSERT INTO reportes_agencia_datos 
        (tipo_reporte, folio_referencia, titulo, datos_json, resumen, monto, fecha_documento, estatus, sincronizado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    foreach ($demoItems as $it) {
        $stmtIns->execute([
            $it['tipo_reporte'],
            $it['folio_referencia'],
            $it['titulo'],
            json_encode($it['datos_json'], JSON_UNESCAPED_UNICODE),
            $it['resumen'],
            $it['monto'],
            $it['fecha_documento'],
            $it['estatus']
        ]);
    }

    // Actualizar estado de agencia
    try {
        $stmtUp = $pdo->prepare("UPDATE agencias SET local_ultima_sincronizacion = CURRENT_TIMESTAMP, local_ultimo_estado_sync = 'Demo sembrado' WHERE id = ?");
        $stmtUp->execute([$agenciaData['id'] ?? 1]);
    } catch (Throwable $t) {}

    header("Location: reportes_agencia.php?msg=demo_ok");
    exit();
}

// 2. PARÁMETROS DE FILTRADO Y BÚSQUEDA
$filtroTipo   = trim($_GET['tipo'] ?? '');
$filtroEstatus= trim($_GET['estatus'] ?? '');
$filtroBusq   = trim($_GET['q'] ?? '');
$filtroFechaI = trim($_GET['fecha_desde'] ?? '');
$filtroFechaF = trim($_GET['fecha_hasta'] ?? '');

$where = ["1=1"];
$params = [];

if (!empty($filtroTipo)) {
    $where[] = "tipo_reporte = ?";
    $params[] = $filtroTipo;
}
if (!empty($filtroEstatus)) {
    $where[] = "estatus = ?";
    $params[] = $filtroEstatus;
}
if (!empty($filtroBusq)) {
    $where[] = "(folio_referencia LIKE ? OR titulo LIKE ? OR resumen LIKE ? OR datos_json LIKE ?)";
    $busqParam = "%$filtroBusq%";
    $params[] = $busqParam;
    $params[] = $busqParam;
    $params[] = $busqParam;
    $params[] = $busqParam;
}
if (!empty($filtroFechaI)) {
    $where[] = "fecha_documento >= ?";
    $params[] = $filtroFechaI;
}
if (!empty($filtroFechaF)) {
    $where[] = "fecha_documento <= ?";
    $params[] = $filtroFechaF;
}

$whereSql = implode(" AND ", $where);

// EXPORTACIÓN A EXCEL / CSV
if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel' && $puedeExportar && $pdo) {
    $sqlExp = "SELECT * FROM reportes_agencia_datos WHERE $whereSql ORDER BY fecha_documento DESC, id DESC";
    $stmtExp = $pdo->prepare($sqlExp);
    $stmtExp->execute($params);
    $registrosExp = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Reportes_Agencia_' . date('Ymd_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Reportes</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
    echo '<table border="1" style="font-family: Arial, sans-serif; border-collapse: collapse;">';
    echo '<tr style="background: #0f223d; color: #ffffff; font-weight: bold; text-align: center;">';
    echo '<th>ID</th><th>Folio</th><th>Tipo</th><th>Título / Concepto</th><th>Resumen</th><th>Monto ($)</th><th>Fecha Doc</th><th>Estatus</th><th>Sincronizado En</th>';
    echo '</tr>';

    foreach ($registrosExp as $r) {
        echo '<tr>';
        echo '<td align="center">' . $r['id'] . '</td>';
        echo '<td align="center"><b>' . htmlspecialchars($r['folio_referencia'] ?? '') . '</b></td>';
        echo '<td align="center">' . strtoupper(htmlspecialchars($r['tipo_reporte'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($r['titulo'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['resumen'] ?? '') . '</td>';
        echo '<td align="right">' . number_format(floatval($r['monto'] ?? 0), 2) . '</td>';
        echo '<td align="center">' . htmlspecialchars($r['fecha_documento'] ?? '') . '</td>';
        echo '<td align="center">' . htmlspecialchars($r['estatus'] ?? '') . '</td>';
        echo '<td align="center">' . htmlspecialchars($r['sincronizado_en'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit();
}

// 3. CONSULTA DE REPORTES Y KPIs
$reportes = [];
$totalReportes = 0;
$totalMonto = 0.0;
$conteoTipos = [];

if ($pdo) {
    try {
        // Conteo y Sumatoria General
        $stmtKpi = $pdo->query("
            SELECT 
                COUNT(*) as total_filas,
                COALESCE(SUM(monto), 0) as suma_monto
            FROM reportes_agencia_datos
        ");
        $kpiRow = $stmtKpi ? $stmtKpi->fetch(PDO::FETCH_ASSOC) : ['total_filas' => 0, 'suma_monto' => 0];
        $totalReportes = intval($kpiRow['total_filas'] ?? 0);
        $totalMonto = floatval($kpiRow['suma_monto'] ?? 0);

        // Conteo por Tipo
        $stmtTipos = $pdo->query("SELECT tipo_reporte, COUNT(*) as cant FROM reportes_agencia_datos GROUP BY tipo_reporte");
        while ($tRow = $stmtTipos->fetch(PDO::FETCH_ASSOC)) {
            $conteoTipos[$tRow['tipo_reporte']] = intval($tRow['cant']);
        }

        // Consulta Filtrada
        $sqlList = "SELECT * FROM reportes_agencia_datos WHERE $whereSql ORDER BY fecha_documento DESC, id DESC LIMIT 200";
        $stmtList = $pdo->prepare($sqlList);
        $stmtList->execute($params);
        $reportes = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {}
}

$ultimaSync = $agenciaData['local_ultima_sincronizacion'] ?? null;
$estadoSync = $agenciaData['local_ultimo_estado_sync'] ?? 'Sin sincronizar';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Agencia - Portal de Sistemas</title>
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
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5), 0 0 20px rgba(16, 185, 129, 0.08);
            position: relative;
            overflow: hidden;
        }
        .hero-panel::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.18) 0%, transparent 70%);
            pointer-events: none;
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
            padding: 20px;
            margin-bottom: 25px;
        }
        .table-custom {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            overflow: hidden;
        }
        .table-custom table {
            margin-bottom: 0;
            color: #ffffff;
        }
        .table-custom th {
            background: #050d1a;
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .table-custom td {
            padding: 14px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .table-custom tr:hover td {
            background: var(--bg-card-hover);
        }
        .badge-tipo {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .badge-ventas { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-servicios { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-refacciones { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-inventario { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        .badge-general { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }
        
        .form-control, .form-select {
            background: #061120;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 8px;
        }
        .form-control:focus, .form-select:focus {
            background: #08172c;
            border-color: var(--accent-green);
            color: #ffffff;
            box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.2);
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Menú Principal
        </a>
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($logoAgencia)): ?>
                <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="Logo" style="max-height: 28px; max-width: 100px; object-fit: contain;">
            <?php endif; ?>
            <span class="fw-bold fs-5">PORTAL DE SISTEMAS <span class="text-success">| Reportes Agencia</span></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if ($esAdmin): ?>
            <a href="agencia.php#servidor_local" class="btn btn-outline-success btn-sm rounded-3 px-3 text-white">
                <i class="bi bi-sliders me-1 text-success"></i> Configurar Servidor Local
            </a>
        <?php endif; ?>
        <span class="badge bg-dark border border-secondary text-secondary p-2 small font-monospace">
            <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($agenciaNombre); ?>
        </span>
    </div>
</div>

<div class="container-fluid px-4" style="max-width: 1400px;">

    <!-- Mensajes de Notificación -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'demo_ok'): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> ¡Datos de demostración sembrados con éxito! Ahora puedes probar los filtros, búsqueda y visualizador de reportes.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- HERO PANEL -->
    <div class="hero-panel">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-4">
            <div>
                <span class="badge bg-success bg-opacity-25 text-success border border-success px-3 py-1 rounded-pill mb-2">
                    <i class="bi bi-database-check me-1"></i> Base de Datos del Servidor Local / DMS
                </span>
                <h2 class="fw-bold text-white mb-2">Reportes Operativos de la Agencia</h2>
                <p class="text-secondary mb-0" style="max-width: 650px;">
                    Consulta, análisis y monitoreo en tiempo real de órdenes de servicio, ventas, refacciones e inventario transmitidos desde el servidor local físico hacia este cPanel.
                </p>
            </div>

            <!-- Estado del Conector y Acciones Rápidas -->
            <div class="d-flex flex-column align-items-lg-end gap-2 w-100 w-lg-auto">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge p-2 px-3 rounded-pill <?php echo (!empty($ultimaSync)) ? 'bg-success bg-opacity-25 text-success border border-success' : 'bg-warning bg-opacity-25 text-warning border border-warning'; ?>">
                        <i class="bi <?php echo (!empty($ultimaSync)) ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?> me-1"></i>
                        <?php echo (!empty($ultimaSync)) ? 'Conector Activo' : 'Esperando Sincronización'; ?>
                    </span>
                    <button type="button" class="btn btn-outline-light btn-sm rounded-3" onclick="ejecutarPruebaConexionRapida()">
                        <i class="bi bi-activity text-success me-1"></i> Diagnóstico
                    </button>
                    <a href="agencia.php?descargar_conector_local=1" class="btn btn-success text-dark fw-bold btn-sm rounded-3 px-3">
                        <i class="bi bi-cloud-arrow-down-fill me-1"></i> Descargar API (.php)
                    </a>
                </div>
                <div class="text-secondary small font-monospace">
                    Host: <span class="text-light"><?php echo htmlspecialchars(($agenciaData['local_db_host'] ?? '127.0.0.1') . ':' . ($agenciaData['local_db_port'] ?? 3306)); ?></span>
                    &bull; Última Sync: <span class="text-info"><?php echo !empty($ultimaSync) ? htmlspecialchars($ultimaSync) : 'Sin registros'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- TARJETAS DE KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Total Reportes</span>
                    <div class="p-2 rounded-2 bg-success bg-opacity-15 text-success fs-5">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-white"><?php echo number_format($totalReportes); ?></div>
                <small class="text-secondary">Registros en la base de datos</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Órdenes / Servicios</span>
                    <div class="p-2 rounded-2 bg-info bg-opacity-15 text-info fs-5">
                        <i class="bi bi-tools"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-white"><?php echo number_format($conteoTipos['servicios'] ?? 0); ?></div>
                <small class="text-secondary">En taller y mantenimiento</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Ventas Registradas</span>
                    <div class="p-2 rounded-2 bg-primary bg-opacity-15 text-primary fs-5">
                        <i class="bi bi-car-front-fill"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-white"><?php echo number_format($conteoTipos['ventas'] ?? 0); ?></div>
                <small class="text-secondary">Nuevos, seminuevos y refacciones</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase">Monto Acumulado</span>
                    <div class="p-2 rounded-2 bg-warning bg-opacity-15 text-warning fs-5">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
                <div class="fs-3 fw-bold text-success">$<?php echo number_format($totalMonto, 2); ?></div>
                <small class="text-secondary">Volumen total de transacciones</small>
            </div>
        </div>
    </div>

    <!-- BARRA DE FILTROS Y BÚSQUEDA -->
    <div class="filter-box">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary">Búsqueda rápida</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Folio, cliente, concepto..." value="<?php echo htmlspecialchars($filtroBusq); ?>">
                </div>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Tipo de Reporte</label>
                <select name="tipo" class="form-select">
                    <option value="">Todos los tipos</option>
                    <option value="ventas" <?php echo ($filtroTipo === 'ventas') ? 'selected' : ''; ?>>Ventas de Unidades</option>
                    <option value="servicios" <?php echo ($filtroTipo === 'servicios') ? 'selected' : ''; ?>>Órdenes de Servicio</option>
                    <option value="refacciones" <?php echo ($filtroTipo === 'refacciones') ? 'selected' : ''; ?>>Refacciones</option>
                    <option value="inventario" <?php echo ($filtroTipo === 'inventario') ? 'selected' : ''; ?>>Inventario</option>
                    <option value="general" <?php echo ($filtroTipo === 'general') ? 'selected' : ''; ?>>General / Operativo</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Estatus</label>
                <select name="estatus" class="form-select">
                    <option value="">Todos los estatus</option>
                    <option value="Activo" <?php echo ($filtroEstatus === 'Activo') ? 'selected' : ''; ?>>Activo</option>
                    <option value="Concluido" <?php echo ($filtroEstatus === 'Concluido') ? 'selected' : ''; ?>>Concluido</option>
                    <option value="En Proceso" <?php echo ($filtroEstatus === 'En Proceso') ? 'selected' : ''; ?>>En Proceso</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Fecha Desde</label>
                <input type="date" name="fecha_desde" class="form-control" value="<?php echo htmlspecialchars($filtroFechaI); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Fecha Hasta</label>
                <input type="date" name="fecha_hasta" class="form-control" value="<?php echo htmlspecialchars($filtroFechaF); ?>">
            </div>

            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-success w-100 rounded-3 text-dark fw-bold" title="Filtrar">
                    <i class="bi bi-funnel-fill"></i>
                </button>
                <a href="reportes_agencia.php" class="btn btn-outline-secondary rounded-3 text-white" title="Limpiar">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-secondary border-opacity-25 flex-wrap gap-2">
            <div class="text-secondary small">
                Mostrando <strong><?php echo count($reportes); ?></strong> de <strong><?php echo $totalReportes; ?></strong> reporte(s) encontrados.
            </div>
            <div class="d-flex gap-2">
                <?php if ($totalReportes === 0 && $puedeCrear): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="accion" value="sembrar_demo">
                        <button type="submit" class="btn btn-outline-info btn-sm rounded-3">
                            <i class="bi bi-magic me-1"></i> Sembrar Datos de Prueba
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($puedeExportar): ?>
                    <a href="reportes_agencia.php?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'excel'])); ?>" class="btn btn-outline-success btn-sm rounded-3">
                        <i class="bi bi-file-earmark-excel-fill text-success me-1"></i> Exportar a Excel
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TABLA DE REPORTES -->
    <div class="table-custom">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Folio</th>
                        <th style="width: 130px;">Tipo</th>
                        <th>Título / Concepto</th>
                        <th>Resumen Operativo</th>
                        <th style="width: 130px; text-align: right;">Monto</th>
                        <th style="width: 120px; text-align: center;">Fecha</th>
                        <th style="width: 120px; text-align: center;">Estatus</th>
                        <th style="width: 90px; text-align: center;">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportes)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <h5 class="fw-bold text-white mb-1">No hay reportes disponibles</h5>
                                <p class="small mb-3">No se encontraron registros con los filtros seleccionados o el servidor local aún no ha transmitido datos.</p>
                                <?php if ($puedeCrear): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="accion" value="sembrar_demo">
                                        <button type="submit" class="btn btn-sm btn-success text-dark fw-bold rounded-3 px-3">
                                            <i class="bi bi-magic me-1"></i> Cargar Reportes de Demostración
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reportes as $r): ?>
                            <?php
                                $tipoClase = 'badge-general';
                                if ($r['tipo_reporte'] === 'ventas') $tipoClase = 'badge-ventas';
                                elseif ($r['tipo_reporte'] === 'servicios') $tipoClase = 'badge-servicios';
                                elseif ($r['tipo_reporte'] === 'refacciones') $tipoClase = 'badge-refacciones';
                                elseif ($r['tipo_reporte'] === 'inventario') $tipoClase = 'badge-inventario';

                                $badgeEstatus = 'bg-secondary';
                                if ($r['estatus'] === 'Concluido') $badgeEstatus = 'bg-success';
                                elseif ($r['estatus'] === 'En Proceso') $badgeEstatus = 'bg-info text-dark';
                                elseif ($r['estatus'] === 'Activo') $badgeEstatus = 'bg-primary';
                            ?>
                            <tr>
                                <td>
                                    <span class="font-monospace fw-bold text-info small">
                                        <?php echo htmlspecialchars($r['folio_referencia'] ?: ('REP-' . $r['id'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-tipo <?php echo $tipoClase; ?>">
                                        <?php echo htmlspecialchars($r['tipo_reporte']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-white"><?php echo htmlspecialchars($r['titulo']); ?></div>
                                    <div class="text-secondary" style="font-size: 0.75rem;">Sync: <?php echo htmlspecialchars($r['sincronizado_en']); ?></div>
                                </td>
                                <td>
                                    <div class="text-light small text-truncate" style="max-width: 320px;" title="<?php echo htmlspecialchars($r['resumen']); ?>">
                                        <?php echo htmlspecialchars($r['resumen'] ?: '---'); ?>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($r['monto'] > 0): ?>
                                        <span class="fw-bold font-monospace text-success">$<?php echo number_format($r['monto'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary">---</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="small text-secondary"><?php echo htmlspecialchars($r['fecha_documento']); ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge rounded-pill <?php echo $badgeEstatus; ?> small">
                                        <?php echo htmlspecialchars($r['estatus']); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-sm btn-outline-info rounded-3 p-1 px-2 lh-1" onclick='abrirModalDetalle(<?php echo json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="Ver Detalle Completo">
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

</div>

<!-- ========================================================================= -->
<!-- MODAL: DETALLE DE REPORTE OPERATIVO                                       -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalDetalleReporte" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: #081528; border: 1px solid rgba(255, 255, 255, 0.15); color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div>
                    <h5 class="modal-title fw-bold text-white mb-0" id="det_titulo">Detalle del Reporte</h5>
                    <small class="text-secondary font-monospace" id="det_folio">Folio: ---</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <div class="p-2 rounded-2" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="text-secondary small fw-bold">TIPO DE REPORTE</div>
                            <div class="fw-bold text-info" id="det_tipo">---</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-2 rounded-2" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="text-secondary small fw-bold">MONTO REGISTRADO</div>
                            <div class="fw-bold text-success font-monospace fs-6" id="det_monto">$0.00</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-2 rounded-2" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="text-secondary small fw-bold">FECHA / ESTATUS</div>
                            <div class="fw-bold text-light" id="det_fecha_estatus">---</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Resumen Operativo</label>
                    <div class="p-3 rounded-2 text-light" style="background: #050d1a; border: 1px solid rgba(255,255,255,0.08);" id="det_resumen">
                        ---
                    </div>
                </div>

                <div>
                    <label class="form-label small fw-bold text-secondary">Datos Estructurados del Servidor Local (Payload JSON)</label>
                    <div class="p-3 rounded-2 overflow-auto" style="background: #040914; border: 1px solid rgba(255,255,255,0.08); max-height: 250px;">
                        <pre class="mb-0 text-info font-monospace small" id="det_json">{}</pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: DIAGNÓSTICO DE CONEXIÓN RÁPIDO                                      -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalDiagnosticoRapido" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #081528; border: 1px solid rgba(16, 185, 129, 0.4); color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                    <i class="bi bi-activity text-success"></i> Diagnóstico de Conexión
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="contenedorDiagRapido">
                <div class="text-center py-3">
                    <div class="spinner-border text-success mb-2" role="status"></div>
                    <p class="text-secondary small mb-0">Comprobando enlace con servidor local...</p>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
                <a href="agencia.php#servidor_local" class="btn btn-success text-dark fw-bold rounded-3">
                    Configurar en Módulo Agencia
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function abrirModalDetalle(item) {
    document.getElementById('det_titulo').textContent = item.titulo || 'Detalle del Reporte';
    document.getElementById('det_folio').textContent = 'Folio: ' + (item.folio_referencia || ('REP-' + item.id));
    document.getElementById('det_tipo').textContent = (item.tipo_reporte || 'GENERAL').toUpperCase();
    document.getElementById('det_monto').textContent = item.monto > 0 ? ('$' + Number(item.monto).toLocaleString('es-MX', { minimumFractionDigits: 2 })) : '---';
    document.getElementById('det_fecha_estatus').textContent = (item.fecha_documento || '---') + ' (' + (item.estatus || 'Activo') + ')';
    document.getElementById('det_resumen').textContent = item.resumen || 'Sin resumen registrado.';

    let jsonParsed = {};
    try {
        jsonParsed = (typeof item.datos_json === 'string') ? JSON.parse(item.datos_json) : (item.datos_json || {});
    } catch(e) {
        jsonParsed = { raw: item.datos_json };
    }
    document.getElementById('det_json').textContent = JSON.stringify(jsonParsed, null, 2);

    const modal = new bootstrap.Modal(document.getElementById('modalDetalleReporte'));
    modal.show();
}

function ejecutarPruebaConexionRapida() {
    const modal = new bootstrap.Modal(document.getElementById('modalDiagnosticoRapido'));
    const cont = document.getElementById('contenedorDiagRapido');
    cont.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-success mb-2" role="status"></div>
            <p class="text-secondary small mb-0">Probando sockets TCP y receptor de cPanel...</p>
        </div>
    `;
    modal.show();

    fetch('api_test_conexion_local.php', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        const d = data.detalles || {};
        cont.innerHTML = `
            <div class="alert ${d.pdo_conectado ? 'alert-success' : (d.es_ip_privada ? 'alert-info' : 'alert-warning')} border-0 p-3 rounded-3 mb-3">
                <h6 class="fw-bold mb-1">${escapeHtml(data.mensaje)}</h6>
                <small class="d-block text-secondary">Host: <code>${escapeHtml(d.host)}:${escapeHtml(d.puerto)}</code></small>
            </div>
            <div class="small text-secondary">
                <div>&bull; Socket TCP: <strong class="${d.socket_abierto ? 'text-success' : 'text-danger'}">${d.socket_abierto ? 'Abierto (' + d.socket_tiempo_ms + ' ms)' : 'Cerrado'}</strong></div>
                <div>&bull; Receptor cPanel: <strong class="text-success">Activo</strong></div>
                <div>&bull; Total sincronizados en cPanel: <strong class="text-white">${d.total_registros_en_cpanel}</strong></div>
            </div>
        `;
    })
    .catch(err => {
        cont.innerHTML = `<div class="alert alert-danger border-0">Error: ${escapeHtml(err.message)}</div>`;
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' })[m]);
}
</script>
</body>
</html>
