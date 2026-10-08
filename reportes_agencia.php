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

// =========================================================================
// LÓGICA ESPECÍFICA PARA EL REPORTE: ÓRDENES DE SERVICIO
// =========================================================================
if ($reporteActivo === 'ordenes_servicio') {

    $filtroQ          = trim($_GET['q'] ?? '');
    $filtroAlmacen    = trim($_GET['almacen'] ?? '');
    $filtroEstatus    = trim($_GET['estatus'] ?? '');
    $filtroTipo       = trim($_GET['tipo'] ?? '');
    $filtroAsesor     = trim($_GET['asesor'] ?? '');
    $filtroAntiguedad = trim($_GET['antiguedad'] ?? '');
    $filtroFechaI     = trim($_GET['fecha_desde'] ?? '');
    $filtroFechaF     = trim($_GET['fecha_hasta'] ?? '');

    $where = ["1=1"];
    $params = [];

    if (!empty($filtroQ)) {
        $where[] = "(no_orden LIKE ? OR cliente LIKE ? OR vin_chasis LIKE ? OR placas LIKE ? OR modelo LIKE ? OR nombre_asesor LIKE ? OR rfc LIKE ?)";
        $qParam = "%$filtroQ%";
        for ($i = 0; $i < 7; $i++) $params[] = $qParam;
    }
    if (!empty($filtroAlmacen)) {
        $where[] = "almacen = ?";
        $params[] = $filtroAlmacen;
    }
    if (!empty($filtroEstatus)) {
        if ($filtroEstatus === 'AB' || $filtroEstatus === 'ABIERTA') {
            $where[] = "(cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%')";
        } elseif ($filtroEstatus === 'CE' || $filtroEstatus === 'CERRADA') {
            $where[] = "(cve_estatus = 'CE' OR descripcion_estatus LIKE '%CERRADA%')";
        } elseif ($filtroEstatus === 'CA' || $filtroEstatus === 'CANCELADA') {
            $where[] = "(cve_estatus IN ('CA','CN') OR descripcion_estatus LIKE '%CANCELADA%')";
        } else {
            $where[] = "(cve_estatus = ? OR descripcion_estatus = ?)";
            $params[] = $filtroEstatus;
            $params[] = $filtroEstatus;
        }
    }
    if (!empty($filtroTipo)) {
        $where[] = "(cve_tipo_orden = ? OR tipo_orden = ?)";
        $params[] = $filtroTipo;
        $params[] = $filtroTipo;
    }
    if (!empty($filtroAsesor)) {
        $where[] = "(cve_asesor = ? OR nombre_asesor = ?)";
        $params[] = $filtroAsesor;
        $params[] = $filtroAsesor;
    }
    if (!empty($filtroAntiguedad)) {
        if ($filtroAntiguedad === 'critica') {
            $where[] = "(cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%') AND dias_abierta > 15";
        } elseif ($filtroAntiguedad === 'retrasada') {
            $where[] = "(cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%') AND dias_abierta BETWEEN 8 AND 15";
        } elseif ($filtroAntiguedad === 'atencion') {
            $where[] = "(cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%') AND dias_abierta BETWEEN 4 AND 7";
        } elseif ($filtroAntiguedad === 'normal') {
            $where[] = "(cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%') AND dias_abierta <= 3";
        } elseif ($filtroAntiguedad === 'vencida') {
            $where[] = "(cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%') AND fecha_promesa IS NOT NULL AND fecha_promesa > '2000-01-01' AND fecha_promesa < CURRENT_TIMESTAMP";
        }
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
        $sqlExp = "SELECT * FROM reportes_ordenes_servicio WHERE $whereSql ORDER BY (CASE WHEN cve_estatus = 'AB' THEN 1 ELSE 2 END) ASC, dias_abierta DESC, fecha_alta DESC";
        $stmtExp = $pdo->prepare($sqlExp);
        $stmtExp->execute($params);
        $rowsExp = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="Ordenes_Servicio_' . date('Ymd_His') . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Órdenes de Servicio</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
        echo '<table border="1" style="font-family: Arial, sans-serif; border-collapse: collapse;">';
        echo '<tr style="background: #0b1a30; color: #ffffff; font-weight: bold; text-align: center;">';
        echo '<th>No. Orden</th><th>Almacen</th><th>Estatus</th><th>Dias Abierta</th><th>Clasificacion Tiempo</th><th>Tipo Orden</th><th>Tipo Pago</th><th>No. Factura</th><th>Fecha Alta</th><th>Hora Inicio</th><th>Fecha Promesa</th><th>Fecha Entrega</th><th>Hora Fin</th><th>Num Cliente</th><th>Cliente</th><th>RFC</th><th>Telefono</th><th>VIN / Chasis</th><th>Placas</th><th>Modelo</th><th>Año</th><th>Color</th><th>Kilometraje</th><th>Asesor</th><th>Usuario Registro</th>';
        echo '</tr>';

        foreach ($rowsExp as $r) {
            echo '<tr>';
            echo '<td align="center"><b>' . htmlspecialchars($r['no_orden'] ?? '') . '</b></td>';
            echo '<td align="center">' . htmlspecialchars($r['almacen'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['descripcion_estatus'] ?? $r['cve_estatus'] ?? '') . '</td>';
            echo '<td align="center">' . intval($r['dias_abierta'] ?? 0) . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['estatus_tiempo'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['tipo_orden'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['tipo_pago'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['no_factura'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['fecha_alta'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['hora_inicio'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['fecha_promesa'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['fecha_entrega'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['hora_fin'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['num_cliente'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['cliente'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['rfc'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['telefono'] ?? '') . '</td>';
            echo '<td align="center"><code>' . htmlspecialchars($r['vin_chasis'] ?? '') . '</code></td>';
            echo '<td align="center">' . htmlspecialchars($r['placas'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['modelo'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['ano'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['color'] ?? '') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['kilometraje'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['nombre_asesor'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['usuario_registro'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit();
    }

    // Consultas para KPIs y Gráficas
    $ordenesServicio = [];
    $totalOrdenes = 0;
    $totalAbiertas = 0;
    $totalVencidas = 0;
    $totalCriticas = 0;
    $promedioDiasAbiertas = 0.0;
    $chartEstatus = [];
    $chartTipos = [];
    $chartAging = [];
    $catalogoAlmacenesOrd = [];
    $catalogoEstatus = [];
    $catalogoTipos = [];
    $catalogoAsesores = [];

    if ($pdo) {
        try {
            // KPIs dinámicos calculados estrictamente sobre los filtros activos
            $sqlKpi = "
                SELECT 
                    COUNT(*) as total_ordenes,
                    SUM(CASE WHEN cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%' THEN 1 ELSE 0 END) as abiertas,
                    SUM(CASE WHEN (cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%') AND fecha_promesa IS NOT NULL AND fecha_promesa > '2000-01-01' AND fecha_promesa < CURRENT_TIMESTAMP THEN 1 ELSE 0 END) as vencidas,
                    SUM(CASE WHEN (cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%') AND dias_abierta > 15 THEN 1 ELSE 0 END) as criticas,
                    AVG(CASE WHEN cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%' THEN dias_abierta ELSE NULL END) as avg_dias_abiertas
                FROM reportes_ordenes_servicio
                WHERE $whereSql
            ";
            $stmtKpiOrd = $pdo->prepare($sqlKpi);
            $stmtKpiOrd->execute($params);
            $kpisOrd = $stmtKpiOrd ? $stmtKpiOrd->fetch(PDO::FETCH_ASSOC) : [];
            $totalOrdenes = intval($kpisOrd['total_ordenes'] ?? 0);
            $totalAbiertas = intval($kpisOrd['abiertas'] ?? 0);
            $totalVencidas = intval($kpisOrd['vencidas'] ?? 0);
            $totalCriticas = intval($kpisOrd['criticas'] ?? 0);
            $promedioDiasAbiertas = round(floatval($kpisOrd['avg_dias_abiertas'] ?? 0), 1);

            // Chart 1: Distribución por Estatus dinámico según filtros
            $sqlStat = "
                SELECT 
                    CASE 
                        WHEN cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%' THEN 'Abierta'
                        WHEN cve_estatus = 'CE' OR descripcion_estatus LIKE '%CERRADA%' THEN 'Cerrada / Facturada'
                        WHEN cve_estatus IN ('CA','CN') OR descripcion_estatus LIKE '%CANCELADA%' THEN 'Cancelada'
                        ELSE 'Otras'
                    END as grupo_estatus,
                    COUNT(*) as total
                FROM reportes_ordenes_servicio
                WHERE $whereSql
                GROUP BY grupo_estatus
                ORDER BY total DESC
            ";
            $stmtStatChart = $pdo->prepare($sqlStat);
            $stmtStatChart->execute($params);
            $chartEstatus = $stmtStatChart ? $stmtStatChart->fetchAll(PDO::FETCH_ASSOC) : [];

            // Chart 2: Distribución por Tipo de Orden dinámico según filtros
            $sqlTipo = "
                SELECT 
                    CASE 
                        WHEN tipo_orden = 'P' OR cve_tipo_orden = 'P' THEN 'Público / Particular'
                        WHEN tipo_orden = 'G' OR cve_tipo_orden = 'G' THEN 'Garantía'
                        WHEN tipo_orden = 'I' OR cve_tipo_orden = 'I' THEN 'Interna'
                        WHEN tipo_orden = 'H' OR cve_tipo_orden = 'H' THEN 'Hojalatería y Pintura'
                        WHEN tipo_orden = 'A' OR cve_tipo_orden = 'A' THEN 'Aseguradora'
                        WHEN tipo_orden = 'N' OR cve_tipo_orden = 'N' THEN 'Nuevos'
                        WHEN tipo_orden = 'S' OR cve_tipo_orden = 'S' THEN 'Seminuevos'
                        WHEN tipo_orden = 'E' OR cve_tipo_orden = 'E' THEN 'Externo'
                        WHEN tipo_orden = 'R' OR cve_tipo_orden = 'R' THEN 'Reacondicionamiento'
                        ELSE COALESCE(NULLIF(tipo_orden, ''), 'Sin clasificar')
                    END as tipo,
                    COUNT(*) as total
                FROM reportes_ordenes_servicio
                WHERE $whereSql
                GROUP BY tipo
                ORDER BY total DESC
            ";
            $stmtTipoChart = $pdo->prepare($sqlTipo);
            $stmtTipoChart->execute($params);
            $chartTipos = $stmtTipoChart ? $stmtTipoChart->fetchAll(PDO::FETCH_ASSOC) : [];

            // Chart 3: Antigüedad de Órdenes Abiertas (Aging Brackets) dinámico según filtros
            $sqlAging = "
                SELECT 
                    SUM(CASE WHEN dias_abierta <= 3 THEN 1 ELSE 0 END) as normal_0_3,
                    SUM(CASE WHEN dias_abierta BETWEEN 4 AND 7 THEN 1 ELSE 0 END) as atencion_4_7,
                    SUM(CASE WHEN dias_abierta BETWEEN 8 AND 15 THEN 1 ELSE 0 END) as retraso_8_15,
                    SUM(CASE WHEN dias_abierta > 15 THEN 1 ELSE 0 END) as critica_mas_15
                FROM reportes_ordenes_servicio
                WHERE ($whereSql) AND (cve_estatus = 'AB' OR descripcion_estatus LIKE '%ABIERTA%')
            ";
            $stmtAging = $pdo->prepare($sqlAging);
            $stmtAging->execute($params);
            $chartAging = $stmtAging ? $stmtAging->fetch(PDO::FETCH_ASSOC) : [];

            // Catálogos para filtros
            $stmtCatAlm = $pdo->query("SELECT DISTINCT almacen FROM reportes_ordenes_servicio WHERE almacen IS NOT NULL AND almacen != '' ORDER BY almacen ASC");
            $catalogoAlmacenesOrd = $stmtCatAlm ? $stmtCatAlm->fetchAll(PDO::FETCH_COLUMN) : [];

            $stmtCatStat = $pdo->query("SELECT DISTINCT cve_estatus, descripcion_estatus FROM reportes_ordenes_servicio WHERE cve_estatus IS NOT NULL ORDER BY descripcion_estatus ASC");
            $catalogoEstatus = $stmtCatStat ? $stmtCatStat->fetchAll(PDO::FETCH_ASSOC) : [];

            $stmtCatTip = $pdo->query("SELECT DISTINCT tipo_orden FROM reportes_ordenes_servicio WHERE tipo_orden IS NOT NULL AND tipo_orden != '' ORDER BY tipo_orden ASC");
            $catalogoTipos = $stmtCatTip ? $stmtCatTip->fetchAll(PDO::FETCH_COLUMN) : [];

            $stmtCatAse = $pdo->query("SELECT DISTINCT nombre_asesor FROM reportes_ordenes_servicio WHERE nombre_asesor IS NOT NULL AND nombre_asesor != '' ORDER BY nombre_asesor ASC");
            $catalogoAsesores = $stmtCatAse ? $stmtCatAse->fetchAll(PDO::FETCH_COLUMN) : [];

            // Consulta de lista filtrada
            $sqlOrdList = "SELECT * FROM reportes_ordenes_servicio WHERE $whereSql ORDER BY (CASE WHEN cve_estatus = 'AB' THEN 1 ELSE 2 END) ASC, dias_abierta DESC, fecha_alta DESC LIMIT 400";
            $stmtOrdList = $pdo->prepare($sqlOrdList);
            $stmtOrdList->execute($params);
            $ordenesServicio = $stmtOrdList->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {}
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php 
        if ($reporteActivo === 'inventario_seminuevos') echo 'Inventario de Seminuevos';
        elseif ($reporteActivo === 'ordenes_servicio') echo 'Órdenes de Servicio';
        else echo 'Módulo de Reportes de Agencia';
    ?> - PORTAL <?php echo htmlspecialchars(strtoupper($agenciaNombre)); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
            background: linear-gradient(180deg, #0a1b32 0%, #061122 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 22px 20px;
            transition: all 0.28s ease;
            position: relative;
            overflow: hidden;
        }
        .card-kpi::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--kpi-accent, #10b981);
            box-shadow: 0 0 12px var(--kpi-accent, #10b981);
        }
        .card-kpi:hover {
            transform: translateY(-4px);
            border-color: rgba(16, 185, 129, 0.4);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5), 0 0 18px rgba(16, 185, 129, 0.12);
        }
        .filter-box {
            background: linear-gradient(180deg, #071529 0%, #050f1d 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }
        .btn-filtrar {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            border: none !important;
            color: #03140d !important;
            font-weight: 700 !important;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35) !important;
            transition: all 0.2s ease !important;
        }
        .btn-filtrar:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5) !important;
        }
        .report-box-card {
            background: linear-gradient(180deg, #091931 0%, #061122 100%);
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
        .table-custom, .table-custom-container {
            background: #081528 !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
        }
        .table-custom table, .table-custom-container table {
            margin-bottom: 0;
            color: #ffffff !important;
            background: #081528 !important;
            --bs-table-bg: #081528 !important;
            --bs-table-accent-bg: #081528 !important;
            --bs-table-striped-bg: #081528 !important;
            --bs-table-color: #ffffff !important;
            --bs-table-hover-bg: #0d2240 !important;
            --bs-table-hover-color: #ffffff !important;
        }
        .table-custom th, .table-custom-container th {
            background: #040d1a !important;
            color: #94a3b8 !important;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 15px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
            white-space: nowrap;
        }
        .table-custom td, .table-custom-container td {
            background: #081528 !important;
            color: #ffffff !important;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
            vertical-align: middle;
            font-size: 0.88rem;
        }
        .table-custom tbody tr, .table-custom-container tbody tr {
            background: #081528 !important;
            transition: background-color 0.18s ease;
        }
        .table-custom tbody tr:hover td, .table-custom-container tbody tr:hover td {
            background: #0e2444 !important;
            color: #ffffff !important;
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
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .dot-pulse {
            width: 7px;
            height: 7px;
            background: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulseGreen 2s infinite;
        }
        @keyframes pulseGreen {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .btn-copy-vin {
            background: transparent;
            border: none;
            color: #64748b;
            padding: 0 4px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: color 0.15s ease;
        }
        .btn-copy-vin:hover {
            color: #38bdf8;
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
                PORTAL <span class="text-white"><?php echo htmlspecialchars(strtoupper($agenciaNombre)); ?></span> 
                <span class="text-<?php echo ($reporteActivo === 'ordenes_servicio') ? 'primary' : 'success'; ?>">
                    | <?php 
                        if ($reporteActivo === 'inventario_seminuevos') echo 'Inventario de Seminuevos';
                        elseif ($reporteActivo === 'ordenes_servicio') echo 'Órdenes de Servicio';
                        else echo 'Reportes Agencia';
                    ?>
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
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
            <div>
                <h2 class="fw-bold text-white mb-2">Módulo de Reportes de Agencia</h2>
                <p class="text-secondary mb-0" style="max-width: 650px;">
                    Selecciona en los recuadros inferiores el reporte que deseas consultar y analizar en tiempo real.
                </p>
            </div>

            <!-- Estado Sincronización -->
            <div class="d-flex flex-column align-items-lg-end gap-1">
                <span class="badge p-2 px-3 rounded-pill <?php echo (!empty($ultimaSync)) ? 'bg-success bg-opacity-25 text-success border border-success' : 'bg-secondary bg-opacity-25 text-secondary border border-secondary'; ?>">
                    <i class="bi <?php echo (!empty($ultimaSync)) ? 'bi-check-circle-fill' : 'bi-clock-fill'; ?> me-1"></i>
                    <?php echo (!empty($ultimaSync)) ? 'Sincronizado' : 'Sin sincronizar'; ?>
                </span>
                <div class="text-secondary small font-monospace">
                    Última Sincronización: <span class="text-info fw-semibold"><?php echo !empty($ultimaSync) ? htmlspecialchars($ultimaSync) : 'Sin transmisión previa'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- GRID DE RECUADROS DE REPORTES -->
    <div class="row g-4">

        <!-- RECUADRO 1: INVENTARIO DE SEMINUEVOS -->
        <div class="col-md-6 col-lg-4">
            <div class="report-box-card" style="border-color: rgba(16, 185, 129, 0.45);">
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
                    <p class="text-secondary small mb-4">
                        Consulta general del inventario de vehículos seminuevos disponibles para venta.
                    </p>
                </div>

                <div>
                    <a href="reportes_agencia.php?reporte=inventario_seminuevos" class="btn btn-success text-dark fw-bold w-100 rounded-3 py-2 shadow-sm d-flex align-items-center justify-content-center gap-2">
                        <span>Ingresar al Reporte</span> <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- RECUADRO 2: ÓRDENES DE SERVICIO -->
        <div class="col-md-6 col-lg-4">
            <div class="report-box-card" style="border-color: rgba(59, 130, 246, 0.45);">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="p-3 rounded-3" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; font-size: 1.8rem;">
                            <i class="bi bi-tools"></i>
                        </div>
                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary px-3 py-1 rounded-pill">
                            <i class="bi bi-check-circle-fill me-1"></i> DISPONIBLE
                        </span>
                    </div>
                    <h4 class="fw-bold text-white mb-2">Órdenes de Servicio</h4>
                    <p class="text-secondary small mb-4">
                        Monitoreo en tiempo real de órdenes de taller, análisis de órdenes abiertas, días de retraso y distribución por estatus y tipo.
                    </p>
                </div>
                <div>
                    <a href="reportes_agencia.php?reporte=ordenes_servicio" class="btn btn-primary text-white fw-bold w-100 rounded-3 py-2 shadow-sm d-flex align-items-center justify-content-center gap-2">
                        <span>Ingresar al Reporte</span> <i class="bi bi-arrow-right"></i>
                    </a>
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

    <?php elseif ($reporteActivo === 'inventario_seminuevos'): ?>
    <!-- ===================================================================== -->
    <!-- VISTA 2: REPORTE EN DETALLE (INVENTARIO DE SEMINUEVOS)                 -->
    <!-- ===================================================================== -->

    <!-- HERO PANEL REPORTE DETALLE -->
    <div class="hero-panel">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
            <div>
                <h2 class="fw-bold text-white mb-1">Inventario de Seminuevos</h2>
                <p class="text-secondary small mb-0">
                    Listado actualizado de vehículos seminuevos con estatus <span class="text-success fw-semibold">Disponible</span>.
                </p>
            </div>

            <!-- Estado de Sincronización -->
            <div class="d-flex flex-column align-items-lg-end gap-1">
                <span class="badge p-2 px-3 rounded-pill <?php echo (!empty($ultimaSync)) ? 'bg-success bg-opacity-25 text-success border border-success' : 'bg-warning bg-opacity-25 text-warning border border-warning'; ?>">
                    <i class="bi <?php echo (!empty($ultimaSync)) ? 'bi-check-circle-fill' : 'bi-clock-fill'; ?> me-1"></i>
                    <?php echo (!empty($ultimaSync)) ? 'Sincronizado' : 'Esperando Conexión'; ?>
                </span>
                <div class="text-secondary small font-monospace">
                    Última Sincronización: <span class="text-info fw-semibold"><?php echo !empty($ultimaSync) ? htmlspecialchars($ultimaSync) : 'Sin transmisión previa'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- TARJETAS DE KPIs MODERNAS -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi" style="--kpi-accent: #10b981;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Unidades Disponibles</span>
                    <div class="p-2 rounded-3 bg-success bg-opacity-15 text-success fs-5">
                        <i class="bi bi-car-front-fill"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-white"><?php echo number_format($totalUnidades); ?></div>
                <small class="text-secondary">Autos listos para venta</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi" style="--kpi-accent: #38bdf8;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Almacenes con Stock</span>
                    <div class="p-2 rounded-3 bg-info bg-opacity-15 text-info fs-5">
                        <i class="bi bi-building-check"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-white"><?php echo number_format($totalAlmacenes); ?></div>
                <small class="text-secondary">Concesionarios y patios activos</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi" style="--kpi-accent: #f59e0b;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Importe Inventario</span>
                    <div class="p-2 rounded-3 bg-warning bg-opacity-15 text-warning fs-5">
                        <i class="bi bi-box-seam-fill"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-warning font-monospace">$<?php echo number_format($totalImporteInventario, 2); ?></div>
                <small class="text-secondary">Costo acumulado de unidades</small>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card-kpi" style="--kpi-accent: #10b981;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Valor Potencial Venta</span>
                    <div class="p-2 rounded-3 bg-success bg-opacity-15 text-success fs-5">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-success font-monospace">$<?php echo number_format($totalPrecioVenta, 2); ?></div>
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
                <button type="submit" class="btn btn-filtrar w-100 rounded-3 py-2">
                    <i class="bi bi-funnel-fill me-1"></i> Filtrar
                </button>
                <a href="reportes_agencia.php?reporte=inventario_seminuevos" class="btn btn-outline-secondary rounded-3 text-white px-3" title="Limpiar Filtros">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-secondary border-opacity-25 flex-wrap gap-2">
            <div class="text-secondary small d-flex align-items-center gap-2">
                <span class="badge bg-dark border border-secondary text-light font-monospace">
                    <?php echo count($unidades); ?> / <?php echo $totalUnidades; ?>
                </span>
                <span>unidades disponibles en inventario.</span>
            </div>
            <div class="d-flex gap-2">
                <?php if ($puedeExportar): ?>
                    <a href="reportes_agencia.php?<?php echo http_build_query(array_merge($_GET, ['reporte' => 'inventario_seminuevos', 'exportar' => 'excel'])); ?>" class="btn btn-outline-success btn-sm rounded-3 px-3 shadow-sm">
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
                        <th style="width: 180px;">Chasis (VIN)</th>
                        <th style="width: 120px;">Color</th>
                        <th style="width: 130px;">Motor</th>
                        <th style="width: 110px; text-align: center;">Marca / Año</th>
                        <th style="width: 120px; text-align: center;">Estatus</th>
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
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="font-monospace text-warning fw-bold small">
                                            <?php echo htmlspecialchars($u['chasis']); ?>
                                        </span>
                                        <button type="button" class="btn-copy-vin" onclick="copiarVIN('<?php echo htmlspecialchars($u['chasis']); ?>', this)" title="Copiar VIN">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                    </div>
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
                                        <span class="dot-pulse"></span> Disponible
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="fw-bold font-monospace text-success fs-6">
                                        $<?php echo number_format(floatval($u['precio_venta']), 2); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="font-monospace text-secondary small">
                                        $<?php echo number_format(floatval($u['importe_inventario'] ?: $u['costo_inventario']), 2); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="small text-secondary font-monospace">
                                        <?php echo date('d/m/Y', strtotime($u['fecha_alta'])); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-sm btn-outline-info rounded-3 p-1 px-2 lh-1 shadow-sm" onclick='abrirFichaAuto(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="Ver Ficha Técnica Completa">
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

    <?php elseif ($reporteActivo === 'ordenes_servicio'): ?>
    <!-- ===================================================================== -->
    <!-- VISTA 3: REPORTE EN DETALLE (ÓRDENES DE SERVICIO)                     -->
    <!-- ===================================================================== -->

    <!-- HERO PANEL REPORTE DETALLE -->
    <div class="hero-panel" style="background: linear-gradient(135deg, rgba(8, 21, 40, 0.95) 0%, rgba(14, 42, 77, 0.6) 100%); border-color: rgba(59, 130, 246, 0.35);">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary px-3 py-1 rounded-pill small">
                        <i class="bi bi-tools me-1"></i> TALLER & SERVICIO
                    </span>
                    <span class="badge bg-dark border border-secondary text-secondary small">
                        <?php echo count($ordenesServicio); ?> registros mostrados
                    </span>
                </div>
                <h2 class="fw-bold text-white mb-1">Órdenes de Servicio</h2>
                <p class="text-secondary small mb-0">
                    Control de órdenes de taller en tiempo real, análisis de tiempo transcurrido sin cerrar y distribución operativa.
                </p>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($puedeExportar): 
                    $paramsExcel = array_filter([
                        'reporte'     => 'ordenes_servicio',
                        'exportar'    => 'excel',
                        'q'           => $filtroQ,
                        'almacen'     => $filtroAlmacen,
                        'estatus'     => $filtroEstatus,
                        'tipo'        => $filtroTipo,
                        'asesor'      => $filtroAsesor,
                        'antiguedad'  => $filtroAntiguedad,
                        'fecha_desde' => $filtroFechaI,
                        'fecha_hasta' => $filtroFechaF
                    ]);
                ?>
                    <a href="?<?php echo http_build_query($paramsExcel); ?>" class="btn btn-outline-success btn-sm rounded-3 px-3 py-2 text-white">
                        <i class="bi bi-file-earmark-excel-fill text-success me-1"></i> Exportar a Excel
                    </a>
                <?php endif; ?>

                <div class="d-flex flex-column align-items-lg-end gap-1">
                    <span class="badge p-2 px-3 rounded-pill <?php echo (!empty($ultimaSync)) ? 'bg-success bg-opacity-25 text-success border border-success' : 'bg-warning bg-opacity-25 text-warning border border-warning'; ?>">
                        <i class="bi <?php echo (!empty($ultimaSync)) ? 'bi-check-circle-fill' : 'bi-clock-fill'; ?> me-1"></i>
                        <?php echo (!empty($ultimaSync)) ? 'Sincronizado' : 'Esperando Conexión'; ?>
                    </span>
                    <div class="text-secondary small font-monospace">
                        Última Sincronización: <span class="text-info fw-semibold"><?php echo !empty($ultimaSync) ? htmlspecialchars($ultimaSync) : 'Sin transmisión previa'; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TARJETAS DE KPIs -->
    <div class="row g-3 mb-4">
        <!-- Total Órdenes -->
        <div class="col-sm-6 col-lg">
            <div class="card-kpi" style="--kpi-accent: #38bdf8;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Total Órdenes</span>
                    <div class="p-2 rounded-3 bg-info bg-opacity-15 text-info fs-5">
                        <i class="bi bi-folder-check"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-white"><?php echo number_format($totalOrdenes); ?></div>
                <small class="text-secondary">Órdenes analizadas</small>
            </div>
        </div>

        <!-- Órdenes Abiertas -->
        <div class="col-sm-6 col-lg">
            <div class="card-kpi" style="--kpi-accent: #f59e0b; border-color: rgba(245, 158, 11, 0.35);">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-warning small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Órdenes Abiertas</span>
                    <div class="p-2 rounded-3 bg-warning bg-opacity-15 text-warning fs-5">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-warning d-flex align-items-center gap-2">
                    <?php echo number_format($totalAbiertas); ?>
                    <span class="dot-pulse" style="background: #f59e0b; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);"></span>
                </div>
                <small class="text-secondary">Actualmente en proceso en taller</small>
            </div>
        </div>

        <!-- Órdenes Vencidas vs Promesa -->
        <div class="col-sm-6 col-lg">
            <div class="card-kpi" style="--kpi-accent: #ef4444; border-color: rgba(239, 68, 68, 0.35);">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-danger small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Vencidas vs Promesa</span>
                    <div class="p-2 rounded-3 bg-danger bg-opacity-15 text-danger fs-5">
                        <i class="bi bi-alarm-fill"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-danger"><?php echo number_format($totalVencidas); ?></div>
                <small class="text-secondary">Superaron fecha pactada</small>
            </div>
        </div>

        <!-- Promedio Días Abiertas -->
        <div class="col-sm-6 col-lg">
            <div class="card-kpi" style="--kpi-accent: #a855f7;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Promedio Abiertas</span>
                    <div class="p-2 rounded-3 bg-primary bg-opacity-15 text-primary fs-5" style="color: #c084fc !important;">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-white"><?php echo $promedioDiasAbiertas; ?> <span class="fs-5 text-secondary">días</span></div>
                <small class="text-secondary">Tiempo medio sin cerrar</small>
            </div>
        </div>

        <!-- Órdenes Críticas (+15 días) -->
        <div class="col-sm-6 col-lg">
            <div class="card-kpi" style="--kpi-accent: #dc2626;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Críticas (+15 Días)</span>
                    <div class="p-2 rounded-3 bg-danger bg-opacity-15 text-danger fs-5">
                        <i class="bi bi-exclamation-octagon-fill"></i>
                    </div>
                </div>
                <div class="fs-2 fw-bold text-white"><?php echo number_format($totalCriticas); ?></div>
                <small class="text-secondary">Abiertas con alto retraso</small>
            </div>
        </div>
    </div>

    <!-- SECCIÓN DE GRÁFICAS INTERACTIVAS -->
    <div class="row g-3 mb-4">
        <!-- Gráfica 1: Distribución por Estatus -->
        <div class="col-lg-4">
            <div class="card-kpi h-100 p-3" style="--kpi-accent: #3b82f6;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-pie-chart-fill text-info me-2"></i>Distribución por Estatus</h6>
                    <span class="badge bg-dark border border-secondary text-secondary small">Total Órdenes</span>
                </div>
                <div style="position: relative; height: 230px;">
                    <canvas id="chartEstatusCanvas"></canvas>
                </div>
            </div>
        </div>

        <!-- Gráfica 2: Distribución por Tipo de Orden -->
        <div class="col-lg-4">
            <div class="card-kpi h-100 p-3" style="--kpi-accent: #10b981;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-bar-chart-fill text-success me-2"></i>Tipo de Orden</h6>
                    <span class="badge bg-dark border border-secondary text-secondary small">Clasificación</span>
                </div>
                <div style="position: relative; height: 230px;">
                    <canvas id="chartTiposCanvas"></canvas>
                </div>
            </div>
        </div>

        <!-- Gráfica 3: Antigüedad de Órdenes Abiertas (Aging) -->
        <div class="col-lg-4">
            <div class="card-kpi h-100 p-3" style="--kpi-accent: #f59e0b;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-hourglass-top text-warning me-2"></i>Antigüedad Órdenes Abiertas</h6>
                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning small">Tiempo sin cerrar</span>
                </div>
                <div style="position: relative; height: 230px;">
                    <canvas id="chartAgingCanvas"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- PANEL DE FILTROS AVANZADOS -->
    <div class="card-kpi mb-4 p-3" style="--kpi-accent: #6366f1;">
        <form method="GET" action="reportes_agencia.php" class="row g-2 align-items-end">
            <input type="hidden" name="reporte" value="ordenes_servicio">

            <!-- Búsqueda rápida -->
            <div class="col-md-3">
                <label class="form-label text-secondary small fw-bold mb-1">Búsqueda Rápida</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control bg-dark border-secondary text-white" placeholder="No. Orden, Cliente, VIN, Placas, Modelo..." value="<?php echo htmlspecialchars($filtroQ); ?>">
                </div>
            </div>

            <!-- Almacén -->
            <div class="col-sm-6 col-md-2">
                <label class="form-label text-secondary small fw-bold mb-1">Almacén</label>
                <select name="almacen" class="form-select form-select-sm bg-dark border-secondary text-white">
                    <option value="">Todos los Almacenes</option>
                    <?php foreach ($catalogoAlmacenesOrd as $alm): ?>
                        <option value="<?php echo htmlspecialchars($alm); ?>" <?php echo ($filtroAlmacen === $alm) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($alm); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Estatus -->
            <div class="col-sm-6 col-md-2">
                <label class="form-label text-secondary small fw-bold mb-1">Estatus</label>
                <select name="estatus" class="form-select form-select-sm bg-dark border-secondary text-white">
                    <option value="">Todos los Estatus</option>
                    <option value="AB" <?php echo ($filtroEstatus === 'AB') ? 'selected' : ''; ?>>Solo Abiertas (AB)</option>
                    <option value="CE" <?php echo ($filtroEstatus === 'CE') ? 'selected' : ''; ?>>Cerradas / Facturadas (CE)</option>
                    <option value="CA" <?php echo ($filtroEstatus === 'CA') ? 'selected' : ''; ?>>Canceladas (CA/CN)</option>
                </select>
            </div>

            <!-- Tipo de Orden -->
            <div class="col-sm-6 col-md-2">
                <label class="form-label text-secondary small fw-bold mb-1">Tipo de Orden</label>
                <select name="tipo" class="form-select form-select-sm bg-dark border-secondary text-white">
                    <option value="">Todos los Tipos</option>
                    <?php foreach ($catalogoTipos as $tipo): ?>
                        <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo ($filtroTipo === $tipo) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tipo); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Antigüedad / Retraso -->
            <div class="col-sm-6 col-md-3">
                <label class="form-label text-secondary small fw-bold mb-1">Antigüedad (Abiertas)</label>
                <select name="antiguedad" class="form-select form-select-sm bg-dark border-secondary text-white">
                    <option value="">Cualquier Antigüedad</option>
                    <option value="critica" <?php echo ($filtroAntiguedad === 'critica') ? 'selected' : ''; ?>>🔴 Críticas (> 15 días)</option>
                    <option value="retrasada" <?php echo ($filtroAntiguedad === 'retrasada') ? 'selected' : ''; ?>>🟠 Retrasadas (8 a 15 días)</option>
                    <option value="atencion" <?php echo ($filtroAntiguedad === 'atencion') ? 'selected' : ''; ?>>🟡 En atención (4 a 7 días)</option>
                    <option value="normal" <?php echo ($filtroAntiguedad === 'normal') ? 'selected' : ''; ?>>🟢 Normales (0 a 3 días)</option>
                    <option value="vencida" <?php echo ($filtroAntiguedad === 'vencida') ? 'selected' : ''; ?>>⚠️ Vencidas vs Promesa</option>
                </select>
            </div>

            <!-- Asesor -->
            <div class="col-sm-6 col-md-3 mt-2">
                <label class="form-label text-secondary small fw-bold mb-1">Asesor de Servicio</label>
                <select name="asesor" class="form-select form-select-sm bg-dark border-secondary text-white">
                    <option value="">Todos los Asesores</option>
                    <?php foreach ($catalogoAsesores as $ase): ?>
                        <option value="<?php echo htmlspecialchars($ase); ?>" <?php echo ($filtroAsesor === $ase) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ase); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Rango Fecha Alta -->
            <div class="col-sm-6 col-md-4 mt-2">
                <label class="form-label text-secondary small fw-bold mb-1">Rango Fecha Alta</label>
                <div class="d-flex align-items-center gap-2">
                    <input type="date" name="fecha_desde" class="form-control form-control-sm bg-dark border-secondary text-white" value="<?php echo htmlspecialchars($filtroFechaI); ?>">
                    <span class="text-secondary small">a</span>
                    <input type="date" name="fecha_hasta" class="form-control form-control-sm bg-dark border-secondary text-white" value="<?php echo htmlspecialchars($filtroFechaF); ?>">
                </div>
            </div>

            <!-- Botones de Acción -->
            <div class="col-md-5 mt-2 d-flex flex-wrap justify-content-end align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-3 px-3 fw-bold">
                    <i class="bi bi-funnel-fill me-1"></i> Aplicar Filtros
                </button>
                <?php if ($puedeExportar): ?>
                    <button type="submit" name="exportar" value="excel" class="btn btn-outline-success btn-sm rounded-3 px-3 text-white fw-bold">
                        <i class="bi bi-file-earmark-excel-fill text-success me-1"></i> Exportar a Excel
                    </button>
                <?php endif; ?>
                <a href="reportes_agencia.php?reporte=ordenes_servicio" class="btn btn-outline-secondary btn-sm text-secondary rounded-3">
                    <i class="bi bi-x-circle me-1"></i> Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- TABLA DE ÓRDENES DE SERVICIO -->
    <div class="table-custom-container" style="background: #081528 !important; border: 1px solid rgba(255, 255, 255, 0.1) !important;">
        <div class="table-responsive" style="max-height: 650px;">
            <table class="table table-dark table-hover align-middle mb-0" style="background: #081528 !important; --bs-table-bg: #081528 !important; color: #ffffff !important;">
                <thead class="sticky-top" style="background: #040d1a !important;">
                    <tr style="background: #040d1a !important;">
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">No. Orden / Alm.</th>
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">Estatus</th>
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">Tiempo sin Cerrar</th>
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">Tipo Orden</th>
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">Vehículo</th>
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">Cliente</th>
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">Asesor</th>
                        <th style="background: #040d1a !important; color: #94a3b8 !important;">Fecha Alta / Promesa</th>
                        <th class="text-center" style="background: #040d1a !important; color: #94a3b8 !important;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ordenesServicio)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-secondary" style="background: #081528 !important;">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <span>No se encontraron órdenes de servicio con los filtros aplicados.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ordenesServicio as $ord): 
                            $esAbierta = ($ord['cve_estatus'] === 'AB' || stripos($ord['descripcion_estatus'], 'ABIERTA') !== false);
                            $esCerrada = ($ord['cve_estatus'] === 'CE' || stripos($ord['descripcion_estatus'], 'CERRADA') !== false);
                            $dias = intval($ord['dias_abierta'] ?? 0);
                            $timeProm = !empty($ord['fecha_promesa']) ? strtotime($ord['fecha_promesa']) : 0;
                            $tienePromValida = ($timeProm > strtotime('2000-01-01'));
                            $esVencida = ($esAbierta && $tienePromValida && $timeProm < time());
                        ?>
                            <tr style="background: #081528 !important;">
                                <!-- No. Orden -->
                                <td style="background: #081528 !important;">
                                    <span class="fw-bold text-white fs-6 font-monospace" style="cursor: pointer; color: #ffffff !important;" onclick='abrirFichaOrden(<?php echo json_encode($ord, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                        <?php echo htmlspecialchars($ord['no_orden']); ?>
                                    </span>
                                    <?php if (!empty($ord['almacen'])): ?>
                                        <div class="small font-monospace" style="color: #38bdf8 !important;">Alm: <?php echo htmlspecialchars($ord['almacen']); ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Estatus -->
                                <td style="background: #081528 !important;">
                                    <?php if ($esAbierta): ?>
                                        <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2 py-1 rounded-pill d-inline-flex align-items-center gap-1 font-monospace">
                                            <span class="dot-pulse" style="background: #f59e0b; width: 6px; height: 6px;"></span>
                                            ABIERTA
                                        </span>
                                    <?php elseif ($esCerrada): ?>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-1 rounded-pill font-monospace">
                                            <i class="bi bi-check2 me-1"></i> CERRADA
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary px-2 py-1 rounded-pill font-monospace">
                                            <?php echo htmlspecialchars($ord['descripcion_estatus'] ?: $ord['cve_estatus']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Tiempo sin Cerrar -->
                                <td style="background: #081528 !important;">
                                    <?php if ($esAbierta): ?>
                                        <?php if ($dias > 15): ?>
                                            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger px-2 py-1 rounded-2">
                                                <i class="bi bi-exclamation-octagon-fill me-1"></i> <?php echo $dias; ?> días (Crítica)
                                            </span>
                                        <?php elseif ($dias >= 8): ?>
                                            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2 py-1 rounded-2">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?php echo $dias; ?> días (Retraso)
                                            </span>
                                        <?php elseif ($dias >= 4): ?>
                                            <span class="badge bg-info bg-opacity-25 text-info border border-info px-2 py-1 rounded-2">
                                                <i class="bi bi-clock-history me-1"></i> <?php echo $dias; ?> días (Atención)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-1 rounded-2">
                                                <i class="bi bi-check2-circle me-1"></i> <?php echo $dias; ?> días (Al día)
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($esVencida): ?>
                                            <div class="small text-danger mt-1 fw-bold">
                                                <i class="bi bi-alarm-fill me-1"></i> Vencida vs Promesa
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($esCerrada): ?>
                                        <span class="badge bg-dark border border-secondary text-secondary px-2 py-1 rounded-2">
                                            <i class="bi bi-check-all text-success me-1"></i> <?php echo $dias; ?> días ciclo
                                        </span>
                                    <?php else: ?>
                                        <span class="text-secondary small">---</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Tipo Orden -->
                                <td style="background: #081528 !important;">
                                    <span class="badge bg-dark border border-secondary text-light px-2 py-1">
                                        <?php echo htmlspecialchars($ord['tipo_orden'] ?: $ord['cve_tipo_orden']); ?>
                                    </span>
                                </td>

                                <!-- Vehículo -->
                                <td style="background: #081528 !important;">
                                    <div class="fw-bold text-white" style="color: #ffffff !important;">
                                        <?php echo htmlspecialchars($ord['modelo'] ?: 'Sin Modelo'); ?> 
                                        <span class="text-secondary small ms-1">(<?php echo htmlspecialchars($ord['ano'] ?: '---'); ?>)</span>
                                    </div>
                                    <div class="small font-monospace text-secondary d-flex align-items-center gap-1 mt-1">
                                        <span>VIN:</span>
                                        <code class="text-warning fw-bold font-monospace" style="background: transparent; color: #facc15 !important;"><?php echo htmlspecialchars($ord['vin_chasis'] ?: '---'); ?></code>
                                        <?php if (!empty($ord['vin_chasis'])): ?>
                                            <button type="button" class="btn-copy-vin text-secondary" title="Copiar VIN" onclick="copiarVIN('<?php echo htmlspecialchars($ord['vin_chasis']); ?>', this)">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($ord['placas'])): ?>
                                            <span class="ms-1 badge bg-secondary bg-opacity-25 text-info px-1 py-0"><?php echo htmlspecialchars($ord['placas']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Cliente -->
                                <td style="background: #081528 !important;">
                                    <div class="fw-bold text-white" style="color: #ffffff !important;"><?php echo htmlspecialchars($ord['cliente'] ?: '---'); ?></div>
                                    <?php if (!empty($ord['telefono'])): ?>
                                        <div class="small text-info mt-1"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($ord['telefono']); ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Asesor -->
                                <td style="background: #081528 !important;">
                                    <div class="text-light small fw-medium" style="color: #f1f5f9 !important;"><?php echo htmlspecialchars($ord['nombre_asesor'] ?: 'Sin Asignar'); ?></div>
                                </td>

                                <!-- Fechas -->
                                <td style="background: #081528 !important;">
                                    <div class="small text-white">
                                        <span class="text-secondary">Alta:</span> <?php echo !empty($ord['fecha_alta']) ? date('d/m/Y', strtotime($ord['fecha_alta'])) : '---'; ?>
                                    </div>
                                    <?php if ($tienePromValida): ?>
                                        <div class="small <?php echo ($esVencida) ? 'text-danger fw-bold' : 'text-info'; ?>">
                                            <span>Promesa:</span> <?php echo date('d/m/Y', $timeProm); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="small text-secondary opacity-75">Sin promesa</div>
                                    <?php endif; ?>
                                </td>

                                <!-- Acciones -->
                                <td class="text-center" style="background: #081528 !important;">
                                    <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 py-1 text-white fw-bold shadow-sm" style="background: #2563eb !important; border-color: #1d4ed8 !important;" onclick='abrirFichaOrden(<?php echo json_encode($ord, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                        <i class="bi bi-eye-fill me-1"></i> Detalle
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL DE DETALLE DE ORDEN DE SERVICIO -->
    <div class="modal fade" id="modalFichaOrden" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background: #081528; border: 1px solid rgba(59, 130, 246, 0.4); border-radius: 18px; color: #fff;">
                <div class="modal-header border-secondary border-opacity-25 pb-2">
                    <div>
                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary px-3 py-1 rounded-pill small mb-1">
                            Orden de Servicio
                        </span>
                        <h4 class="modal-title fw-bold text-white mb-0" id="fo_orden">No. Orden</h4>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Resumen rápido -->
                    <div class="row g-3 p-3 rounded-3 mb-4" style="background: #050d1a; border: 1px solid rgba(255,255,255,0.06);">
                        <div class="col-sm-4">
                            <span class="text-secondary small d-block">Estatus Actual</span>
                            <span class="fs-5 fw-bold text-warning" id="fo_estatus">---</span>
                        </div>
                        <div class="col-sm-4">
                            <span class="text-secondary small d-block">Tiempo Transcurrido</span>
                            <span class="fs-5 fw-bold text-white" id="fo_dias">---</span>
                        </div>
                        <div class="col-sm-4">
                            <span class="text-secondary small d-block">Tipo de Orden</span>
                            <span class="fs-5 fw-bold text-info" id="fo_tipo">---</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Vehículo -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 h-100" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06);">
                                <h6 class="fw-bold text-info mb-3"><i class="bi bi-car-front me-2"></i>Datos del Vehículo</h6>
                                <div class="mb-2"><strong>Modelo:</strong> <span class="text-white" id="fo_modelo">---</span></div>
                                <div class="mb-2"><strong>Año / Color:</strong> <span class="text-white" id="fo_ano_color">---</span></div>
                                <div class="mb-2"><strong>Placas:</strong> <span class="text-white" id="fo_placas">---</span></div>
                                <div class="mb-2"><strong>Kilometraje:</strong> <span class="text-white" id="fo_kmts">---</span></div>
                                <div class="mb-0"><strong>Chasis (VIN):</strong> <code class="text-warning" id="fo_vin">---</code></div>
                            </div>
                        </div>

                        <!-- Cliente -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 h-100" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06);">
                                <h6 class="fw-bold text-success mb-3"><i class="bi bi-person-badge me-2"></i>Datos del Cliente</h6>
                                <div class="mb-2"><strong>Nombre:</strong> <span class="text-white" id="fo_cliente">---</span></div>
                                <div class="mb-2"><strong>No. Cliente:</strong> <span class="text-white" id="fo_num_cliente">---</span></div>
                                <div class="mb-2"><strong>RFC:</strong> <span class="text-white" id="fo_rfc">---</span></div>
                                <div class="mb-0"><strong>Teléfono:</strong> <span class="text-white" id="fo_telefono">---</span></div>
                            </div>
                        </div>

                        <!-- Fechas y Tiempos -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 h-100" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06);">
                                <h6 class="fw-bold text-warning mb-3"><i class="bi bi-calendar-event me-2"></i>Fechas y Tiempos</h6>
                                <div class="mb-2"><strong>Fecha Alta:</strong> <span class="text-white" id="fo_fecha_alta">---</span></div>
                                <div class="mb-2"><strong>Hora Inicio:</strong> <span class="text-white" id="fo_hora_ini">---</span></div>
                                <div class="mb-2"><strong>Fecha Promesa:</strong> <span class="text-white" id="fo_fecha_promesa">---</span></div>
                                <div class="mb-2"><strong>Fecha Entrega:</strong> <span class="text-white" id="fo_fecha_entrega">---</span></div>
                                <div class="mb-0"><strong>Hora Fin:</strong> <span class="text-white" id="fo_hora_fin">---</span></div>
                            </div>
                        </div>

                        <!-- Asesor y Operación -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 h-100" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06);">
                                <h6 class="fw-bold text-primary mb-3"><i class="bi bi-briefcase me-2"></i>Asesor y Operación</h6>
                                <div class="mb-2"><strong>Asesor de Servicio:</strong> <span class="text-white" id="fo_asesor">---</span></div>
                                <div class="mb-2"><strong>Tipo de Pago:</strong> <span class="text-white" id="fo_tipo_pago">---</span></div>
                                <div class="mb-2"><strong>No. Factura:</strong> <span class="text-white" id="fo_factura">---</span></div>
                                <div class="mb-2"><strong>Almacén:</strong> <span class="text-white" id="fo_almacen">---</span></div>
                                <div class="mb-0"><strong>Usuario Registro:</strong> <span class="text-white" id="fo_usuario">---</span></div>
                            </div>
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

function abrirFichaOrden(item) {
    document.getElementById('fo_orden').textContent = 'Orden #' + (item.no_orden || '---');
    document.getElementById('fo_estatus').textContent = item.descripcion_estatus || item.cve_estatus || '---';
    document.getElementById('fo_dias').textContent = (item.dias_abierta || 0) + ' días (' + (item.estatus_tiempo || '---') + ')';
    document.getElementById('fo_tipo').textContent = item.tipo_orden || item.cve_tipo_orden || '---';
    
    document.getElementById('fo_modelo').textContent = item.modelo || '---';
    document.getElementById('fo_ano_color').textContent = (item.ano || '---') + ' / ' + (item.color || '---');
    document.getElementById('fo_placas').textContent = item.placas || '---';
    document.getElementById('fo_kmts').textContent = item.kilometraje ? (item.kilometraje + ' km') : '---';
    document.getElementById('fo_vin').textContent = item.vin_chasis || '---';

    document.getElementById('fo_cliente').textContent = item.cliente || '---';
    document.getElementById('fo_num_cliente').textContent = item.num_cliente || '---';
    document.getElementById('fo_rfc').textContent = item.rfc || '---';
    document.getElementById('fo_telefono').textContent = item.telefono || '---';

    document.getElementById('fo_fecha_alta').textContent = item.fecha_alta || '---';
    document.getElementById('fo_hora_ini').textContent = item.hora_inicio || '---';
    let fProm = item.fecha_promesa || '---';
    if (fProm.startsWith('1753-') || fProm.startsWith('1900-') || fProm === '01/01/1753') {
        fProm = 'Sin promesa registrada';
    }
    document.getElementById('fo_fecha_promesa').textContent = fProm;
    document.getElementById('fo_fecha_entrega').textContent = item.fecha_entrega || '---';
    document.getElementById('fo_hora_fin').textContent = item.hora_fin || '---';

    document.getElementById('fo_asesor').textContent = item.nombre_asesor || '---';
    document.getElementById('fo_tipo_pago').textContent = item.tipo_pago || '---';
    document.getElementById('fo_factura').textContent = item.no_factura || '---';
    document.getElementById('fo_almacen').textContent = item.almacen || '---';
    document.getElementById('fo_usuario').textContent = item.usuario_registro || '---';

    const modal = new bootstrap.Modal(document.getElementById('modalFichaOrden'));
    modal.show();
}

function copiarVIN(vin, btn) {
    if (!navigator.clipboard) {
        const temp = document.createElement('input');
        temp.value = vin;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
    } else {
        navigator.clipboard.writeText(vin);
    }
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = 'bi bi-check2 text-success';
        setTimeout(() => { icon.className = 'bi bi-clipboard'; }, 1500);
    }
}

<?php if ($reporteActivo === 'ordenes_servicio'): ?>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Chart Estatus
    const ctxStat = document.getElementById('chartEstatusCanvas');
    if (ctxStat) {
        const dataStat = <?php echo json_encode($chartEstatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
        const labelsStat = dataStat.map(d => d.grupo_estatus);
        const valuesStat = dataStat.map(d => Number(d.total));
        const colorsStat = labelsStat.map(l => {
            if (l.includes('Abierta')) return '#f59e0b';
            if (l.includes('Cerrada')) return '#10b981';
            if (l.includes('Cancelada')) return '#ef4444';
            return '#64748b';
        });

        new Chart(ctxStat, {
            type: 'doughnut',
            data: {
                labels: labelsStat,
                datasets: [{
                    data: valuesStat,
                    backgroundColor: colorsStat,
                    borderColor: '#081528',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#94a3b8', font: { size: 11 }, padding: 10 }
                    }
                }
            }
        });
    }

    // 2. Chart Tipos de Orden
    const ctxTip = document.getElementById('chartTiposCanvas');
    if (ctxTip) {
        const dataTip = <?php echo json_encode($chartTipos, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
        const labelsTip = dataTip.map(d => d.tipo);
        const valuesTip = dataTip.map(d => Number(d.total));

        new Chart(ctxTip, {
            type: 'bar',
            data: {
                labels: labelsTip,
                datasets: [{
                    label: 'Órdenes',
                    data: valuesTip,
                    backgroundColor: 'rgba(56, 189, 248, 0.75)',
                    borderColor: '#38bdf8',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8', font: { size: 10 }, maxRotation: 25 },
                        grid: { display: false }
                    },
                    y: {
                        ticks: { color: '#94a3b8', font: { size: 10 } },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    }
                }
            }
        });
    }

    // 3. Chart Aging (Antigüedad de órdenes abiertas)
    const ctxAging = document.getElementById('chartAgingCanvas');
    if (ctxAging) {
        const agingData = <?php echo json_encode($chartAging, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
        new Chart(ctxAging, {
            type: 'bar',
            data: {
                labels: ['0-3 días', '4-7 días', '8-15 días', '+15 días'],
                datasets: [{
                    label: 'Órdenes Abiertas',
                    data: [
                        Number(agingData.normal_0_3 || 0),
                        Number(agingData.atencion_4_7 || 0),
                        Number(agingData.retraso_8_15 || 0),
                        Number(agingData.critica_mas_15 || 0)
                    ],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.85)',
                        'rgba(245, 158, 11, 0.85)',
                        'rgba(249, 115, 22, 0.85)',
                        'rgba(239, 68, 68, 0.85)'
                    ],
                    borderColor: ['#10b981', '#f59e0b', '#f97316', '#ef4444'],
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8', font: { size: 10 } },
                        grid: { display: false }
                    },
                    y: {
                        ticks: { color: '#94a3b8', font: { size: 10 } },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    }
                }
            }
        });
    }
});
<?php endif; ?>
</script>
</body>
</html>
