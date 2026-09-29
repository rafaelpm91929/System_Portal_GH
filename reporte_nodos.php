<?php
session_start();
require_once 'conexion.php';
require_once 'excel_helper.php';

// Protección de Sesión opcional o verificación
$usuarioSesion = $_SESSION['usuario_id'] ?? null;

// Parámetro opcional para filtrar por un nodo específico
$filtroNodo = isset($_GET['nodo']) ? trim($_GET['nodo']) : '';
$filtroId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 1. Obtener datos de la agencia para el encabezado
$stmtAgencia = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
$agenciaData = $stmtAgencia ? $stmtAgencia->fetch(PDO::FETCH_ASSOC) : [];

$agenciaNombre     = !empty($agenciaData['nombre']) ? $agenciaData['nombre'] : ($_SESSION['agencia'] ?? 'DIVOL LA VILLA');
$agenciaRazon      = $agenciaData['razon_social'] ?? '';
$agenciaRfc        = $agenciaData['rfc'] ?? '';
$agenciaDireccion  = $agenciaData['direccion'] ?? '';
$agenciaEncargado  = !empty($agenciaData['encargado_sistemas']) ? $agenciaData['encargado_sistemas'] : 'Heriberto Rojo';
$agenciaTel        = $agenciaData['telefono_sistemas'] ?? '';
$agenciaEmail      = $agenciaData['correo_sistemas'] ?? '';
$logoRel           = $agenciaData['logo_url'] ?? '';

// Convertir logo a base64 para impresión perfecta
$logoBase64 = '';
if (!empty($logoRel)) {
    $logoPaths = [
        $logoRel,
        __DIR__ . '/' . $logoRel,
        __DIR__ . '/' . ltrim($logoRel, '/'),
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . ltrim($logoRel, '/')
    ];
    foreach ($logoPaths as $lp) {
        if (!empty($lp) && file_exists($lp) && is_file($lp)) {
            $sz = @getimagesize($lp);
            if ($sz && !empty($sz['mime'])) {
                $content = @file_get_contents($lp);
                if ($content !== false) {
                    $logoBase64 = 'data:' . $sz['mime'] . ';base64,' . base64_encode($content);
                    break;
                }
            }
        }
    }
}

// 2. Consulta de Nodos de Red
$sqlNodos = "SELECT * FROM infra_nodos";
$paramsNodos = [];
if (!empty($filtroNodo)) {
    $sqlNodos .= " WHERE codigo_nodo = ?";
    $paramsNodos[] = $filtroNodo;
} elseif ($filtroId > 0) {
    $sqlNodos .= " WHERE id = ?";
    $paramsNodos[] = $filtroId;
}
$sqlNodos .= " ORDER BY CAST(codigo_nodo AS INTEGER) ASC, codigo_nodo ASC";

$stmtNodos = $pdo->prepare($sqlNodos);
$stmtNodos->execute($paramsNodos);
$registrosNodos = $stmtNodos->fetchAll(PDO::FETCH_ASSOC);

// 3. Mapeo con equipos de inventario (VW, CORP, SITE, MONITORES)
$equiposMap = [];
$tablasInv = [
    'VW' => 'inv_equipos_vw',
    'CORP' => 'inv_equipos_corp',
    'SITE' => 'inv_site_vw',
    'MONITOR' => 'inv_monitores',
    'TEL' => 'inv_telefonos_poe'
];

function getValSafe($arr, $keys, $default = '') {
    foreach ((array)$keys as $k) {
        if (!empty($arr[$k]) && trim((string)$arr[$k]) !== '') {
            return trim((string)$arr[$k]);
        }
    }
    return $default;
}

foreach ($tablasInv as $prefijo => $tabla) {
    try {
        $st = $pdo->query("SELECT * FROM `$tabla` WHERE numero_nodo IS NOT NULL AND trim(numero_nodo) != ''");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $nk = trim((string)$r['numero_nodo']);
            if ($nk !== '') {
                $nombre = getValSafe($r, ['nombre_equipo', 'equipo', 'marca']);
                if ($prefijo === 'MONITOR' && !empty($r['modelo_exacto'])) {
                    $nombre .= ' ' . $r['modelo_exacto'];
                } elseif ($prefijo === 'TEL') {
                    $extLimpia = trim($r['extension'] ?? '');
                    $nombre = !empty($extLimpia) ? ('Ext. ' . $extLimpia) : '';
                }
                if (!isset($equiposMap[$nk])) {
                    $equiposMap[$nk] = [];
                }
                $equiposMap[$nk][] = [
                    'origen' => $prefijo,
                    'id' => $r['id'],
                    'nombre' => $nombre ?: 'Equipo #' . $r['id'],
                    'tipo' => ($prefijo === 'TEL') ? 'Teléfono PoE' : getValSafe($r, ['tipo_equipo', 'tipo_registro', 'tipo_servidor', 'tipo_switch'], 'Dispositivo'),
                    'usuario' => getValSafe($r, ['usuario', 'responsable', 'nombre'], 'Sin Asignar'),
                    'departamento' => getValSafe($r, ['departamento', 'area'], 'General'),
                    'puesto' => getValSafe($r, ['puesto'], ''),
                    'ip' => getValSafe($r, ['ip', 'ip_local']),
                    'mac' => getValSafe($r, ['mac', 'direccion_mac', 'mac_ethernet', 'mac_wifi']),
                    'serie' => getValSafe($r, ['serie', 'numero_serie', 'serie_equipo']),
                    'puerto_sw' => getValSafe($r, ['puerto_sw']),
                    'switch' => getValSafe($r, ['switch_nombre']),
                    'es_telefono' => ($prefijo === 'TEL')
                ];
            }
        }
    } catch (Throwable $e) {}
}

