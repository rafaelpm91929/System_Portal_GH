<?php
// Polyfills de compatibilidad para servidores con PHP 7.x
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'conexion.php';
require_once 'permisos_helper.php';
require_once 'excel_helper.php';
include_once 'config_agencias.php';

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

// Cargar datos de usuario
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Agente de Sistemas');
$agenciaUsuario = $_SESSION['agencia'] ?? 'Oficina Central Grupo Huerta';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'admin');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

if ($pdo) {
    asegurarTablaTickets($pdo);
}

// Catálogo Oficial de Áreas de Sistemas
$AREAS_SISTEMAS = [
    'DESARROLLO'      => ['nombre' => 'DESARROLLO', 'icono' => 'bi-code-slash', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.18)', 'border' => 'rgba(56, 189, 248, 0.45)'],
    'CYBERSEGURIDAD'  => ['nombre' => 'CYBERSEGURIDAD', 'icono' => 'bi-shield-lock-fill', 'color' => '#f43f5e', 'bg' => 'rgba(244, 63, 94, 0.18)', 'border' => 'rgba(244, 63, 94, 0.45)'],
    'INFRAESTRUCTURA' => ['nombre' => 'INFRAESTRUCTURA', 'icono' => 'bi-hdd-rack-fill', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.18)', 'border' => 'rgba(16, 185, 129, 0.45)'],
    'REDES SOCIALES'  => ['nombre' => 'REDES SOCIALES', 'icono' => 'bi-share-fill', 'color' => '#a855f7', 'bg' => 'rgba(168, 85, 247, 0.18)', 'border' => 'rgba(168, 85, 247, 0.45)'],
    'AUDITORIA'       => ['nombre' => 'AUDITORIA', 'icono' => 'bi-clipboard-check-fill', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.18)', 'border' => 'rgba(245, 158, 11, 0.45)'],
    'CORPORATIVO'     => ['nombre' => 'CORPORATIVO', 'icono' => 'bi-building-fill', 'color' => '#6366f1', 'bg' => 'rgba(99, 102, 241, 0.18)', 'border' => 'rgba(99, 102, 241, 0.45)']
];

$mensaje = $_SESSION['flash_mensaje'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);

