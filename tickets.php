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

requerirPermiso('tickets', 'puede_ver');

// Forzar zona horaria oficial de México (UTC-6)
date_default_timezone_set('America/Mexico_City');
if ($pdo) {
    try { $pdo->exec("SET time_zone = '-06:00'"); } catch (Throwable $tzE) {}
}

/**
 * Formatea la fecha y hora de mensajes y tickets a la hora local oficial de México (America/Mexico_City).
 * Detecta y corrige automáticamente cualquier desfase UTC (+5 o +6 horas adelante) generado por MySQL o servidores en la nube.
 */
function formatearFechaHoraMensaje($fechaStr, $incluirPrefijo = true) {
    if (empty($fechaStr)) return '---';
    try {
        $ts = strtotime($fechaStr);
        if ($ts === false) return $fechaStr;

        // Si la fecha registrada viene con desfase UTC (+2 a +8 horas adelante de la hora actual de México)
        if ($ts > (time() + 300)) {
            $ts -= (6 * 3600); // Convertir UTC a México City (-6 hrs)
        }

        $diaMsg = date('Y-m-d', $ts);
        $hoy = date('Y-m-d');
        $ayer = date('Y-m-d', strtotime('-1 day'));

        $horaFormateada = date('h:i A', $ts); // Ej: 05:05 PM
        $hora24 = date('H:i', $ts);          // Ej: 17:05

        if ($diaMsg === $hoy && $incluirPrefijo) {
            return 'Hoy a las ' . $horaFormateada . ' (' . $hora24 . ' hrs)';
        } elseif ($diaMsg === $ayer && $incluirPrefijo) {
            return 'Ayer a las ' . $horaFormateada . ' (' . $hora24 . ' hrs)';
        } else {
            return date('d/m/Y', $ts) . ' ' . $horaFormateada . ' (' . $hora24 . ' hrs)';
        }
    } catch (Throwable $e) {
        return $fechaStr;
    }
}

/**
 * Resuelve dinámicamente la identidad, token y configuración de la agencia local
 * según su base de datos, cPanel o dominio host, sin depender de nombres fijos.
 */
function obtenerIdentidadAgenciaActual($pdo = null) {
    static $identidad = null;
    if ($identidad !== null) {
        return $identidad;
    }

    $nombre = '';
    // Token maestro para comunicarse con la Dirección Central de Sistemas (portal.grupohuerta.mx)
    // El servidor central siempre valida GH_SISTEMAS_TICKETS_2026! para todas las agencias
    $token = 'GH_SISTEMAS_TICKETS_2026!';
    $envTok = getenv('TOKEN_SECRETO');
    if (!empty($envTok) && $envTok !== 'TK_LOCAL_DEFAULT_2026' && strpos($envTok, 'TK_LOCAL_') !== 0) {
        $token = $envTok;
    }

    $cpanel = '';
    $subdominio = '';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');

    // 1. Consultar base de datos local (tabla agencias)
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($row) {
                if (!empty($row['nombre'])) {
                    $nombre = trim($row['nombre']);
                }
                if (!empty($row['cpanel'])) $cpanel = trim($row['cpanel']);
                if (!empty($row['subdominio'])) $subdominio = trim($row['subdominio']);
            }
        } catch (Throwable $e) {}
    }

    // 2. Sesión activa si ya tiene agencia
    if (empty($nombre) && !empty($_SESSION['agencia'])) {
        $nombre = trim($_SESSION['agencia']);
    }

    // 3. Si no hay nombre aún, inferir del dominio/host actual
    if (empty($nombre)) {
        if (!empty($host)) {
            $hostClean = preg_replace('/:\d+$/', '', strtolower($host));
            $partes = explode('.', $hostClean);
            $segmento = $partes[0];
            if (in_array($segmento, ['portal', 'sistemas', 'admin', 'www']) && count($partes) > 1) {
                $segmento = $partes[1];
            }
            $nombre = ucwords(str_replace(['-', '_'], ' ', $segmento));
        } else {
            $nombre = 'Agencia';
        }
    }

    $identidad = [
        'nombre'     => $nombre,
        'token'      => $token,
        'cpanel'     => $cpanel ?: $host,
        'subdominio' => $subdominio ?: $host,
        'host'       => $host
    ];
    return $identidad;
}

// Cargar permisos y asegurar tablas
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Usuario');
$loginUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? '');
$emailUsuario = $_SESSION['usuario_email'] ?? '';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

// Obtener identidad dinámica de la sucursal/agencia
$identidadActual = obtenerIdentidadAgenciaActual($pdo);
$agenciaUsuario = $identidadActual['nombre'];
$_SESSION['agencia'] = $agenciaUsuario;
$tokenAgencia = $identidadActual['token'];

