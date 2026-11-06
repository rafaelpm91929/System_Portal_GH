<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/excel_helper.php';

if ($pdo) {
    asegurarTablasPermisos($pdo);
    asegurarTablasAuditoriaInterna($pdo);
}

requerirPermiso('auditoria_interna', 'puede_ver');

// Permisos del usuario actual
$puedeEditar = tienePermiso('auditoria_interna', 'puede_editar');
$puedeCrear = tienePermiso('auditoria_interna', 'puede_crear');
$puedeEliminar = tienePermiso('auditoria_interna', 'puede_eliminar');
$puedeExportar = tienePermiso('auditoria_interna', 'puede_exportar');
$esAdmin = in_array(strtolower($_SESSION['usuario_rol'] ?? ''), ['admin', 'superadmin']);

// Datos de sesión y agencia
$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$agenciaData = obtenerDatosAgenciaExcel($pdo ?? null, $_SESSION['agencia'] ?? '');
$agenciaNombre = $agenciaData['nombre'] ?: 'GRUPO HUERTA';

// Listado de áreas disponibles desde inventario y usuarios para los filtros
$areasDisponibles = [];
if ($pdo) {
    try {
        $stmtA = $pdo->query("
            SELECT DISTINCT departamento as area FROM inv_equipos_vw WHERE departamento IS NOT NULL AND departamento != ''
            UNION 
            SELECT DISTINCT area FROM usuarios WHERE area IS NOT NULL AND area != ''
            UNION
            SELECT DISTINCT area FROM audit_correos WHERE area IS NOT NULL AND area != ''
            ORDER BY area ASC
        ");
        $areasDisponibles = $stmtA ? $stmtA->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {}
}
if (empty($areasDisponibles)) {
    $areasDisponibles = ['Administración', 'Caja', 'CRM', 'Dirección', 'Garantías', 'HyP', 'Mercadotecnia', 'Refacciones', 'Seminuevos', 'Servicio', 'Servicios Financieros', 'Sistemas', 'Ventas'];
}

// Métricas de KPIs de cabecera
$totalEquiposAudit = 0;
$equiposConResp = 0;
$mantenimientosProg = 0;
$mantenimientosReal = 0;
$correosActivos = 0;
$correosBajas = 0;
$licenciasActivas = 0;
$usuariosGdsCount = 0;
$passwordsGdsCount = 0;
$usuariosGrpCount = 0;

if ($pdo) {
    try {
        // Equipos
        $stmtEq = $pdo->query("SELECT COUNT(*) as tot, SUM(CASE WHEN responsiva_url IS NOT NULL AND responsiva_url != '' THEN 1 ELSE 0 END) as con_resp FROM inv_equipos_vw WHERE estado IS NULL OR estado != 'Baja'");
        if ($stmtEq && ($rEq = $stmtEq->fetch(PDO::FETCH_ASSOC))) {
            $totalEquiposAudit = (int)($rEq['tot'] ?? 0);
            $equiposConResp = (int)($rEq['con_resp'] ?? 0);
        }
        // Mantenimientos
        $mantenimientosProg = (int)$pdo->query("SELECT COUNT(*) FROM audit_mantenimientos WHERE estado != 'Realizado'")->fetchColumn();
        $mantenimientosReal = (int)$pdo->query("SELECT COUNT(*) FROM audit_mantenimientos WHERE estado = 'Realizado'")->fetchColumn();
        // Correos
        $correosActivos = (int)$pdo->query("SELECT COUNT(*) FROM audit_correos WHERE estado = 'Activo'")->fetchColumn();
        $correosBajas = (int)$pdo->query("SELECT COUNT(*) FROM audit_correos WHERE estado != 'Activo'")->fetchColumn();
        // Licencias
        $licenciasActivas = (int)$pdo->query("SELECT COUNT(*) FROM audit_licencias_office WHERE estado = 'Activa'")->fetchColumn();
        // GDS / GRP
        $usuariosGdsCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_gds_usuarios")->fetchColumn();
        $passwordsGdsCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_gds_passwords_movimientos")->fetchColumn();
        $usuariosGrpCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_grp_usuarios")->fetchColumn();
    } catch (Throwable $eKpi) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría Interna y Control Operativo | <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <?php include_once 'pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <script>
    window.DATOS_AGENCIA_EXCEL = <?php echo json_encode($agenciaData, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <?php renderizarEstilosTemaGlobal($pdo); ?>
    <style>
        :root {
            --bg-dark: #040d1a;
            --bg-card: #09172c;
            --bg-card-hover: #0e2440;
            --gold-accent: #d4af37;
            --gold-border: rgba(212, 175, 55, 0.4);
            --gold-glow: rgba(212, 175, 55, 0.15);
            --color-exitoso: #10b981;
            --color-alerta: #f59e0b;
            --color-critico: #ef4444;
            --color-info: #0284c7;
        }

        body {
            background-color: var(--bg-dark);
            background-image: radial-gradient(#0b223e 1px, transparent 1px);
            background-size: 24px 24px;
            color: #e2e8f0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
        }

        .top-navbar {
            background: rgba(9, 23, 44, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--gold-border);
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .card-executive {
            background: linear-gradient(145deg, rgba(9, 23, 44, 0.9) 0%, rgba(5, 14, 28, 0.95) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.35);
            transition: all 0.25s ease;
        }

        .card-executive:hover {
            border-color: rgba(212, 175, 55, 0.3);
            box-shadow: 0 10px 30px rgba(0,0,0,0.45);
        }

        .kpi-card {
            border-radius: 12px;
            padding: 16px;
            height: 100%;
            background: rgba(13, 30, 54, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            transition: transform 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            border-color: var(--gold-border);
        }

        .kpi-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            font-weight: 700;
        }

        .kpi-number {
            font-size: 1.7rem;
            font-weight: 800;
            line-height: 1.2;
            font-family: 'JetBrains Mono', monospace;
        }

        /* Navegación por Pilares y Pestañas */
        .pillar-header {
            background: rgba(15, 23, 42, 0.8);
            border-radius: 12px;
            padding: 8px 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 16px;
        }

        .nav-pills-custom {
            gap: 8px;
        }

        .nav-pills-custom .nav-link {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.88rem;
            padding: 10px 18px;
            border-radius: 10px;
            border: 1px solid transparent;
            background: rgba(255, 255, 255, 0.03);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-pills-custom .nav-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(255, 255, 255, 0.15);
        }

        .nav-pills-custom .nav-link.active {
            color: #ffffff;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border-color: #38bdf8;
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.35);
        }

        .nav-pills-custom .nav-link.active.pillar-security {
            background: linear-gradient(135deg, #b45309 0%, #78350f 100%);
            border-color: #f59e0b;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.35);
        }

        /* Tablas Customizadas */
        .table-custom {
            width: 100%;
            margin-bottom: 0;
            color: #cbd5e1;
            font-size: 0.86rem;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-custom thead th {
            background-color: #0c1a2e;
            color: #94a3b8;
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 14px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
            white-space: nowrap;
        }

        .table-custom tbody tr {
            transition: background 0.15s ease;
        }

        .table-custom tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.04) !important;
        }

        .table-custom tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            vertical-align: middle;
        }

        .badge-status-pill {
            font-size: 0.74rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            letter-spacing: 0.3px;
        }

        .badge-ok { background: rgba(16, 185, 129, 0.18); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }
        .badge-warn { background: rgba(245, 158, 11, 0.18); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); }
        .badge-err { background: rgba(239, 68, 68, 0.18); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); }
        .badge-info { background: rgba(56, 189, 248, 0.18); color: #7dd3fc; border: 1px solid rgba(56, 189, 248, 0.35); }
        .badge-muted { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }

        .pass-hidden {
            letter-spacing: 3px;
            font-family: 'JetBrains Mono', monospace;
            color: #94a3b8;
        }

        /* Estilos de Impresión */
        @media print {
            .top-navbar, .no-print, .btn, .nav-pills-custom, .input-group, select, input {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .card-executive {
                background: #ffffff !important;
                border: 1px solid #000000 !important;
                box-shadow: none !important;
                color: #000000 !important;
            }
            .table-custom thead th {
                background: #f1f5f9 !important;
                color: #000000 !important;
                border: 1px solid #cbd5e1 !important;
            }
            .table-custom tbody td {
                color: #000000 !important;
                border: 1px solid #cbd5e1 !important;
            }
            .print-only-header {
                display: block !important;
            }
        }
    </style>
</head>
<body>

<!-- Navbar Superior -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm rounded-3 text-white border-opacity-25" title="Regresar al Menú Principal">
            <i class="bi bi-arrow-left me-1"></i> Menú
        </a>
        <div class="vr bg-secondary opacity-25" style="height: 24px;"></div>
        <div class="d-flex align-items-center gap-2">
            <div class="p-2 rounded-3 text-warning d-flex align-items-center justify-content-center" style="background: rgba(212, 175, 55, 0.15); border: 1px solid var(--gold-border); width: 36px; height: 36px;">
                <i class="bi bi-shield-check fs-5"></i>
            </div>
            <div>
                <span class="fw-bold tracking-wide text-white">AUDITORÍA INTERNA & CONTROL OPERATIVO</span>
                <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50 ms-2">TI & SEGURIDAD</span>
            </div>
        </div>
    </div>

    <!-- Opciones de Exportación e Impresión -->
    <div class="d-flex align-items-center gap-2">
        <?php if ($puedeExportar): ?>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-success rounded-3 fw-semibold shadow-sm" onclick="exportarSeccionActivaExcel()" title="Exportar la sección actualmente en pantalla a Microsoft Excel (.xlsx)">
                <i class="bi bi-file-earmark-spreadsheet-fill me-1 text-success"></i> Exportar a Excel
            </button>
            <button type="button" class="btn btn-outline-success dropdown-toggle dropdown-toggle-split rounded-end-3" data-bs-toggle="dropdown" aria-expanded="false" title="Más opciones de exportación">
                <span class="visually-hidden">Opciones de exportación</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow border-secondary border-opacity-50">
                <li><h6 class="dropdown-header text-warning">Reportes en Excel Oficial (.xlsx)</h6></li>
                <li>
                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="exportarSeccionActivaExcel()">
                        <i class="bi bi-file-earmark-excel-fill text-success me-2"></i> Exportar Sección Activa (.xlsx)
                    </a>
                </li>
                <li>
                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="exportarLibroCompletoAuditoriaExcel()">
                        <i class="bi bi-journal-album text-warning me-2"></i> Libro Completo de Auditoría (Multi-Hoja)
                    </a>
                </li>
                <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
                <li><h6 class="dropdown-header text-info">Descargas Directas (.xls)</h6></li>
                <li><a class="dropdown-item py-1 small" href="api_auditoria.php?action=exportar_excel_seccion&seccion=mantenimientos"><i class="bi bi-tools me-2 text-info"></i> Mantenimientos (.xls)</a></li>
                <li><a class="dropdown-item py-1 small" href="api_auditoria.php?action=exportar_excel_seccion&seccion=correos"><i class="bi bi-envelope me-2 text-info"></i> Control de Correos (.xls)</a></li>
                <li><a class="dropdown-item py-1 small" href="api_auditoria.php?action=exportar_excel_seccion&seccion=licencias"><i class="bi bi-key me-2 text-info"></i> Licencias Office (.xls)</a></li>
                <li><a class="dropdown-item py-1 small" href="api_auditoria.php?action=exportar_excel_seccion&seccion=gds_usuarios"><i class="bi bi-person-badge me-2 text-info"></i> Usuarios GDS (.xls)</a></li>
                <li><a class="dropdown-item py-1 small" href="api_auditoria.php?action=exportar_excel_seccion&seccion=gds_passwords"><i class="bi bi-shield-lock me-2 text-info"></i> Passwords Movimientos GDS (.xls)</a></li>
                <li><a class="dropdown-item py-1 small" href="api_auditoria.php?action=exportar_excel_seccion&seccion=grp"><i class="bi bi-people me-2 text-info"></i> Usuarios GRP (.xls)</a></li>
            </ul>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-outline-light btn-sm rounded-3 fw-semibold" onclick="imprimirAuditoria()" title="Imprimir o Guardar en PDF">
            <i class="bi bi-printer me-1"></i> Imprimir / PDF
        </button>

        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3" title="Cerrar Sesión">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="container-fluid px-4 py-4" style="max-width: 1600px;">

    <!-- Encabezado de Página -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-white">Módulo de Auditoría Interna</h2>
            <p class="text-secondary small mb-0">
                Supervisión continua de infraestructura física, responsivas firmadas, planes preventivos y control estricto de accesos a sistemas core (GDS y GRP).
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-dark border border-secondary border-opacity-50 text-secondary p-2">
                <i class="bi bi-building me-1 text-warning"></i> <?php echo htmlspecialchars($agenciaNombre); ?>
            </span>
            <span class="badge bg-dark border border-secondary border-opacity-50 text-secondary p-2">
                <i class="bi bi-calendar3 me-1 text-info"></i> <?php echo date('d/m/Y'); ?>
            </span>
        </div>
    </div>

    <!-- TARJETAS DE KPIS EJECUTIVOS GLOBALES -->
    <div class="row g-3 mb-4">
        <!-- 1. Responsivas Equipos -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive kpi-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Responsivas PC</span>
                    <i class="bi bi-file-earmark-check text-info fs-5"></i>
                </div>
                <div class="kpi-number text-info"><?php echo $equiposConResp; ?> <span class="text-secondary fs-6 fw-normal">/ <?php echo $totalEquiposAudit; ?></span></div>
                <div class="text-secondary small mt-1"><?php echo ($totalEquiposAudit > 0) ? round(($equiposConResp/$totalEquiposAudit)*100) : 0; ?>% equipos firmados</div>
            </div>
        </div>

        <!-- 2. Mantenimientos -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive kpi-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Mantenimientos</span>
                    <i class="bi bi-tools text-warning fs-5"></i>
                </div>
                <div class="kpi-number text-warning"><?php echo $mantenimientosProg; ?></div>
                <div class="text-secondary small mt-1"><?php echo $mantenimientosReal; ?> realizados con PDF</div>
            </div>
        </div>

        <!-- 3. Correos Activos -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive kpi-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Cuentas Correo</span>
                    <i class="bi bi-envelope-check text-success fs-5"></i>
                </div>
                <div class="kpi-number text-success"><?php echo $correosActivos; ?></div>
                <div class="text-secondary small mt-1"><?php echo $correosBajas; ?> cuentas en baja / canceladas</div>
            </div>
        </div>

        <!-- 4. Licencias Office -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive kpi-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Licencias Office</span>
                    <i class="bi bi-key-fill text-primary fs-5"></i>
                </div>
                <div class="kpi-number text-primary"><?php echo $licenciasActivas; ?></div>
                <div class="text-secondary small mt-1">Product Keys auditadas</div>
            </div>
        </div>

        <!-- 5. Usuarios GDS & GRP -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive kpi-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Accesos Core</span>
                    <i class="bi bi-people-fill text-info fs-5"></i>
                </div>
                <div class="kpi-number text-white"><?php echo ($usuariosGdsCount + $usuariosGrpCount); ?></div>
                <div class="text-secondary small mt-1"><?php echo $usuariosGdsCount; ?> GDS + <?php echo $usuariosGrpCount; ?> GRP</div>
            </div>
        </div>

        <!-- 6. Passwords Movimientos GDS -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive kpi-card" style="border-color: rgba(239, 68, 68, 0.3);">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Claves Movimiento</span>
                    <i class="bi bi-shield-lock-fill text-danger fs-5"></i>
                </div>
                <div class="kpi-number text-danger"><?php echo $passwordsGdsCount; ?></div>
                <div class="text-secondary small mt-1">Operaciones críticas GDS</div>
            </div>
        </div>
    </div>

    <!-- NAVEGACIÓN PRINCIPAL: SECCIONES Y SUBSECCIONES -->
    <div class="card-executive p-3 mb-4">
        <ul class="nav nav-pills nav-pills-custom" id="pillsAuditoria" role="tablist">
            
            <!-- PILAR 1: GESTIÓN DE INFRAESTRUCTURA -->
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="pill-responsivas-tab" data-bs-toggle="pill" data-bs-target="#pill-responsivas" type="button" role="tab" onclick="cambiarSeccionActiva('responsivas')">
                    <i class="bi bi-file-earmark-check-fill text-info"></i> Responsivas de Equipos
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pill-mantenimiento-tab" data-bs-toggle="pill" data-bs-target="#pill-mantenimiento" type="button" role="tab" onclick="cambiarSeccionActiva('mantenimiento')">
                    <i class="bi bi-tools text-warning"></i> Planes de Mantenimiento
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pill-correos-tab" data-bs-toggle="pill" data-bs-target="#pill-correos" type="button" role="tab" onclick="cambiarSeccionActiva('correos')">
                    <i class="bi bi-envelope-fill text-success"></i> Control de Correos
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pill-licencias-tab" data-bs-toggle="pill" data-bs-target="#pill-licencias" type="button" role="tab" onclick="cambiarSeccionActiva('licencias')">
                    <i class="bi bi-key-fill text-primary"></i> Licencias de Office
                </button>
            </li>

            <!-- SEPARADOR -->
            <li class="nav-item d-none d-lg-block align-self-center mx-1">
                <div class="vr bg-secondary opacity-50" style="height: 28px;"></div>
            </li>

            <!-- PILAR 2: SEGURIDAD DE LA INFORMACIÓN -->
            <li class="nav-item" role="presentation">
                <button class="nav-link pillar-security" id="pill-gds-usr-tab" data-bs-toggle="pill" data-bs-target="#pill-gds-usr" type="button" role="tab" onclick="cambiarSeccionActiva('gds_usr')">
                    <i class="bi bi-person-badge-fill text-warning"></i> Usuarios GDS
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link pillar-security" id="pill-gds-pass-tab" data-bs-toggle="pill" data-bs-target="#pill-gds-pass" type="button" role="tab" onclick="cambiarSeccionActiva('gds_pass')">
                    <i class="bi bi-shield-lock-fill text-danger"></i> Password GDS (Movimientos)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link pillar-security" id="pill-grp-usr-tab" data-bs-toggle="pill" data-bs-target="#pill-grp-usr" type="button" role="tab" onclick="cambiarSeccionActiva('grp_usr')">
                    <i class="bi bi-people-fill text-info"></i> Usuarios de GRP
                </button>
            </li>
        </ul>
    </div>

    <!-- CONTENIDO DE LAS SUBSECCIONES -->
    <div class="tab-content" id="pillsAuditoriaContent">

        <!-- =================================================================== -->
        <!-- SUBSECCIÓN 1.1: RESPONSIVAS DE EQUIPOS (INVENTARIO)                 -->
        <!-- =================================================================== -->
        <div class="tab-pane fade show active" id="pill-responsivas" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-file-earmark-check-fill text-info me-2"></i>Auditoría de Cartas Responsivas de Equipos</h5>
                        <p class="text-secondary small mb-0">Monitoreo de responsivas físicas y digitales subidas al inventario. Genera formatos para firma y sube evidencias en PDF.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-dark border border-secondary border-opacity-25 text-info px-3 py-2 small" id="contadorBadgeResponsivas">
                            Cargando equipos...
                        </span>
                    </div>
                </div>

                <!-- Filtros de Responsivas -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-12 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" id="busqResp" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por equipo, usuario o serie..." onkeyup="cargarResponsivasEquipos()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroAreaResp" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarResponsivasEquipos()">
                            <option value="">Todas las Áreas</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroEstadoResp" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarResponsivasEquipos()">
                            <option value="">Todos los Estados de Responsiva</option>
                            <option value="firmada">🟢 Con Responsiva Firmada (PDF)</option>
                            <option value="pendiente">🔴 Pendiente de Firma / Subir</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <a href="equipos.php" target="_blank" class="btn btn-outline-info btn-sm rounded-3 w-100">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Ir a Inventario
                        </a>
                    </div>
                </div>

                <!-- Tabla de Responsivas -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaResponsivasEquipos">
                        <thead>
                            <tr>
                                <th>Equipo</th>
                                <th>Usuario Responsable</th>
                                <th>Área / Depto</th>
                                <th>Puesto</th>
                                <th>Tipo / Marca</th>
                                <th>Número de Serie</th>
                                <th class="text-center">Estado Responsiva</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyResponsivasEquipos">
                            <tr><td colspan="8" class="text-center py-4 text-secondary"><div class="spinner-border spinner-border-sm text-info me-2"></div>Cargando equipos del inventario...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SUBSECCIÓN 1.2: PLANES DE MANTENIMIENTO                             -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="pill-mantenimiento" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-tools text-warning me-2"></i>Planes y Calendario de Mantenimiento por Área</h5>
                        <p class="text-secondary small mb-0">Control de mantenimiento preventivo y correctivo de hardware. Adjunta reportes de servicio y evidencias en PDF.</p>
                    </div>
                    <?php if ($puedeCrear): ?>
                    <button class="btn btn-warning btn-sm rounded-3 fw-semibold shadow" onclick="abrirModalNuevoMantenimiento()">
                        <i class="bi bi-plus-circle me-1"></i> Programar Mantenimiento
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Filtros Mantenimiento -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-12 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" id="busqMant" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por equipo, usuario o técnico..." onkeyup="cargarMantenimientos()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroAreaMant" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarMantenimientos()">
                            <option value="">Todas las Áreas</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroEstadoMant" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarMantenimientos()">
                            <option value="">Todos los Estados</option>
                            <option value="Programado">🟡 Programado</option>
                            <option value="En Proceso">🔵 En Proceso</option>
                            <option value="Realizado">🟢 Realizado (Con Evidencia)</option>
                            <option value="Atrasado">🔴 Atrasado</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <span class="text-secondary small" id="contadorMantenimientos">0 registros</span>
                    </div>
                </div>

                <!-- Tabla de Mantenimientos -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaMantenimientos">
                        <thead>
                            <tr>
                                <th>Equipo</th>
                                <th>Área</th>
                                <th>Usuario Asignado</th>
                                <th>Tipo</th>
                                <th>Fecha Programada</th>
                                <th>Fecha Realizada</th>
                                <th>Frecuencia</th>
                                <th class="text-center">Estado</th>
                                <th>Técnico</th>
                                <th class="text-center">Evidencia PDF</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyMantenimientos">
                            <tr><td colspan="11" class="text-center py-4 text-secondary">Cargando planes de mantenimiento...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SUBSECCIÓN 1.3: CONTROL DE CORREOS                                 -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="pill-correos" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-envelope-fill text-success me-2"></i>Matriz y Control de Correos Institucionales</h5>
                        <p class="text-secondary small mb-0">Padrón oficial de cuentas de correo, estado activo, gestión de bajas, cancelaciones y motivos para auditoría.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-outline-info btn-sm rounded-3" onclick="sincronizarCorreos()" title="Importar automáticamente nuevos correos detectados en el inventario o directorio">
                            <i class="bi bi-arrow-repeat me-1"></i> Sincronizar Cuentas
                        </button>
                        <?php if ($puedeCrear): ?>
                        <button class="btn btn-success btn-sm rounded-3 fw-semibold shadow" onclick="abrirModalNuevoCorreo()">
                            <i class="bi bi-plus-circle me-1"></i> Nueva Cuenta de Correo
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filtros Correos -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-12 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" id="busqCorr" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por colaborador, correo o puesto..." onkeyup="cargarCorreos()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroAreaCorr" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarCorreos()">
                            <option value="">Todas las Áreas</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroEstadoCorr" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarCorreos()">
                            <option value="">Todos los Estados</option>
                            <option value="Activo">🟢 Cuentas Activas</option>
                            <option value="En Baja">🟡 En Proceso de Baja</option>
                            <option value="Eliminado">🔴 Eliminadas / Canceladas</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <span class="text-secondary small" id="contadorCorreos">0 cuentas</span>
                    </div>
                </div>

                <!-- Tabla de Correos -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaCorreos">
                        <thead>
                            <tr>
                                <th>Colaborador</th>
                                <th>Correo Electrónico</th>
                                <th>Área</th>
                                <th>Puesto</th>
                                <th>Tipo</th>
                                <th class="text-center">Estado</th>
                                <th>Fecha Alta</th>
                                <th>Fecha / Motivo de Baja</th>
                                <th>Contraseña</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyCorreos">
                            <tr><td colspan="10" class="text-center py-4 text-secondary">Cargando cuentas de correo...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SUBSECCIÓN 1.4: LICENCIAS DE OFFICE                                -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="pill-licencias" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-key-fill text-primary me-2"></i>Matriz de Licenciamiento Microsoft Office</h5>
                        <p class="text-secondary small mb-0">Control de códigos y Product Keys de Microsoft Office / 365, cuentas vinculadas, equipos asignados y vigencia.</p>
                    </div>
                    <?php if ($puedeCrear): ?>
                    <button class="btn btn-primary btn-sm rounded-3 fw-semibold shadow" onclick="abrirModalNuevaLicencia()">
                        <i class="bi bi-plus-circle me-1"></i> Registrar Licencia
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Filtros Licencias -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-12 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" id="busqLic" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por código, producto o usuario..." onkeyup="cargarLicencias()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroAreaLic" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarLicencias()">
                            <option value="">Todas las Áreas</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroEstadoLic" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarLicencias()">
                            <option value="">Todos los Estados</option>
                            <option value="Activa">🟢 Activa (Asignada)</option>
                            <option value="Disponible">🔵 Disponible (Sin asignar)</option>
                            <option value="Vencida">🔴 Vencida</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <span class="text-secondary small" id="contadorLicencias">0 licencias</span>
                    </div>
                </div>

                <!-- Tabla de Licencias -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaLicencias">
                        <thead>
                            <tr>
                                <th>Código / Clave de Licencia</th>
                                <th>Producto</th>
                                <th>Tipo</th>
                                <th>Cuenta Microsoft</th>
                                <th>Equipo Asignado</th>
                                <th>Usuario Asignado</th>
                                <th>Área</th>
                                <th class="text-center">Estado</th>
                                <th>Fecha Compra</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyLicencias">
                            <tr><td colspan="10" class="text-center py-4 text-secondary">Cargando licencias de Office...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SUBSECCIÓN 2.1: SEGURIDAD: USUARIOS GDS                             -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="pill-gds-usr" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-person-badge-fill text-warning me-2"></i>Asignación de Usuarios y Credenciales DMS / GDS</h5>
                        <p class="text-secondary small mb-0">Control de usuarios y claves del sistema de agencia. Emite la responsiva formal de confidencialidad y adjunta el PDF firmado.</p>
                    </div>
                    <?php if ($puedeCrear): ?>
                    <button class="btn btn-warning btn-sm rounded-3 fw-semibold shadow" onclick="abrirModalNuevoGdsUsuario()">
                        <i class="bi bi-plus-circle me-1"></i> Asignar Usuario GDS
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Filtros GDS Usuarios -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-12 col-md-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" id="busqGdsUsr" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por colaborador, usuario GDS o perfil..." onkeyup="cargarGdsUsuarios()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroAreaGdsUsr" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarGdsUsuarios()">
                            <option value="">Todas las Áreas</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select id="filtroEstadoGdsUsr" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarGdsUsuarios()">
                            <option value="">Todos</option>
                            <option value="Activo">🟢 Activo</option>
                            <option value="Suspendido">🟡 Suspendido</option>
                            <option value="Baja">🔴 Baja</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <span class="text-secondary small" id="contadorGdsUsr">0 usuarios</span>
                    </div>
                </div>

                <!-- Tabla de Usuarios GDS -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaGdsUsuarios">
                        <thead>
                            <tr>
                                <th>Colaborador</th>
                                <th>Área</th>
                                <th>Puesto</th>
                                <th>Usuario GDS</th>
                                <th>Contraseña GDS</th>
                                <th>Perfil / Rol GDS</th>
                                <th>Sucursal</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Responsiva Firmada</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyGdsUsuarios">
                            <tr><td colspan="10" class="text-center py-4 text-secondary">Cargando usuarios de GDS...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SUBSECCIÓN 2.2: SEGURIDAD: CONTRASEÑAS DE MOVIMIENTOS GDS           -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="pill-gds-pass" role="tabpanel">
            <div class="card-executive p-4 border-danger border-opacity-25">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-shield-lock-fill text-danger me-2"></i>Custodia de Contraseñas de Movimientos Críticos (GDS)</h5>
                        <p class="text-secondary small mb-0">Registro y custodia de claves para operaciones de alta sensibilidad (cancelaciones, descuentos, traspasos). Emite responsiva y sube evidencia.</p>
                    </div>
                    <?php if ($puedeCrear): ?>
                    <button class="btn btn-danger btn-sm rounded-3 fw-semibold shadow" onclick="abrirModalNuevoGdsPassword()">
                        <i class="bi bi-key-fill me-1"></i> Configurar Password de Movimiento
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Filtros Password Movimientos -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-12 col-md-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" id="busqGdsPass" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por movimiento o responsable..." onkeyup="cargarGdsPasswords()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroAreaGdsPass" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarGdsPasswords()">
                            <option value="">Todas las Áreas</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select id="filtroNivelGdsPass" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarGdsPasswords()">
                            <option value="">Todos los Niveles</option>
                            <option value="Crítico">🔴 Crítico</option>
                            <option value="Alto">🟠 Alto</option>
                            <option value="Medio">🟡 Medio</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <span class="text-secondary small" id="contadorGdsPass">0 claves</span>
                    </div>
                </div>

                <!-- Tabla de Passwords de Movimientos -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaGdsPasswords">
                        <thead>
                            <tr>
                                <th>Tipo de Movimiento</th>
                                <th>Descripción de la Operación</th>
                                <th>Contraseña Asignada</th>
                                <th>Usuario / Gerente Autorizado</th>
                                <th>Puesto / Área</th>
                                <th class="text-center">Nivel</th>
                                <th>Última Actualización</th>
                                <th class="text-center">Responsiva Firmada</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyGdsPasswords">
                            <tr><td colspan="9" class="text-center py-4 text-secondary">Cargando contraseñas de movimientos...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SUBSECCIÓN 2.3: SEGURIDAD: CONTROL DE USUARIOS GRP                  -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="pill-grp-usr" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-people-fill text-info me-2"></i>Control de Usuarios y Roles de GRP</h5>
                        <p class="text-secondary small mb-0">Asignación de cuentas de usuario, contraseñas, roles específicos y módulos autorizados dentro del sistema GRP.</p>
                    </div>
                    <?php if ($puedeCrear): ?>
                    <button class="btn btn-info btn-sm rounded-3 fw-semibold shadow text-dark" onclick="abrirModalNuevoGrpUsuario()">
                        <i class="bi bi-plus-circle-fill me-1"></i> Asignar Usuario GRP
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Filtros GRP -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col-12 col-md-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-search"></i></span>
                            <input type="text" id="busqGrp" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por colaborador, usuario GRP o roles..." onkeyup="cargarGrpUsuarios()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroAreaGrp" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarGrpUsuarios()">
                            <option value="">Todas las Áreas</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select id="filtroEstadoGrp" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="cargarGrpUsuarios()">
                            <option value="">Todos</option>
                            <option value="Activo">🟢 Activo</option>
                            <option value="Inactivo">🔴 Inactivo</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <span class="text-secondary small" id="contadorGrp">0 cuentas GRP</span>
                    </div>
                </div>

                <!-- Tabla de Usuarios GRP -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaGrpUsuarios">
                        <thead>
                            <tr>
                                <th>Colaborador</th>
                                <th>Área</th>
                                <th>Puesto</th>
                                <th>Usuario GRP</th>
                                <th>Contraseña GRP</th>
                                <th>Roles Asignados en GRP</th>
                                <th>Módulos Permitidos</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Responsiva Firmada</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyGrpUsuarios">
                            <tr><td colspan="10" class="text-center py-4 text-secondary">Cargando usuarios de GRP...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ======================================================================= -->
<!-- MODALES DEL SISTEMA                                                     -->
<!-- ======================================================================= -->

<!-- 1. MODAL: MANTENIMIENTO (PROGRAMAR / EDITAR) -->
<div class="modal fade" id="modalMantenimiento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="modalMantenimientoTitulo"><i class="bi bi-tools text-warning me-2"></i>Programar Mantenimiento</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formMantenimiento" onsubmit="guardarMantenimiento(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="mantId" value="0">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Nombre del Equipo *</label>
                        <input type="text" name="nombre_equipo" id="mantEquipo" class="form-control bg-dark text-white border-secondary" placeholder="Ej. PC-ADM-01, LAP-VTA-05" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Área / Depto</label>
                            <select name="area" id="mantArea" class="form-select bg-dark text-white border-secondary">
                                <?php foreach ($areasDisponibles as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Tipo</label>
                            <select name="tipo_mantenimiento" id="mantTipo" class="form-select bg-dark text-white border-secondary">
                                <option value="Preventivo">Preventivo</option>
                                <option value="Correctivo">Correctivo</option>
                                <option value="Limpieza Física">Limpieza Física</option>
                                <option value="Optimización SO">Optimización SO / Antivirus</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Usuario Responsable</label>
                        <input type="text" name="usuario_responsable" id="mantUsuario" class="form-control bg-dark text-white border-secondary" placeholder="Nombre del colaborador">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Fecha Programada *</label>
                            <input type="date" name="fecha_programada" id="mantFechaProg" class="form-control bg-dark text-white border-secondary" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Frecuencia</label>
                            <select name="frecuencia" id="mantFrecuencia" class="form-select bg-dark text-white border-secondary">
                                <option value="Mensual">Mensual</option>
                                <option value="Trimestral">Trimestral</option>
                                <option value="Semestral" selected>Semestral</option>
                                <option value="Anual">Anual</option>
                                <option value="Única">Única</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Estado</label>
                            <select name="estado" id="mantEstado" class="form-select bg-dark text-white border-secondary">
                                <option value="Programado">Programado</option>
                                <option value="En Proceso">En Proceso</option>
                                <option value="Realizado">Realizado</option>
                                <option value="Atrasado">Atrasado</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Técnico Asignado</label>
                            <input type="text" name="tecnico_responsable" id="mantTecnico" class="form-control bg-dark text-white border-secondary" value="Sistemas">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Observaciones / Tareas a Realizar</label>
                        <textarea name="observaciones" id="mantObs" rows="2" class="form-control bg-dark text-white border-secondary" placeholder="Detalles de la revisión..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning btn-sm fw-semibold">Guardar Mantenimiento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2. MODAL: SUBIR EVIDENCIA DE MANTENIMIENTO (PDF) -->
<div class="modal fade" id="modalSubirEvidencia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>Adjuntar Evidencia de Mantenimiento</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formSubirEvidencia" onsubmit="enviarEvidenciaMantenimiento(event)">
                <div class="modal-body">
                    <input type="hidden" name="mantenimiento_id" id="evidenciaMantId">
                    <p class="text-secondary small mb-3" id="evidenciaEquipoLabel">Equipo: ...</p>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Seleccionar Reporte / Checklist en PDF *</label>
                        <input type="file" name="archivo_pdf" id="archivoEvidenciaPdf" class="form-control bg-dark text-white border-secondary" accept="application/pdf" required>
                        <small class="text-secondary" style="font-size: 0.72rem;">Sube el PDF con firmas del usuario y reporte técnico de servicio.</small>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="marcar_realizado" id="checkMarcarRealizado" checked value="1">
                        <label class="form-check-label small" for="checkMarcarRealizado">Marcar automáticamente como <strong>Realizado</strong> con fecha de hoy</label>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm fw-semibold" id="btnSubirEvid"><i class="bi bi-cloud-arrow-up-fill me-1"></i> Subir Evidencia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 3. MODAL: CORREO (CREAR / EDITAR) -->
<div class="modal fade" id="modalCorreo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="modalCorreoTitulo"><i class="bi bi-envelope-fill text-success me-2"></i>Cuenta de Correo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formCorreo" onsubmit="guardarCorreo(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="corrId" value="0">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Nombre del Colaborador *</label>
                        <input type="text" name="nombre_colaborador" id="corrNombre" class="form-control bg-dark text-white border-secondary" placeholder="Nombre y Apellidos" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Correo Electrónico *</label>
                        <input type="email" name="correo" id="corrEmail" class="form-control bg-dark text-white border-secondary" placeholder="ejemplo@agencia.com" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Área / Depto</label>
                            <select name="area" id="corrArea" class="form-select bg-dark text-white border-secondary">
                                <?php foreach ($areasDisponibles as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Puesto</label>
                            <input type="text" name="puesto" id="corrPuesto" class="form-control bg-dark text-white border-secondary" placeholder="Ej. Asesor de Ventas">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Tipo de Cuenta</label>
                            <select name="tipo_cuenta" id="corrTipo" class="form-select bg-dark text-white border-secondary">
                                <option value="Corporativo">Corporativo (Dominio)</option>
                                <option value="Planta VW/SEAT">Planta (VW/SEAT)</option>
                                <option value="Servicio">Servicio / Taller</option>
                                <option value="Genérico">Genérico / Área</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Estado</label>
                            <select name="estado" id="corrEstado" class="form-select bg-dark text-white border-secondary">
                                <option value="Activo">Activo</option>
                                <option value="En Baja">En Baja</option>
                                <option value="Eliminado">Eliminado</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Contraseña de Correo (Control Sistemas)</label>
                        <input type="text" name="contrasena_guardada" id="corrPass" class="form-control bg-dark text-white border-secondary" placeholder="Clave asignada">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Observaciones / Notas</label>
                        <textarea name="observaciones" id="corrObs" rows="2" class="form-control bg-dark text-white border-secondary" placeholder="Detalles de la cuenta..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm fw-semibold">Guardar Cuenta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 4. MODAL: DAR DE BAJA CORREO -->
<div class="modal fade" id="modalBajaCorreo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-person-x-fill me-2"></i>Registrar Baja de Correo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formBajaCorreo" onsubmit="confirmarBajaCorreo(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="bajaCorrId">
                    <p class="small text-white mb-2" id="bajaCorrEmailLabel">Correo: ...</p>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Acción a Realizar *</label>
                        <select name="estado" id="bajaCorrEstado" class="form-select bg-dark text-white border-secondary">
                            <option value="En Baja">Marcar como "En Baja" (Temporal / Resguardo)</option>
                            <option value="Eliminado">Marcar como "Eliminado" (Definitivo / Cancelado)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Motivo de Baja / Cancelación *</label>
                        <textarea name="motivo_baja" id="bajaCorrMotivo" rows="3" class="form-control bg-dark text-white border-secondary" placeholder="Ej. Renuncia de colaborador, cambio de puesto, reasignación..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Aplicar Baja</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 5. MODAL: LICENCIA DE OFFICE (CREAR / EDITAR) -->
<div class="modal fade" id="modalLicencia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="modalLicenciaTitulo"><i class="bi bi-key-fill text-primary me-2"></i>Licencia de Office</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formLicencia" onsubmit="guardarLicencia(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="licId" value="0">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Código / Product Key *</label>
                        <input type="text" name="codigo_licencia" id="licCodigo" class="form-control bg-dark text-white border-secondary font-monospace" placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Producto / Versión *</label>
                        <select name="nombre_producto" id="licProducto" class="form-select bg-dark text-white border-secondary">
                            <option value="Microsoft 365 Business Standard">Microsoft 365 Business Standard</option>
                            <option value="Microsoft 365 Business Basic">Microsoft 365 Business Basic</option>
                            <option value="Office Hogar y Empresas 2021">Office Hogar y Empresas 2021</option>
                            <option value="Office Hogar y Empresas 2019">Office Hogar y Empresas 2019</option>
                            <option value="Office Professional Plus 2019">Office Professional Plus 2019</option>
                            <option value="Microsoft 365 Apps for Enterprise">Microsoft 365 Apps for Enterprise</option>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Tipo de Licencia</label>
                            <select name="tipo_licencia" id="licTipo" class="form-select bg-dark text-white border-secondary">
                                <option value="Digital / ESD">Digital / ESD</option>
                                <option value="Suscripción Anual">Suscripción Anual</option>
                                <option value="OEM">OEM (De fábrica)</option>
                                <option value="FPP">FPP (Caja física)</option>
                                <option value="Volumen">Volumen / MAK</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Estado</label>
                            <select name="estado" id="licEstado" class="form-select bg-dark text-white border-secondary">
                                <option value="Activa">Activa (Asignada)</option>
                                <option value="Disponible">Disponible (Sin asignar)</option>
                                <option value="Vencida">Vencida</option>
                                <option value="Reasignada">Reasignada</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Cuenta Microsoft Asociada</label>
                        <input type="email" name="cuenta_microsoft" id="licCuentaMs" class="form-control bg-dark text-white border-secondary" placeholder="correo.activacion@agencia.com">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Equipo Asignado</label>
                            <input type="text" name="equipo_asignado" id="licEquipo" class="form-control bg-dark text-white border-secondary" placeholder="Ej. PC-VTA-02">
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Usuario Asignado</label>
                            <input type="text" name="usuario_asignado" id="licUsuario" class="form-control bg-dark text-white border-secondary" placeholder="Nombre colaborador">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Área / Depto</label>
                        <select name="area" id="licArea" class="form-select bg-dark text-white border-secondary">
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-semibold">Guardar Licencia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 6. MODAL: USUARIO GDS (CREAR / EDITAR) -->
<div class="modal fade" id="modalGdsUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="modalGdsUsrTitulo"><i class="bi bi-person-badge-fill text-warning me-2"></i>Usuario DMS / GDS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formGdsUsuario" onsubmit="guardarGdsUsuario(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="gdsUsrId" value="0">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Colaborador / Empleado *</label>
                        <input type="text" name="colaborador" id="gdsUsrColaborador" class="form-control bg-dark text-white border-secondary" placeholder="Nombre completo" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Usuario GDS (Login) *</label>
                            <input type="text" name="usuario_gds" id="gdsUsrLogin" class="form-control bg-dark text-white border-secondary font-monospace" placeholder="ej. rmartinez" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Contraseña GDS *</label>
                            <input type="text" name="password_gds" id="gdsUsrPass" class="form-control bg-dark text-white border-secondary font-monospace" placeholder="Clave temporal" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Área / Depto</label>
                            <select name="area" id="gdsUsrArea" class="form-select bg-dark text-white border-secondary">
                                <?php foreach ($areasDisponibles as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Puesto</label>
                            <input type="text" name="puesto" id="gdsUsrPuesto" class="form-control bg-dark text-white border-secondary" placeholder="Puesto">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Perfil / Rol GDS</label>
                            <input type="text" name="perfil_gds" id="gdsUsrPerfil" class="form-control bg-dark text-white border-secondary" placeholder="Ej. Ventas, Caja, Asesor">
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Sucursal</label>
                            <input type="text" name="sucursal" id="gdsUsrSucursal" class="form-control bg-dark text-white border-secondary" value="Matriz">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Estado</label>
                            <select name="estado" id="gdsUsrEstado" class="form-select bg-dark text-white border-secondary">
                                <option value="Activo">Activo</option>
                                <option value="Suspendido">Suspendido</option>
                                <option value="Baja">Baja</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Fecha Asignación</label>
                            <input type="date" name="fecha_asignacion" id="gdsUsrFecha" class="form-control bg-dark text-white border-secondary" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning btn-sm fw-semibold">Guardar Usuario GDS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 7. MODAL: PASSWORD DE MOVIMIENTO GDS (CREAR / EDITAR) -->
<div class="modal fade" id="modalGdsPassword" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-danger" id="modalGdsPassTitulo"><i class="bi bi-shield-lock-fill me-2"></i>Contraseña de Movimiento GDS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formGdsPassword" onsubmit="guardarGdsPassword(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="gdsPassId" value="0">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Tipo de Movimiento / Operación Crítica *</label>
                        <input type="text" name="tipo_movimiento" id="gdsPassTipo" class="form-control bg-dark text-white border-secondary" placeholder="Ej. Cancelación de Facturas, Descuentos Especiales" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Descripción y Alcance de la Autorización</label>
                        <textarea name="descripcion_movimiento" id="gdsPassDesc" rows="2" class="form-control bg-dark text-white border-secondary" placeholder="Detalles de la autorización requerida..."></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Contraseña de Movimiento *</label>
                            <input type="text" name="password_movimiento" id="gdsPassVal" class="form-control bg-dark text-white border-secondary font-monospace" placeholder="Clave de autorización" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Nivel de Seguridad</label>
                            <select name="nivel_seguridad" id="gdsPassNivel" class="form-select bg-dark text-white border-secondary">
                                <option value="Crítico">Crítico (Gerencial)</option>
                                <option value="Alto" selected>Alto</option>
                                <option value="Medio">Medio</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Usuario / Gerente Autorizado *</label>
                            <input type="text" name="usuario_autorizado" id="gdsPassUsuario" class="form-control bg-dark text-white border-secondary" placeholder="Nombre completo" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Puesto / Cargo</label>
                            <input type="text" name="puesto_autorizado" id="gdsPassPuesto" class="form-control bg-dark text-white border-secondary" placeholder="Ej. Gerente Administrativo">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Área / Depto</label>
                        <select name="area" id="gdsPassArea" class="form-select bg-dark text-white border-secondary">
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Guardar Clave</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 8. MODAL: USUARIO GRP (CREAR / EDITAR) -->
<div class="modal fade" id="modalGrpUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-info" id="modalGrpUsrTitulo"><i class="bi bi-people-fill me-2"></i>Usuario de GRP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formGrpUsuario" onsubmit="guardarGrpUsuario(event)">
                <div class="modal-body">
                    <input type="hidden" name="id" id="grpUsrId" value="0">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Colaborador / Empleado *</label>
                        <input type="text" name="colaborador" id="grpUsrColaborador" class="form-control bg-dark text-white border-secondary" placeholder="Nombre completo" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Usuario GRP *</label>
                            <input type="text" name="usuario_grp" id="grpUsrLogin" class="form-control bg-dark text-white border-secondary font-monospace" placeholder="Login GRP" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Contraseña GRP *</label>
                            <input type="text" name="password_grp" id="grpUsrPass" class="form-control bg-dark text-white border-secondary font-monospace" placeholder="Password" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary">Área</label>
                            <select name="area" id="grpUsrArea" class="form-select bg-dark text-white border-secondary">
                                <?php foreach ($areasDisponibles as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary">Puesto</label>
                            <input type="text" name="puesto" id="grpUsrPuesto" class="form-control bg-dark text-white border-secondary" placeholder="Puesto">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Roles Asignados en GRP * (separados por coma)</label>
                        <input type="text" name="roles_grp" id="grpUsrRoles" class="form-control bg-dark text-white border-secondary" placeholder="Ej. Compras, Contabilidad, Tesorería, Facturación" required>
                        <small class="text-secondary" style="font-size: 0.72rem;">Indica los perfiles o roles asignados dentro del ERP.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Módulos Permitidos</label>
                        <input type="text" name="modulos_permitidos" id="grpUsrModulos" class="form-control bg-dark text-white border-secondary" placeholder="Ej. Cuentas por Cobrar, Inventarios, Pólizas">
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info btn-sm fw-semibold text-dark">Guardar Usuario GRP</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 9. MODAL: SUBIR RESPONSIVA FIRMADA (GENÉRICO PARA EQUIPOS, GDS O GRP) -->
<div class="modal fade" id="modalSubirResponsivaDoc" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-success"><i class="bi bi-file-earmark-arrow-up-fill me-2"></i>Subir Carta Responsiva Firmada (PDF)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formSubirResponsivaDoc" onsubmit="enviarResponsivaDocumento(event)">
                <div class="modal-body">
                    <input type="hidden" name="tipo_entidad" id="subirDocTipo"> <!-- 'equipo', 'gds_usuario', 'gds_password', 'grp_usuario' -->
                    <input type="hidden" name="entidad_id" id="subirDocId">
                    <input type="hidden" name="seccion_equipo" id="subirDocSeccionEq">
                    <p class="text-white small mb-3" id="subirDocLabelInfo">Documento: ...</p>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Seleccionar Archivo PDF Firmado *</label>
                        <input type="file" name="archivo_pdf" id="archivoRespDocPdf" class="form-control bg-dark text-white border-secondary" accept="application/pdf" required>
                        <small class="text-secondary" style="font-size: 0.72rem;">Sube el PDF escaneado con las firmas oficiales de entrega y recepción.</small>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm fw-semibold" id="btnSubirDocSubmit"><i class="bi bi-cloud-arrow-up-fill me-1"></i> Guardar PDF Firmado</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 10. MODAL: VISOR DE PDFS INTEGRADO -->
<div class="modal fade" id="modalVisorPdf" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="height: 90vh;">
        <div class="modal-content bg-dark text-white border-secondary h-100">
            <div class="modal-header border-secondary py-2">
                <h6 class="modal-title fw-bold text-white mb-0" id="visorPdfTitulo"><i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>Visor de Documento</h6>
                <div class="d-flex align-items-center gap-2">
                    <a id="btnDescargarPdfDirecto" href="#" target="_blank" class="btn btn-outline-info btn-sm py-1 px-2" title="Abrir en pestaña nueva">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Abrir completo
                    </a>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0 h-100 bg-secondary bg-opacity-10">
                <iframe id="iframeVisorPdf" src="" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- SCRIPTS DEL MÓDULO AUDITORÍA INTERNA -->
<script>
let seccionActiva = 'responsivas';
let cacheDatos = {
    responsivas: [],
    mantenimiento: [],
    correos: [],
    licencias: [],
    gds_usr: [],
    gds_pass: [],
    grp_usr: []
};

// Inicialización
document.addEventListener('DOMContentLoaded', () => {
    cargarResponsivasEquipos();
});

function cambiarSeccionActiva(sec) {
    seccionActiva = sec;
    if (sec === 'responsivas') cargarResponsivasEquipos();
    else if (sec === 'mantenimiento') cargarMantenimientos();
    else if (sec === 'correos') cargarCorreos();
    else if (sec === 'licencias') cargarLicencias();
    else if (sec === 'gds_usr') cargarGdsUsuarios();
    else if (sec === 'gds_pass') cargarGdsPasswords();
    else if (sec === 'grp_usr') cargarGrpUsuarios();
}

// =============================================================================
// 1. CARGA: RESPONSIVAS DE EQUIPOS
// =============================================================================
function cargarResponsivasEquipos() {
    const area = document.getElementById('filtroAreaResp')?.value || '';
    const estado = document.getElementById('filtroEstadoResp')?.value || '';
    const busq = document.getElementById('busqResp')?.value || '';

    const tbody = document.getElementById('tbodyResponsivasEquipos');
    if (!tbody) return;

    let url = `api_auditoria.php?action=listar_responsivas_equipos&area=${encodeURIComponent(area)}&estado_resp=${encodeURIComponent(estado)}&busqueda=${encodeURIComponent(busq)}`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cacheDatos.responsivas = res.datos || [];
            
            const contEl = document.getElementById('contadorBadgeResponsivas');
            if (contEl) contEl.innerText = `${cacheDatos.responsivas.length} equipos en inventario`;

            if (cacheDatos.responsivas.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-secondary">No se encontraron equipos bajo los filtros seleccionados.</td></tr>';
                return;
            }

            let html = '';
            cacheDatos.responsivas.forEach(e => {
                const badgeResp = e.tiene_responsiva === 1
                    ? '<span class="badge-status-pill badge-ok"><i class="bi bi-check-circle-fill"></i> Firmada (PDF)</span>'
                    : '<span class="badge-status-pill badge-err"><i class="bi bi-clock-history"></i> Pendiente</span>';

                let btnVerResp = '';
                if (e.tiene_responsiva === 1) {
                    btnVerResp = `
                        <button type="button" class="btn btn-outline-info btn-sm px-2 py-0 me-1" onclick="verPdfModal('${e.responsiva_url}', 'Responsiva - ${e.nombre_equipo}')" title="Visualizar responsiva firmada">
                            <i class="bi bi-eye-fill"></i>
                        </button>
                    `;
                }

                html += `
                    <tr>
                        <td><strong class="text-white">${e.nombre_equipo}</strong></td>
                        <td>${e.usuario}</td>
                        <td><span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">${e.area}</span></td>
                        <td><small class="text-secondary">${e.puesto}</small></td>
                        <td><span class="badge bg-dark text-secondary">${e.tipo}</span> ${e.marca} ${e.modelo}</td>
                        <td><code class="text-secondary">${e.serie}</code></td>
                        <td class="text-center">${badgeResp}</td>
                        <td class="text-end text-nowrap">
                            ${btnVerResp}
                            <a href="generar_responsiva.php?id=${e.id}&sec=${e.seccion}" target="_blank" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" title="Generar formato de Responsiva para imprimir y firmar">
                                <i class="bi bi-printer-fill me-1"></i> Imprimir
                            </a>
                            <button type="button" class="btn btn-outline-success btn-sm px-2 py-0" onclick="abrirModalSubirResponsivaEquipo(${e.id}, '${e.seccion}', '${e.nombre_equipo} (${e.usuario})')" title="Subir PDF de la responsiva firmada">
                                <i class="bi bi-upload"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">Error: ${err.message}</td></tr>`;
        });
}

function abrirModalSubirResponsivaEquipo(id, seccion, label) {
    document.getElementById('subirDocTipo').value = 'equipo';
    document.getElementById('subirDocId').value = id;
    document.getElementById('subirDocSeccionEq').value = seccion;
    document.getElementById('subirDocLabelInfo').innerHTML = `Equipo: <strong>${label}</strong>`;
    document.getElementById('archivoRespDocPdf').value = '';
    const modal = new bootstrap.Modal(document.getElementById('modalSubirResponsivaDoc'));
    modal.show();
}

// =============================================================================
// 2. CARGA: PLANES DE MANTENIMIENTO
// =============================================================================
function cargarMantenimientos() {
    const area = document.getElementById('filtroAreaMant')?.value || '';
    const estado = document.getElementById('filtroEstadoMant')?.value || '';
    const busq = document.getElementById('busqMant')?.value || '';

    const tbody = document.getElementById('tbodyMantenimientos');
    if (!tbody) return;

    let url = `api_auditoria.php?action=listar_mantenimientos&area=${encodeURIComponent(area)}&estado=${encodeURIComponent(estado)}&busqueda=${encodeURIComponent(busq)}`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cacheDatos.mantenimiento = res.datos || [];

            const contEl = document.getElementById('contadorMantenimientos');
            if (contEl) contEl.innerText = `${cacheDatos.mantenimiento.length} registros`;

            if (cacheDatos.mantenimiento.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 text-secondary">No hay planes de mantenimiento registrados con estos filtros.</td></tr>';
                return;
            }

            let html = '';
            cacheDatos.mantenimiento.forEach(m => {
                let badgeClass = 'badge-warn';
                if (m.estado === 'Realizado') badgeClass = 'badge-ok';
                else if (m.estado === 'Atrasado') badgeClass = 'badge-err';
                else if (m.estado === 'En Proceso') badgeClass = 'badge-info';

                let evidenciaHtml = '<span class="badge-status-pill badge-err"><i class="bi bi-clock"></i> Pendiente</span>';
                if (m.evidencia_pdf_url) {
                    evidenciaHtml = `
                        <button type="button" class="btn btn-outline-success btn-sm px-2 py-0" onclick="verPdfModal('${m.evidencia_pdf_url}', 'Evidencia Mantenimiento - ${m.nombre_equipo}')" title="Ver reporte de mantenimiento en PDF">
                            <i class="bi bi-file-earmark-check-fill me-1"></i> Ver PDF
                        </button>
                    `;
                }

                html += `
                    <tr>
                        <td><strong class="text-white">${m.nombre_equipo}</strong></td>
                        <td><span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">${m.area || 'General'}</span></td>
                        <td>${m.usuario_responsable || 'Sin asignar'}</td>
                        <td><span class="badge bg-dark text-info">${m.tipo_mantenimiento}</span></td>
                        <td>${m.fecha_programada}</td>
                        <td>${m.fecha_realizada || '-'}</td>
                        <td>${m.frecuencia}</td>
                        <td class="text-center"><span class="badge-status-pill ${badgeClass}">${m.estado}</span></td>
                        <td><small class="text-secondary">${m.tecnico_responsable || 'Sistemas'}</small></td>
                        <td class="text-center">${evidenciaHtml}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-outline-info btn-sm px-2 py-0 me-1" onclick="abrirModalSubirEvidenciaMant(${m.id}, '${m.nombre_equipo}')" title="Adjuntar reporte de servicio / evidencia PDF">
                                <i class="bi bi-upload"></i>
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" onclick='abrirModalEditarMantenimiento(${JSON.stringify(m)})' title="Editar plan">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm px-2 py-0" onclick="eliminarMantenimiento(${m.id})" title="Eliminar registro">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="11" class="text-center py-4 text-danger">Error: ${err.message}</td></tr>`;
        });
}

function abrirModalNuevoMantenimiento() {
    document.getElementById('formMantenimiento').reset();
    document.getElementById('mantId').value = 0;
    document.getElementById('mantFechaProg').value = new Date().toISOString().slice(0, 10);
    document.getElementById('modalMantenimientoTitulo').innerHTML = '<i class="bi bi-tools text-warning me-2"></i>Programar Mantenimiento';
    const modal = new bootstrap.Modal(document.getElementById('modalMantenimiento'));
    modal.show();
}

function abrirModalEditarMantenimiento(m) {
    document.getElementById('mantId').value = m.id;
    document.getElementById('mantEquipo').value = m.nombre_equipo;
    document.getElementById('mantArea').value = m.area || 'General';
    document.getElementById('mantUsuario').value = m.usuario_responsable || '';
    document.getElementById('mantTipo').value = m.tipo_mantenimiento || 'Preventivo';
    document.getElementById('mantFechaProg').value = m.fecha_programada;
    document.getElementById('mantFrecuencia').value = m.frecuencia || 'Semestral';
    document.getElementById('mantEstado').value = m.estado || 'Programado';
    document.getElementById('mantTecnico').value = m.tecnico_responsable || 'Sistemas';
    document.getElementById('mantObs').value = m.observaciones || '';
    document.getElementById('modalMantenimientoTitulo').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i>Editar Plan de Mantenimiento';
    const modal = new bootstrap.Modal(document.getElementById('modalMantenimiento'));
    modal.show();
}

function guardarMantenimiento(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('formMantenimiento'));
    formData.append('action', 'guardar_mantenimiento');

    fetch('api_auditoria.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalMantenimiento')).hide();
            Swal.fire({ title: 'Guardado', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarMantenimientos();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function abrirModalSubirEvidenciaMant(id, equipo) {
    document.getElementById('evidenciaMantId').value = id;
    document.getElementById('evidenciaEquipoLabel').innerHTML = `Equipo: <strong>${equipo}</strong>`;
    document.getElementById('archivoEvidenciaPdf').value = '';
    const modal = new bootstrap.Modal(document.getElementById('modalSubirEvidencia'));
    modal.show();
}

function enviarEvidenciaMantenimiento(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubirEvid');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Subiendo...';

    const formData = new FormData(document.getElementById('formSubirEvidencia'));
    formData.append('action', 'subir_evidencia_mantenimiento');

    fetch('api_auditoria.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-arrow-up-fill me-1"></i> Subir Evidencia';
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalSubirEvidencia')).hide();
            Swal.fire({ title: 'Evidencia Guardada', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarMantenimientos();
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-arrow-up-fill me-1"></i> Subir Evidencia';
            Swal.fire('Error', err.message, 'error');
        });
}

function eliminarMantenimiento(id) {
    Swal.fire({
        title: '¿Eliminar mantenimiento?',
        text: 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'eliminar_mantenimiento');
            fd.append('id', id);
            fetch('api_auditoria.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) throw new Error(r.error);
                    cargarMantenimientos();
                });
        }
    });
}

// =============================================================================
// 3. CARGA: CONTROL DE CORREOS
// =============================================================================
function cargarCorreos() {
    const area = document.getElementById('filtroAreaCorr')?.value || '';
    const estado = document.getElementById('filtroEstadoCorr')?.value || '';
    const busq = document.getElementById('busqCorr')?.value || '';

    const tbody = document.getElementById('tbodyCorreos');
    if (!tbody) return;

    let url = `api_auditoria.php?action=listar_correos&area=${encodeURIComponent(area)}&estado=${encodeURIComponent(estado)}&busqueda=${encodeURIComponent(busq)}`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cacheDatos.correos = res.datos || [];

            const contEl = document.getElementById('contadorCorreos');
            if (contEl) contEl.innerText = `${cacheDatos.correos.length} cuentas`;

            if (cacheDatos.correos.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-secondary">No se encontraron cuentas de correo.</td></tr>';
                return;
            }

            let html = '';
            cacheDatos.correos.forEach(c => {
                let badgeClass = 'badge-ok';
                if (c.estado === 'En Baja') badgeClass = 'badge-warn';
                else if (c.estado === 'Eliminado') badgeClass = 'badge-err';

                const passId = `corrPass_${c.id}`;
                const passVal = c.contrasena_guardada || '';
                const passHtml = passVal ? `
                    <div class="d-flex align-items-center gap-1">
                        <span id="${passId}" class="pass-hidden" data-pass="${escapeHtml(passVal)}">••••••••</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="togglePassView('${passId}')" title="Ver contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="copiarAlPortapapeles('${escapeHtml(passVal)}')" title="Copiar contraseña">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                ` : '<span class="text-secondary small">N/A</span>';

                let infoBaja = '-';
                if (c.estado !== 'Activo') {
                    infoBaja = `<span class="text-warning">${c.fecha_baja || 'Fecha no registrada'}</span><br><small class="text-secondary">${c.motivo_baja || 'Sin motivo especificado'}</small>`;
                }

                html += `
                    <tr>
                        <td><strong class="text-white">${c.nombre_colaborador}</strong></td>
                        <td><code class="text-info">${c.correo}</code></td>
                        <td><span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">${c.area || 'General'}</span></td>
                        <td>${c.puesto || 'N/A'}</td>
                        <td><span class="badge bg-dark text-secondary">${c.tipo_cuenta}</span></td>
                        <td class="text-center"><span class="badge-status-pill ${badgeClass}">${c.estado}</span></td>
                        <td>${c.fecha_alta || '-'}</td>
                        <td>${infoBaja}</td>
                        <td>${passHtml}</td>
                        <td class="text-end text-nowrap">
                            ${c.estado === 'Activo' ? `
                                <button type="button" class="btn btn-outline-danger btn-sm px-2 py-0 me-1" onclick="abrirModalBajaCorreo(${c.id}, '${c.correo}')" title="Poner en Baja o Cancelar">
                                    <i class="bi bi-person-x"></i> Baja
                                </button>
                            ` : `
                                <button type="button" class="btn btn-outline-success btn-sm px-2 py-0 me-1" onclick="reactivarCorreo(${c.id})" title="Reactivar cuenta">
                                    <i class="bi bi-check-circle"></i> Reactivar
                                </button>
                            `}
                            <button type="button" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" onclick='abrirModalEditarCorreo(${JSON.stringify(c)})' title="Editar cuenta">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0" onclick="eliminarCorreo(${c.id})" title="Eliminar registro">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">Error: ${err.message}</td></tr>`;
        });
}

function sincronizarCorreos() {
    Swal.fire({
        title: 'Sincronizando cuentas de correo...',
        text: 'Buscando cuentas activas en el inventario de equipos y directorio institucional...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const fd = new FormData();
    fd.append('action', 'sincronizar_correos');
    fetch('api_auditoria.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            Swal.fire({ title: '¡Sincronizado!', text: res.mensaje, icon: 'success' });
            cargarCorreos();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function abrirModalNuevoCorreo() {
    document.getElementById('formCorreo').reset();
    document.getElementById('corrId').value = 0;
    document.getElementById('modalCorreoTitulo').innerHTML = '<i class="bi bi-envelope-fill text-success me-2"></i>Nueva Cuenta de Correo';
    const modal = new bootstrap.Modal(document.getElementById('modalCorreo'));
    modal.show();
}

function abrirModalEditarCorreo(c) {
    document.getElementById('corrId').value = c.id;
    document.getElementById('corrNombre').value = c.nombre_colaborador;
    document.getElementById('corrEmail').value = c.correo;
    document.getElementById('corrArea').value = c.area || 'General';
    document.getElementById('corrPuesto').value = c.puesto || '';
    document.getElementById('corrTipo').value = c.tipo_cuenta || 'Corporativo';
    document.getElementById('corrEstado').value = c.estado || 'Activo';
    document.getElementById('corrPass').value = c.contrasena_guardada || '';
    document.getElementById('corrObs').value = c.observaciones || '';
    document.getElementById('modalCorreoTitulo').innerHTML = '<i class="bi bi-pencil-square text-success me-2"></i>Editar Cuenta de Correo';
    const modal = new bootstrap.Modal(document.getElementById('modalCorreo'));
    modal.show();
}

function guardarCorreo(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('formCorreo'));
    formData.append('action', 'guardar_correo');

    fetch('api_auditoria.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalCorreo')).hide();
            Swal.fire({ title: 'Guardado', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarCorreos();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function abrirModalBajaCorreo(id, email) {
    document.getElementById('bajaCorrId').value = id;
    document.getElementById('bajaCorrEmailLabel').innerHTML = `Cuenta a desactivar: <strong class="text-warning">${email}</strong>`;
    document.getElementById('bajaCorrMotivo').value = '';
    const modal = new bootstrap.Modal(document.getElementById('modalBajaCorreo'));
    modal.show();
}

function confirmarBajaCorreo(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('formBajaCorreo'));
    fd.append('action', 'cambiar_estado_correo');

    fetch('api_auditoria.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalBajaCorreo')).hide();
            Swal.fire({ title: 'Baja Aplicada', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarCorreos();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function reactivarCorreo(id) {
    const fd = new FormData();
    fd.append('action', 'cambiar_estado_correo');
    fd.append('id', id);
    fd.append('estado', 'Activo');

    fetch('api_auditoria.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cargarCorreos();
        });
}

function eliminarCorreo(id) {
    Swal.fire({
        title: '¿Eliminar cuenta de correo?',
        text: 'Se eliminará del registro de auditoría.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'eliminar_correo');
            fd.append('id', id);
            fetch('api_auditoria.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) throw new Error(r.error);
                    cargarCorreos();
                });
        }
    });
}

// =============================================================================
// 4. CARGA: LICENCIAS DE OFFICE
// =============================================================================
function cargarLicencias() {
    const area = document.getElementById('filtroAreaLic')?.value || '';
    const estado = document.getElementById('filtroEstadoLic')?.value || '';
    const busq = document.getElementById('busqLic')?.value || '';

    const tbody = document.getElementById('tbodyLicencias');
    if (!tbody) return;

    let url = `api_auditoria.php?action=listar_licencias&area=${encodeURIComponent(area)}&estado=${encodeURIComponent(estado)}&busqueda=${encodeURIComponent(busq)}`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cacheDatos.licencias = res.datos || [];

            const contEl = document.getElementById('contadorLicencias');
            if (contEl) contEl.innerText = `${cacheDatos.licencias.length} licencias`;

            if (cacheDatos.licencias.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-secondary">No hay licencias registradas.</td></tr>';
                return;
            }

            let html = '';
            cacheDatos.licencias.forEach(l => {
                let badgeClass = (l.estado === 'Activa') ? 'badge-ok' : ((l.estado === 'Disponible') ? 'badge-info' : 'badge-err');

                html += `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <code class="text-warning fw-bold">${l.codigo_licencia}</code>
                                <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="copiarAlPortapapeles('${escapeHtml(l.codigo_licencia)}')" title="Copiar Product Key">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </td>
                        <td><strong class="text-white">${l.nombre_producto}</strong></td>
                        <td><span class="badge bg-dark text-secondary">${l.tipo_licencia}</span></td>
                        <td><small class="text-info">${l.cuenta_microsoft || 'N/A'}</small></td>
                        <td>${l.equipo_asignado || '<span class="text-secondary">Sin asignar</span>'}</td>
                        <td>${l.usuario_asignado || '<span class="text-secondary">Sin asignar</span>'}</td>
                        <td><span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">${l.area || 'General'}</span></td>
                        <td class="text-center"><span class="badge-status-pill ${badgeClass}">${l.estado}</span></td>
                        <td>${l.fecha_compra || '-'}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" onclick='abrirModalEditarLicencia(${JSON.stringify(l)})' title="Editar licencia">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm px-2 py-0" onclick="eliminarLicencia(${l.id})" title="Eliminar registro">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">Error: ${err.message}</td></tr>`;
        });
}

function abrirModalNuevaLicencia() {
    document.getElementById('formLicencia').reset();
    document.getElementById('licId').value = 0;
    document.getElementById('modalLicenciaTitulo').innerHTML = '<i class="bi bi-key-fill text-primary me-2"></i>Registrar Licencia de Office';
    const modal = new bootstrap.Modal(document.getElementById('modalLicencia'));
    modal.show();
}

function abrirModalEditarLicencia(l) {
    document.getElementById('licId').value = l.id;
    document.getElementById('licCodigo').value = l.codigo_licencia;
    document.getElementById('licProducto').value = l.nombre_producto;
    document.getElementById('licTipo').value = l.tipo_licencia || 'Digital / ESD';
    document.getElementById('licCuentaMs').value = l.cuenta_microsoft || '';
    document.getElementById('licEquipo').value = l.equipo_asignado || '';
    document.getElementById('licUsuario').value = l.usuario_asignado || '';
    document.getElementById('licArea').value = l.area || 'General';
    document.getElementById('licEstado').value = l.estado || 'Activa';
    document.getElementById('modalLicenciaTitulo').innerHTML = '<i class="bi bi-pencil-square text-primary me-2"></i>Editar Licencia de Office';
    const modal = new bootstrap.Modal(document.getElementById('modalLicencia'));
    modal.show();
}

function guardarLicencia(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('formLicencia'));
    formData.append('action', 'guardar_licencia');

    fetch('api_auditoria.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalLicencia')).hide();
            Swal.fire({ title: 'Guardado', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarLicencias();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function eliminarLicencia(id) {
    Swal.fire({
        title: '¿Eliminar licencia?',
        text: 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'eliminar_licencia');
            fd.append('id', id);
            fetch('api_auditoria.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) throw new Error(r.error);
                    cargarLicencias();
                });
        }
    });
}

// =============================================================================
// 5. CARGA: USUARIOS GDS
// =============================================================================
function cargarGdsUsuarios() {
    const area = document.getElementById('filtroAreaGdsUsr')?.value || '';
    const estado = document.getElementById('filtroEstadoGdsUsr')?.value || '';
    const busq = document.getElementById('busqGdsUsr')?.value || '';

    const tbody = document.getElementById('tbodyGdsUsuarios');
    if (!tbody) return;

    let url = `api_auditoria.php?action=listar_gds_usuarios&area=${encodeURIComponent(area)}&estado=${encodeURIComponent(estado)}&busqueda=${encodeURIComponent(busq)}`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cacheDatos.gds_usr = res.datos || [];

            const contEl = document.getElementById('contadorGdsUsr');
            if (contEl) contEl.innerText = `${cacheDatos.gds_usr.length} usuarios`;

            if (cacheDatos.gds_usr.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-secondary">No hay usuarios de GDS registrados.</td></tr>';
                return;
            }

            let html = '';
            cacheDatos.gds_usr.forEach(u => {
                const passId = `gdsUsrPass_${u.id}`;
                const passVal = u.password_gds || '';
                const passHtml = `
                    <div class="d-flex align-items-center gap-1">
                        <span id="${passId}" class="pass-hidden" data-pass="${escapeHtml(passVal)}">••••••••</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="togglePassView('${passId}')" title="Ver contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="copiarAlPortapapeles('${escapeHtml(passVal)}')" title="Copiar contraseña">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                `;

                const respHtml = u.responsiva_firmada_url ? `
                    <button type="button" class="btn btn-outline-success btn-sm px-2 py-0" onclick="verPdfModal('${u.responsiva_firmada_url}', 'Responsiva GDS - ${u.colaborador}')" title="Ver responsiva firmada en PDF">
                        <i class="bi bi-file-earmark-check-fill me-1"></i> Firmada (PDF)
                    </button>
                ` : '<span class="badge-status-pill badge-err"><i class="bi bi-clock"></i> Pendiente</span>';

                html += `
                    <tr>
                        <td><strong class="text-white">${u.colaborador}</strong></td>
                        <td><span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">${u.area || 'General'}</span></td>
                        <td><small class="text-secondary">${u.puesto || 'N/A'}</small></td>
                        <td><code class="text-warning fw-bold">${u.usuario_gds}</code></td>
                        <td>${passHtml}</td>
                        <td><span class="badge bg-dark text-info">${u.perfil_gds || 'Operativo'}</span></td>
                        <td><small class="text-secondary">${u.sucursal || 'Matriz'}</small></td>
                        <td class="text-center"><span class="badge-status-pill ${u.estado === 'Activo' ? 'badge-ok' : 'badge-err'}">${u.estado}</span></td>
                        <td class="text-center">${respHtml}</td>
                        <td class="text-end text-nowrap">
                            <a href="generar_responsiva_gds.php?id=${u.id}" target="_blank" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" title="Generar Responsiva Oficial GDS para imprimir y firmar">
                                <i class="bi bi-printer-fill me-1"></i> Responsiva
                            </a>
                            <button type="button" class="btn btn-outline-success btn-sm px-2 py-0 me-1" onclick="abrirModalSubirResponsivaDoc('gds_usuario', ${u.id}, 'Usuario GDS: ${u.colaborador} (${u.usuario_gds})')" title="Subir PDF de la responsiva firmada">
                                <i class="bi bi-upload"></i>
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" onclick='abrirModalEditarGdsUsuario(${JSON.stringify(u)})' title="Editar usuario">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm px-2 py-0" onclick="eliminarGdsUsuario(${u.id})" title="Eliminar registro">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">Error: ${err.message}</td></tr>`;
        });
}

function abrirModalNuevoGdsUsuario() {
    document.getElementById('formGdsUsuario').reset();
    document.getElementById('gdsUsrId').value = 0;
    document.getElementById('modalGdsUsrTitulo').innerHTML = '<i class="bi bi-person-badge-fill text-warning me-2"></i>Asignar Usuario DMS / GDS';
    const modal = new bootstrap.Modal(document.getElementById('modalGdsUsuario'));
    modal.show();
}

function abrirModalEditarGdsUsuario(u) {
    document.getElementById('gdsUsrId').value = u.id;
    document.getElementById('gdsUsrColaborador').value = u.colaborador;
    document.getElementById('gdsUsrLogin').value = u.usuario_gds;
    document.getElementById('gdsUsrPass').value = u.password_gds;
    document.getElementById('gdsUsrArea').value = u.area || 'General';
    document.getElementById('gdsUsrPuesto').value = u.puesto || '';
    document.getElementById('gdsUsrPerfil').value = u.perfil_gds || 'Operativo';
    document.getElementById('gdsUsrSucursal').value = u.sucursal || 'Matriz';
    document.getElementById('gdsUsrEstado').value = u.estado || 'Activo';
    document.getElementById('modalGdsUsrTitulo').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i>Editar Usuario GDS';
    const modal = new bootstrap.Modal(document.getElementById('modalGdsUsuario'));
    modal.show();
}

function guardarGdsUsuario(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('formGdsUsuario'));
    formData.append('action', 'guardar_gds_usuario');

    fetch('api_auditoria.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalGdsUsuario')).hide();
            Swal.fire({ title: 'Guardado', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarGdsUsuarios();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function eliminarGdsUsuario(id) {
    Swal.fire({
        title: '¿Eliminar usuario GDS?',
        text: 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'eliminar_gds_usuario');
            fd.append('id', id);
            fetch('api_auditoria.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) throw new Error(r.error);
                    cargarGdsUsuarios();
                });
        }
    });
}

// =============================================================================
// 6. CARGA: PASSWORD DE MOVIMIENTOS GDS
// =============================================================================
function cargarGdsPasswords() {
    const area = document.getElementById('filtroAreaGdsPass')?.value || '';
    const nivel = document.getElementById('filtroNivelGdsPass')?.value || '';
    const busq = document.getElementById('busqGdsPass')?.value || '';

    const tbody = document.getElementById('tbodyGdsPasswords');
    if (!tbody) return;

    let url = `api_auditoria.php?action=listar_gds_passwords&area=${encodeURIComponent(area)}&nivel=${encodeURIComponent(nivel)}&busqueda=${encodeURIComponent(busq)}`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cacheDatos.gds_pass = res.datos || [];

            const contEl = document.getElementById('contadorGdsPass');
            if (contEl) contEl.innerText = `${cacheDatos.gds_pass.length} claves`;

            if (cacheDatos.gds_pass.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-secondary">No hay contraseñas de movimientos registradas.</td></tr>';
                return;
            }

            let html = '';
            cacheDatos.gds_pass.forEach(p => {
                const passId = `gdsPassVal_${p.id}`;
                const passVal = p.password_movimiento || '';
                const passHtml = `
                    <div class="d-flex align-items-center gap-1">
                        <span id="${passId}" class="pass-hidden" data-pass="${escapeHtml(passVal)}">••••••••</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="togglePassView('${passId}')" title="Ver contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="copiarAlPortapapeles('${escapeHtml(passVal)}')" title="Copiar contraseña">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                `;

                const respHtml = p.responsiva_firmada_url ? `
                    <button type="button" class="btn btn-outline-success btn-sm px-2 py-0" onclick="verPdfModal('${p.responsiva_firmada_url}', 'Responsiva Clave Movimiento - ${p.tipo_movimiento}')" title="Ver responsiva firmada en PDF">
                        <i class="bi bi-file-earmark-check-fill me-1"></i> Firmada (PDF)
                    </button>
                ` : '<span class="badge-status-pill badge-err"><i class="bi bi-clock"></i> Pendiente</span>';

                html += `
                    <tr>
                        <td><strong class="text-white">${p.tipo_movimiento}</strong></td>
                        <td><small class="text-secondary">${p.descripcion_movimiento || 'Sin descripción'}</small></td>
                        <td>${passHtml}</td>
                        <td><strong class="text-danger">${p.usuario_autorizado}</strong></td>
                        <td>${p.puesto_autorizado || ''} <span class="badge bg-secondary bg-opacity-25 text-light">${p.area}</span></td>
                        <td class="text-center"><span class="badge-status-pill badge-err">${p.nivel_seguridad || 'Crítico'}</span></td>
                        <td>${p.fecha_actualizacion || '-'}</td>
                        <td class="text-center">${respHtml}</td>
                        <td class="text-end text-nowrap">
                            <a href="generar_responsiva_movimiento.php?id=${p.id}" target="_blank" class="btn btn-outline-danger btn-sm px-2 py-0 me-1" title="Generar Responsiva Oficial de Clave Especial">
                                <i class="bi bi-printer-fill me-1"></i> Responsiva
                            </a>
                            <button type="button" class="btn btn-outline-success btn-sm px-2 py-0 me-1" onclick="abrirModalSubirResponsivaDoc('gds_password', ${p.id}, 'Clave: ${p.tipo_movimiento} (${p.usuario_autorizado})')" title="Subir PDF de la responsiva firmada">
                                <i class="bi bi-upload"></i>
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" onclick='abrirModalEditarGdsPassword(${JSON.stringify(p)})' title="Editar clave">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0" onclick="eliminarGdsPassword(${p.id})" title="Eliminar registro">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-danger">Error: ${err.message}</td></tr>`;
        });
}

function abrirModalNuevoGdsPassword() {
    document.getElementById('formGdsPassword').reset();
    document.getElementById('gdsPassId').value = 0;
    document.getElementById('modalGdsPassTitulo').innerHTML = '<i class="bi bi-shield-lock-fill me-2"></i>Configurar Contraseña de Movimiento GDS';
    const modal = new bootstrap.Modal(document.getElementById('modalGdsPassword'));
    modal.show();
}

function abrirModalEditarGdsPassword(p) {
    document.getElementById('gdsPassId').value = p.id;
    document.getElementById('gdsPassTipo').value = p.tipo_movimiento;
    document.getElementById('gdsPassDesc').value = p.descripcion_movimiento || '';
    document.getElementById('gdsPassVal').value = p.password_movimiento;
    document.getElementById('gdsPassUsuario').value = p.usuario_autorizado;
    document.getElementById('gdsPassPuesto').value = p.puesto_autorizado || '';
    document.getElementById('gdsPassArea').value = p.area || 'General';
    document.getElementById('gdsPassNivel').value = p.nivel_seguridad || 'Alto';
    document.getElementById('modalGdsPassTitulo').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Editar Contraseña de Movimiento';
    const modal = new bootstrap.Modal(document.getElementById('modalGdsPassword'));
    modal.show();
}

function guardarGdsPassword(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('formGdsPassword'));
    formData.append('action', 'guardar_gds_password');

    fetch('api_auditoria.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalGdsPassword')).hide();
            Swal.fire({ title: 'Guardado', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarGdsPasswords();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function eliminarGdsPassword(id) {
    Swal.fire({
        title: '¿Eliminar clave de movimiento?',
        text: 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'eliminar_gds_password');
            fd.append('id', id);
            fetch('api_auditoria.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) throw new Error(r.error);
                    cargarGdsPasswords();
                });
        }
    });
}

// =============================================================================
// 7. CARGA: USUARIOS GRP
// =============================================================================
function cargarGrpUsuarios() {
    const area = document.getElementById('filtroAreaGrp')?.value || '';
    const estado = document.getElementById('filtroEstadoGrp')?.value || '';
    const busq = document.getElementById('busqGrp')?.value || '';

    const tbody = document.getElementById('tbodyGrpUsuarios');
    if (!tbody) return;

    let url = `api_auditoria.php?action=listar_grp_usuarios&area=${encodeURIComponent(area)}&estado=${encodeURIComponent(estado)}&busqueda=${encodeURIComponent(busq)}`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            cacheDatos.grp_usr = res.datos || [];

            const contEl = document.getElementById('contadorGrp');
            if (contEl) contEl.innerText = `${cacheDatos.grp_usr.length} cuentas GRP`;

            if (cacheDatos.grp_usr.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-secondary">No hay usuarios de GRP registrados.</td></tr>';
                return;
            }

            let html = '';
            cacheDatos.grp_usr.forEach(g => {
                const passId = `grpUsrPass_${g.id}`;
                const passVal = g.password_grp || '';
                const passHtml = `
                    <div class="d-flex align-items-center gap-1">
                        <span id="${passId}" class="pass-hidden" data-pass="${escapeHtml(passVal)}">••••••••</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="togglePassView('${passId}')" title="Ver contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-1 border-0" onclick="copiarAlPortapapeles('${escapeHtml(passVal)}')" title="Copiar contraseña">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                `;

                // Badges de roles
                const rolesArr = (g.roles_grp || '').split(',');
                let rolesBadges = '';
                rolesArr.forEach(r => {
                    const rTrim = r.trim();
                    if (rTrim) rolesBadges += `<span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25 me-1 mb-1">${escapeHtml(rTrim)}</span>`;
                });

                const respHtml = g.responsiva_firmada_url ? `
                    <button type="button" class="btn btn-outline-success btn-sm px-2 py-0" onclick="verPdfModal('${g.responsiva_firmada_url}', 'Responsiva GRP - ${g.colaborador}')" title="Ver responsiva firmada en PDF">
                        <i class="bi bi-file-earmark-check-fill me-1"></i> Firmada (PDF)
                    </button>
                ` : '<span class="badge-status-pill badge-err"><i class="bi bi-clock"></i> Pendiente</span>';

                html += `
                    <tr>
                        <td><strong class="text-white">${g.colaborador}</strong></td>
                        <td><span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">${g.area || 'General'}</span></td>
                        <td><small class="text-secondary">${g.puesto || 'N/A'}</small></td>
                        <td><code class="text-info fw-bold">${g.usuario_grp}</code></td>
                        <td>${passHtml}</td>
                        <td>${rolesBadges}</td>
                        <td><small class="text-secondary">${g.modulos_permitidos || 'Todos los módulos'}</small></td>
                        <td class="text-center"><span class="badge-status-pill ${g.estado === 'Activo' ? 'badge-ok' : 'badge-err'}">${g.estado}</span></td>
                        <td class="text-center">${respHtml}</td>
                        <td class="text-end text-nowrap">
                            <a href="generar_responsiva_grp.php?id=${g.id}" target="_blank" class="btn btn-outline-info btn-sm px-2 py-0 me-1" title="Generar Responsiva Oficial GRP para imprimir y firmar">
                                <i class="bi bi-printer-fill me-1"></i> Responsiva
                            </a>
                            <button type="button" class="btn btn-outline-success btn-sm px-2 py-0 me-1" onclick="abrirModalSubirResponsivaDoc('grp_usuario', ${g.id}, 'Usuario GRP: ${g.colaborador} (${g.usuario_grp})')" title="Subir PDF de la responsiva firmada">
                                <i class="bi bi-upload"></i>
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm px-2 py-0 me-1" onclick='abrirModalEditarGrpUsuario(${JSON.stringify(g)})' title="Editar usuario GRP">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm px-2 py-0" onclick="eliminarGrpUsuario(${g.id})" title="Eliminar registro">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">Error: ${err.message}</td></tr>`;
        });
}

function abrirModalNuevoGrpUsuario() {
    document.getElementById('formGrpUsuario').reset();
    document.getElementById('grpUsrId').value = 0;
    document.getElementById('modalGrpUsrTitulo').innerHTML = '<i class="bi bi-people-fill text-info me-2"></i>Asignar Usuario de GRP';
    const modal = new bootstrap.Modal(document.getElementById('modalGrpUsuario'));
    modal.show();
}

function abrirModalEditarGrpUsuario(g) {
    document.getElementById('grpUsrId').value = g.id;
    document.getElementById('grpUsrColaborador').value = g.colaborador;
    document.getElementById('grpUsrLogin').value = g.usuario_grp;
    document.getElementById('grpUsrPass').value = g.password_grp;
    document.getElementById('grpUsrArea').value = g.area || 'General';
    document.getElementById('grpUsrPuesto').value = g.puesto || '';
    document.getElementById('grpUsrRoles').value = g.roles_grp || '';
    document.getElementById('grpUsrModulos').value = g.modulos_permitidos || '';
    document.getElementById('modalGrpUsrTitulo').innerHTML = '<i class="bi bi-pencil-square text-info me-2"></i>Editar Usuario GRP';
    const modal = new bootstrap.Modal(document.getElementById('modalGrpUsuario'));
    modal.show();
}

function guardarGrpUsuario(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('formGrpUsuario'));
    formData.append('action', 'guardar_grp_usuario');

    fetch('api_auditoria.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalGrpUsuario')).hide();
            Swal.fire({ title: 'Guardado', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });
            cargarGrpUsuarios();
        })
        .catch(err => Swal.fire('Error', err.message, 'error'));
}

function eliminarGrpUsuario(id) {
    Swal.fire({
        title: '¿Eliminar usuario GRP?',
        text: 'Esta acción no se puede deshacer.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'eliminar_grp_usuario');
            fd.append('id', id);
            fetch('api_auditoria.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) throw new Error(r.error);
                    cargarGrpUsuarios();
                });
        }
    });
}