// 4. Mapeo con puertos de switch asignados en infra_switch_puertos
$puertosMap = [];
try {
    $st = $pdo->query("SELECT * FROM infra_switch_puertos WHERE (nodo_codigo != '' AND nodo_codigo IS NOT NULL) OR (equipo_nombre != '' AND equipo_nombre IS NOT NULL)");
    while ($p = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($p['nodo_codigo'])) {
            $puertosMap[trim($p['nodo_codigo'])] = $p;
        }
    }
} catch (Throwable $e) {}

// Métricas para el resumen
$totalNodos = count($registrosNodos);
$nodosActivos = 0;
$nodosConEquipo = 0;
$vlansCountMap = [];

foreach ($registrosNodos as $nd) {
    if (strtolower($nd['estatus'] ?? '') === 'activo') $nodosActivos++;
    $c = trim($nd['codigo_nodo'] ?? '');
    if ((isset($equiposMap[$c]) && !empty($equiposMap[$c])) || (isset($puertosMap[$c]) && !empty($puertosMap[$c]['equipo_nombre']))) {
        $nodosConEquipo++;
    }
    if (!empty($nd['vlan'])) {
        $vlansCountMap[$nd['vlan']] = ($vlansCountMap[$nd['vlan']] ?? 0) + 1;
    }
}
$fechaEmision = date('d/m/Y H:i');

