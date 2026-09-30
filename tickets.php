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

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

// Cargar permisos y asegurar tablas
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Usuario');
$agenciaUsuario = $_SESSION['agencia'] ?? 'Grupo Huerta';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
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

$mensaje = '';
$error = '';

/**
 * Envía el ticket en tiempo real al Portal Maestro Central de la Dirección de Sistemas
 */
function enviarTicketACentral($datosTicket, $archivoLocal = null) {
    $urlCentral = 'https://portal.grupohuerta.mx/api_receptor_tickets.php';
    $token = getenv('TOKEN_SECRETO') ?: 'GedasDivolavilla2026!';

    $payload = [
        'token'              => $token,
        'agencia'            => $datosTicket['agencia'] ?? 'VW Divol La Villa',
        'area_sistemas'      => $datosTicket['area_sistemas'] ?? 'INFRAESTRUCTURA',
        'titulo'             => $datosTicket['titulo'] ?? '',
        'descripcion'        => $datosTicket['descripcion'] ?? '',
        'prioridad'          => $datosTicket['prioridad'] ?? 'Media',
        'solicitante_nombre' => $datosTicket['solicitante_nombre'] ?? 'Usuario',
        'solicitante_email'  => $datosTicket['solicitante_email'] ?? ''
    ];

    if ($archivoLocal && file_exists(__DIR__ . '/' . $archivoLocal)) {
        $payload['archivo_base64'] = base64_encode(file_get_contents(__DIR__ . '/' . $archivoLocal));
        $payload['archivo_nombre'] = basename($archivoLocal);
    }

    $ch = curl_init($urlCentral);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: PortalAgencia/2.0'
        ]
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res !== false && $httpCode === 200) {
        $dec = json_decode($res, true);
        if ($dec && ($dec['status'] ?? '') === 'ok') {
            return ['ok' => true, 'folio' => $dec['folio'] ?? null, 'datos' => $dec];
        }
    }
    return ['ok' => false, 'error' => $err ?: "HTTP $httpCode"];
}

