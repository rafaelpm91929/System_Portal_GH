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


// Cargar permisos y asegurar tablas
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Usuario');
$loginUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? '');
$emailUsuario = $_SESSION['usuario_email'] ?? '';
$agenciaUsuario = $_SESSION['agencia'] ?? 'Grupo Huerta';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

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

    $urlCentral = 'https://portal.grupohuerta.mx/api_consultar_tickets.php';
    $token = getenv('TOKEN_SECRETO') ?: 'GedasDivolavilla2026!';

    try {
        // Obtener folios locales registrados
        $stmtFolios = $pdo->query("SELECT folio FROM tickets_soporte WHERE folio IS NOT NULL AND folio != '' ORDER BY id DESC LIMIT 50");
        $folios = $stmtFolios ? $stmtFolios->fetchAll(PDO::FETCH_COLUMN) : [];

        $payload = [
            'token'   => $token,
            'agencia' => 'Divol La Villa'
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
                        solicitante_usuario = COALESCE(NULLIF(solicitante_usuario, ''), :sol_usr),
                        actualizado_en = :act
                    WHERE folio = :folio
                ");

                $stmtIns = $pdo->prepare("
                    INSERT INTO tickets_soporte 
                    (folio, area_sistemas, titulo, descripcion, prioridad, estado, asignado_a, notas_resolucion, solicitante_usuario, solicitante_nombre, solicitante_email, solicitante_agencia, creado_en, actualizado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($dec['tickets'] as $tc) {
                    if (empty($tc['folio'])) continue;

                    $stmtChk = $pdo->prepare("SELECT id FROM tickets_soporte WHERE folio = ? LIMIT 1");
                    $stmtChk->execute([$tc['folio']]);
                    $existe = $stmtChk->fetchColumn();

                    if ($existe) {
                        $stmtUpd->execute([
                            ':estado'    => $tc['estado'] ?? 'Abierto',
                            ':asignado'  => $tc['asignado_a'] ?? '',
                            ':notas'     => $tc['notas_resolucion'] ?? '',
                            ':prioridad' => $tc['prioridad'] ?? 'Media',
                            ':sol_usr'   => $tc['solicitante_usuario'] ?? '',
                            ':act'       => $tc['actualizado_en'] ?? date('Y-m-d H:i:s'),
                            ':folio'     => $tc['folio']
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
                            $tc['solicitante_usuario'] ?? '',
                            $tc['solicitante_nombre'] ?? 'Usuario',
                            $tc['solicitante_email'] ?? '',
                            $tc['solicitante_agencia'] ?? 'Divol La Villa',
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

    // 2. Enviar nuevo mensaje / contestar al ticket
    elseif ($accion === 'enviar_mensaje') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $textoMensaje = trim($_POST['mensaje'] ?? '');

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

                $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
                $sqlFecha = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";

                $stmtMsg = $pdo->prepare("
                    INSERT INTO tickets_mensajes 
                    (ticket_id, folio, autor_id, autor_nombre, autor_usuario, autor_rol, mensaje, archivo_adjunto, creado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, $sqlFecha)
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
                    $archivoMensaje
                ]);

                $stmtUp = $pdo->prepare("UPDATE tickets_soporte SET actualizado_en = $sqlFecha WHERE id = ?");
                $stmtUp->execute([$ticketId]);

                // Notificar a Central de Sistemas en background
                try {
                    $token = getenv('TOKEN_SECRETO') ?: 'GedasDivolavilla2026!';
                    $payloadCentral = [
                        'token'     => $token,
                        'accion'    => 'nuevo_mensaje',
                        'folio'     => $folioT,
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

                header("Location: tickets.php?ver=" . $ticketId . "&msg_ok=1#conversacion");
                exit;
            } catch (Throwable $t) {
                $error = "Error al guardar el mensaje: " . $t->getMessage();
            }
        }
    }

    // 3. Actualizar Estado / Seguimiento de Ticket
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

                header("Location: tickets.php?ver=" . $ticketId . "&estado_ok=1");
                exit;
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

// Consulta de ticket seleccionado para la pestaña de detalle
$verTicketId = intval($_GET['ver'] ?? ($_GET['id'] ?? 0));
$ticketSeleccionado = null;
$mensajesSeleccionados = [];

if ($verTicketId > 0 && $pdo) {
    try {
        $stmtSel = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
        $stmtSel->execute([$verTicketId]);
        $ticketSeleccionado = $stmtSel->fetch(PDO::FETCH_ASSOC);

        if ($ticketSeleccionado) {
            if (!$esAdmin) {
                $pertenece = false;
                if (!empty($ticketSeleccionado['solicitante_id']) && (int)$ticketSeleccionado['solicitante_id'] === (int)$usuarioId) $pertenece = true;
                if (!empty($ticketSeleccionado['solicitante_usuario']) && !empty($loginUsuario) && strtolower($ticketSeleccionado['solicitante_usuario']) === strtolower($loginUsuario)) $pertenece = true;
                if (!empty($ticketSeleccionado['solicitante_email']) && !empty($emailUsuario) && strtolower($ticketSeleccionado['solicitante_email']) === strtolower($emailUsuario)) $pertenece = true;
                if (!empty($ticketSeleccionado['solicitante_nombre']) && !empty($nombreUsuario) && strtolower($ticketSeleccionado['solicitante_nombre']) === strtolower($nombreUsuario)) $pertenece = true;

                if (!$pertenece) {
                    $ticketSeleccionado = null;
                }
            }

            if ($ticketSeleccionado) {
                $stmtM = $pdo->prepare("SELECT * FROM tickets_mensajes WHERE ticket_id = ? ORDER BY id ASC");
                $stmtM->execute([$verTicketId]);
                $mensajesSeleccionados = $stmtM->fetchAll(PDO::FETCH_ASSOC);
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
                                                            <span class="text-secondary" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?php echo date('d/m/Y H:i', strtotime($t['actualizado_en'])); ?></span>
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
                                            <div class="d-inline-flex gap-1.5">
                                                <!-- Ver Detalle / Contestar en la Misma Pantalla -->
                                                <a href="tickets.php?ver=<?php echo $t['id']; ?>" class="btn-action-icon text-info" title="Ver Detalle y Contestar Ticket">
                                                    <i class="bi bi-chat-left-dots-fill"></i>
                                                </a>
                                                
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
                            </div>
                            <h2 class="fw-bold text-white mb-1"><?php echo htmlspecialchars($ticketSeleccionado['titulo']); ?></h2>
                            <div class="text-secondary small">
                                <i class="bi bi-calendar3 me-1"></i> Creado el <?php echo !empty($ticketSeleccionado['creado_en']) ? date('d/m/Y \a \l\a\s H:i', strtotime($ticketSeleccionado['creado_en'])) : '---'; ?>
                                <?php if (!empty($ticketSeleccionado['actualizado_en'])): ?>
                                    &bull; <i class="bi bi-clock-history me-1"></i> Última actividad: <?php echo date('d/m/Y H:i', strtotime($ticketSeleccionado['actualizado_en'])); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="p-2.5 rounded-3 text-end" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.1);">
                                <div class="text-secondary small fw-bold" style="font-size: 0.7rem;">FOLIO</div>
                                <div class="fs-4 font-monospace fw-bold text-info"><?php echo htmlspecialchars($ticketSeleccionado['folio'] ?? ('#TK-' . $ticketSeleccionado['id'])); ?></div>
                            </div>
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
                    <h5 class="fw-bold text-info mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-card-text"></i> Descripción del Requerimiento Original
                    </h5>
                    <div class="p-3.5 rounded-3 text-white" style="background: #061325; border: 1px solid rgba(255, 255, 255, 0.1); font-size: 0.98rem; line-height: 1.6; white-space: pre-line;">
                        <?php echo htmlspecialchars($ticketSeleccionado['descripcion']); ?>
                    </div>

                    <?php if (!empty($ticketSeleccionado['archivo_adjunto'])): 
                        $extT = strtolower(pathinfo($ticketSeleccionado['archivo_adjunto'], PATHINFO_EXTENSION));
                        $esImgT = in_array($extT, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                    ?>
                        <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                            <div class="text-secondary small fw-bold text-uppercase mb-2">
                                <i class="bi bi-paperclip text-info me-1"></i> Evidencia / Archivo Adjunto Original
                            </div>
                            <?php if ($esImgT && file_exists(__DIR__ . '/' . $ticketSeleccionado['archivo_adjunto'])): ?>
                                <div class="mb-2">
                                    <a href="<?php echo htmlspecialchars($ticketSeleccionado['archivo_adjunto']); ?>" target="_blank" title="Ver imagen completa">
                                        <img src="<?php echo htmlspecialchars($ticketSeleccionado['archivo_adjunto']); ?>" alt="Evidencia" class="preview-img">
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div>
                                <a href="<?php echo htmlspecialchars($ticketSeleccionado['archivo_adjunto']); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-semibold">
                                    <i class="bi bi-box-arrow-up-right me-1"></i> Ver / Descargar Archivo (<?php echo strtoupper($extT); ?>)
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Respuesta de Central Sistemas si existe -->
                <?php if (!empty(trim($ticketSeleccionado['notas_resolucion'] ?? ''))): ?>
                    <div class="detail-card" style="border-color: rgba(56, 189, 248, 0.45); background: linear-gradient(145deg, #09213d 0%, #06182c 100%);">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h5 class="fw-bold text-info mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-headset"></i> Respuesta Oficial de la Dirección Central de Sistemas
                            </h5>
                            <span class="badge bg-primary rounded-pill px-3 py-1">
                                <?php echo !empty($ticketSeleccionado['asignado_a']) ? htmlspecialchars($ticketSeleccionado['asignado_a']) : 'Dirección Central TI'; ?>
                            </span>
                        </div>
                        <div class="p-3 rounded-3 text-white mt-3" style="background: rgba(3, 11, 23, 0.7); border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.95rem; line-height: 1.6; white-space: pre-line;">
                            <?php echo htmlspecialchars($ticketSeleccionado['notas_resolucion']); ?>
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
                                                        <img src="<?php echo htmlspecialchars($msg['archivo_adjunto']); ?>" alt="Captura" style="max-height: 220px; border-radius: 8px;">
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

                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                <div>
                                    <label class="text-secondary small fw-bold text-uppercase d-block mb-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-paperclip me-1"></i> Adjuntar captura o archivo (opcional)
                                    </label>
                                    <input type="file" name="adjunto_mensaje" class="form-control form-control-sm rounded-3" style="background: #061325; color: #fff; border: 1px solid rgba(255, 255, 255, 0.15); max-width: 380px;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
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
        if (window.location.hash === '#conversacion') {
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
</script>
<?php include_once 'pwa_body.php'; ?>
</body>
</html>