// =============================================================================
// SUBIR RESPONSIVAS FIRMADAS (GDS, GRP O EQUIPOS)
// =============================================================================
function abrirModalSubirResponsivaDoc(tipo, id, label) {
    document.getElementById('subirDocTipo').value = tipo;
    document.getElementById('subirDocId').value = id;
    document.getElementById('subirDocLabelInfo').innerHTML = `Documento: <strong>${label}</strong>`;
    document.getElementById('archivoRespDocPdf').value = '';
    const modal = new bootstrap.Modal(document.getElementById('modalSubirResponsivaDoc'));
    modal.show();
}

function enviarResponsivaDocumento(e) {
    e.preventDefault();
    const tipo = document.getElementById('subirDocTipo').value;
    const id = document.getElementById('subirDocId').value;
    const seccionEq = document.getElementById('subirDocSeccionEq').value;

    const fd = new FormData();
    const fileInput = document.getElementById('archivoRespDocPdf');
    if (!fileInput.files || fileInput.files.length === 0) {
        Swal.fire('Atención', 'Selecciona un archivo PDF', 'warning');
        return;
    }
    fd.append('archivo_pdf', fileInput.files[0]);

    if (tipo === 'equipo') {
        fd.append('action', 'subir_responsiva_equipo');
        fd.append('equipo_id', id);
        fd.append('seccion', seccionEq);
    } else if (tipo === 'gds_usuario') {
        fd.append('action', 'subir_responsiva_gds_usuario');
        fd.append('gds_usuario_id', id);
    } else if (tipo === 'gds_password') {
        fd.append('action', 'subir_responsiva_gds_password');
        fd.append('movimiento_id', id);
    } else if (tipo === 'grp_usuario') {
        fd.append('action', 'subir_responsiva_grp_usuario');
        fd.append('grp_usuario_id', id);
    }

    const btn = document.getElementById('btnSubirDocSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando...';

    fetch('api_auditoria.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-arrow-up-fill me-1"></i> Guardar PDF Firmado';
            if (!res.success) throw new Error(res.error);
            bootstrap.Modal.getInstance(document.getElementById('modalSubirResponsivaDoc')).hide();
            Swal.fire({ title: '¡Responsiva Guardada!', text: res.mensaje, icon: 'success', timer: 1500, showConfirmButton: false });

            // Recargar vista correspondiente
            if (tipo === 'equipo') cargarResponsivasEquipos();
            else if (tipo === 'gds_usuario') cargarGdsUsuarios();
            else if (tipo === 'gds_password') cargarGdsPasswords();
            else if (tipo === 'grp_usuario') cargarGrpUsuarios();
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-arrow-up-fill me-1"></i> Guardar PDF Firmado';
            Swal.fire('Error', err.message, 'error');
        });
}