// ====================================================
// EXPORTACIÓN A EXCEL (.XLS) CON DISEÑO CORPORATIVO OFICIAL
// ====================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'exportar_excel') {
    $agenciaInfo = obtenerDatosAgenciaExcel($pdo, $agenciaNombre);
    $agenciaLimpia = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agenciaInfo['nombre']);
    $fileName = "Cedula_Nodos_RJ45_{$agenciaLimpia}_" . date('Ymd_His') . ".xls";

    $columnasExcel = [
        ['label' => 'Código Nodo', 'width' => '90px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Tipo de Nodo', 'width' => '110px', 'align' => 'center'],
        ['label' => 'Área / Ubicación', 'width' => '160px', 'align' => 'left'],
        ['label' => 'Switch Asignado', 'width' => '150px', 'align' => 'left'],
        ['label' => 'Pto Switch', 'width' => '90px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Patch Panel', 'width' => '130px', 'align' => 'left'],
        ['label' => 'Pto Panel', 'width' => '80px', 'align' => 'center', 'is_text' => true],
        ['label' => 'VLAN', 'width' => '80px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Estatus', 'width' => '95px', 'align' => 'center'],
        ['label' => 'Dispositivo Conectado', 'width' => '180px', 'align' => 'left'],
        ['label' => 'Usuario Asignado', 'width' => '160px', 'align' => 'left'],
        ['label' => 'Dirección IP', 'width' => '120px', 'align' => 'center', 'is_text' => true]
    ];

    $filasExcel = [];
    foreach ($registrosNodos as $nd) {
        $c = trim($nd['codigo_nodo'] ?? '');
        $eq = $equiposMap[$c] ?? null;
        $swInfo = $puertosMap[$c] ?? null;

        $swNombre = !empty($nd['switch_nombre']) ? $nd['switch_nombre'] : ($swInfo['switch_nombre'] ?? '---');
        $swPto = !empty($nd['switch_puerto']) ? $nd['switch_puerto'] : ($swInfo['puerto_numero'] ?? '---');
        $dispositivo = $eq ? ($eq['nombre'] ?: $eq['tipo']) : ($swInfo['equipo_nombre'] ?? '---');
        $usuario = $eq['usuario'] ?? '---';
        $ip = $eq['ip'] ?? '---';

        $esActivo = (strtolower($nd['estatus'] ?? '') === 'activo');

        $filasExcel[] = [
            ['val' => '<b>' . htmlspecialchars($c) . '</b>', 'align' => 'center', 'is_text' => true],
            ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($nd['tipo_nodo'] ?? 'Datos') . '</span>', 'align' => 'center'],
            ['val' => htmlspecialchars($nd['area_ubicacion'] ?? 'General'), 'align' => 'left'],
            ['val' => htmlspecialchars($swNombre), 'align' => 'left'],
            ['val' => htmlspecialchars($swPto), 'align' => 'center', 'is_text' => true],
            ['val' => htmlspecialchars($nd['patch_panel'] ?? '---'), 'align' => 'left'],
            ['val' => htmlspecialchars($nd['puerto_patch'] ?? '---'), 'align' => 'center', 'is_text' => true],
            ['val' => htmlspecialchars($nd['vlan'] ?? '---'), 'align' => 'center', 'is_text' => true],
            ['val' => '<span class="badge-pill ' . ($esActivo ? 'badge-status-ok' : 'badge-status-warn') . '">' . htmlspecialchars($nd['estatus'] ?? 'Activo') . '</span>', 'align' => 'center'],
            ['val' => '<strong>' . htmlspecialchars($dispositivo) . '</strong>', 'align' => 'left'],
            ['val' => htmlspecialchars($usuario), 'align' => 'left'],
            ['val' => htmlspecialchars($ip), 'align' => 'center', 'is_text' => true]
        ];
    }

    descargarExcelConDiseno(
        'CÉDULA MATRIZ DE NODOS DE RED RJ45',
        'Inventario y Mapeo Físico de Cableado Estructurado',
        $columnasExcel,
        $filasExcel,
        $agenciaInfo,
        $fileName,
        'Nodos_RJ45'
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cédula de Nodos de Red RJ45 - <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-navy: #0f172a;
            --primary-blue: #0284c7;
            --accent-cyan: #06b6d4;
            --border-color: #cbd5e1;
            --bg-light: #f8fafc;
        }
        
        body {
            background-color: #f1f5f9;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 13px;
            line-height: 1.4;
            padding-bottom: 50px;
        }

        /* BARRA DE HERRAMIENTAS FLOTANTE (SE OCULTA AL IMPRIMIR) */
        .toolbar-flotante {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            padding: 10px 24px;
        }

        .document-container {
            max-width: 1320px;
            margin: 24px auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
            padding: 24px 30px;
        }

        /* ENCABEZADO INSTITUCIONAL CON FONDO BLANCO */
        .header-box {
            background: #ffffff;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 22px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .cartela-tecnica {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 11.5px;
            min-width: 250px;
        }

        .cartela-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3.5px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .cartela-row:last-child {
            border-bottom: none;
        }

        .cartela-label {
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            font-size: 10.5px;
        }

        .cartela-val {
            font-weight: 800;
            color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        /* TARJETAS RESUMEN KPIS */
        .kpi-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        /* TABLA MATRIZ EJECUTIVA */
        .table-nodos {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 15px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            overflow: hidden;
            font-size: 12px;
        }

        .table-nodos thead th {
            background: #0f172a;
            color: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            border-bottom: 2px solid #0284c7;
            vertical-align: middle;
        }

        .table-nodos tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            color: #1e293b;
        }

        .table-nodos tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .table-nodos tbody tr:hover {
            background-color: #f0fdf4;
        }

        .nodo-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #e0f2fe;
            color: #0369a1;
            border: 1.5px solid #38bdf8;
            font-weight: 800;
            font-family: monospace;
            font-size: 14px;
        }

        /* VISTA TARJETAS / CÉDULAS */
        .grid-cedulas {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
            margin-top: 18px;
        }

        .nodo-cedula-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            page-break-inside: avoid;
            position: relative;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .nodo-cedula-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }

        .flow-diagram-step {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* SECCIÓN DE FIRMAS */
        .signatures-section {
            margin-top: 36px;
            padding-top: 20px;
            border-top: 1.5px solid #cbd5e1;
            page-break-inside: avoid;
        }

        .signature-line-box {
            text-align: center;
            padding: 0 20px;
        }

        .signature-line {
            width: 100%;
            height: 1px;
            background-color: #475569;
            margin: 45px auto 8px auto;
        }

        /* REGLAS ESTRICTAS DE IMPRESIÓN */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
                font-size: 11px !important;
            }

            .toolbar-flotante, .no-print {
                display: none !important;
            }

            .document-container {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            .header-box {
                border: 1px solid #94a3b8 !important;
                box-shadow: none !important;
                page-break-inside: avoid;
                margin-bottom: 12px !important;
                padding: 10px 14px !important;
            }

            .table-nodos thead th {
                background: #0f172a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .table-nodos tbody tr {
                page-break-inside: avoid;
            }

            .nodo-badge {
                border: 1px solid #0284c7 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .nodo-cedula-card {
                border: 1px solid #94a3b8 !important;
                box-shadow: none !important;
            }

            @page {
                size: landscape;
                margin: 8mm 10mm;
            }
        }
    </style>