if ($pdo) {
    asegurarTablaTickets($pdo);

    // Asegurar carga de login y email si faltan en sesión
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

$mensaje = $_SESSION['flash_mensaje'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);

/**
 * Envía el ticket en tiempo real al Portal Maestro Central de la Dirección de Sistemas
 */
function enviarTicketACentral($datosTicket, $archivoLocal = null) {
    global $pdo, $agenciaUsuario, $tokenAgencia;
    $identidad = obtenerIdentidadAgenciaActual($pdo);
    $urlCentral = 'https://portal.grupohuerta.mx/api_receptor_tickets.php';
    $token = !empty($datosTicket['token']) ? $datosTicket['token'] : ($tokenAgencia ?: $identidad['token']);
    $agencia = !empty($datosTicket['agencia']) ? $datosTicket['agencia'] : ($agenciaUsuario ?: $identidad['nombre']);

    $payload = [
        'token'               => $token,
        'agencia'             => $agencia,
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

/**
 * Sincroniza en tiempo real los estados y respuestas de TI desde la Central (portal.grupohuerta.mx)
 */
function sincronizarTicketsConCentral($pdo) {
    if (!$pdo) return 0;
    global $agenciaUsuario, $tokenAgencia;
    $identidad = obtenerIdentidadAgenciaActual($pdo);
    $token = $tokenAgencia ?: $identidad['token'];
    $agencia = $agenciaUsuario ?: $identidad['nombre'];

    $urlCentral = 'https://portal.grupohuerta.mx/api_consultar_tickets.php?token=' . urlencode($token);

    try {
        // Obtener folios locales registrados
        $stmtFolios = $pdo->query("SELECT folio FROM tickets_soporte WHERE folio IS NOT NULL AND folio != '' ORDER BY id DESC LIMIT 50");
        $folios = $stmtFolios ? $stmtFolios->fetchAll(PDO::FETCH_COLUMN) : [];

        $payload = [
            'token'   => $token,
            'agencia' => $agencia
        ];
        if (!empty($folios)) {
            $payload['folios'] = $folios;
        }

        $ch = curl_init($urlCentral);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 6,
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
            if ($dec && ($dec['status'] ?? '') === 'ok' && !empty($dec['tickets'])) {
                $actualizados = 0;
                $stmtUpd = $pdo->prepare("
                    UPDATE tickets_soporte 
                    SET estado = :estado, 
                        asignado_a = :asignado, 
                        notas_resolucion = :notas, 
                        prioridad = :prioridad,
                        evidencia_resolucion = COALESCE(NULLIF(:evidencia, ''), evidencia_resolucion),
                        solicitante_usuario = COALESCE(NULLIF(solicitante_usuario, ''), :sol_usr),
                        requiere_firma_reporte = CASE WHEN :req_firma = 1 OR :rep_firmado_a = 1 THEN 1 ELSE requiere_firma_reporte END,
                        reporte_orden_no = COALESCE(NULLIF(:rep_orden, ''), reporte_orden_no),
                        reporte_problema = COALESCE(NULLIF(:rep_prob, ''), reporte_problema),
                        reporte_trabajo_solicitado = COALESCE(NULLIF(:rep_sol, ''), reporte_trabajo_solicitado),
                        reporte_observaciones = COALESCE(NULLIF(:rep_obs, ''), reporte_observaciones),
                        reporte_trabajos_realizados = COALESCE(NULLIF(:rep_real, ''), reporte_trabajos_realizados),
                        reporte_tecnico_nombre = COALESCE(NULLIF(:rep_tec, ''), reporte_tecnico_nombre),
                        reporte_firmado = CASE WHEN :rep_firmado_b = 1 THEN 1 ELSE reporte_firmado END,
                        reporte_firma_digital = COALESCE(NULLIF(:rep_firma_img, ''), reporte_firma_digital),
                        reporte_firmado_por = COALESCE(NULLIF(:rep_firmado_por, ''), reporte_firmado_por),
                        reporte_firmado_en = COALESCE(NULLIF(:rep_firmado_en, ''), reporte_firmado_en),
                        actualizado_en = :act
                    WHERE folio = :folio
                ");

                $stmtIns = $pdo->prepare("
                    INSERT INTO tickets_soporte 
                    (folio, area_sistemas, titulo, descripcion, prioridad, estado, asignado_a, notas_resolucion, evidencia_resolucion, solicitante_usuario, solicitante_nombre, solicitante_email, solicitante_agencia, creado_en, actualizado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($dec['tickets'] as $tc) {
                    if (empty($tc['folio'])) continue;

                    $stmtChk = $pdo->prepare("SELECT id FROM tickets_soporte WHERE folio = ? LIMIT 1");
                    $stmtChk->execute([$tc['folio']]);
                    $existe = $stmtChk->fetchColumn();

                    if ($existe) {
                        $valFirmado = !empty($tc['reporte_firmado']) ? 1 : 0;
                        $stmtUpd->execute([
                            ':estado'          => $tc['estado'] ?? 'Abierto',
                            ':asignado'        => $tc['asignado_a'] ?? '',
                            ':notas'           => $tc['notas_resolucion'] ?? '',
                            ':evidencia'       => $tc['evidencia_resolucion'] ?? '',
                            ':prioridad'       => $tc['prioridad'] ?? 'Media',
                            ':sol_usr'         => $tc['solicitante_usuario'] ?? '',
                            ':req_firma'       => isset($tc['requiere_firma_reporte']) ? (int)$tc['requiere_firma_reporte'] : null,
                            ':rep_orden'       => $tc['reporte_orden_no'] ?? '',
                            ':rep_prob'        => $tc['reporte_problema'] ?? '',
                            ':rep_sol'         => $tc['reporte_trabajo_solicitado'] ?? '',
                            ':rep_obs'         => $tc['reporte_observaciones'] ?? '',
                            ':rep_real'        => $tc['reporte_trabajos_realizados'] ?? '',
                            ':rep_tec'         => $tc['reporte_tecnico_nombre'] ?? '',
                            ':rep_firmado_a'   => $valFirmado,
                            ':rep_firmado_b'   => $valFirmado,
                            ':rep_firma_img'   => $tc['reporte_firma_digital'] ?? '',
                            ':rep_firmado_por' => $tc['reporte_firmado_por'] ?? '',
                            ':rep_firmado_en'  => $tc['reporte_firmado_en'] ?? null,
                            ':act'             => $tc['actualizado_en'] ?? date('Y-m-d H:i:s'),
                            ':folio'           => $tc['folio']
                        ]);
                    } else {
                        $stmtIns->execute([
                            $tc['folio'],
                            $tc['area_sistemas'] ?? 'INFRAESTRUCTURA',
                            $tc['titulo'] ?? 'Requerimiento de Sistemas',
                            $tc['descripcion'] ?? '',
                            $tc['prioridad'] ?? 'Media',
                            $tc['estado'] ?? 'Abierto',
                            $tc['asignado_a'] ?? '',
                            $tc['notas_resolucion'] ?? '',
                            $tc['evidencia_resolucion'] ?? '',
                            $tc['solicitante_usuario'] ?? '',
                            $tc['solicitante_nombre'] ?? 'Usuario',
                            $tc['solicitante_email'] ?? '',
                            $tc['solicitante_agencia'] ?? $agencia,
                            $tc['creado_en'] ?? date('Y-m-d H:i:s'),
                            $tc['actualizado_en'] ?? date('Y-m-d H:i:s')
                        ]);
                    }
                    $actualizados++;
                }
                $_SESSION['ultimo_sync_tickets'] = time();
                return $actualizados;
            }
        }
    } catch (Throwable $e) {}

    return 0;
}

/**
 * Sincroniza en tiempo real los mensajes y contestaciones de este ticket desde la Central (portal.grupohuerta.mx)
 */
function sincronizarMensajesDeTicket($pdo, $ticketId, $folio) {
    if (empty($folio)) return [];
    // Token maestro para conectar con Central
    $token = 'GH_SISTEMAS_TICKETS_2026!';
    $urlCentral = 'https://portal.grupohuerta.mx/api_consultar_tickets.php?mensajes=1&folio=' . urlencode($folio) . '&token=' . urlencode($token);

    $mensajesCentral = [];
    try {
        $ch = curl_init($urlCentral);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'User-Agent: PortalAgencia/2.0'
            ]
        ]);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res !== false && $httpCode === 200) {
            $dec = json_decode($res, true);
            if ($dec && ($dec['status'] ?? '') === 'ok' && !empty($dec['mensajes'])) {
                $mensajesCentral = $dec['mensajes'];

                if ($pdo) {
                    $stmtChk = $pdo->prepare("SELECT id FROM tickets_mensajes WHERE (ticket_id = ? OR folio = ?) AND mensaje = ? AND autor_nombre = ? LIMIT 1");
                    $stmtIns = $pdo->prepare("
                        INSERT INTO tickets_mensajes 
                        (ticket_id, folio, autor_id, autor_nombre, autor_usuario, autor_rol, autor_agencia, mensaje, archivo_adjunto, creado_en)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($mensajesCentral as &$m) {
                        $txtM = trim($m['mensaje'] ?? '');
                        if (empty($txtM)) continue;
                        $fechaM = $m['creado_en'] ?? date('Y-m-d H:i:s');
                        // Si la fecha remota viene en UTC (+2 a +8 horas adelantada respecto a la hora actual de México)
                        $tsM = strtotime($fechaM);
                        if ($tsM !== false && $tsM > (time() + 300)) {
                            $fechaM = date('Y-m-d H:i:s', $tsM - (6 * 3600));
                            $m['creado_en'] = $fechaM;
                        }
                        $autorNomM = $m['autor_nombre'] ?? 'Sistemas Central';
                        $stmtChk->execute([$ticketId, $folio, $txtM, $autorNomM]);
                        if (!$stmtChk->fetchColumn()) {
                            try {
                                $stmtIns->execute([
                                    $ticketId,
                                    $folio,
                                    $m['autor_id'] ?? null,
                                    $autorNomM,
                                    $m['autor_usuario'] ?? '',
                                    $m['autor_rol'] ?? 'agente',
                                    $m['autor_agencia'] ?? 'Dirección Sistemas',
                                    $txtM,
                                    $m['archivo_adjunto'] ?? null,
                                    $fechaM
                                ]);
                            } catch (Throwable $eIns) {}
                        }
                    }
                    unset($m);
                }
            }
        }
    } catch (Throwable $e) {}

    return $mensajesCentral;
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
        $solicitanteUsuario = $loginUsuario;
        $solicitanteNombre = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
        $solicitanteEmail = trim($_POST['solicitante_email'] ?? $emailUsuario);
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
                    $mensaje = "✅ ¡Ticket con Folio oficial <strong>$folio</strong> enviado con éxito a la Dirección Central de Sistemas (portal.grupohuerta.mx)! Un agente de TI atenderá tu solicitud a la brevedad.";
                } else {
                    $stmtCount = $pdo->query("SELECT COUNT(*) FROM tickets_soporte");
                    $conteo = $stmtCount ? (int)$stmtCount->fetchColumn() : 0;
                    $folio = 'TK-' . date('Y') . '-' . str_pad($conteo + 1, 4, '0', STR_PAD_LEFT);
                    $mensaje = "ℹ️ Ticket registrado en el portal de la agencia con Folio <strong>$folio</strong>.";
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
                header("Location: tickets.php?ver=" . $nuevoId . "&creado=1");
                exit;
            } catch (Throwable $t) {
                $error = "Error al registrar el ticket: " . $t->getMessage();
            }
        }
    }

    // 2. Modificar Requerimiento del Ticket (Solicitante o Administrador)
    elseif ($accion === 'modificar_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $area = trim($_POST['area_sistemas'] ?? '');
        $prioridad = trim($_POST['prioridad'] ?? 'Media');
        $nuevoEstado = trim($_POST['estado'] ?? '');

        if ($ticketId > 0 && !empty($titulo) && !empty($descripcion)) {
            try {
                $stmtCheckT = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
                $stmtCheckT->execute([$ticketId]);
                $tActual = $stmtCheckT->fetch(PDO::FETCH_ASSOC);

                if ($tActual) {
                    if (!$esAdmin) {
                        $error = "No cuentas con autorización para modificar este ticket. Los requerimientos no pueden ser alterados una vez generados por los usuarios.";
                    } else {
                        $archivoUrl = $tActual['archivo_adjunto'];
                        if (isset($_FILES['archivo_adjunto']) && $_FILES['archivo_adjunto']['error'] === UPLOAD_ERR_OK) {
                            $fileTmp = $_FILES['archivo_adjunto']['tmp_name'];
                            $fileName = $_FILES['archivo_adjunto']['name'];
                            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                            $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

                            if (in_array($fileExt, $extPermitidas)) {
                                $dirUpload = __DIR__ . '/uploads/tickets/';
                                if (!is_dir($dirUpload)) @mkdir($dirUpload, 0777, true);
                                $nuevoNombre = 'ticket_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                                if (move_uploaded_file($fileTmp, $dirUpload . $nuevoNombre)) {
                                    $archivoUrl = 'uploads/tickets/' . $nuevoNombre;
                                }
                            }
                        }

                        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
                        $sqlFecha = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";
                        $estadoFinal = (!empty($nuevoEstado) && in_array($nuevoEstado, ['Abierto', 'En Proceso', 'Resuelto', 'Cerrado'])) ? $nuevoEstado : $tActual['estado'];

                        $stmtUpd = $pdo->prepare("
                            UPDATE tickets_soporte 
                            SET titulo = :titulo,
                                descripcion = :descripcion,
                                area_sistemas = :area,
                                prioridad = :prioridad,
                                estado = :estado,
                                archivo_adjunto = :archivo,
                                actualizado_en = $sqlFecha
                            WHERE id = :id
                        ");
                        $stmtUpd->execute([
                            ':titulo'      => $titulo,
                            ':descripcion' => $descripcion,
                            ':area'        => !empty($area) ? $area : $tActual['area_sistemas'],
                            ':prioridad'   => $prioridad,
                            ':estado'      => $estadoFinal,
                            ':archivo'     => $archivoUrl,
                            ':id'          => $ticketId
                        ]);

                        // Notificar modificación a Central de Sistemas
                        try {
                            $folioT = $tActual['folio'] ?: ('TK-' . $ticketId);
                            $tokenMod = 'GH_SISTEMAS_TICKETS_2026!';
                            $payloadCentral = [
                                'token'         => $tokenMod,
                                'accion'        => 'actualizar_ticket',
                                'folio'         => $folioT,
                                'titulo'        => $titulo,
                                'descripcion'   => $descripcion,
                                'area_sistemas' => !empty($area) ? $area : $tActual['area_sistemas'],
                                'prioridad'     => $prioridad,
                                'estado'        => $estadoFinal,
                                'modificado_por'=> $nombreUsuario
                            ];
                            $ctx = stream_context_create([
                                'http' => [
                                    'method'  => 'POST',
                                    'header'  => "Content-Type: application/json\r\nAuthorization: Bearer $tokenMod\r\n",
                                    'content' => json_encode($payloadCentral),
                                    'timeout' => 3
                                ]
                            ]);
                            @file_get_contents('https://portal.grupohuerta.mx/api_receptor_tickets.php', false, $ctx);
                        } catch (Throwable $eNet) {}

                        header("Location: tickets.php?ver=" . $ticketId . "&modificado=1");
                        exit;
                    }
                }
            } catch (Throwable $t) {
                $error = "Error al actualizar ticket: " . $t->getMessage();
            }
        } else {
            $error = "Por favor completa el título y la descripción del ticket.";
        }
    }

    // 3. Enviar nuevo mensaje / contestar al ticket
    elseif ($accion === 'enviar_mensaje') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $textoMensaje = trim($_POST['mensaje'] ?? '');
        $cambioEstado = trim($_POST['cambio_estado'] ?? '');

        if ($ticketId > 0 && !empty($textoMensaje)) {
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
                $stmtCheckT = $pdo->prepare("SELECT folio FROM tickets_soporte WHERE id = ?");
                $stmtCheckT->execute([$ticketId]);
                $folioT = $stmtCheckT->fetchColumn() ?: ('TK-' . $ticketId);

                $fechaActual = date('Y-m-d H:i:s');

                $stmtMsg = $pdo->prepare("
                    INSERT INTO tickets_mensajes 
                    (ticket_id, folio, autor_id, autor_nombre, autor_usuario, autor_rol, mensaje, archivo_adjunto, creado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $rolAutor = $esAdmin ? 'administrador' : 'solicitante';
                $stmtMsg->execute([
                    $ticketId,
                    $folioT,
                    $usuarioId,
                    $nombreUsuario,
                    $loginUsuario,
                    $rolAutor,
                    $textoMensaje,
                    $archivoMensaje,
                    $fechaActual
                ]);

                // Actualizar estado si el administrador seleccionó uno al contestar
                if ($esAdmin && !empty($cambioEstado) && in_array($cambioEstado, ['Abierto', 'En Proceso', 'Resuelto', 'Cerrado'])) {
                    $stmtUpSt = $pdo->prepare("UPDATE tickets_soporte SET estado = :est, actualizado_en = :act WHERE id = :id");
                    $stmtUpSt->execute([':est' => $cambioEstado, ':act' => $fechaActual, ':id' => $ticketId]);
                } else {
                    $stmtUp = $pdo->prepare("UPDATE tickets_soporte SET actualizado_en = :act WHERE id = :id");
                    $stmtUp->execute([':act' => $fechaActual, ':id' => $ticketId]);
                }

                // Notificar a Central de Sistemas en background
                try {
                    $tokenMsg = 'GH_SISTEMAS_TICKETS_2026!';
                    $agenciaMsg = $agenciaUsuario ?: $identidadActual['nombre'];
                    $postFields = [
                        'token'         => $tokenMsg,
                        'accion'        => 'agregar_mensaje',
                        'folio'         => $folioT,
                        'autor_nombre'  => $nombreUsuario,
                        'autor_usuario' => $loginUsuario,
                        'autor_rol'     => $rolAutor,
                        'autor_agencia' => $agenciaMsg,
                        'mensaje'       => $textoMensaje,
                        'creado_en'     => $fechaActual
                    ];
                    if ($esAdmin && !empty($cambioEstado)) {
                        $postFields['cambio_estado'] = $cambioEstado;
                    }
                    if ($archivoMensaje && file_exists(__DIR__ . '/' . $archivoMensaje)) {
                        $postFields['archivo_base64'] = base64_encode(file_get_contents(__DIR__ . '/' . $archivoMensaje));
                        $postFields['archivo_nombre'] = basename($archivoMensaje);
                    }

                    $chCentral = curl_init('https://portal.grupohuerta.mx/api_receptor_tickets.php?token=' . urlencode($tokenMsg));
                    curl_setopt_array($chCentral, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => http_build_query($postFields),
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer ' . $tokenMsg,
                            'Content-Type: application/x-www-form-urlencoded'
                        ]
                    ]);
                    @curl_exec($chCentral);
                    @curl_close($chCentral);
                } catch (Throwable $eNet) {}

                header("Location: tickets.php?ver=" . $ticketId . "&msg_ok=1#conversacion");
                exit;
            } catch (Throwable $t) {
                $error = "Error al guardar el mensaje: " . $t->getMessage();
            }
        }
    }

    // 2.5 Firmar Digitalmente Reporte de Servicio de Infraestructura por el Solicitante
    elseif ($accion === 'firmar_reporte_usuario') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $firmaBase64 = trim($_POST['reporte_firma_digital'] ?? '');
        $firmadoPor = trim($_POST['reporte_firmado_por'] ?? $nombreUsuario);

        if ($ticketId > 0 && !empty($firmaBase64)) {
            try {
                $stmtTk = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
                $stmtTk->execute([$ticketId]);
                $tk = $stmtTk->fetch(PDO::FETCH_ASSOC);

                if ($tk) {
                    $fechaFirma = date('Y-m-d H:i:s');
                    $folioTk = $tk['folio'] ?? ('TK-' . $ticketId);

                    // 1. Guardar localmente en la agencia
                    $stmtFirmaLoc = $pdo->prepare("
                        UPDATE tickets_soporte 
                        SET requiere_firma_reporte = 1,
                            reporte_firmado = 1,
                            reporte_firma_digital = :firma,
                            reporte_firmado_por = :por,
                            reporte_firmado_en = :fec_rep,
                            actualizado_en = :fec_act
                        WHERE id = :id
                    ");
                    $stmtFirmaLoc->execute([
                        ':firma'   => $firmaBase64,
                        ':por'     => $firmadoPor,
                        ':fec_rep' => $fechaFirma,
                        ':fec_act' => $fechaFirma,
                        ':id'      => $ticketId
                    ]);

                    // 2. Registrar en la conversación del ticket local
                    try {
                        $avisoFirma = "✍️ El solicitante " . $firmadoPor . " ha firmado digitalmente el Reporte Oficial de Servicio Técnico (Orden No. " . $folioTk . ").";
                        $stmtMsgLoc = $pdo->prepare("
                            INSERT INTO tickets_mensajes
                            (ticket_id, folio, autor_usuario, autor_nombre, autor_rol, mensaje, creado_en)
                            VALUES
                            (?, ?, ?, ?, 'solicitante', ?, ?)
                        ");
                        $stmtMsgLoc->execute([$ticketId, $folioTk, $loginUsuario, $firmadoPor, $avisoFirma, $fechaFirma]);
                    } catch (Throwable $eMsgL) {
                        try {
                            $stmtMsgLoc2 = $pdo->prepare("
                                INSERT INTO tickets_mensajes
                                (ticket_id, autor_usuario, autor_nombre, autor_rol, mensaje, creado_en)
                                VALUES
                                (?, ?, ?, 'solicitante', ?, ?)
                            ");
                            $stmtMsgLoc2->execute([$ticketId, $loginUsuario, $firmadoPor, $avisoFirma, $fechaFirma]);
                        } catch (Throwable $eMsgL2) {}
                    }

                    // 3. Enviar la firma inmediatamente a Central TI (portal.grupohuerta.mx)
                    try {
                        $identidad = obtenerIdentidadAgenciaActual($pdo);
                        $tokenCentral = $tokenAgencia ?: ($identidad['token'] ?? 'GH_SISTEMAS_TICKETS_2026!');
                        $urlCentral = 'https://portal.grupohuerta.mx/api_receptor_tickets.php';
                        $payloadFirma = [
                            'token'                 => $tokenCentral,
                            'accion'                => 'firmar_reporte',
                            'folio'                 => $folioTk,
                            'reporte_firma_digital' => $firmaBase64,
                            'reporte_firmado_por'   => $firmadoPor,
                            'reporte_firmado_en'    => $fechaFirma
                        ];

                        $chSign = curl_init($urlCentral);
                        curl_setopt_array($chSign, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => json_encode($payloadFirma),
                            CURLOPT_TIMEOUT        => 8,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $tokenCentral,
                                'User-Agent: PortalAgencia/2.0'
                            ]
                        ]);
                        @curl_exec($chSign);
                        @curl_close($chSign);
                    } catch (Throwable $eSignRem) {}

                    header("Location: tickets.php?ver=" . $ticketId . "&firmado_ok=1#reporte_servicio");
                    exit();
                } else {
                    $error = "El ticket no requiere firma de reporte o no fue encontrado.";
                }
            } catch (Throwable $eSignErr) {
                $error = "Error al guardar firma digital: " . $eSignErr->getMessage();
            }
        } else {
            $error = "La firma digital es requerida para confirmar el reporte.";
        }
    }

    // 3. Actualizar Estado / Seguimiento de Ticket (Solo Administrador)
    elseif ($accion === 'actualizar_ticket') {
        if (!$esAdmin) {
            $error = "No cuentas con autorización para modificar el seguimiento o estado del ticket.";
        } else {
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

                header("Location: tickets.php?ver=" . $ticketId . "&estado_ok=1");
                exit;
            } catch (Throwable $t) {
                $error = "Error al actualizar ticket: " . $t->getMessage();
            }
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

    // 4. Vaciar Todos los Tickets (Solo SuperAdmin)
    elseif ($accion === 'vaciar_todos_tickets' && $esAdmin) {
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
            if ($driver === 'sqlite') {
                $pdo->exec("DELETE FROM tickets_soporte;");
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name='tickets_soporte';");
            } else {
                $pdo->exec("TRUNCATE TABLE `tickets_soporte`;");
            }
            $dir = __DIR__ . '/uploads/tickets/';
            if (is_dir($dir)) {
                foreach (glob($dir . '*.*') as $f) {
                    if (basename($f) !== '.gitkeep') {
                        @unlink($f);
                    }
                }
            }
            $mensaje = "Se han eliminado todos los tickets y reseteado los folios.";
        } catch (Throwable $t) {
            $error = "Error al vaciar tickets: " . $t->getMessage();
        }
    }

    // Patrón Post/Redirect/Get: Previene duplicación de tickets al recargar con F5
    $_SESSION['flash_mensaje'] = $mensaje;
    $_SESSION['flash_error'] = $error;
    header("Location: tickets.php");
    exit();
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
            if (!$esAdmin) {
                $stmtExp = $pdo->prepare("
                    SELECT * FROM tickets_soporte 
                    WHERE solicitante_id = :uid 
                       OR (solicitante_usuario IS NOT NULL AND solicitante_usuario != '' AND solicitante_usuario = :ulogin)
                       OR (solicitante_email IS NOT NULL AND solicitante_email != '' AND solicitante_email = :uemail)
                    ORDER BY id DESC
                ");
                $stmtExp->execute([
                    ':uid'    => $usuarioId,
                    ':ulogin' => $loginUsuario,
                    ':uemail' => $emailUsuario
                ]);
            } else {
                $stmtExp = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
            }
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
        ['label' => 'Usuario / Perfil', 'width' => '130px', 'align' => 'left', 'is_text' => true],
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
            ['val' => (!empty($r['solicitante_usuario']) ? '@' . htmlspecialchars($r['solicitante_usuario']) : '---'), 'align' => 'left', 'is_text' => true],
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
        // Sincronizar automáticamente con Central (portal.grupohuerta.mx)
        $forzarSync = isset($_GET['sincronizar']) || isset($_GET['sync']);
        $tiempoUltimoSync = $_SESSION['ultimo_sync_tickets'] ?? 0;

        if ($forzarSync || (time() - $tiempoUltimoSync) > 10) {
            $numSync = sincronizarTicketsConCentral($pdo);
            if ($forzarSync && $numSync > 0) {
                $mensaje = "✅ Se sincronizaron $numSync tickets con la Dirección Central de Sistemas (portal.grupohuerta.mx).";
            }
        }

        // AISLAMIENTO DE TICKETS POR PERFIL DE USUARIO:
        // Si no es Administrador ni SuperAdmin, solo ve sus propios tickets subidos
        if (!$esAdmin) {
            $stmtUser = $pdo->prepare("
                SELECT * FROM tickets_soporte 
                WHERE solicitante_id = :uid 
                   OR (solicitante_usuario IS NOT NULL AND solicitante_usuario != '' AND LOWER(solicitante_usuario) = LOWER(:ulogin))
                   OR (solicitante_email IS NOT NULL AND solicitante_email != '' AND LOWER(solicitante_email) = LOWER(:uemail))
                   OR (solicitante_nombre IS NOT NULL AND solicitante_nombre != '' AND LOWER(solicitante_nombre) = LOWER(:unombre))
                ORDER BY id DESC
            ");
            $stmtUser->execute([
                ':uid'     => $usuarioId,
                ':ulogin'  => $loginUsuario,
                ':uemail'  => $emailUsuario,
                ':unombre' => $nombreUsuario
            ]);
            $tickets = $stmtUser->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Administradores y SuperAdmins ven todos los tickets de la agencia
            $stmtAll = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
            $tickets = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        $totalTickets = count($tickets);
        foreach ($tickets as $t) {
            $estLower = strtolower($t['estado'] ?? '');
            if ($estLower === 'abierto') $totalAbiertos++;
            elseif ($estLower === 'en proceso') $totalEnProceso++;
            elseif ($estLower === 'resuelto' || $estLower === 'cerrado') $totalResueltos++;
        }
    } catch (Throwable $e) {}
}

// Consulta de ticket seleccionado para la pestaña de detalle (soporta ID numérico o Folio oficial)
$verParam = trim($_GET['ver'] ?? ($_GET['id'] ?? ''));
$verTicketId = 0;
$ticketSeleccionado = null;
$mensajesSeleccionados = [];

if (!empty($verParam) && $pdo) {
    try {
        if (is_numeric($verParam) && intval($verParam) > 0) {
            $stmtSel = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ? LIMIT 1");
            $stmtSel->execute([intval($verParam)]);
        } else {
            $stmtSel = $pdo->prepare("SELECT * FROM tickets_soporte WHERE folio = ? LIMIT 1");
            $stmtSel->execute([$verParam]);
        }
        $ticketSeleccionado = $stmtSel ? $stmtSel->fetch(PDO::FETCH_ASSOC) : null;

        if ($ticketSeleccionado) {
            $verTicketId = (int)$ticketSeleccionado['id'];
            $folioActivo = $ticketSeleccionado['folio'] ?? '';

            if (!$esAdmin) {
                $pertenece = false;
                if (!empty($ticketSeleccionado['solicitante_id']) && (int)$ticketSeleccionado['solicitante_id'] === (int)$usuarioId) $pertenece = true;
                if (!empty($ticketSeleccionado['solicitante_usuario']) && !empty($loginUsuario) && strtolower($ticketSeleccionado['solicitante_usuario']) === strtolower($loginUsuario)) $pertenece = true;
                if (!empty($ticketSeleccionado['solicitante_email']) && !empty($emailUsuario) && strtolower($ticketSeleccionado['solicitante_email']) === strtolower($emailUsuario)) $pertenece = true;
                if (!empty($ticketSeleccionado['solicitante_nombre']) && !empty($nombreUsuario) && strtolower($ticketSeleccionado['solicitante_nombre']) === strtolower($nombreUsuario)) $pertenece = true;
                if (!empty($ticketSeleccionado['solicitante_agencia']) && !empty($agenciaUsuario)) {
                    if (stripos($ticketSeleccionado['solicitante_agencia'], $agenciaUsuario) !== false || stripos($agenciaUsuario, $ticketSeleccionado['solicitante_agencia']) !== false) {
                        $pertenece = true;
                    }
                }

                if (!$pertenece) {
                    $ticketSeleccionado = null;
                }
            }

            if ($ticketSeleccionado) {
                $mensajesRemotos = [];
                // Sincronizar en tiempo real los mensajes desde Central (portal.grupohuerta.mx)
                if (!empty($folioActivo)) {
                    $mensajesRemotos = sincronizarMensajesDeTicket($pdo, $verTicketId, $folioActivo);
                }

                // Cargar mensajes locales de la tabla tickets_mensajes
                $stmtM = $pdo->prepare("SELECT * FROM tickets_mensajes WHERE ticket_id = ? OR (folio IS NOT NULL AND folio != '' AND folio = ?) ORDER BY id ASC");
                $stmtM->execute([$verTicketId, $folioActivo]);
                $mensajesSeleccionados = $stmtM ? $stmtM->fetchAll(PDO::FETCH_ASSOC) : [];

                // Fusión garantizada: si la base de datos local está vacía o faltan mensajes de Central, integrarlos
                if (empty($mensajesSeleccionados) && !empty($mensajesRemotos)) {
                    $mensajesSeleccionados = $mensajesRemotos;
                } elseif (!empty($mensajesRemotos)) {
                    $textosLocales = array_map(function($lm) { 
                        return trim($lm['mensaje'] ?? '') . '|' . trim($lm['autor_nombre'] ?? ''); 
                    }, $mensajesSeleccionados);
                    foreach ($mensajesRemotos as $rm) {
                        $txtR = trim($rm['mensaje'] ?? '') . '|' . trim($rm['autor_nombre'] ?? '');
                        if (!empty($txtR) && !in_array($txtR, $textosLocales)) {
                            $mensajesSeleccionados[] = $rm;
                            $textosLocales[] = $txtR;
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {}
}

$tabActiva = 'lista';
if ($ticketSeleccionado) {
    $tabActiva = 'detalle';
} elseif (isset($_GET['nuevo'])) {
    $tabActiva = 'nuevo';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets Soporte Dirección Sistemas - Portal Grupo Huerta</title>
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
            cursor: pointer;
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

        .folio-tag {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 1.15rem;
            font-weight: 800;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.14);
            border: 1px solid rgba(56, 189, 248, 0.4);
            padding: 7px 15px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            letter-spacing: 0.6px;
            transition: all 0.2s ease;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            text-decoration: none;
        }
        .table-custom tbody tr:hover .folio-tag {
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.4) 0%, rgba(56, 189, 248, 0.3) 100%);
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 16px rgba(56, 189, 248, 0.55);
            transform: scale(1.02);
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

        .custom-nav-tabs {
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding-bottom: 12px;
        }
        .custom-tab-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #94a3b8 !important;
            border-radius: 12px !important;
            padding: 10px 20px !important;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .custom-tab-btn:hover {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8 !important;
            border-color: rgba(56, 189, 248, 0.35) !important;
        }
        .custom-tab-btn.active {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%) !important;
            color: #ffffff !important;
            border-color: #38bdf8 !important;
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.45);
        }
        .btn-close-tab {
            font-size: 1.15rem;
            line-height: 1;
            color: rgba(255, 255, 255, 0.6);
            padding: 2px 6px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-close-tab:hover {
            color: #ffffff;
            background: rgba(239, 68, 68, 0.35);
        }

        .detail-card, .form-card {
            background: linear-gradient(145deg, #0b1a30 0%, #071322 100%);
            border: 1px solid rgba(56, 189, 248, 0.22);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            padding: 30px 35px;
            margin-bottom: 24px;
        }
        @media (max-width: 768px) {
            .detail-card, .form-card {
                padding: 20px 18px;
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

    <!-- Mensajes de Notificación y Alertas de Acción -->
    <?php if (isset($_GET['creado'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border-left: 4px solid #22c55e !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> ¡Tu ticket ha sido registrado y enviado exitosamente a la Dirección de Sistemas!
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['msg_ok'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border-left: 4px solid #22c55e !important;">
            <i class="bi bi-chat-dots-fill me-2 fs-5"></i> Tu mensaje ha sido enviado y registrado en la conversación del ticket.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['modificado'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border-left: 4px solid #22c55e !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> ¡Ticket modificado exitosamente! Los cambios y evidencias han sido guardados y sincronizados.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['estado_ok'])): ?>
        <div class="alert alert-info alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4" role="alert" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border-left: 4px solid #38bdf8 !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> Estado y notas del ticket actualizados correctamente.
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
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

    <!-- PESTAÑAS DE NAVEGACIÓN INTERNAS (EN LA MISMA VENTANA) -->
    <ul class="nav nav-pills custom-nav-tabs mb-4 gap-2" id="pestanasTickets" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link custom-tab-btn <?php echo ($tabActiva === 'lista') ? 'active' : ''; ?> d-flex align-items-center gap-2" id="tab-btn-lista" data-bs-toggle="pill" data-bs-target="#tab-pane-lista" type="button" role="tab" onclick="actualizarUrl('lista')">
                <i class="bi bi-collection-fill"></i>
                <span>Mis Tickets</span>
                <span class="badge rounded-pill bg-dark border text-info ms-1"><?php echo count($tickets); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link custom-tab-btn <?php echo ($tabActiva === 'nuevo') ? 'active' : ''; ?> d-flex align-items-center gap-2" id="tab-btn-nuevo" data-bs-toggle="pill" data-bs-target="#tab-pane-nuevo" type="button" role="tab" onclick="actualizarUrl('nuevo')">
                <i class="bi bi-plus-circle-fill text-info"></i>
                <span>Levantar Nuevo Ticket</span>
            </button>
        </li>
        <?php if ($ticketSeleccionado): ?>
            <li class="nav-item" role="presentation" id="tab-item-detalle">
                <button class="nav-link custom-tab-btn <?php echo ($tabActiva === 'detalle') ? 'active' : ''; ?> d-flex align-items-center gap-2" id="tab-btn-detalle" data-bs-toggle="pill" data-bs-target="#tab-pane-detalle" type="button" role="tab">
                    <i class="bi bi-chat-left-dots-fill text-warning"></i>
                    <span>Ticket <?php echo htmlspecialchars($ticketSeleccionado['folio'] ?? ('#TK-' . $ticketSeleccionado['id'])); ?></span>
                    <a href="tickets.php" class="btn-close-tab ms-1" title="Cerrar pestaña y volver a la lista">&times;</a>
                </button>
            </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content" id="pestanasTicketsContent">

        <!-- ==========================================
             PESTAÑA 1: LISTADO DE MIS TICKETS
             ========================================== -->
        <div class="tab-pane fade <?php echo ($tabActiva === 'lista') ? 'show active' : ''; ?>" id="tab-pane-lista" role="tabpanel">
            <!-- Encabezado Principal y Botón Destacado "Levantar Nuevo Ticket" -->
            <div class="card border-0 rounded-4 shadow-sm mb-4 p-3 p-md-4" style="background: linear-gradient(145deg, #0f2744 0%, #091a30 100%); border: 1px solid rgba(56, 189, 248, 0.25) !important;">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                            <?php if (!$esAdmin): ?>
                                <span class="badge rounded-pill bg-primary bg-opacity-25 text-info border border-info border-opacity-25 px-3 py-1.5" style="font-size: 0.8rem;">
                                    <i class="bi bi-person-fill me-1"></i> Tickets personales: <strong class="text-white">@<?php echo htmlspecialchars($loginUsuario ?: $nombreUsuario); ?></strong>
                                </span>
                            <?php else: ?>
                                <span class="badge rounded-pill bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-25 px-3 py-1.5" style="font-size: 0.8rem;">
                                    <i class="bi bi-shield-check me-1"></i> Modo Administrador: <strong class="text-white">Todos los tickets</strong>
                                </span>
                            <?php endif; ?>
                            <span class="badge rounded-pill bg-dark bg-opacity-50 text-secondary border border-secondary border-opacity-25 px-2.5 py-1.5" style="font-size: 0.78rem;">
                                <i class="bi bi-collection me-1"></i> <?php echo count($tickets); ?> registrados
                            </span>
                        </div>
                        <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                            <i class="bi bi-ticket-detailed-fill text-info"></i> Mis Tickets de Soporte
                        </h3>
                        <p class="text-secondary small mb-0">Listado y seguimiento de requerimientos e incidencias para la Dirección de Sistemas.</p>
                    </div>
                    <div class="text-md-end">
                        <button type="button" onclick="cambiarPestana('nuevo')" class="btn btn-primary btn-lg rounded-4 px-4 py-3 fw-bold d-inline-flex align-items-center justify-content-center gap-2.5 shadow-lg w-100 w-md-auto" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); border: 1px solid rgba(56, 189, 248, 0.4); font-size: 1.15rem; min-height: 56px; box-shadow: 0 6px 20px rgba(2, 132, 199, 0.45) !important;">
                            <i class="bi bi-plus-circle-fill fs-4 text-white"></i>
                            <span>Levantar Nuevo Ticket</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Barra de Búsqueda, Filtros y Acciones Secundarias -->
            <div class="row g-3 align-items-center mb-4">
                <div class="col-12 col-md-7 col-lg-8">
                    <div class="search-box-wrapper">
                        <i class="bi bi-search text-secondary"></i>
                        <input type="text" id="buscadorTickets" class="search-input" placeholder="Buscar por folio, asunto, solicitante..." onkeyup="filtrarTicketsEnVivo()">
                        <button type="button" class="btn btn-link btn-sm text-secondary p-0" onclick="limpiarBuscador()" title="Limpiar">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>

                <div class="col-12 col-md-5 col-lg-4 d-flex justify-content-md-end gap-2 flex-wrap">
                    <a href="tickets.php?sincronizar=1" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-semibold d-inline-flex align-items-center gap-1.5 shadow-sm" title="Consultar avances y respuestas en vivo de la Dirección de Sistemas">
                        <i class="bi bi-arrow-repeat"></i> Sincronizar
                    </a>
                    <?php if ($esAdmin): ?>
                    <a href="tickets.php?accion=exportar_excel" class="btn btn-success btn-sm rounded-3 px-3 fw-semibold d-inline-flex align-items-center gap-1.5 shadow-sm" style="background: #16a34a; border-color: #16a34a;" title="Exportar a Microsoft Excel">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </a>
                    <?php endif; ?>
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
                                        data-solicitante="<?php echo htmlspecialchars(mb_strtolower(($t['solicitante_nombre'] ?? '') . ' ' . ($t['solicitante_usuario'] ?? ''))); ?>"
                                        data-estado="<?php echo htmlspecialchars($estLower); ?>" onclick="abrirTicket(<?php echo $t['id']; ?>, event)" title="Haz clic en cualquier parte para entrar a este ticket">
                                        
                                        <!-- Folio -->
                                        <td>
                                            <div class="folio-tag" title="Entrar a este ticket">
                                                <i class="bi bi-ticket-perforated-fill text-info fs-5"></i>
                                                <span><?php echo htmlspecialchars($t['folio'] ?? ('#TK-' . $t['id'])); ?></span>
                                            </div>
                                            <?php if (!empty($t['creado_en'])): ?>
                                                <div class="text-secondary small mt-1" style="font-size: 0.7rem;">
                                                    <i class="bi bi-calendar3 me-1"></i><?php echo formatearFechaHoraMensaje($t['creado_en'], false); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Área Destino -->
                                        <td>
                                            <span class="area-badge" style="background: <?php echo $areaConf['bg']; ?>; color: <?php echo $areaConf['color']; ?>; border: 1px solid <?php echo $areaConf['border']; ?>;">
                                                <i class="bi <?php echo $areaConf['icono']; ?>"></i> <?php echo $areaConf['nombre']; ?>
                                            </span>
                                        </td>

                                        <!-- Título / Asunto -->
                                        <td>
                                            <div>
                                                <a href="tickets.php?ver=<?php echo $t['id']; ?>" class="fw-bold text-white fs-6 text-decoration-none" title="Ver requerimiento y responder">
                                                    <?php echo htmlspecialchars($t['titulo']); ?>
                                                    <?php if (!empty($t['archivo_adjunto'])): ?>
                                                        <i class="bi bi-paperclip text-info ms-1" title="Contiene archivo adjunto"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </div>
                                            <div class="text-secondary small text-truncate" style="max-width: 380px;">
                                                <?php echo htmlspecialchars(mb_substr($t['descripcion'], 0, 90)); ?><?php echo mb_strlen($t['descripcion']) > 90 ? '...' : ''; ?>
                                            </div>
                                            <?php if (!empty(trim($t['notas_resolucion'] ?? ''))): ?>
                                                <div class="mt-2 p-2 rounded-2" style="background: rgba(14, 165, 233, 0.12); border-left: 3px solid #38bdf8; max-width: 440px;">
                                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                                        <span class="small fw-bold text-info" style="font-size: 0.78rem;">
                                                             <i class="bi bi-headset me-1"></i> Respuesta TI Central <?php if (!empty($t['asignado_a'])): ?>— <strong><?php echo htmlspecialchars($t['asignado_a']); ?></strong><?php endif; ?>:
                                                        </span>
                                                        <?php if (!empty($t['actualizado_en'])): ?>
                                                            <span class="text-secondary" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?php echo formatearFechaHoraMensaje($t['actualizado_en']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-white small" style="font-size: 0.82rem; white-space: pre-line; line-height: 1.4;">
                                                        <?php echo htmlspecialchars($t['notas_resolucion']); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
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
                                            <div class="fw-semibold text-white d-flex align-items-center gap-1.5 flex-wrap">
                                                <span><?php echo htmlspecialchars($t['solicitante_nombre'] ?? 'Usuario'); ?></span>
                                                <?php if (!empty($t['solicitante_usuario'])): ?>
                                                    <span class="badge bg-dark border text-info font-monospace py-0 px-1" style="font-size: 0.68rem;" title="Usuario del sistema: <?php echo htmlspecialchars($t['solicitante_usuario']); ?>">
                                                        @<?php echo htmlspecialchars($t['solicitante_usuario']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-secondary" style="font-size: 0.73rem;">
                                                <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($t['solicitante_agencia'] ?? 'General'); ?>
                                                <?php if (!empty($t['solicitante_email'])): ?>
                                                    &bull; <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($t['solicitante_email']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Acciones -->
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1.5 align-items-center">
                                                <!-- Ver Detalle / Contestar en la Misma Pantalla -->
                                                <a href="tickets.php?ver=<?php echo $t['id']; ?>" class="btn-action-icon text-info" title="Ver Detalle y Contestar Ticket">
                                                    <i class="bi bi-chat-left-dots-fill"></i>
                                                </a>

                                                <?php 
                                                    $esPropioFila = $esAdmin || (
                                                        (!empty($t['solicitante_id']) && (int)$t['solicitante_id'] === (int)$usuarioId) ||
                                                        (!empty($t['solicitante_usuario']) && !empty($loginUsuario) && strtolower($t['solicitante_usuario']) === strtolower($loginUsuario)) ||
                                                        (!empty($t['solicitante_nombre']) && !empty($nombreUsuario) && strtolower($t['solicitante_nombre']) === strtolower($nombreUsuario))
                                                    );
                                                ?>
                                                <?php if ($esAdmin): ?>
                                                    <!-- Modificar Ticket (Solo Administrador) -->
                                                    <a href="tickets.php?ver=<?php echo $t['id']; ?>&editar=1" class="btn-action-icon text-warning" title="Modificar este Ticket">
                                                        <i class="bi bi-pencil-fill"></i>
                                                    </a>
                                                <?php endif; ?>
                                                
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

        <!-- ==========================================
             PESTAÑA 2: LEVANTAR NUEVO TICKET
             ========================================== -->
        <div class="tab-pane fade <?php echo ($tabActiva === 'nuevo') ? 'show active' : ''; ?>" id="tab-pane-nuevo" role="tabpanel">
            <div class="form-card mx-auto" style="max-width: 960px;">
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary border-opacity-25 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 rounded-4" style="background: rgba(2, 132, 199, 0.2); border: 1px solid rgba(56, 189, 248, 0.4); color: #38bdf8; font-size: 1.6rem;">
                            <i class="bi bi-ticket-detailed-fill"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-white mb-1">Levantar Nuevo Ticket de Soporte</h4>
                            <p class="text-secondary small mb-0">Requerimientos, incidencias y asistencia para la Dirección de Sistemas TI.</p>
                        </div>
                    </div>
                    <button type="button" onclick="cambiarPestana('lista')" class="btn btn-outline-secondary btn-sm rounded-3 text-white">
                        <i class="bi bi-arrow-left me-1"></i> Volver a Mis Tickets
                    </button>
                </div>

                <form method="POST" action="tickets.php" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="crear_ticket">

                    <div class="row g-4">
                        <!-- Área de Sistemas -->
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                <i class="bi bi-cpu-fill text-info me-1"></i> Área de Sistemas Asignada <span class="text-danger">*</span>
                            </label>
                            <select name="area_sistemas" class="form-select rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" required>
                                <option value="" disabled selected>Selecciona el área de soporte...</option>
                                <option value="INFRAESTRUCTURA">🖥️ INFRAESTRUCTURA (Redes, Servidores, Impresoras, Equipos)</option>
                                <option value="DESARROLLO">💻 DESARROLLO (Portales, Sistemas Web, Bases de Datos, Módulos)</option>
                                <option value="CYBERSEGURIDAD">🛡️ CYBERSEGURIDAD (Accesos, Antivirus, Bloqueos, Cuentas)</option>
                                <option value="REDES SOCIALES">📱 REDES SOCIALES (Marketing Digital, Publicaciones, Medios)</option>
                                <option value="AUDITORIA">📋 AUDITORIA (Procesos, Revisiones TI, Cumplimiento)</option>
                                <option value="CORPORATIVO">🏢 CORPORATIVO (Dirección General y Enlace Central)</option>
                            </select>
                        </div>

                        <!-- Prioridad -->
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                <i class="bi bi-reception-3 text-warning me-1"></i> Nivel de Prioridad <span class="text-danger">*</span>
                            </label>
                            <select name="prioridad" class="form-select rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" required>
                                <option value="Baja">🟢 Baja (Requerimiento general, consulta no urgente)</option>
                                <option value="Media" selected>🟡 Media (Operación regular normal)</option>
                                <option value="Alta">🟠 Alta (Afecta parcialmente la operación)</option>
                                <option value="Urgente">🔴 Urgente (Operación completamente detenida / Bloqueo crítico)</option>
                            </select>
                        </div>

                        <!-- Título / Asunto -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                <i class="bi bi-card-heading text-info me-1"></i> Título / Asunto Breve <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="titulo" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Ej. Falla en enlace de Internet / Acceso a base de datos / Falla en impresora..." required>
                        </div>

                        <!-- Descripción Detallada -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                <i class="bi bi-text-paragraph text-info me-1"></i> Descripción Detallada del Requerimiento o Falla <span class="text-danger">*</span>
                            </label>
                            <textarea name="descripcion" rows="5" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Describe detalladamente qué necesitas o qué error ocurre..." required></textarea>
                        </div>

                        <!-- Archivo Adjunto -->
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                <i class="bi bi-paperclip text-info me-1"></i> Adjuntar Evidencia o Captura (Opcional)
                            </label>
                            <input type="file" name="archivo_adjunto" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
                            <div class="text-secondary small mt-1" style="font-size: 0.78rem;">Formatos permitidos: Imágenes (PNG, JPG, WEBP), PDF, Word, Excel, ZIP.</div>
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
                            <input type="email" name="solicitante_email" value="<?php echo htmlspecialchars($_SESSION['usuario_email'] ?? ''); ?>" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #94a3b8;" readonly>
                        </div>

                        <!-- Botones de Acción -->
                        <div class="col-12 pt-3 border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <button type="button" onclick="cambiarPestana('lista')" class="btn btn-outline-secondary px-4 py-2.5 rounded-3 text-white">
                                <i class="bi bi-arrow-left me-1"></i> Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary btn-lg px-5 py-2.5 rounded-3 fw-bold shadow-lg" style="background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%); border: none;">
                                <i class="bi bi-send-fill me-1"></i> Registrar y Enviar Ticket
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ==========================================
             PESTAÑA 3: DETALLE, SEGUIMIENTO Y RESPUESTAS
             ========================================== -->
        <?php if ($ticketSeleccionado): 
            $tSelAreaKey = strtoupper(trim($ticketSeleccionado['area_sistemas'] ?? 'DESARROLLO'));
            $tSelAreaConf = $AREAS_SISTEMAS[$tSelAreaKey] ?? [
                'nombre' => $tSelAreaKey, 'icono' => 'bi-gear-fill', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.15)', 'border' => 'rgba(56, 189, 248, 0.35)'
            ];

            $tSelEstLower = strtolower($ticketSeleccionado['estado'] ?? 'abierto');
            $tSelEstClass = 'bg-warning text-dark';
            if ($tSelEstLower === 'en proceso') $tSelEstClass = 'bg-info text-dark';
            elseif ($tSelEstLower === 'resuelto') $tSelEstClass = 'bg-success text-white';
            elseif ($tSelEstLower === 'cerrado') $tSelEstClass = 'bg-secondary text-white';

            $tSelPrioLower = strtolower($ticketSeleccionado['prioridad'] ?? 'media');
            $tSelPrioClass = 'bg-primary text-white';
            if ($tSelPrioLower === 'urgente') $tSelPrioClass = 'bg-danger text-white';
            elseif ($tSelPrioLower === 'alta') $tSelPrioClass = 'bg-warning text-dark';
            elseif ($tSelPrioLower === 'baja') $tSelPrioClass = 'bg-info text-dark';
        ?>
            <div class="tab-pane fade <?php echo ($tabActiva === 'detalle') ? 'show active' : ''; ?>" id="tab-pane-detalle" role="tabpanel">
                
                <!-- Encabezado del Ticket Seleccionado -->
                <div class="detail-card">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 pb-3 border-bottom border-secondary border-opacity-25">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                <span class="badge rounded-pill" style="background: <?php echo $tSelAreaConf['bg']; ?>; color: <?php echo $tSelAreaConf['color']; ?>; border: 1px solid <?php echo $tSelAreaConf['border']; ?>; font-size: 0.85rem;">
                                    <i class="bi <?php echo $tSelAreaConf['icono']; ?> me-1"></i> <?php echo $tSelAreaConf['nombre']; ?>
                                </span>
                                <span class="badge rounded-pill <?php echo $tSelPrioClass; ?> px-2.5 py-1" style="font-size: 0.82rem;">
                                    <i class="bi bi-exclamation-circle me-1"></i> Prioridad <?php echo htmlspecialchars($ticketSeleccionado['prioridad'] ?? 'Media'); ?>
                                </span>
                                <span class="badge rounded-pill <?php echo $tSelEstClass; ?> px-3 py-1" style="font-size: 0.85rem;">
                                    <i class="bi bi-clock-history me-1"></i> <?php echo htmlspecialchars($ticketSeleccionado['estado'] ?? 'Abierto'); ?>
                                </span>
                                <?php if (!empty($ticketSeleccionado['reporte_firmado']) || !empty($ticketSeleccionado['reporte_firma_digital'])): ?>
                                    <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketSeleccionado['id']; ?>&auto=1" target="_blank" class="badge rounded-pill bg-danger px-3 py-1.5 text-decoration-none fw-bold shadow-sm d-inline-flex align-items-center gap-1.5" style="font-size: 0.85rem;" title="Descargar Reporte Oficial en PDF">
                                        <i class="bi bi-file-earmark-pdf-fill"></i> Descargar PDF Firmado
                                    </a>
                                <?php elseif (!empty($ticketSeleccionado['requiere_firma_reporte']) || !empty($ticketSeleccionado['reporte_trabajos_realizados'])): ?>
                                    <a href="#reporte_servicio" class="badge rounded-pill bg-warning text-dark px-3 py-1.5 text-decoration-none fw-bold shadow-sm d-inline-flex align-items-center gap-1" style="font-size: 0.85rem;">
                                        <i class="bi bi-pen-fill"></i> Firma Digital Pendiente
                                    </a>
                                <?php endif; ?>
                            </div>
                            <h2 class="fw-bold text-white mb-1"><?php echo htmlspecialchars($ticketSeleccionado['titulo']); ?></h2>
                            <div class="text-secondary small">
                                <i class="bi bi-calendar3 me-1"></i> Creado el <?php echo formatearFechaHoraMensaje($ticketSeleccionado['creado_en']); ?>
                                <?php if (!empty($ticketSeleccionado['actualizado_en'])): ?>
                                    &bull; <i class="bi bi-clock-history me-1"></i> Última actividad: <?php echo formatearFechaHoraMensaje($ticketSeleccionado['actualizado_en']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="p-2.5 rounded-3 text-end" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.1);">
                                <div class="text-secondary small fw-bold" style="font-size: 0.7rem;">FOLIO</div>
                                <div class="fs-4 font-monospace fw-bold text-info"><?php echo htmlspecialchars($ticketSeleccionado['folio'] ?? ('#TK-' . $ticketSeleccionado['id'])); ?></div>
                            </div>
                            <?php 
                                $puedeEditarTicket = $esAdmin;
                            ?>
                            <?php if ($puedeEditarTicket): ?>
                                <button type="button" class="btn btn-warning btn-sm rounded-3 px-3 py-2 fw-bold text-dark d-inline-flex align-items-center gap-1.5 shadow" data-bs-toggle="modal" data-bs-target="#modalModificarTicket" title="Modificar requerimiento o estado del ticket">
                                    <i class="bi bi-pencil-square"></i> Modificar Ticket
                                </button>
                            <?php endif; ?>
                            <button type="button" onclick="cambiarPestana('lista')" class="btn btn-outline-secondary btn-sm rounded-3 text-white px-3 py-2" title="Volver al listado">
                                <i class="bi bi-arrow-left me-1"></i> Volver a Mis Tickets
                            </button>
                        </div>
                    </div>

                    <!-- Metadatos de asignación -->
                    <div class="row g-3 mt-2">
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                                <div class="text-secondary small" style="font-size: 0.72rem;">SOLICITANTE</div>
                                <div class="fw-semibold text-white"><?php echo htmlspecialchars($ticketSeleccionado['solicitante_nombre'] ?? 'Usuario'); ?></div>
                                <div class="text-info small" style="font-size: 0.75rem;">@<?php echo htmlspecialchars($ticketSeleccionado['solicitante_usuario'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                                <div class="text-secondary small" style="font-size: 0.72rem;">SUCURSAL / AGENCIA</div>
                                <div class="fw-semibold text-white"><?php echo htmlspecialchars($ticketSeleccionado['solicitante_agencia'] ?? 'General'); ?></div>
                                <div class="text-secondary small text-truncate" style="font-size: 0.75rem;"><?php echo htmlspecialchars($ticketSeleccionado['solicitante_email'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                                <div class="text-secondary small" style="font-size: 0.72rem;">TÉCNICO ASIGNADO</div>
                                <div class="fw-semibold text-white"><?php echo !empty($ticketSeleccionado['asignado_a']) ? htmlspecialchars($ticketSeleccionado['asignado_a']) : '<span class="text-secondary fw-normal">Por asignar</span>'; ?></div>
                                <div class="text-secondary small" style="font-size: 0.75rem;">Sistemas Grupo Huerta</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.07);">
                                <div class="text-secondary small" style="font-size: 0.72rem;">ESTADO ACTUAL</div>
                                <div class="fw-bold <?php echo strpos($tSelEstClass, 'text-dark') !== false ? 'text-warning' : 'text-success'; ?>"><?php echo htmlspecialchars($ticketSeleccionado['estado'] ?? 'Abierto'); ?></div>
                                <div class="text-secondary small" style="font-size: 0.75rem;">Atención Central TI</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detalle del Requerimiento Original -->
                <div class="detail-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="fw-bold text-info mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-card-text"></i> Descripción del Requerimiento Original
                        </h5>
                        <?php if ($puedeEditarTicket): ?>
                            <button type="button" class="btn btn-outline-warning btn-sm rounded-pill py-0.5 px-2.5" style="font-size: 0.78rem;" data-bs-toggle="modal" data-bs-target="#modalModificarTicket">
                                <i class="bi bi-pencil me-1"></i> Editar Requerimiento
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="p-3.5 rounded-3 text-white" style="background: #061325; border: 1px solid rgba(255, 255, 255, 0.1); font-size: 0.98rem; line-height: 1.6; white-space: pre-line;">
                        <?php echo htmlspecialchars($ticketSeleccionado['descripcion']); ?>
                    </div>

                    <?php if (!empty($ticketSeleccionado['archivo_adjunto'])): 
                        $rawAdjT = trim($ticketSeleccionado['archivo_adjunto']);
                        $extT = strtolower(pathinfo($rawAdjT, PATHINFO_EXTENSION));
                        $esImgT = in_array($extT, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                        $esPdfT = ($extT === 'pdf');

                        if (str_starts_with($rawAdjT, 'http://') || str_starts_with($rawAdjT, 'https://')) {
                            $urlAdjT = $rawAdjT;
                        } elseif (file_exists(__DIR__ . '/' . $rawAdjT)) {
                            $urlAdjT = $rawAdjT;
                        } else {
                            $urlAdjT = 'https://portal.grupohuerta.mx/' . ltrim($rawAdjT, '/');
                        }
                        $nombreAdjT = basename($rawAdjT);
                    ?>
                        <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                            <div class="text-secondary small fw-bold text-uppercase mb-2">
                                <i class="bi bi-paperclip text-info me-1"></i> Evidencia / Archivo Adjunto Original
                            </div>
                            <?php if ($esPdfT): ?>
                                <div class="d-flex align-items-center justify-content-between p-2.5 px-3 rounded-3" style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); max-width: 520px;">
                                    <div class="d-flex align-items-center gap-2.5 overflow-hidden">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger fs-2 flex-shrink-0"></i>
                                        <div class="text-truncate">
                                            <div class="text-white fw-bold small text-truncate" title="<?php echo htmlspecialchars($nombreAdjT); ?>">
                                                <?php echo htmlspecialchars($nombreAdjT); ?>
                                            </div>
                                            <div class="text-secondary" style="font-size: 0.73rem;">Documento PDF &bull; Documento adjunto</div>
                                        </div>
                                    </div>
                                    <a href="<?php echo htmlspecialchars($urlAdjT); ?>" target="_blank" download class="btn btn-sm btn-danger rounded-pill px-3 py-1 text-nowrap ms-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.78rem;">
                                        <i class="bi bi-file-pdf me-1"></i> Abrir / Descargar PDF
                                    </a>
                                </div>
                            <?php elseif ($esImgT): ?>
                                <div class="mb-2">
                                    <a href="<?php echo htmlspecialchars($urlAdjT); ?>" target="_blank" title="Ver imagen completa">
                                        <img src="<?php echo htmlspecialchars($urlAdjT); ?>" alt="Evidencia" class="preview-img" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px;">
                                    </a>
                                </div>
                                <div>
                                    <a href="<?php echo htmlspecialchars($urlAdjT); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-semibold">
                                        <i class="bi bi-image me-1"></i> Ver imagen completa
                                    </a>
                                </div>
                            <?php else: ?>
                                <div>
                                    <a href="<?php echo htmlspecialchars($urlAdjT); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-semibold">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Ver / Descargar Archivo (<?php echo strtoupper($extT); ?>)
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Respuesta de Central Sistemas si existe -->
                <?php if (!empty(trim($ticketSeleccionado['notas_resolucion'] ?? '')) || !empty($ticketSeleccionado['evidencia_resolucion'])): ?>
                    <div class="detail-card" style="border-color: rgba(56, 189, 248, 0.45); background: linear-gradient(145deg, #09213d 0%, #06182c 100%);">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h5 class="fw-bold text-info mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-headset"></i> Respuesta Oficial de la Dirección Central de Sistemas
                            </h5>
                            <span class="badge bg-primary rounded-pill px-3 py-1">
                                <?php echo !empty($ticketSeleccionado['asignado_a']) ? htmlspecialchars($ticketSeleccionado['asignado_a']) : 'Dirección Central TI'; ?>
                            </span>
                        </div>
                        <?php if (!empty(trim($ticketSeleccionado['notas_resolucion'] ?? ''))): ?>
                            <div class="p-3 rounded-3 text-white mt-3" style="background: rgba(3, 11, 23, 0.7); border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.95rem; line-height: 1.6; white-space: pre-line;">
                                <?php echo htmlspecialchars($ticketSeleccionado['notas_resolucion']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($ticketSeleccionado['evidencia_resolucion'])): 
                            $rawEvT = trim($ticketSeleccionado['evidencia_resolucion']);
                            $extEvT = strtolower(pathinfo($rawEvT, PATHINFO_EXTENSION));
                            $esEvPdf = ($extEvT === 'pdf');
                            $esEvImg = in_array($extEvT, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                            $esEvXls = in_array($extEvT, ['xls', 'xlsx', 'csv']);

                            if (str_starts_with($rawEvT, 'http://') || str_starts_with($rawEvT, 'https://')) {
                                $urlEvT = $rawEvT;
                            } elseif (file_exists(__DIR__ . '/' . $rawEvT)) {
                                $urlEvT = $rawEvT;
                            } else {
                                $urlEvT = 'https://portal.grupohuerta.mx/' . ltrim($rawEvT, '/');
                            }
                            $nombreEvT = basename($rawEvT);
                        ?>
                            <div class="mt-3 pt-3 border-top border-secondary border-opacity-25">
                                <div class="text-secondary small fw-bold text-uppercase mb-2">
                                    <i class="bi bi-paperclip text-info me-1"></i> Evidencia Adjunta de la Solución:
                                </div>
                                <?php if ($esEvPdf): ?>
                                    <div class="d-flex align-items-center justify-content-between p-2.5 px-3 rounded-3" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); max-width: 520px;">
                                        <div class="d-flex align-items-center gap-2.5 overflow-hidden">
                                            <i class="bi bi-file-earmark-pdf-fill text-danger fs-2 flex-shrink-0"></i>
                                            <div class="text-truncate">
                                                <div class="text-white fw-bold small text-truncate" title="<?php echo htmlspecialchars($nombreEvT); ?>">
                                                    <?php echo htmlspecialchars($nombreEvT); ?>
                                                </div>
                                                <div class="text-secondary" style="font-size: 0.73rem;">Documento PDF &bull; Evidencia técnica</div>
                                            </div>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($urlEvT); ?>" target="_blank" download class="btn btn-sm btn-danger rounded-pill px-3 py-1 text-nowrap ms-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.78rem;">
                                            <i class="bi bi-file-pdf me-1"></i> Abrir / Descargar PDF
                                        </a>
                                    </div>
                                <?php elseif ($esEvXls): ?>
                                    <div class="d-flex align-items-center justify-content-between p-2.5 px-3 rounded-3" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); max-width: 520px;">
                                        <div class="d-flex align-items-center gap-2.5 overflow-hidden">
                                            <i class="bi bi-file-earmark-excel-fill text-success fs-2 flex-shrink-0"></i>
                                            <div class="text-truncate">
                                                <div class="text-white fw-bold small text-truncate" title="<?php echo htmlspecialchars($nombreEvT); ?>">
                                                    <?php echo htmlspecialchars($nombreEvT); ?>
                                                </div>
                                                <div class="text-secondary" style="font-size: 0.73rem;">Hoja de cálculo / Reporte Excel</div>
                                            </div>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($urlEvT); ?>" target="_blank" download class="btn btn-sm btn-success rounded-pill px-3 py-1 text-nowrap ms-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.78rem;">
                                            <i class="bi bi-download me-1"></i> Descargar Excel
                                        </a>
                                    </div>
                                <?php elseif ($esEvImg): ?>
                                    <div class="mb-2">
                                        <a href="<?php echo htmlspecialchars($urlEvT); ?>" target="_blank" title="Ver imagen completa">
                                            <img src="<?php echo htmlspecialchars($urlEvT); ?>" alt="Evidencia" class="preview-img" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px;">
                                        </a>
                                    </div>
                                    <div>
                                        <a href="<?php echo htmlspecialchars($urlEvT); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-semibold">
                                            <i class="bi bi-image me-1"></i> Ver imagen completa
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <a href="<?php echo htmlspecialchars($urlEvT); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-semibold">
                                            <i class="bi bi-box-arrow-up-right me-1"></i> Ver / Descargar Evidencia (<?php echo strtoupper($extEvT); ?>)
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- ======================================================= -->
                <!-- APARTADO DE FIRMA DIGITAL / REPORTE DE SERVICIO TÉCNICO -->
                <!-- ======================================================= -->
                <?php 
                $mostrarSeccionReporte = !empty($ticketSeleccionado['requiere_firma_reporte']) 
                                      || !empty($ticketSeleccionado['reporte_firmado']) 
                                      || !empty($ticketSeleccionado['reporte_firma_digital'])
                                      || !empty($ticketSeleccionado['reporte_trabajos_realizados']);
                if ($mostrarSeccionReporte): 
                    $repOrdenNo = !empty($ticketSeleccionado['reporte_orden_no']) ? $ticketSeleccionado['reporte_orden_no'] : ($ticketSeleccionado['folio'] ?? ('TK-' . $ticketSeleccionado['id']));
                    $repProblema = !empty($ticketSeleccionado['reporte_problema']) ? $ticketSeleccionado['reporte_problema'] : ($ticketSeleccionado['descripcion'] ?? '');
                    $repTrabajoSol = $ticketSeleccionado['reporte_trabajo_solicitado'] ?? '';
                    $repObservaciones = $ticketSeleccionado['reporte_observaciones'] ?? '';
                    $repTrabajosReal = $ticketSeleccionado['reporte_trabajos_realizados'] ?? '';
                    $repTecnico = !empty($ticketSeleccionado['reporte_tecnico_nombre']) ? $ticketSeleccionado['reporte_tecnico_nombre'] : (!empty($ticketSeleccionado['asignado_a']) ? $ticketSeleccionado['asignado_a'] : 'Dirección Central de Sistemas');
                    $repFirmado = !empty($ticketSeleccionado['reporte_firmado']) || !empty($ticketSeleccionado['reporte_firma_digital']);
                    $repFirmaImg = $ticketSeleccionado['reporte_firma_digital'] ?? '';
                    $repFirmadoPor = $ticketSeleccionado['reporte_firmado_por'] ?? '';
                    $repFirmadoEn = $ticketSeleccionado['reporte_firmado_en'] ?? '';

                    $logoIzqPath = __DIR__ . '/uploads/reporte_logo_izq.png';
                    $logoDerPath = __DIR__ . '/uploads/reporte_logo_der.png';
                    $logoIzqSrc  = file_exists($logoIzqPath) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logoIzqPath))) : 'uploads/reporte_logo_izq.png';
                    $logoDerSrc  = file_exists($logoDerPath) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logoDerPath))) : 'uploads/reporte_logo_der.png';
                ?>
                    <div class="detail-card mb-4" id="reporte_servicio" style="border-color: <?php echo $repFirmado ? 'rgba(16, 185, 129, 0.45)' : 'rgba(245, 158, 11, 0.55)'; ?>; background: <?php echo $repFirmado ? 'linear-gradient(145deg, #062319 0%, #03140e 100%)' : 'linear-gradient(145deg, #1f1604 0%, #0d0a02 100%)'; ?>; box-shadow: 0 10px 30px rgba(0,0,0,0.4);">
                        
                        <!-- Banner Oficial Membretado Grupo Huerta -->
                        <div class="bg-white p-3 rounded-3 mb-3 d-flex align-items-center justify-content-between border shadow-sm" style="border-color: #cbd5e1;">
                            <div style="flex: 0 0 130px;">
                                <img src="<?php echo $logoIzqSrc; ?>" alt="GH Grupo Huerta" style="max-height: 44px; max-width: 125px; object-fit: contain;">
                            </div>
                            <div class="text-center flex-grow-1 px-2">
                                <div style="color: #002060; font-weight: 900; font-size: 16px; letter-spacing: 0.5px; line-height: 1.2; text-transform: uppercase;">
                                    REPORTE DE MANTENIMIENTO TI
                                </div>
                                <div style="color: #002060; font-weight: 900; font-size: 15px; letter-spacing: 0.5px; line-height: 1.2; text-transform: uppercase;">
                                    GRUPO HUERTA
                                </div>
                            </div>
                            <div style="flex: 0 0 130px;" class="text-end">
                                <img src="<?php echo $logoDerSrc; ?>" alt="Sistemas Grupo Huerta" style="max-height: 60px; max-width: 65px; object-fit: contain;">
                            </div>
                        </div>

                        <!-- Encabezado del Formato -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-3 border-bottom border-secondary border-opacity-25">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge <?php echo $repFirmado ? 'bg-success' : 'bg-warning text-dark'; ?> fw-bold px-2.5 py-1">
                                        <i class="bi <?php echo $repFirmado ? 'bi-patch-check-fill' : 'bi-pen-fill'; ?> me-1"></i>
                                        <?php echo $repFirmado ? 'REPORTE FIRMADO DIGITALMENTE' : 'FIRMA DIGITAL REQUERIDA'; ?>
                                    </span>
                                    <span class="badge bg-secondary bg-opacity-30 text-light font-monospace">
                                        ORDEN NO. <strong class="text-info"><?php echo htmlspecialchars($repOrdenNo); ?></strong>
                                    </span>
                                </div>
                                <h5 class="fw-bold text-white mb-0">
                                    Formato Oficial de Servicio Técnico &bull; Área Infraestructura
                                </h5>
                            </div>
                            <?php if ($repFirmado): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketSeleccionado['id']; ?>&auto=1" target="_blank" class="btn btn-sm btn-danger rounded-pill px-3 py-1.5 fw-bold d-inline-flex align-items-center gap-1.5 shadow-sm">
                                        <i class="bi bi-file-earmark-pdf-fill"></i> Descargar / Guardar PDF
                                    </a>
                                    <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketSeleccionado['id']; ?>" target="_blank" class="btn btn-sm btn-outline-light rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5">
                                        <i class="bi bi-printer"></i> Vista Imprimible
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (isset($_GET['firmado_ok'])): ?>
                            <div class="alert alert-success d-flex flex-wrap align-items-center justify-content-between gap-3 rounded-3 mb-3 p-3 border border-success border-opacity-40" style="background: rgba(16, 185, 129, 0.18);">
                                <div class="d-flex align-items-center gap-2 text-white">
                                    <i class="bi bi-check-circle-fill text-success fs-4 flex-shrink-0"></i>
                                    <div>
                                        <strong class="d-block">¡Firma digital registrada con éxito!</strong>
                                        <span class="small text-light">El reporte quedó certificado y sincronizado con la Dirección de Sistemas. Ya puedes descargarlo en formato PDF oficial.</span>
                                    </div>
                                </div>
                                <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketSeleccionado['id']; ?>&auto=1" target="_blank" class="btn btn-danger fw-bold rounded-pill px-3 py-2 text-white d-inline-flex align-items-center gap-1.5 shadow">
                                    <i class="bi bi-file-earmark-pdf-fill fs-5"></i> Descargar Reporte en PDF
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Cuerpo del Reporte -->
                        <div class="rounded-3 p-3 p-md-4 mb-3" style="background: rgba(3, 11, 23, 0.7); border: 1px solid rgba(255, 255, 255, 0.1);">
                            
                            <!-- Metadatos Básicos -->
                            <div class="row g-3 mb-3 pb-3 border-bottom border-secondary border-opacity-20 text-white" style="font-size: 0.88rem;">
                                <div class="col-sm-6 col-md-3">
                                    <span class="text-secondary small d-block text-uppercase fw-semibold">Orden NO:</span>
                                    <strong class="text-info font-monospace fs-6"><?php echo htmlspecialchars($repOrdenNo); ?></strong>
                                </div>
                                <div class="col-sm-6 col-md-3">
                                    <span class="text-secondary small d-block text-uppercase fw-semibold">Técnico que Atendió:</span>
                                    <strong class="text-white"><?php echo htmlspecialchars($repTecnico); ?></strong>
                                </div>
                                <div class="col-sm-6 col-md-3">
                                    <span class="text-secondary small d-block text-uppercase fw-semibold">Solicitante:</span>
                                    <span class="text-light"><?php echo htmlspecialchars($ticketSeleccionado['solicitante_nombre'] ?? 'Solicitante'); ?></span>
                                </div>
                                <div class="col-sm-6 col-md-3">
                                    <span class="text-secondary small d-block text-uppercase fw-semibold">Área:</span>
                                    <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-30">INFRAESTRUCTURA</span>
                                </div>
                            </div>

                            <!-- 1. Necesidad o Problema -->
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1">
                                    <i class="bi bi-exclamation-circle text-info me-1"></i> Necesidad o Problema:
                                </div>
                                <div class="p-2.5 px-3 rounded-2 text-white" style="background: rgba(4, 13, 26, 0.85); border: 1px solid rgba(255, 255, 255, 0.08); font-size: 0.9rem; line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repProblema); ?>
                                </div>
                            </div>

                            <!-- 2. Trabajo Solicitado -->
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1">
                                    <i class="bi bi-card-checklist text-info me-1"></i> Trabajo Solicitado (Llenado por Técnico):
                                </div>
                                <div class="p-2.5 px-3 rounded-2 text-white" style="background: rgba(4, 13, 26, 0.85); border: 1px solid rgba(255, 255, 255, 0.08); font-size: 0.9rem; line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repTrabajoSol ?: 'Revisión y soporte de infraestructura.'); ?>
                                </div>
                            </div>

                            <!-- 3. Trabajos Realizados -->
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1">
                                    <i class="bi bi-tools text-info me-1"></i> Trabajos Realizados:
                                </div>
                                <div class="p-2.5 px-3 rounded-2 text-white" style="background: rgba(4, 13, 26, 0.85); border: 1px solid rgba(56, 189, 248, 0.25); font-size: 0.92rem; line-height: 1.6; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repTrabajosReal ?: 'Trabajos concluidos de conformidad.'); ?>
                                </div>
                            </div>

                            <!-- 4. Observaciones -->
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1">
                                    <i class="bi bi-chat-square-text text-info me-1"></i> Observaciones:
                                </div>
                                <div class="p-2.5 px-3 rounded-2 text-white" style="background: rgba(4, 13, 26, 0.85); border: 1px solid rgba(255, 255, 255, 0.08); font-size: 0.9rem; line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repObservaciones ?: 'Sin observaciones adicionales.'); ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($repFirmado): ?>
                            <!-- SECCIÓN: FIRMA YA REGISTRADA -->
                            <div class="p-3 rounded-3 border border-success border-opacity-40 d-flex flex-wrap align-items-center justify-content-between gap-3" style="background: rgba(16, 185, 129, 0.08);">
                                <div class="d-flex align-items-center gap-3">
                                    <?php if (!empty($repFirmaImg)): ?>
                                        <div class="p-2 bg-white rounded-2 shadow-sm text-center" style="max-width: 220px;">
                                            <img src="<?php echo htmlspecialchars($repFirmaImg); ?>" alt="Firma Digital" style="max-height: 70px; max-width: 200px; object-fit: contain;">
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="text-success fw-bold d-flex align-items-center gap-1.5">
                                            <i class="bi bi-check-circle-fill"></i> Conformidad Certificada Digitalmente
                                        </div>
                                        <div class="text-white small">
                                            Firmado por: <strong><?php echo htmlspecialchars($repFirmadoPor); ?></strong>
                                        </div>
                                        <div class="text-secondary" style="font-size: 0.75rem;">
                                            Fecha y hora: <?php echo formatearFechaHoraMensaje($repFirmadoEn); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketSeleccionado['id']; ?>&auto=1" target="_blank" class="btn btn-danger btn-sm rounded-pill px-3.5 py-1.5 fw-bold d-inline-flex align-items-center gap-1.5 shadow-sm">
                                        <i class="bi bi-file-earmark-pdf-fill"></i> Descargar Reporte en PDF
                                    </a>
                                    <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketSeleccionado['id']; ?>" target="_blank" class="btn btn-outline-light btn-sm rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5">
                                        <i class="bi bi-printer"></i> Imprimir
                                    </a>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- SECCIÓN: RECUADRO INTERACTIVO DE FIRMA DIGITAL -->
                            <div class="p-3 p-md-4 rounded-3 border border-warning border-opacity-40" style="background: rgba(245, 158, 11, 0.06);">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-pencil-fill text-warning fs-5"></i>
                                    <h6 class="fw-bold text-white mb-0">Firmar Formato de Reporte de Servicio Técnico</h6>
                                </div>
                                <p class="text-secondary small mb-3">
                                    Dibuja tu firma digital en el recuadro a continuación usando tu <strong>mouse</strong>, <strong>dedo</strong> o <strong>pantalla táctil</strong> para certificar de conformidad los trabajos de infraestructura realizados:
                                </p>

                                <div class="row g-3 align-items-end">
                                    <div class="col-lg-7">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <label class="form-label text-secondary small fw-bold text-uppercase mb-0">
                                                <i class="bi bi-brush text-warning me-1"></i> Área de Trazo de Firma Digital:
                                            </label>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 text-white-50" style="font-size: 0.72rem;" onclick="limpiarCanvasFirma()">
                                                <i class="bi bi-eraser me-1"></i> Limpiar Trazo
                                            </button>
                                        </div>
                                        <div class="position-relative" style="background: #ffffff; border-radius: 8px; border: 2px dashed #0284c7; overflow: hidden;">
                                            <canvas id="firmaDigitalCanvas" width="600" height="170" style="background: #ffffff; width: 100%; height: 170px; display: block; cursor: crosshair; touch-action: none;"></canvas>
                                            <div class="position-absolute bottom-0 start-0 w-100 text-center pb-1 text-muted pe-none" style="font-size: 0.7rem; opacity: 0.45; border-top: 1px dotted #cbd5e1;">
                                                Línea de firma &bull; Firma sobre este espacio
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-5">
                                        <form id="formFirmaDigital" method="POST" action="tickets.php?ver=<?php echo $ticketSeleccionado['id']; ?>">
                                            <input type="hidden" name="accion" value="firmar_reporte_usuario">
                                            <input type="hidden" name="ticket_id" value="<?php echo $ticketSeleccionado['id']; ?>">
                                            <input type="hidden" name="reporte_firma_digital" id="hiddenFirmaDigital" value="">

                                            <div class="mb-3">
                                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                                    <i class="bi bi-person-check text-info me-1"></i> Nombre de Quien Firma:
                                                </label>
                                                <input type="text" name="reporte_firmado_por" id="inputFirmadoPor" class="form-control rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(56, 189, 248, 0.4);" value="<?php echo htmlspecialchars($nombreUsuario); ?>" placeholder="Escribe tu nombre completo..." required>
                                                <div class="form-text text-secondary" style="font-size: 0.72rem;">Nombre que figurará en el comprobante de conformidad.</div>
                                            </div>

                                            <button type="button" class="btn btn-success w-100 py-2.5 rounded-3 fw-bold shadow-lg d-flex align-items-center justify-content-center gap-2" onclick="enviarFirmaDigital()">
                                                <i class="bi bi-check2-circle fs-5"></i>
                                                <span>Confirmar y Enviar Firma Digital</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- HOJA OCULTA PARA IMPRESIÓN OFICIAL -->
                    <div id="hojaReporteOficialAgencia" class="d-none">
                        <div style="background: #ffffff; color: #0f172a; padding: 30px; font-family: sans-serif;">
                            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 15px;">
                                <div style="flex: 0 0 140px;">
                                    <img src="<?php echo $logoIzqSrc; ?>" alt="GH Grupo Huerta" style="max-height: 48px; max-width: 135px; object-fit: contain;">
                                </div>
                                <div style="text-align: center; flex: 1; padding: 0 10px;">
                                    <div style="color: #002060; font-weight: 900; font-size: 18px; letter-spacing: 0.5px; line-height: 1.2; text-transform: uppercase;">
                                        REPORTE DE MANTENIMIENTO TI
                                    </div>
                                    <div style="color: #002060; font-weight: 900; font-size: 17px; letter-spacing: 0.5px; line-height: 1.2; text-transform: uppercase;">
                                        GRUPO HUERTA
                                    </div>
                                </div>
                                <div style="flex: 0 0 140px; text-align: right;">
                                    <img src="<?php echo $logoDerSrc; ?>" alt="Sistemas Grupo Huerta" style="max-height: 72px; max-width: 76px; object-fit: contain;">
                                </div>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                                <div style="background: #002060; color: #fff; padding: 5px 12px; font-weight: 700; font-family: monospace; font-size: 13px; border-radius: 4px; display: inline-block;">
                                    ORDEN NO. <?php echo htmlspecialchars($repOrdenNo); ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b;">Fecha: <?php echo htmlspecialchars(date('d/m/Y', strtotime($ticketSeleccionado['creado_en']))); ?></div>
                            </div>

                            <table style="width: 100%; font-size: 13px; margin-bottom: 20px; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 6px; font-weight: 700; color: #475569; width: 25%;">Agencia Solicitante:</td>
                                    <td style="padding: 6px; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($ticketSeleccionado['solicitante_agencia'] ?? 'Agencia'); ?></td>
                                    <td style="padding: 6px; font-weight: 700; color: #475569; width: 25%;">Técnico Responsable:</td>
                                    <td style="padding: 6px; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($repTecnico); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px; font-weight: 700; color: #475569;">Solicitante del Ticket:</td>
                                    <td style="padding: 6px; color: #0f172a;"><?php echo htmlspecialchars($ticketSeleccionado['solicitante_nombre'] ?? 'Solicitante'); ?></td>
                                    <td style="padding: 6px; font-weight: 700; color: #475569;">Área Especializada:</td>
                                    <td style="padding: 6px; font-weight: 700; color: #0284c7;">INFRAESTRUCTURA (REDES / SITE / HARDWARE)</td>
                                </tr>
                            </table>

                            <div style="margin-bottom: 16px;">
                                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">1. Necesidad o Problema Reportado:</div>
                                <div style="padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 13px; line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repProblema); ?>
                                </div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">2. Trabajo Solicitado:</div>
                                <div style="padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 13px; line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repTrabajoSol ?: 'Atención y soporte técnico de infraestructura.'); ?>
                                </div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">3. Trabajos Realizados por el Técnico:</div>
                                <div style="padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 13px; line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repTrabajosReal ?: 'Trabajos concluidos.'); ?>
                                </div>
                            </div>

                            <div style="margin-bottom: 24px;">
                                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">4. Observaciones y Recomendaciones:</div>
                                <div style="padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 13px; line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($repObservaciones ?: 'Sin observaciones adicionales.'); ?>
                                </div>
                            </div>

                            <table style="width: 100%; margin-top: 40px; border-top: 1px solid #cbd5e1; padding-top: 20px;">
                                <tr>
                                    <td style="width: 50%; text-align: center; vertical-align: bottom;">
                                        <div style="height: 80px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px;">
                                            <?php echo htmlspecialchars($repTecnico); ?>
                                        </div>
                                        <div style="border-top: 1px solid #000; width: 75%; margin: 0 auto; padding-top: 6px; font-size: 12px; font-weight: 700;">
                                            Técnico Responsable<br><span style="font-size: 10px; color: #64748b; font-weight: normal;">Dirección Central de Sistemas</span>
                                        </div>
                                    </td>
                                    <td style="width: 50%; text-align: center; vertical-align: bottom;">
                                        <div style="height: 80px; display: flex; align-items: center; justify-content: center;">
                                            <?php if (!empty($repFirmaImg)): ?>
                                                <img src="<?php echo htmlspecialchars($repFirmaImg); ?>" style="max-height: 75px; max-width: 220px;">
                                            <?php else: ?>
                                                <span style="color: #64748b; font-style: italic;">Pendiente de firma</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="border-top: 1px solid #000; width: 75%; margin: 0 auto; padding-top: 6px; font-size: 12px; font-weight: 700;">
                                            <?php echo htmlspecialchars($repFirmadoPor ?: ($ticketSeleccionado['solicitante_nombre'] ?? 'Solicitante')); ?><br>
                                            <span style="font-size: 10px; color: #16a34a; font-weight: 700;">Firma Digital de Conformidad &bull; <?php echo formatearFechaHoraMensaje($repFirmadoEn); ?></span>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Hilo de Mensajes y Respuestas -->
                <div class="detail-card" id="conversacion">
                    <h5 class="fw-bold text-white mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-chat-left-text-fill text-info"></i> Mensajes y Respuestas del Ticket
                        <span class="badge bg-secondary bg-opacity-50 text-white-50 ms-auto font-monospace" style="font-size: 0.75rem;"><?php echo count($mensajesSeleccionados); ?> mensaje(s)</span>
                    </h5>

                    <?php if (empty($mensajesSeleccionados)): ?>
                        <div class="p-4 rounded-3 text-center text-secondary mb-4" style="background: rgba(255, 255, 255, 0.03); border: 1px dashed rgba(255, 255, 255, 0.1);">
                            <i class="bi bi-chat-square-dots fs-2 text-muted d-block mb-1"></i>
                            <div class="fw-semibold">No hay mensajes adicionales en este hilo aún.</div>
                            <div class="small text-muted">Escribe tu respuesta a continuación para comunicarte con el equipo de Sistemas.</div>
                        </div>
                    <?php else: ?>
                        <div class="mb-4">
                            <?php foreach ($mensajesSeleccionados as $msg): 
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
                                            <i class="bi bi-clock me-1"></i> <?php echo formatearFechaHoraMensaje($msg['creado_en'] ?? ''); ?>
                                        </div>
                                    </div>

                                    <div class="text-white" style="font-size: 0.94rem; line-height: 1.55; white-space: pre-line;">
                                        <?php echo htmlspecialchars($msg['mensaje']); ?>
                                    </div>

                                     <?php if (!empty($msg['archivo_adjunto'])): 
                                        $rawAdjM = trim($msg['archivo_adjunto']);
                                        $extMsg = strtolower(pathinfo($rawAdjM, PATHINFO_EXTENSION));
                                        $esImgMsg = in_array($extMsg, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                                        $esPdfMsg = ($extMsg === 'pdf');

                                        if (str_starts_with($rawAdjM, 'http://') || str_starts_with($rawAdjM, 'https://')) {
                                            $urlAdjM = $rawAdjM;
                                        } elseif (file_exists(__DIR__ . '/' . $rawAdjM)) {
                                            $urlAdjM = $rawAdjM;
                                        } else {
                                            $urlAdjM = 'https://portal.grupohuerta.mx/' . ltrim($rawAdjM, '/');
                                        }
                                        $nomAdjM = basename($rawAdjM);
                                    ?>
                                        <div class="mt-2.5 pt-2 border-top border-secondary border-opacity-25">
                                            <?php if ($esPdfMsg): ?>
                                                <div class="d-flex align-items-center justify-content-between p-2.5 px-3 rounded-3 my-1" style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); max-width: 500px;">
                                                    <div class="d-flex align-items-center gap-2.5 overflow-hidden">
                                                        <i class="bi bi-file-earmark-pdf-fill text-danger fs-2 flex-shrink-0"></i>
                                                        <div class="text-truncate">
                                                            <div class="text-white fw-bold small text-truncate" title="<?php echo htmlspecialchars($nomAdjM); ?>">
                                                                <?php echo htmlspecialchars($nomAdjM); ?>
                                                            </div>
                                                            <div class="text-secondary" style="font-size: 0.72rem;">Documento PDF adjunto</div>
                                                        </div>
                                                    </div>
                                                    <a href="<?php echo htmlspecialchars($urlAdjM); ?>" target="_blank" download class="btn btn-sm btn-danger rounded-pill px-3 py-1 text-nowrap ms-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.78rem;">
                                                        <i class="bi bi-file-pdf me-1"></i> Abrir / Descargar PDF
                                                    </a>
                                                </div>
                                            <?php elseif ($esImgMsg): ?>
                                                <div class="mb-2">
                                                    <a href="<?php echo htmlspecialchars($urlAdjM); ?>" target="_blank">
                                                        <img src="<?php echo htmlspecialchars($urlAdjM); ?>" alt="Captura" style="max-height: 220px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15);">
                                                    </a>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($urlAdjM); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3" style="font-size: 0.78rem;">
                                                    <i class="bi bi-image me-1"></i> Ver imagen completa
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo htmlspecialchars($urlAdjM); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3" style="font-size: 0.78rem;">
                                                    <i class="bi bi-paperclip me-1"></i> Archivo adjunto (<?php echo strtoupper($extMsg); ?>)
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Formulario: Contestar / Enviar Otro Mensaje -->
                    <div class="p-3.5 rounded-4 mt-3" style="background: rgba(15, 23, 42, 0.75); border: 1px solid rgba(56, 189, 248, 0.3);">
                        <h6 class="fw-bold text-info mb-3 d-flex align-items-center gap-2">
                            <i class="bi bi-reply-fill fs-5"></i> Contestar / Enviar Mensaje a este Ticket
                        </h6>

                        <form method="POST" action="tickets.php?ver=<?php echo $verTicketId; ?>" enctype="multipart/form-data">
                            <input type="hidden" name="accion" value="enviar_mensaje">
                            <input type="hidden" name="ticket_id" value="<?php echo $verTicketId; ?>">

                            <div class="mb-3">
                                <textarea name="mensaje" rows="4" class="form-control rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255, 255, 255, 0.15);" placeholder="Escribe aquí tu respuesta, detalles adicionales, avances o dudas sobre este requerimiento..." required></textarea>
                            </div>

                            <div class="row g-3 align-items-end mb-3">
                                <div class="<?php echo $esAdmin ? 'col-md-6' : 'col-12'; ?>">
                                    <label class="text-secondary small fw-bold text-uppercase d-block mb-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger me-1"></i> Adjuntar PDF o Evidencia (opcional)
                                    </label>
                                    <input type="file" name="adjunto_mensaje" class="form-control form-control-sm rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255, 255, 255, 0.15);" accept=".pdf,image/*,.doc,.docx,.xls,.xlsx,.txt,.zip">
                                </div>
                                <?php if ($esAdmin): ?>
                                <div class="col-md-6">
                                    <label class="text-secondary small fw-bold text-uppercase d-block mb-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-clock-history me-1"></i> Actualizar estado del ticket (opcional)
                                    </label>
                                    <select name="cambio_estado" class="form-select form-select-sm rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255, 255, 255, 0.15);">
                                        <option value="">Mantener estado actual (<?php echo htmlspecialchars($ticketSeleccionado['estado'] ?? 'Abierto'); ?>)</option>
                                        <option value="Abierto">Cambiar a: Abierto (Reabrir caso)</option>
                                        <option value="En Proceso">Cambiar a: En Proceso (En atención)</option>
                                        <option value="Resuelto">Cambiar a: Resuelto (Mi problema quedó solucionado)</option>
                                        <option value="Cerrado">Cambiar a: Cerrado (Finalizar ticket)</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-primary px-4 py-2.5 rounded-3 fw-bold d-inline-flex align-items-center gap-2 shadow" style="background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%); border: none;">
                                    <i class="bi bi-send-fill"></i>
                                    <span>Enviar Contestación</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Panel Admin: Actualizar Estatus -->
                <?php if ($esAdmin): ?>
                    <div class="detail-card" style="border-color: rgba(148, 163, 184, 0.3);">
                        <h5 class="fw-bold text-warning mb-3 d-flex align-items-center gap-2">
                            <i class="bi bi-shield-lock-fill"></i> Panel de Administración &bull; Actualizar Estatus del Ticket
                        </h5>
                        <form method="POST" action="tickets.php?ver=<?php echo $verTicketId; ?>">
                            <input type="hidden" name="accion" value="actualizar_ticket">
                            <input type="hidden" name="ticket_id" value="<?php echo $verTicketId; ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label text-secondary small fw-bold text-uppercase">Estado del Ticket</label>
                                    <select name="estado" class="form-select rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255, 255, 255, 0.15);">
                                        <option value="Abierto" <?php echo $ticketSeleccionado['estado'] === 'Abierto' ? 'selected' : ''; ?>>Abierto (En espera)</option>
                                        <option value="En Proceso" <?php echo $ticketSeleccionado['estado'] === 'En Proceso' ? 'selected' : ''; ?>>En Proceso (Trabajando)</option>
                                        <option value="Resuelto" <?php echo $ticketSeleccionado['estado'] === 'Resuelto' ? 'selected' : ''; ?>>Resuelto (Solución entregada)</option>
                                        <option value="Cerrado" <?php echo $ticketSeleccionado['estado'] === 'Cerrado' ? 'selected' : ''; ?>>Cerrado (Finalizado)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-secondary small fw-bold text-uppercase">Técnico Asignado</label>
                                    <input type="text" name="asignado_a" class="form-control rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255, 255, 255, 0.15);" value="<?php echo htmlspecialchars($ticketSeleccionado['asignado_a'] ?? ''); ?>" placeholder="Ej. Ing. de Sistemas...">
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-secondary small fw-bold text-uppercase">Notas de Solución Oficial / Resolución</label>
                                    <textarea name="notas_resolucion" rows="3" class="form-control rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255, 255, 255, 0.15);" placeholder="Escribe la solución técnica o respuesta oficial..."><?php echo htmlspecialchars($ticketSeleccionado['notas_resolucion'] ?? ''); ?></textarea>
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

                <!-- ============================================== -->
                <!-- MODAL: MODIFICAR TICKET (SOLO ADMIN) -->
                <!-- ============================================== -->
                <?php if ($esAdmin): ?>
                <div class="modal fade" id="modalModificarTicket" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content" style="background: #0b1a30; border: 1px solid rgba(56, 189, 248, 0.4); border-radius: 18px; box-shadow: 0 15px 40px rgba(0,0,0,0.7);">
                            <form method="POST" action="tickets.php?ver=<?php echo $verTicketId; ?>" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="modificar_ticket">
                                <input type="hidden" name="ticket_id" id="modTicketId" value="<?php echo $verTicketId; ?>">

                                <div class="modal-header border-secondary border-opacity-25 pb-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="p-2 rounded-3" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; font-size: 1.25rem;">
                                            <i class="bi bi-pencil-square"></i>
                                        </div>
                                        <div>
                                            <h5 class="modal-title fw-bold text-white mb-0" id="modModalTitulo">Modificar Requerimiento del Ticket</h5>
                                            <span class="text-secondary small font-monospace" id="modModalFolio">
                                                <?php echo htmlspecialchars($ticketSeleccionado['folio'] ?? ('#TK-' . $ticketSeleccionado['id'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                </div>

                                <div class="modal-body p-4">
                                    <div class="row g-3">
                                        <!-- Título / Asunto -->
                                        <div class="col-12">
                                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                                <i class="bi bi-card-heading text-info me-1"></i> Título / Asunto <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" name="titulo" id="modTitulo" class="form-control rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255,255,255,0.15);" value="<?php echo htmlspecialchars($ticketSeleccionado['titulo'] ?? ''); ?>" required>
                                        </div>

                                        <!-- Área de Sistemas -->
                                        <div class="col-md-6">
                                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                                <i class="bi bi-diagram-3 text-info me-1"></i> Área de Sistemas Destino <span class="text-danger">*</span>
                                            </label>
                                            <select name="area_sistemas" id="modArea" class="form-select rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255,255,255,0.15);" required>
                                                <option value="INFRAESTRUCTURA" <?php echo ($ticketSeleccionado['area_sistemas'] ?? '') === 'INFRAESTRUCTURA' ? 'selected' : ''; ?>>🖥️ INFRAESTRUCTURA (Redes, Servidores, Impresoras)</option>
                                                <option value="DESARROLLO" <?php echo ($ticketSeleccionado['area_sistemas'] ?? '') === 'DESARROLLO' ? 'selected' : ''; ?>>💻 DESARROLLO (Portales, Sistemas Web, Bases de Datos)</option>
                                                <option value="CYBERSEGURIDAD" <?php echo ($ticketSeleccionado['area_sistemas'] ?? '') === 'CYBERSEGURIDAD' ? 'selected' : ''; ?>>🛡️ CYBERSEGURIDAD (Accesos, Antivirus, Cuentas)</option>
                                                <option value="REDES SOCIALES" <?php echo ($ticketSeleccionado['area_sistemas'] ?? '') === 'REDES SOCIALES' ? 'selected' : ''; ?>>📱 REDES SOCIALES (Marketing, Publicaciones)</option>
                                                <option value="AUDITORIA" <?php echo ($ticketSeleccionado['area_sistemas'] ?? '') === 'AUDITORIA' ? 'selected' : ''; ?>>📋 AUDITORIA (Procesos, Revisiones TI)</option>
                                                <option value="CORPORATIVO" <?php echo ($ticketSeleccionado['area_sistemas'] ?? '') === 'CORPORATIVO' ? 'selected' : ''; ?>>🏢 CORPORATIVO (Dirección General)</option>
                                            </select>
                                        </div>

                                        <!-- Prioridad -->
                                        <div class="col-md-3">
                                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                                <i class="bi bi-reception-3 text-warning me-1"></i> Prioridad
                                            </label>
                                            <select name="prioridad" id="modPrioridad" class="form-select rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255,255,255,0.15);">
                                                <option value="Baja" <?php echo ($ticketSeleccionado['prioridad'] ?? '') === 'Baja' ? 'selected' : ''; ?>>🟢 Baja</option>
                                                <option value="Media" <?php echo ($ticketSeleccionado['prioridad'] ?? 'Media') === 'Media' ? 'selected' : ''; ?>>🟡 Media</option>
                                                <option value="Alta" <?php echo ($ticketSeleccionado['prioridad'] ?? '') === 'Alta' ? 'selected' : ''; ?>>🟠 Alta</option>
                                                <option value="Urgente" <?php echo ($ticketSeleccionado['prioridad'] ?? '') === 'Urgente' ? 'selected' : ''; ?>>🔴 Urgente</option>
                                            </select>
                                        </div>

                                        <!-- Estado del Ticket -->
                                        <div class="col-md-3">
                                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                                <i class="bi bi-clock-history text-info me-1"></i> Estado
                                            </label>
                                            <select name="estado" id="modEstado" class="form-select rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255,255,255,0.15);">
                                                <option value="Abierto" <?php echo ($ticketSeleccionado['estado'] ?? '') === 'Abierto' ? 'selected' : ''; ?>>Abierto</option>
                                                <option value="En Proceso" <?php echo ($ticketSeleccionado['estado'] ?? '') === 'En Proceso' ? 'selected' : ''; ?>>En Proceso</option>
                                                <option value="Resuelto" <?php echo ($ticketSeleccionado['estado'] ?? '') === 'Resuelto' ? 'selected' : ''; ?>>Resuelto</option>
                                                <option value="Cerrado" <?php echo ($ticketSeleccionado['estado'] ?? '') === 'Cerrado' ? 'selected' : ''; ?>>Cerrado</option>
                                            </select>
                                        </div>

                                        <!-- Descripción Detallada -->
                                        <div class="col-12">
                                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                                <i class="bi bi-text-paragraph text-info me-1"></i> Descripción Detallada <span class="text-danger">*</span>
                                            </label>
                                            <textarea name="descripcion" id="modDescripcion" rows="5" class="form-control rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255,255,255,0.15);" required><?php echo htmlspecialchars($ticketSeleccionado['descripcion'] ?? ''); ?></textarea>
                                        </div>

                                        <!-- Archivo de Evidencia -->
                                        <div class="col-12">
                                            <label class="form-label text-secondary small fw-bold text-uppercase">
                                                <i class="bi bi-paperclip text-info me-1"></i> Actualizar o Agregar Archivo de Evidencia (Opcional)
                                            </label>
                                            <input type="file" name="archivo_adjunto" class="form-control rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255,255,255,0.15);" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
                                            <?php if (!empty($ticketSeleccionado['archivo_adjunto'])): ?>
                                                <div class="small text-info mt-1">
                                                    <i class="bi bi-check-circle me-1"></i> Archivo actual: <strong><?php echo basename($ticketSeleccionado['archivo_adjunto']); ?></strong> (Se conservará si no seleccionas uno nuevo).
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-footer border-secondary border-opacity-25 pt-3">
                                    <button type="button" class="btn btn-outline-secondary px-4 rounded-3 text-white" data-bs-dismiss="modal">
                                        Cancelar
                                    </button>
                                    <button type="submit" class="btn btn-warning px-4 py-2 rounded-3 fw-bold text-dark shadow">
                                        <i class="bi bi-check2-circle me-1"></i> Guardar Cambios del Ticket
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        <?php endif; ?>

    </div>
</div>



<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function abrirTicket(id, event) {
        if (event && event.target && event.target.closest('button, form, input, select, textarea, .btn-action-icon.text-danger, a[href*="accion="]')) {
            return;
        }
        window.location.href = 'tickets.php?ver=' + id;
    }

    function cambiarPestana(tabName) {
        const targetBtnId = (tabName === 'nuevo') ? 'tab-btn-nuevo' : ((tabName === 'detalle') ? 'tab-btn-detalle' : 'tab-btn-lista');
        const triggerEl = document.getElementById(targetBtnId);
        if (triggerEl) {
            const tab = bootstrap.Tab.getOrCreateInstance(triggerEl);
            tab.show();
            actualizarUrl(tabName);
        }
    }

    function actualizarUrl(tabName) {
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            if (tabName === 'nuevo') {
                url.searchParams.set('nuevo', '1');
                url.searchParams.delete('ver');
                url.searchParams.delete('id');
            } else if (tabName === 'lista') {
                url.searchParams.delete('nuevo');
                url.searchParams.delete('ver');
                url.searchParams.delete('id');
            }
            window.history.replaceState({}, '', url.toString());
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('editar')) {
            const modalEl = document.getElementById('modalModificarTicket');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }
        if (window.location.hash === '#conversacion' || urlParams.has('msg_ok')) {
            const conv = document.getElementById('conversacion');
            if (conv) {
                setTimeout(() => conv.scrollIntoView({ behavior: 'smooth' }), 200);
            }
        }
    });

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

    // ==========================================
    // FIRMA DIGITAL TÁCTIL Y MOUSE (HTML5 CANVAS)
    // ==========================================
    let canvasFirma = null;
    let ctxFirma = null;
    let trazandoFirma = false;
    let tieneTrazosFirma = false;

    function inicializarCanvasFirma() {
        canvasFirma = document.getElementById('firmaDigitalCanvas');
        if (!canvasFirma) return;
        ctxFirma = canvasFirma.getContext('2d');
        
        ctxFirma.strokeStyle = '#022c5e';
        ctxFirma.lineWidth = 2.8;
        ctxFirma.lineCap = 'round';
        ctxFirma.lineJoin = 'round';

        function getPos(e) {
            const rect = canvasFirma.getBoundingClientRect();
            const scaleX = canvasFirma.width / rect.width;
            const scaleY = canvasFirma.height / rect.height;
            if (e.touches && e.touches.length > 0) {
                return {
                    x: (e.touches[0].clientX - rect.left) * scaleX,
                    y: (e.touches[0].clientY - rect.top) * scaleY
                };
            }
            return {
                x: (e.clientX - rect.left) * scaleX,
                y: (e.clientY - rect.top) * scaleY
            };
        }

        function empezarTrazo(e) {
            e.preventDefault();
            trazandoFirma = true;
            tieneTrazosFirma = true;
            const pos = getPos(e);
            ctxFirma.beginPath();
            ctxFirma.moveTo(pos.x, pos.y);
        }

        function moverTrazo(e) {
            if (!trazandoFirma) return;
            e.preventDefault();
            const pos = getPos(e);
            ctxFirma.lineTo(pos.x, pos.y);
            ctxFirma.stroke();
        }

        function terminarTrazo(e) {
            if (!trazandoFirma) return;
            e.preventDefault();
            trazandoFirma = false;
        }

        canvasFirma.addEventListener('mousedown', empezarTrazo);
        canvasFirma.addEventListener('mousemove', moverTrazo);
        window.addEventListener('mouseup', terminarTrazo);

        canvasFirma.addEventListener('touchstart', empezarTrazo, { passive: false });
        canvasFirma.addEventListener('touchmove', moverTrazo, { passive: false });
        window.addEventListener('touchend', terminarTrazo, { passive: false });
    }

    function limpiarCanvasFirma() {
        if (!canvasFirma || !ctxFirma) return;
        ctxFirma.clearRect(0, 0, canvasFirma.width, canvasFirma.height);
        tieneTrazosFirma = false;
    }

    function enviarFirmaDigital() {
        if (!tieneTrazosFirma) {
            alert('Por favor realiza tu trazo de firma en el recuadro blanco antes de confirmar.');
            return;
        }
        const inputNom = document.getElementById('inputFirmadoPor');
        const nombre = (inputNom ? inputNom.value : '').trim();
        if (!nombre) {
            alert('Por favor escribe tu nombre completo para certificar la firma.');
            return;
        }
        const dataUrl = canvasFirma.toDataURL('image/png');
        document.getElementById('hiddenFirmaDigital').value = dataUrl;
        document.getElementById('formFirmaDigital').submit();
    }

    function imprimirReporteServicioAgencia() {
        const el = document.getElementById('hojaReporteOficialAgencia');
        if (!el) {
            window.print();
            return;
        }
        const ventana = window.open('', '_blank', 'width=900,height=1100');
        ventana.document.write('<!DOCTYPE html><html><head><title>Reporte de Servicio Técnico - Grupo Huerta</title>');
        ventana.document.write('<style>body{background:#fff;margin:0;padding:25px;font-family:sans-serif;}@media print{@page{margin:15mm;}}</style>');
        ventana.document.write('</head><body>');
        ventana.document.write(el.innerHTML);
        ventana.document.write('</body></html>');
        ventana.document.close();
        ventana.focus();
        setTimeout(() => {
            ventana.print();
            ventana.close();
        }, 600);
    }

    document.addEventListener('DOMContentLoaded', function() {
        inicializarCanvasFirma();
    });
</script>
<?php include_once 'pwa_body.php'; ?>
</body>
</html>