// =============================================================================
// VISOR DE PDFS EN MODAL
// =============================================================================
function verPdfModal(url, titulo) {
    if (!url) {
        Swal.fire('Aviso', 'No hay archivo PDF disponible para visualizar.', 'info');
        return;
    }
    document.getElementById('visorPdfTitulo').innerHTML = `<i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>${escapeHtml(titulo || 'Documento PDF')}`;
    document.getElementById('iframeVisorPdf').src = url;
    document.getElementById('btnDescargarPdfDirecto').href = url;
    const modal = new bootstrap.Modal(document.getElementById('modalVisorPdf'));
    modal.show();
}

// =============================================================================
// UTILIDADES: VER/OCULTAR CONTRASEÑAS Y COPIAR
// =============================================================================
function togglePassView(elId) {
    const el = document.getElementById(elId);
    if (!el) return;
    const pass = el.getAttribute('data-pass') || '';
    if (el.innerText === '••••••••') {
        el.innerText = pass;
        el.classList.remove('pass-hidden');
        el.classList.add('text-warning', 'fw-bold');
    } else {
        el.innerText = '••••••••';
        el.classList.add('pass-hidden');
        el.classList.remove('text-warning', 'fw-bold');
    }
}

function copiarAlPortapapeles(texto) {
    if (!navigator.clipboard) {
        const ta = document.createElement('textarea');
        ta.value = texto;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    } else {
        navigator.clipboard.writeText(texto);
    }
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1500,
        timerProgressBar: true
    });
    Toast.fire({ icon: 'success', title: 'Copiado al portapapeles' });
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function imprimirAuditoria() {
    window.print();
}