</head>
<body>

    <!-- BARRA DE ACCIÓN FLOTANTE (NO IMPRIMIBLE) -->
    <div class="toolbar-flotante no-print d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <a href="infraestructura.php?sec=red&sub=nodos" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i> Volver a Infraestructura
            </a>
            <span class="text-white-50">|</span>
            <span class="text-white fw-bold d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-pdf-fill text-danger fs-5"></i> Cédula Oficial de Nodos RJ45
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Alternador de Vista (Tabla vs Cédulas) -->
            <div class="btn-group btn-group-sm rounded-pill p-1 bg-dark border border-secondary" role="group">
                <button type="button" class="btn btn-sm btn-info text-dark fw-bold rounded-pill px-3" id="btnVistaTabla" onclick="cambiarVistaReporte('tabla')">
                    <i class="bi bi-table me-1"></i> Vista Tabla Matriz
                </button>
                <button type="button" class="btn btn-sm btn-dark text-white rounded-pill px-3" id="btnVistaCedulas" onclick="cambiarVistaReporte('cedulas')">
                    <i class="bi bi-grid me-1"></i> Vista Cédulas / Tarjetas
                </button>
            </div>

            <!-- Botón Exportar a Excel -->
            <a href="reporte_nodos.php?accion=exportar_excel<?php echo !empty($filtroNodo) ? '&nodo=' . urlencode($filtroNodo) : ''; ?>" class="btn btn-success btn-sm rounded-pill px-3 py-1.5 fw-bold shadow-sm d-flex align-items-center gap-1.5" style="background: #16a34a; border-color: #16a34a;" title="Exportar Cédula de Nodos a Microsoft Excel con logotipo oficial">
                <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel (.xls)
            </a>

            <!-- Botón Imprimir / PDF -->
            <button type="button" class="btn btn-warning btn-sm rounded-pill px-4 py-1.5 fw-bold shadow-sm" onclick="window.print()">
                <i class="bi bi-printer-fill me-1"></i> Imprimir / Guardar PDF
            </button>
        </div>
    </div>

    <!-- DOCUMENTO FORMAL / CÉDULA TÉCNICA -->
    <div class="document-container">

        <!-- 1. ENCABEZADO INSTITUCIONAL DE AGENCIA (FONDO BLANCO) -->
        <div class="header-box">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                
                <!-- LOGO Y DATOS DE LA AGENCIA -->
                <div class="d-flex align-items-center gap-3">
                    <?php if (!empty($logoBase64)): ?>
                        <div class="p-1 rounded bg-white" style="border: 1px solid #e2e8f0;">
                            <img src="<?php echo $logoBase64; ?>" alt="Logo <?php echo htmlspecialchars($agenciaNombre); ?>" style="max-height: 58px; max-width: 150px; object-fit: contain;">
                        </div>
                    <?php else: ?>
                        <div class="rounded-3 d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 58px; height: 58px; background: linear-gradient(135deg, #0284c7, #0f172a); font-size: 1.6rem;">
                            <?php echo strtoupper(substr($agenciaNombre, 0, 1)); ?>
                        </div>
                    <?php endif; ?>

                    <div style="border-left: 2px solid #e2e8f0; padding-left: 14px;">
                        <h4 class="fw-bold mb-0 text-uppercase" style="color: #0f172a; letter-spacing: -0.3px;">
                            <?php echo htmlspecialchars($agenciaNombre); ?>
                        </h4>
                        <?php if (!empty($agenciaRazon)): ?>
                            <div class="small fw-semibold text-secondary" style="font-size: 11px;">
                                <?php echo htmlspecialchars($agenciaRazon); ?> <?php echo !empty($agenciaRfc) ? '· RFC: ' . htmlspecialchars($agenciaRfc) : ''; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-1 d-flex align-items-center gap-2">
                            <span class="badge" style="background-color: #0284c7; color: #ffffff; font-size: 10px; font-weight: 700; letter-spacing: 0.5px;">
                                <i class="bi bi-hdd-network-fill me-1"></i> INFRAESTRUCTURA DE RED
                            </span>
                            <span class="fw-bold text-dark" style="font-size: 12.5px;">
                                MATRIZ TÉCNICA DE NODOS RJ45 & CONECTIVIDAD
                            </span>
                        </div>

                        <?php if (!empty($agenciaDireccion)): ?>
                            <div class="text-muted small mt-0.5" style="font-size: 10px;">
                                <i class="bi bi-geo-alt me-0.5 text-danger"></i> <?php echo htmlspecialchars($agenciaDireccion); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CARTELA TÉCNICA DE EMISIÓN Y ENCARGADO -->
                <div class="cartela-tecnica">
                    <div class="cartela-row">
                        <span class="cartela-label"><i class="bi bi-calendar-event me-1"></i> EMISIÓN:</span>
                        <span class="cartela-val"><?php echo $fechaEmision; ?></span>
                    </div>
                    <div class="cartela-row">
                        <span class="cartela-label"><i class="bi bi-person-badge me-1"></i> ENCARGADO:</span>
                        <span class="cartela-val text-primary" style="font-size: 11px;"><?php echo htmlspecialchars($agenciaEncargado); ?></span>
                    </div>
                    <div class="cartela-row">
                        <span class="cartela-label"><i class="bi bi-diagram-3 me-1"></i> ÁREA / DEPTO:</span>
                        <span class="cartela-val">Sistemas / IT</span>
                    </div>
                    <div class="cartela-row">
                        <span class="cartela-label"><i class="bi bi-hash me-1"></i> TOTAL NODOS:</span>
                        <span class="cartela-val text-success"><?php echo $totalNodos; ?> Registrados</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- 2. RESUMEN EJECUTIVO (KPIs) -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-ethernet"></i>
                    </div>
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 10px;">Total Nodos</div>
                        <div class="fs-5 fw-bold text-dark font-monospace"><?php echo $totalNodos; ?> <span class="small fw-normal text-muted" style="font-size: 11px;">Puertos</span></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 10px;">Nodos Activos</div>
                        <div class="fs-5 fw-bold text-success font-monospace"><?php echo $nodosActivos; ?> <span class="small fw-normal text-muted" style="font-size: 11px;">Operativos</span></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-display"></i>
                    </div>
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 10px;">Equipos Conectados</div>
                        <div class="fs-5 fw-bold text-dark font-monospace"><?php echo $nodosConEquipo; ?> <span class="small fw-normal text-muted" style="font-size: 11px;">Enlazados</span></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-diagram-3-fill"></i>
                    </div>
                    <div>
                        <div class="text-secondary small fw-bold text-uppercase" style="font-size: 10px;">VLANs Asignadas</div>
                        <div class="fs-5 fw-bold text-dark font-monospace"><?php echo count($vlansCountMap); ?> <span class="small fw-normal text-muted" style="font-size: 11px;">Segmentos</span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. CONTENIDO: VISTA TABLA MATRIZ (DEFAULT) -->
        <div id="seccionVistaTabla">
            <div class="table-responsive">
                <table class="table-nodos">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 75px;">Nodo</th>
                            <th style="width: 140px;">Ubicación / Área</th>
                            <th style="width: 110px;">Tipo Cable</th>
                            <th style="width: 110px;">Patch Panel</th>
                            <th style="width: 140px;">Switch & Puerto</th>
                            <th class="text-center" style="width: 75px;">VLAN</th>
                            <th>Dispositivo Conectado & Usuario</th>
                            <th style="width: 140px;">Dirección IP / MAC</th>
                            <th class="text-center" style="width: 90px;">Estatus</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registrosNodos)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-1"></i> No se encontraron nodos de red registrados en el sistema.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registrosNodos as $nd): 
                                $c = trim($nd['codigo_nodo'] ?? '');
                                $eqList = $equiposMap[$c] ?? [];
                                $swp = $puertosMap[$c] ?? null;

                                // Determinar switch y puerto prioritario
                                $swFinal = !empty($nd['switch_puerto']) ? $nd['switch_puerto'] : '';
                                if ($swp && !empty($swp['switch_nombre'])) {
                                    $swFinal = $swp['switch_nombre'] . ' / P' . $swp['puerto_numero'];
                                }

                                $estatusVal = ucfirst(strtolower($nd['estatus'] ?? 'Activo'));
                                $estatusBadgeClass = ($estatusVal === 'Activo') ? 'bg-success text-white' : (($estatusVal === 'Disponible') ? 'bg-info text-dark' : 'bg-warning text-dark');
                            ?>
                                <tr>
                                    <!-- NODO -->
                                    <td class="text-center">
                                        <div class="nodo-badge mx-auto">
                                            <?php echo htmlspecialchars($nd['codigo_nodo']); ?>
                                        </div>
                                    </td>

                                    <!-- UBICACIÓN -->
                                    <td>
                                        <div class="fw-bold text-dark">
                                            <i class="bi bi-geo-alt-fill text-danger me-1 small"></i>
                                            <?php echo htmlspecialchars($nd['ubicacion'] ?: 'Sin asignar'); ?>
                                        </div>
                                    </td>

                                    <!-- TIPO -->
                                    <td>
                                        <span class="badge bg-light text-secondary border border-secondary border-opacity-25" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($nd['tipo_nodo'] ?: 'Voz y Datos'); ?>
                                        </span>
                                    </td>

                                    <!-- PATCH PANEL -->
                                    <td>
                                        <div class="font-monospace fw-semibold text-primary" style="font-size: 11.5px;">
                                            <i class="bi bi-hdd-network me-1"></i><?php echo htmlspecialchars($nd['patch_panel'] ?: 'N/A'); ?>
                                        </div>
                                    </td>

                                    <!-- SWITCH Y PUERTO -->
                                    <td>
                                        <div class="font-monospace fw-bold" style="color: #6b21a8; font-size: 11.5px;">
                                            <i class="bi bi-cpu-fill me-1" style="color: #9333ea;"></i>
                                            <?php echo htmlspecialchars($swFinal ?: 'Sin conectar'); ?>
                                        </div>
                                    </td>

                                    <!-- VLAN -->
                                    <td class="text-center">
                                        <?php if (!empty($nd['vlan'])): ?>
                                            <span class="badge bg-warning bg-opacity-25 text-dark border border-warning font-monospace" style="font-size: 11px;">
                                                <?php echo htmlspecialchars($nd['vlan']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">--</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- EQUIPO Y USUARIO -->
                                    <td>
                                        <?php if (!empty($eqList)): ?>
                                            <div class="d-flex flex-column gap-2">
                                            <?php foreach ($eqList as $idx => $eqItem): ?>
                                                <div class="<?php echo ($idx > 0) ? 'pt-1 border-top border-dashed border-secondary border-opacity-25' : ''; ?>">
                                                    <div class="fw-bold text-dark d-flex align-items-center gap-1 flex-wrap">
                                                        <?php if ($eqItem['es_telefono']): ?>
                                                            <?php if (!empty($eqItem['nombre'])): ?>
                                                                <span class="badge bg-warning bg-opacity-25 text-dark border border-warning font-monospace" style="font-size: 10px;" title="Teléfono PoE Asignado">
                                                                    <i class="bi bi-telephone-fill me-1"></i><?php echo htmlspecialchars($eqItem['nombre']); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <i class="bi bi-display text-primary" title="Equipo Principal"></i>
                                                            <span><?php echo htmlspecialchars($eqItem['nombre']); ?></span>
                                                            <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size: 9px;">
                                                                <?php echo htmlspecialchars($eqItem['tipo']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!$eqItem['es_telefono'] && !empty($eqItem['usuario']) && $eqItem['usuario'] !== 'Sin Asignar'): ?>
                                                        <div class="small text-muted" style="font-size: 10.5px;">
                                                            <i class="bi bi-person-fill me-0.5 text-secondary"></i> <?php echo htmlspecialchars($eqItem['usuario']); ?>
                                                            <?php if (!empty($eqItem['departamento']) && $eqItem['departamento'] !== 'General'): ?>
                                                                <span class="text-secondary opacity-75">(<?php echo htmlspecialchars($eqItem['departamento']); ?>)</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($swp && !empty($swp['equipo_nombre'])): ?>
                                            <div class="fw-bold text-dark d-flex align-items-center gap-1">
                                                <i class="bi bi-display text-primary"></i>
                                                <?php echo htmlspecialchars($swp['equipo_nombre']); ?>
                                                <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size: 9.5px;"><?php echo htmlspecialchars($swp['equipo_tipo'] ?? 'Equipo'); ?></span>
                                            </div>
                                            <?php if (!empty($swp['usuario'])): ?>
                                                <div class="small text-muted" style="font-size: 10.5px;">
                                                    <i class="bi bi-person-fill me-0.5 text-secondary"></i> <?php echo htmlspecialchars($swp['usuario']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border border-secondary border-opacity-25">Puerto Libre</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- IP & MAC -->
                                    <td>
                                        <?php if (!empty($eqList)): ?>
                                            <div class="d-flex flex-column gap-2">
                                            <?php foreach ($eqList as $idx => $eqItem): ?>
                                                <div class="<?php echo ($idx > 0) ? 'pt-1 border-top border-dashed border-secondary border-opacity-25' : ''; ?>">
                                                    <?php if (!empty($eqItem['ip'])): ?>
                                                        <div class="font-monospace fw-bold text-truncate" style="color: <?php echo $eqItem['es_telefono'] ? '#b45309' : '#0284c7'; ?> !important; font-size: 11px;">
                                                            <i class="bi bi-globe me-0.5"></i> <?php echo htmlspecialchars($eqItem['ip']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($eqItem['mac'])): ?>
                                                        <div class="font-monospace text-secondary text-truncate" style="font-size: 10px;">
                                                            <i class="bi bi-upc me-0.5"></i> <?php echo htmlspecialchars($eqItem['mac']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (empty($eqItem['ip']) && empty($eqItem['mac'])): ?>
                                                        <span class="text-muted small">--</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($swp && (!empty($swp['equipo_ip']) || !empty($swp['telefono_poe_ip']))): ?>
                                            <?php if (!empty($swp['equipo_ip'])): ?>
                                                <div class="font-monospace fw-bold text-info text-truncate" style="color: #0284c7 !important; font-size: 11px;">
                                                    <i class="bi bi-globe me-0.5"></i> <?php echo htmlspecialchars($swp['equipo_ip']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($swp['telefono_poe_ip'])): ?>
                                                <div class="font-monospace fw-bold text-truncate" style="color: #b45309 !important; font-size: 11px;">
                                                    <i class="bi bi-telephone-fill me-0.5"></i> <?php echo htmlspecialchars($swp['telefono_poe_ip']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">--</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- ESTATUS -->
                                    <td class="text-center">
                                        <span class="badge <?php echo $estatusBadgeClass; ?> px-2.5 py-1 rounded-pill" style="font-size: 10.5px;">
                                            <?php echo htmlspecialchars($estatusVal); ?>
                                        </span>
                                    </td>

                                    <!-- NOTAS -->
                                    <td>
                                        <span class="text-secondary small fst-italic" style="font-size: 11px;">
                                            <?php echo htmlspecialchars($nd['notas'] ?: '--'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4. CONTENIDO: VISTA CÉDULAS / FICHAS (OPCIONAL / VISUAL) -->
        <div id="seccionVistaCedulas" style="display: none;">
            <div class="grid-cedulas">
                <?php foreach ($registrosNodos as $nd): 
                    $c = trim($nd['codigo_nodo'] ?? '');
                    $eqList = $equiposMap[$c] ?? [];
                    $swp = $puertosMap[$c] ?? null;

                    $swFinal = !empty($nd['switch_puerto']) ? $nd['switch_puerto'] : '';
                    if ($swp && !empty($swp['switch_nombre'])) {
                        $swFinal = $swp['switch_nombre'] . ' / P' . $swp['puerto_numero'];
                    }

                    $estatusVal = ucfirst(strtolower($nd['estatus'] ?? 'Activo'));
                ?>
                    <div class="nodo-cedula-card">
                        <div class="nodo-cedula-header">
                            <div class="d-flex align-items-center gap-2">
                                <div class="nodo-badge">
                                    <?php echo htmlspecialchars($nd['codigo_nodo']); ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark fs-6">NODO <?php echo htmlspecialchars($nd['codigo_nodo']); ?></div>
                                    <div class="text-muted small" style="font-size: 10.5px;">
                                        <i class="bi bi-geo-alt-fill text-danger me-0.5"></i> <?php echo htmlspecialchars($nd['ubicacion'] ?: 'Sin Ubicación'); ?>
                                    </div>
                                </div>
                            </div>
                            <span class="badge <?php echo ($estatusVal === 'Activo') ? 'bg-success' : 'bg-warning text-dark'; ?> px-2 py-1 rounded-pill" style="font-size: 10px;">
                                <?php echo htmlspecialchars($estatusVal); ?>
                            </span>
                        </div>

                        <!-- DIAGRAMA RUTA DE CONEXIÓN -->
                        <div class="d-flex flex-column gap-1.5 mb-3">
                            <div class="flow-diagram-step">
                                <i class="bi bi-hdd-network text-primary"></i>
                                <span class="text-secondary">Patch Panel:</span>
                                <strong class="text-dark font-monospace"><?php echo htmlspecialchars($nd['patch_panel'] ?: 'No registrado'); ?></strong>
                            </div>
                            <div class="flow-diagram-step">
                                <i class="bi bi-cpu-fill" style="color:#9333ea;"></i>
                                <span class="text-secondary">Switch & Puerto:</span>
                                <strong class="font-monospace" style="color:#6b21a8;"><?php echo htmlspecialchars($swFinal ?: 'Sin conectar'); ?></strong>
                            </div>
                            <div class="flow-diagram-step">
                                <i class="bi bi-diagram-3-fill text-warning"></i>
                                <span class="text-secondary">VLAN:</span>
                                <strong class="font-monospace text-dark"><?php echo htmlspecialchars($nd['vlan'] ?: 'VLAN Nativa'); ?></strong>
                            </div>
                        </div>

                        <!-- DATOS DEL ACTIVO CONECTADO -->
                        <?php if (!empty($eqList)): ?>
                            <?php foreach ($eqList as $idx => $eqItem): ?>
                                <div class="p-2.5 rounded-2 bg-light border border-secondary border-opacity-25 mb-2" style="font-size: 11px;">
                                    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                                        <span class="fw-bold text-dark">
                                            <?php if ($eqItem['es_telefono']): ?>
                                                <i class="bi bi-telephone-fill text-warning me-1"></i> Teléfono PoE (En Serie)
                                            <?php else: ?>
                                                <i class="bi bi-display text-primary me-1"></i> Dispositivo Conectado
                                            <?php endif; ?>
                                        </span>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="badge <?php echo $eqItem['es_telefono'] ? 'bg-warning bg-opacity-25 text-dark border border-warning' : 'bg-secondary bg-opacity-25 text-secondary'; ?>">
                                                <?php echo htmlspecialchars($eqItem['tipo']); ?>
                                            </span>
                                            <?php if ($eqItem['es_telefono'] && count($eqList) > 1): ?>
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size: 8.5px;">
                                                    <i class="bi bi-link-45deg"></i> Cascada
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="fw-bold text-primary font-monospace"><?php echo htmlspecialchars($eqItem['nombre']); ?></div>
                                    <div class="text-muted small">Resp: <strong><?php echo htmlspecialchars($eqItem['usuario']); ?></strong></div>
                                    <?php if (!empty($eqItem['ip'])): ?>
                                        <div class="font-monospace text-dark mt-0.5"><i class="bi bi-globe text-info me-1"></i> IP: <strong style="color: <?php echo $eqItem['es_telefono'] ? '#b45309' : '#0284c7'; ?>"><?php echo htmlspecialchars($eqItem['ip']); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if (!empty($eqItem['mac'])): ?>
                                        <div class="font-monospace text-secondary" style="font-size: 10px;"><i class="bi bi-upc me-1"></i> MAC: <?php echo htmlspecialchars($eqItem['mac']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($swp && !empty($swp['equipo_nombre'])): ?>
                            <div class="p-2.5 rounded-2 bg-light border border-secondary border-opacity-25 mb-2" style="font-size: 11px;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark"><i class="bi bi-display text-info me-1"></i> Dispositivo Conectado</span>
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary"><?php echo htmlspecialchars($swp['equipo_tipo'] ?? 'Equipo'); ?></span>
                                </div>
                                <div class="fw-bold text-primary font-monospace"><?php echo htmlspecialchars($swp['equipo_nombre']); ?></div>
                                <div class="text-muted small">Resp: <strong><?php echo htmlspecialchars($swp['usuario'] ?? 'N/A'); ?></strong></div>
                                <?php if (!empty($swp['equipo_ip'])): ?>
                                    <div class="font-monospace text-dark mt-0.5"><i class="bi bi-globe text-info me-1"></i> IP: <strong><?php echo htmlspecialchars($swp['equipo_ip']); ?></strong></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-2.5 rounded-2 bg-light border border-secondary border-opacity-25 mb-2 text-center text-muted" style="font-size: 11px;">
                                <i class="bi bi-ethernet fs-5 d-block mb-1 text-secondary opacity-50"></i>
                                Sin dispositivo conectado (Nodo Disponible)
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($nd['notas'])): ?>
                            <div class="small text-muted fst-italic mt-1" style="font-size: 10.5px;">
                                <i class="bi bi-journal-text me-1"></i> <?php echo htmlspecialchars($nd['notas']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 5. FIRMAS DE CONFORMIDAD Y VALIDACIÓN TÉCNICA -->
        <div class="signatures-section">
            <div class="row text-center g-4 justify-content-center">
                <div class="col-5">
                    <div class="signature-line-box">
                        <div class="signature-line"></div>
                        <div class="fw-bold text-dark" style="font-size: 12px;"><?php echo htmlspecialchars($agenciaEncargado); ?></div>
                        <div class="text-secondary small" style="font-size: 11px;">Encargado de Sistemas / IT</div>
                        <div class="text-muted" style="font-size: 9.5px;">Revisión y Certificación Técnica de Conectividad</div>
                    </div>
                </div>

                <div class="col-5">
                    <div class="signature-line-box">
                        <div class="signature-line"></div>
                        <div class="fw-bold text-dark" style="font-size: 12px;"><?php echo htmlspecialchars($agenciaNombre); ?></div>
                        <div class="text-secondary small" style="font-size: 11px;">Supervisión Administrativa / Operaciones</div>
                        <div class="text-muted" style="font-size: 9.5px;">Vo.Bo. Infraestructura y Telecomunicaciones</div>
                    </div>
                </div>
            </div>
            
            <div class="text-center text-muted mt-4 pt-2 border-top" style="font-size: 9.5px;">
                CÉDULA TÉCNICA DE INFRAESTRUCTURA &bull; PORTAL DE SISTEMAS &bull; EMITIDO EL <?php echo $fechaEmision; ?> &bull; DOCUMENTO CONFIDENCIAL PARA USO INTERNO
            </div>
        </div>

    </div>

    <script>
        function cambiarVistaReporte(vista) {
            const vTabla = document.getElementById('seccionVistaTabla');
            const vCedulas = document.getElementById('seccionVistaCedulas');
            const btnTabla = document.getElementById('btnVistaTabla');
            const btnCedulas = document.getElementById('btnVistaCedulas');

            if (vista === 'cedulas') {
                vTabla.style.display = 'none';
                vCedulas.style.display = 'block';
                btnCedulas.classList.remove('btn-dark', 'text-white');
                btnCedulas.classList.add('btn-info', 'text-dark', 'fw-bold');
                btnTabla.classList.remove('btn-info', 'text-dark', 'fw-bold');
                btnTabla.classList.add('btn-dark', 'text-white');
            } else {
                vTabla.style.display = 'block';
                vCedulas.style.display = 'none';
                btnTabla.classList.remove('btn-dark', 'text-white');
                btnTabla.classList.add('btn-info', 'text-dark', 'fw-bold');
                btnCedulas.classList.remove('btn-info', 'text-dark', 'fw-bold');
                btnCedulas.classList.add('btn-dark', 'text-white');
            }
        }
    </script>
    <?php imprimirScriptExportadorExcelJS($agenciaData); ?>
</body>
</html>