// ====================================================
// MANEJO DE ACCIONES POST (CREAR, EDITAR, ELIMINAR)
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. Crear Nuevo Ticket
    if ($accion === 'crear_ticket') {
        $area = trim($_POST['area_sistemas'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad = trim($_POST['prioridad'] ?? 'Media');
        $solicitanteNombre = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
        $solicitanteEmail = trim($_POST['solicitante_email'] ?? ($_SESSION['usuario_email'] ?? ''));
        $solicitanteAgencia = trim($_POST['solicitante_agencia'] ?? $agenciaUsuario);

        if (empty($titulo) || empty($descripcion) || empty($area)) {
            $error = "Por favor completa el Área de Sistemas, Título y Descripción del ticket.";
        } else {
            // Manejo de archivo adjunto (opcional)
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
                // Enviar primero a la Dirección Central de Sistemas (portal.grupohuerta.mx)
                $resCentral = enviarTicketACentral([
                    'agencia'            => $solicitanteAgencia,
                    'area_sistemas'      => $area,
                    'titulo'             => $titulo,
                    'descripcion'        => $descripcion,
                    'prioridad'          => $prioridad,
                    'solicitante_nombre' => $solicitanteNombre,
                    'solicitante_email'  => $solicitanteEmail
                ], $archivoUrl);

                $folio = null;
                if ($resCentral['ok'] && !empty($resCentral['folio'])) {
                    $folio = $resCentral['folio'];
                    $mensaje = "✅ ¡Ticket con Folio oficial <strong>$folio</strong> enviado con éxito a la Dirección Central de Sistemas (portal.grupohuerta.mx)! Un agente de TI atenderá tu solicitud a la brevedad.";
                } else {
                    $stmtCount = $pdo->query("SELECT COUNT(*) FROM tickets_soporte");
                    $conteo = $stmtCount ? (int)$stmtCount->fetchColumn() : 0;
                    $folio = 'TK-' . date('Y') . '-' . str_pad($conteo + 1, 4, '0', STR_PAD_LEFT);
                    $mensaje = "ℹ️ Ticket registrado en el portal de la agencia con Folio <strong>$folio</strong>.";
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO tickets_soporte 
                    (folio, area_sistemas, titulo, descripcion, prioridad, estado, solicitante_id, solicitante_nombre, solicitante_email, solicitante_agencia, archivo_adjunto, creado_en)
                    VALUES (?, ?, ?, ?, ?, 'Abierto', ?, ?, ?, ?, ?, datetime('now', 'localtime'))
                ");
                
                // Fallback para MySQL vs SQLite en la fecha
                $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
                if ($driver !== 'sqlite') {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO tickets_soporte 
                        (folio, area_sistemas, titulo, descripcion, prioridad, estado, solicitante_id, solicitante_nombre, solicitante_email, solicitante_agencia, archivo_adjunto, creado_en)
                        VALUES (?, ?, ?, ?, ?, 'Abierto', ?, ?, ?, ?, ?, NOW())
                    ");
                }

                $stmtIns->execute([
                    $folio,
                    $area,
                    $titulo,
                    $descripcion,
                    $prioridad,
                    $usuarioId,
                    $solicitanteNombre,
                    $solicitanteEmail,
                    $solicitanteAgencia,
                    $archivoUrl
                ]);
            } catch (Throwable $t) {
                $error = "Error al registrar el ticket: " . $t->getMessage();
            }
        }
    }

    // 2. Actualizar Estado / Seguimiento de Ticket
    elseif ($accion === 'actualizar_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $nuevoEstado = trim($_POST['estado'] ?? 'Abierto');
        $asignadoA = trim($_POST['asignado_a'] ?? '');
        $notas = trim($_POST['notas_resolucion'] ?? '');

        if ($ticketId > 0) {
            try {
                $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
                $sqlFecha = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";

                $stmtUp = $pdo->prepare("
                    UPDATE tickets_soporte 
                    SET estado = ?, asignado_a = ?, notas_resolucion = ?, actualizado_en = $sqlFecha
                    WHERE id = ?
                ");
                $stmtUp->execute([$nuevoEstado, $asignadoA, $notas, $ticketId]);

                $mensaje = "El ticket ha sido actualizado a estado <strong>" . htmlspecialchars($nuevoEstado) . "</strong>.";
            } catch (Throwable $t) {
                $error = "Error al actualizar ticket: " . $t->getMessage();
            }
        }
    }

    // 3. Eliminar Ticket (Solo Administradores)
    elseif ($accion === 'eliminar_ticket' && $esAdmin) {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        if ($ticketId > 0) {
            try {
                $stmtDel = $pdo->prepare("DELETE FROM tickets_soporte WHERE id = ?");
                $stmtDel->execute([$ticketId]);
                $mensaje = "Ticket eliminado correctamente.";
            } catch (Throwable $t) {
                $error = "Error al eliminar ticket: " . $t->getMessage();
            }
        }
    }
}

// ====================================================
// EXPORTACIÓN A EXCEL (.XLS CON MEMBRETE Y LOGO OFICIAL)
// ====================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'exportar_excel') {
    $agenciaInfo = obtenerDatosAgenciaExcel($pdo, $agenciaUsuario);
    $agenciaLimpia = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agenciaInfo['nombre']);
    $fileName = "Tickets_Sistemas_{$agenciaLimpia}_" . date('Ymd_His') . ".xls";

    $filasDb = [];
    if ($pdo) {
        try {
            $stmtExp = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
            $filasDb = $stmtExp ? $stmtExp->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}
    }

    $columnasExcel = [
        ['label' => 'Folio', 'width' => '90px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Área de Sistemas', 'width' => '150px', 'align' => 'left'],
        ['label' => 'Título / Requerimiento', 'width' => '220px', 'align' => 'left'],
        ['label' => 'Prioridad', 'width' => '100px', 'align' => 'center'],
        ['label' => 'Estado', 'width' => '110px', 'align' => 'center'],
        ['label' => 'Solicitante', 'width' => '170px', 'align' => 'left'],
        ['label' => 'Correo Solicitante', 'width' => '190px', 'align' => 'left', 'is_text' => true],
        ['label' => 'Agencia / Sucursal', 'width' => '140px', 'align' => 'left'],
        ['label' => 'Asignado a', 'width' => '150px', 'align' => 'left'],
        ['label' => 'Fecha de Creación', 'width' => '130px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Notas / Resolución', 'width' => '240px', 'align' => 'left']
    ];

    $filasExcel = [];
    foreach ($filasDb as $r) {
        $filasExcel[] = [
            ['val' => '<b>' . htmlspecialchars($r['folio'] ?? ('#TK-' . $r['id'])) . '</b>', 'align' => 'center', 'is_text' => true],
            ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($r['area_sistemas'] ?? '') . '</span>', 'align' => 'left'],
            ['val' => '<strong>' . htmlspecialchars($r['titulo'] ?? '') . '</strong>', 'align' => 'left'],
            ['val' => htmlspecialchars($r['prioridad'] ?? 'Media'), 'align' => 'center'],
            ['val' => '<span class="badge-pill ' . (strtolower($r['estado'] ?? '') === 'resuelto' || strtolower($r['estado'] ?? '') === 'cerrado' ? 'badge-status-ok' : 'badge-status-warn') . '">' . htmlspecialchars($r['estado'] ?? 'Abierto') . '</span>', 'align' => 'center'],
            ['val' => htmlspecialchars($r['solicitante_nombre'] ?? '---'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['solicitante_email'] ?? '---'), 'align' => 'left', 'is_text' => true],
            ['val' => htmlspecialchars($r['solicitante_agencia'] ?? '---'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['asignado_a'] ?? 'Sin asignar'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['creado_en'] ?? '---'), 'align' => 'center', 'is_text' => true],
            ['val' => htmlspecialchars($r['notas_resolucion'] ?? '---'), 'align' => 'left']
        ];
    }

    descargarExcelConDiseno(
        'TICKETS DE SOPORTE &bull; DIRECCIÓN DE SISTEMAS',
        'Control y Gestión Integral de Requerimientos e Incidencias TI',
        $columnasExcel,
        $filasExcel,
        $agenciaInfo,
        $fileName,
        'Tickets Sistemas'
    );
}