// ====================================================
// MANEJO DE ACCIONES POST (CREAR, EDITAR, ATENDER)
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. Crear / Registrar Nuevo Ticket de Soporte (Por Agente)
    if ($accion === 'crear_ticket') {
        $area = trim($_POST['area_sistemas'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad = trim($_POST['prioridad'] ?? 'Media');
        $solicitanteNombre = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
        $solicitanteEmail = trim($_POST['solicitante_email'] ?? '');
        $solicitanteAgencia = trim($_POST['solicitante_agencia'] ?? 'VW Divol La Villa');
        $asignadoA = trim($_POST['asignado_a'] ?? '');

        if (empty($titulo) || empty($descripcion) || empty($area)) {
            $error = "Por favor completa el Área de Sistemas, Título y Descripción del ticket.";
        } else {
            $archivoUrl = null;
            if (isset($_FILES['archivo_adjunto']) && $_FILES['archivo_adjunto']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['archivo_adjunto']['tmp_name'];
                $fileName = $_FILES['archivo_adjunto']['name'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

                if (in_array($fileExt, $extPermitidas)) {
                    $dirUpload = __DIR__ . '/uploads/tickets/';
                    if (!is_dir($dirUpload)) {
                        @mkdir($dirUpload, 0777, true);
                    }
                    $nuevoNombre = 'ticket_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $destino = $dirUpload . $nuevoNombre;
                    if (move_uploaded_file($fileTmp, $destino)) {
                        $archivoUrl = 'uploads/tickets/' . $nuevoNombre;
                    }
                }
            }

            try {
                $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                if ($driver === 'sqlite') {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM tickets_soporte");
                } else {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM `tickets_soporte`");
                }
                $nextNum = ($countStmt ? (int)$countStmt->fetchColumn() : 0) + 1;
                $folio = 'TK-' . date('Y') . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("
                    INSERT INTO tickets_soporte 
                    (folio, area_sistemas, titulo, descripcion, prioridad, estado, solicitante_id, solicitante_nombre, solicitante_email, solicitante_agencia, asignado_a, archivo_adjunto)
                    VALUES
                    (:folio, :area, :titulo, :desc, :prio, 'Abierto', :sol_id, :sol_nom, :sol_em, :sol_ag, :asignado, :archivo)
                ");
                $stmt->execute([
                    ':folio'     => $folio,
                    ':area'      => $area,
                    ':titulo'    => $titulo,
                    ':desc'      => $descripcion,
                    ':prio'      => $prioridad,
                    ':sol_id'    => $usuarioId,
                    ':sol_nom'   => $solicitanteNombre,
                    ':sol_em'    => $solicitanteEmail,
                    ':sol_ag'    => $solicitanteAgencia,
                    ':asignado'  => $asignadoA,
                    ':archivo'   => $archivoUrl
                ]);
                $mensaje = "Ticket con Folio <strong>$folio</strong> para la agencia <strong>" . htmlspecialchars($solicitanteAgencia) . "</strong> registrado con éxito.";
            } catch (Throwable $e) {
                $error = "Error al registrar el ticket: " . $e->getMessage();
            }
        }
    }

    // 2. Actualizar Estado, Asignación y Notas de Resolución por el Agente
    if ($accion === 'actualizar_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $nuevoEstado = trim($_POST['estado'] ?? 'Abierto');
        $asignadoA = trim($_POST['asignado_a'] ?? '');
        $prioridad = trim($_POST['prioridad'] ?? 'Media');
        $notasResolucion = trim($_POST['notas_resolucion'] ?? '');

        if ($ticketId > 0) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE tickets_soporte 
                    SET estado = :estado, asignado_a = :asignado, prioridad = :prioridad, notas_resolucion = :notas 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':estado'    => $nuevoEstado,
                    ':asignado'  => $asignadoA,
                    ':prioridad' => $prioridad,
                    ':notas'     => $notasResolucion,
                    ':id'        => $ticketId
                ]);
                $mensaje = "Ticket ID #$ticketId actualizado correctamente por el agente.";
            } catch (Throwable $e) {
                $error = "Error al actualizar el ticket: " . $e->getMessage();
            }
        }
    }

    // 3. Auto-asignarme ticket
    if ($accion === 'autoasignar_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        if ($ticketId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE tickets_soporte SET asignado_a = :nombre, estado = CASE WHEN estado = 'Abierto' THEN 'En Proceso' ELSE estado END WHERE id = :id");
                $stmt->execute([':nombre' => $nombreUsuario, ':id' => $ticketId]);
                $mensaje = "Te has autoasignado el ticket #$ticketId. Estado actualizado a 'En Proceso'.";
            } catch (Throwable $e) {
                $error = "Error al autoasignar: " . $e->getMessage();
            }
        }
    }

    // 4. Eliminar Ticket
    if ($accion === 'eliminar_ticket' && $esAdmin) {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        if ($ticketId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM tickets_soporte WHERE id = :id");
                $stmt->execute([':id' => $ticketId]);
                $mensaje = "El ticket #$ticketId ha sido eliminado correctamente.";
            } catch (Throwable $e) {
                $error = "Error al eliminar el ticket: " . $e->getMessage();
            }
        }
    }

    // Patrón Post/Redirect/Get: Previene duplicados al recargar con F5
    $_SESSION['flash_mensaje'] = $mensaje;
    $_SESSION['flash_error'] = $error;
    header("Location: tickets.php");
    exit();
}

// ====================================================
// EXPORTACIÓN A EXCEL CORPORATIVO
// ====================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel' && $pdo) {
    $stmtExp = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
    $registros = $stmtExp ? $stmtExp->fetchAll(PDO::FETCH_ASSOC) : [];

    $fileName = 'Reporte_Tickets_Helpdesk_TI_' . date('Ymd_His') . '.xls';
    $agenciaInfo = ['nombre' => 'Dirección Central de Sistemas - Grupo Huerta'];

    $columnasExcel = [
        ['titulo' => 'FOLIO', 'ancho' => 110, 'align' => 'center'],
        ['titulo' => 'AGENCIA ORIGEN', 'ancho' => 180, 'align' => 'left'],
        ['titulo' => 'ÁREA SISTEMAS', 'ancho' => 140, 'align' => 'center'],
        ['titulo' => 'TÍTULO / ASUNTO', 'ancho' => 240, 'align' => 'left'],
        ['titulo' => 'PRIORIDAD', 'ancho' => 110, 'align' => 'center'],
        ['titulo' => 'ESTADO', 'ancho' => 120, 'align' => 'center'],
        ['titulo' => 'SOLICITANTE', 'ancho' => 170, 'align' => 'left'],
        ['titulo' => 'AGENTE ASIGNADO', 'ancho' => 170, 'align' => 'left'],
        ['titulo' => 'FECHA REGISTRO', 'ancho' => 150, 'align' => 'center'],
        ['titulo' => 'NOTAS RESOLUCIÓN', 'ancho' => 280, 'align' => 'left']
    ];

    $filasExcel = [];
    foreach ($registros as $r) {
        $filasExcel[] = [
            ['val' => htmlspecialchars($r['folio'] ?? 'TK-' . $r['id']), 'align' => 'center', 'is_bold' => true],
            ['val' => htmlspecialchars($r['solicitante_agencia'] ?? 'Central'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['area_sistemas'] ?? '---'), 'align' => 'center'],
            ['val' => htmlspecialchars($r['titulo'] ?? '---'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['prioridad'] ?? 'Media'), 'align' => 'center'],
            ['val' => htmlspecialchars($r['estado'] ?? 'Abierto'), 'align' => 'center'],
            ['val' => htmlspecialchars($r['solicitante_nombre'] ?? '---'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['asignado_a'] ?? 'Sin asignar'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['creado_en'] ?? '---'), 'align' => 'center', 'is_text' => true],
            ['val' => htmlspecialchars($r['notas_resolucion'] ?? '---'), 'align' => 'left']
        ];
    }

    descargarExcelConDiseno(
        'TICKETS DE SOPORTE &bull; MESA DE AYUDA TI',
        'Panel de Agentes - Control de Incidencias de Agencias Grupo Huerta',
        $columnasExcel,
        $filasExcel,
        $agenciaInfo,
        $fileName,
        'Tickets Helpdesk'
    );
}

