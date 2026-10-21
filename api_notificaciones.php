<?php
// =============================================================================
// API DE NOTIFICACIONES MULTIMÓDULO - GRUPO HUERTA
// Maneja la entrega, sincronización y estado de lectura respetando permisos RBAC.
// =============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'listar';
$token  = $_GET['token']  ?? $_POST['token']  ?? '';

// 1. ENDPOINT PÚBLICO PROTEGIDO POR TOKEN: CONSUMO REMOTO ENTRE PORTALES
if ($action === 'listar_remoto') {
    if ($token !== 'GH_NOTIFICACIONES_SEGURA_2026') {
        http_response_code(403);
        echo json_encode(['exito' => false, 'error' => 'Token de seguridad inválido.']);
        exit();
    }

    if (!$pdo) {
        echo json_encode(['exito' => false, 'error' => 'Sin conexión a base de datos.', 'notificaciones' => []]);
        exit();
    }

    asegurarTablaNotificaciones($pdo);
    try {
        $stmt = $pdo->query("SELECT * FROM notificaciones WHERE estatus = 1 ORDER BY id DESC LIMIT 50");
        $notifs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        echo json_encode(['exito' => true, 'total' => count($notifs), 'notificaciones' => $notifs]);
    } catch (Throwable $e) {
        echo json_encode(['exito' => false, 'error' => $e->getMessage(), 'notificaciones' => []]);
    }
    exit();
}

// 2. ENDPOINTS DE USUARIO (REQUIEREN SESIÓN INICIADA)
$usuarioId = $_SESSION['usuario_id'] ?? null;
if (!$usuarioId) {
    http_response_code(401);
    echo json_encode(['exito' => false, 'error' => 'Sesión no iniciada.', 'notificaciones' => [], 'total_no_leidas' => 0]);
    exit();
}

// Refrescar permisos del usuario
if ($pdo) {
    cargarPermisosSesion($pdo, $usuarioId);
    asegurarTablaNotificaciones($pdo);
}