// ====================================================
// CONSULTA DE TICKETS Y MÉTRICAS
// ====================================================
$tickets = [];
$totalTickets = 0;
$totalAbiertos = 0;
$totalEnProceso = 0;
$totalResueltos = 0;

if ($pdo) {
    try {
        $stmtAll = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
        $tickets = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];

        $totalTickets = count($tickets);
        foreach ($tickets as $t) {
            $estLower = strtolower($t['estado'] ?? '');
            if ($estLower === 'abierto') $totalAbiertos++;
            elseif ($estLower === 'en proceso') $totalEnProceso++;
            elseif ($estLower === 'resuelto' || $estLower === 'cerrado') $totalResueltos++;
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets Soporte Dirección Sistemas - Portal Grupo Huerta</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
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
            background: rgba(6, 19, 37, 0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 35px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .main-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 30px 35px;
        }

        .kpi-card {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 18px;
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
        .kpi-blue { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .kpi-green { background: rgba(34, 197, 94, 0.15); color: #4ade80; }

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
        .search-input::placeholder {
            color: #64748b;
        }

        .filter-pill {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            border-radius: 30px;
            padding: 6px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .filter-pill:hover, .filter-pill.active {
            background: #0284c7;
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
        }

        .tickets-table-container {
            background: #0b1a30;
            border: 1px solid rgba(56, 189, 248, 0.2);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.5);
        }

        .table-custom {
            margin-bottom: 0;
            color: #ffffff !important;
            width: 100%;
            --bs-table-bg: transparent !important;
            --bs-table-accent-bg: transparent !important;
            --bs-table-striped-bg: transparent !important;
            --bs-table-hover-bg: rgba(56, 189, 248, 0.08) !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }
        .table-custom thead th {
            background: #08162b !important;
            color: #38bdf8 !important;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            padding: 16px 20px;
            border-bottom: 2px solid rgba(56, 189, 248, 0.3) !important;
            white-space: nowrap;
        }
        .table-custom tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.06) !important;
            transition: all 0.15s ease;
        }
        .table-custom tbody tr:nth-child(odd) td {
            background-color: #0b1c34 !important;
            color: #ffffff !important;
        }
        .table-custom tbody tr:nth-child(even) td {
            background-color: #0e223f !important;
            color: #ffffff !important;
        }
        .table-custom tbody tr:hover td {
            background-color: #14325c !important;
            color: #ffffff !important;
        }
        .table-custom tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            font-size: 0.92rem;
            box-shadow: none !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06) !important;
        }

        .area-badge {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 5px 12px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25);
        }

        .status-badge {
            font-size: 0.76rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .status-abierto { background: rgba(234, 179, 8, 0.2); color: #fde047; border: 1px solid rgba(234, 179, 8, 0.4); }
        .status-proceso { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); }
        .status-resuelto { background: rgba(34, 197, 94, 0.2); color: #86efac; border: 1px solid rgba(34, 197, 94, 0.4); }
        .status-cerrado { background: rgba(148, 163, 184, 0.2); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.35); }

        .prio-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }
        .prio-baja { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; }
        .prio-media { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
        .prio-alta { background: rgba(249, 115, 22, 0.2); color: #fb923c; }
        .prio-urgente { background: rgba(244, 63, 94, 0.25); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.4); }

        .btn-action-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #cbd5e1;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .btn-action-icon:hover {
            background: rgba(56, 189, 248, 0.25);
            border-color: #38bdf8;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .modal-content {
            background-color: #0b1a30;
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: #ffffff;
            border-radius: 18px;
        }
        .modal-header, .modal-footer {
            border-color: rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>

<!-- Navbar Principal -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm rounded-3 px-3 text-white border-opacity-25" title="Regresar al Menú Principal">
            <i class="bi bi-arrow-left me-1"></i> Menú
        </a>
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-ticket-detailed-fill fs-4" style="color: #38bdf8;"></i>
            <span class="fw-bold tracking-wide">TICKETS SOPORTE <span class="text-secondary fw-normal">| Dirección de Sistemas</span></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="text-end d-none d-md-block">
            <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.75rem;"><?php echo strtoupper($rolActual); ?> &bull; <?php echo htmlspecialchars($agenciaUsuario); ?></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Salir
        </a>
    </div>
</div>

<div class="main-container">

    <!-- Mensajes de Notificación -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border-left: 4px solid #22c55e !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border-left: 4px solid #ef4444 !important;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Tarjetas de Métricas (KPIs) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-cyan">
                    <i class="bi bi-collection-fill"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">TOTAL TICKETS</div>
                    <div class="fs-3 fw-bold text-white"><?php echo $totalTickets; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-yellow">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">EN ESPERA / ABIERTOS</div>
                    <div class="fs-3 fw-bold text-warning"><?php echo $totalAbiertos; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-blue">
                    <i class="bi bi-gear-wide-connected"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">EN PROCESO</div>
                    <div class="fs-3 fw-bold text-info"><?php echo $totalEnProceso; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-green">
                    <i class="bi bi-check2-all"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">RESUELTOS / CERRADOS</div>
                    <div class="fs-3 fw-bold text-success"><?php echo $totalResueltos; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Barra de Búsqueda, Filtros y Botones -->
    <div class="row g-3 align-items-center mb-4">
        <div class="col-md-5 col-lg-4">
            <div class="search-box-wrapper">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="buscadorTickets" class="search-input" placeholder="Buscar por folio, asunto, solicitante..." onkeyup="filtrarTicketsEnVivo()">
                <button type="button" class="btn btn-link btn-sm text-secondary p-0" onclick="limpiarBuscador()" title="Limpiar">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        </div>

        <div class="col-md-7 col-lg-8 d-flex justify-content-md-end gap-2 flex-wrap">
            <a href="tickets.php?accion=exportar_excel" class="btn btn-success btn-sm rounded-3 px-3 fw-semibold d-flex align-items-center gap-1.5 shadow-sm" style="background: #16a34a; border-color: #16a34a;" title="Exportar a Microsoft Excel">
                <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
            </a>
            <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold d-flex align-items-center gap-1.5 shadow-sm" style="background: #0284c7; border-color: #0284c7;" data-bs-toggle="modal" data-bs-target="#modalNuevoTicket">
                <i class="bi bi-plus-lg"></i> Levantar Nuevo Ticket
            </button>
        </div>

        <!-- Filtros de Áreas de Sistemas -->
        <div class="col-12 mt-2">
            <div class="d-flex align-items-center gap-2 overflow-x-auto pb-1" id="pillsAreasSistemas">
                <span class="filter-pill active" onclick="filtrarPorArea('TODAS', this)">
                    <i class="bi bi-grid-fill"></i> Todas las Áreas
                </span>
                <?php foreach ($AREAS_SISTEMAS as $claveArea => $infoArea): ?>
                    <span class="filter-pill" onclick="filtrarPorArea('<?php echo $claveArea; ?>', this)">
                        <i class="bi <?php echo $infoArea['icono']; ?>" style="color: <?php echo $infoArea['color']; ?>;"></i> <?php echo $claveArea; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tabla Principal de Tickets -->
    <div class="tickets-table-container">
        <div class="table-responsive">
            <table class="table table-custom align-middle" id="tablaTickets">
                <thead>
                    <tr>
                        <th style="width: 10%;">Folio</th>
                        <th style="width: 16%;">Área Destino</th>
                        <th style="width: 28%;">Título / Requerimiento</th>
                        <th style="width: 11%;">Prioridad</th>
                        <th style="width: 12%;">Estado</th>
                        <th style="width: 15%;">Solicitante / Agencia</th>
                        <th class="text-end" style="width: 8%;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tbodyTickets">
                    <?php if (empty($tickets)): ?>
                        <tr id="filaSinTickets">
                            <td colspan="7" class="text-center py-5 text-secondary">
                                <i class="bi bi-ticket-perforated fs-1 d-block mb-2 text-muted"></i>
                                <div class="fw-semibold">No hay tickets registrados en el sistema.</div>
                                <div class="small text-muted mt-1">Presiona <strong>"Levantar Nuevo Ticket"</strong> para solicitar soporte a una de las áreas de Sistemas.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): 
                            $areaKey = strtoupper(trim($t['area_sistemas'] ?? 'DESARROLLO'));
                            $areaConf = $AREAS_SISTEMAS[$areaKey] ?? [
                                'nombre' => $areaKey, 'icono' => 'bi-gear-fill', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.15)', 'border' => 'rgba(56, 189, 248, 0.35)'
                            ];

                            $prioLower = strtolower($t['prioridad'] ?? 'media');
                            $prioClass = 'prio-media';
                            if ($prioLower === 'urgente') $prioClass = 'prio-urgente';
                            elseif ($prioLower === 'alta') $prioClass = 'prio-alta';
                            elseif ($prioLower === 'baja') $prioClass = 'prio-baja';

                            $estLower = strtolower($t['estado'] ?? 'abierto');
                            $estClass = 'status-abierto';
                            if ($estLower === 'en proceso') $estClass = 'status-proceso';
                            elseif ($estLower === 'resuelto') $estClass = 'status-resuelto';
                            elseif ($estLower === 'cerrado') $estClass = 'status-cerrado';
                        ?>
                            <tr class="ticket-row" 
                                data-folio="<?php echo htmlspecialchars(mb_strtolower($t['folio'] ?? '')); ?>"
                                data-area="<?php echo htmlspecialchars($areaKey); ?>"
                                data-titulo="<?php echo htmlspecialchars(mb_strtolower($t['titulo'] ?? '')); ?>"
                                data-solicitante="<?php echo htmlspecialchars(mb_strtolower($t['solicitante_nombre'] ?? '')); ?>"
                                data-estado="<?php echo htmlspecialchars($estLower); ?>">
                                
                                <!-- Folio -->
                                <td>
                                    <span class="font-monospace fw-bold text-info">
                                        <?php echo htmlspecialchars($t['folio'] ?? ('#TK-' . $t['id'])); ?>
                                    </span>
                                </td>

                                <!-- Área Destino -->
                                <td>
                                    <span class="area-badge" style="background: <?php echo $areaConf['bg']; ?>; color: <?php echo $areaConf['color']; ?>; border: 1px solid <?php echo $areaConf['border']; ?>;">
                                        <i class="bi <?php echo $areaConf['icono']; ?>"></i> <?php echo $areaConf['nombre']; ?>
                                    </span>
                                </td>

                                <!-- Título / Asunto -->
                                <td>
                                    <div class="fw-bold text-white fs-6">
                                        <?php echo htmlspecialchars($t['titulo']); ?>
                                        <?php if (!empty($t['archivo_adjunto'])): ?>
                                            <i class="bi bi-paperclip text-info ms-1" title="Contiene archivo adjunto"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-secondary small text-truncate" style="max-width: 380px;">
                                        <?php echo htmlspecialchars(mb_substr($t['descripcion'], 0, 90)); ?><?php echo mb_strlen($t['descripcion']) > 90 ? '...' : ''; ?>
                                    </div>
                                </td>

                                <!-- Prioridad -->
                                <td>
                                    <span class="prio-badge <?php echo $prioClass; ?>">
                                        <?php echo htmlspecialchars($t['prioridad'] ?? 'Media'); ?>
                                    </span>
                                </td>

                                <!-- Estado -->
                                <td>
                                    <span class="status-badge <?php echo $estClass; ?>">
                                        <i class="bi bi-circle-fill" style="font-size: 0.45rem;"></i>
                                        <?php echo htmlspecialchars($t['estado'] ?? 'Abierto'); ?>
                                    </span>
                                </td>

                                <!-- Solicitante y Sucursal -->
                                <td>
                                    <div class="fw-semibold text-white small"><?php echo htmlspecialchars($t['solicitante_nombre'] ?? '---'); ?></div>
                                    <div class="text-secondary" style="font-size: 0.75rem;">
                                        <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($t['solicitante_agencia'] ?? 'General'); ?>
                                    </div>
                                </td>

                                <!-- Acciones -->
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1.5">
                                        <!-- Ver Detalle / Seguimiento -->
                                        <button type="button" class="btn-action-icon text-info" onclick="abrirModalDetalleTicket(<?php echo htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8'); ?>)" title="Ver Detalle y Dar Seguimiento">
                                            <i class="bi bi-eye-fill"></i>
                                        </button>
                                        
                                        <?php if ($esAdmin): ?>
                                            <!-- Eliminar Ticket -->
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que deseas eliminar este ticket definitivamente?');">
                                                <input type="hidden" name="accion" value="eliminar_ticket">
                                                <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                                <button type="submit" class="btn-action-icon text-danger" title="Eliminar Ticket">
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