// ====================================================
// CONSULTA DE TICKETS Y MÉTRICAS DE AGENTES
// ====================================================
$tickets = [];
$totalTickets = 0;
$totalAbiertos = 0;
$totalEnProceso = 0;
$totalResueltos = 0;
$totalSinAsignar = 0;
$conteoPorAgencia = [];

// Inicializar conteos por cada agencia conectada
if (!empty($CATALOGO_AGENCIAS)) {
    foreach ($CATALOGO_AGENCIAS as $ag) {
        $conteoPorAgencia[$ag['nombre']] = 0;
    }
}
$conteoPorAgencia['Oficina Central Grupo Huerta'] = 0;

if ($pdo) {
    try {
        $stmtAll = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
        $tickets = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];

        $totalTickets = count($tickets);
        foreach ($tickets as $t) {
            $estLower = strtolower($t['estado'] ?? '');
            if ($estLower === 'abierto') {
                $totalAbiertos++;
            } elseif ($estLower === 'en proceso') {
                $totalEnProceso++;
            } elseif ($estLower === 'resuelto' || $estLower === 'cerrado') {
                $totalResueltos++;
            }

            if (empty(trim($t['asignado_a'] ?? ''))) {
                $totalSinAsignar++;
            }

            $agNom = $t['solicitante_agencia'] ?? 'Oficina Central Grupo Huerta';
            if (!isset($conteoPorAgencia[$agNom])) {
                $conteoPorAgencia[$agNom] = 0;
            }
            if ($estLower !== 'resuelto' && $estLower !== 'cerrado') {
                $conteoPorAgencia[$agNom]++;
            }
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Agentes TI - Tickets Soporte Dirección Sistemas</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #061325;
            background-image: radial-gradient(#0e2440 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 60px;
        }

        .top-navbar {
            background: rgba(6, 19, 37, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 35px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .main-container {
            max-width: 1560px;
            margin: 0 auto;
            padding: 25px 35px;
        }

        /* Banner de Agente */
        .agent-badge {
            background: rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(14, 165, 233, 0.35);
            color: #38bdf8;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* Barra de Agencias Conectadas */
        .agencies-monitor-bar {
            background: #091a32;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 25px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        .agency-pill-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            padding: 7px 16px;
            border-radius: 25px;
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            user-select: none;
        }
        .agency-pill-btn:hover {
            background: rgba(56, 189, 248, 0.15);
            border-color: #38bdf8;
            color: #ffffff;
            transform: translateY(-2px);
        }
        .agency-pill-btn.active {
            background: #0284c7 !important;
            border-color: #38bdf8 !important;
            color: #ffffff !important;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.45);
        }
        .pending-count-badge {
            font-size: 0.7rem;
            padding: 2px 7px;
            border-radius: 12px;
            font-weight: 700;
        }

        /* KPIs */
        .kpi-card {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            border-color: rgba(56, 189, 248, 0.4);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        }
        .kpi-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .kpi-cyan { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
        .kpi-yellow { background: rgba(234, 179, 8, 0.15); color: #fde047; }
        .kpi-red { background: rgba(244, 63, 94, 0.15); color: #fb7185; }
        .kpi-blue { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .kpi-green { background: rgba(34, 197, 94, 0.15); color: #4ade80; }

        /* Filtros por Área */
        .area-pill {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            user-select: none;
        }
        .area-pill:hover, .area-pill.active {
            color: #ffffff;
            border-color: #38bdf8;
            background: rgba(56, 189, 248, 0.2);
            box-shadow: 0 2px 8px rgba(56, 189, 248, 0.25);
        }

        .search-box-wrapper {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 7px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .search-box-wrapper:focus-within {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25);
            background: #112543;
        }
        .search-input {
            background: transparent;
            border: none;
            color: #ffffff;
            outline: none;
            width: 100%;
            font-size: 0.95rem;
        }

        /* Tabla de Tickets */
        .tickets-table-card {
            background: #091a32;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.45);
        }
        .table {
            margin-bottom: 0;
            --bs-table-bg: transparent;
            --bs-table-color: #ffffff;
            border-collapse: collapse;
        }
        .table thead th {
            background-color: #0b2242 !important;
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 15px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            vertical-align: middle;
        }
        .table tbody tr {
            background-color: #08172c !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background-color 0.15s ease;
        }
        .table tbody tr:nth-of-type(even) {
            background-color: #0b1f3b !important;
        }
        .table tbody tr:hover {
            background-color: #122b52 !important;
        }
        .table tbody td {
            padding: 14px 18px;
            vertical-align: middle;
            font-size: 0.88rem;
            color: #e2e8f0;
            border-top: none;
        }

        .badge-folio {
            font-family: monospace;
            font-weight: 700;
            font-size: 0.85rem;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.3);
            padding: 3px 8px;
            border-radius: 6px;
        }
        .badge-agencia {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 12px;
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .badge-prio-urgente { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .badge-prio-alta    { background: rgba(249, 115, 22, 0.2); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.4); }
        .badge-prio-media   { background: rgba(234, 179, 8, 0.2); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-prio-baja    { background: rgba(100, 116, 139, 0.2); color: #94a3b8; border: 1px solid rgba(100, 116, 139, 0.4); }

        .badge-status-abierto    { background: rgba(234, 179, 8, 0.18); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-status-proceso    { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        .badge-status-resuelto   { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.4); }
        .badge-status-cerrado    { background: rgba(100, 116, 139, 0.25); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.4); }

        /* Modales */
        .modal-content {
            background-color: #0b1f3b;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 20px;
        }
        .modal-header { border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 18px 25px; }
        .modal-footer { border-top: 1px solid rgba(255, 255, 255, 0.08); padding: 15px 25px; }
        .form-control, .form-select {
            background-color: #061325;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 10px;
        }
        .form-control:focus, .form-select:focus {
            background-color: #0a1f3d;
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25);
        }
    </style>
</head>
<body>

<!-- Navbar de Agente -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-light btn-sm rounded-3 px-3 py-1 d-flex align-items-center gap-2">
            <i class="bi bi-arrow-left"></i> <span>Menú Principal</span>
        </a>
        <div class="d-flex align-items-center gap-2 border-start border-secondary ps-3">
            <i class="bi bi-headset text-info fs-4"></i>
            <span class="fw-bold tracking-wide">MESA DE AYUDA TI <span class="text-secondary fw-normal">| Panel de Agentes de Sistemas</span></span>
            <span class="agent-badge ms-2"><i class="bi bi-person-badge-fill"></i> VISTA DE AGENTE</span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="text-end d-none d-md-block">
            <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.75rem;"><?php echo htmlspecialchars($agenciaUsuario); ?> &bull; <span class="badge bg-primary text-uppercase"><?php echo htmlspecialchars($rolActual); ?></span></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Salir
        </a>
    </div>
</div>

<div class="main-container">

    <!-- Notificaciones -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 bg-success bg-opacity-25 text-white border-success mb-3" role="alert">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 bg-danger bg-opacity-25 text-white border-danger mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo $error; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ============================================== -->
    <!-- 1. MONITOR DE AGENCIAS CONECTADAS (VISTA AGENTE) -->
    <!-- ============================================== -->
    <div class="agencies-monitor-bar">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-buildings-fill text-success fs-5"></i>
                <span class="fw-bold text-white">Agencias Conectadas (Filtro Rápido):</span>
                <span class="small text-secondary">Haz clic en una sucursal para ver sus tickets</span>
            </div>
            <a href="agencias.php" class="btn btn-outline-info btn-sm rounded-3 px-3">
                <i class="bi bi-gear-fill me-1"></i> Administrar cPanels / Conexiones
            </a>
        </div>

        <div class="d-flex flex-wrap gap-2 pt-1" id="agenciasFilterContainer">
            <!-- Botón Todas -->
            <button class="agency-pill-btn active" onclick="filtrarPorAgencia('TODAS', this)">
                <i class="bi bi-globe2 text-info"></i>
                <span>Todas las Agencias</span>
                <span class="pending-count-badge bg-primary text-white"><?php echo $totalTickets; ?></span>
            </button>

            <!-- Botones por cada Agencia Conectada -->
            <?php foreach ($conteoPorAgencia as $nomAg => $pendientes): 
                if ($nomAg === 'Oficina Central Grupo Huerta') continue;
            ?>
                <button class="agency-pill-btn" onclick="filtrarPorAgencia('<?php echo htmlspecialchars($nomAg); ?>', this)">
                    <i class="bi bi-building text-warning"></i>
                    <span><?php echo htmlspecialchars($nomAg); ?></span>
                    <?php if ($pendientes > 0): ?>
                        <span class="pending-count-badge bg-danger text-white"><?php echo $pendientes; ?> pend.</span>
                    <?php else: ?>
                        <span class="pending-count-badge bg-secondary text-light">0 pend.</span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>

            <!-- Central -->
            <button class="agency-pill-btn" onclick="filtrarPorAgencia('Oficina Central Grupo Huerta', this)">
                <i class="bi bi-building-lock text-info"></i>
                <span>Oficina Central</span>
                <span class="pending-count-badge bg-secondary text-light"><?php echo $conteoPorAgencia['Oficina Central Grupo Huerta'] ?? 0; ?></span>
            </button>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- 2. KPIs DE CONTROL DE LA MESA DE AYUDA -->
    <!-- ============================================== -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-cyan"><i class="bi bi-inbox-fill"></i></div>
                <div>
                    <div class="fs-4 fw-bold text-white"><?php echo $totalTickets; ?></div>
                    <div class="small text-secondary fw-semibold">TOTAL TICKETS</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-yellow"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="fs-4 fw-bold text-warning"><?php echo $totalAbiertos; ?></div>
                    <div class="small text-secondary fw-semibold">ABIERTOS / EN ESPERA</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-blue"><i class="bi bi-gear-wide-connected"></i></div>
                <div>
                    <div class="fs-4 fw-bold text-info"><?php echo $totalEnProceso; ?></div>
                    <div class="small text-secondary fw-semibold">EN ATENCIÓN / PROCESO</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-green"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="fs-4 fw-bold text-success"><?php echo $totalResueltos; ?></div>
                    <div class="small text-secondary fw-semibold">RESUELTOS / CERRADOS</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- 3. BARRA DE HERRAMIENTAS: BÚSQUEDA Y ACCIONES -->
    <!-- ============================================== -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <!-- Buscador -->
        <div class="search-box-wrapper flex-grow-1" style="max-width: 500px;">
            <i class="bi bi-search text-secondary"></i>
            <input type="text" id="busquedaInput" class="search-input" placeholder="Buscar por folio, requerimiento, solicitante o técnico..." oninput="filtrarTickets()">
            <button type="button" class="btn btn-link btn-sm text-secondary p-0" onclick="limpiarBusqueda()"><i class="bi bi-x-circle-fill"></i></button>
        </div>

        <!-- Botones de Acción para Agente -->
        <div class="d-flex align-items-center gap-2">
            <a href="tickets.php?export=excel" class="btn btn-success btn-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-excel-fill"></i> <span>Exportar a Excel</span>
            </a>
            <button class="btn btn-primary btn-sm rounded-3 px-3 py-2 fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalNuevoTicket">
                <i class="bi bi-plus-circle-fill"></i> <span>+ Registrar Ticket Manual</span>
            </button>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- 4. CHIPS DE FILTRADO POR ÁREA DE SISTEMAS -->
    <!-- ============================================== -->
    <div class="d-flex flex-wrap gap-2 mb-4" id="areasFilterContainer">
        <div class="area-pill active" onclick="filtrarPorArea('TODAS', this)">
            <i class="bi bi-grid-fill"></i> Todas las Áreas
        </div>
        <?php foreach ($AREAS_SISTEMAS as $areaKey => $areaInfo): ?>
            <div class="area-pill" onclick="filtrarPorArea('<?php echo $areaKey; ?>', this)">
                <i class="bi <?php echo $areaInfo['icono']; ?>"></i> <?php echo $areaInfo['nombre']; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ============================================== -->
    <!-- 5. TABLA DE TICKETS - VISTA DE AGENTE TI -->
    <!-- ============================================== -->
    <div class="tickets-table-card">
        <div class="table-responsive">
            <table class="table align-middle" id="tablaTickets">
                <thead>
                    <tr>
                        <th style="width: 130px;">FOLIO & FECHA</th>
                        <th style="width: 170px;">AGENCIA ORIGEN</th>
                        <th style="width: 140px;">ÁREA DESTINO</th>
                        <th>REQUERIMIENTO / ASUNTO</th>
                        <th style="width: 110px;">PRIORIDAD</th>
                        <th style="width: 120px;">ESTADO</th>
                        <th style="width: 160px;">SOLICITANTE</th>
                        <th style="width: 160px;">AGENTE ASIGNADO</th>
                        <th style="width: 140px;" class="text-center">ACCIONES</th>
                    </tr>
                </thead>
                <tbody id="ticketsTbody">
                    <?php if (empty($tickets)): ?>
                        <tr id="filaSinTickets">
                            <td colspan="9" class="text-center py-5">
                                <i class="bi bi-inbox text-secondary display-3 d-block mb-3"></i>
                                <h5 class="fw-bold text-white mb-1">No hay tickets registrados en el sistema</h5>
                                <p class="small text-secondary mb-0">Los tickets enviados desde los cPanels de las agencias o registrados por llamada aparecerán aquí.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): 
                            $folio = $t['folio'] ?: ('TK-' . date('Y') . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT));
                            $area = strtoupper($t['area_sistemas'] ?? '');
                            $infoArea = $AREAS_SISTEMAS[$area] ?? ['nombre' => $area, 'icono' => 'bi-ticket-detailed', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.15)', 'border' => 'rgba(56, 189, 248, 0.3)'];
                            $prio = ucfirst(strtolower($t['prioridad'] ?? 'Media'));
                            $estado = ucfirst(strtolower($t['estado'] ?? 'Abierto'));
                            $agenciaTicket = $t['solicitante_agencia'] ?? 'Desconocida';
                            $asignado = $t['asignado_a'] ?? '';

                            $prioClass = 'badge-prio-media';
                            if ($prio === 'Urgente') $prioClass = 'badge-prio-urgente';
                            elseif ($prio === 'Alta') $prioClass = 'badge-prio-alta';
                            elseif ($prio === 'Baja') $prioClass = 'badge-prio-baja';

                            $statusClass = 'badge-status-abierto';
                            if ($estado === 'En proceso') $statusClass = 'badge-status-proceso';
                            elseif ($estado === 'Resuelto') $statusClass = 'badge-status-resuelto';
                            elseif ($estado === 'Cerrado') $statusClass = 'badge-status-cerrado';
                        ?>
                            <tr class="ticket-row" 
                                data-id="<?php echo $t['id']; ?>"
                                data-folio="<?php echo htmlspecialchars($folio); ?>"
                                data-area="<?php echo htmlspecialchars($area); ?>"
                                data-agencia="<?php echo htmlspecialchars($agenciaTicket); ?>"
                                data-estado="<?php echo htmlspecialchars($estado); ?>"
                                data-titulo="<?php echo htmlspecialchars($t['titulo']); ?>"
                                data-solicitante="<?php echo htmlspecialchars($t['solicitante_nombre'] ?? ''); ?>"
                                data-asignado="<?php echo htmlspecialchars($asignado); ?>">
                                
                                <td>
                                    <span class="badge-folio"><?php echo htmlspecialchars($folio); ?></span>
                                    <div class="small text-secondary mt-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-clock me-1"></i><?php echo date('d/m/Y H:i', strtotime($t['creado_en'])); ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge-agencia">
                                        <i class="bi bi-building"></i> <?php echo htmlspecialchars($agenciaTicket); ?>
                                    </span>
                                </td>

                                <td>
                                    <span style="font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 12px; background: <?php echo $infoArea['bg']; ?>; color: <?php echo $infoArea['color']; ?>; border: 1px solid <?php echo $infoArea['border']; ?>; display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="bi <?php echo $infoArea['icono']; ?>"></i> <?php echo $infoArea['nombre']; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-bold text-white mb-1"><?php echo htmlspecialchars($t['titulo']); ?></div>
                                    <div class="text-secondary small text-truncate" style="max-width: 320px;">
                                        <?php echo htmlspecialchars(mb_strimwidth($t['descripcion'], 0, 75, '...')); ?>
                                    </div>
                                    <?php if (!empty($t['archivo_adjunto'])): ?>
                                        <span class="badge bg-dark border border-secondary text-info mt-1" style="font-size: 0.7rem;">
                                            <i class="bi bi-paperclip me-1"></i> Evidencia adjunta
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge rounded-pill <?php echo $prioClass; ?> px-2 py-1" style="font-size: 0.75rem;">
                                        <?php echo $prio; ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge rounded-pill <?php echo $statusClass; ?> px-2 py-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 0.45rem;"></i> <?php echo $estado; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold text-white"><?php echo htmlspecialchars($t['solicitante_nombre'] ?? 'Usuario'); ?></div>
                                    <div class="text-secondary small" style="font-size: 0.75rem;"><?php echo htmlspecialchars($t['solicitante_email'] ?? '---'); ?></div>
                                </td>

                                <td>
                                    <?php if (!empty($asignado)): ?>
                                        <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25 px-2 py-1">
                                            <i class="bi bi-person-fill-check me-1"></i> <?php echo htmlspecialchars($asignado); ?>
                                        </span>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="accion" value="autoasignar_ticket">
                                            <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-outline-warning btn-sm rounded-pill px-2 py-0" style="font-size: 0.75rem;" title="Tomar este ticket y asignármelo">
                                                <i class="bi bi-hand-index-thumb"></i> Asignarme
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <button class="btn btn-sm btn-info rounded-3 px-2 py-1 text-dark fw-bold" onclick="abrirModalAtender(<?php echo htmlspecialchars(json_encode($t)); ?>)" title="Gestionar y resolver ticket">
                                        <i class="bi bi-headset me-1"></i> Atender
                                    </button>
                                    <?php if ($esAdmin): ?>
                                        <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar permanentemente este ticket?');" style="display:inline;">
                                            <input type="hidden" name="accion" value="eliminar_ticket">
                                            <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-1" title="Eliminar ticket">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ============================================== -->
<!-- MODAL: REGISTRAR TICKET MANUAL POR EL AGENTE -->
<!-- ============================================== -->
<div class="modal fade" id="modalNuevoTicket" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="crear_ticket">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-headset text-primary"></i> Registrar Ticket de Soporte (Mesa de Ayuda)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Agencia de Origen -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Agencia / Sucursal de Origen *</label>
                            <select name="solicitante_agencia" class="form-select" required>
                                <?php foreach ($conteoPorAgencia as $nomAg => $c): ?>
                                    <option value="<?php echo htmlspecialchars($nomAg); ?>"><?php echo htmlspecialchars($nomAg); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Área de Sistemas -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Área Destino de Sistemas *</label>
                            <select name="area_sistemas" class="form-select" required>
                                <option value="" disabled selected>-- Selecciona el Área --</option>
                                <?php foreach ($AREAS_SISTEMAS as $areaKey => $areaInfo): ?>
                                    <option value="<?php echo $areaKey; ?>"><?php echo $areaInfo['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Prioridad -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Nivel de Prioridad *</label>
                            <select name="prioridad" class="form-select" required>
                                <option value="Baja">Baja (Requerimiento programado)</option>
                                <option value="Media" selected>Media (Operación normal)</option>
                                <option value="Alta">Alta (Afecta operación de usuario)</option>
                                <option value="Urgente">Urgente (Sistema o red caída)</option>
                            </select>
                        </div>

                        <!-- Agente Asignado -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Asignar a Técnico / Agente</label>
                            <input type="text" name="asignado_a" class="form-control" value="<?php echo htmlspecialchars($nombreUsuario); ?>" placeholder="Nombre del especialista de TI">
                        </div>

                        <!-- Solicitante -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Nombre del Usuario / Solicitante *</label>
                            <input type="text" name="solicitante_nombre" class="form-control" placeholder="Ej: Lic. Carlos Ramírez" required>
                        </div>

                        <!-- Email Solicitante -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Correo o Teléfono del Solicitante</label>
                            <input type="text" name="solicitante_email" class="form-control" placeholder="carlos@divolavilla.com o Ext. 104">
                        </div>

                        <!-- Título -->
                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Título / Asunto Breve *</label>
                            <input type="text" name="titulo" class="form-control" placeholder="Ej: No conecta a SQL Server en taller o Falla switch principal" required>
                        </div>

                        <!-- Descripción -->
                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Descripción Detallada del Requerimiento *</label>
                            <textarea name="descripcion" class="form-control" rows="4" placeholder="Describe los síntomas, equipo afectado y datos importantes del caso..." required></textarea>
                        </div>

                        <!-- Archivo de Evidencia -->
                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Adjuntar Captura o Archivo (Opcional)</label>
                            <input type="file" name="archivo_adjunto" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold">
                        <i class="bi bi-save2 me-1"></i> Registrar Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- MODAL: ATENCIÓN Y SEGUIMIENTO POR EL AGENTE -->
<!-- ============================================== -->
<div class="modal fade" id="modalAtenderTicket" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="actualizar_ticket">
                <input type="hidden" name="ticket_id" id="atenderTicketId">

                <div class="modal-header">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge-folio fs-6" id="atenderFolio">TK-2026-0000</span>
                            <span class="badge-agencia" id="atenderAgencia">VW Divol La Villa</span>
                        </div>
                        <h5 class="modal-title fw-bold mt-2" id="atenderTitulo">Detalle del Ticket</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Solicitud Original -->
                    <div class="p-3 rounded-3 mb-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08);">
                        <div class="d-flex justify-content-between small text-secondary mb-2">
                            <div><i class="bi bi-person me-1"></i> Solicitante: <strong class="text-white" id="atenderSolicitante">---</strong> (<span id="atenderEmail">---</span>)</div>
                            <div><i class="bi bi-diagram-3 me-1"></i> Área: <strong class="text-info" id="atenderArea">---</strong></div>
                        </div>
                        <div class="text-light small" id="atenderDescripcion" style="white-space: pre-line; line-height: 1.6;"></div>

                        <div id="contenedorAdjunto" class="mt-3 pt-2 border-top border-secondary border-opacity-25 d-none">
                            <span class="small text-secondary">Archivo de Evidencia: </span>
                            <a href="#" id="linkAdjunto" target="_blank" class="btn btn-outline-info btn-sm rounded-pill py-0 px-3">
                                <i class="bi bi-paperclip me-1"></i> Ver / Descargar Evidencia
                            </a>
                        </div>
                    </div>

                    <!-- Panel de Gestión del Agente -->
                    <h6 class="fw-bold text-info mb-3"><i class="bi bi-pencil-square me-1"></i> Gestión y Resolución del Agente TI</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Estado del Ticket *</label>
                            <select name="estado" id="atenderEstado" class="form-select" required>
                                <option value="Abierto">Abierto (En espera)</option>
                                <option value="En Proceso">En Proceso (En atención)</option>
                                <option value="Resuelto">Resuelto (Trabajo terminado)</option>
                                <option value="Cerrado">Cerrado (Finalizado)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Prioridad</label>
                            <select name="prioridad" id="atenderPrioridad" class="form-select">
                                <option value="Baja">Baja</option>
                                <option value="Media">Media</option>
                                <option value="Alta">Alta</option>
                                <option value="Urgente">Urgente</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Agente / Especialista Asignado</label>
                            <input type="text" name="asignado_a" id="atenderAsignado" class="form-control" placeholder="Técnico a cargo">
                        </div>

                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Bitácora / Notas de Resolución del Agente</label>
                            <textarea name="notas_resolucion" id="atenderNotas" class="form-control" rows="4" placeholder="Escribe aquí las acciones realizadas, diagnóstico, solución aplicada o motivo de cierre..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-info rounded-3 px-4 fw-bold text-dark">
                        <i class="bi bi-check2-circle me-1"></i> Guardar Cambios de Atención
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let filtroAgenciaActual = 'TODAS';
let filtroAreaActual = 'TODAS';

function filtrarPorAgencia(agencia, elem) {
    filtroAgenciaActual = agencia;
    document.querySelectorAll('#agenciasFilterContainer .agency-pill-btn').forEach(btn => btn.classList.remove('active'));
    elem.classList.add('active');
    filtrarTickets();
}

function filtrarPorArea(area, elem) {
    filtroAreaActual = area;
    document.querySelectorAll('#areasFilterContainer .area-pill').forEach(pill => pill.classList.remove('active'));
    elem.classList.add('active');
    filtrarTickets();
}

function filtrarTickets() {
    const texto = (document.getElementById('busquedaInput').value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.ticket-row');
    let visibles = 0;

    rows.forEach(row => {
        const areaRow = (row.dataset.area || '').toUpperCase();
        const agenciaRow = (row.dataset.agencia || '').toLowerCase();
        const folioRow = (row.dataset.folio || '').toLowerCase();
        const tituloRow = (row.dataset.titulo || '').toLowerCase();
        const solicitanteRow = (row.dataset.solicitante || '').toLowerCase();
        const asignadoRow = (row.dataset.asignado || '').toLowerCase();

        // 1. Filtro por Agencia
        let matchAgencia = true;
        if (filtroAgenciaActual !== 'TODAS') {
            matchAgencia = (agenciaRow === filtroAgenciaActual.toLowerCase());
        }

        // 2. Filtro por Área
        let matchArea = true;
        if (filtroAreaActual !== 'TODAS') {
            matchArea = (areaRow === filtroAreaActual);
        }

        // 3. Filtro por Búsqueda de texto
        let matchTexto = true;
        if (texto !== '') {
            matchTexto = folioRow.includes(texto) ||
                         tituloRow.includes(texto) ||
                         solicitanteRow.includes(texto) ||
                         asignadoRow.includes(texto) ||
                         agenciaRow.includes(texto);
        }

        if (matchAgencia && matchArea && matchTexto) {
            row.style.display = '';
            visibles++;
        } else {
            row.style.display = 'none';
        }
    });

    const filaSin = document.getElementById('filaSinTickets');
    if (filaSin) {
        filaSin.style.display = (visibles === 0) ? '' : 'none';
    }
}

function limpiarBusqueda() {
    document.getElementById('busquedaInput').value = '';
    filtrarTickets();
}

function abrirModalAtender(ticket) {
    document.getElementById('atenderTicketId').value = ticket.id;
    document.getElementById('atenderFolio').innerText = ticket.folio || ('TK-' + ticket.id);
    document.getElementById('atenderAgencia').innerText = ticket.solicitante_agencia || 'Agencia General';
    document.getElementById('atenderTitulo').innerText = ticket.titulo || 'Sin título';
    document.getElementById('atenderSolicitante').innerText = ticket.solicitante_nombre || 'Usuario';
    document.getElementById('atenderEmail').innerText = ticket.solicitante_email || 'Sin correo';
    document.getElementById('atenderArea').innerText = ticket.area_sistemas || 'SISTEMAS';
    document.getElementById('atenderDescripcion').innerText = ticket.descripcion || '';
    document.getElementById('atenderEstado').value = ticket.estado || 'Abierto';
    document.getElementById('atenderPrioridad').value = ticket.prioridad || 'Media';
    document.getElementById('atenderAsignado').value = ticket.asignado_a || '';
    document.getElementById('atenderNotas').value = ticket.notas_resolucion || '';

    const contenedorAdj = document.getElementById('contenedorAdjunto');
    const linkAdj = document.getElementById('linkAdjunto');
    if (ticket.archivo_adjunto && ticket.archivo_adjunto !== '') {
        linkAdj.href = ticket.archivo_adjunto;
        contenedorAdj.classList.remove('d-none');
    } else {
        contenedorAdj.classList.add('d-none');
    }

    const modal = new bootstrap.Modal(document.getElementById('modalAtenderTicket'));
    modal.show();
}
</script>
</body>
</html>
