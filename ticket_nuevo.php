<?php
/**
 * PORTAL DE AGENCIA - LEVANTAR NUEVO TICKET (NUEVA PESTAÑA COMPLETA)
 * Permite registrar un ticket de soporte a pantalla completa y sin modales flotantes.
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

// Catálogo Oficial de Áreas de Sistemas
$AREAS_SISTEMAS = [
    'DESARROLLO'      => ['nombre' => 'DESARROLLO', 'icono' => 'bi-code-slash', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.18)', 'border' => 'rgba(56, 189, 248, 0.45)'],
    'CYBERSEGURIDAD'  => ['nombre' => 'CYBERSEGURIDAD', 'icono' => 'bi-shield-lock-fill', 'color' => '#f43f5e', 'bg' => 'rgba(244, 63, 94, 0.18)', 'border' => 'rgba(244, 63, 94, 0.45)'],
    'INFRAESTRUCTURA' => ['nombre' => 'INFRAESTRUCTURA', 'icono' => 'bi-hdd-rack-fill', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.18)', 'border' => 'rgba(16, 185, 129, 0.45)'],
    'REDES SOCIALES'  => ['nombre' => 'REDES SOCIALES', 'icono' => 'bi-share-fill', 'color' => '#a855f7', 'bg' => 'rgba(168, 85, 247, 0.18)', 'border' => 'rgba(168, 85, 247, 0.45)'],
    'AUDITORIA'       => ['nombre' => 'AUDITORIA', 'icono' => 'bi-clipboard-check-fill', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.18)', 'border' => 'rgba(245, 158, 11, 0.45)'],
    'CORPORATIVO'     => ['nombre' => 'CORPORATIVO', 'icono' => 'bi-building-fill', 'color' => '#6366f1', 'bg' => 'rgba(99, 102, 241, 0.18)', 'border' => 'rgba(99, 102, 241, 0.45)']
];

/**
 * Envía el ticket en tiempo real a la Dirección Central
 */