<!-- ====================================================
     MODAL: LEVANTAR NUEVO TICKET
     ==================================================== -->
<div class="modal fade" id="modalNuevoTicket" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="kpi-icon kpi-cyan" style="width: 40px; height: 40px; font-size: 1.2rem;">
                        <i class="bi bi-ticket-detailed-fill"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Levantar Nuevo Ticket de Soporte</h5>
                        <small class="text-secondary">Dirección de Sistemas - Requerimientos e Incidencias</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="crear_ticket">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Área de Sistemas Destinataria -->
                        <div class="col-md-7">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Área de Sistemas Asignada <span class="text-danger">*</span></label>
                            <select name="area_sistemas" class="form-select rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" required>
                                <option value="" disabled selected>Selecciona el área de soporte...</option>
                                <?php foreach ($AREAS_SISTEMAS as $k => $info): ?>
                                    <option value="<?php echo $k; ?>"><?php echo $k; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Prioridad -->
                        <div class="col-md-5">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Nivel de Prioridad <span class="text-danger">*</span></label>
                            <select name="prioridad" class="form-select rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" required>
                                <option value="Baja">Baja (Requerimiento general)</option>
                                <option value="Media" selected>Media (Operación normal)</option>
                                <option value="Alta">Alta (Afecta operación parcial)</option>
                                <option value="Urgente">Urgente (Bloqueo crítico)</option>
                            </select>
                        </div>

                        <!-- Título / Asunto -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Título / Asunto Breve <span class="text-danger">*</span></label>
                            <input type="text" name="titulo" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Ej. Falla en enlace de Internet / Acceso a base de datos / Publicación en Redes..." required>
                        </div>

                        <!-- Descripción Detallada -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Descripción Detallada del Requerimiento o Falla <span class="text-danger">*</span></label>
                            <textarea name="descripcion" rows="4" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Describe detalladamente qué necesitas o qué error ocurre..." required></textarea>
                        </div>

                        <!-- Archivo Adjunto -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Adjuntar Archivo o Captura (Opcional)</label>
                            <input type="file" name="archivo_adjunto" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
                            <div class="form-text text-secondary small">Formatos permitidos: Imágenes (PNG, JPG), PDF, Documentos Word/Excel.</div>
                        </div>

                        <!-- Datos del Solicitante Prellenados -->
                        <div class="col-md-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Solicitante</label>
                            <input type="text" name="solicitante_nombre" value="<?php echo htmlspecialchars($nombreUsuario); ?>" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #94a3b8;" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Sucursal / Agencia</label>
                            <input type="text" name="solicitante_agencia" value="<?php echo htmlspecialchars($agenciaUsuario); ?>" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #94a3b8;" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Correo de Contacto</label>
                            <input type="email" name="solicitante_email" value="<?php echo htmlspecialchars($_SESSION['usuario_email'] ?? ''); ?>" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #94a3b8;" placeholder="correo@empresa.com">
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm text-white rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-3 px-4 fw-bold shadow-sm" style="background: #0284c7; border-color: #0284c7;">
                        <i class="bi bi-send-fill me-1"></i> Registrar Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====================================================
     MODAL: DETALLE Y SEGUIMIENTO DE TICKET
     ==================================================== -->