// SINCRONIZACIÓN AUTOMÁTICA EN SEGUNDO PLANO (SI ES PORTAL DE AGENCIA Y CENTRAL RESPONDE)
if ($action === 'listar') {
    // Intentar sincronizar desde Portal Central GH si no estamos en el central
    $esPortalCentral = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'portal.grupohuerta.mx') !== false);
    if (!$esPortalCentral && $pdo) {
        try {
            $urlCentral = 'https://portal.grupohuerta.mx/api_notificaciones.php?action=listar_remoto&token=GH_NOTIFICACIONES_SEGURA_2026';
            $ctx = stream_context_create([
                'http' => ['timeout' => 2, 'header' => "User-Agent: PortalAgenciaNotif/1.0\r\n"],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);
            $raw = @file_get_contents($urlCentral, false, $ctx);
            if ($raw) {
                $remotoJson = json_decode($raw, true);
                if (!empty($remotoJson['exito']) && !empty($remotoJson['notificaciones'])) {
                    foreach ($remotoJson['notificaciones'] as $rn) {
                        // En agencia nunca importar notificaciones genericas de tickets remotos
                        if (!empty($rn['modulo']) && strtolower(trim($rn['modulo'])) === 'tickets') {
                            continue;
                        }
                        $stmtCheck = $pdo->prepare("SELECT id FROM notificaciones WHERE modulo = ? AND tipo = ? AND referencia_id = ?");
                        $stmtCheck->execute([$rn['modulo'], $rn['tipo'], $rn['referencia_id']]);
                        if (!$stmtCheck->fetch()) {
                            $stmtIns = $pdo->prepare("
                                INSERT INTO notificaciones (modulo, tipo, titulo, mensaje, enlace, icono, color, referencia_id, creado_en, estatus)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                            ");
                            $stmtIns->execute([
                                $rn['modulo'],
                                $rn['tipo'],
                                $rn['titulo'],
                                $rn['mensaje'],
                                $rn['enlace'] ?? '',
                                $rn['icono'] ?? 'bi-bell-fill',
                                $rn['color'] ?? 'primary',
                                $rn['referencia_id'] ?? null,
                                $rn['creado_en'] ?? date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        } catch (Throwable $eSync) {}
    }

        // 1. Handshake automático hacia Central TI: registra en Central la URL exacta de esta agencia
    if (empty($_SESSION['central_handshake_ts']) || (time() - $_SESSION['central_handshake_ts']) > 120) {
        try {
            $isHttpsH = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
            $protoH = $isHttpsH ? 'https://' : 'http://';
            $hostH = $_SERVER['HTTP_HOST'] ?? '';
            $dirH = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            $whUrlH = !empty($hostH) ? ($protoH . $hostH . $dirH . '/api_webhook_ticket.php') : '';
            $ptUrlH = !empty($hostH) ? ($protoH . $hostH . $dirH . '/') : '';
            $nomAgH = $_SESSION['agencia'] ?? 'Agencia';

            if (!empty($whUrlH)) {
                $payloadHs = json_encode([
                    'token'       => 'GH_SISTEMAS_TICKETS_2026!',
                    'accion'      => 'handshake_agencia',
                    'agencia'     => $nomAgH,
                    'webhook_url' => $whUrlH,
                    'portal_url'  => $ptUrlH,
                    'host'        => $hostH
                ], JSON_UNESCAPED_UNICODE);

                $chH = curl_init('https://portal.grupohuerta.mx/api_receptor_tickets.php');
                curl_setopt_array($chH, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payloadHs,
                    CURLOPT_TIMEOUT        => 3,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json; charset=utf-8',
                        'Authorization: Bearer GH_SISTEMAS_TICKETS_2026!',
                        'User-Agent: PortalAgenciaNotif/2.0'
                    ]
                ]);
                @curl_exec($chH);
                @curl_close($chH);
                $_SESSION['central_handshake_ts'] = time();
            }
        } catch (Throwable $eHsh) {}
    }

    // 2. Fallback Pull: Sincronizar en vivo respuestas y mensajes de Central para los tickets del usuario
    if (!empty($_SESSION['usuario_id']) && $pdo) {
        $uIdSess    = (int)$_SESSION['usuario_id'];
        $uLoginSess = strtolower(trim($_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? '')));
        $uNomSess   = strtolower(trim($_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? '')));
        $uMailSess  = strtolower(trim($_SESSION['usuario_email'] ?? ($_SESSION['email'] ?? '')));

        try {
            // Buscar tickets del usuario (activos o recientes)
            $stmtMisT = $pdo->prepare("
                SELECT id, folio, titulo, estado, creado_en, actualizado_en 
                FROM tickets_soporte 
                WHERE (
                    (solicitante_id IS NOT NULL AND solicitante_id = :uid)
                    OR (:ulogin != '' AND LOWER(COALESCE(solicitante_usuario, '')) = :ulogin)
                    OR (:umail != '' AND LOWER(COALESCE(solicitante_email, '')) = :umail)
                    OR (:unom != '' AND LOWER(COALESCE(solicitante_nombre, '')) = :unom)
                  )
                ORDER BY id DESC LIMIT 10
            ");
            $stmtMisT->execute([
                ':uid'    => $uIdSess,
                ':ulogin' => $uLoginSess,
                ':umail'  => $uMailSess,
                ':unom'   => $uNomSess
            ]);
            $misTickets = $stmtMisT->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($misTickets)) {
                foreach ($misTickets as $myTk) {
                    $fol = trim($myTk['folio'] ?? '');
                    if (empty($fol)) continue;

                    // A. Asegurar notificación inicial del ticket en la campanita si no existe
                    if (function_exists('crearNotificacion')) {
                        $stmtNChk = $pdo->prepare("SELECT id FROM notificaciones WHERE modulo = 'tickets' AND (referencia_id = ? OR enlace LIKE ?) LIMIT 1");
                        $stmtNChk->execute([$myTk['id'], '%' . $fol . '%']);
                        if (!$stmtNChk->fetchColumn()) {
                            crearNotificacion(
                                'tickets',
                                'ticket_creado',
                                "Ticket #$fol Registrado",
                                "Tu requerimiento \"" . ($myTk['titulo'] ?? 'Ticket') . "\" fue recibido en el sistema de soporte.",
                                'tickets.php?ver=' . $fol,
                                'bi-ticket-detailed-fill',
                                'success',
                                $myTk['id'],
                                $uIdSess,
                                $pdo
                            );
                        }
                    }

                    // B. Consultar mensajes y respuestas remotas de Central TI vía cURL
                    $urlM = 'https://portal.grupohuerta.mx/api_consultar_tickets.php?mensajes=1&folio=' . urlencode($fol) . '&token=' . urlencode('GH_SISTEMAS_TICKETS_2026!');
                    $chM = curl_init($urlM);
                    curl_setopt_array($chM, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 3,
                        CURLOPT_CONNECTTIMEOUT => 2,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer GH_SISTEMAS_TICKETS_2026!',
                            'User-Agent: PortalAgenciaNotif/2.0'
                        ]
                    ]);
                    $resM = curl_exec($chM);
                    $httpCodeM = curl_getinfo($chM, CURLINFO_HTTP_CODE);
                    curl_close($chM);

                    if ($resM !== false && $httpCodeM === 200) {
                        $decM = json_decode($resM, true);
                        if (!empty($decM['status']) && $decM['status'] === 'ok' && !empty($decM['mensajes'])) {
                            $stmtChkM = $pdo->prepare("SELECT id FROM tickets_mensajes WHERE (ticket_id = ? OR folio = ?) AND mensaje = ? LIMIT 1");
                            $stmtInsM = $pdo->prepare("
                                INSERT INTO tickets_mensajes 
                                (ticket_id, folio, autor_id, autor_nombre, autor_usuario, autor_rol, autor_agencia, mensaje, archivo_adjunto, creado_en)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");

                            foreach ($decM['mensajes'] as $rm) {
                                $txtMsg = trim($rm['mensaje'] ?? '');
                                if (empty($txtMsg)) continue;
                                $rolAut = strtolower(trim($rm['autor_rol'] ?? 'agente'));

                                // Guardar en tickets_mensajes si no existe
                                $stmtChkM->execute([$myTk['id'], $fol, $txtMsg]);
                                if (!$stmtChkM->fetchColumn()) {
                                    $stmtInsM->execute([
                                        $myTk['id'],
                                        $fol,
                                        $rm['autor_id'] ?? null,
                                        $rm['autor_nombre'] ?? 'Sistemas',
                                        $rm['autor_usuario'] ?? '',
                                        $rm['autor_rol'] ?? 'agente',
                                        $rm['autor_agencia'] ?? 'Central',
                                        $txtMsg,
                                        $rm['archivo_adjunto'] ?? null,
                                        $rm['creado_en'] ?? date('Y-m-d H:i:s')
                                    ]);
                                }

                                // Crear notificación en la campanita si es contestación de TI y no se ha notificado
                                if (($rolAut === 'agente' || $rolAut === 'admin' || $rolAut === 'superadmin') && function_exists('crearNotificacion')) {
                                    $stmtNotifChk = $pdo->prepare("
                                        SELECT id FROM notificaciones 
                                        WHERE modulo = 'tickets' 
                                          AND (referencia_id = ? OR enlace LIKE ?) 
                                          AND mensaje LIKE ?
                                        LIMIT 1
                                    ");
                                    $stmtNotifChk->execute([$myTk['id'], '%' . $fol . '%', '%' . mb_substr($txtMsg, 0, 30) . '%']);
                                    if (!$stmtNotifChk->fetchColumn()) {
                                        crearNotificacion(
                                            'tickets',
                                            'mensaje_ticket',
                                            "Respuesta en tu Ticket #$fol",
                                            "El agente " . ($rm['autor_nombre'] ?? 'de soporte') . " contestó: \"" . mb_substr($txtMsg, 0, 80) . "\"",
                                            'tickets.php?ver=' . $fol,
                                            'bi-chat-dots-fill',
                                            'primary',
                                            $myTk['id'],
                                            $uIdSess,
                                            $pdo
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable $ePullSync) {}
    }

    $resultado = obtenerNotificacionesUsuario($usuarioId, 30, $pdo);
    echo json_encode([
        'exito' => true,
        'notificaciones' => $resultado['notificaciones'],
        'total_no_leidas' => $resultado['total_no_leidas']
    ]);
    exit();
}

if ($action === 'marcar_leida') {
    $notifId = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    $ok = marcarNotificacionLeida($notifId, $usuarioId, $pdo);
    echo json_encode(['exito' => $ok]);
    exit();
}

if ($action === 'marcar_todas') {
    $ok = marcarTodasNotificacionesLeidas($usuarioId, $pdo);
    echo json_encode(['exito' => $ok]);
    exit();
}

echo json_encode(['exito' => false, 'error' => 'Acción no soportada.']);
exit();