function enviarTicketACentralNuevo($datosTicket, $archivoLocal = null) {
    $urlCentral = 'https://portal.grupohuerta.mx/api_receptor_tickets.php';
    $token = getenv('TOKEN_SECRETO') ?: 'GedasDivolavilla2026!';

    $payload = [
        'token'               => $token,
        'agencia'             => $datosTicket['agencia'] ?? 'Divol La Villa',
        'area_sistemas'       => $datosTicket['area_sistemas'] ?? 'INFRAESTRUCTURA',
        'titulo'              => $datosTicket['titulo'] ?? '',
        'descripcion'         => $datosTicket['descripcion'] ?? '',
        'prioridad'           => $datosTicket['prioridad'] ?? 'Media',
        'solicitante_usuario' => $datosTicket['solicitante_usuario'] ?? '',
        'solicitante_nombre'  => $datosTicket['solicitante_nombre'] ?? 'Usuario',
        'solicitante_email'   => $datosTicket['solicitante_email'] ?? ''
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
    curl_close($ch);

    if ($res !== false && $httpCode === 200) {
        $dec = json_decode($res, true);
        if ($dec && ($dec['status'] ?? '') === 'ok') {
            return ['ok' => true, 'folio' => $dec['folio'] ?? null, 'datos' => $dec];
        }
    }
    return ['ok' => false];
}

$error = '';
$mensaje = '';

// Procesar Formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $area = trim($_POST['area_sistemas'] ?? '');
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $prioridad = trim($_POST['prioridad'] ?? 'Media');
    $solicitanteUsuario = $loginUsuario;
    $solicitanteNombre = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
    $solicitanteEmail = trim($_POST['solicitante_email'] ?? $emailUsuario);
    $solicitanteAgencia = trim($_POST['solicitante_agencia'] ?? $agenciaUsuario);

    if (empty($titulo) || empty($descripcion) || empty($area)) {
        $error = "Por favor selecciona el Área de Sistemas, ingresa el Asunto y describe detalladamente tu requerimiento.";
    } else {
        // Manejo de archivo adjunto
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
            // Intentar enviar a Central
            $resCentral = enviarTicketACentralNuevo([
                'agencia'             => $solicitanteAgencia,
                'area_sistemas'       => $area,
                'titulo'              => $titulo,
                'descripcion'         => $descripcion,
                'prioridad'           => $prioridad,
                'solicitante_usuario' => $solicitanteUsuario,
                'solicitante_nombre'  => $solicitanteNombre,
                'solicitante_email'   => $solicitanteEmail
            ], $archivoUrl);

            $folio = null;
            if ($resCentral['ok'] && !empty($resCentral['folio'])) {
                $folio = $resCentral['folio'];
            } else {
                $stmtCount = $pdo->query("SELECT COUNT(*) FROM tickets_soporte");
                $conteo = $stmtCount ? (int)$stmtCount->fetchColumn() : 0;
                $folio = 'TK-' . date('Y') . '-' . str_pad($conteo + 1, 4, '0', STR_PAD_LEFT);
            }

            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
            $sqlFecha = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";

            $stmtIns = $pdo->prepare("
                INSERT INTO tickets_soporte 
                (folio, area_sistemas, titulo, descripcion, prioridad, estado, solicitante_id, solicitante_usuario, solicitante_nombre, solicitante_email, solicitante_agencia, archivo_adjunto, creado_en)
                VALUES (?, ?, ?, ?, ?, 'Abierto', ?, ?, ?, ?, ?, ?, $sqlFecha)
            ");

            $stmtIns->execute([
                $folio,
                $area,
                $titulo,
                $descripcion,
                $prioridad,
                $usuarioId,
                $solicitanteUsuario,
                $solicitanteNombre,
                $solicitanteEmail,
                $solicitanteAgencia,
                $archivoUrl
            ]);

            $nuevoId = (int)$pdo->lastInsertId();

            // Redirigir a la pestaña de detalle y seguimiento del ticket recién creado
            header("Location: ticket_detalle.php?id=" . $nuevoId . "&creado=1");
            exit;

        } catch (Throwable $t) {
            $error = "Error al registrar el ticket: " . $t->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Levantar Nuevo Ticket de Soporte - Portal Grupo Huerta</title>
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
            padding-bottom: 60px;
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

        .form-card {
            background: linear-gradient(145deg, #0b1a30 0%, #071322 100%);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            padding: 35px 40px;
        }

        @media (max-width: 768px) {
            .form-card {
                padding: 24px 20px;
            }
            .top-navbar {
                padding: 12px 18px;
            }
        }

        .form-label {
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 7px;
        }

        .form-control, .form-select {
            background-color: #061325;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            background-color: #081932;
            border-color: #38bdf8;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.2);
            color: #ffffff;
            outline: none;
        }

        .form-control::placeholder {
            color: #64748b;
        }

        .info-pill {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 10px 14px;
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
            <i class="bi bi-ticket-detailed-fill fs-4 text-info"></i>
            <span class="fw-bold tracking-wide">NUEVO TICKET <span class="text-secondary fw-normal">| Dirección de Sistemas</span></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-3 text-secondary" onclick="window.close()" title="Cerrar esta pestaña">
            <i class="bi bi-x-lg me-1"></i> Cerrar Pestaña
        </button>
    </div>
</div>

<div class="container my-5" style="max-width: 920px;">

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border-left: 4px solid #ef4444 !important;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <!-- Encabezado del Formulario -->
        <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom border-secondary border-opacity-25">
            <div class="p-3 rounded-4" style="background: rgba(2, 132, 199, 0.2); border: 1px solid rgba(56, 189, 248, 0.4); color: #38bdf8; font-size: 1.8rem;">
                <i class="bi bi-ticket-detailed-fill"></i>
            </div>
            <div>
                <h3 class="fw-bold text-white mb-1">Levantar Nuevo Ticket de Soporte</h3>
                <p class="text-secondary small mb-0">Dirección de Sistemas &bull; Requerimientos, Incidencias y Asistencia Técnica TI</p>
            </div>
        </div>

        <form method="POST" action="ticket_nuevo.php" enctype="multipart/form-data" id="formNuevoTicket">
            
            <div class="row g-4">
                <!-- Área de Sistemas -->
                <div class="col-md-6">
                    <label class="form-label">
                        <i class="bi bi-cpu-fill text-info me-1"></i> Área de Sistemas Asignada <span class="text-danger">*</span>
                    </label>
                    <select name="area_sistemas" class="form-select" required>
                        <option value="" disabled selected>Selecciona el área de soporte...</option>
                        <option value="INFRAESTRUCTURA">🖥️ INFRAESTRUCTURA (Redes, Servidores, Impresoras, Equipos)</option>
                        <option value="DESARROLLO">💻 DESARROLLO (Portales, Sistemas Web, Bases de Datos, Módulos)</option>
                        <option value="CYBERSEGURIDAD">🛡️ CYBERSEGURIDAD (Accesos, Antivirus, Bloqueos, Cuentas)</option>
                        <option value="REDES SOCIALES">📱 REDES SOCIALES (Marketing Digital, Publicaciones, Medios)</option>
                        <option value="AUDITORIA">📋 AUDITORIA (Procesos, Revisiones TI, Cumplimiento)</option>
                        <option value="CORPORATIVO">🏢 CORPORATIVO (Dirección General y Enlace Central)</option>
                    </select>
                </div>

                <!-- Nivel de Prioridad -->
                <div class="col-md-6">
                    <label class="form-label">
                        <i class="bi bi-reception-3 text-warning me-1"></i> Nivel de Prioridad <span class="text-danger">*</span>
                    </label>
                    <select name="prioridad" class="form-select" required>
                        <option value="Baja">🟢 Baja (Consulta general, requerimiento no urgente)</option>
                        <option value="Media" selected>🟡 Media (Operación regular normal)</option>
                        <option value="Alta">🟠 Alta (Afecta parcialmente la operación de sucursal)</option>
                        <option value="Urgente">🔴 Urgente (Operación completamente detenida / Bloqueo crítico)</option>
                    </select>
                </div>

                <!-- Título o Asunto -->
                <div class="col-12">
                    <label class="form-label">
                        <i class="bi bi-card-heading text-info me-1"></i> Título / Asunto Breve <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="titulo" class="form-control" placeholder="Ej. Falla en enlace de Internet / Acceso a base de datos / Falla en impresora..." maxlength="200" required>
                </div>

                <!-- Descripción Detallada -->
                <div class="col-12">
                    <label class="form-label">
                        <i class="bi bi-text-paragraph text-info me-1"></i> Descripción Detallada del Requerimiento o Falla <span class="text-danger">*</span>
                    </label>
                    <textarea name="descripcion" class="form-control" rows="6" placeholder="Describe claramente qué necesitas, los pasos para reproducir el error, mensajes que aparezcan en pantalla o el motivo de tu solicitud..." required></textarea>
                </div>

                <!-- Adjuntar Archivo o Captura -->
                <div class="col-12">
                    <label class="form-label">
                        <i class="bi bi-paperclip text-info me-1"></i> Adjuntar Evidencia o Captura de Pantalla (Opcional)
                    </label>
                    <input type="file" name="archivo_adjunto" class="form-control" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
                    <div class="text-secondary small mt-1.5" style="font-size: 0.78rem;">
                        <i class="bi bi-info-circle me-1"></i> Formatos permitidos: Imágenes (PNG, JPG, WEBP), PDF, Word, Excel, ZIP. Máx 15MB.
                    </div>
                </div>

                <!-- Datos Informativos del Solicitante -->
                <div class="col-12">
                    <div class="p-3 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08);">
                        <div class="text-secondary small fw-bold text-uppercase mb-2">Información del Solicitante</div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="info-pill">
                                    <div class="text-secondary small" style="font-size: 0.72rem;">SOLICITANTE</div>
                                    <div class="fw-semibold text-white"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                                    <input type="hidden" name="solicitante_nombre" value="<?php echo htmlspecialchars($nombreUsuario); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-pill">
                                    <div class="text-secondary small" style="font-size: 0.72rem;">AGENCIA / SUCURSAL</div>
                                    <div class="fw-semibold text-white"><?php echo htmlspecialchars($agenciaUsuario); ?></div>
                                    <input type="hidden" name="solicitante_agencia" value="<?php echo htmlspecialchars($agenciaUsuario); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-pill">
                                    <div class="text-secondary small" style="font-size: 0.72rem;">CORREO / USUARIO</div>
                                    <div class="fw-semibold text-info text-truncate"><?php echo htmlspecialchars($emailUsuario ?: ('@' . $loginUsuario)); ?></div>
                                    <input type="hidden" name="solicitante_email" value="<?php echo htmlspecialchars($emailUsuario); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="col-12 pt-3 border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <a href="tickets.php" class="btn btn-outline-secondary px-4 py-2.5 rounded-3 text-white">
                        <i class="bi bi-arrow-left me-1"></i> Regresar
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg px-5 py-3 rounded-3 fw-bold d-inline-flex align-items-center gap-2 shadow-lg" style="background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%); border: none; font-size: 1.1rem; box-shadow: 0 6px 20px rgba(2, 132, 199, 0.45) !important;">
                        <i class="bi bi-send-fill fs-5"></i>
                        <span>Registrar y Enviar Ticket</span>
                    </button>
                </div>

            </div>

        </form>
    </div>

</div>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