<div class="modal fade" id="modalDetalleTicket" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header py-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="font-monospace fs-5 fw-bold text-info" id="viewFolio">TK-0000</span>
                    <span id="viewAreaBadge" class="area-badge">ÁREA</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" value="actualizar_ticket">
                <input type="hidden" name="ticket_id" id="editTicketId" value="0">

                <div class="modal-body p-4">
                    <!-- Título -->
                    <h5 class="fw-bold text-white mb-2" id="viewTitulo">Título del Ticket</h5>

                    <!-- Metadatos -->
                    <div class="p-3 rounded-3 mb-3" style="background: rgba(6, 19, 37, 0.7); border: 1px solid rgba(255, 255, 255, 0.08);">
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Solicitante</div>
                                <div class="text-white small fw-semibold" id="viewSolicitante">--</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Agencia / Sucursal</div>
                                <div class="text-white small fw-semibold" id="viewAgencia">--</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Prioridad</div>
                                <div class="small fw-bold" id="viewPrioridad">--</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Fecha Creación</div>
                                <div class="text-secondary small font-monospace" id="viewFecha">--</div>
                            </div>
                        </div>
                    </div>

                    <!-- Descripción -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold text-uppercase mb-1">Descripción del Requerimiento</label>
                        <div class="p-3 rounded-3 text-white small" id="viewDescripcion" style="background: #061325; border: 1px solid rgba(255, 255, 255, 0.08); white-space: pre-line; min-height: 80px;">
                            --
                        </div>
                    </div>

                    <!-- Archivo Adjunto -->
                    <div class="mb-3" id="viewContenedorAdjunto" style="display: none;">
                        <label class="form-label text-secondary small fw-bold text-uppercase mb-1">Evidencia / Archivo Adjunto</label>
                        <div>
                            <a href="#" id="viewArchivoLink" target="_blank" class="btn btn-outline-info btn-sm rounded-3">
                                <i class="bi bi-paperclip me-1"></i> Visualizar Archivo Adjunto
                            </a>
                        </div>
                    </div>

                    <hr class="border-secondary border-opacity-25 my-3">

                    <!-- SECCIÓN DE SEGUIMIENTO Y RESOLUCIÓN -->
                    <h6 class="fw-bold text-info mb-3"><i class="bi bi-arrow-repeat me-1"></i> Actualizar Estatus y Seguimiento</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Estado del Ticket</label>
                            <select name="estado" id="editEstado" class="form-select rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;">
                                <option value="Abierto">Abierto (En espera)</option>
                                <option value="En Proceso">En Proceso (Trabajando)</option>
                                <option value="Resuelto">Resuelto (Solución entregada)</option>
                                <option value="Cerrado">Cerrado (Finalizado)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Técnico / Responsable Asignado</label>
                            <input type="text" name="asignado_a" id="editAsignadoA" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Ej. Ing. de Desarrollo / Soporte TI...">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Notas de Seguimiento / Solución Aplicada</label>
                            <textarea name="notas_resolucion" id="editNotas" rows="3" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Escribe aquí los avances, respuesta o solución dada al ticket..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm text-white rounded-3 px-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-success btn-sm rounded-3 px-4 fw-bold shadow-sm" style="background: #16a34a; border-color: #16a34a;">
                        <i class="bi bi-check2-circle me-1"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let areaFiltroActual = 'TODAS';

    function filtrarPorArea(area, el) {
        areaFiltroActual = area.toUpperCase();
        document.querySelectorAll('#pillsAreasSistemas .filter-pill').forEach(p => p.classList.remove('active'));
        if (el) el.classList.add('active');
        filtrarTicketsEnVivo();
    }

    function filtrarTicketsEnVivo() {
        const query = (document.getElementById('buscadorTickets').value || '').trim().toLowerCase();
        const filas = document.querySelectorAll('#tbodyTickets .ticket-row');
        let visibles = 0;

        filas.forEach(row => {
            const folio = row.getAttribute('data-folio') || '';
            const area = (row.getAttribute('data-area') || '').toUpperCase();
            const titulo = row.getAttribute('data-titulo') || '';
            const solicitante = row.getAttribute('data-solicitante') || '';

            const coincideArea = (areaFiltroActual === 'TODAS' || area === areaFiltroActual);
            const coincideTexto = (query === '' || folio.includes(query) || titulo.includes(query) || solicitante.includes(query) || area.toLowerCase().includes(query));

            if (coincideArea && coincideTexto) {
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

    function limpiarBuscador() {
        document.getElementById('buscadorTickets').value = '';
        filtrarTicketsEnVivo();
    }

    function abrirModalDetalleTicket(ticket) {
        if (!ticket) return;

        document.getElementById('editTicketId').value = ticket.id;
        document.getElementById('viewFolio').textContent = ticket.folio || ('#TK-' + ticket.id);
        document.getElementById('viewTitulo').textContent = ticket.titulo || 'Sin Título';
        document.getElementById('viewSolicitante').textContent = ticket.solicitante_nombre || '---';
        document.getElementById('viewAgencia').textContent = ticket.solicitante_agencia || 'General';
        document.getElementById('viewPrioridad').textContent = ticket.prioridad || 'Media';
        document.getElementById('viewFecha').textContent = ticket.creado_en || '---';
        document.getElementById('viewDescripcion').textContent = ticket.descripcion || 'Sin descripción';
        
        document.getElementById('editEstado').value = ticket.estado || 'Abierto';
        document.getElementById('editAsignadoA').value = ticket.asignado_a || '';
        document.getElementById('editNotas').value = ticket.notas_resolucion || '';

        const badgeEl = document.getElementById('viewAreaBadge');
        if (badgeEl) {
            badgeEl.textContent = ticket.area_sistemas || 'GENERAL';
        }

        const contenedorAdjunto = document.getElementById('viewContenedorAdjunto');
        const linkAdjunto = document.getElementById('viewArchivoLink');
        if (ticket.archivo_adjunto && ticket.archivo_adjunto.trim() !== '') {
            contenedorAdjunto.style.display = 'block';
            linkAdjunto.href = ticket.archivo_adjunto;
        } else {
            contenedorAdjunto.style.display = 'none';
        }

        const modal = new bootstrap.Modal(document.getElementById('modalDetalleTicket'));
        modal.show();
    }
</script>
</body>
</html>