// =============================================================================
// EXPORTACIÓN AVANZADA A EXCEL (.XLSX) CON EXCELJS
// =============================================================================
async function exportarSeccionActivaExcel() {
    if (typeof ExcelJS === 'undefined') {
        Swal.fire('Info', 'Cargando motor de Excel, intente de nuevo en un momento...', 'info');
        return;
    }

    const agencia = window.DATOS_AGENCIA_EXCEL || { nombre: 'GRUPO HUERTA' };
    const now = new Date();
    const fechaStr = now.toLocaleDateString('es-MX');
    const horaStr = now.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });

    const wb = new ExcelJS.Workbook();
    wb.creator = 'Portal de Sistemas - ' + agencia.nombre;
    wb.created = now;

    const borderThin = {
        top: { style: 'thin', color: { argb: 'FFCBD5E1' } },
        left: { style: 'thin', color: { argb: 'FFCBD5E1' } },
        bottom: { style: 'thin', color: { argb: 'FFCBD5E1' } },
        right: { style: 'thin', color: { argb: 'FFCBD5E1' } }
    };
    const fillHeader = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F172A' } };
    const fontHeader = { name: 'Segoe UI', size: 10, bold: true, color: { argb: 'FFFFFFFF' } };

    let sheetName = 'Auditoría';
    let subTitulo = 'Reporte de Auditoría';
    let dataRows = [];
    let headers = [];

    if (seccionActiva === 'responsivas') {
        sheetName = 'Responsivas de Equipos';
        subTitulo = 'Auditoría de Cartas Responsivas de Equipos';
        headers = ['Equipo', 'Usuario', 'Área', 'Puesto', 'Tipo / Modelo', 'Serie', 'Estado Responsiva'];
        dataRows = cacheDatos.responsivas.map(e => [
            e.nombre_equipo, e.usuario, e.area, e.puesto, `${e.tipo} ${e.marca} ${e.modelo}`, e.serie,
            e.tiene_responsiva === 1 ? 'Firmada (PDF)' : 'Pendiente'
        ]);
    } else if (seccionActiva === 'mantenimiento') {
        sheetName = 'Planes Mantenimiento';
        subTitulo = 'Cronograma y Evidencias de Mantenimiento';
        headers = ['Equipo', 'Área', 'Usuario Responsable', 'Tipo', 'Fecha Programada', 'Fecha Realizada', 'Frecuencia', 'Estado', 'Técnico', 'Evidencia PDF'];
        dataRows = cacheDatos.mantenimiento.map(m => [
            m.nombre_equipo, m.area, m.usuario_responsable, m.tipo_mantenimiento, m.fecha_programada,
            m.fecha_realizada || '-', m.frecuencia, m.estado, m.tecnico_responsable || 'Sistemas',
            m.evidencia_pdf_url ? 'SÍ (PDF)' : 'Pendiente'
        ]);
    } else if (seccionActiva === 'correos') {
        sheetName = 'Control de Correos';
        subTitulo = 'Matriz Integral de Cuentas de Correo y Bajas';
        headers = ['Colaborador', 'Correo Electrónico', 'Área', 'Puesto', 'Tipo Cuenta', 'Estado', 'Fecha Alta', 'Fecha Baja', 'Motivo de Baja'];
        dataRows = cacheDatos.correos.map(c => [
            c.nombre_colaborador, c.correo, c.area, c.puesto, c.tipo_cuenta, c.estado,
            c.fecha_alta || '-', c.fecha_baja || '-', c.motivo_baja || '-'
        ]);
    } else if (seccionActiva === 'licencias') {
        sheetName = 'Licencias de Office';
        subTitulo = 'Matriz de Licenciamiento y Product Keys';
        headers = ['Product Key', 'Producto', 'Tipo', 'Cuenta Microsoft', 'Equipo Asignado', 'Usuario Asignado', 'Área', 'Estado', 'Fecha Compra'];
        dataRows = cacheDatos.licencias.map(l => [
            l.codigo_licencia, l.nombre_producto, l.tipo_licencia, l.cuenta_microsoft, l.equipo_asignado,
            l.usuario_asignado, l.area, l.estado, l.fecha_compra || '-'
        ]);
    } else if (seccionActiva === 'gds_usr') {
        sheetName = 'Usuarios GDS';
        subTitulo = 'Control de Accesos y Credenciales DMS/GDS';
        headers = ['Colaborador', 'Área', 'Puesto', 'Usuario GDS', 'Contraseña GDS', 'Perfil GDS', 'Sucursal', 'Estado', 'Responsiva'];
        dataRows = cacheDatos.gds_usr.map(u => [
            u.colaborador, u.area, u.puesto, u.usuario_gds, u.password_gds, u.perfil_gds, u.sucursal,
            u.estado, u.responsiva_firmada_url ? 'Firmada (PDF)' : 'Pendiente'
        ]);
    } else if (seccionActiva === 'gds_pass') {
        sheetName = 'Passwords Movimientos GDS';
        subTitulo = 'Custodia de Claves de Movimientos Críticos';
        headers = ['Tipo de Movimiento', 'Descripción', 'Contraseña de Autorización', 'Usuario Autorizado', 'Puesto', 'Área', 'Nivel', 'Última Modif.', 'Responsiva'];
        dataRows = cacheDatos.gds_pass.map(p => [
            p.tipo_movimiento, p.descripcion_movimiento, p.password_movimiento, p.usuario_autorizado,
            p.puesto_autorizado, p.area, p.nivel_seguridad, p.fecha_actualizacion || '-',
            p.responsiva_firmada_url ? 'Firmada (PDF)' : 'Pendiente'
        ]);
    } else if (seccionActiva === 'grp_usr') {
        sheetName = 'Usuarios GRP';
        subTitulo = 'Asignación de Roles y Permisos GRP';
        headers = ['Colaborador', 'Área', 'Puesto', 'Usuario GRP', 'Contraseña GRP', 'Roles GRP', 'Módulos', 'Estado', 'Responsiva'];
        dataRows = cacheDatos.grp_usr.map(g => [
            g.colaborador, g.area, g.puesto, g.usuario_grp, g.password_grp, g.roles_grp,
            g.modulos_permitidos, g.estado, g.responsiva_firmada_url ? 'Firmada (PDF)' : 'Pendiente'
        ]);
    }

    const ws = wb.addWorksheet(sheetName.substring(0, 31), { views: [{ showGridLines: true }] });

    // Membrete
    const numCols = headers.length;
    const endColLetter = String.fromCharCode(65 + numCols - 1);

    ws.mergeCells(`A2:${endColLetter}2`);
    const cTitle = ws.getCell('A2');
    cTitle.value = (agencia.nombre || 'GRUPO HUERTA').toUpperCase();
    cTitle.font = { name: 'Segoe UI', size: 15, bold: true, color: { argb: 'FF0F172A' } };

    ws.mergeCells(`A3:${endColLetter}3`);
    const cSub = ws.getCell('A3');
    cSub.value = subTitulo.toUpperCase();
    cSub.font = { name: 'Segoe UI', size: 11, bold: true, color: { argb: 'FF0284C7' } };

    ws.mergeCells(`A4:${endColLetter}4`);
    const cMeta = ws.getCell('A4');
    cMeta.value = `Emisión: ${fechaStr} ${horaStr} hrs | Razón Social: ${agencia.razon_social || ''} | Total: ${dataRows.length} registros`;
    cMeta.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF475569' } };

    // Encabezados fila 6
    const hRow = ws.getRow(6);
    hRow.height = 24;
    headers.forEach((h, idx) => {
        const cell = ws.getCell(6, idx + 1);
        cell.value = h;
        cell.font = fontHeader;
        cell.fill = fillHeader;
        cell.alignment = { horizontal: 'center', vertical: 'middle' };
        cell.border = borderThin;
    });

    // Filas de datos
    let rIdx = 7;
    dataRows.forEach((row, rowNum) => {
        const r = ws.getRow(rIdx);
        r.height = 19;
        const bgZebra = (rowNum % 2 === 0) ? 'FFFFFFFF' : 'FFF8FAFC';

        row.forEach((val, colNum) => {
            const cell = ws.getCell(rIdx, colNum + 1);
            cell.value = val;
            cell.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF1E293B' } };
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgZebra } };
            cell.alignment = { vertical: 'middle' };
            cell.border = borderThin;
        });
        rIdx++;
    });

    // Auto-ajuste de columnas
    ws.columns.forEach(col => {
        col.width = 20;
    });

    // Escribir buffer y descargar
    const buffer = await wb.xlsx.writeBuffer();
    const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const fileName = `Auditoria_${sheetName.replace(/[^a-zA-Z0-9_-]/g, '_')}_${now.toISOString().slice(0, 10)}.xlsx`;

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    Swal.fire({
        title: '¡Reporte Generado!',
        text: `Se descargó exitosamente: ${fileName}`,
        icon: 'success',
        timer: 2000,
        showConfirmButton: false
    });
}

