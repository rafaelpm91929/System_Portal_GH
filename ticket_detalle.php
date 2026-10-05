<?php
/**
 * PORTAL DE AGENCIA - DETALLE Y SEGUIMIENTO DE TICKET (NUEVA PESTAÑA COMPLETA)
 * Permite visualizar el requerimiento completo, hilo de respuestas y enviar nuevos mensajes al ticket.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

requerirPermiso('tickets', 'puede_ver');

$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Usuario');
$loginUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? '');
$emailUsuario = $_SESSION['usuario_email'] ?? '';
$agenciaUsuario = $_SESSION['agencia'] ?? 'Divol La Villa';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

if ($pdo) {
    asegurarTablaTickets($pdo);
    asegurarTablaTicketsMensajes($pdo);
    if (empty($loginUsuario) || empty($emailUsuario)) {
        try {
            $stmtUInfo = $pdo->prepare("SELECT usuario, email FROM usuarios WHERE id = ?");
            $stmtUInfo->execute([$usuarioId]);
            $rU = $stmtUInfo->fetch(PDO::FETCH_ASSOC);
            if ($rU) {
                if (empty($loginUsuario) && !empty($rU['usuario'])) {
                    $loginUsuario = $rU['usuario'];
                    $_SESSION['usuario_login'] = $loginUsuario;
                }
                if (empty($emailUsuario) && !empty($rU['email'])) {
                    $emailUsuario = $rU['email'];
                    $_SESSION['usuario_email'] = $emailUsuario;
                }
            }
        } catch (Throwable $e) {}
    }
}

// Obtener ID o Folio del Ticket
$ticketId = intval($_GET['id'] ?? 0);
$ticketFolio = trim($_GET['folio'] ?? '');

$ticket = null;
if ($pdo) {
    if ($ticketId > 0) {
        $stmtT = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
        $stmtT->execute([$ticketId]);
        $ticket = $stmtT->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($ticketFolio)) {
        $stmtT = $pdo->prepare("SELECT * FROM tickets_soporte WHERE folio = ?");
        $stmtT->execute([$ticketFolio]);
        $ticket = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($ticket) {
            $ticketId = (int)$ticket['id'];
        }
    }
}

if (!$ticket) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Ticket no encontrado</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head><body style='background:#061325;color:#fff;padding:50px;text-align:center;'><h2>Ticket no encontrado</h2><p>El ticket solicitado no existe o fue eliminado.</p><a href='tickets.php' class='btn btn-primary'>Volver al Listado</a></body></html>";
    exit;
}

// Validación de seguridad / Aislamiento por usuario
if (!$esAdmin) {
    $pertenece = false;
    if (!empty($ticket['solicitante_id']) && (int)$ticket['solicitante_id'] === (int)$usuarioId) {
        $pertenece = true;
    }
    if (!empty($ticket['solicitante_usuario']) && !empty($loginUsuario) && strtolower($ticket['solicitante_usuario']) === strtolower($loginUsuario)) {
        $pertenece = true;
    }
    if (!empty($ticket['solicitante_email']) && !empty($emailUsuario) && strtolower($ticket['solicitante_email']) === strtolower($emailUsuario)) {
        $pertenece = true;
    }
    if (!empty($ticket['solicitante_nombre']) && !empty($nombreUsuario) && strtolower($ticket['solicitante_nombre']) === strtolower($nombreUsuario)) {
        $pertenece = true;
    }

    if (!$pertenece) {
        echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Acceso no autorizado</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head><body style='background:#061325;color:#fff;padding:50px;text-align:center;'><h2 class='text-danger'>Acceso Restringido</h2><p>Este ticket pertenece a otro usuario de la agencia.</p><a href='tickets.php' class='btn btn-outline-info'>Volver a mis tickets</a></body></html>";
        exit;
    }
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

$mensajeFlash = '';
$errorFlash = '';

// Procesar Respuestas y Mensajes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // Enviar nuevo mensaje / contestar al ticket
    if ($accion === 'enviar_mensaje') {
        $textoMensaje = trim($_POST['mensaje'] ?? '');

        if (empty($textoMensaje)) {
            $errorFlash = "El mensaje no puede estar vacío.";
        } else {
            // Manejo de archivo adjunto opcional en el mensaje
            $archivoMensaje = null;
            if (isset($_FILES['adjunto_mensaje']) && $_FILES['adjunto_mensaje']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['adjunto_mensaje']['tmp_name'];
                $fileName = $_FILES['adjunto_mensaje']['name'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

                if (in_array($fileExt, $extPermitidas)) {
                    $dirUpload = __DIR__ . '/uploads/tickets/';
                    if (!is_dir($dirUpload)) {
                        @mkdir($dirUpload, 0777, true);
                    }
                    $nuevoNombre = 'msg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $destino = $dirUpload . $nuevoNombre;
                    if (move_uploaded_file($fileTmp, $destino)) {
                        $archivoMensaje = 'uploads/tickets/' . $nuevoNombre;
                    }
                }
            }

            try {
                $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
                $sqlFecha = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";

                // Insertar mensaje en el hilo
                $stmtMsg = $pdo->prepare("
                    INSERT INTO tickets_mensajes 
                    (ticket_id, folio, autor_id, autor_nombre, autor_usuario, autor_rol, mensaje, archivo_adjunto, creado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, $sqlFecha)
                ");
                $rolAutor = $esAdmin ? 'administrador' : 'solicitante';
                $stmtMsg->execute([
                    $ticketId,
                    $ticket['folio'],
                    $usuarioId,
                    $nombreUsuario,
                    $loginUsuario,
                    $rolAutor,
                    $textoMensaje,
                    $archivoMensaje
                ]);

                // Actualizar timestamp en el ticket y si estaba cerrado, reabrirlo o poner en espera
                $stmtUp = $pdo->prepare("UPDATE tickets_soporte SET actualizado_en = $sqlFecha WHERE id = ?");
                $stmtUp->execute([$ticketId]);

                // Intentar notificar a Central de Sistemas en background
                try {
                    $token = getenv('TOKEN_SECRETO') ?: 'GedasDivolavilla2026!';
                    $payloadCentral = [
                        'token'     => $token,
                        'accion'    => 'nuevo_mensaje',
                        'folio'     => $ticket['folio'],
                        'autor'     => $nombreUsuario,
                        'usuario'   => $loginUsuario,
                        'mensaje'   => $textoMensaje,
                        'agencia'   => $agenciaUsuario
                    ];
                    $ctx = stream_context_create([
                        'http' => [
                            'method'  => 'POST',
                            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",
                            'content' => json_encode($payloadCentral),
                            'timeout' => 3
                        ]
                    ]);
                    @file_get_contents('https://portal.grupohuerta.mx/api_receptor_tickets.php', false, $ctx);
                } catch (Throwable $eNet) {}

                header("Location: ticket_detalle.php?id=" . $ticketId . "&msg_ok=1#conversacion");
                exit;

            } catch (Throwable $t) {
                $errorFlash = "Error al guardar el mensaje: " . $t->getMessage();
            }
        }
    }

    // Actualizar Estado (Administradores)
    elseif ($accion === 'actualizar_estado' && $esAdmin) {
        $nuevoEstado = trim($_POST['estado'] ?? 'Abierto');
        $asignadoA = trim($_POST['asignado_a'] ?? '');
        $notasResolucion = trim($_POST['notas_resolucion'] ?? '');

        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
            $sqlFecha = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";

            $stmtUpState = $pdo->prepare("
                UPDATE tickets_soporte 
                SET estado = ?, asignado_a = ?, notas_resolucion = ?, actualizado_en = $sqlFecha
                WHERE id = ?
            ");
            $stmtUpState->execute([$nuevoEstado, $asignadoA, $notasResolucion, $ticketId]);

            header("Location: ticket_detalle.php?id=" . $ticketId . "&estado_ok=1");
            exit;
        } catch (Throwable $t) {
            $errorFlash = "Error al actualizar el ticket: " . $t->getMessage();
        }
    }
}

// Cargar mensajes del hilo
$mensajesHilo = [];
if ($pdo) {
    try {
        $stmtMsgs = $pdo->prepare("SELECT * FROM tickets_mensajes WHERE ticket_id = ? ORDER BY id ASC");
        $stmtMsgs->execute([$ticketId]);
        $mensajesHilo = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// Configuración visual del área
$areaKey = strtoupper(trim($ticket['area_sistemas'] ?? 'DESARROLLO'));
$areaConf = $AREAS_SISTEMAS[$areaKey] ?? [
    'nombre' => $areaKey, 'icono' => 'bi-gear-fill', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.15)', 'border' => 'rgba(56, 189, 248, 0.35)'
];

// Configuración de estatus
$estLower = strtolower($ticket['estado'] ?? 'abierto');
$estClass = 'bg-warning text-dark';
$estBadge = 'Abierto (En espera)';
if ($estLower === 'en proceso') {
    $estClass = 'bg-info text-dark';
    $estBadge = 'En Proceso';
} elseif ($estLower === 'resuelto') {
    $estClass = 'bg-success text-white';
    $estBadge = 'Resuelto';
} elseif ($estLower === 'cerrado') {
    $estClass = 'bg-secondary text-white';
    $estBadge = 'Cerrado';
}

// Configuración de prioridad
$prioLower = strtolower($ticket['prioridad'] ?? 'media');
$prioClass = 'bg-primary text-white';
if ($prioLower === 'urgente') $prioClass = 'bg-danger text-white';
elseif ($prioLower === 'alta') $prioClass = 'bg-warning text-dark';
elseif ($prioLower === 'baja') $prioClass = 'bg-info text-dark';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($ticket['folio'] ?? ('#TK-' . $ticket['id'])); ?> - Detalle de Ticket</title>
    <?php include_once 'pwa_head.php'; ?>
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
            padding-bottom: 70px;
        }

        .top-navbar {
            background: rgba(6, 19, 37, 0.95);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 35px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .detail-card {
            background: linear-gradient(145deg, #0b1a30 0%, #071322 100%);
            border: 1px solid rgba(56, 189, 248, 0.22);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            padding: 30px 35px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .detail-card {
                padding: 20px 18px;
            }
            .top-navbar {
                padding: 12px 18px;
            }
        }

        .chat-bubble {
            border-radius: 18px;
            padding: 18px 22px;
            margin-bottom: 18px;
            position: relative;
        }

        .chat-bubble-user {
            background: #0d233f;
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-left: 4px solid #38bdf8;
        }

        .chat-bubble-staff {
            background: #06282d;
            border: 1px solid rgba(45, 212, 191, 0.35);
            border-left: 4px solid #14b8a6;
        }

        .chat-bubble-admin {
            background: #1e1b4b;
            border: 1px solid rgba(167, 139, 250, 0.35);
            border-left: 4px solid #8b5cf6;
        }

        .form-control {
            background-color: #061325;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.95rem;
        }

        .form-control:focus {
            background-color: #081932;
            border-color: #38bdf8;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.2);
            color: #ffffff;
            outline: none;
        }

        .preview-img {
            max-height: 380px;
            max-width: 100%;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            object-fit: contain;
            background: #000;
        }
    </style>
</head>
<body>

<!-- Navbar Superior -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="tickets.php" class="btn btn-outline-secondary btn-sm rounded-3 px-3 text-white border-opacity-25" title="Regresar al Listado de Tickets">
            <i class="bi bi-arrow-left me-1"></i> Listado de Tickets
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="font-monospace fw-bold fs-5 text-info"><?php echo htmlspecialchars($ticket['folio'] ?? ('#TK-' . $ticket['id'])); ?></span>
            <span class="badge rounded-pill <?php echo $estClass; ?> px-2.5 py-1"><?php echo htmlspecialchars($ticket['estado'] ?? 'Abierto'); ?></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-3 text-secondary" onclick="window.close()" title="Cerrar esta pestaña">
            <i class="bi bi-x-lg me-1"></i> Cerrar Pestaña
        </button>
    </div>
</div>

<div class="container my-4" style="max-width: 1080px;">

    <?php if (isset($_GET['creado'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border-left: 4px solid #22c55e !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> ¡Tu ticket con folio <strong><?php echo htmlspecialchars($ticket['folio']); ?></strong> fue registrado exitosamente! La Dirección de Sistemas ha recibido tu requerimiento.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg_ok'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border-left: 4px solid #22c55e !important;">
            <i class="bi bi-chat-dots-fill me-2 fs-5"></i> Tu mensaje ha sido enviado y registrado en el hilo del ticket correctamente.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['estado_ok'])): ?>
        <div class="alert alert-info alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border-left: 4px solid #38bdf8 !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> Estado y seguimiento del ticket actualizados correctamente.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorFlash)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border-left: 4px solid #ef4444 !important;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo htmlspecialchars($errorFlash); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- TARJETA 1: ENCABEZADO Y DATOS CLAVE DEL TICKET -->
    <div class="detail-card">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 pb-3 border-bottom border-secondary border-opacity-25">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge rounded-pill" style="background: <?php echo $areaConf['bg']; ?>; color: <?php echo $areaConf['color']; ?>; border: 1px solid <?php echo $areaConf['border']; ?>; font-size: 0.85rem;">
                        <i class="bi <?php echo $areaConf['icono']; ?> me-1"></i> <?php echo $areaConf['nombre']; ?>
                    </span>
                    <span class="badge rounded-pill <?php echo $prioClass; ?> px-2.5 py-1" style="font-size: 0.82rem;">
                        <i class="bi bi-exclamation-circle me-1"></i> Prioridad <?php echo htmlspecialchars($ticket['prioridad'] ?? 'Media'); ?>
                    </span>
                    <span class="badge rounded-pill <?php echo $estClass; ?> px-3 py-1" style="font-size: 0.85rem;">
                        <i class="bi bi-clock-history me-1"></i> <?php echo htmlspecialchars($ticket['estado'] ?? 'Abierto'); ?>
                    </span>
                </div>
                <h2 class="fw-bold text-white mb-1"><?php echo htmlspecialchars($ticket['titulo']); ?></h2>
                <div class="text-secondary small">
                    <i class="bi bi-calendar3 me-1"></i> Creado el <?php echo !empty($ticket['creado_en']) ? date('d/m/Y \a \l\a\s H:i', strtotime($ticket['creado_en'])) : '---'; ?>
                    <?php if (!empty($ticket['actualizado_en'])): ?>
                        &bull; <i class="bi bi-clock-history me-1"></i> Última actividad: <?php echo date('d/m/Y H:i', strtotime($ticket['actualizado_en'])); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-md-end">
                <div class="p-2.5 rounded-3" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.1);">
                    <div class="text-secondary small fw-bold" style="font-size: 0.72rem;">FOLIO OFICIAL</div>
                    <div class="fs-4 font-monospace fw-bold text-info"><?php echo htmlspecialchars($ticket['folio'] ?? ('#TK-' . $ticket['id'])); ?></div>
                </div>
            </div>
        </div>

        <!-- Metadatos del Solicitante y Asignación -->
        <div class="row g-3 mt-2">
            <div class="col-6 col-md-3">
                <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                    <div class="text-secondary small" style="font-size: 0.72rem;">SOLICITANTE</div>
                    <div class="fw-semibold text-white"><?php echo htmlspecialchars($ticket['solicitante_nombre'] ?? 'Usuario'); ?></div>
                    <div class="text-info small" style="font-size: 0.75rem;">@<?php echo htmlspecialchars($ticket['solicitante_usuario'] ?? ''); ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                    <div class="text-secondary small" style="font-size: 0.72rem;">SUCURSAL / AGENCIA</div>
                    <div class="fw-semibold text-white"><?php echo htmlspecialchars($ticket['solicitante_agencia'] ?? 'General'); ?></div>
                    <div class="text-secondary small text-truncate" style="font-size: 0.75rem;"><?php echo htmlspecialchars($ticket['solicitante_email'] ?? ''); ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                    <div class="text-secondary small" style="font-size: 0.72rem;">TÉCNICO ASIGNADO</div>
                    <div class="fw-semibold text-white"><?php echo !empty($ticket['asignado_a']) ? htmlspecialchars($ticket['asignado_a']) : '<span class="text-secondary fw-normal">Por asignar</span>'; ?></div>
                    <div class="text-secondary small" style="font-size: 0.75rem;">Sistemas Grupo Huerta</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                    <div class="text-secondary small" style="font-size: 0.72rem;">ESTADO ACTUAL</div>
                    <div class="fw-bold <?php echo strpos($estClass, 'text-dark') !== false ? 'text-warning' : 'text-success'; ?>"><?php echo htmlspecialchars($ticket['estado'] ?? 'Abierto'); ?></div>
                    <div class="text-secondary small" style="font-size: 0.75rem;">Atención Central TI</div>
                </div>
            </div>
        </div>
    </div>

    <!-- TARJETA 2: REQUERIMIENTO ORIGINAL -->
    <div class="detail-card">
        <h5 class="fw-bold text-info mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-card-text"></i> Descripción del Requerimiento Original
        </h5>
        
        <div class="p-3.5 rounded-3 text-white" style="background: #061325; border: 1px solid rgba(255, 255, 255, 0.1); font-size: 0.98rem; line-height: 1.6; white-space: pre-line;">
            <?php echo htmlspecialchars($ticket['descripcion']); ?>
        </div>

        <?php if (!empty($ticket['archivo_adjunto'])): 
            $ext = strtolower(pathinfo($ticket['archivo_adjunto'], PATHINFO_EXTENSION));
            $esImagen = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        ?>
            <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                <div class="text-secondary small fw-bold text-uppercase mb-2">
                    <i class="bi bi-paperclip text-info me-1"></i> Evidencia / Archivo Adjunto Original
                </div>
                <?php if ($esImagen && file_exists(__DIR__ . '/' . $ticket['archivo_adjunto'])): ?>
                    <div class="mb-2">
                        <a href="<?php echo htmlspecialchars($ticket['archivo_adjunto']); ?>" target="_blank" title="Abrir imagen completa">
                            <img src="<?php echo htmlspecialchars($ticket['archivo_adjunto']); ?>" alt="Evidencia Ticket" class="preview-img">
                        </a>
                    </div>
                <?php endif; ?>
                <div>
                    <a href="<?php echo htmlspecialchars($ticket['archivo_adjunto']); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-semibold">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Ver / Descargar Archivo (<?php echo strtoupper($ext); ?>)
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- TARJETA 3: RESPUESTA DE SISTEMAS CENTRAL (SI APLICA) -->
    <?php if (!empty(trim($ticket['notas_resolucion'] ?? ''))): ?>
        <div class="detail-card" style="border-color: rgba(56, 189, 248, 0.45); background: linear-gradient(145deg, #09213d 0%, #06182c 100%);">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="fw-bold text-info mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-headset"></i> Respuesta y Seguimiento de la Dirección Central de Sistemas
                </h5>
                <span class="badge bg-primary rounded-pill px-3 py-1">
                    <?php echo !empty($ticket['asignado_a']) ? htmlspecialchars($ticket['asignado_a']) : 'Dirección de Sistemas TI'; ?>
                </span>
            </div>
            <div class="p-3 rounded-3 text-white mt-3" style="background: rgba(3, 11, 23, 0.7); border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.95rem; line-height: 1.6; white-space: pre-line;">
                <?php echo htmlspecialchars($ticket['notas_resolucion']); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TARJETA 4: HILO DE CONVERSACIÓN Y MENSAJES ADICIONALES -->
    <div class="detail-card" id="conversacion">
        <h5 class="fw-bold text-white mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-chat-left-text-fill text-info"></i> Mensajes y Respuestas del Ticket
            <span class="badge bg-secondary bg-opacity-50 text-white-50 ms-auto font-monospace" style="font-size: 0.75rem;"><?php echo count($mensajesHilo); ?> mensaje(s)</span>
        </h5>

        <?php if (empty($mensajesHilo)): ?>
            <div class="p-4 rounded-3 text-center text-secondary mb-4" style="background: rgba(255, 255, 255, 0.03); border: 1px dashed rgba(255, 255, 255, 0.1);">
                <i class="bi bi-chat-square-dots fs-2 text-muted d-block mb-1"></i>
                <div class="fw-semibold">No hay mensajes adicionales en este hilo aún.</div>
                <div class="small text-muted">Puedes redactar una respuesta o enviar otro mensaje a continuación.</div>
            </div>
        <?php else: ?>
            <div class="mb-4">
                <?php foreach ($mensajesHilo as $msg): 
                    $esPropio = (!empty($msg['autor_usuario']) && strtolower($msg['autor_usuario']) === strtolower($loginUsuario))
                             || (!empty($msg['autor_id']) && (int)$msg['autor_id'] === (int)$usuarioId);
                    $bubbleClass = $esPropio ? 'chat-bubble-user' : 'chat-bubble-staff';
                    $badgeRol = $esPropio ? 'Tú (Solicitante)' : 'Sistemas / Atención';
                    if (!empty($msg['autor_rol']) && $msg['autor_rol'] === 'administrador') {
                        $bubbleClass = 'chat-bubble-admin';
                        $badgeRol = 'Administrador TI';
                    }
                ?>
                    <div class="chat-bubble <?php echo $bubbleClass; ?>">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-white"><?php echo htmlspecialchars($msg['autor_nombre'] ?? 'Usuario'); ?></span>
                                <span class="badge rounded-pill bg-dark border border-secondary text-info" style="font-size: 0.72rem;"><?php echo $badgeRol; ?></span>
                            </div>
                            <div class="text-secondary small" style="font-size: 0.75rem;">
                                <i class="bi bi-clock me-1"></i> <?php echo !empty($msg['creado_en']) ? date('d/m/Y H:i', strtotime($msg['creado_en'])) : ''; ?>
                            </div>
                        </div>

                        <div class="text-white" style="font-size: 0.94rem; line-height: 1.55; white-space: pre-line;">
                            <?php echo htmlspecialchars($msg['mensaje']); ?>
                        </div>

                        <?php if (!empty($msg['archivo_adjunto'])): 
                            $extMsg = strtolower(pathinfo($msg['archivo_adjunto'], PATHINFO_EXTENSION));
                            $esImgMsg = in_array($extMsg, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                        ?>
                            <div class="mt-2.5 pt-2 border-top border-secondary border-opacity-25">
                                <?php if ($esImgMsg && file_exists(__DIR__ . '/' . $msg['archivo_adjunto'])): ?>
                                    <div class="mb-2">
                                        <a href="<?php echo htmlspecialchars($msg['archivo_adjunto']); ?>" target="_blank">
                                            <img src="<?php echo htmlspecialchars($msg['archivo_adjunto']); ?>" alt="Captura adjunta" style="max-height: 220px; border-radius: 8px;">
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars($msg['archivo_adjunto']); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3" style="font-size: 0.78rem;">
                                    <i class="bi bi-paperclip me-1"></i> Archivo adjunto (<?php echo strtoupper($extMsg); ?>)
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- FORMULARIO: CONTESTAR / ENVIAR OTRO MENSAJE -->
        <div class="p-3.5 rounded-4 mt-3" style="background: rgba(15, 23, 42, 0.75); border: 1px solid rgba(56, 189, 248, 0.3);">
            <h6 class="fw-bold text-info mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-reply-fill fs-5"></i> Contestar / Enviar Mensaje a este Ticket
            </h6>

            <form method="POST" action="ticket_detalle.php?id=<?php echo $ticketId; ?>" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="enviar_mensaje">

                <div class="mb-3">
                    <textarea name="mensaje" rows="4" class="form-control" placeholder="Escribe aquí tu respuesta, detalles adicionales, avances o consulta sobre este ticket..." required></textarea>
                </div>

                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <label class="text-secondary small fw-bold text-uppercase d-block mb-1" style="font-size: 0.75rem;">
                            <i class="bi bi-paperclip me-1"></i> Adjuntar captura o archivo (opcional)
                        </label>
                        <input type="file" name="adjunto_mensaje" class="form-control form-control-sm" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" style="max-width: 380px;">
                    </div>
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary px-4 py-2.5 rounded-3 fw-bold d-inline-flex align-items-center gap-2 shadow" style="background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%); border: none;">
                            <i class="bi bi-send-fill"></i>
                            <span>Enviar Mensaje</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- PANEL EXCLUSIVO ADMIN: CAMBIAR ESTADO / RESOLUCIÓN -->
    <?php if ($esAdmin): ?>
        <div class="detail-card" style="border-color: rgba(148, 163, 184, 0.3);">
            <h5 class="fw-bold text-warning mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-shield-lock-fill"></i> Panel de Administración &bull; Actualizar Estatus del Ticket
            </h5>
            <form method="POST" action="ticket_detalle.php?id=<?php echo $ticketId; ?>">
                <input type="hidden" name="accion" value="actualizar_estado">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Estado del Ticket</label>
                        <select name="estado" class="form-select" style="background: #061325; color: #fff;">
                            <option value="Abierto" <?php echo $ticket['estado'] === 'Abierto' ? 'selected' : ''; ?>>Abierto (En espera)</option>
                            <option value="En Proceso" <?php echo $ticket['estado'] === 'En Proceso' ? 'selected' : ''; ?>>En Proceso (Trabajando)</option>
                            <option value="Resuelto" <?php echo $ticket['estado'] === 'Resuelto' ? 'selected' : ''; ?>>Resuelto (Solución entregada)</option>
                            <option value="Cerrado" <?php echo $ticket['estado'] === 'Cerrado' ? 'selected' : ''; ?>>Cerrado (Finalizado)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Técnico Asignado</label>
                        <input type="text" name="asignado_a" class="form-control" value="<?php echo htmlspecialchars($ticket['asignado_a'] ?? ''); ?>" placeholder="Ej. Ing. de Sistemas...">
                    </div>
                    <div class="col-12">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Notas de Solución Oficial / Resolución</label>
                        <textarea name="notas_resolucion" rows="3" class="form-control" placeholder="Escribe la solución técnica o respuesta oficial..."><?php echo htmlspecialchars($ticket['notas_resolucion'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-warning px-4 py-2 rounded-3 fw-bold text-dark">
                            <i class="bi bi-check-circle-fill me-1"></i> Guardar Cambios de Estatus
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