async function exportarLibroCompletoAuditoriaExcel() {
    Swal.fire({
        title: 'Generando Libro Completo de Auditoría...',
        text: 'Estructurando las 7 secciones en un libro multi-hoja oficial...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        // Asegurar que todas las secciones estén cargadas
        const promises = [
            fetch('api_auditoria.php?action=listar_responsivas_equipos').then(r => r.json()),
            fetch('api_auditoria.php?action=listar_mantenimientos').then(r => r.json()),
            fetch('api_auditoria.php?action=listar_correos').then(r => r.json()),
            fetch('api_auditoria.php?action=listar_licencias').then(r => r.json()),
            fetch('api_auditoria.php?action=listar_gds_usuarios').then(r => r.json()),
            fetch('api_auditoria.php?action=listar_gds_passwords').then(r => r.json()),
            fetch('api_auditoria.php?action=listar_grp_usuarios').then(r => r.json())
        ];

        const [rResp, rMant, rCorr, rLic, rGdsU, rGdsP, rGrp] = await Promise.all(promises);

        const agencia = window.DATOS_AGENCIA_EXCEL || { nombre: 'GRUPO HUERTA' };
        const now = new Date();
        const wb = new ExcelJS.Workbook();
        wb.creator = 'Portal de Sistemas - ' + agencia.nombre;
        wb.created = now;

        const borderThin = {
            top: { style: 'thin', color: { argb: 'FFCBD5E1' } },
            left: { style: 'thin', color: { argb: 'FFCBD5E1' } },
            bottom: { style: 'thin', color: { argb: 'FFCBD5E1' } },
            right: { style: 'thin', color: { argb: 'FFCBD5E1' } }
        };
        const fillHeader = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F172A' } };
        const fontHeader = { name: 'Segoe UI', size: 10, bold: true, color: { argb: 'FFFFFFFF' } };

        const agregarHoja = (nombreHoja, titulo, headers, data) => {
            const ws = wb.addWorksheet(nombreHoja.substring(0, 31), { views: [{ showGridLines: true }] });
            const endCol = String.fromCharCode(65 + headers.length - 1);

            ws.mergeCells(`A2:${endCol}2`);
            const cT = ws.getCell('A2');
            cT.value = (agencia.nombre || 'GRUPO HUERTA').toUpperCase();
            cT.font = { name: 'Segoe UI', size: 14, bold: true, color: { argb: 'FF0F172A' } };

            ws.mergeCells(`A3:${endCol}3`);
            const cS = ws.getCell('A3');
            cS.value = titulo.toUpperCase();
            cS.font = { name: 'Segoe UI', size: 11, bold: true, color: { argb: 'FF0284C7' } };

            ws.getRow(5).height = 24;
            headers.forEach((h, i) => {
                const cell = ws.getCell(5, i + 1);
                cell.value = h;
                cell.font = fontHeader;
                cell.fill = fillHeader;
                cell.alignment = { horizontal: 'center', vertical: 'middle' };
                cell.border = borderThin;
            });

            let rIdx = 6;
            data.forEach((row, rowIdx) => {
                const r = ws.getRow(rIdx);
                r.height = 19;
                const bgZebra = (rowIdx % 2 === 0) ? 'FFFFFFFF' : 'FFF8FAFC';
                row.forEach((val, cIdx) => {
                    const cell = ws.getCell(rIdx, cIdx + 1);
                    cell.value = val;
                    cell.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF1E293B' } };
                    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgZebra } };
                    cell.alignment = { vertical: 'middle' };
                    cell.border = borderThin;
                });
                rIdx++;
            });

            ws.columns.forEach(col => { col.width = 22; });
        };

        // 1. Responsivas
        agregarHoja('1. Responsivas Equipos', 'Auditoría de Responsivas de Equipos', 
            ['Equipo', 'Usuario', 'Área', 'Puesto', 'Tipo / Marca', 'Serie', 'Estado Responsiva'],
            (rResp.datos || []).map(e => [e.nombre_equipo, e.usuario, e.area, e.puesto, `${e.tipo} ${e.marca}`, e.serie, e.tiene_responsiva === 1 ? 'Firmada' : 'Pendiente'])
        );

        // 2. Mantenimientos
        agregarHoja('2. Planes Mantenimiento', 'Planes y Calendario de Mantenimiento',
            ['Equipo', 'Área', 'Usuario', 'Tipo', 'Fecha Prog.', 'Fecha Real.', 'Frecuencia', 'Estado', 'Técnico', 'Evidencia PDF'],
            (rMant.datos || []).map(m => [m.nombre_equipo, m.area, m.usuario_responsable, m.tipo_mantenimiento, m.fecha_programada, m.fecha_realizada || '-', m.frecuencia, m.estado, m.tecnico_responsable || 'Sistemas', m.evidencia_pdf_url ? 'SÍ (PDF)' : 'Pendiente'])
        );

        // 3. Correos
        agregarHoja('3. Control de Correos', 'Matriz de Correos, Altas y Bajas',
            ['Colaborador', 'Correo Electrónico', 'Área', 'Puesto', 'Tipo', 'Estado', 'Fecha Alta', 'Fecha Baja', 'Motivo'],
            (rCorr.datos || []).map(c => [c.nombre_colaborador, c.correo, c.area, c.puesto, c.tipo_cuenta, c.estado, c.fecha_alta || '-', c.fecha_baja || '-', c.motivo_baja || '-'])
        );

        // 4. Licencias
        agregarHoja('4. Licencias Office', 'Matriz de Licencias y Product Keys',
            ['Product Key', 'Producto', 'Tipo', 'Cuenta MS', 'Equipo', 'Usuario', 'Área', 'Estado', 'Compra'],
            (rLic.datos || []).map(l => [l.codigo_licencia, l.nombre_producto, l.tipo_licencia, l.cuenta_microsoft, l.equipo_asignado, l.usuario_asignado, l.area, l.estado, l.fecha_compra || '-'])
        );

        // 5. Usuarios GDS
        agregarHoja('5. Usuarios GDS', 'Usuarios y Credenciales GDS',
            ['Colaborador', 'Área', 'Puesto', 'Usuario GDS', 'Password GDS', 'Perfil GDS', 'Sucursal', 'Estado', 'Responsiva'],
            (rGdsU.datos || []).map(u => [u.colaborador, u.area, u.puesto, u.usuario_gds, u.password_gds, u.perfil_gds, u.sucursal, u.estado, u.responsiva_firmada_url ? 'SÍ (PDF)' : 'Pendiente'])
        );

        // 6. Passwords Movimientos GDS
        agregarHoja('6. Passwords Movimientos GDS', 'Custodia de Claves de Movimientos Críticos GDS',
            ['Tipo de Movimiento', 'Descripción', 'Password', 'Usuario Autorizado', 'Puesto', 'Área', 'Nivel', 'Última Modif.', 'Responsiva'],
            (rGdsP.datos || []).map(p => [p.tipo_movimiento, p.descripcion_movimiento, p.password_movimiento, p.usuario_autorizado, p.puesto_autorizado, p.area, p.nivel_seguridad, p.fecha_actualizacion || '-', p.responsiva_firmada_url ? 'SÍ (PDF)' : 'Pendiente'])
        );

        // 7. Usuarios GRP
        agregarHoja('7. Usuarios GRP', 'Usuarios y Roles en Sistema GRP',
            ['Colaborador', 'Área', 'Puesto', 'Usuario GRP', 'Password GRP', 'Roles GRP', 'Módulos', 'Estado', 'Responsiva'],
            (rGrp.datos || []).map(g => [g.colaborador, g.area, g.puesto, g.usuario_grp, g.password_grp, g.roles_grp, g.modulos_permitidos, g.estado, g.responsiva_firmada_url ? 'SÍ (PDF)' : 'Pendiente'])
        );

        const buffer = await wb.xlsx.writeBuffer();
        const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const fileName = `Libro_Completo_Auditoria_Interna_${now.toISOString().slice(0, 10)}.xlsx`;

        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        Swal.fire({
            title: '¡Libro de Auditoría Descargado!',
            text: 'Se han integrado las 7 secciones en un solo libro Excel (.xlsx) con hojas individuales.',
            icon: 'success'
        });

    } catch (err) {
        Swal.fire('Error', 'No se pudo generar el libro completo: ' + err.message, 'error');
    }
}
</script>
</body>
</html>
