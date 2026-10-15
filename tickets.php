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

date_default_timezone_set('America/Mexico_City');
if ($pdo) {
    try { $pdo->exec("SET time_zone = '-06:00'"); } catch (Throwable $tzE) {}
}

/**
 * Formatea la fecha y hora de mensajes y tickets a la hora local oficial de México (America/Mexico_City).
 * Detecta y corrige automáticamente cualquier desfase UTC (+2 a +8 horas adelante) generado por MySQL o servidores en la nube.
 */
if (!function_exists('formatearFechaHoraMensaje')) {
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
}

// Normalizador unificado de nombres de agencia
if (!function_exists('normalizarAgencia')) {
    function normalizarAgencia($nombre) {
        $nombre = trim($nombre ?? '');
        if (empty($nombre)) return 'Oficina Central Grupo Huerta';
        if (stripos($nombre, 'divol') !== false) return 'Divol La Villa';
        if (stripos($nombre, 'central') !== false) return 'Oficina Central Grupo Huerta';
        return $nombre;
    }
}

// Protección de Sesión y Control de Acceso Estricto
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

if ($pdo) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
}
requerirPermiso('tickets', 'puede_ver');

// Cargar datos de usuario
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Agente de Sistemas');
$agenciaUsuario = $_SESSION['agencia'] ?? 'Oficina Central Grupo Huerta';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esSuperAdmin = ($rolActual === 'superadmin');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

if ($pdo) {
    asegurarTablaTickets($pdo);
    asegurarTablaCitas($pdo);
    asegurarColumnasUsuarios($pdo);

    // Endpoint AJAX exclusivo para SuperAdmin: Ficha y estadísticas del Agente
    if (isset($_GET['ajax_agente_stats'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$esSuperAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: Solo el rol SuperAdmin puede consultar la ficha y estadísticas del agente.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $terminoAgente = trim($_GET['agente'] ?? '');
        $datosAgente = obtenerDatosAgente($pdo, $terminoAgente);
        $usuarioAgente = $datosAgente['usuario'] ?? null;
        $statsAgente = obtenerEstadisticasAgente($pdo, $terminoAgente, $usuarioAgente);
        echo json_encode([
            'status' => 'ok',
            'agente' => $datosAgente,
            'stats'  => $statsAgente
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (isset($_GET['ajax_mensajes'])) {
        header('Content-Type: application/json; charset=utf-8');
        $tkId = intval($_GET['ajax_mensajes']);
        $stmtM = $pdo->prepare("SELECT * FROM tickets_mensajes WHERE ticket_id = ? ORDER BY id ASC");
        $stmtM->execute([$tkId]);
        echo json_encode(['status' => 'ok', 'mensajes' => $stmtM->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// Catálogo Oficial Maestro de Áreas de Sistemas
$AREAS_SISTEMAS_CATALOGO = [
    'DESARROLLO'      => ['nombre' => 'DESARROLLO', 'icono' => 'bi-code-slash', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.18)', 'border' => 'rgba(56, 189, 248, 0.45)'],
    'CYBERSEGURIDAD'  => ['nombre' => 'CYBERSEGURIDAD', 'icono' => 'bi-shield-lock-fill', 'color' => '#f43f5e', 'bg' => 'rgba(244, 63, 94, 0.18)', 'border' => 'rgba(244, 63, 94, 0.45)'],
    'INFRAESTRUCTURA' => ['nombre' => 'INFRAESTRUCTURA', 'icono' => 'bi-hdd-rack-fill', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.18)', 'border' => 'rgba(16, 185, 129, 0.45)'],
    'REDES SOCIALES'  => ['nombre' => 'REDES SOCIALES', 'icono' => 'bi-share-fill', 'color' => '#a855f7', 'bg' => 'rgba(168, 85, 247, 0.18)', 'border' => 'rgba(168, 85, 247, 0.45)'],
    'AUDITORIA'          => ['nombre' => 'AUDITORIA', 'icono' => 'bi-clipboard-check-fill', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.18)', 'border' => 'rgba(245, 158, 11, 0.45)'],
    'CORPORATIVO'        => ['nombre' => 'CORPORATIVO', 'icono' => 'bi-building-fill', 'color' => '#6366f1', 'bg' => 'rgba(99, 102, 241, 0.18)', 'border' => 'rgba(99, 102, 241, 0.45)'],
    'DIRECCION SISTEMAS' => ['nombre' => 'DIRECCION SISTEMAS', 'icono' => 'bi-diagram-3-fill', 'color' => '#06b6d4', 'bg' => 'rgba(6, 182, 212, 0.18)', 'border' => 'rgba(6, 182, 212, 0.45)']
];
$AREAS_SISTEMAS_CATALOGO['DIRECCIÓN SISTEMAS'] = $AREAS_SISTEMAS_CATALOGO['DIRECCION SISTEMAS'];

// Obtener áreas de tickets autorizadas para este usuario (desde sesión o recargar de BD)
$areasRaw = trim($_SESSION['areas_tickets'] ?? '');
if (empty($areasRaw) && $pdo && !empty($usuarioId)) {
    try {
        $stmtU = $pdo->prepare("SELECT rol, areas_tickets FROM usuarios WHERE id = ?");
        $stmtU->execute([$usuarioId]);
        $rowU = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($rowU) {
            $areasRaw = trim($rowU['areas_tickets'] ?? '');
            $_SESSION['areas_tickets'] = $areasRaw;
            if (isset($rowU['rol'])) {
                $_SESSION['usuario_rol'] = $rowU['rol'];
                $rolActual = strtolower($rowU['rol']);
                $esSuperAdmin = ($rolActual === 'superadmin');
                $esAdmin = in_array($rolActual, ['superadmin', 'admin']);
            }
        }
    } catch (Throwable $e) {}
}

$accesoTodasAreas = ($esSuperAdmin || strtoupper($areasRaw) === 'TODOS' || $areasRaw === '*');
$areasAutorizadas = [];

if ($accesoTodasAreas) {
    $areasAutorizadas = array_keys($AREAS_SISTEMAS_CATALOGO);
} elseif (!empty($areasRaw) && strtoupper($areasRaw) !== 'NINGUNA') {
    $parts = array_map('trim', explode(',', strtoupper($areasRaw)));
    $areasAutorizadas = array_values(array_intersect($parts, array_keys($AREAS_SISTEMAS_CATALOGO)));
}

// Catálogo activo para la sesión actual (determina qué chips y selects se despliegan)
$AREAS_SISTEMAS = [];
foreach ($areasAutorizadas as $aKey) {
    if (isset($AREAS_SISTEMAS_CATALOGO[$aKey])) {
        $AREAS_SISTEMAS[$aKey] = $AREAS_SISTEMAS_CATALOGO[$aKey];
    }
}

$mensaje = $_SESSION['flash_mensaje'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);

// ====================================================
// MANEJO DE ACCIONES POST (CREAR, EDITAR, ATENDER)
// ====================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. Crear / Registrar Nuevo Ticket de Soporte (Por Agente)
    if ($accion === 'crear_ticket') {
        $area = trim($_POST['area_sistemas'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad = trim($_POST['prioridad'] ?? 'Media');
        $solicitanteNombre = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
        $solicitanteEmail = trim($_POST['solicitante_email'] ?? '');
        $solicitanteAgencia = normalizarAgencia(trim($_POST['solicitante_agencia'] ?? 'Divol La Villa'));
        $asignadoA = trim($_POST['asignado_a'] ?? '');

        if (!$accesoTodasAreas && !in_array(strtoupper($area), $areasAutorizadas)) {
            $error = "Acceso denegado: No tienes autorización para registrar tickets en el área '$area'.";
        } elseif (empty($titulo) || empty($descripcion) || empty($area)) {
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

                $solicitanteUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? '');
                $stmt = $pdo->prepare("
                    INSERT INTO tickets_soporte 
                    (folio, area_sistemas, titulo, descripcion, prioridad, estado, solicitante_id, solicitante_usuario, solicitante_nombre, solicitante_email, solicitante_agencia, asignado_a, archivo_adjunto)
                    VALUES
                    (:folio, :area, :titulo, :desc, :prio, 'Abierto', :sol_id, :sol_usr, :sol_nom, :sol_em, :sol_ag, :asignado, :archivo)
                ");
                $stmt->execute([
                    ':folio'     => $folio,
                    ':area'      => $area,
                    ':titulo'    => $titulo,
                    ':desc'      => $descripcion,
                    ':prio'      => $prioridad,
                    ':sol_id'    => $usuarioId,
                    ':sol_usr'   => $solicitanteUsuario,
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
                // Validación estricta de área autorizada
                $stmtChk = $pdo->prepare("SELECT area_sistemas FROM tickets_soporte WHERE id = ?");
                $stmtChk->execute([$ticketId]);
                $areaTicket = strtoupper(trim($stmtChk->fetchColumn() ?: ''));

                if (!$accesoTodasAreas && !in_array($areaTicket, $areasAutorizadas)) {
                    $error = "Acceso denegado: No tienes autorización para modificar tickets del área '$areaTicket'.";
                } else {
                    // Procesar archivo de evidencia (PDF, fotos, Excel, etc.)
                    $archivoEvidenciaUrl = null;
                    if (isset($_FILES['archivo_evidencia']) && $_FILES['archivo_evidencia']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['archivo_evidencia']['tmp_name'];
                        $fileName = $_FILES['archivo_evidencia']['name'];
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'xls', 'xlsx', 'csv', 'doc', 'docx', 'txt', 'zip'];

                        if (in_array($fileExt, $extPermitidas)) {
                            $dirUpload = __DIR__ . '/uploads/tickets/';
                            if (!is_dir($dirUpload)) @mkdir($dirUpload, 0777, true);
                            $nuevoNombre = 'evidencia_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                            if (move_uploaded_file($fileTmp, $dirUpload . $nuevoNombre)) {
                                $archivoEvidenciaUrl = 'uploads/tickets/' . $nuevoNombre;
                            }
                        }
                    }

                    $fechaAct = date('Y-m-d H:i:s');

                    if ($archivoEvidenciaUrl !== null) {
                        $stmt = $pdo->prepare("
                            UPDATE tickets_soporte 
                            SET estado = :estado, asignado_a = :asignado, prioridad = :prioridad, notas_resolucion = :notas, evidencia_resolucion = :evidencia, actualizado_en = :act 
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':estado'    => $nuevoEstado,
                            ':asignado'  => $asignadoA,
                            ':prioridad' => $prioridad,
                            ':notas'     => $notasResolucion,
                            ':evidencia' => $archivoEvidenciaUrl,
                            ':act'       => $fechaAct,
                            ':id'        => $ticketId
                        ]);

                        // Registrar la evidencia en el hilo de la conversación
                        try {
                            $msgTextoEvidencia = !empty($notasResolucion) 
                                ? "📎 Evidencia de gestión adjuntada:\n" . $notasResolucion 
                                : "📎 Evidencia de atención adjuntada por el especialista (" . ($asignadoA ?: $nombreUsuario) . ").";
                            $stmtMsgEv = $pdo->prepare("
                                INSERT INTO tickets_mensajes
                                (ticket_id, autor_id, autor_usuario, autor_nombre, autor_email, autor_rol, autor_agencia, mensaje, archivo_adjunto, es_nota_interna, creado_en)
                                VALUES
                                (:tk, :aut_id, :aut_usr, :aut_nom, :aut_em, 'agente', :aut_ag, :msg, :adj, 0, :creado_en)
                            ");
                            $stmtMsgEv->execute([
                                ':tk'        => $ticketId,
                                ':aut_id'    => $usuarioId,
                                ':aut_usr'   => $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? ''),
                                ':aut_nom'   => $nombreUsuario,
                                ':aut_em'    => $_SESSION['usuario_email'] ?? '',
                                ':aut_ag'    => $agenciaUsuario,
                                ':msg'       => $msgTextoEvidencia,
                                ':adj'       => $archivoEvidenciaUrl,
                                ':creado_en' => $fechaAct
                            ]);
                        } catch (Throwable $eMsgEv) {}
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE tickets_soporte 
                            SET estado = :estado, asignado_a = :asignado, prioridad = :prioridad, notas_resolucion = :notas, actualizado_en = :act 
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':estado'    => $nuevoEstado,
                            ':asignado'  => $asignadoA,
                            ':prioridad' => $prioridad,
                            ':notas'     => $notasResolucion,
                            ':act'       => $fechaAct,
                            ':id'        => $ticketId
                        ]);
                    }

                    // Notificar a la agencia sucursal en tiempo real si tiene endpoint configurado
                    try {
                        $stmtTInfo = $pdo->prepare("SELECT folio, solicitante_agencia, evidencia_resolucion FROM tickets_soporte WHERE id = ?");
                        $stmtTInfo->execute([$ticketId]);
                        $tInfo = $stmtTInfo->fetch(PDO::FETCH_ASSOC);

                        if ($tInfo && !empty($tInfo['solicitante_agencia'])) {
                            $agKey = strpos(strtolower($tInfo['solicitante_agencia']), 'divol') !== false ? 'divolavilla' : '';
                            $targetUrl = 'https://portal.divolavilla.com/api_webhook_ticket.php';
                            if (!empty($CATALOGO_AGENCIAS[$agKey]['endpoint'])) {
                                $targetUrl = str_replace(['/sistemas/api_obtener_datos.php', 'api_obtener_datos.php'], '/api_webhook_ticket.php', $CATALOGO_AGENCIAS[$agKey]['endpoint']);
                            }

                            $tokenWh = !empty($CATALOGO_AGENCIAS[$agKey]['token']) ? $CATALOGO_AGENCIAS[$agKey]['token'] : 'GH_SISTEMAS_TICKETS_2026!';
                            $postDataWh = [
                                'token'               => $tokenWh,
                                'folio'               => $tInfo['folio'],
                                'estado'              => $nuevoEstado,
                                'asignado_a'          => $asignadoA,
                                'prioridad'           => $prioridad,
                                'notas_resolucion'    => $notasResolucion,
                                'evidencia_resolucion'=> $archivoEvidenciaUrl ?: ($tInfo['evidencia_resolucion'] ?? null),
                                'actualizado_en'      => $fechaAct
                            ];

                            if ($archivoEvidenciaUrl && file_exists(__DIR__ . '/' . $archivoEvidenciaUrl)) {
                                $postDataWh['archivo_base64'] = base64_encode(file_get_contents(__DIR__ . '/' . $archivoEvidenciaUrl));
                                $postDataWh['archivo_nombre'] = basename($archivoEvidenciaUrl);
                                $postDataWh['mensaje'] = !empty($notasResolucion) ? $notasResolucion : "Evidencia de atención técnica adjuntada.";
                                $postDataWh['autor_nombre'] = $nombreUsuario;
                                $postDataWh['autor_rol'] = 'agente';
                            }

                            $chWh = curl_init($targetUrl . '?token=' . urlencode($tokenWh));
                            curl_setopt_array($chWh, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_POST           => true,
                                CURLOPT_POSTFIELDS     => http_build_query($postDataWh),
                                CURLOPT_TIMEOUT        => 4,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_HTTPHEADER     => [
                                    'Authorization: Bearer ' . $tokenWh,
                                    'Content-Type: application/x-www-form-urlencoded'
                                ]
                            ]);
                            @curl_exec($chWh);
                            @curl_close($chWh);
                        }
                    } catch (Throwable $eWh) {}

                    $mensaje = "Ticket ID #$ticketId actualizado correctamente por el agente.";
                    if (!empty($_POST['redirect_ver'])) {
                        header("Location: tickets.php?ver=" . $ticketId . "&actualizado=1");
                        exit();
                    }
                }
            } catch (Throwable $e) {
                $error = "Error al actualizar el ticket: " . $e->getMessage();
            }
        }
    }

    // 2.05 Guardar Formato de Reporte de Servicio Técnico (Infraestructura) para Firma Digital
    if ($accion === 'guardar_reporte_servicio') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $trabajoSolicitado = trim($_POST['reporte_trabajo_solicitado'] ?? '');
        $observaciones = trim($_POST['reporte_observaciones'] ?? '');
        $trabajosRealizados = trim($_POST['reporte_trabajos_realizados'] ?? '');
        $tecnicoNombre = trim($_POST['reporte_tecnico_nombre'] ?? $nombreUsuario);
        $deshabilitarFirma = !empty($_POST['deshabilitar_reporte']) ? 1 : 0;
        $habilitarFirma = $deshabilitarFirma ? 0 : 1;

        if ($ticketId > 0) {
            try {
                $stmtTk = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
                $stmtTk->execute([$ticketId]);
                $tkData = $stmtTk->fetch(PDO::FETCH_ASSOC);

                if (!$tkData) {
                    $error = "Ticket no encontrado.";
                } elseif (strtoupper(trim($tkData['area_sistemas'] ?? '')) !== 'INFRAESTRUCTURA') {
                    $error = "El formato de reporte de servicio con firma digital está disponible únicamente para tickets del área de INFRAESTRUCTURA.";
                } else {
                    $fechaAct = date('Y-m-d H:i:s');
                    $folioTk = $tkData['folio'] ?? ('TK-' . $ticketId);
                    $problemaTk = $tkData['descripcion'] ?? '';

                    $stmtUpdRep = $pdo->prepare("
                        UPDATE tickets_soporte 
                        SET requiere_firma_reporte = :req,
                            reporte_orden_no = :folio,
                            reporte_problema = :prob,
                            reporte_trabajo_solicitado = :sol,
                            reporte_observaciones = :obs,
                            reporte_trabajos_realizados = :real,
                            reporte_tecnico_nombre = :tec,
                            actualizado_en = :act
                        WHERE id = :id
                    ");
                    $stmtUpdRep->execute([
                        ':req'   => $habilitarFirma,
                        ':folio' => $folioTk,
                        ':prob'  => $problemaTk,
                        ':sol'   => $trabajoSolicitado,
                        ':obs'   => $observaciones,
                        ':real'  => $trabajosRealizados,
                        ':tec'   => $tecnicoNombre,
                        ':act'   => $fechaAct,
                        ':id'    => $ticketId
                    ]);

                    // Registrar mensaje en el chat del ticket si se habilitó
                    if ($habilitarFirma) {
                        try {
                            $avisoChat = "📋 Se ha generado el Reporte Oficial de Servicio Técnico (Orden No. " . $folioTk . "). Favor de revisarlo y firmarlo digitalmente en su portal.";
                            $stmtMsgRep = $pdo->prepare("
                                INSERT INTO tickets_mensajes
                                (ticket_id, autor_usuario, autor_nombre, autor_rol, autor_agencia, mensaje, creado_en)
                                VALUES
                                (?, ?, ?, 'agente', 'Dirección Sistemas', ?, ?)
                            ");
                            $stmtMsgRep->execute([$ticketId, $loginUsuario, $tecnicoNombre, $avisoChat, $fechaAct]);
                        } catch (Throwable $tMsgR) {}
                    }

                    // Disparar Webhook a la agencia sucursal para sincronización en tiempo real
                    try {
                        if (!empty($tkData['solicitante_agencia'])) {
                            $agKey = strpos(strtolower($tkData['solicitante_agencia']), 'divol') !== false ? 'divolavilla' : '';
                            $targetUrl = 'https://portal.divolavilla.com/api_webhook_ticket.php';
                            if (!empty($CATALOGO_AGENCIAS[$agKey]['endpoint'])) {
                                $targetUrl = str_replace(['/sistemas/api_obtener_datos.php', 'api_obtener_datos.php'], '/api_webhook_ticket.php', $CATALOGO_AGENCIAS[$agKey]['endpoint']);
                            }
                            $tokenWh = !empty($CATALOGO_AGENCIAS[$agKey]['token']) ? $CATALOGO_AGENCIAS[$agKey]['token'] : 'GH_SISTEMAS_TICKETS_2026!';
                            $postDataWh = [
                                'token'                       => $tokenWh,
                                'folio'                       => $folioTk,
                                'accion'                      => 'reporte_servicio',
                                'requiere_firma_reporte'      => $habilitarFirma,
                                'reporte_orden_no'            => $folioTk,
                                'reporte_problema'            => $problemaTk,
                                'reporte_trabajo_solicitado'  => $trabajoSolicitado,
                                'reporte_observaciones'       => $observaciones,
                                'reporte_trabajos_realizados' => $trabajosRealizados,
                                'reporte_tecnico_nombre'      => $tecnicoNombre,
                                'mensaje'                     => $habilitarFirma ? "📋 Se ha habilitado el Reporte de Servicio Técnico (Orden No. {$folioTk}). Requiere su firma digital." : "",
                                'autor_nombre'                => $tecnicoNombre,
                                'autor_rol'                   => 'agente',
                                'actualizado_en'              => $fechaAct
                            ];
                            $chWh = curl_init($targetUrl . '?token=' . urlencode($tokenWh));
                            curl_setopt_array($chWh, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_POST           => true,
                                CURLOPT_POSTFIELDS     => http_build_query($postDataWh),
                                CURLOPT_TIMEOUT        => 4,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_HTTPHEADER     => [
                                    'Authorization: Bearer ' . $tokenWh,
                                    'Content-Type: application/x-www-form-urlencoded'
                                ]
                            ]);
                            @curl_exec($chWh);
                            @curl_close($chWh);
                        }
                    } catch (Throwable $eWhR) {}

                    $mensaje = $habilitarFirma ? "Reporte de servicio para Infraestructura guardado y enviado para firma digital del usuario." : "Reporte de servicio actualizado.";
                    header("Location: tickets.php?ver=" . $ticketId . "&reporte_guardado=1");
                    exit();
                }
            } catch (Throwable $eRep) {
                $error = "Error al guardar el reporte de servicio: " . $eRep->getMessage();
            }
        }
    }

    // 2.1 Enviar Contestación / Respuesta en el Chat del Ticket
    if ($accion === 'agregar_mensaje_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $mensajeTxt = trim($_POST['mensaje'] ?? '');
        $esNotaInterna = !empty($_POST['es_nota_interna']) ? 1 : 0;
        $cambioEstado = trim($_POST['cambio_estado'] ?? '');

        if ($ticketId > 0 && !empty($mensajeTxt)) {
            try {
                // Validación estricta de área autorizada
                $stmtChk = $pdo->prepare("SELECT id, folio, area_sistemas, solicitante_agencia, asignado_a, estado FROM tickets_soporte WHERE id = ?");
                $stmtChk->execute([$ticketId]);
                $tActual = $stmtChk->fetch(PDO::FETCH_ASSOC);

                if (!$tActual) {
                    $error = "El ticket especificado no existe.";
                } else {
                    $areaTicket = strtoupper(trim($tActual['area_sistemas'] ?? ''));
                    if (!$accesoTodasAreas && !in_array($areaTicket, $areasAutorizadas)) {
                        $error = "Acceso denegado: No tienes autorización para responder en el área '$areaTicket'.";
                    } else {
                        // Procesar evidencia adjunta si se envió
                        $archivoMsgUrl = null;
                        if (isset($_FILES['archivo_mensaje']) && $_FILES['archivo_mensaje']['error'] === UPLOAD_ERR_OK) {
                            $fileTmp = $_FILES['archivo_mensaje']['tmp_name'];
                            $fileName = $_FILES['archivo_mensaje']['name'];
                            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                            $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];
                            if (in_array($fileExt, $extPermitidas)) {
                                $dirUpload = __DIR__ . '/uploads/tickets/';
                                if (!is_dir($dirUpload)) @mkdir($dirUpload, 0777, true);
                                $nuevoNombre = 'chat_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                                if (move_uploaded_file($fileTmp, $dirUpload . $nuevoNombre)) {
                                    $archivoMsgUrl = 'uploads/tickets/' . $nuevoNombre;
                                }
                            }
                        }

                        $autorRol = ($esAdmin) ? 'agente' : 'usuario';
                        $autorUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? '');

                        $fechaMsgActual = date('Y-m-d H:i:s');
                        $stmtMsg = $pdo->prepare("
                            INSERT INTO tickets_mensajes
                            (ticket_id, autor_id, autor_usuario, autor_nombre, autor_email, autor_rol, autor_agencia, mensaje, archivo_adjunto, es_nota_interna, creado_en)
                            VALUES
                            (:tk, :aut_id, :aut_usr, :aut_nom, :aut_em, :aut_rol, :aut_ag, :msg, :adj, :interna, :creado_en)
                        ");
                        $stmtMsg->execute([
                            ':tk'        => $ticketId,
                            ':aut_id'    => $usuarioId,
                            ':aut_usr'   => $autorUsuario,
                            ':aut_nom'   => $nombreUsuario,
                            ':aut_em'    => $_SESSION['usuario_email'] ?? '',
                            ':aut_rol'   => $autorRol,
                            ':aut_ag'    => $agenciaUsuario,
                            ':msg'       => $mensajeTxt,
                            ':adj'       => $archivoMsgUrl,
                            ':interna'   => $esNotaInterna,
                            ':creado_en' => $fechaMsgActual
                        ]);

                        // Actualizar estado si se seleccionó uno nuevo
                        if (!empty($cambioEstado) && in_array($cambioEstado, ['Abierto', 'En Proceso', 'Resuelto', 'Cerrado'])) {
                            $stmtUpdSt = $pdo->prepare("UPDATE tickets_soporte SET estado = :est, actualizado_en = :act WHERE id = :id");
                            $stmtUpdSt->execute([':est' => $cambioEstado, ':act' => $fechaMsgActual, ':id' => $ticketId]);
                        } else {
                            if ($autorRol === 'agente' && strtolower($tActual['estado']) === 'abierto') {
                                $stmtUpdSt = $pdo->prepare("UPDATE tickets_soporte SET estado = 'En Proceso', actualizado_en = :act WHERE id = :id");
                                $stmtUpdSt->execute([':act' => $fechaMsgActual, ':id' => $ticketId]);
                            } else {
                                $stmtUpdAct = $pdo->prepare("UPDATE tickets_soporte SET actualizado_en = :act WHERE id = :id");
                                $stmtUpdAct->execute([':act' => $fechaMsgActual, ':id' => $ticketId]);
                            }
                        }

                        // Auto-asignar al agente que contesta si no tenía asignado
                        if ($autorRol === 'agente' && empty($tActual['asignado_a'])) {
                            $stmtUpdAsig = $pdo->prepare("UPDATE tickets_soporte SET asignado_a = :ag WHERE id = :id");
                            $stmtUpdAsig->execute([':ag' => $nombreUsuario, ':id' => $ticketId]);
                        }

                        // Notificar a la agencia sucursal en tiempo real la contestación vía webhook
                        if (!empty($tActual['solicitante_agencia']) && !$esNotaInterna) {
                            try {
                                $agKey = strpos(strtolower($tActual['solicitante_agencia']), 'divol') !== false ? 'divolavilla' : '';
                                $targetUrl = 'https://portal.divolavilla.com/api_webhook_ticket.php';
                                if (!empty($CATALOGO_AGENCIAS[$agKey]['endpoint'])) {
                                    $targetUrl = str_replace(['/sistemas/api_obtener_datos.php', 'api_obtener_datos.php'], '/api_webhook_ticket.php', $CATALOGO_AGENCIAS[$agKey]['endpoint']);
                                }
                                $tokenWh = !empty($CATALOGO_AGENCIAS[$agKey]['token']) ? $CATALOGO_AGENCIAS[$agKey]['token'] : 'GH_SISTEMAS_TICKETS_2026!';
                                $postMsgWh = [
                                    'token'         => $tokenWh,
                                    'accion'        => 'nuevo_mensaje',
                                    'folio'         => $tActual['folio'],
                                    'ticket_id'     => $ticketId,
                                    'mensaje'       => $mensajeTxt,
                                    'autor_nombre'  => $nombreUsuario,
                                    'autor_usuario' => $autorUsuario,
                                    'autor_rol'     => $autorRol,
                                    'autor_agencia' => $agenciaUsuario,
                                    'estado'        => !empty($cambioEstado) ? $cambioEstado : ($tActual['estado'] ?? 'En Proceso'),
                                    'creado_en'     => $fechaMsgActual,
                                    'actualizado_en'=> $fechaMsgActual
                                ];
                                if ($archivoMsgUrl && file_exists(__DIR__ . '/' . $archivoMsgUrl)) {
                                    $postMsgWh['archivo_base64'] = base64_encode(file_get_contents(__DIR__ . '/' . $archivoMsgUrl));
                                    $postMsgWh['archivo_nombre'] = basename($archivoMsgUrl);
                                }
                                $chWhM = curl_init($targetUrl . '?token=' . urlencode($tokenWh));
                                curl_setopt_array($chWhM, [
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_POST           => true,
                                    CURLOPT_POSTFIELDS     => http_build_query($postMsgWh),
                                    CURLOPT_TIMEOUT        => 4,
                                    CURLOPT_SSL_VERIFYPEER => false,
                                    CURLOPT_HTTPHEADER     => [
                                        'Authorization: Bearer ' . $tokenWh,
                                        'Content-Type: application/x-www-form-urlencoded'
                                    ]
                                ]);
                                @curl_exec($chWhM);
                                @curl_close($chWhM);
                            } catch (Throwable $eWhM) {}
                        }

                        header("Location: tickets.php?ver=" . $ticketId . "&enviado=1");
                        exit();
                    }
                }
            } catch (Throwable $eMsg) {
                $error = "Error al enviar la contestación: " . $eMsg->getMessage();
            }
        } else {
            $error = "Por favor escribe un mensaje para la contestación.";
        }
    }

    // 3. Auto-asignarme ticket
    if ($accion === 'autoasignar_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        if ($ticketId > 0) {
            try {
                $stmtChk = $pdo->prepare("SELECT area_sistemas FROM tickets_soporte WHERE id = ?");
                $stmtChk->execute([$ticketId]);
                $areaTicket = strtoupper(trim($stmtChk->fetchColumn() ?: ''));

                if (!$accesoTodasAreas && !in_array($areaTicket, $areasAutorizadas)) {
                    $error = "Acceso denegado: No tienes autorización para tomar tickets del área '$areaTicket'.";
                } else {
                    $stmt = $pdo->prepare("UPDATE tickets_soporte SET asignado_a = :nombre, estado = CASE WHEN estado = 'Abierto' THEN 'En Proceso' ELSE estado END WHERE id = :id");
                    $stmt->execute([':nombre' => $nombreUsuario, ':id' => $ticketId]);
                    $mensaje = "Te has autoasignado el ticket #$ticketId. Estado actualizado a 'En Proceso'.";
                }
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

    // 5. Vaciar Todos los Tickets (Solo SuperAdmin)
    if ($accion === 'vaciar_todos_tickets' && $esAdmin) {
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
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
            $mensaje = "Se han eliminado todos los tickets y se ha reiniciado el contador de folios a 0.";
        } catch (Throwable $e) {
            $error = "Error al vaciar tickets: " . $e->getMessage();
        }
    }

    // Función auxiliar para enviar webhook de citas a la agencia
    if (!function_exists('enviarWebhookCitaAgencia')) {
        function enviarWebhookCitaAgencia($pdo, $agencia, $citaData, $accionWh = 'guardar_cita') {
            if (empty($agencia)) return;
            $targetUrl = 'https://portal.divolavilla.com/api_webhook_ticket.php';
            $tokenWh = 'GH_SISTEMAS_TICKETS_2026!';

            if ($pdo) {
                try {
                    $stmtAg = $pdo->prepare("SELECT subdominio, local_api_token FROM agencias WHERE nombre LIKE ? LIMIT 1");
                    $stmtAg->execute(['%' . trim($agencia) . '%']);
                    $agRow = $stmtAg->fetch(PDO::FETCH_ASSOC);
                    if ($agRow && !empty($agRow['subdominio'])) {
                        $sub = trim($agRow['subdominio']);
                        if (strpos($sub, 'http') !== 0) $sub = 'https://' . $sub;
                        $targetUrl = rtrim($sub, '/') . '/api_webhook_ticket.php';
                    }
                } catch (Throwable $eAg) {}
            }

            $payload = array_merge([
                'token'  => $tokenWh,
                'accion' => $accionWh
            ], $citaData);

            $chWh = curl_init($targetUrl . '?token=' . urlencode($tokenWh));
            curl_setopt_array($chWh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($payload),
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $tokenWh,
                    'Content-Type: application/x-www-form-urlencoded'
                ]
            ]);
            @curl_exec($chWh);
            @curl_close($chWh);
        }
    }

    // 6. Agendar Cita de Soporte Técnico (Con o Sin Ticket)
    if ($accion === 'agendar_cita') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $ticketFolio = trim($_POST['ticket_folio'] ?? '');
        $origenTicketId = intval($_POST['origen_ticket_id'] ?? 0);

        $agenciaCita = trim($_POST['agencia'] ?? '');
        $areaCita = trim($_POST['area_sistemas'] ?? 'INFRAESTRUCTURA');
        $tipoCita = trim($_POST['tipo_cita'] ?? 'Presencial');
        $asuntoCita = trim($_POST['asunto'] ?? '');
        $descCita = trim($_POST['descripcion'] ?? '');
        $solNomCita = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
        $solEmailCita = trim($_POST['solicitante_email'] ?? '');
        $tecnicoCita = trim($_POST['tecnico_asignado'] ?? $nombreUsuario);
        $fechaCita = trim($_POST['fecha_cita'] ?? '');
        $horaCita = trim($_POST['hora_cita'] ?? '09:00');
        $notasCita = trim($_POST['notas_atencion'] ?? '');

        // Validación: Agencia, Asunto y Fecha son los campos obligatorios
        if (empty($agenciaCita) || empty($asuntoCita) || empty($fechaCita)) {
            $error = "Por favor completa la Agencia, Asunto y Fecha de la cita.";
            if ($origenTicketId > 0) $_SESSION['redir_ticket_ver'] = $origenTicketId;
        } else {
            try {
                $tkAsoc = null;
                // Si viene un ticket indicado, verificar que exista
                if ($ticketId > 0 || !empty($ticketFolio)) {
                    $stmtTkCheck = $pdo->prepare("SELECT id, folio, titulo, solicitante_agencia, solicitante_nombre, solicitante_email, area_sistemas FROM tickets_soporte WHERE id = ? OR folio = ? LIMIT 1");
                    $stmtTkCheck->execute([$ticketId, $ticketFolio]);
                    $tkAsoc = $stmtTkCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$tkAsoc) {
                        $error = "El ticket seleccionado no existe en el sistema o es inválido.";
                        if ($origenTicketId > 0) $_SESSION['redir_ticket_ver'] = $origenTicketId;
                    } else {
                        $ticketId = $tkAsoc['id'];
                        $ticketFolio = $tkAsoc['folio'] ?: ('TK-' . $ticketId);
                    }
                } else {
                    $ticketId = null;
                    $ticketFolio = null;
                }

                if (empty($error)) {
                    $stmtCConteo = $pdo->query("SELECT COUNT(*) FROM citas_soporte");
                    $numC = ($stmtCConteo ? (int)$stmtCConteo->fetchColumn() : 0) + 1;
                    $folioCita = 'CTA-' . date('Y') . '-' . str_pad($numC, 4, '0', STR_PAD_LEFT);

                    $stmtInsC = $pdo->prepare("
                        INSERT INTO citas_soporte 
                        (folio, ticket_id, ticket_folio, agencia, area_sistemas, tipo_cita, asunto, descripcion, solicitante_nombre, solicitante_usuario, solicitante_email, tecnico_asignado, fecha_cita, hora_cita, estado, notas_atencion)
                        VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Programada', ?)
                    ");
                    $stmtInsC->execute([
                        $folioCita,
                        $ticketId,
                        $ticketFolio,
                        $agenciaCita,
                        $areaCita,
                        $tipoCita,
                        $asuntoCita,
                        $descCita,
                        $solNomCita,
                        $_SESSION['usuario_login'] ?? '',
                        $solEmailCita,
                        $tecnicoCita,
                        $fechaCita,
                        $horaCita,
                        $notasCita
                    ]);

                    // Registrar en la conversación del ticket si está vinculado
                    $avisoCita = "📅 Cita Técnica Programada ($folioCita):\nSe programó visita técnica para el " . date('d/m/Y', strtotime($fechaCita)) . " a las $horaCita hrs con el técnico $tecnicoCita.\nTipo: $tipoCita | Asunto: $asuntoCita" . (!empty($descCita) ? "\nDetalles: $descCita" : "");
                    if ($ticketId > 0) {
                        try {
                            $stmtMsgCita = $pdo->prepare("
                                INSERT INTO tickets_mensajes
                                (ticket_id, autor_usuario, autor_nombre, autor_rol, autor_agencia, mensaje, creado_en)
                                VALUES (?, ?, ?, 'agente', 'Dirección Sistemas', ?, ?)
                            ");
                            $stmtMsgCita->execute([$ticketId, $_SESSION['usuario_login'] ?? 'sistemas', $nombreUsuario, $avisoCita, date('Y-m-d H:i:s')]);
                        } catch (Throwable $eMsgC) {}
                    }

                    // Disparar Webhook push completo a la agencia para sincronización inmediata
                    try {
                        $citaPayloadWh = [
                            'folio_cita'            => $folioCita,
                            'ticket_id'             => $ticketId,
                            'ticket_folio'          => $ticketFolio,
                            'agencia'               => $agenciaCita,
                            'area_sistemas'         => $areaCita,
                            'tipo_cita'             => $tipoCita,
                            'asunto'                => $asuntoCita,
                            'descripcion'           => $descCita,
                            'solicitante_nombre'    => $solNomCita,
                            'solicitante_email'     => $solEmailCita,
                            'solicitante_usuario'   => $_SESSION['usuario_login'] ?? '',
                            'tecnico_asignado'      => $tecnicoCita,
                            'fecha_cita'            => $fechaCita,
                            'hora_cita'             => $horaCita,
                            'estado'                => 'Programada',
                            'notas_atencion'        => $notasCita,
                            'motivo_reprogramacion' => '',
                            'folio'                 => $ticketFolio ?: '',
                            'mensaje'               => $avisoCita,
                            'autor_nombre'          => $nombreUsuario,
                            'autor_rol'             => 'agente',
                            'autor_agencia'         => 'Dirección Sistemas',
                            'actualizado_en'        => date('Y-m-d H:i:s')
                        ];
                        enviarWebhookCitaAgencia($pdo, $agenciaCita, $citaPayloadWh, 'guardar_cita');
                    } catch (Throwable $eWhC) {}

                    if ($ticketId > 0) {
                        $mensaje = "Cita programada con éxito con folio <strong>$folioCita</strong> vinculada al ticket <strong>$ticketFolio</strong>.";
                        if ($origenTicketId > 0) {
                            $_SESSION['redir_ticket_ver'] = $origenTicketId;
                        } else {
                            $_SESSION['tab_activa'] = 'citas';
                        }
                    } else {
                        $mensaje = "Cita general programada con éxito con folio <strong>$folioCita</strong> para <strong>$agenciaCita</strong> (Sin ticket vinculado).";
                        $_SESSION['tab_activa'] = 'citas';
                    }
                }
            } catch (Throwable $eCita) {
                $error = "Error al agendar cita: " . $eCita->getMessage();
                if ($origenTicketId > 0) $_SESSION['redir_ticket_ver'] = $origenTicketId;
            }
        }
    }

    // 7. Actualizar Cita de Soporte
    if ($accion === 'actualizar_cita') {
        $citaId = intval($_POST['cita_id'] ?? 0);
        $origenTicketId = intval($_POST['origen_ticket_id'] ?? 0);
        $estadoCita = trim($_POST['estado'] ?? 'Programada');
        $tecnicoCita = trim($_POST['tecnico_asignado'] ?? '');
        $fechaCita = trim($_POST['fecha_cita'] ?? '');
        $horaCita = trim($_POST['hora_cita'] ?? '');
        $notasCita = trim($_POST['notas_atencion'] ?? '');
        $motivoCambio = trim($_POST['motivo_cambio'] ?? '');

        if ($citaId > 0) {
            try {
                $stmtPrev = $pdo->prepare("SELECT * FROM citas_soporte WHERE id = ?");
                $stmtPrev->execute([$citaId]);
                $citaPrev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

                if (!$citaPrev) {
                    $error = "La cita técnica no fue encontrada.";
                    if ($origenTicketId > 0) $_SESSION['redir_ticket_ver'] = $origenTicketId;
                } else {
                    $cambioFechaHora = (!empty($citaPrev['fecha_cita']) && ($fechaCita !== $citaPrev['fecha_cita'] || $horaCita !== $citaPrev['hora_cita']));

                    if ($cambioFechaHora && empty($motivoCambio)) {
                        $error = "Al cambiar la fecha u hora de la cita, es obligatorio especificar el motivo o justificación del cambio.";
                        if ($origenTicketId > 0 || !empty($citaPrev['ticket_id'])) {
                            $_SESSION['redir_ticket_ver'] = $origenTicketId ?: $citaPrev['ticket_id'];
                        }
                    } else {
                        $historialActual = $citaPrev['historial_cambios'] ?? '';
                        $motivoGuardar = $citaPrev['motivo_reprogramacion'] ?? '';

                        if ($cambioFechaHora) {
                            $motivoGuardar = $motivoCambio;
                            $lineaHistorial = "[" . date('d/m/Y H:i') . " - $nombreUsuario] Reprogramación de (" . $citaPrev['fecha_cita'] . " " . $citaPrev['hora_cita'] . ") a ($fechaCita $horaCita). Motivo: $motivoCambio";
                            $historialActual = empty($historialActual) ? $lineaHistorial : ($lineaHistorial . "\n" . $historialActual);
                        }

                        $stmtUpC = $pdo->prepare("
                            UPDATE citas_soporte 
                            SET estado = ?, tecnico_asignado = ?, fecha_cita = ?, hora_cita = ?, notas_atencion = ?, motivo_reprogramacion = ?, historial_cambios = ?
                            WHERE id = ?
                        ");
                        $stmtUpC->execute([$estadoCita, $tecnicoCita, $fechaCita, $horaCita, $notasCita, $motivoGuardar, $historialActual, $citaId]);
                        $mensaje = "Cita ID #$citaId actualizada correctamente.";

                        $folioCitaTxt = $citaPrev['folio'] ?: ('CTA-' . $citaId);
                        $avisoReprog = "📅 Reprogramación de Cita ($folioCitaTxt):\nLa visita técnica fue reprogramada de " . date('d/m/Y', strtotime($citaPrev['fecha_cita'])) . " " . $citaPrev['hora_cita'] . " hrs a " . date('d/m/Y', strtotime($fechaCita)) . " " . $horaCita . " hrs.\nMotivo del cambio: $motivoCambio\n(Registrado por $nombreUsuario)";

                        // Si cambió fecha/hora y tiene ticket asociado, insertar mensaje en ticket
                        if ($cambioFechaHora && (!empty($citaPrev['ticket_id']) || !empty($citaPrev['ticket_folio']))) {
                            $tkCId = $citaPrev['ticket_id'] ?: 0;
                            try {
                                $stmtMsgRep = $pdo->prepare("
                                    INSERT INTO tickets_mensajes
                                    (ticket_id, autor_usuario, autor_nombre, autor_rol, autor_agencia, mensaje, creado_en)
                                    VALUES (?, ?, ?, 'agente', 'Dirección Sistemas', ?, ?)
                                ");
                                $stmtMsgRep->execute([$tkCId, $_SESSION['usuario_login'] ?? 'sistemas', $nombreUsuario, $avisoReprog, date('Y-m-d H:i:s')]);
                            } catch (Throwable $eMsgR) {}
                        }

                        // Sincronizar actualización completa de cita con la agencia
                        try {
                            if (!empty($citaPrev['agencia'])) {
                                $citaPayloadWh = [
                                    'folio_cita'            => $folioCitaTxt,
                                    'ticket_id'             => $citaPrev['ticket_id'],
                                    'ticket_folio'          => $citaPrev['ticket_folio'],
                                    'agencia'               => $citaPrev['agencia'],
                                    'area_sistemas'         => $citaPrev['area_sistemas'] ?? 'INFRAESTRUCTURA',
                                    'tipo_cita'             => $citaPrev['tipo_cita'] ?? 'Presencial',
                                    'asunto'                => $citaPrev['asunto'] ?? '',
                                    'descripcion'           => $citaPrev['descripcion'] ?? '',
                                    'solicitante_nombre'    => $citaPrev['solicitante_nombre'] ?? '',
                                    'solicitante_email'     => $citaPrev['solicitante_email'] ?? '',
                                    'solicitante_usuario'   => $citaPrev['solicitante_usuario'] ?? '',
                                    'tecnico_asignado'      => $tecnicoCita,
                                    'fecha_cita'            => $fechaCita,
                                    'hora_cita'             => $horaCita,
                                    'estado'                => $estadoCita,
                                    'notas_atencion'        => $notasCita,
                                    'motivo_reprogramacion' => $motivoGuardar,
                                    'historial_cambios'     => $historialActual,
                                    'folio'                 => $citaPrev['ticket_folio'] ?: '',
                                    'mensaje'               => $avisoReprog,
                                    'autor_nombre'          => $nombreUsuario,
                                    'autor_rol'             => 'agente',
                                    'autor_agencia'         => 'Dirección Sistemas',
                                    'actualizado_en'        => date('Y-m-d H:i:s')
                                ];
                                enviarWebhookCitaAgencia($pdo, $citaPrev['agencia'], $citaPayloadWh, 'guardar_cita');
                            }
                        } catch (Throwable $eWhR) {}

                        if ($origenTicketId > 0 || !empty($citaPrev['ticket_id'])) {
                            $_SESSION['redir_ticket_ver'] = $origenTicketId ?: $citaPrev['ticket_id'];
                        } else {
                            $_SESSION['tab_activa'] = 'citas';
                        }
                    }
                }
            } catch (Throwable $eUpC) {
                $error = "Error al actualizar cita: " . $eUpC->getMessage();
                if ($origenTicketId > 0) $_SESSION['redir_ticket_ver'] = $origenTicketId;
            }
        }
    }

    // 8. Eliminar Cita
    if ($accion === 'eliminar_cita' && $esAdmin) {
        $citaId = intval($_POST['cita_id'] ?? 0);
        $origenTicketId = intval($_POST['origen_ticket_id'] ?? 0);
        if ($citaId > 0) {
            try {
                $stmtPrevDel = $pdo->prepare("SELECT * FROM citas_soporte WHERE id = ?");
                $stmtPrevDel->execute([$citaId]);
                $citaDel = $stmtPrevDel->fetch(PDO::FETCH_ASSOC);

                $stmtDelC = $pdo->prepare("DELETE FROM citas_soporte WHERE id = ?");
                $stmtDelC->execute([$citaId]);
                $mensaje = "Cita ID #$citaId eliminada correctamente.";

                if ($citaDel && !empty($citaDel['agencia'])) {
                    try {
                        enviarWebhookCitaAgencia($pdo, $citaDel['agencia'], [
                            'folio_cita' => $citaDel['folio'] ?: ('CTA-' . $citaId)
                        ], 'eliminar_cita');
                    } catch (Throwable $eWhDel) {}
                }

                if ($origenTicketId > 0) {
                    $_SESSION['redir_ticket_ver'] = $origenTicketId;
                } else {
                    $_SESSION['tab_activa'] = 'citas';
                }
            } catch (Throwable $eDelC) {
                $error = "Error al eliminar cita: " . $eDelC->getMessage();
            }
        }
    }

    // 9. Actualizar Fotografía y Ficha de Agente (Exclusivo SuperAdmin)
    if ($accion === 'superadmin_actualizar_foto_agente') {
        if (!$esSuperAdmin) {
            $error = "Acceso denegado: Solo el rol SuperAdmin puede actualizar la fotografía y datos del agente.";
        } else {
            $agenteId = intval($_POST['agente_id'] ?? 0);
            $agenteNombre = trim($_POST['agente_nombre'] ?? '');
            $nuevoCargo = trim($_POST['cargo'] ?? '');
            $nuevoTelefono = trim($_POST['telefono'] ?? '');
            $origenTicketId = intval($_POST['ticket_id'] ?? ($_POST['origen_ticket_id'] ?? 0));

            // Si no se proporcionó agente_id, buscar por nombre/usuario
            if ($agenteId <= 0 && !empty($agenteNombre)) {
                $uDat = obtenerDatosAgente($pdo, $agenteNombre);
                if ($uDat) {
                    $agenteId = (int)$uDat['id'];
                }
            }

            if ($agenteId > 0) {
                try {
                    asegurarColumnasUsuarios($pdo);
                    $fotoUrl = null;
                    if (isset($_FILES['foto_agente']) && $_FILES['foto_agente']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['foto_agente']['tmp_name'];
                        $fileName = $_FILES['foto_agente']['name'];
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                        if (in_array($fileExt, $extPermitidas)) {
                            $dirUpload = __DIR__ . '/uploads/usuarios/';
                            if (!is_dir($dirUpload)) {
                                @mkdir($dirUpload, 0777, true);
                            }
                            $nuevoNombre = 'agente_' . $agenteId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $fileExt;
                            $destino = $dirUpload . $nuevoNombre;
                            if (move_uploaded_file($fileTmp, $destino)) {
                                $fotoUrl = 'uploads/usuarios/' . $nuevoNombre;
                            }
                        } else {
                            $error = "Formato de imagen inválido. Solo se admiten archivos JPG, PNG, WEBP o GIF.";
                        }
                    }

                    if (empty($error)) {
                        if ($fotoUrl) {
                            $stmtUpU = $pdo->prepare("UPDATE usuarios SET foto = :foto, cargo = COALESCE(NULLIF(:cargo, ''), cargo), telefono = COALESCE(NULLIF(:tel, ''), telefono) WHERE id = :id");
                            $stmtUpU->execute([':foto' => $fotoUrl, ':cargo' => $nuevoCargo, ':tel' => $nuevoTelefono, ':id' => $agenteId]);
                        } else {
                            $stmtUpU = $pdo->prepare("UPDATE usuarios SET cargo = COALESCE(NULLIF(:cargo, ''), cargo), telefono = COALESCE(NULLIF(:tel, ''), telefono) WHERE id = :id");
                            $stmtUpU->execute([':cargo' => $nuevoCargo, ':tel' => $nuevoTelefono, ':id' => $agenteId]);
                        }
                        $mensaje = "Fotografía y datos del especialista actualizados correctamente.";
                    }
                } catch (Throwable $eFoto) {
                    $error = "Error al actualizar agente: " . $eFoto->getMessage();
                }
            } else {
                $error = "No se localizó al usuario en la base de datos para asociarle la fotografía.";
            }

            if ($origenTicketId > 0) {
                $_SESSION['redir_ticket_ver'] = $origenTicketId;
            } else {
                $_SESSION['tab_activa'] = 'agentes';
            }
        }
    }

    // 10. Eliminar Fotografía del Agente (Exclusivo SuperAdmin)
    if ($accion === 'superadmin_eliminar_foto_agente') {
        if (!$esSuperAdmin) {
            $error = "Acceso denegado: Solo el rol SuperAdmin puede gestionar la fotografía del agente.";
        } else {
            $agenteId = intval($_POST['agente_id'] ?? 0);
            $agenteNombre = trim($_POST['agente_nombre'] ?? '');
            $origenTicketId = intval($_POST['ticket_id'] ?? ($_POST['origen_ticket_id'] ?? 0));

            if ($agenteId <= 0 && !empty($agenteNombre)) {
                $uDat = obtenerDatosAgente($pdo, $agenteNombre);
                if ($uDat) {
                    $agenteId = (int)$uDat['id'];
                }
            }

            if ($agenteId > 0) {
                try {
                    $stmtDelF = $pdo->prepare("UPDATE usuarios SET foto = NULL WHERE id = ?");
                    $stmtDelF->execute([$agenteId]);
                    $mensaje = "Fotografía del agente eliminada correctamente.";
                } catch (Throwable $eDelF) {
                    $error = "Error al quitar fotografía: " . $eDelF->getMessage();
                }
            }

            if ($origenTicketId > 0) {
                $_SESSION['redir_ticket_ver'] = $origenTicketId;
            } else {
                $_SESSION['tab_activa'] = 'agentes';
            }
        }
    }

    // Patrón Post/Redirect/Get: Previene duplicados al recargar con F5
    $_SESSION['flash_mensaje'] = $mensaje;
    $_SESSION['flash_error'] = $error;
    $redirTicketVer = $_SESSION['redir_ticket_ver'] ?? null;
    unset($_SESSION['redir_ticket_ver']);
    $tabRedir = $_SESSION['tab_activa'] ?? '';
    unset($_SESSION['tab_activa']);

    if (!empty($redirTicketVer)) {
        $urlDestino = "tickets.php?ver=" . intval($redirTicketVer);
    } else {
        $urlDestino = "tickets.php" . (!empty($tabRedir) ? "?tab=" . urlencode($tabRedir) : "");
    }
    header("Location: $urlDestino");
    exit();
}

// ====================================================
// EXPORTACIÓN A EXCEL CORPORATIVO
// ====================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel' && $pdo) {
    $agenciaFiltro = trim($_GET['agencia'] ?? '');
    $whereParts = [];
    $whereParams = [];

    if (!empty($agenciaFiltro) && $agenciaFiltro !== 'TODAS') {
        $whereParts[] = "LOWER(solicitante_agencia) LIKE ?";
        $whereParams[] = '%' . strtolower($agenciaFiltro) . '%';
        $fileName = 'Reporte_Tickets_' . preg_replace('/[^A-Za-z0-9]/', '_', $agenciaFiltro) . '_' . date('Ymd_His') . '.xls';
        $agenciaInfo = ['nombre' => $agenciaFiltro . ' - Grupo Huerta'];
    } else {
        $fileName = 'Reporte_Tickets_Helpdesk_TI_' . date('Ymd_His') . '.xls';
        $agenciaInfo = ['nombre' => 'Dirección Central de Sistemas - Grupo Huerta'];
    }

    if (!$accesoTodasAreas) {
        if (empty($areasAutorizadas)) {
            $whereParts[] = "1 = 0";
        } else {
            $inQ = implode(',', array_fill(0, count($areasAutorizadas), '?'));
            $whereParts[] = "UPPER(area_sistemas) IN ($inQ)";
            foreach ($areasAutorizadas as $ar) {
                $whereParams[] = $ar;
            }
        }
    }

    $sqlExp = "SELECT * FROM tickets_soporte";
    if (!empty($whereParts)) {
        $sqlExp .= " WHERE " . implode(" AND ", $whereParts);
    }
    $sqlExp .= " ORDER BY id DESC";

    $stmtExp = $pdo->prepare($sqlExp);
    $stmtExp->execute($whereParams);
    $registros = $stmtExp ? $stmtExp->fetchAll(PDO::FETCH_ASSOC) : [];

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
$agenciasList = [];
$ticketsSinVer = [];

// 1. Inicializar agencias del catálogo oficial y personalizadas
if (!empty($CATALOGO_AGENCIAS)) {
    foreach ($CATALOGO_AGENCIAS as $k => $ag) {
        $nomNorm = normalizarAgencia($ag['nombre'] ?? '');
        $conteoPorAgencia[$nomNorm] = 0;
        $agenciasList[$nomNorm] = [
            'id'          => $ag['id'] ?? $k,
            'nombre'      => $nomNorm,
            'cpanel'      => $ag['cpanel'] ?? ($ag['subdominio'] ?? 'cPanel Conectado'),
            'subdominio'  => $ag['subdominio'] ?? '',
            'icono'       => $ag['icono'] ?? 'bi-building-fill-check',
            'color'       => $ag['color'] ?? '#2563eb',
            'descripcion' => $ag['descripcion'] ?? 'Agencia cPanel Conectada',
            'total'       => 0,
            'abiertos'    => 0,
            'en_proceso'  => 0,
            'resueltos'   => 0,
            'ultimo_ticket' => null
        ];
    }
}

// 2. Garantizar presencia de Oficina Central Grupo Huerta
if (!isset($agenciasList['Oficina Central Grupo Huerta'])) {
    $conteoPorAgencia['Oficina Central Grupo Huerta'] = 0;
    $agenciasList['Oficina Central Grupo Huerta'] = [
        'id'          => 'oficina_central',
        'nombre'      => 'Oficina Central Grupo Huerta',
        'cpanel'      => 'portal.grupohuerta.mx',
        'subdominio'  => 'portal.grupohuerta.mx',
        'icono'       => 'bi-buildings-fill',
        'color'       => '#10b981',
        'descripcion' => 'Dirección Central de Sistemas y TI',
        'total'       => 0,
        'abiertos'    => 0,
        'en_proceso'  => 0,
        'resueltos'   => 0,
        'ultimo_ticket' => null
    ];
}

if ($pdo) {
    try {
        if ($accesoTodasAreas) {
            $stmtAll = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
            $tickets = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            if (empty($areasAutorizadas)) {
                $tickets = [];
            } else {
                $inQ = implode(',', array_fill(0, count($areasAutorizadas), '?'));
                $stmtAll = $pdo->prepare("SELECT * FROM tickets_soporte WHERE UPPER(area_sistemas) IN ($inQ) ORDER BY id DESC");
                $stmtAll->execute($areasAutorizadas);
                $tickets = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];
            }
        }

        $totalTickets = count($tickets);
        foreach ($tickets as $t) {
            $estLower = strtolower(trim($t['estado'] ?? ''));
            if ($estLower === 'abierto') {
                $totalAbiertos++;
                $ticketsSinVer[] = $t;
            } elseif ($estLower === 'en proceso') {
                $totalEnProceso++;
            } elseif ($estLower === 'resuelto' || $estLower === 'cerrado') {
                $totalResueltos++;
            }

            if (empty(trim($t['asignado_a'] ?? ''))) {
                $totalSinAsignar++;
            }

            $agNom = normalizarAgencia($t['solicitante_agencia'] ?? 'Oficina Central Grupo Huerta');
            if (!isset($agenciasList[$agNom])) {
                $conteoPorAgencia[$agNom] = 0;
                $agenciasList[$agNom] = [
                    'id'          => preg_replace('/[^a-z0-9]/', '_', strtolower($agNom)),
                    'nombre'      => $agNom,
                    'cpanel'      => 'Sucursal Registrada',
                    'subdominio'  => '',
                    'icono'       => 'bi-building',
                    'color'       => '#6366f1',
                    'descripcion' => 'Sucursal Grupo Huerta',
                    'total'       => 0,
                    'abiertos'    => 0,
                    'en_proceso'  => 0,
                    'resueltos'   => 0,
                    'ultimo_ticket' => null
                ];
            }

            $agenciasList[$agNom]['total']++;
            if ($estLower === 'abierto') {
                $agenciasList[$agNom]['abiertos']++;
            } elseif ($estLower === 'en proceso') {
                $agenciasList[$agNom]['en_proceso']++;
            } elseif ($estLower === 'resuelto' || $estLower === 'cerrado') {
                $agenciasList[$agNom]['resueltos']++;
            }

            if ($agenciasList[$agNom]['ultimo_ticket'] === null) {
                $agenciasList[$agNom]['ultimo_ticket'] = $t;
            }

            if ($estLower !== 'resuelto' && $estLower !== 'cerrado') {
                $conteoPorAgencia[$agNom]++;
            }
        }
    } catch (Throwable $e) {}
}

// ====================================================
// CONSULTA DE CITAS DE SOPORTE TÉCNICO
// ====================================================
$citas = [];
$totalCitas = 0;
$totalCitasProgramadas = 0;
$totalCitasEnCurso = 0;
$totalCitasRealizadas = 0;

if ($pdo) {
    try {
        $stmtC = $pdo->query("SELECT * FROM citas_soporte ORDER BY fecha_cita ASC, hora_cita ASC");
        $citas = $stmtC ? $stmtC->fetchAll(PDO::FETCH_ASSOC) : [];
        $totalCitas = count($citas);
        foreach ($citas as $c) {
            $estC = strtolower(trim($c['estado'] ?? 'programada'));
            if ($estC === 'programada') $totalCitasProgramadas++;
            elseif ($estC === 'en curso' || $estC === 'en atencion') $totalCitasEnCurso++;
            elseif ($estC === 'realizada' || $estC === 'completada') $totalCitasRealizadas++;
        }
    } catch (Throwable $eC) {}
}

// ====================================================
// CÁLCULO DE MÉTRICAS Y ESTADÍSTICAS DEL SISTEMA
// ====================================================
$conteoPorArea = [];
foreach ($AREAS_SISTEMAS as $k => $v) {
    $conteoPorArea[$k] = 0;
}
$conteoPorEstado = ['Abierto' => 0, 'En Proceso' => 0, 'Resuelto' => 0, 'Cerrado' => 0];
$conteoPorPrioridad = ['Baja' => 0, 'Media' => 0, 'Alta' => 0, 'Urgente' => 0];

foreach ($tickets as $t) {
    $a = strtoupper(trim($t['area_sistemas'] ?? ''));
    if (isset($conteoPorArea[$a])) {
        $conteoPorArea[$a]++;
    }

    $e = strtolower(trim($t['estado'] ?? 'abierto'));
    if ($e === 'abierto') $conteoPorEstado['Abierto']++;
    elseif ($e === 'en proceso') $conteoPorEstado['En Proceso']++;
    elseif ($e === 'resuelto') $conteoPorEstado['Resuelto']++;
    elseif ($e === 'cerrado') $conteoPorEstado['Cerrado']++;
    else $conteoPorEstado['Abierto']++;

    $p = ucfirst(strtolower(trim($t['prioridad'] ?? 'Media')));
    if (isset($conteoPorPrioridad[$p])) {
        $conteoPorPrioridad[$p]++;
    }
}

$tasaResolucion = $totalTickets > 0 ? round((($conteoPorEstado['Resuelto'] + $conteoPorEstado['Cerrado']) / $totalTickets) * 100, 1) : 0;

// ====================================================
// CARGA DE TICKET ESPECÍFICO PARA VISTA COMPLETA (NO FLOTANTE)
// ====================================================
$ticketVerParam = trim($_GET['ver'] ?? ($_GET['ticket_id'] ?? ''));
$ticketActivo = null;
$mensajesTicketActivo = [];
$citaTicketActivo = null;

if (!empty($ticketVerParam) && $pdo) {
    try {
        if (is_numeric($ticketVerParam)) {
            $stmtTkVer = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
            $stmtTkVer->execute([intval($ticketVerParam)]);
        } else {
            $stmtTkVer = $pdo->prepare("SELECT * FROM tickets_soporte WHERE folio = ? OR id = ?");
            $stmtTkVer->execute([$ticketVerParam, intval($ticketVerParam)]);
        }
        $ticketActivo = $stmtTkVer ? $stmtTkVer->fetch(PDO::FETCH_ASSOC) : null;

        if ($ticketActivo) {
            $stmtMsgTk = $pdo->prepare("SELECT * FROM tickets_mensajes WHERE ticket_id = ? ORDER BY id ASC");
            $stmtMsgTk->execute([$ticketActivo['id']]);
            $mensajesTicketActivo = $stmtMsgTk ? $stmtMsgTk->fetchAll(PDO::FETCH_ASSOC) : [];

            // Cargar cita vinculada al ticket
            try {
                $stmtCitaTk = $pdo->prepare("SELECT * FROM citas_soporte WHERE ticket_id = ? OR ticket_folio = ? ORDER BY id DESC LIMIT 1");
                $stmtCitaTk->execute([$ticketActivo['id'], $ticketActivo['folio'] ?? '']);
                $citaTicketActivo = $stmtCitaTk ? $stmtCitaTk->fetch(PDO::FETCH_ASSOC) : null;
            } catch (Throwable $eTkCita) {
                $citaTicketActivo = null;
            }

            // Cargar datos y estadísticas del agente si es SuperAdmin
            $datosAgenteTicket = null;
            $statsAgenteTicket = null;
            if ($esSuperAdmin && !empty($ticketActivo['asignado_a'])) {
                $datosAgenteTicket = obtenerDatosAgente($pdo, $ticketActivo['asignado_a']);
                $statsAgenteTicket = obtenerEstadisticasAgente($pdo, $ticketActivo['asignado_a'], $datosAgenteTicket['usuario'] ?? null);
            }
        }
    } catch (Throwable $eVer) {}
}

// Carga de lista de agentes con acceso y áreas de atención (Exclusivo SuperAdmin)
$agentesTickets = ($esSuperAdmin && $pdo) ? obtenerAgentesConAccesoTickets($pdo) : [];
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
    <!-- Chart.js para Estadísticas -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #061325;
            background-image: radial-gradient(#0e2440 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        /* Estructura de Layout con Menú Lateral */
        .app-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .app-sidebar {
            width: 260px;
            background: #061528;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 1030;
            transition: margin-left 0.3s cubic-bezier(0.2, 0, 0, 1), transform 0.3s cubic-bezier(0.2, 0, 0, 1);
        }
        .app-sidebar.collapsed {
            margin-left: -260px;
        }

        .sidebar-brand {
            padding: 18px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar-brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(14, 165, 233, 0.4);
            color: #38bdf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .sidebar-menu {
            padding: 16px 12px;
            flex-grow: 1;
            overflow-y: auto;
        }

        .sidebar-group-title {
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: #64748b;
            padding: 12px 14px 6px;
        }

        .sidebar-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 16px;
            border-radius: 12px;
            border: 1px solid transparent;
            background: transparent;
            color: #94a3b8;
            font-size: 0.92rem;
            font-weight: 600;
            text-align: left;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            margin-bottom: 5px;
        }

        .sidebar-btn:hover {
            background: rgba(56, 189, 248, 0.1);
            color: #38bdf8;
            border-color: rgba(56, 189, 248, 0.25);
            transform: translateX(3px);
        }

        .sidebar-btn.active {
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.35), rgba(14, 165, 233, 0.18));
            border-color: rgba(56, 189, 248, 0.5);
            color: #38bdf8;
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.25);
        }

        .sidebar-btn i {
            font-size: 1.2rem;
            line-height: 1;
        }

        .sidebar-footer {
            padding: 15px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.25);
        }

        .app-content {
            flex-grow: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            padding-bottom: 60px;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 1025;
        }

        @media (max-width: 991px) {
            .app-sidebar {
                position: fixed;
                left: -280px;
                top: 0;
                bottom: 0;
                z-index: 1050;
                margin-left: 0 !important;
            }
            .app-sidebar.show {
                left: 0;
            }
            .sidebar-backdrop.show {
                display: block;
            }
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
            width: 100%;
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

        /* Hub de Agencias (Diseño Formal Futurista) */
        .agency-ticket-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(390px, 1fr));
            gap: 24px;
        }
        .agency-ticket-card {
            background: linear-gradient(145deg, rgba(13, 27, 48, 0.94) 0%, rgba(8, 18, 36, 0.98) 100%);
            border: 1px solid rgba(56, 189, 248, 0.2);
            border-radius: 22px;
            padding: 24px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .agency-ticket-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #38bdf8, #818cf8, #a855f7);
            opacity: 0.6;
            transition: opacity 0.3s ease;
        }
        .agency-ticket-card:hover {
            transform: translateY(-6px);
            border-color: rgba(56, 189, 248, 0.6);
            background: linear-gradient(145deg, rgba(16, 36, 64, 0.96) 0%, rgba(10, 24, 48, 0.98) 100%);
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.65), 0 0 28px rgba(56, 189, 248, 0.22);
        }
        .agency-ticket-card:hover::before {
            opacity: 1;
        }
        .agency-ticket-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.55rem;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.15), rgba(99, 102, 241, 0.2));
            border: 1px solid rgba(56, 189, 248, 0.3);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.35);
        }
        .agency-stat-box {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.85) 0%, rgba(8, 15, 28, 0.95) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 12px 8px;
            text-align: center;
            transition: all 0.25s ease;
        }
        .agency-stat-box.stat-totales {
            border-color: rgba(255, 255, 255, 0.1);
        }
        .agency-stat-box.stat-sin-atender {
            background: linear-gradient(180deg, rgba(245, 158, 11, 0.12) 0%, rgba(30, 20, 5, 0.85) 100%);
            border-color: rgba(245, 158, 11, 0.25);
        }
        .agency-stat-box.highlight-open {
            border-color: rgba(245, 158, 11, 0.65) !important;
            background: linear-gradient(180deg, rgba(245, 158, 11, 0.22) 0%, rgba(50, 30, 5, 0.95) 100%) !important;
            box-shadow: 0 0 16px rgba(245, 158, 11, 0.3);
            animation: pulseGlow 2.5s infinite;
        }
        .agency-stat-box.stat-en-proceso {
            background: linear-gradient(180deg, rgba(14, 165, 233, 0.12) 0%, rgba(5, 25, 45, 0.85) 100%);
            border-color: rgba(14, 165, 233, 0.25);
        }
        .agency-stat-box.stat-resueltos {
            background: linear-gradient(180deg, rgba(16, 185, 129, 0.12) 0%, rgba(5, 35, 25, 0.85) 100%);
            border-color: rgba(16, 185, 129, 0.25);
        }
        .agency-ticket-card:hover .agency-stat-box {
            transform: translateY(-2px);
        }
        .stat-number {
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.5px;
        }
        .stat-label {
            font-size: 0.65rem;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.8px;
            margin-top: 5px;
            text-transform: uppercase;
        }
        .btn-futuristic-view {
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.9) 0%, rgba(37, 99, 235, 0.95) 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.8rem;
            padding: 6px 16px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
            transition: all 0.2s ease;
            text-decoration: none;
            border: 1px solid rgba(56, 189, 248, 0.4);
        }
        .agency-ticket-card:hover .btn-futuristic-view {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            box-shadow: 0 6px 18px rgba(2, 132, 199, 0.6);
            transform: scale(1.04);
        }
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 10px rgba(245, 158, 11, 0.25); }
            50% { box-shadow: 0 0 20px rgba(245, 158, 11, 0.55); }
        }

        /* ==================================================== */
        /* GALERÍA 3D COVERFLOW (INSPIRADA EN LA REFERENCIA)   */
        /* ==================================================== */
        .coverflow-3d-wrapper {
            position: relative;
            width: 100%;
            padding: 20px 0 50px;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow: visible;
        }

        .coverflow-3d-stage {
            position: relative;
            width: 100%;
            max-width: 1100px;
            height: 480px;
            perspective: 1200px;
            transform-style: preserve-3d;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto;
        }

        .coverflow-card {
            position: absolute;
            width: 350px;
            height: 460px;
            border-radius: 24px;
            background: linear-gradient(165deg, rgba(13, 27, 48, 0.96) 0%, rgba(8, 16, 32, 0.98) 100%);
            border: 1px solid rgba(56, 189, 248, 0.25);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.75);
            transition: transform 0.55s cubic-bezier(0.25, 1, 0.5, 1),
                        opacity 0.55s ease,
                        filter 0.55s ease,
                        box-shadow 0.55s ease,
                        border-color 0.55s ease;
            cursor: pointer;
            user-select: none;
            padding: 24px 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            -webkit-box-reflect: below 10px linear-gradient(to bottom, transparent 65%, rgba(0, 0, 0, 0.4) 100%);
            overflow: hidden;
        }

        .coverflow-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #38bdf8, #818cf8, #c084fc, #38bdf8);
            opacity: 0.8;
        }

        /* Posiciones en el espacio 3D */
        .coverflow-card.pos-center {
            transform: translateX(0) translateZ(0) rotateY(0deg) scale(1);
            z-index: 10;
            opacity: 1;
            filter: blur(0);
            border-color: rgba(56, 189, 248, 0.65);
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.85), 0 0 40px rgba(56, 189, 248, 0.25);
            pointer-events: auto;
        }

        .coverflow-card.pos-left {
            transform: translateX(-280px) translateZ(-160px) rotateY(38deg) scale(0.85);
            z-index: 5;
            opacity: 0.6;
            filter: brightness(0.65) blur(0.5px);
            pointer-events: auto;
        }
        .coverflow-card.pos-left:hover {
            opacity: 0.85;
            filter: brightness(0.85);
            transform: translateX(-280px) translateZ(-130px) rotateY(34deg) scale(0.88);
        }

        .coverflow-card.pos-right {
            transform: translateX(280px) translateZ(-160px) rotateY(-38deg) scale(0.85);
            z-index: 5;
            opacity: 0.6;
            filter: brightness(0.65) blur(0.5px);
            pointer-events: auto;
        }
        .coverflow-card.pos-right:hover {
            opacity: 0.85;
            filter: brightness(0.85);
            transform: translateX(280px) translateZ(-130px) rotateY(-34deg) scale(0.88);
        }

        .coverflow-card.pos-hidden-left {
            transform: translateX(-520px) translateZ(-320px) rotateY(55deg) scale(0.65);
            z-index: 1;
            opacity: 0;
            pointer-events: none;
        }

        .coverflow-card.pos-hidden-right {
            transform: translateX(520px) translateZ(-320px) rotateY(-55deg) scale(0.65);
            z-index: 1;
            opacity: 0;
            pointer-events: none;
        }

        /* Controles circulares de navegación (como en la imagen de referencia) */
        .coverflow-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 22px;
            margin-top: 30px;
            z-index: 25;
            position: relative;
        }

        .coverflow-btn-circle {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: rgba(13, 27, 48, 0.7);
            border: 2px solid rgba(255, 255, 255, 0.4);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            cursor: pointer;
            transition: all 0.25s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        }

        .coverflow-btn-circle:hover {
            background: #0284c7;
            border-color: #38bdf8;
            color: #ffffff;
            transform: scale(1.12);
            box-shadow: 0 0 25px rgba(56, 189, 248, 0.6);
        }

        .coverflow-btn-circle:active {
            transform: scale(0.94);
        }

        /* Indicador de agencia y selector de puntos */
        .coverflow-dots {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 15px;
        }
        .coverflow-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transition: all 0.25s ease;
            cursor: pointer;
        }
        .coverflow-dot.active {
            width: 28px;
            border-radius: 12px;
            background: #38bdf8;
            box-shadow: 0 0 12px #38bdf8;
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
        /* ==================================================== */
        /* VISTA CALENDARIO MENSUAL Y SELECTOR DE CITAS         */
        /* ==================================================== */
        .citas-view-switcher {
            background: rgba(13, 27, 48, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 4px;
            border-radius: 30px;
            display: inline-flex;
            gap: 4px;
        }
        .citas-view-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .citas-view-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }
        .citas-view-btn.active {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);
        }

        .calendar-card {
            background: #091a32;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.45);
        }
        .calendar-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            padding-bottom: 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .calendar-nav-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .calendar-nav-btn:hover {
            background: #0284c7;
            border-color: #38bdf8;
            transform: scale(1.05);
        }
        .calendar-month-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
            text-transform: capitalize;
            min-width: 200px;
            text-align: center;
        }
        .calendar-weekdays-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 10px;
            text-align: center;
        }
        .calendar-weekday {
            padding: 8px 4px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
        }
        .calendar-grid-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }
        .calendar-cell {
            background: rgba(13, 27, 48, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            min-height: 125px;
            padding: 8px;
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease;
            position: relative;
        }
        .calendar-cell:hover {
            border-color: rgba(56, 189, 248, 0.4);
            background: rgba(15, 33, 60, 0.85);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
        }
        .calendar-cell.other-month {
            opacity: 0.32;
            background: rgba(6, 15, 28, 0.3);
        }
        .calendar-cell.today {
            border-color: rgba(56, 189, 248, 0.8);
            background: rgba(14, 165, 233, 0.08);
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.15) inset;
        }
        .calendar-cell-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .calendar-cell-num {
            font-size: 0.88rem;
            font-weight: 700;
            color: #cbd5e1;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .calendar-cell.today .calendar-cell-num {
            background: #0284c7;
            color: #ffffff;
            font-weight: 800;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.7);
        }
        .calendar-btn-quick-add {
            opacity: 0;
            background: rgba(255, 255, 255, 0.08);
            border: none;
            color: #38bdf8;
            width: 22px;
            height: 22px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .calendar-cell:hover .calendar-btn-quick-add {
            opacity: 1;
        }
        .calendar-btn-quick-add:hover {
            background: #0284c7;
            color: #ffffff;
        }
        .calendar-events-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
            overflow-y: auto;
            max-height: 95px;
            padding-right: 2px;
        }
        .calendar-event-item {
            border-radius: 8px;
            padding: 5px 7px;
            font-size: 0.71rem;
            cursor: pointer;
            transition: all 0.18s ease;
            text-decoration: none;
            display: block;
            line-height: 1.25;
            user-select: none;
        }
        .calendar-event-item:hover {
            transform: translateY(-1px);
            filter: brightness(1.2);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.5);
        }
        .calendar-event-item.status-programada {
            background: rgba(14, 165, 233, 0.18);
            border: 1px solid rgba(56, 189, 248, 0.45);
            color: #e0f2fe;
        }
        .calendar-event-item.status-en-curso {
            background: rgba(245, 158, 11, 0.18);
            border: 1px solid rgba(245, 158, 11, 0.5);
            color: #fef3c7;
        }
        .calendar-event-item.status-realizada {
            background: rgba(16, 185, 129, 0.18);
            border: 1px solid rgba(16, 185, 129, 0.5);
            color: #d1fae5;
        }
        .calendar-event-item.status-cancelada {
            background: rgba(239, 68, 68, 0.18);
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #fee2e2;
        }
        @media (max-width: 768px) {
            .calendar-cell {
                min-height: 90px;
                padding: 4px;
            }
            .calendar-weekday {
                font-size: 0.68rem;
            }
            .calendar-event-item {
                font-size: 0.65rem;
                padding: 3px 4px;
            }
        }

        /* ======================================================= */
        /* ESTILOS DE VISTA COMPLETA DEL TICKET Y CHAT CONTESTACIONES */
        /* ======================================================= */
        .ticket-workspace {
            animation: fadeInTicket 0.25s ease-out;
        }
        @keyframes fadeInTicket {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .ticket-header-box {
            background: linear-gradient(135deg, rgba(8, 22, 42, 0.95) 0%, rgba(4, 13, 26, 0.95) 100%);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45), 0 0 20px rgba(2, 132, 199, 0.15);
        }

        .ticket-card {
            background: rgba(8, 22, 42, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(10px);
        }

        .chat-thread-container {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .chat-bubble-card {
            border-radius: 14px;
            padding: 16px 20px;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .chat-bubble-card:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }

        .chat-bubble-user {
            background: linear-gradient(135deg, rgba(15, 34, 56, 0.9) 0%, rgba(10, 25, 42, 0.9) 100%);
            border: 1px solid rgba(56, 189, 248, 0.22);
            border-left: 4px solid #38bdf8;
        }

        .chat-bubble-agent {
            background: linear-gradient(135deg, rgba(6, 44, 74, 0.95) 0%, rgba(8, 30, 52, 0.95) 100%);
            border: 1px solid rgba(14, 165, 233, 0.45);
            border-left: 4px solid #0284c7;
            box-shadow: 0 4px 18px rgba(2, 132, 199, 0.18);
        }

        .chat-bubble-internal {
            background: linear-gradient(135deg, rgba(50, 35, 10, 0.92) 0%, rgba(30, 20, 5, 0.92) 100%);
            border: 1px dashed rgba(245, 158, 11, 0.5);
            border-left: 4px solid #f59e0b;
        }

        .chat-avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .chat-avatar-user {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .chat-avatar-agent {
            background: rgba(2, 132, 199, 0.25);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.5);
            box-shadow: 0 0 12px rgba(56, 189, 248, 0.35);
        }

        .chat-avatar-internal {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }

        .chat-composer-box {
            background: rgba(10, 25, 45, 0.95);
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.4);
        }

        .chat-composer-box textarea:focus {
            background: rgba(6, 18, 34, 0.9) !important;
            border-color: #38bdf8 !important;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important;
            color: #fff !important;
        }

        .evidence-preview-box {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ticket-link-row {
            color: #fff;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .ticket-link-row:hover {
            color: #38bdf8 !important;
        }
    </style>
</head>
<body>

<!-- Backdrop para móviles -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<!-- Pestaña Flotante para Mostrar Menú Lateral cuando esté Oculto -->
<button type="button" id="btnShowSidebarTab" class="btn btn-primary btn-sm rounded-end-3 shadow-lg" onclick="toggleSidebar()" style="display: none; position: fixed; left: 0; top: 78px; z-index: 1045; padding: 8px 10px; border-left: none;" title="Mostrar Menú Lateral">
    <i class="bi bi-layout-sidebar-reverse fs-5 text-white"></i>
</button>

<div class="app-layout">
    <!-- ============================================== -->
    <!-- MENÚ LATERAL (SIDEBAR DEL AGENTE DE TICKETS) -->
    <!-- ============================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- Encabezado del Menú Lateral -->
        <div class="sidebar-brand d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <div class="sidebar-brand-icon">
                    <i class="bi bi-headset"></i>
                </div>
                <div class="overflow-hidden">
                    <div class="fw-bold text-white text-truncate" style="font-size: 0.95rem;">Mesa de Ayuda TI</div>
                    <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Dirección Central &bull; Soporte</div>
                </div>
            </div>
            <button type="button" class="btn btn-sm text-secondary p-1 border-0" onclick="toggleSidebar()" title="Ocultar Menú Lateral">
                <i class="bi bi-chevron-left text-white fs-5" id="iconSidebarChevron"></i>
            </button>
        </div>

        <!-- Botones Principales del Menú Lateral -->
        <div class="sidebar-menu">
            <div class="sidebar-group-title">MÓDULOS DE SOPORTE</div>

            <!-- Botón 1: Tickets -->
            <button type="button" class="sidebar-btn active" id="sidebarBtn_tickets" onclick="cambiarTab('tickets')">
                <i class="bi bi-ticket-detailed-fill text-info"></i>
                <span class="flex-grow-1">Tickets</span>
                <?php if ($totalAbiertos > 0): ?>
                    <span class="badge bg-warning text-dark font-monospace fw-bold" style="font-size: 0.72rem;"><?php echo $totalAbiertos; ?></span>
                <?php else: ?>
                    <span class="badge bg-dark border border-secondary text-secondary font-monospace" style="font-size: 0.72rem;"><?php echo $totalTickets; ?></span>
                <?php endif; ?>
            </button>

            <!-- Botón 2: Citas -->
            <button type="button" class="sidebar-btn" id="sidebarBtn_citas" onclick="cambiarTab('citas')">
                <i class="bi bi-calendar-event-fill text-success"></i>
                <span class="flex-grow-1">Citas</span>
                <?php if ($totalCitasProgramadas > 0): ?>
                    <span class="badge bg-success text-white font-monospace fw-bold" style="font-size: 0.72rem;"><?php echo $totalCitasProgramadas; ?></span>
                <?php else: ?>
                    <span class="badge bg-dark border border-secondary text-secondary font-monospace" style="font-size: 0.72rem;"><?php echo $totalCitas; ?></span>
                <?php endif; ?>
            </button>

            <!-- Botón 3: Estadísticas -->
            <button type="button" class="sidebar-btn" id="sidebarBtn_estadisticas" onclick="cambiarTab('estadisticas')">
                <i class="bi bi-bar-chart-line-fill text-primary"></i>
                <span class="flex-grow-1">Estadísticas</span>
                <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25" style="font-size: 0.65rem;">Métricas</span>
            </button>

            <?php if ($esSuperAdmin): ?>
            <!-- Botón 4: Agentes (Exclusivo SuperAdmin) -->
            <button type="button" class="sidebar-btn" id="sidebarBtn_agentes" onclick="cambiarTab('agentes')">
                <i class="bi bi-people-fill text-warning"></i>
                <span class="flex-grow-1">Agentes</span>
                <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-30 font-monospace" style="font-size: 0.68rem;"><?php echo count($agentesTickets); ?></span>
            </button>
            <?php endif; ?>

            <div class="sidebar-group-title mt-3">NAVEGACIÓN SISTEMA</div>
            <a href="menu.php" class="sidebar-btn text-secondary">
                <i class="bi bi-arrow-left-circle"></i>
                <span>Menú Principal</span>
            </a>
            <a href="agencias.php" class="sidebar-btn text-secondary">
                <i class="bi bi-buildings"></i>
                <span>cPanels / Agencias</span>
            </a>
            <?php if (tienePermiso('usuarios', 'puede_ver')): ?>
                <a href="usuarios.php" class="sidebar-btn text-secondary">
                    <i class="bi bi-people"></i>
                    <span>Usuarios</span>
                </a>
            <?php endif; ?>
        </div>

        <!-- Footer del Perfil en el Sidebar -->
        <div class="sidebar-footer">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <div class="rounded-circle bg-dark border border-secondary d-flex align-items-center justify-content-center text-info fw-bold" style="width: 36px; height: 36px; flex-shrink: 0; font-size: 0.85rem;">
                        <?php echo strtoupper(substr($nombreUsuario, 0, 1)); ?>
                    </div>
                    <div class="overflow-hidden">
                        <div class="text-white fw-semibold small text-truncate" style="font-size: 0.82rem;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                        <div class="text-secondary small font-monospace text-truncate" style="font-size: 0.72rem;">@<?php echo htmlspecialchars($_SESSION['usuario_login'] ?? 'agente'); ?></div>
                    </div>
                </div>
                <a href="logout.php" class="btn btn-sm btn-outline-danger border-0 p-1" title="Cerrar Sesión">
                    <i class="bi bi-power fs-5"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- CONTENIDO DE LA APLICACIÓN -->
    <div class="app-content">
        <!-- Navbar Superior -->
        <div class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-secondary btn-sm text-white rounded-3 me-1 border-opacity-40" onclick="toggleSidebar()" title="Ocultar / Mostrar Menú Lateral">
                    <i class="bi bi-layout-sidebar-inset me-1"></i> <span class="d-none d-md-inline">Menú</span>
                </button>
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold tracking-wide" id="topNavTituloSeccion">
                        <i class="bi bi-ticket-detailed-fill text-info me-1"></i> Tickets de Soporte
                    </span>
                    <span class="agent-badge ms-2 d-none d-sm-inline-flex"><i class="bi bi-person-badge-fill"></i> PANEL DE AGENTE TI</span>
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

            <?php if (!$accesoTodasAreas): ?>
                <?php if (empty($areasAutorizadas)): ?>
                    <div class="alert alert-warning border-0 rounded-4 p-4 text-center my-4" style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.35) !important;" role="alert">
                        <i class="bi bi-shield-lock-fill text-warning fs-1 d-block mb-2"></i>
                        <h5 class="fw-bold text-white mb-2">Sin Áreas de Sistemas Autorizadas</h5>
                        <p class="text-secondary small mb-0" style="max-width: 600px; margin: 0 auto;">
                            Tu usuario no tiene ninguna área de Sistemas asignada para tickets. Por políticas de seguridad, no puedes consultar ni dar seguimiento a requerimientos de otras áreas. Solicita a un Administrador que configure tus áreas en la Gestión de Usuarios.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info border-0 rounded-3 py-2 px-3 mb-4 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: rgba(2, 132, 199, 0.15); border: 1px solid rgba(56, 189, 248, 0.3) !important;">
                        <div class="small text-light d-flex flex-wrap align-items-center gap-2">
                            <i class="bi bi-shield-lock-fill text-info fs-5"></i>
                            <span>Áreas de Sistemas autorizadas para tu perfil:</span>
                            <?php foreach ($areasAutorizadas as $ak): 
                                $inf = $AREAS_SISTEMAS_CATALOGO[$ak] ?? null;
                            ?>
                                <span class="badge" style="background: <?php echo $inf['bg'] ?? '#0284c7'; ?>; color: <?php echo $inf['color'] ?? '#38bdf8'; ?>; border: 1px solid <?php echo $inf['border'] ?? '#38bdf8'; ?>;">
                                    <i class="bi <?php echo $inf['icono'] ?? 'bi-tag'; ?> me-1"></i><?php echo $ak; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <span class="badge bg-secondary bg-opacity-50 text-light small">Filtro de Seguridad Activo</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ======================================================= -->
            <!-- SECCIÓN DETALLE Y RESOLUCIÓN COMPLETA DEL TICKET (NO FLOTANTE) -->
            <!-- ======================================================= -->
            <div id="seccionTab_detalle" class="tab-seccion ticket-workspace" style="<?php echo $ticketActivo ? 'display: block;' : 'display: none;'; ?>">
                <?php if ($ticketActivo): 
                    $tEst = strtolower(trim($ticketActivo['estado'] ?? 'abierto'));
                    $tPrio = ucfirst(strtolower(trim($ticketActivo['prioridad'] ?? 'media')));
                    $tAreaKey = strtoupper(trim($ticketActivo['area_sistemas'] ?? 'INFRAESTRUCTURA'));
                    $tAreaInfo = $AREAS_SISTEMAS_CATALOGO[$tAreaKey] ?? [
                        'nombre' => $tAreaKey,
                        'icono'  => 'bi-cpu-fill',
                        'color'  => '#38bdf8',
                        'bg'     => 'rgba(56, 189, 248, 0.18)',
                        'border' => 'rgba(56, 189, 248, 0.45)'
                    ];

                    $tPrioClass = 'bg-secondary text-light';
                    if ($tPrio === 'Baja') $tPrioClass = 'bg-success bg-opacity-25 text-success border border-success border-opacity-35';
                    elseif ($tPrio === 'Media') $tPrioClass = 'bg-warning bg-opacity-25 text-warning border border-warning border-opacity-35';
                    elseif ($tPrio === 'Alta') $tPrioClass = 'bg-danger bg-opacity-25 text-danger border border-danger border-opacity-35';
                    elseif ($tPrio === 'Urgente') $tPrioClass = 'bg-danger text-white shadow-sm';

                    $tStatusClass = 'bg-warning text-dark fw-bold';
                    $tStatusGlow = 'rgba(245, 158, 11, 0.3)';
                    if ($tEst === 'en proceso') {
                        $tStatusClass = 'bg-info text-dark fw-bold';
                        $tStatusGlow = 'rgba(14, 165, 233, 0.35)';
                    } elseif ($tEst === 'resuelto') {
                        $tStatusClass = 'bg-success text-white fw-bold';
                        $tStatusGlow = 'rgba(16, 185, 129, 0.35)';
                    } elseif ($tEst === 'cerrado') {
                        $tStatusClass = 'bg-secondary text-white fw-bold';
                        $tStatusGlow = 'rgba(100, 116, 139, 0.2)';
                    }
                ?>
                <!-- Header Box del Ticket -->
                <div class="ticket-header-box mb-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <a href="tickets.php" class="btn btn-outline-light btn-sm rounded-pill px-3 py-1.5 d-inline-flex align-items-center gap-1 border-opacity-35 shadow-sm" style="backdrop-filter: blur(4px);" onclick="volverAListaTickets(event)">
                                <i class="bi bi-arrow-left"></i> Volver a la Lista de Tickets
                            </a>
                            <span class="badge-folio fs-6 px-3 py-1.5" id="detFolioBadge">
                                <?php echo htmlspecialchars($ticketActivo['folio'] ?? ('TK-' . $ticketActivo['id'])); ?>
                            </span>
                            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill text-white border-opacity-25 px-2 py-1" onclick="copiarFolio('<?php echo htmlspecialchars($ticketActivo['folio'] ?? ('TK-' . $ticketActivo['id'])); ?>')" title="Copiar Folio">
                                <i class="bi bi-clipboard"></i>
                            </button>
                            <span class="badge-agencia px-3 py-1.5">
                                <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($ticketActivo['solicitante_agencia'] ?? 'Agencia Central'); ?>
                            </span>
                            <span style="font-size: 0.8rem; font-weight: 700; padding: 4px 12px; border-radius: 12px; background: <?php echo $tAreaInfo['bg']; ?>; color: <?php echo $tAreaInfo['color']; ?>; border: 1px solid <?php echo $tAreaInfo['border']; ?>; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="bi <?php echo $tAreaInfo['icono']; ?>"></i> <?php echo $tAreaInfo['nombre']; ?>
                            </span>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge rounded-pill <?php echo $tPrioClass; ?> px-3 py-1.5 fw-bold" style="font-size: 0.82rem;">
                                Prioridad: <?php echo $tPrio; ?>
                            </span>
                            <span class="badge rounded-pill <?php echo $tStatusClass; ?> px-3 py-1.5 fs-6 shadow-sm" style="box-shadow: 0 0 15px <?php echo $tStatusGlow; ?> !important;">
                                <i class="bi bi-circle-fill me-1" style="font-size: 0.55rem;"></i> <?php echo htmlspecialchars($ticketActivo['estado'] ?? 'Abierto'); ?>
                            </span>
                        </div>
                    </div>

                    <h2 class="fw-bold text-white mb-2" style="font-size: 1.6rem; line-height: 1.3;">
                        <?php echo htmlspecialchars($ticketActivo['titulo'] ?? 'Sin título'); ?>
                    </h2>

                    <div class="d-flex flex-wrap align-items-center gap-3 text-secondary small pt-2 border-top border-secondary border-opacity-20">
                        <div>
                            <i class="bi bi-person-circle text-info me-1"></i> Solicitante: 
                            <strong class="text-white"><?php echo htmlspecialchars($ticketActivo['solicitante_nombre'] ?? 'Usuario'); ?></strong>
                            <?php if (!empty($ticketActivo['solicitante_usuario'])): ?>
                                <span class="text-info font-monospace">(@<?php echo htmlspecialchars($ticketActivo['solicitante_usuario']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ticketActivo['solicitante_email'])): ?>
                            <div>
                                <i class="bi bi-envelope text-secondary me-1"></i>
                                <a href="mailto:<?php echo htmlspecialchars($ticketActivo['solicitante_email']); ?>" class="text-info text-decoration-none">
                                    <?php echo htmlspecialchars($ticketActivo['solicitante_email']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div>
                            <i class="bi bi-calendar3 me-1"></i> Registrado: 
                            <span class="text-white"><?php echo formatearFechaHoraMensaje($ticketActivo['creado_en'] ?? 'now'); ?></span>
                        </div>
                        <?php if (!empty($ticketActivo['asignado_a'])): ?>
                            <div>
                                <i class="bi bi-headset text-warning me-1"></i> Técnico asignado: 
                                <strong class="text-warning"><?php echo htmlspecialchars($ticketActivo['asignado_a']); ?></strong>
                            </div>
                        <?php else: ?>
                            <div>
                                <span class="badge bg-secondary bg-opacity-50 text-light"><i class="bi bi-exclamation-circle me-1"></i> Sin técnico asignado</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Contenido Principal: 2 Columnas -->
                <div class="row g-4">
                    <!-- Columna Izquierda: Solicitud Original + Hilo de Chat/Contestaciones + Composer -->
                    <div class="col-lg-8">
                        <!-- 1. Tarjeta de Solicitud Inicial -->
                        <div class="ticket-card p-4 mb-4" style="border-left: 4px solid #38bdf8;">
                            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="chat-avatar-circle chat-avatar-user">
                                        <i class="bi bi-file-earmark-text-fill"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-white fs-6">Requerimiento Inicial Reportado</div>
                                        <div class="text-secondary small">
                                            Por <strong class="text-light"><?php echo htmlspecialchars($ticketActivo['solicitante_nombre'] ?? 'Solicitante'); ?></strong> 
                                            el <?php echo formatearFechaHoraMensaje($ticketActivo['creado_en'] ?? 'now'); ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="badge bg-info bg-opacity-20 text-info border border-info border-opacity-30 rounded-pill px-3 py-1">
                                    Apertura de Incidencia
                                </span>
                            </div>

                            <div class="text-light fs-6" style="white-space: pre-line; line-height: 1.7; color: #e2e8f0 !important;">
                                <?php echo htmlspecialchars($ticketActivo['descripcion'] ?? 'Sin descripción'); ?>
                            </div>

                            <?php if (!empty($ticketActivo['archivo_adjunto'])): 
                                $extAdj = strtolower(pathinfo($ticketActivo['archivo_adjunto'], PATHINFO_EXTENSION));
                                $esImg = in_array($extAdj, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                                $esPdf = ($extAdj === 'pdf');
                            ?>
                                <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                                    <div class="small text-secondary fw-semibold mb-2"><i class="bi bi-paperclip me-1 text-info"></i> Evidencia Adjunta en la Solicitud:</div>
                                    <?php if ($esPdf): ?>
                                        <div class="d-flex align-items-center justify-content-between p-2.5 px-3 rounded-3" style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); max-width: 520px;">
                                            <div class="d-flex align-items-center gap-2.5 overflow-hidden">
                                                <i class="bi bi-file-earmark-pdf-fill text-danger fs-2 flex-shrink-0"></i>
                                                <div class="text-truncate">
                                                    <div class="text-white fw-bold small text-truncate" title="<?php echo htmlspecialchars(basename($ticketActivo['archivo_adjunto'])); ?>">
                                                        <?php echo htmlspecialchars(basename($ticketActivo['archivo_adjunto'])); ?>
                                                    </div>
                                                    <div class="text-secondary" style="font-size: 0.73rem;">Documento PDF adjunto a la solicitud</div>
                                                </div>
                                            </div>
                                            <a href="<?php echo htmlspecialchars($ticketActivo['archivo_adjunto']); ?>" target="_blank" download class="btn btn-sm btn-danger rounded-pill px-3 py-1 text-nowrap ms-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" style="font-size: 0.78rem;">
                                                <i class="bi bi-file-pdf me-1"></i> Abrir / Descargar PDF
                                            </a>
                                        </div>
                                    <?php elseif ($esImg): ?>
                                        <div class="p-2 rounded-3 bg-black bg-opacity-40 d-inline-block border border-secondary border-opacity-30">
                                            <a href="<?php echo htmlspecialchars($ticketActivo['archivo_adjunto']); ?>" target="_blank" title="Clic para ver en tamaño completo">
                                                <img src="<?php echo htmlspecialchars($ticketActivo['archivo_adjunto']); ?>" alt="Evidencia" class="img-fluid rounded-2 shadow" style="max-height: 240px; object-fit: contain; display: block;">
                                            </a>
                                            <div class="mt-2 text-center">
                                                <a href="<?php echo htmlspecialchars($ticketActivo['archivo_adjunto']); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-pill px-3 py-0.5" style="font-size: 0.78rem;">
                                                    <i class="bi bi-arrows-fullscreen me-1"></i> Ver Imagen Completa
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="evidence-preview-box">
                                            <i class="bi bi-file-earmark-arrow-down-fill text-info fs-3"></i>
                                            <div class="flex-grow-1 overflow-hidden">
                                                <div class="text-white small fw-bold text-truncate"><?php echo basename($ticketActivo['archivo_adjunto']); ?></div>
                                                <div class="text-secondary" style="font-size: 0.72rem;">Archivo adjunto (<?php echo strtoupper($extAdj); ?>)</div>
                                            </div>
                                            <a href="<?php echo htmlspecialchars($ticketActivo['archivo_adjunto']); ?>" target="_blank" class="btn btn-info btn-sm rounded-pill px-3 fw-bold text-dark">
                                                <i class="bi bi-download me-1"></i> Descargar
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- 2. Hilo del Chat y Contestaciones -->
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-chat-dots-fill text-info"></i> Conversación y Respuestas
                                <span class="badge bg-dark border border-secondary text-info rounded-pill px-2.5 py-1 font-monospace" style="font-size: 0.75rem;">
                                    <?php echo count($mensajesTicketActivo); ?> mensajes
                                </span>
                            </h5>
                            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill text-white border-opacity-30 py-0 px-2" onclick="location.reload()" title="Actualizar conversación">
                                <i class="bi bi-arrow-clockwise me-1"></i> Actualizar
                            </button>
                        </div>

                        <div class="chat-thread-container mb-4">
                            <?php if (empty($mensajesTicketActivo) && empty($ticketActivo['notas_resolucion'])): ?>
                                <div class="p-4 rounded-4 text-center border border-secondary border-opacity-25" style="background: rgba(255, 255, 255, 0.02);">
                                    <i class="bi bi-chat-square-text text-secondary fs-1 d-block mb-2 opacity-50"></i>
                                    <div class="text-light fw-semibold">Aún no hay respuestas en este ticket</div>
                                    <div class="text-secondary small mt-1">Escribe a continuación para enviar una contestación al solicitante o agregar notas técnicas de seguimiento.</div>
                                </div>
                            <?php endif; ?>

                            <!-- Si existían notas de resolución previas del agente, mostrarlas como evento -->
                            <?php if (!empty($ticketActivo['notas_resolucion'])): ?>
                                <div class="chat-bubble-card chat-bubble-agent">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="chat-avatar-circle chat-avatar-agent">
                                                <i class="bi bi-headset"></i>
                                            </div>
                                            <div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="fw-bold text-info">
                                                        <?php echo htmlspecialchars($ticketActivo['asignado_a'] ?: 'Agente de Sistemas'); ?>
                                                    </span>
                                                    <span class="badge bg-primary text-white py-0 px-2" style="font-size: 0.68rem;">SOPORTE TI</span>
                                                    <span class="badge bg-dark border border-secondary text-secondary py-0 px-2" style="font-size: 0.68rem;">Bitácora de Atención</span>
                                                </div>
                                                <span class="text-secondary" style="font-size: 0.72rem;">Registrado en bitácora técnica</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-light small" style="white-space: pre-line; line-height: 1.6;">
                                        <?php echo htmlspecialchars($ticketActivo['notas_resolucion']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Mensajes del Chat en orden cronológico -->
                            <?php foreach ($mensajesTicketActivo as $msg): 
                                $esAgente = ($msg['autor_rol'] === 'agente');
                                $esInterna = !empty($msg['es_nota_interna']);
                                $bubbleClass = $esInterna ? 'chat-bubble-internal' : ($esAgente ? 'chat-bubble-agent' : 'chat-bubble-user');
                                $avatarClass = $esInterna ? 'chat-avatar-internal' : ($esAgente ? 'chat-avatar-agent' : 'chat-avatar-user');
                                $avatarIcon  = $esInterna ? 'bi-lock-fill' : ($esAgente ? 'bi-headset' : 'bi-person-fill');
                            ?>
                                <div class="chat-bubble-card <?php echo $bubbleClass; ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="chat-avatar-circle <?php echo $avatarClass; ?>">
                                                <i class="bi <?php echo $avatarIcon; ?>"></i>
                                            </div>
                                            <div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <strong class="<?php echo $esAgente ? 'text-info' : 'text-white'; ?>">
                                                        <?php echo htmlspecialchars($msg['autor_nombre'] ?? 'Usuario'); ?>
                                                    </strong>
                                                    <?php if (!empty($msg['autor_usuario'])): ?>
                                                        <span class="text-secondary font-monospace small" style="font-size: 0.72rem;">@<?php echo htmlspecialchars($msg['autor_usuario']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($esInterna): ?>
                                                        <span class="badge bg-warning text-dark py-0 px-2 fw-bold" style="font-size: 0.68rem;">NOTA INTERNA TI</span>
                                                    <?php elseif ($esAgente): ?>
                                                        <span class="badge bg-primary text-white py-0 px-2" style="font-size: 0.68rem;">SOPORTE TI &bull; AGENTE</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-info bg-opacity-25 text-info py-0 px-2" style="font-size: 0.68rem;">SOLICITANTE</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($msg['autor_agencia'])): ?>
                                                        <span class="badge bg-dark border border-secondary text-secondary py-0 px-1.5" style="font-size: 0.65rem;">
                                                            <?php echo htmlspecialchars($msg['autor_agencia']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="text-secondary" style="font-size: 0.72rem;">
                                                    <i class="bi bi-clock me-1"></i><?php echo formatearFechaHoraMensaje($msg['creado_en'] ?? ''); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="text-light" style="white-space: pre-line; line-height: 1.6; font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($msg['mensaje']); ?>
                                    </div>

                                    <?php if (!empty($msg['archivo_adjunto'])): 
                                        $rawAdjM = trim($msg['archivo_adjunto']);
                                        $extM = strtolower(pathinfo($rawAdjM, PATHINFO_EXTENSION));
                                        $esImgM = in_array($extM, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                                        $esPdfM = ($extM === 'pdf');
                                        $nomAdjM = basename($rawAdjM);
                                        $urlAdjM = $rawAdjM;
                                    ?>
                                        <div class="mt-3 pt-2 border-top border-secondary border-opacity-25">
                                            <?php if ($esPdfM): ?>
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
                                            <?php elseif ($esImgM): ?>
                                                <div class="mb-2">
                                                    <a href="<?php echo htmlspecialchars($urlAdjM); ?>" target="_blank">
                                                        <img src="<?php echo htmlspecialchars($urlAdjM); ?>" alt="Adjunto" class="img-fluid rounded-2 shadow-sm" style="max-height: 200px; object-fit: contain; background: rgba(0,0,0,0.5); padding: 4px; border: 1px solid rgba(255,255,255,0.15);">
                                                    </a>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($urlAdjM); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-pill px-3 py-1 text-decoration-none" style="font-size: 0.78rem;">
                                                    <i class="bi bi-image me-1"></i> Ver imagen completa
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo htmlspecialchars($urlAdjM); ?>" target="_blank" class="btn btn-outline-info btn-sm rounded-pill px-3 py-1 text-decoration-none" style="font-size: 0.78rem;">
                                                    <i class="bi bi-paperclip me-1"></i> Ver / Descargar: <?php echo htmlspecialchars($nomAdjM); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- 3. Caja de Nueva Contestación / Chat Composer -->
                        <div class="chat-composer-box">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="agregar_mensaje_ticket">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticketActivo['id']; ?>">

                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="form-label small fw-bold text-info mb-0">
                                        <i class="bi bi-reply-fill me-1"></i> Escribir Contestación al Solicitante
                                    </label>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="es_nota_interna" id="swNotaInterna" value="1">
                                        <label class="form-check-label small text-secondary" for="swNotaInterna" title="Solo visible para administradores y técnicos de TI">
                                            <i class="bi bi-lock-fill text-warning me-1"></i> Nota Interna TI
                                        </label>
                                    </div>
                                </div>

                                <textarea name="mensaje" rows="4" class="form-control rounded-3 mb-3" placeholder="Escribe aquí tu respuesta, actualización técnica, instrucciones o solución para el usuario..." required style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35); resize: vertical;"></textarea>

                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pt-2 border-top border-secondary border-opacity-25">
                                    <div class="d-flex align-items-center gap-2">
                                        <label class="btn btn-outline-secondary btn-sm rounded-pill text-white border-opacity-40 mb-0 d-inline-flex align-items-center gap-1" style="cursor: pointer;">
                                            <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                                            <span>Adjuntar PDF o Evidencia</span>
                                            <input type="file" name="archivo_mensaje" id="archivoMensajeInput" class="d-none" accept=".pdf,image/*,.doc,.docx,.xls,.xlsx,.txt,.zip" onchange="mostrarArchivoMsgSeleccionado(this)">
                                        </label>
                                        <span id="txtArchivoMsgSeleccionado" class="small text-secondary font-monospace"></span>
                                    </div>

                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <select name="cambio_estado" class="form-select form-select-sm rounded-3 text-white" style="width: auto; background: rgba(8, 22, 42, 0.9); border-color: rgba(56, 189, 248, 0.4);">
                                            <option value="">Mantener Estado Actual</option>
                                            <option value="En Proceso" <?php echo $tEst === 'abierto' ? 'selected' : ''; ?>>Cambiar a: En Proceso</option>
                                            <option value="Resuelto">Cambiar a: Resuelto</option>
                                            <option value="Cerrado">Cambiar a: Cerrado</option>
                                        </select>

                                        <button type="submit" class="btn btn-info btn-sm rounded-pill px-4 fw-bold text-dark d-inline-flex align-items-center gap-1.5 shadow">
                                            <i class="bi bi-send-fill"></i> Enviar Contestación
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Columna Derecha: Panel de Gestión del Agente TI & Ficha Técnica -->
                    <div class="col-lg-4">
                        <!-- Panel de Resolución y Estado -->
                        <div class="ticket-card p-4 mb-4" style="border-top: 3px solid #0284c7;">
                            <h5 class="fw-bold text-white mb-3 d-flex align-items-center gap-2">
                                <i class="bi bi-sliders2 text-info"></i> Gestión del Ticket
                            </h5>

                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="accion" value="actualizar_ticket">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticketActivo['id']; ?>">
                                <input type="hidden" name="redirect_ver" value="1">

                                <div class="mb-3">
                                    <label class="form-label small text-secondary fw-semibold">Estado del Ticket *</label>
                                    <select name="estado" class="form-select" required style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35);">
                                        <option value="Abierto" <?php echo $tEst === 'abierto' ? 'selected' : ''; ?>>Abierto (En espera)</option>
                                        <option value="En Proceso" <?php echo $tEst === 'en proceso' ? 'selected' : ''; ?>>En Proceso (En atención)</option>
                                        <option value="Resuelto" <?php echo $tEst === 'resuelto' ? 'selected' : ''; ?>>Resuelto (Trabajo terminado)</option>
                                        <option value="Cerrado" <?php echo $tEst === 'cerrado' ? 'selected' : ''; ?>>Cerrado (Finalizado)</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-secondary fw-semibold">Nivel de Prioridad</label>
                                    <select name="prioridad" class="form-select" style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35);">
                                        <option value="Baja" <?php echo $tPrio === 'Baja' ? 'selected' : ''; ?>>Baja</option>
                                        <option value="Media" <?php echo $tPrio === 'Media' ? 'selected' : ''; ?>>Media</option>
                                        <option value="Alta" <?php echo $tPrio === 'Alta' ? 'selected' : ''; ?>>Alta</option>
                                        <option value="Urgente" <?php echo $tPrio === 'Urgente' ? 'selected' : ''; ?>>Urgente</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label small text-secondary fw-semibold mb-0">Especialista Asignado</label>
                                        <button type="button" class="btn btn-link btn-sm text-info p-0 text-decoration-none" style="font-size: 0.74rem;" onclick="asignarmeEnFormulario('<?php echo htmlspecialchars($nombreUsuario); ?>')">
                                            <i class="bi bi-hand-index-thumb"></i> Asignarme a mí
                                        </button>
                                    </div>
                                    <input type="text" name="asignado_a" id="inputAsignadoAgente" class="form-control" value="<?php echo htmlspecialchars($ticketActivo['asignado_a'] ?? ''); ?>" placeholder="Nombre del técnico responsable" style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35);">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-secondary fw-semibold">Bitácora / Notas de Solución</label>
                                    <textarea name="notas_resolucion" class="form-control" rows="3" placeholder="Diagnóstico, solución técnica aplicada o motivo de cierre..." style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35); font-size: 0.88rem;"><?php echo htmlspecialchars($ticketActivo['notas_resolucion'] ?? ''); ?></textarea>
                                </div>

                                <!-- Evidencia (PDF, fotos, Excel, etc.) -->
                                <div class="mb-3">
                                    <label class="form-label small text-secondary fw-semibold d-flex align-items-center justify-content-between mb-1">
                                        <span><i class="bi bi-paperclip text-info me-1"></i> Evidencia (PDF, fotos, Excel, etc.)</span>
                                        <span class="badge bg-secondary bg-opacity-25 text-light" style="font-size: 0.68rem;">Opcional</span>
                                    </label>
                                    <input type="file" name="archivo_evidencia" class="form-control" style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35); font-size: 0.84rem;" accept=".pdf,image/*,.xls,.xlsx,.csv,.doc,.docx,.txt,.zip">
                                    <div class="form-text text-secondary mt-1" style="font-size: 0.72rem;">
                                        Adjunta comprobantes, reportes PDF, fotos/capturas o archivos Excel de la atención.
                                    </div>
                                    <?php if (!empty($ticketActivo['evidencia_resolucion'])): 
                                        $evUrl = $ticketActivo['evidencia_resolucion'];
                                        $evExt = strtolower(pathinfo($evUrl, PATHINFO_EXTENSION));
                                        $esEvPdf = ($evExt === 'pdf');
                                        $esEvImg = in_array($evExt, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                                        $esEvXls = in_array($evExt, ['xls', 'xlsx', 'csv']);
                                    ?>
                                        <div class="mt-2 p-2 rounded-2 border border-info border-opacity-30 d-flex align-items-center justify-content-between" style="background: rgba(14, 165, 233, 0.1);">
                                            <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                                <?php if ($esEvPdf): ?>
                                                    <i class="bi bi-file-earmark-pdf-fill text-danger fs-5 flex-shrink-0"></i>
                                                <?php elseif ($esEvXls): ?>
                                                    <i class="bi bi-file-earmark-excel-fill text-success fs-5 flex-shrink-0"></i>
                                                <?php elseif ($esEvImg): ?>
                                                    <i class="bi bi-file-earmark-image-fill text-info fs-5 flex-shrink-0"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-paperclip text-info fs-5 flex-shrink-0"></i>
                                                <?php endif; ?>
                                                <span class="small text-white text-truncate" title="<?php echo htmlspecialchars(basename($evUrl)); ?>" style="font-size: 0.78rem;">
                                                    <?php echo htmlspecialchars(basename($evUrl)); ?>
                                                </span>
                                            </div>
                                            <a href="<?php echo htmlspecialchars($evUrl); ?>" target="_blank" download class="btn btn-sm btn-outline-info rounded-pill py-0.5 px-2.5 text-nowrap" style="font-size: 0.72rem;">
                                                <i class="bi bi-box-arrow-up-right me-1"></i> Ver Evidencia
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <button type="submit" class="btn btn-info w-100 fw-bold rounded-3 text-dark py-2 shadow">
                                    <i class="bi bi-check2-circle me-1"></i> Guardar Cambios de Atención
                                </button>

                                <?php 
                                $esInfraestructura = ($ticketActivo && strtoupper(trim($ticketActivo['area_sistemas'] ?? '')) === 'INFRAESTRUCTURA');
                                if ($esInfraestructura): 
                                    $reporteHabilitado = !empty($ticketActivo['requiere_firma_reporte']);
                                    $reporteFirmado = !empty($ticketActivo['reporte_firmado']) || !empty($ticketActivo['reporte_firma_digital']);
                                ?>
                                    <div class="mt-3 pt-3 border-top border-secondary border-opacity-30">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="small text-info fw-bold d-flex align-items-center gap-1">
                                                <i class="bi bi-file-earmark-check-fill text-warning"></i> Reporte de Servicio
                                            </span>
                                            <span class="badge <?php echo $reporteFirmado ? 'bg-success' : ($reporteHabilitado ? 'bg-warning text-dark' : 'bg-secondary bg-opacity-30 text-light'); ?>" style="font-size: 0.68rem;">
                                                <?php echo $reporteFirmado ? '✓ Firmado Digital' : ($reporteHabilitado ? '⏳ Pendiente Firma' : 'Opcional'); ?>
                                            </span>
                                        </div>
                                        <button type="button" class="btn <?php echo $reporteFirmado ? 'btn-outline-success' : ($reporteHabilitado ? 'btn-outline-warning' : 'btn-outline-info'); ?> w-100 fw-bold rounded-3 py-2 text-start d-flex align-items-center justify-content-between shadow-sm" data-bs-toggle="modal" data-bs-target="#modalReporteServicio">
                                            <span class="d-flex align-items-center gap-2">
                                                <i class="bi <?php echo $reporteFirmado ? 'bi-patch-check-fill text-success fs-6' : 'bi-pen-fill text-warning fs-6'; ?>"></i>
                                                <span style="font-size: 0.84rem;"><?php echo $reporteFirmado ? 'Ver Formato Firmado' : ($reporteHabilitado ? 'Editar Reporte de Servicio' : 'Generar Formato para Firma'); ?></span>
                                            </span>
                                            <i class="bi bi-chevron-right small text-secondary"></i>
                                        </button>
                                        <?php if ($reporteFirmado || $reporteHabilitado): ?>
                                            <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketActivo['id']; ?>&auto=1" target="_blank" class="btn btn-sm btn-outline-danger w-100 fw-semibold rounded-3 py-1.5 mt-2 d-flex align-items-center justify-content-center gap-1.5 shadow-sm">
                                                <i class="bi bi-file-earmark-pdf-fill"></i> Descargar / Imprimir PDF
                                            </a>
                                        <?php endif; ?>
                                        <div class="form-text text-secondary mt-1" style="font-size: 0.72rem;">
                                            <?php if ($reporteFirmado): ?>
                                                <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Firmado digitalmente por <strong><?php echo htmlspecialchars($ticketActivo['reporte_firmado_por'] ?? 'el usuario'); ?></strong></span>
                                            <?php elseif ($reporteHabilitado): ?>
                                                <span class="text-warning"><i class="bi bi-clock-history me-1"></i>Habilitado. El usuario puede firmarlo en su portal de agencia.</span>
                                            <?php else: ?>
                                                Opcional Infraestructura: escribe los trabajos realizados y envíalo para firma digital.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>

                        <!-- Ficha Técnica del Solicitante -->
                        <div class="ticket-card p-4 mb-4">
                            <h6 class="fw-bold text-white mb-3 d-flex align-items-center gap-2">
                                <i class="bi bi-person-lines-fill text-info"></i> Ficha del Solicitante
                            </h6>
                            <div class="d-flex flex-column gap-2 small">
                                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                    <span class="text-secondary">Nombre:</span>
                                    <strong class="text-white"><?php echo htmlspecialchars($ticketActivo['solicitante_nombre'] ?? '---'); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                    <span class="text-secondary">Usuario:</span>
                                    <span class="text-info font-monospace">@<?php echo htmlspecialchars($ticketActivo['solicitante_usuario'] ?? '---'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                    <span class="text-secondary">Email:</span>
                                    <span class="text-white text-truncate" style="max-width: 170px;" title="<?php echo htmlspecialchars($ticketActivo['solicitante_email'] ?? ''); ?>"><?php echo htmlspecialchars($ticketActivo['solicitante_email'] ?? '---'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                    <span class="text-secondary">Agencia Origen:</span>
                                    <strong class="text-light"><?php echo htmlspecialchars($ticketActivo['solicitante_agencia'] ?? 'General'); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                    <span class="text-secondary">Área TI:</span>
                                    <span class="text-info fw-bold"><?php echo htmlspecialchars($ticketActivo['area_sistemas'] ?? '---'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                    <span class="text-secondary">Fecha Alta:</span>
                                    <span class="text-secondary font-monospace"><?php echo formatearFechaHoraMensaje($ticketActivo['creado_en']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between py-1">
                                    <span class="text-secondary">Última Act.:</span>
                                    <span class="text-secondary font-monospace"><?php echo formatearFechaHoraMensaje($ticketActivo['actualizado_en'] ?? $ticketActivo['creado_en']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Cita Técnica Vinculada -->
                        <div class="ticket-card p-4 mb-4" style="border-top: 3px solid <?php echo $citaTicketActivo ? '#10b981' : '#f59e0b'; ?>;">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h6 class="fw-bold text-white m-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-calendar2-check-fill <?php echo $citaTicketActivo ? 'text-success' : 'text-warning'; ?>"></i> Cita / Visita Técnica
                                </h6>
                                <?php if ($citaTicketActivo): 
                                    $estCita = strtolower(trim($citaTicketActivo['estado'] ?? 'programada'));
                                    $badgeCita = 'bg-success';
                                    if ($estCita === 'en curso') $badgeCita = 'bg-warning text-dark';
                                    elseif ($estCita === 'realizada') $badgeCita = 'bg-primary';
                                    elseif ($estCita === 'cancelada') $badgeCita = 'bg-danger';
                                ?>
                                    <span class="badge <?php echo $badgeCita; ?> rounded-pill px-2.5 py-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-clock-history me-1"></i> <?php echo htmlspecialchars($citaTicketActivo['estado']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-30 text-secondary rounded-pill px-2 py-0.5" style="font-size: 0.7rem;">
                                        Sin Cita Asignada
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($citaTicketActivo): ?>
                                <div class="d-flex flex-column gap-2 small">
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Folio Cita:</span>
                                        <strong class="text-info font-monospace"><?php echo htmlspecialchars($citaTicketActivo['folio']); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Fecha y Hora:</span>
                                        <strong class="text-white">
                                            <i class="bi bi-calendar-event text-warning me-1"></i> <?php echo date('d/m/Y', strtotime($citaTicketActivo['fecha_cita'])); ?> a las <?php echo htmlspecialchars($citaTicketActivo['hora_cita']); ?> hrs
                                        </strong>
                                    </div>
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Técnico Asignado:</span>
                                        <span class="text-light fw-bold"><?php echo htmlspecialchars($citaTicketActivo['tecnico_asignado'] ?? 'Sin asignar'); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Tipo de Cita:</span>
                                        <span class="text-light"><?php echo htmlspecialchars($citaTicketActivo['tipo_cita'] ?? 'Presencial'); ?></span>
                                    </div>
                                    <?php if (!empty($citaTicketActivo['motivo_reprogramacion'])): ?>
                                        <div class="p-2.5 rounded-3 border border-warning border-opacity-40 mt-1" style="background: rgba(245, 158, 11, 0.1);">
                                            <div class="text-warning small fw-bold d-flex align-items-center gap-1">
                                                <i class="bi bi-info-circle-fill"></i> Último Motivo de Reprogramación:
                                            </div>
                                            <div class="text-light small mt-1" style="font-size: 0.76rem;">
                                                <?php echo nl2br(htmlspecialchars($citaTicketActivo['motivo_reprogramacion'])); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <button type="button" class="btn btn-outline-warning btn-sm w-100 fw-bold rounded-3 py-2 mt-3 d-flex align-items-center justify-content-center gap-1.5 shadow-sm" onclick="abrirModalActualizarCita(<?php echo htmlspecialchars(json_encode($citaTicketActivo)); ?>, <?php echo $ticketActivo['id']; ?>)">
                                    <i class="bi bi-pencil-square"></i> Modificar / Reprogramar Cita
                                </button>
                            <?php else: ?>
                                <p class="text-secondary small mb-3">
                                    Este ticket aún no tiene una visita o cita técnica programada en la agenda del equipo.
                                </p>
                                <button type="button" class="btn btn-outline-success btn-sm w-100 fw-bold rounded-3 py-2 d-flex align-items-center justify-content-center gap-1.5 shadow-sm" onclick="agendarCitaParaTicket(<?php echo htmlspecialchars(json_encode($ticketActivo)); ?>)">
                                    <i class="bi bi-calendar-plus-fill"></i> Agendar Cita para este Ticket
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Acciones Rápidas -->
                        <div class="ticket-card p-3">
                            <div class="d-grid gap-2">
                                <?php if ($citaTicketActivo): ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm rounded-3 py-2 text-start d-flex align-items-center justify-content-between" onclick="abrirModalActualizarCita(<?php echo htmlspecialchars(json_encode($citaTicketActivo)); ?>, <?php echo $ticketActivo['id']; ?>)">
                                        <span><i class="bi bi-calendar-check-fill text-warning me-2"></i> Cita: <?php echo date('d/m/Y', strtotime($citaTicketActivo['fecha_cita'])); ?> (<?php echo htmlspecialchars($citaTicketActivo['hora_cita']); ?> hrs)</span>
                                        <span class="badge bg-warning text-dark rounded-pill" style="font-size: 0.65rem;"><?php echo htmlspecialchars($citaTicketActivo['estado']); ?></span>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-success btn-sm rounded-3 py-2 text-start d-flex align-items-center justify-content-between" onclick="agendarCitaParaTicket(<?php echo htmlspecialchars(json_encode($ticketActivo)); ?>)">
                                        <span><i class="bi bi-calendar-plus me-2"></i> Agendar Cita Técnica</span>
                                        <i class="bi bi-chevron-right small text-secondary"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($esInfraestructura): ?>
                                    <button type="button" class="btn <?php echo $reporteFirmado ? 'btn-outline-success' : ($reporteHabilitado ? 'btn-outline-warning' : 'btn-outline-info'); ?> btn-sm rounded-3 py-2 text-start d-flex align-items-center justify-content-between" data-bs-toggle="modal" data-bs-target="#modalReporteServicio">
                                        <span><i class="bi bi-file-earmark-check me-2"></i> Reporte de Servicio</span>
                                        <span class="badge <?php echo $reporteFirmado ? 'bg-success' : ($reporteHabilitado ? 'bg-warning text-dark' : 'bg-secondary bg-opacity-30'); ?> rounded-pill" style="font-size: 0.65rem;">
                                            <?php echo $reporteFirmado ? '✓ Firmado' : ($reporteHabilitado ? 'Por firmar' : 'Infraestructura'); ?>
                                        </span>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-light btn-sm rounded-3 py-2 text-start d-flex align-items-center justify-content-between border-opacity-25" onclick="window.print()">
                                    <span><i class="bi bi-printer me-2"></i> Imprimir Ficha de Ticket</span>
                                    <i class="bi bi-chevron-right small text-secondary"></i>
                                </button>
                                <?php if ($esAdmin): ?>
                                    <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar permanentemente este ticket?');" class="m-0">
                                        <input type="hidden" name="accion" value="eliminar_ticket">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticketActivo['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-3 py-2 text-start w-100 d-flex align-items-center justify-content-between border-opacity-35">
                                            <span><i class="bi bi-trash3 me-2"></i> Eliminar Ticket</span>
                                            <i class="bi bi-chevron-right small text-secondary"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <div class="alert alert-warning rounded-4 p-4 text-center my-4">
                        <i class="bi bi-exclamation-triangle fs-2 d-block mb-2 text-warning"></i>
                        <h5 class="fw-bold text-white">No se encontró el ticket solicitado</h5>
                        <p class="small text-secondary mb-3">El ticket no existe o no cuentas con permisos para ver el área correspondiente.</p>
                        <a href="tickets.php" class="btn btn-primary rounded-pill px-4" onclick="volverAListaTickets(event)">Volver al listado de tickets</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ======================================================= -->
            <!-- SECCIÓN 1: GESTIÓN DE TICKETS Y AGENCIA S -->
            <!-- ======================================================= -->
            <div id="seccionTab_tickets" class="tab-seccion" style="<?php echo $ticketActivo ? 'display: none;' : ''; ?>">
                <div id="vistaAgencias">
                    <!-- Encabezado de Vista: Tickets Dirección Sistemas -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-primary bg-opacity-20 text-info border border-info border-opacity-30 rounded-pill px-3 py-1 fw-bold">
                                    <i class="bi bi-shield-check me-1"></i> Mesa de Ayuda TI
                                </span>
                                <span class="text-secondary small">&bull; Dirección Central de Sistemas</span>
                            </div>
                            <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                                <i class="bi bi-ticket-perforated-fill text-info"></i> Tickets Dirección Sistemas
                            </h3>
                            <p class="text-secondary mb-0 small">
                                Monitoreo y control central de requerimientos técnicos e infraestructura en todas las sucursales de Grupo Huerta.
                            </p>
                        </div>
                    </div>

                    <!-- KPIs Generales Globales del Sistema -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <div class="kpi-icon kpi-cyan"><i class="bi bi-inbox-fill"></i></div>
                                <div>
                                    <div class="fs-4 fw-bold text-white"><?php echo $totalTickets; ?></div>
                                    <div class="small text-secondary fw-semibold">TOTAL TICKETS SISTEMA</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <div class="kpi-icon kpi-yellow"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="fs-4 fw-bold text-warning"><?php echo $totalAbiertos; ?></div>
                                    <div class="small text-secondary fw-semibold">ABIERTOS / SIN ATENDER</div>
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
                    <!-- SECCIÓN: TICKETS SIN VER / PENDIENTES DE ATENCIÓN -->
                    <!-- ============================================== -->
                    <div class="mb-5">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <div>
                                <h5 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-bell-fill text-warning"></i> Tickets Sin Ver / Pendientes de Atención
                                </h5>
                                <span class="text-secondary small">Requerimientos reportados que esperan ser atendidos por el equipo de sistemas</span>
                            </div>
                            <div>
                                <span class="badge <?php echo count($ticketsSinVer) > 0 ? 'bg-warning text-dark' : 'bg-success bg-opacity-20 text-success border border-success border-opacity-30'; ?> px-3 py-2 rounded-pill fw-bold">
                                    <i class="bi <?php echo count($ticketsSinVer) > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'; ?> me-1"></i>
                                    <?php echo count($ticketsSinVer); ?> <?php echo count($ticketsSinVer) === 1 ? 'Ticket Pendiente' : 'Tickets Pendientes'; ?>
                                </span>
                            </div>
                        </div>

                        <?php if (empty($ticketsSinVer)): ?>
                            <div class="p-4 rounded-4 text-center" style="background: linear-gradient(145deg, rgba(16, 185, 129, 0.08) 0%, rgba(9, 26, 50, 0.6) 100%); border: 1px solid rgba(16, 185, 129, 0.25); box-shadow: 0 10px 25px rgba(0,0,0,0.25);">
                                <div class="d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); color: #34d399; font-size: 1.6rem; border: 1px solid rgba(16, 185, 129, 0.4);">
                                    <i class="bi bi-check2-all"></i>
                                </div>
                                <h6 class="text-white fw-bold mb-1">¡Bandeja al día! No hay tickets sin ver ni pendientes de atención</h6>
                                <p class="text-secondary small mb-0">Todas las incidencias recibidas en tus áreas autorizadas han sido atendidas o están en proceso.</p>
                            </div>
                        <?php else: ?>
                            <div class="p-3 rounded-4" style="background: linear-gradient(145deg, rgba(13, 27, 48, 0.95) 0%, rgba(8, 18, 36, 0.98) 100%); border: 1px solid rgba(245, 158, 11, 0.3); box-shadow: 0 12px 32px rgba(0,0,0,0.45);">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                                                <th class="text-secondary small fw-bold">FOLIO</th>
                                                <th class="text-secondary small fw-bold">SUCURSAL</th>
                                                <th class="text-secondary small fw-bold">ÁREA TI</th>
                                                <th class="text-secondary small fw-bold">ASUNTO / INCIDENCIA</th>
                                                <th class="text-secondary small fw-bold">SOLICITANTE</th>
                                                <th class="text-secondary small fw-bold">FECHA</th>
                                                <th class="text-end text-secondary small fw-bold">ACCIÓN</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ticketsSinVer as $tsv): 
                                                $areaK = strtoupper(trim($tsv['area_sistemas'] ?? ''));
                                                $ainf = $AREAS_SISTEMAS[$areaK] ?? ($AREAS_SISTEMAS_CATALOGO[$areaK] ?? ['nombre' => $areaK, 'color' => '#38bdf8', 'bg' => 'rgba(56,189,248,0.15)', 'border' => 'rgba(56,189,248,0.3)', 'icono' => 'bi-tag']);
                                                $folioTsv = $tsv['folio'] ?? ('TK-' . $tsv['id']);
                                            ?>
                                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                                    <td>
                                                        <span class="badge bg-danger bg-opacity-20 text-danger border border-danger border-opacity-40 px-2 py-1 font-monospace fw-bold">
                                                            <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem; vertical-align: middle;"></i><?php echo htmlspecialchars($folioTsv); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge-agencia">
                                                            <i class="bi bi-building"></i> <?php echo htmlspecialchars($tsv['solicitante_agencia'] ?? 'Agencia'); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 10px; background: <?php echo $ainf['bg']; ?>; color: <?php echo $ainf['color']; ?>; border: 1px solid <?php echo $ainf['border']; ?>; display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="bi <?php echo $ainf['icono']; ?>"></i> <?php echo $ainf['nombre']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="fw-bold text-white text-truncate" style="max-width: 280px;" title="<?php echo htmlspecialchars($tsv['titulo'] ?? ''); ?>">
                                                            <a href="tickets.php?ver=<?php echo $tsv['id']; ?>" class="ticket-link-row"><?php echo htmlspecialchars($tsv['titulo'] ?? 'Sin título'); ?></a>
                                                        </div>
                                                        <div class="text-secondary small text-truncate" style="max-width: 280px;">
                                                            <?php echo htmlspecialchars(mb_substr($tsv['descripcion'] ?? '', 0, 60)) . '...'; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-white small fw-semibold"><?php echo htmlspecialchars($tsv['solicitante_nombre'] ?? 'Usuario'); ?></div>
                                                        <?php if (!empty($tsv['solicitante_usuario'])): ?>
                                                            <span class="text-secondary font-monospace" style="font-size: 0.75rem;">@<?php echo htmlspecialchars($tsv['solicitante_usuario']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="text-secondary small font-monospace"><?php echo formatearFechaHoraMensaje($tsv['creado_en'] ?? 'now'); ?></span>
                                                    </td>
                                                    <td class="text-end">
                                                        <a href="tickets.php?ver=<?php echo $tsv['id']; ?>" class="btn btn-warning btn-sm fw-bold rounded-pill px-3 shadow-sm text-dark d-inline-flex align-items-center gap-1">
                                                            <i class="bi bi-headset"></i> Atender
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ============================================== -->
                    <!-- GALERÍA 3D COVERFLOW DE AGENCIAS CONECTADAS   -->
                    <!-- ============================================== -->
                    <div class="mb-5">
                        <!-- Barra de Control y Título de la Galería 3D -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <div>
                                <h5 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-boxes text-info"></i> Galería 3D de Sedes Operativas
                                </h5>
                                <span class="text-secondary small">Visualizador interactivo 3D. Haz clic en una tarjeta o usa las flechas para explorar el historial de cada agencia.</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span id="coverflowContadorBadge" class="badge bg-dark text-info border border-info border-opacity-30 rounded-pill px-3 py-2 font-monospace">
                                    Agencia 1 de <?php echo count($agenciasList); ?>
                                </span>
                                <button type="button" id="btnToggleAutoCoverflow" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 fw-bold" onclick="toggleAutoCoverflow()" title="Pausar o reanudar rotación automática">
                                    <i class="bi bi-pause-fill" id="iconAutoCoverflow"></i> <span id="txtAutoCoverflow">Pausar</span>
                                </button>
                            </div>
                        </div>

                        <!-- Escenario 3D Coverflow -->
                        <div class="coverflow-3d-wrapper">
                            <div class="coverflow-3d-stage" id="coverflowStage">
                                <?php 
                                $cardIdx = 0;
                                foreach ($agenciasList as $nomAg => $agInfo): 
                                    $posClass = ($cardIdx === 0) ? 'pos-center' : (($cardIdx === 1) ? 'pos-right' : 'pos-hidden-right');
                                ?>
                                    <div class="coverflow-card <?php echo $posClass; ?>" id="cardCoverflow_<?php echo $cardIdx; ?>" onclick="clickCard3D(<?php echo $cardIdx; ?>, '<?php echo htmlspecialchars(addslashes($nomAg)); ?>')">
                                        <!-- Header de Tarjeta -->
                                        <div>
                                            <div class="d-flex align-items-center justify-content-between mb-3">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div style="width: 44px; height: 44px; border-radius: 12px; background: <?php echo $agInfo['color']; ?>22; color: <?php echo $agInfo['color']; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; border: 1px solid <?php echo $agInfo['color']; ?>44; box-shadow: 0 0 12px <?php echo $agInfo['color']; ?>33;">
                                                        <i class="bi <?php echo $agInfo['icono']; ?>"></i>
                                                    </div>
                                                    <div>
                                                        <span class="text-secondary small fw-bold text-uppercase d-block" style="letter-spacing: 1px; font-size: 0.68rem;">Sede Conectada</span>
                                                        <h4 class="fw-bold text-white mb-0 text-truncate" style="max-width: 170px;" title="<?php echo htmlspecialchars($nomAg); ?>"><?php echo htmlspecialchars($nomAg); ?></h4>
                                                    </div>
                                                </div>
                                                <div>
                                                    <?php if ($agInfo['abiertos'] > 0): ?>
                                                        <span class="badge bg-danger rounded-pill px-2 py-1 small">
                                                            <i class="bi bi-exclamation-circle-fill"></i> <?php echo $agInfo['abiertos']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-30 rounded-pill px-2 py-1 small">
                                                            <i class="bi bi-check2"></i> Al Día
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <span class="font-monospace small px-2 py-1 rounded text-info" style="background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.25); font-size: 0.75rem;">
                                                    <i class="bi bi-link-45deg me-1"></i><?php echo htmlspecialchars($agInfo['cpanel']); ?>
                                                </span>
                                            </div>

                                            <!-- Métricas de Tickets -->
                                            <div class="row g-2 mb-3">
                                                <div class="col-6">
                                                    <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold text-white"><?php echo $agInfo['total']; ?></div>
                                                        <div class="text-secondary small" style="font-size: 0.65rem; letter-spacing: 0.5px;">TOTALES</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div style="background: <?php echo $agInfo['abiertos'] > 0 ? 'rgba(239, 68, 68, 0.15)' : 'rgba(255,255,255,0.04)'; ?>; border: 1px solid <?php echo $agInfo['abiertos'] > 0 ? 'rgba(239, 68, 68, 0.4)' : 'rgba(255,255,255,0.08)'; ?>; border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold <?php echo $agInfo['abiertos'] > 0 ? 'text-danger' : 'text-warning'; ?>"><?php echo $agInfo['abiertos']; ?></div>
                                                        <div class="<?php echo $agInfo['abiertos'] > 0 ? 'text-danger' : 'text-warning'; ?> small fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">SIN ATENDER</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold text-info"><?php echo $agInfo['en_proceso']; ?></div>
                                                        <div class="text-info small" style="font-size: 0.65rem; letter-spacing: 0.5px;">EN PROCESO</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold text-success"><?php echo $agInfo['resueltos']; ?></div>
                                                        <div class="text-success small" style="font-size: 0.65rem; letter-spacing: 0.5px;">RESUELTOS</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Snippet Último Requerimiento -->
                                            <div class="p-2 rounded mb-2" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.05); font-size: 0.72rem; min-height: 48px;">
                                                <?php if ($agInfo['ultimo_ticket']): ?>
                                                    <div class="text-secondary text-truncate" title="<?php echo htmlspecialchars($agInfo['ultimo_ticket']['titulo'] ?? ''); ?>">
                                                        <i class="bi bi-clock-history me-1 text-info"></i>
                                                        <strong class="text-white"><?php echo htmlspecialchars($agInfo['ultimo_ticket']['folio'] ?? ('TK-'.$agInfo['ultimo_ticket']['id'])); ?></strong>:
                                                        <?php echo htmlspecialchars($agInfo['ultimo_ticket']['titulo'] ?? ''); ?>
                                                    </div>
                                                    <div class="text-secondary small" style="font-size: 0.68rem;">
                                                        <?php echo date('d/m/Y H:i', strtotime($agInfo['ultimo_ticket']['creado_en'])); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-secondary fst-italic pt-1">
                                                        <i class="bi bi-info-circle me-1"></i> Sin incidencias registradas.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Botón de Acción -->
                                        <div>
                                            <button type="button" class="btn btn-sm w-100 rounded-pill py-2 fw-bold text-white d-flex align-items-center justify-content-center gap-2" style="background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%); border: 1px solid rgba(56, 189, 248, 0.4); box-shadow: 0 4px 15px rgba(2, 132, 199, 0.35);" onclick="event.stopPropagation(); mostrarHistorialAgencia('<?php echo htmlspecialchars(addslashes($nomAg)); ?>')">
                                                <span>Ver Historial de Tickets</span>
                                                <i class="bi bi-arrow-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php 
                                    $cardIdx++;
                                endforeach; 
                                ?>
                            </div>

                            <!-- Controles Circulares Inferiores (inspirados en la referencia) -->
                            <div class="coverflow-controls">
                                <button type="button" class="coverflow-btn-circle" onclick="cambiar3DCoverflow(-1)" title="Agencia Anterior" aria-label="Anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <button type="button" class="coverflow-btn-circle" onclick="cambiar3DCoverflow(1)" title="Agencia Siguiente" aria-label="Siguiente">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>

                            <!-- Indicadores Dots -->
                            <div class="coverflow-dots">
                                <?php for ($d = 0; $d < count($agenciasList); $d++): ?>
                                    <div class="coverflow-dot <?php echo $d === 0 ? 'active' : ''; ?>" id="coverflowDot_<?php echo $d; ?>" onclick="irA3DCoverflow(<?php echo $d; ?>)" title="Ir a agencia <?php echo ($d + 1); ?>"></div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>

    <!-- ======================================================= -->
    <!-- VISTA 2: HISTORIAL DE TICKETS DE LA AGENCIA SELECCIONADA -->
    <!-- ======================================================= -->
    <div id="vistaHistorialTickets" style="display: none;">
        <!-- Botón de Retorno y Título Dinámico -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-3 border-bottom border-secondary border-opacity-20">
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-secondary text-white rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2" onclick="mostrarVistaAgencias()">
                    <i class="bi bi-arrow-left"></i> <span>Volver a Selección de Agencias</span>
                </button>
                <div>
                    <h4 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history text-info"></i>
                        <span id="tituloHistorialAgencia">Historial de Tickets</span>
                    </h4>
                    <span class="text-secondary small" id="subtituloHistorialAgencia">Auditoría detallada e incidencias reportadas</span>
                </div>
            </div>

            <!-- Mini Recuadros de Métricas de la Agencia Seleccionada -->
            <div class="d-flex flex-wrap gap-2" id="kpisAgenciaSeleccionada">
                <!-- Se actualiza reactivamente vía JS -->
            </div>
        </div>

        <!-- Barra de Filtro Rápido entre Agencias (Pills) -->
        <div class="agencies-monitor-bar mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-funnel-fill text-info"></i>
                    <span class="fw-bold text-white small text-uppercase">Cambiar Agencia:</span>
                </div>
                <button type="button" class="btn btn-link text-info text-decoration-none btn-sm p-0" onclick="mostrarVistaAgencias()">
                    <i class="bi bi-grid-3x3-gap-fill me-1"></i> Ver todas en cuadrícula
                </button>
            </div>
            <div class="d-flex flex-wrap gap-2 pt-1" id="agenciasFilterContainer">
                <button class="agency-pill-btn active" data-agencia="TODAS" onclick="filtrarPorAgencia('TODAS', this)">
                    <i class="bi bi-globe2 text-info"></i>
                    <span>Todas las Agencias</span>
                    <span class="pending-count-badge bg-primary text-white"><?php echo $totalTickets; ?></span>
                </button>
                <?php foreach ($agenciasList as $nomAg => $agInfo): ?>
                    <button class="agency-pill-btn" data-agencia="<?php echo htmlspecialchars($nomAg); ?>" onclick="filtrarPorAgencia('<?php echo htmlspecialchars(addslashes($nomAg)); ?>', this)">
                        <i class="bi <?php echo $agInfo['icono']; ?> text-warning"></i>
                        <span><?php echo htmlspecialchars($nomAg); ?></span>
                        <?php if ($agInfo['abiertos'] > 0): ?>
                            <span class="pending-count-badge bg-danger text-white"><?php echo $agInfo['abiertos']; ?> pend.</span>
                        <?php else: ?>
                            <span class="pending-count-badge bg-secondary text-light"><?php echo $agInfo['total']; ?> tot.</span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Barra de Búsqueda y Acciones de la Mesa de Ayuda -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <!-- Buscador -->
            <div class="search-box-wrapper flex-grow-1" style="max-width: 500px;">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="busquedaInput" class="search-input" placeholder="Buscar por folio, requerimiento, solicitante o técnico..." oninput="filtrarTickets()">
                <button type="button" class="btn btn-link btn-sm text-secondary p-0" onclick="limpiarBusqueda()"><i class="bi bi-x-circle-fill"></i></button>
            </div>

            <!-- Botones de Acción -->
            <div class="d-flex align-items-center gap-2">
                <?php if ($esAdmin && $totalTickets > 0): ?>
                    <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar TODOS los tickets registrados y reiniciar el contador a 0?');" style="display:inline;">
                        <input type="hidden" name="accion" value="vaciar_todos_tickets">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-1" title="Eliminar todos los tickets">
                            <i class="bi bi-trash3-fill"></i> <span>Vaciar Tickets</span>
                        </button>
                    </form>
                <?php endif; ?>
                <a href="tickets.php?export=excel" id="btnExportarExcel" class="btn btn-success btn-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-excel-fill"></i> <span>Exportar a Excel</span>
                </a>
                <button class="btn btn-primary btn-sm rounded-3 px-3 py-2 fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalNuevoTicket">
                    <i class="bi bi-plus-circle-fill"></i> <span>+ Registrar Ticket Manual</span>
                </button>
            </div>
        </div>

        <!-- Chips de Filtrado por Área de Sistemas -->
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
                            $agenciaTicket = normalizarAgencia($t['solicitante_agencia'] ?? 'Desconocida');
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
                                data-solicitante="<?php echo htmlspecialchars(($t['solicitante_nombre'] ?? '') . ' ' . ($t['solicitante_usuario'] ?? '')); ?>"
                                data-asignado="<?php echo htmlspecialchars($asignado); ?>">
                                
                                <td>
                                    <a href="tickets.php?ver=<?php echo $t['id']; ?>" class="ticket-link-row" title="Ver ticket completo">
                                        <span class="badge-folio"><?php echo htmlspecialchars($folio); ?></span>
                                    </a>
                                    <div class="small text-secondary mt-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-clock me-1"></i><?php echo formatearFechaHoraMensaje($t['creado_en'] ?? ''); ?>
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
                                    <div class="fw-bold text-white mb-1">
                                        <a href="tickets.php?ver=<?php echo $t['id']; ?>" class="ticket-link-row" title="Ver ticket completo"><?php echo htmlspecialchars($t['titulo']); ?></a>
                                    </div>
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
                                    <div class="fw-semibold text-white d-flex align-items-center gap-1.5 flex-wrap">
                                        <span><?php echo htmlspecialchars($t['solicitante_nombre'] ?? 'Usuario'); ?></span>
                                        <?php if (!empty($t['solicitante_usuario'])): ?>
                                            <span class="badge bg-dark border text-info font-monospace py-0 px-1" style="font-size: 0.68rem;" title="Usuario del sistema: <?php echo htmlspecialchars($t['solicitante_usuario']); ?>">
                                                @<?php echo htmlspecialchars($t['solicitante_usuario']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-secondary small" style="font-size: 0.73rem;">
                                        <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($t['solicitante_email'] ?? '---'); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($asignado)): ?>
                                        <?php if ($esSuperAdmin): ?>
                                            <button type="button" class="btn btn-sm p-1 px-2.5 rounded-pill d-inline-flex align-items-center gap-1.5 border border-info border-opacity-30 text-info shadow-sm" style="background: rgba(14, 165, 233, 0.12); font-size: 0.78rem;" onclick="abrirModalAgenteSuperAdmin('<?php echo htmlspecialchars(addslashes($asignado)); ?>')" title="Ver Ficha Técnica y Estadísticas del Agente (SuperAdmin)">
                                                <i class="bi bi-person-badge-fill text-warning"></i>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($asignado); ?></span>
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25 px-2 py-1">
                                                <i class="bi bi-person-fill-check me-1"></i> <?php echo htmlspecialchars($asignado); ?>
                                            </span>
                                        <?php endif; ?>
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
                                    <a href="tickets.php?ver=<?php echo $t['id']; ?>" class="btn btn-sm btn-info rounded-3 px-2 py-1 text-dark fw-bold" title="Abrir ticket completo y contestar">
                                        <i class="bi bi-headset me-1"></i> Atender
                                    </a>
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
    </div> <!-- /#vistaHistorialTickets -->
    </div> <!-- /#seccionTab_tickets -->

    <!-- ======================================================= -->
    <!-- SECCIÓN 2: CITAS Y AGENDA DE SOPORTE TI -->
    <!-- ======================================================= -->
    <div id="seccionTab_citas" class="tab-seccion" style="display: none;">
        <!-- Encabezado de Citas -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-30 rounded-pill px-3 py-1 fw-bold">
                        <i class="bi bi-calendar2-check-fill me-1"></i> Agenda de Soporte
                    </span>
                    <span class="text-secondary small">&bull; Visitas Técnicas y Mantenimientos</span>
                </div>
                <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-event text-success"></i> Citas y Visitas Programadas
                </h3>
                <p class="text-secondary mb-0 small">
                    Coordinación de visitas presenciales, mantenimientos preventivos y soporte técnico en sucursales.
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <!-- Selector de Vista: Lista vs Calendario -->
                <div class="citas-view-switcher" role="group" aria-label="Selector de vista de citas">
                    <button type="button" id="btnVistaCitasLista" class="citas-view-btn active" onclick="cambiarVistaModoCitas('lista')">
                        <i class="bi bi-list-task"></i> <span>Vista Lista</span>
                    </button>
                    <button type="button" id="btnVistaCitasCalendario" class="citas-view-btn" onclick="cambiarVistaModoCitas('calendario')">
                        <i class="bi bi-calendar3"></i> <span>Vista Calendario</span>
                    </button>
                </div>

                <button type="button" class="btn btn-success rounded-pill px-3 py-2 fw-bold d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNuevaCita">
                    <i class="bi bi-calendar-plus-fill"></i> <span>+ Agendar Nueva Cita</span>
                </button>
            </div>
        </div>

        <!-- KPIs de Citas -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-cyan"><i class="bi bi-calendar3"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-white"><?php echo $totalCitas; ?></div>
                        <div class="small text-secondary fw-semibold">TOTAL DE CITAS</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-green"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-success"><?php echo $totalCitasProgramadas; ?></div>
                        <div class="small text-secondary fw-semibold">PROGRAMADAS</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-yellow"><i class="bi bi-tools"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-warning"><?php echo $totalCitasEnCurso; ?></div>
                        <div class="small text-secondary fw-semibold">EN ATENCIÓN</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-blue"><i class="bi bi-check-circle-fill"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-info"><?php echo $totalCitasRealizadas; ?></div>
                        <div class="small text-secondary fw-semibold">REALIZADAS</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra de Filtros y Búsqueda de Citas -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3 p-3 rounded-3" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
            <div class="d-flex align-items-center gap-2 overflow-x-auto" id="filtrosEstadoCitas">
                <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 active filter-cita-pill" onclick="filtrarCitasPorEstado('TODAS', this)">Todas (<?php echo $totalCitas; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 filter-cita-pill" onclick="filtrarCitasPorEstado('Programada', this)">Programadas (<?php echo $totalCitasProgramadas; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3 filter-cita-pill" onclick="filtrarCitasPorEstado('En Curso', this)">En Curso (<?php echo $totalCitasEnCurso; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 filter-cita-pill" onclick="filtrarCitasPorEstado('Realizada', this)">Realizadas (<?php echo $totalCitasRealizadas; ?>)</button>
            </div>
            <div class="search-box-wrapper" style="min-width: 280px;">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="busquedaCitasInput" class="search-input" placeholder="Buscar cita por sucursal, técnico, asunto..." oninput="filtrarCitasTexto()">
            </div>
        </div>

        <!-- ============================================== -->
        <!-- VISTA 1: LISTADO TABULAR DE CITAS             -->
        <!-- ============================================== -->
        <div id="vistaCitasLista">
            <div class="tickets-table-card mb-4">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width: 14%;">Folio / Fecha</th>
                                <th style="width: 16%;">Agencia / Sucursal</th>
                                <th style="width: 14%;">Tipo / Área</th>
                                <th style="width: 24%;">Asunto / Objetivo</th>
                                <th style="width: 14%;">Técnico Asignado</th>
                                <th style="width: 10%;">Estado</th>
                                <th style="width: 8%; text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyCitas">
                            <?php if (empty($citas)): ?>
                                <tr id="filaSinCitas">
                                    <td colspan="7" class="text-center py-5 text-secondary">
                                        <i class="bi bi-calendar-x fs-1 d-block mb-2 text-muted"></i>
                                        <div class="fw-semibold">No hay citas de soporte técnico registradas.</div>
                                        <div class="small text-muted mt-1">Presiona <strong>"+ Agendar Nueva Cita"</strong> para programar una visita técnica a una agencia.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($citas as $c): 
                                    $estC = ucfirst(strtolower($c['estado'] ?? 'Programada'));
                                    $badgeClassC = 'badge-status-proceso';
                                    if ($estC === 'Programada') $badgeClassC = 'badge-status-abierto';
                                    elseif ($estC === 'En curso' || $estC === 'En Curso') $badgeClassC = 'badge-status-proceso';
                                    elseif ($estC === 'Realizada' || $estC === 'Completada') $badgeClassC = 'badge-status-resuelto';
                                    elseif ($estC === 'Cancelada') $badgeClassC = 'badge-prio-urgente';
                                ?>
                                    <tr class="cita-row"
                                        data-id="<?php echo $c['id']; ?>"
                                        data-estado="<?php echo htmlspecialchars($estC); ?>"
                                        data-agencia="<?php echo htmlspecialchars($c['agencia'] ?? ''); ?>"
                                        data-asunto="<?php echo htmlspecialchars($c['asunto'] ?? ''); ?>"
                                        data-tecnico="<?php echo htmlspecialchars($c['tecnico_asignado'] ?? ''); ?>"
                                        data-folio="<?php echo htmlspecialchars($c['folio'] ?? ''); ?>">
                                        <td>
                                            <span class="badge-folio"><?php echo htmlspecialchars($c['folio'] ?? ('CTA-' . $c['id'])); ?></span>
                                            <?php if (!empty($c['ticket_folio']) || !empty($c['ticket_id'])): ?>
                                                <a href="tickets.php?ver=<?php echo urlencode($c['ticket_id'] ?: $c['ticket_folio']); ?>" class="badge bg-info bg-opacity-20 text-info border border-info border-opacity-30 text-decoration-none ms-1" style="font-size: 0.68rem;" title="Ver Ticket Asociado">
                                                    <i class="bi bi-ticket-perforated me-1"></i><?php echo htmlspecialchars($c['ticket_folio'] ?: ('TK-' . $c['ticket_id'])); ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($c['motivo_reprogramacion'])): ?>
                                                <span class="badge bg-warning bg-opacity-20 text-warning border border-warning border-opacity-30 ms-1" style="font-size: 0.68rem;" title="<?php echo htmlspecialchars($c['motivo_reprogramacion']); ?>">
                                                    <i class="bi bi-clock-history me-0.5"></i> Reprog.
                                                </span>
                                            <?php endif; ?>
                                            <div class="small text-secondary mt-1" style="font-size: 0.72rem;">
                                                <i class="bi bi-calendar-event me-1"></i><?php echo date('d/m/Y', strtotime($c['fecha_cita'])); ?> &bull; <?php echo htmlspecialchars($c['hora_cita']); ?> hrs
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-agencia">
                                                <i class="bi bi-building"></i> <?php echo htmlspecialchars($c['agencia'] ?? 'General'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-white small"><?php echo htmlspecialchars($c['tipo_cita'] ?? 'Presencial'); ?></div>
                                            <span class="badge bg-dark border text-info" style="font-size: 0.68rem;"><?php echo htmlspecialchars($c['area_sistemas'] ?? 'INFRAESTRUCTURA'); ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-white mb-1"><?php echo htmlspecialchars($c['asunto']); ?></div>
                                            <div class="text-secondary small text-truncate" style="max-width: 320px;">
                                                <?php echo htmlspecialchars($c['descripcion'] ?? 'Sin descripción adicional'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-white small fw-semibold">
                                                <i class="bi bi-person-gear text-info me-1"></i><?php echo htmlspecialchars($c['tecnico_asignado'] ?? 'Sin asignar'); ?>
                                            </div>
                                            <div class="text-secondary small" style="font-size: 0.72rem;">
                                                Solicita: <?php echo htmlspecialchars($c['solicitante_nombre'] ?? '---'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill <?php echo $badgeClassC; ?> px-2.5 py-1" style="font-size: 0.75rem;">
                                                <?php echo $estC; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1.5">
                                                <button type="button" class="btn btn-outline-info btn-sm rounded-3 py-1 px-2" onclick="abrirModalActualizarCita(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)" title="Atender / Modificar Cita">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <?php if ($esAdmin): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que deseas eliminar esta cita?');">
                                                        <input type="hidden" name="accion" value="eliminar_cita">
                                                        <input type="hidden" name="cita_id" value="<?php echo $c['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm border-0 py-1 px-2" title="Eliminar Cita">
                                                            <i class="bi bi-trash"></i>
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

        <!-- ============================================== -->
        <!-- VISTA 2: CALENDARIO MENSUAL DE CITAS           -->
        <!-- ============================================== -->
        <div id="vistaCitasCalendario" style="display: none;">
            <div class="calendar-card mb-4">
                <!-- Barra Superior de Navegación del Mes -->
                <div class="calendar-toolbar">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="calendar-nav-btn" onclick="cambiarMesCalendario(-1)" title="Mes Anterior" aria-label="Mes Anterior">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div id="calendarMonthTitle" class="calendar-month-title">Mes Año</div>
                        <button type="button" class="calendar-nav-btn" onclick="cambiarMesCalendario(1)" title="Mes Siguiente" aria-label="Mes Siguiente">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 ms-2 fw-bold" onclick="irAHoyCalendario()">
                            <i class="bi bi-calendar-check me-1"></i> Hoy
                        </button>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="d-flex align-items-center gap-3 small text-secondary">
                            <span class="d-inline-flex align-items-center gap-1.5"><span style="width: 9px; height: 9px; border-radius: 50%; background: #38bdf8; display: inline-block;"></span> Programada</span>
                            <span class="d-inline-flex align-items-center gap-1.5"><span style="width: 9px; height: 9px; border-radius: 50%; background: #f59e0b; display: inline-block;"></span> En Curso</span>
                            <span class="d-inline-flex align-items-center gap-1.5"><span style="width: 9px; height: 9px; border-radius: 50%; background: #10b981; display: inline-block;"></span> Realizada</span>
                        </div>
                        <span id="calendarCitasMonthCount" class="badge bg-dark text-info border border-info border-opacity-30 rounded-pill px-3 py-2 font-monospace">
                            0 Citas este mes
                        </span>
                    </div>
                </div>

                <!-- Fila de Encabezados de Días de la Semana -->
                <div class="calendar-weekdays-row">
                    <div class="calendar-weekday">Lunes</div>
                    <div class="calendar-weekday">Martes</div>
                    <div class="calendar-weekday">Miércoles</div>
                    <div class="calendar-weekday">Jueves</div>
                    <div class="calendar-weekday">Viernes</div>
                    <div class="calendar-weekday">Sábado</div>
                    <div class="calendar-weekday">Domingo</div>
                </div>

                <!-- Cuadrícula Dinámica de Días y Citas del Mes -->
                <div class="calendar-grid-days" id="calendarGridDays">
                    <!-- Renderizado dinámicamente con JavaScript -->
                </div>
            </div>
        </div>
    </div> <!-- /#seccionTab_citas -->

    <!-- ======================================================= -->
    <!-- SECCIÓN 3: ESTADÍSTICAS Y ANALÍTICA TI -->
    <!-- ======================================================= -->
    <div id="seccionTab_estadisticas" class="tab-seccion" style="display: none;">
        <!-- Encabezado de Estadísticas -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary bg-opacity-20 text-info border border-info border-opacity-30 rounded-pill px-3 py-1 fw-bold">
                        <i class="bi bi-graph-up-arrow me-1"></i> Inteligencia Operativa
                    </span>
                    <span class="text-secondary small">&bull; Rendimiento de Mesa de Ayuda</span>
                </div>
                <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-bar-chart-line text-primary"></i> Métricas y Estadísticas de Sistemas
                </h3>
                <p class="text-secondary mb-0 small">
                    Análisis consolidado de incidencias por área, volumen por agencia y eficacia en tiempo de atención.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="tickets.php?export=excel" class="btn btn-outline-success rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-excel-fill"></i> <span>Exportar Datos (Excel)</span>
                </a>
            </div>
        </div>

        <!-- KPIs Ejecutivos de Estadísticas -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-cyan"><i class="bi bi-collection-fill"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-white"><?php echo $totalTickets; ?></div>
                        <div class="small text-secondary fw-semibold">TOTAL INCIDENCIAS</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-green"><i class="bi bi-shield-check"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-success"><?php echo $tasaResolucion; ?>%</div>
                        <div class="small text-secondary fw-semibold">TASA DE RESOLUCIÓN</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-yellow"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-warning"><?php echo $totalAbiertos; ?></div>
                        <div class="small text-secondary fw-semibold">TICKETS SIN ATENDER</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-blue"><i class="bi bi-calendar2-week"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-info"><?php echo $totalCitas; ?></div>
                        <div class="small text-secondary fw-semibold">CITAS Y VISITAS TI</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fila de Gráficas de Rendimiento (Chart.js) -->
        <div class="row g-4 mb-4">
            <!-- Gráfica 1: Tickets por Estado -->
            <div class="col-lg-4">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-pie-chart-fill text-info me-2"></i> Estado de Requerimientos</h6>
                        <span class="badge bg-dark text-secondary border border-secondary" style="font-size: 0.7rem;">Tiempo Real</span>
                    </div>
                    <div style="height: 250px; position: relative;">
                        <canvas id="chartTicketsEstado"></canvas>
                    </div>
                </div>
            </div>

            <!-- Gráfica 2: Tickets por Área -->
            <div class="col-lg-4">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-bar-chart-fill text-warning me-2"></i> Carga por Área de Sistemas</h6>
                        <span class="badge bg-dark text-info border border-info border-opacity-25" style="font-size: 0.7rem;"><?php echo count($AREAS_SISTEMAS); ?> <?php echo count($AREAS_SISTEMAS) === 1 ? 'Área Asignada' : 'Áreas Asignadas'; ?></span>
                    </div>
                    <div style="height: 250px; position: relative;">
                        <canvas id="chartTicketsArea"></canvas>
                    </div>
                </div>
            </div>

            <!-- Gráfica 3: Tickets por Agencia -->
            <div class="col-lg-4">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-buildings-fill text-success me-2"></i> Demanda por Sucursal</h6>
                        <span class="badge bg-dark text-secondary border border-secondary" style="font-size: 0.7rem;">Agencias</span>
                    </div>
                    <div style="height: 250px; position: relative;">
                        <canvas id="chartTicketsAgencia"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla Analítica de Áreas y Agencias -->
        <div class="row g-4 mb-4">
            <!-- Desglose por Área -->
            <div class="col-lg-6">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-diagram-3-fill text-info me-2"></i> Desglose Detallado por Área TI</h6>
                        <span class="badge bg-dark text-secondary border border-secondary" style="font-size: 0.7rem;"><?php echo count($AREAS_SISTEMAS); ?> Visibles</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Área Asignada</th>
                                    <th class="text-center">Tickets</th>
                                    <th>% del Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($AREAS_SISTEMAS)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-secondary py-3">No tienes áreas de sistemas asignadas a tu usuario.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($AREAS_SISTEMAS as $ak => $ainf): 
                                        $cnt = $conteoPorArea[$ak] ?? 0;
                                        $pct = $totalTickets > 0 ? round(($cnt / $totalTickets) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <span style="font-size: 0.78rem; font-weight: 700; padding: 4px 10px; border-radius: 12px; background: <?php echo $ainf['bg']; ?>; color: <?php echo $ainf['color']; ?>; border: 1px solid <?php echo $ainf['border']; ?>; display: inline-flex; align-items: center; gap: 6px;">
                                                    <i class="bi <?php echo $ainf['icono']; ?>"></i> <?php echo $ainf['nombre']; ?>
                                                </span>
                                            </td>
                                            <td class="text-center fw-bold text-white"><?php echo $cnt; ?></td>
                                            <td style="width: 40%;">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 6px; background: rgba(255,255,255,0.08);">
                                                        <div class="progress-bar" style="width: <?php echo $pct; ?>%; background: <?php echo $ainf['color']; ?>;"></div>
                                                    </div>
                                                    <span class="small font-monospace text-secondary" style="font-size: 0.75rem; width: 45px;"><?php echo $pct; ?>%</span>
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

            <!-- Desglose por Agencia -->
            <div class="col-lg-6">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <h6 class="fw-bold text-white mb-3"><i class="bi bi-building-check text-success me-2"></i> Desglose por Agencia / Sucursal</h6>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center text-warning">Sin Atender</th>
                                    <th class="text-center text-success">Resueltos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agenciasList as $agN => $agD): ?>
                                    <tr>
                                        <td>
                                            <span class="badge-agencia"><i class="bi <?php echo $agD['icono']; ?>"></i> <?php echo htmlspecialchars($agN); ?></span>
                                        </td>
                                        <td class="text-center fw-bold text-white"><?php echo $agD['total']; ?></td>
                                        <td class="text-center fw-bold text-warning"><?php echo $agD['abiertos']; ?></td>
                                        <td class="text-center fw-bold text-success"><?php echo $agD['resueltos']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- /#seccionTab_estadisticas -->

    <?php if ($esSuperAdmin): ?>
    <!-- ======================================================= -->
    <!-- SECCIÓN 4: AGENTES Y ESPECIALISTAS TI (SOLO SUPERADMIN) -->
    <!-- ======================================================= -->
    <div id="seccionTab_agentes" class="tab-seccion" style="display: none;">
        <!-- Encabezado de Agentes -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-warning bg-opacity-20 text-warning border border-warning border-opacity-30 rounded-pill px-3 py-1 fw-bold">
                        <i class="bi bi-shield-lock-fill me-1"></i> Control SuperAdmin
                    </span>
                    <span class="text-secondary small">&bull; Directorio de Mesa de Ayuda TI</span>
                </div>
                <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-people-fill text-warning"></i> Agentes y Especialistas TI
                </h3>
                <p class="text-secondary mb-0 small">
                    Directorio del personal con acceso al módulo de tickets y áreas de atención asignadas. Administra su fotografía y audita su productividad.
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-dark border border-secondary text-warning px-3 py-2 fs-6 rounded-3 shadow-sm">
                    <i class="bi bi-person-check-fill me-1 text-success"></i> <?php echo count($agentesTickets); ?> Agentes Habilitados
                </span>
            </div>
        </div>

        <!-- Barra de búsqueda rápida de agentes -->
        <div class="ticket-card p-3 mb-4" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255, 255, 255, 0.08);">
            <div class="row g-2 align-items-center">
                <div class="col-md-7 col-lg-8">
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary border-opacity-50 text-secondary">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="filtroAgentesInput" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Buscar especialista por nombre, @usuario, correo o área asignada..." oninput="filtrarAgentesGrid()">
                        <button type="button" class="btn btn-outline-secondary text-secondary border-secondary border-opacity-50" onclick="document.getElementById('filtroAgentesInput').value=''; filtrarAgentesGrid();" title="Limpiar búsqueda">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-5 col-lg-4 text-md-end text-secondary small">
                    <i class="bi bi-info-circle text-info me-1"></i> Agentes con permisos en tickets y áreas asignadas.
                </div>
            </div>
        </div>

        <!-- Grid de Agentes -->
        <?php if (empty($agentesTickets)): ?>
            <div class="ticket-card text-center py-5">
                <i class="bi bi-people text-secondary opacity-50 d-block mb-3" style="font-size: 3.5rem;"></i>
                <h5 class="fw-bold text-white mb-2">No se encontraron agentes configurados</h5>
                <p class="text-secondary small mb-3" style="max-width: 500px; margin: 0 auto;">
                    Para que los usuarios aparezcan en este listado, deben tener acceso al módulo de tickets y al menos un área de Sistemas asignada.
                </p>
                <a href="usuarios.php" class="btn btn-warning btn-sm rounded-pill px-4 fw-bold">
                    <i class="bi bi-person-plus-fill me-1"></i> Gestionar en Módulo de Usuarios
                </a>
            </div>
        <?php else: ?>
            <div class="row g-3" id="gridAgentesTickets">
                <?php foreach ($agentesTickets as $ag): 
                    $agId = (int)($ag['id'] ?? 0);
                    $agNombre = !empty($ag['nombre']) ? $ag['nombre'] : $ag['usuario'];
                    $agUser = $ag['usuario'] ?? '';
                    $agFoto = $ag['foto'] ?? '';
                    $agCargo = !empty($ag['cargo']) ? $ag['cargo'] : 'Especialista en Soporte TI';
                    $agEmail = $ag['email'] ?? '';
                    $agTel = $ag['telefono'] ?? '';
                    $agAgencia = !empty($ag['agencia']) ? $ag['agencia'] : 'Dirección General TI';
                    $agRol = strtoupper($ag['rol'] ?? 'AGENTE');
                    $agAreasRaw = trim($ag['areas_tickets'] ?? '');

                    // Analizar áreas asignadas
                    $esAccesoTotalAreas = ($agRol === 'SUPERADMIN' || strtoupper($agAreasRaw) === 'TODOS' || $agAreasRaw === '*');
                    $agAreasList = [];
                    if (!$esAccesoTotalAreas && !empty($agAreasRaw)) {
                        $partes = array_map('trim', explode(',', strtoupper($agAreasRaw)));
                        foreach ($partes as $p) {
                            if (isset($AREAS_SISTEMAS_CATALOGO[$p])) {
                                $agAreasList[] = $p;
                            }
                        }
                    }

                    // Métricas de tickets para este agente
                    $stAg = obtenerEstadisticasAgente($pdo, $agNombre, $agUser);
                    $totalResueltos = ($stAg['resueltos'] ?? 0) + ($stAg['cerrados'] ?? 0);
                    $tasaEfectiva = $stAg['efectividad'] ?? 0;
                    $tieneFotoValida = (!empty($agFoto) && file_exists(__DIR__ . '/' . $agFoto));
                ?>
                    <div class="col-12 col-md-6 col-xl-4 agente-card-item" 
                         data-nombre="<?php echo htmlspecialchars(strtolower($agNombre)); ?>" 
                         data-usuario="<?php echo htmlspecialchars(strtolower($agUser)); ?>" 
                         data-email="<?php echo htmlspecialchars(strtolower($agEmail)); ?>" 
                         data-areas="<?php echo htmlspecialchars(strtolower($agAreasRaw)); ?>" 
                         data-cargo="<?php echo htmlspecialchars(strtolower($agCargo)); ?>">
                        <div class="ticket-card p-4 h-100 d-flex flex-column" style="background: linear-gradient(180deg, rgba(30, 41, 59, 0.45) 0%, rgba(15, 23, 42, 0.95) 100%); border: 1px solid rgba(255, 255, 255, 0.08); border-top: 3.5px solid #f59e0b; border-radius: 14px;">
                            
                            <!-- Header de la Tarjeta: Avatar y Datos Principales -->
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="position-relative flex-shrink-0">
                                    <?php if ($tieneFotoValida): ?>
                                        <img src="<?php echo htmlspecialchars($agFoto); ?>" alt="Foto <?php echo htmlspecialchars($agNombre); ?>" class="rounded-circle shadow" style="width: 66px; height: 66px; object-fit: cover; border: 2.5px solid #f59e0b;">
                                    <?php else: ?>
                                        <div class="rounded-circle d-flex align-items-center justify-content-center shadow" style="width: 66px; height: 66px; background: linear-gradient(135deg, #1e293b, #0f172a); border: 2.5px solid #f59e0b; color: #f59e0b; font-size: 1.7rem;">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-warning btn-sm position-absolute bottom-0 end-0 rounded-circle p-0 d-flex align-items-center justify-content-center shadow" style="width: 24px; height: 24px; font-size: 0.72rem;" onclick="abrirModalAgenteSuperAdmin('<?php echo htmlspecialchars(addslashes($agNombre)); ?>')" title="Asignar o cambiar foto del agente">
                                        <i class="bi bi-camera-fill text-dark"></i>
                                    </button>
                                </div>
                                <div class="overflow-hidden flex-grow-1">
                                    <div class="fw-bold text-white fs-6 text-truncate" title="<?php echo htmlspecialchars($agNombre); ?>">
                                        <?php echo htmlspecialchars($agNombre); ?>
                                    </div>
                                    <div class="text-warning small text-truncate fw-semibold" style="font-size: 0.78rem;">
                                        <i class="bi bi-briefcase me-1"></i><?php echo htmlspecialchars($agCargo); ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1.5 flex-wrap mt-1">
                                        <span class="badge bg-secondary bg-opacity-40 text-info font-monospace" style="font-size: 0.7rem;">
                                            @<?php echo htmlspecialchars($agUser); ?>
                                        </span>
                                        <span class="badge <?php echo $agRol === 'SUPERADMIN' ? 'bg-warning text-dark fw-bold' : ($agRol === 'ADMIN' ? 'bg-primary bg-opacity-30 text-info border border-info border-opacity-30' : 'bg-secondary bg-opacity-30 text-light'); ?>" style="font-size: 0.65rem;">
                                            <?php echo htmlspecialchars($agRol); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Información de Contacto y Sucursal -->
                            <div class="p-2.5 rounded-3 mb-3 border border-secondary border-opacity-25" style="background: rgba(255, 255, 255, 0.02); font-size: 0.76rem;">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="text-secondary"><i class="bi bi-building me-1 text-info"></i> Agencia:</span>
                                    <span class="text-light fw-semibold text-truncate ms-2" style="max-width: 170px;" title="<?php echo htmlspecialchars($agAgencia); ?>">
                                        <?php echo htmlspecialchars($agAgencia); ?>
                                    </span>
                                </div>
                                <?php if (!empty($agEmail)): ?>
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="text-secondary"><i class="bi bi-envelope me-1 text-info"></i> Correo:</span>
                                        <a href="mailto:<?php echo htmlspecialchars($agEmail); ?>" class="text-info text-decoration-none text-truncate ms-2" style="max-width: 170px;" title="<?php echo htmlspecialchars($agEmail); ?>">
                                            <?php echo htmlspecialchars($agEmail); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($agTel)): ?>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="text-secondary"><i class="bi bi-telephone me-1 text-success"></i> Tel / Ext:</span>
                                        <span class="text-white fw-semibold"><?php echo htmlspecialchars($agTel); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Áreas de Atención Asignadas -->
                            <div class="mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-1.5">
                                    <span class="text-secondary small fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">ÁREAS ASIGNADAS:</span>
                                    <?php if ($esAccesoTotalAreas): ?>
                                        <span class="badge bg-warning bg-opacity-20 text-warning border border-warning border-opacity-30" style="font-size: 0.65rem;">Acceso Total</span>
                                    <?php else: ?>
                                        <span class="badge bg-dark text-secondary border border-secondary" style="font-size: 0.65rem;"><?php echo count($agAreasList); ?> Áreas</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php if ($esAccesoTotalAreas): ?>
                                        <span class="badge rounded-pill bg-warning bg-opacity-25 text-warning border border-warning border-opacity-40 px-2 py-1" style="font-size: 0.72rem;">
                                            <i class="bi bi-stars me-1"></i> Todas las Áreas de Sistemas
                                        </span>
                                    <?php elseif (empty($agAreasList)): ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size: 0.72rem;">
                                            <i class="bi bi-slash-circle me-1"></i> Sin áreas específicas
                                        </span>
                                    <?php else: ?>
                                        <?php foreach ($agAreasList as $ak): 
                                            $infA = $AREAS_SISTEMAS_CATALOGO[$ak] ?? null;
                                        ?>
                                            <span class="badge rounded-pill px-2 py-1" style="background: <?php echo $infA['bg'] ?? 'rgba(56, 189, 248, 0.18)'; ?>; color: <?php echo $infA['color'] ?? '#38bdf8'; ?>; border: 1px solid <?php echo $infA['border'] ?? 'rgba(56, 189, 248, 0.4)'; ?>; font-size: 0.7rem;">
                                                <i class="bi <?php echo $infA['icono'] ?? 'bi-tag'; ?> me-1"></i><?php echo htmlspecialchars($ak); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Métricas Rápidas y Rendimiento -->
                            <div class="mb-3">
                                <div class="row g-1 text-center mb-2">
                                    <div class="col-4">
                                        <div class="p-2 rounded-2" style="background: rgba(14, 165, 233, 0.08); border: 1px solid rgba(56, 189, 248, 0.2);">
                                            <div class="text-secondary" style="font-size: 0.65rem;">ASIGNADOS</div>
                                            <div class="fw-bold text-info fs-6"><?php echo (int)($stAg['total'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-2 rounded-2" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2);">
                                            <div class="text-secondary" style="font-size: 0.65rem;">EN ATENCIÓN</div>
                                            <div class="fw-bold text-warning fs-6"><?php echo (int)($stAg['en_proceso'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-2 rounded-2" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2);">
                                            <div class="text-secondary" style="font-size: 0.65rem;">RESUELTOS</div>
                                            <div class="fw-bold text-success fs-6"><?php echo $totalResueltos; ?></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Barra de Efectividad -->
                                <div>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="text-secondary" style="font-size: 0.7rem;">Tasa de Efectividad:</span>
                                        <strong class="text-success" style="font-size: 0.72rem;"><?php echo $tasaEfectiva; ?>%</strong>
                                    </div>
                                    <div class="progress" style="height: 6px; background: rgba(255, 255, 255, 0.08); border-radius: 4px;">
                                        <div class="progress-bar bg-success progress-bar-striped" role="progressbar" style="width: <?php echo min(100, $tasaEfectiva); ?>%;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Botones de Acción de la Tarjeta -->
                            <div class="mt-auto pt-3 border-top border-secondary border-opacity-25 d-flex gap-2">
                                <button type="button" class="btn btn-outline-warning btn-sm w-50 rounded-3 fw-bold d-flex align-items-center justify-content-center gap-1.5 shadow-sm" onclick="abrirModalAgenteSuperAdmin('<?php echo htmlspecialchars(addslashes($agNombre)); ?>')" title="Asignar o cambiar foto de perfil">
                                    <i class="bi bi-camera-fill"></i> Foto
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm w-50 rounded-3 fw-bold d-flex align-items-center justify-content-center gap-1.5 shadow-sm" onclick="abrirModalAgenteSuperAdmin('<?php echo htmlspecialchars(addslashes($agNombre)); ?>')" title="Ver estadísticas completas, historial de tickets y citas">
                                    <i class="bi bi-bar-chart-fill"></i> Estadísticas
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div> <!-- /#seccionTab_agentes -->
    <?php endif; ?>

</div> <!-- /.main-container -->
</div> <!-- /.app-content -->
</div> <!-- /.app-layout -->

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
                            <select name="solicitante_agencia" id="form_solicitante_agencia" class="form-select" required>
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
            <form method="POST" enctype="multipart/form-data">
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

                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold d-flex align-items-center justify-content-between mb-1">
                                <span><i class="bi bi-paperclip text-info me-1"></i> Evidencia (PDF, fotos, Excel, etc.)</span>
                                <span class="badge bg-secondary bg-opacity-25 text-light" style="font-size: 0.68rem;">Opcional</span>
                            </label>
                            <input type="file" name="archivo_evidencia" class="form-control" accept=".pdf,image/*,.xls,.xlsx,.csv,.doc,.docx,.txt,.zip">
                            <div class="form-text text-secondary" style="font-size: 0.72rem;">
                                Adjunta comprobantes, reportes PDF, capturas de pantalla o archivos de Excel de la atención.
                            </div>
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

<!-- Modal Nueva Cita Técnica -->
<div class="modal fade" id="modalNuevaCita" tabindex="-1" aria-labelledby="modalNuevaCitaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="background: #1e293b; color: #f8fafc; border-radius: 16px;">
            <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                <h5 class="modal-title fw-bold text-white" id="modalNuevaCitaLabel">
                    <i class="bi bi-calendar-plus text-primary me-2"></i> Agendar Nueva Cita Técnica / Soporte
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="tickets.php" method="POST" id="formNuevaCita">
                <input type="hidden" name="accion" value="agendar_cita">
                <input type="hidden" name="origen_ticket_id" id="citaOrigenTicketId" value="">
                <input type="hidden" name="ticket_folio" id="citaTicketFolio" value="">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Selección de Ticket Vinculado (Opcional) -->
                        <div class="col-12">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-40" style="background: rgba(14, 165, 233, 0.05);">
                                <label class="form-label text-info small fw-bold d-flex align-items-center justify-content-between mb-1">
                                    <span><i class="bi bi-ticket-perforated-fill me-1"></i> Ticket de Soporte Asociado (Opcional)</span>
                                    <span class="badge bg-info bg-opacity-25 text-info font-monospace" style="font-size: 0.68rem;">Con o Sin Ticket</span>
                                </label>
                                <select name="ticket_id" id="citaTicketSelect" class="form-select bg-dark text-white border-secondary border-opacity-50" onchange="alSeleccionarTicketParaCita(this.value)">
                                    <option value="">-- Cita General de Agencia (Sin ticket vinculado) --</option>
                                    <?php foreach ($tickets as $tOpc): ?>
                                        <option value="<?php echo $tOpc['id']; ?>"
                                            data-folio="<?php echo htmlspecialchars($tOpc['folio']); ?>"
                                            data-titulo="<?php echo htmlspecialchars($tOpc['titulo']); ?>"
                                            data-agencia="<?php echo htmlspecialchars($tOpc['solicitante_agencia'] ?? ''); ?>"
                                            data-area="<?php echo htmlspecialchars($tOpc['area_sistemas'] ?? 'INFRAESTRUCTURA'); ?>"
                                            data-solicitante="<?php echo htmlspecialchars($tOpc['solicitante_nombre'] ?? ''); ?>"
                                            data-email="<?php echo htmlspecialchars($tOpc['solicitante_email'] ?? ''); ?>"
                                            data-desc="<?php echo htmlspecialchars($tOpc['descripcion'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(($tOpc['folio'] ?: ('TK-' . $tOpc['id'])) . ' - ' . $tOpc['titulo'] . ' (' . ($tOpc['solicitante_agencia'] ?? 'Agencia') . ' | Estado: ' . $tOpc['estado'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text text-secondary mt-1" style="font-size: 0.74rem;">
                                    <i class="bi bi-info-circle me-1 text-info"></i> Puedes vincular un ticket para notificar al solicitante, o dejarlo sin ticket para una cita general que recibirá el Administrador de la agencia.
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Agencia o Sucursal Destino *</label>
                            <select name="agencia" id="citaAgencia" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <option value="">-- Seleccionar Agencia --</option>
                                <?php foreach ($agenciasList as $nomAg => $infoAg): ?>
                                    <option value="<?php echo htmlspecialchars($nomAg); ?>"><?php echo htmlspecialchars($nomAg); ?></option>
                                <?php endforeach; ?>
                                <option value="Oficina Central Grupo Huerta">Oficina Central Grupo Huerta</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Tipo de Cita / Visita *</label>
                            <select name="tipo_cita" id="citaTipoCita" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <option value="Visita Técnica Presencial">Visita Técnica Presencial</option>
                                <option value="Mantenimiento Preventivo">Mantenimiento Preventivo</option>
                                <option value="Revisión de Red / Servidores">Revisión de Red / Servidores</option>
                                <option value="Reunión de Sistemas">Reunión de Sistemas</option>
                                <option value="Soporte Remoto Agendado">Soporte Remoto Agendado</option>
                                <option value="Auditoría Informática">Auditoría Informática</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Fecha de la Cita *</label>
                            <input type="date" name="fecha_cita" id="modalNuevaCita_fecha" class="form-control bg-dark text-white border-secondary border-opacity-50" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Hora de la Cita</label>
                            <input type="time" name="hora_cita" id="citaHora" class="form-control bg-dark text-white border-secondary border-opacity-50" value="10:00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Área de Sistemas *</label>
                            <select name="area_sistemas" id="citaAreaSistemas" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <?php foreach ($AREAS_SISTEMAS as $kArea => $ainfo): ?>
                                    <option value="<?php echo $kArea; ?>"><?php echo htmlspecialchars($ainfo['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Técnico / Ingeniero Asignado</label>
                            <input type="text" name="tecnico_asignado" id="citaTecnico" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Ej. Ing. Daniel / Soporte Central" value="<?php echo htmlspecialchars($nombreUsuario); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Asunto de la Cita *</label>
                            <input type="text" name="asunto" id="citaAsunto" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Ej. Atención TK-XXXX: Mantenimiento general" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Descripción detallada / Objetivos</label>
                            <textarea name="descripcion" id="citaDescripcion" class="form-control bg-dark text-white border-secondary border-opacity-50" rows="3" placeholder="Detalles de la visita, equipos a revisar o requerimientos..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Contacto / Solicitante en Agencia</label>
                            <input type="text" name="solicitante_nombre" id="citaSolNombre" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Nombre de quien recibe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Correo de Contacto</label>
                            <input type="email" name="solicitante_email" id="citaSolEmail" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="correo@agencia.com">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold">
                        <i class="bi bi-calendar-check me-1"></i> Agendar Cita
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Actualizar Cita -->
<div class="modal fade" id="modalActualizarCita" tabindex="-1" aria-labelledby="modalActualizarCitaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="background: #1e293b; color: #f8fafc; border-radius: 16px;">
            <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                <h5 class="modal-title fw-bold text-white" id="modalActualizarCitaLabel">
                    <i class="bi bi-pencil-square text-warning me-2"></i> Actualizar Cita <span id="citaActualizarFolio" class="badge bg-secondary ms-2"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="tickets.php" method="POST" id="formActualizarCita" onsubmit="return validarActualizarCita(this);">
                <input type="hidden" name="accion" value="actualizar_cita">
                <input type="hidden" name="cita_id" id="citaActualizarId">
                <input type="hidden" name="origen_ticket_id" id="citaActualizarOrigenTicketId" value="">
                <input type="hidden" id="citaOriginalFecha" value="">
                <input type="hidden" id="citaOriginalHora" value="">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <div class="text-secondary small fw-bold text-uppercase">Agencia / Sucursal</div>
                        <div id="citaActualizarAgencia" class="fw-bold fs-6 text-info"></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-secondary small fw-bold text-uppercase">Asunto / Ticket Vinculado</div>
                        <div id="citaActualizarAsunto" class="fw-semibold text-white"></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Estado de la Cita *</label>
                            <select name="estado" id="citaActualizarEstado" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <option value="Programada">Programada</option>
                                <option value="En Curso">En Curso</option>
                                <option value="Realizada">Realizada</option>
                                <option value="Cancelada">Cancelada</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Fecha</label>
                            <input type="date" name="fecha_cita" id="citaActualizarFecha" class="form-control bg-dark text-white border-secondary border-opacity-50" oninput="verificarCambioFechaHoraCita()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Hora</label>
                            <input type="time" name="hora_cita" id="citaActualizarHora" class="form-control bg-dark text-white border-secondary border-opacity-50" oninput="verificarCambioFechaHoraCita()">
                        </div>

                        <!-- Campo obligatorio si se cambia fecha u hora -->
                        <div class="col-12" id="contenedorMotivoCambioCita" style="display: none;">
                            <div class="p-3 rounded-3 border border-warning" style="background: rgba(245, 158, 11, 0.15);">
                                <label class="form-label text-warning small fw-bold d-flex align-items-center gap-1 mb-1">
                                    <i class="bi bi-exclamation-triangle-fill fs-6"></i> Motivo de Reprogramación (Obligatorio) *
                                </label>
                                <div class="text-secondary small mb-2" style="font-size: 0.76rem;">
                                    Has modificado la fecha u hora original. Por política del departamento de TI, es obligatorio justificar el motivo del cambio:
                                </div>
                                <textarea name="motivo_cambio" id="citaMotivoCambio" class="form-control bg-dark text-white border-warning" rows="2" placeholder="Explica la causa del cambio (ej: Solicitud del usuario, retraso de proveedor, reprogramación técnica)..."></textarea>
                            </div>
                        </div>

                        <div class="col-12" id="citaHistorialReprogSec" style="display: none;">
                            <div class="p-2.5 rounded-3 border border-secondary border-opacity-30" style="background: rgba(15, 23, 42, 0.6);">
                                <div class="text-secondary small fw-bold mb-1"><i class="bi bi-clock-history text-info me-1"></i> Historial de Cambios:</div>
                                <div id="citaHistorialReprogTexto" class="text-secondary font-monospace small" style="font-size: 0.72rem; white-space: pre-wrap; max-height: 80px; overflow-y: auto;"></div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Técnico Asignado</label>
                            <input type="text" name="tecnico_asignado" id="citaActualizarTecnico" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Nombre del técnico">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Notas de Atención / Resultados</label>
                            <textarea name="notas_atencion" id="citaActualizarNotas" class="form-control bg-dark text-white border-secondary border-opacity-50" rows="3" placeholder="Resultados de la visita, observaciones técnicas o pendientes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-warning rounded-3 px-4 fw-bold text-dark">
                        <i class="bi bi-save me-1"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================= -->
<!-- MODAL FORMATO / REPORTE DE SERVICIO TÉCNICO (INFRAESTRUCTURA) -->
<!-- ======================================================= -->
<?php if ($ticketActivo && strtoupper(trim($ticketActivo['area_sistemas'] ?? '')) === 'INFRAESTRUCTURA'): 
    $repOrdenNo = !empty($ticketActivo['reporte_orden_no']) ? $ticketActivo['reporte_orden_no'] : ($ticketActivo['folio'] ?? ('TK-' . $ticketActivo['id']));
    $repProblema = !empty($ticketActivo['reporte_problema']) ? $ticketActivo['reporte_problema'] : ($ticketActivo['descripcion'] ?? '');
    $repTrabajoSol = $ticketActivo['reporte_trabajo_solicitado'] ?? '';
    $repObservaciones = $ticketActivo['reporte_observaciones'] ?? '';
    $repTrabajosReal = $ticketActivo['reporte_trabajos_realizados'] ?? '';
    $repTecnico = !empty($ticketActivo['reporte_tecnico_nombre']) ? $ticketActivo['reporte_tecnico_nombre'] : (!empty($ticketActivo['asignado_a']) ? $ticketActivo['asignado_a'] : $nombreUsuario);
    $repFirmado = !empty($ticketActivo['reporte_firmado']) || !empty($ticketActivo['reporte_firma_digital']);
    $repFirmaImg = $ticketActivo['reporte_firma_digital'] ?? '';
    $repFirmadoPor = $ticketActivo['reporte_firmado_por'] ?? '';
    $repFirmadoEn = $ticketActivo['reporte_firmado_en'] ?? '';

    $logoIzqPath = __DIR__ . '/uploads/reporte_logo_izq.png';
    $logoDerPath = __DIR__ . '/uploads/reporte_logo_der.png';
    $logoIzqSrc  = file_exists($logoIzqPath) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logoIzqPath))) : 'uploads/reporte_logo_izq.png';
    $logoDerSrc  = file_exists($logoDerPath) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logoDerPath))) : 'uploads/reporte_logo_der.png';
?>
<div class="modal fade" id="modalReporteServicio" tabindex="-1" aria-labelledby="modalReporteServicioLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="background: #040d1a; border: 1px solid rgba(56, 189, 248, 0.35); box-shadow: 0 25px 60px rgba(0,0,0,0.85); color: #fff;">
            
            <div class="modal-header border-bottom border-secondary border-opacity-25" style="background: rgba(14, 165, 233, 0.08);">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-info text-dark fw-bold px-2.5 py-1.5"><i class="bi bi-hdd-rack me-1"></i> INFRAESTRUCTURA</span>
                    <h5 class="modal-title fw-bold text-white mb-0" id="modalReporteServicioLabel">
                        Formato Oficial de Reporte de Servicio Técnico
                    </h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary bg-opacity-40 text-light font-monospace px-3 py-1.5">
                        ORDEN NO: <strong class="text-info"><?php echo htmlspecialchars($repOrdenNo); ?></strong>
                    </span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <div class="modal-body p-4">
                <?php if ($repFirmado): ?>
                    <!-- VISTA OFICIAL DE REPORTE FIRMADO DIGITALMENTE -->
                    <div class="alert alert-success d-flex align-items-center justify-content-between p-3 rounded-3 mb-4" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4);">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-patch-check-fill text-success fs-3"></i>
                            <div>
                                <h6 class="fw-bold text-white mb-0">Reporte Firmado Digitalmente de Conformidad</h6>
                                <div class="small text-secondary">
                                    Firmado por <strong><?php echo htmlspecialchars($repFirmadoPor); ?></strong> el 
                                    <span class="text-white"><?php echo formatearFechaHoraMensaje($repFirmadoEn); ?></span>
                                </div>
                            </div>
                        </div>
                        <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketActivo['id']; ?>&auto=1" target="_blank" class="btn btn-sm btn-danger rounded-pill px-3 py-1.5 fw-bold d-flex align-items-center gap-1.5 shadow">
                            <i class="bi bi-file-earmark-pdf-fill"></i> Guardar PDF / Imprimir
                        </a>
                    </div>

                    <!-- HOJA MEMBRETADA OFICIAL ESTILIZADA -->
                    <div id="hojaReporteOficial" class="p-4 p-md-5 rounded-4 border border-secondary border-opacity-30 mb-4" style="background: #ffffff; color: #1e293b;">
                        <!-- Membrete Superior Oficial con Logos y Título Centrado -->
                        <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-3" style="border-color: #0f172a !important; border-bottom-width: 2px !important;">
                            <!-- Logo Izquierdo GH Grupo Huerta -->
                            <div style="flex: 0 0 160px;">
                                <img src="<?php echo $logoIzqSrc; ?>" alt="GH Grupo Huerta" style="max-height: 52px; max-width: 155px; object-fit: contain;">
                            </div>

                            <!-- Título Central en Azul Marino Oficial -->
                            <div class="text-center flex-grow-1 px-2">
                                <div style="color: #002060; font-weight: 900; font-size: 19px; letter-spacing: 0.5px; line-height: 1.2; text-transform: uppercase;">
                                    REPORTE DE MANTENIMIENTO TI
                                </div>
                                <div style="color: #002060; font-weight: 900; font-size: 18px; letter-spacing: 0.5px; line-height: 1.2; text-transform: uppercase;">
                                    GRUPO HUERTA
                                </div>
                            </div>

                            <!-- Logo Derecho Sello Circular Departamental y Agencias -->
                            <div style="flex: 0 0 160px;" class="text-end">
                                <img src="<?php echo $logoDerSrc; ?>" alt="Sistemas Grupo Huerta" style="max-height: 78px; max-width: 82px; object-fit: contain;">
                            </div>
                        </div>

                        <!-- Sub-barra: Orden y Estatus -->
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom" style="border-color: #e2e8f0;">
                            <div class="d-flex align-items-center gap-2">
                                <div class="badge" style="background: #002060; color: #fff; font-size: 13px; font-weight: 700; padding: 5px 12px; border-radius: 4px; font-family: monospace;">
                                    ORDEN NO. <?php echo htmlspecialchars($repOrdenNo); ?>
                                </div>
                                <span class="text-muted small fw-semibold">
                                    Fecha: <strong class="text-dark"><?php echo htmlspecialchars(date('d/m/Y', strtotime($ticketActivo['creado_en']))); ?></strong>
                                </span>
                            </div>
                            <div>
                                <div class="badge bg-success bg-opacity-10 text-success border border-success fw-bold px-3 py-1.5" style="font-size: 11px;">
                                    <i class="bi bi-patch-check-fill me-1"></i> CERTIFICADO DIGITALMENTE
                                </div>
                            </div>
                        </div>

                        <!-- Metadatos Generales -->
                        <div class="row g-3 mb-4 pb-3 border-bottom" style="border-color: #e2e8f0 !important; font-size: 0.88rem;">
                            <div class="col-md-6">
                                <div class="text-muted small text-uppercase fw-semibold">Agencia / Sucursal Solicitante:</div>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($ticketActivo['solicitante_agencia'] ?? 'Agencia Sucursal'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small text-uppercase fw-semibold">Solicitante del Ticket:</div>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($ticketActivo['solicitante_nombre'] ?? 'Solicitante'); ?> (<?php echo htmlspecialchars($ticketActivo['solicitante_email'] ?? ''); ?>)</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small text-uppercase fw-semibold">Técnico Especialista Responsable:</div>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($repTecnico); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small text-uppercase fw-semibold">Área de Atención:</div>
                                <div class="fw-bold text-primary">INFRAESTRUCTURA (SITE / REDES / HARDWARE)</div>
                            </div>
                        </div>

                        <!-- Campos del reporte -->
                        <div class="mb-4">
                            <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.78rem;">1. Necesidad o Problema Reportado:</div>
                            <div class="p-3 rounded-2" style="background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.9rem; line-height: 1.5; white-space: pre-line;">
                                <?php echo htmlspecialchars($repProblema); ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.78rem;">2. Trabajo Solicitado:</div>
                            <div class="p-3 rounded-2" style="background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.9rem; line-height: 1.5; white-space: pre-line;">
                                <?php echo htmlspecialchars($repTrabajoSol ?: 'Atención y diagnóstico técnico de infraestructura.'); ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.78rem;">3. Trabajos Realizados por el Técnico:</div>
                            <div class="p-3 rounded-2" style="background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.9rem; line-height: 1.5; white-space: pre-line;">
                                <?php echo htmlspecialchars($repTrabajosReal ?: 'Trabajo técnico de infraestructura concluido.'); ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.78rem;">4. Observaciones Técnicas y Recomendaciones:</div>
                            <div class="p-3 rounded-2" style="background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.9rem; line-height: 1.5; white-space: pre-line;">
                                <?php echo htmlspecialchars($repObservaciones ?: 'Sin observaciones adicionales.'); ?>
                            </div>
                        </div>

                        <!-- Firmas -->
                        <div class="row pt-4 mt-4 border-top" style="border-color: #cbd5e1 !important;">
                            <div class="col-6 text-center">
                                <div class="d-flex align-items-center justify-content-center" style="height: 110px;">
                                    <div class="fw-bold text-dark" style="font-size: 1.1rem;"><?php echo htmlspecialchars($repTecnico); ?></div>
                                </div>
                                <div class="border-top pt-2 mx-auto" style="border-color: #000 !important; width: 80%;">
                                    <div class="fw-bold text-dark small">Técnico que Atendió</div>
                                    <div class="text-muted" style="font-size: 0.72rem;">Dirección de Sistemas</div>
                                </div>
                            </div>
                            <div class="col-6 text-center">
                                <div class="d-flex align-items-center justify-content-center" style="height: 110px;">
                                    <?php if (!empty($repFirmaImg)): ?>
                                        <img src="<?php echo htmlspecialchars($repFirmaImg); ?>" alt="Firma Digital" style="max-height: 95px; max-width: 250px; object-fit: contain;">
                                    <?php else: ?>
                                        <div class="text-muted small italic">Firma digital registrada</div>
                                    <?php endif; ?>
                                </div>
                                <div class="border-top pt-2 mx-auto" style="border-color: #000 !important; width: 80%;">
                                    <div class="fw-bold text-dark small"><?php echo htmlspecialchars($repFirmadoPor ?: ($ticketActivo['solicitante_nombre'] ?? 'Solicitante')); ?></div>
                                    <div class="text-success fw-bold" style="font-size: 0.72rem;">Firma Digital de Conformidad &bull; <?php echo formatearFechaHoraMensaje($repFirmadoEn); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Formulario de Edición / Creación del Formato -->
                <form method="POST" action="tickets.php?ver=<?php echo $ticketActivo['id']; ?>">
                    <input type="hidden" name="accion" value="guardar_reporte_servicio">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticketActivo['id']; ?>">

                    <div class="card p-3 mb-3" style="background: rgba(14, 165, 233, 0.04); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 12px;">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h6 class="fw-bold text-info mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-pencil-square"></i> <?php echo $repFirmado ? 'Modificar Datos del Reporte' : 'Llenar Formato de Servicio para Enviar a Firma'; ?>
                            </h6>
                            <span class="small text-secondary">
                                El solicitante podrá firmar desde su portal de agencia cuando lo envíes.
                            </span>
                        </div>

                        <div class="row g-3">
                            <!-- Orden NO. -->
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                    <i class="bi bi-hash text-info me-1"></i> Orden NO.
                                </label>
                                <input type="text" class="form-control" style="background: rgba(4, 13, 26, 0.85); color: #38bdf8; font-weight: 700; border-color: rgba(56, 189, 248, 0.4);" value="<?php echo htmlspecialchars($repOrdenNo); ?>" readonly>
                                <div class="form-text text-secondary" style="font-size: 0.7rem;">Tomado automáticamente del folio del ticket.</div>
                            </div>

                            <!-- Técnico que Atendió -->
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                    <i class="bi bi-person-badge text-info me-1"></i> Quien Atendió el Ticket
                                </label>
                                <input type="text" name="reporte_tecnico_nombre" class="form-control" style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35);" value="<?php echo htmlspecialchars($repTecnico); ?>" required>
                            </div>

                            <!-- Agencia y Solicitante -->
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                    <i class="bi bi-building text-info me-1"></i> Solicitante / Agencia
                                </label>
                                <input type="text" class="form-control" style="background: rgba(4, 13, 26, 0.85); color: #e2e8f0; border-color: rgba(255, 255, 255, 0.15);" value="<?php echo htmlspecialchars(($ticketActivo['solicitante_nombre'] ?? '') . ' (' . ($ticketActivo['solicitante_agencia'] ?? '') . ')'); ?>" readonly>
                            </div>

                            <!-- Necesidad o Problema -->
                            <div class="col-12">
                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                    <i class="bi bi-exclamation-circle text-info me-1"></i> Necesidad o Problema (Ticket Original)
                                </label>
                                <textarea class="form-control" rows="2" style="background: rgba(4, 13, 26, 0.85); color: #cbd5e1; border-color: rgba(255, 255, 255, 0.15); font-size: 0.88rem;" readonly><?php echo htmlspecialchars($repProblema); ?></textarea>
                            </div>

                            <!-- Trabajo Solicitado -->
                            <div class="col-12">
                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                    <i class="bi bi-card-checklist text-info me-1"></i> Trabajo Solicitado <span class="text-danger">*</span>
                                </label>
                                <textarea name="reporte_trabajo_solicitado" rows="3" class="form-control" style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35); font-size: 0.9rem;" placeholder="Escribe el trabajo solicitado por el usuario o requerimiento de infraestructura..." required><?php echo htmlspecialchars($repTrabajoSol); ?></textarea>
                            </div>

                            <!-- Trabajos Realizados -->
                            <div class="col-12">
                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                    <i class="bi bi-tools text-info me-1"></i> Trabajos Realizados <span class="text-danger">*</span>
                                </label>
                                <textarea name="reporte_trabajos_realizados" rows="4" class="form-control" style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35); font-size: 0.9rem;" placeholder="Escribe detalladamente las actividades ejecutadas, revisiones técnicas, configuraciones, refacciones o soluciones aplicadas..." required><?php echo htmlspecialchars($repTrabajosReal); ?></textarea>
                            </div>

                            <!-- Observaciones -->
                            <div class="col-12">
                                <label class="form-label text-secondary small fw-bold text-uppercase">
                                    <i class="bi bi-chat-square-text text-info me-1"></i> Observaciones y Recomendaciones
                                </label>
                                <textarea name="reporte_observaciones" rows="3" class="form-control" style="background: rgba(4, 13, 26, 0.85); color: #fff; border-color: rgba(56, 189, 248, 0.35); font-size: 0.9rem;" placeholder="Escribe observaciones adicionales, recomendaciones preventivas o condiciones del equipo..."><?php echo htmlspecialchars($repObservaciones); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between pt-2">
                        <div>
                            <?php if ($repFirmado || $repHabilitado): ?>
                                <a href="reporte_servicio_imprimir.php?id=<?php echo $ticketActivo['id']; ?>&auto=1" target="_blank" class="btn btn-outline-info rounded-3 px-3 py-2 fw-semibold">
                                    <i class="bi bi-file-earmark-pdf-fill me-1 text-danger"></i> Ver / Descargar PDF Oficial
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2 text-white" data-bs-dismiss="modal">
                                Cerrar
                            </button>
                            <button type="submit" class="btn btn-info fw-bold text-dark px-4 py-2 rounded-3 shadow">
                                <i class="bi bi-send-check-fill me-1"></i> <?php echo $repFirmado ? 'Guardar Cambios del Formato' : 'Guardar y Enviar para Firma Digital'; ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($esSuperAdmin): ?>
<!-- ============================================================== -->
<!-- MODAL: FICHA Y ESTADÍSTICAS DEL AGENTE (EXCLUSIVO SUPERADMIN) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalAgenteSuperAdmin" tabindex="-1" aria-labelledby="modalAgenteSuperAdminLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="background: #040d1a; border: 1px solid rgba(245, 158, 11, 0.45); box-shadow: 0 25px 60px rgba(0,0,0,0.9); color: #fff; border-radius: 18px;">
            
            <div class="modal-header border-bottom border-secondary border-opacity-25" style="background: linear-gradient(90deg, rgba(245, 158, 11, 0.15) 0%, rgba(14, 165, 233, 0.08) 100%);">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 rounded-3" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;">
                        <i class="bi bi-person-bounding-box fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="modalAgenteSuperAdminLabel">
                            Ficha Técnica y Rendimiento del Agente de Sistemas
                        </h5>
                        <div class="text-secondary small">Panel de auditoría y productividad exclusivo para SuperAdmin</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning text-dark fw-bold px-3 py-1.5 rounded-pill">
                        <i class="bi bi-shield-lock-fill me-1"></i> ROL SUPERADMIN
                    </span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <div class="modal-body p-4">
                <!-- Loader para llamadas AJAX -->
                <div id="modalAgenteLoading" class="text-center py-5" style="display: none;">
                    <div class="spinner-border text-warning" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Cargando datos...</span>
                    </div>
                    <div class="text-light mt-3 fw-semibold">Consultando métricas y ficha del agente...</div>
                </div>

                <div id="modalAgenteContenido">
                    <div class="row g-4">
                        <!-- Columna Izquierda: Perfil, Foto y Subida -->
                        <div class="col-lg-4">
                            <div class="p-3.5 rounded-4 h-100 border border-secondary border-opacity-25" style="background: rgba(255, 255, 255, 0.02);">
                                
                                <!-- Foto de Perfil Grande -->
                                <div class="text-center mb-3">
                                    <div class="position-relative d-inline-block">
                                        <div id="modalAgenteFotoContenedor">
                                            <img id="modalAgenteFotoImg" src="" alt="Foto Agente" class="rounded-circle shadow" style="width: 110px; height: 110px; object-fit: cover; border: 3px solid #f59e0b; display: none;">
                                            <div id="modalAgenteFotoPlaceholder" class="rounded-circle shadow mx-auto d-flex align-items-center justify-content-center" style="width: 110px; height: 110px; background: linear-gradient(135deg, #1e293b, #0f172a); border: 3px solid #f59e0b; color: #f59e0b; font-size: 3rem;">
                                                <i class="bi bi-person-fill"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <h5 class="fw-bold text-white mt-2 mb-1" id="modalAgenteNombreTxt">---</h5>
                                    <div class="text-warning small fw-semibold" id="modalAgenteCargoTxt">Especialista en Soporte TI</div>
                                    <div class="mt-1 d-flex align-items-center justify-content-center gap-1.5 flex-wrap">
                                        <span class="badge bg-secondary bg-opacity-40 text-info font-monospace" id="modalAgenteUsuarioTxt">@usuario</span>
                                        <span class="badge bg-primary bg-opacity-30 text-white border border-primary border-opacity-40" id="modalAgenteRolTxt">Admin</span>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success" id="modalAgenteActivoTxt">Activo</span>
                                    </div>
                                </div>

                                <!-- Formulario para Poner o Cambiar Foto -->
                                <div class="p-3 rounded-3 mb-3 border border-warning border-opacity-30" style="background: rgba(245, 158, 11, 0.05);">
                                    <label class="form-label text-warning small fw-bold text-uppercase d-flex align-items-center justify-content-between mb-2">
                                        <span><i class="bi bi-camera-fill me-1"></i> Asignar / Cambiar Foto</span>
                                        <span class="badge bg-secondary bg-opacity-40 text-light" style="font-size: 0.65rem;">JPG, PNG, WEBP</span>
                                    </label>
                                    
                                    <form method="POST" enctype="multipart/form-data" action="tickets.php<?php echo !empty($ticketActivo['id']) ? ('?ver=' . $ticketActivo['id']) : ''; ?>">
                                        <input type="hidden" name="accion" value="superadmin_actualizar_foto_agente">
                                        <input type="hidden" name="agente_id" id="formFotoAgenteId" value="">
                                        <input type="hidden" name="agente_nombre" id="formFotoAgenteNombre" value="">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticketActivo['id'] ?? 0; ?>">

                                        <div class="mb-2">
                                            <input type="file" name="foto_agente" id="inputFotoAgenteModal" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.gif" style="background: rgba(4, 13, 26, 0.9); color: #fff; border-color: rgba(245, 158, 11, 0.35); font-size: 0.78rem;" required onchange="previsualizarFotoModal(this)">
                                        </div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <input type="text" name="cargo" id="formFotoAgenteCargo" class="form-control form-control-sm" placeholder="Cargo/Puesto" style="background: rgba(4, 13, 26, 0.9); color: #fff; border-color: rgba(255,255,255,0.15); font-size: 0.75rem;">
                                            </div>
                                            <div class="col-6">
                                                <input type="text" name="telefono" id="formFotoAgenteTelefono" class="form-control form-control-sm" placeholder="Teléfono/Ext." style="background: rgba(4, 13, 26, 0.9); color: #fff; border-color: rgba(255,255,255,0.15); font-size: 0.75rem;">
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-warning btn-sm w-100 fw-bold text-dark py-1.5 shadow-sm">
                                            <i class="bi bi-cloud-arrow-up-fill me-1"></i> Guardar Fotografía y Datos
                                        </button>
                                    </form>

                                    <!-- Botón para Quitar Foto -->
                                    <form method="POST" id="formEliminarFotoAgente" action="tickets.php<?php echo !empty($ticketActivo['id']) ? ('?ver=' . $ticketActivo['id']) : ''; ?>" class="mt-2" onsubmit="return confirm('¿Seguro que deseas eliminar la fotografía de este agente?');" style="display: none;">
                                        <input type="hidden" name="accion" value="superadmin_eliminar_foto_agente">
                                        <input type="hidden" name="agente_id" id="formDelFotoAgenteId" value="">
                                        <input type="hidden" name="agente_nombre" id="formDelFotoAgenteNombre" value="">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticketActivo['id'] ?? 0; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm w-100 py-1" style="font-size: 0.75rem;">
                                            <i class="bi bi-trash3 me-1"></i> Quitar Foto Actual
                                        </button>
                                    </form>
                                </div>

                                <!-- Datos Detallados de Contacto y Sucursal -->
                                <div class="d-flex flex-column gap-2 small">
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Correo:</span>
                                        <span class="text-white text-truncate ms-2" id="modalAgenteEmailTxt" style="max-width: 170px;">---</span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Sucursal:</span>
                                        <span class="text-light fw-semibold text-truncate ms-2" id="modalAgenteAgenciaTxt">---</span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Teléfono:</span>
                                        <span class="text-white" id="modalAgenteTelTxt">---</span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1 border-bottom border-secondary border-opacity-20">
                                        <span class="text-secondary">Áreas TI:</span>
                                        <span class="text-info fw-bold" id="modalAgenteAreasTxt">TODOS</span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1">
                                        <span class="text-secondary">Fecha Alta:</span>
                                        <span class="text-secondary font-monospace" id="modalAgenteFechaTxt">---</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Columna Derecha: Métricas y Estadísticas de Rendimiento -->
                        <div class="col-lg-8">
                            <h6 class="fw-bold text-info mb-3 d-flex align-items-center gap-2">
                                <i class="bi bi-graph-up-arrow"></i> Desempeño y Métricas de Productividad en Tickets
                            </h6>

                            <!-- Matriz de Indicadores Clave (KPIs) -->
                            <div class="row g-2 mb-3">
                                <div class="col-6 col-md-3">
                                    <div class="p-3 rounded-3 text-center border border-info border-opacity-25" style="background: rgba(14, 165, 233, 0.08);">
                                        <div class="text-secondary small" style="font-size: 0.72rem;">TOTAL TICKETS</div>
                                        <div class="fs-4 fw-bold text-white mt-1" id="statModalTotal">0</div>
                                        <div class="text-info small" style="font-size: 0.68rem;">Asignados</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="p-3 rounded-3 text-center border border-warning border-opacity-25" style="background: rgba(245, 158, 11, 0.08);">
                                        <div class="text-secondary small" style="font-size: 0.72rem;">ABIERTOS</div>
                                        <div class="fs-4 fw-bold text-warning mt-1" id="statModalAbiertos">0</div>
                                        <div class="text-warning small" style="font-size: 0.68rem;">En espera</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="p-3 rounded-3 text-center border border-primary border-opacity-25" style="background: rgba(59, 130, 246, 0.08);">
                                        <div class="text-secondary small" style="font-size: 0.72rem;">EN PROCESO</div>
                                        <div class="fs-4 fw-bold text-info mt-1" id="statModalEnProceso">0</div>
                                        <div class="text-info small" style="font-size: 0.68rem;">Atendiéndose</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="p-3 rounded-3 text-center border border-success border-opacity-25" style="background: rgba(16, 185, 129, 0.08);">
                                        <div class="text-secondary small" style="font-size: 0.72rem;">RESUELTOS / CERRADOS</div>
                                        <div class="fs-4 fw-bold text-success mt-1" id="statModalResueltos">0</div>
                                        <div class="text-success small" style="font-size: 0.68rem;">Solucionados</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Barra de Productividad y Efectividad -->
                            <div class="p-3 rounded-3 mb-3 border border-secondary border-opacity-25" style="background: rgba(255, 255, 255, 0.02);">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small fw-bold text-white d-flex align-items-center gap-1.5">
                                        <i class="bi bi-speedometer2 text-warning"></i> Tasa de Efectividad en Resolución
                                    </span>
                                    <span class="fs-6 fw-bold text-success" id="statModalEfectividad">0%</span>
                                </div>
                                <div class="progress" style="height: 10px; background: rgba(255, 255, 255, 0.08); border-radius: 6px;">
                                    <div id="statModalEfectividadBar" class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-2 small text-secondary" style="font-size: 0.72rem;">
                                    <span>Calculado sobre tickets resueltos y cerrados del total asignado.</span>
                                    <span id="statModalReportesFirmadosTxt"><i class="bi bi-patch-check-fill text-success me-1"></i>0 Reportes Firmados</span>
                                </div>
                            </div>

                            <!-- Distribución por Área de Sistemas -->
                            <div class="mb-3">
                                <h6 class="text-white small fw-bold text-uppercase mb-2 d-flex align-items-center gap-1.5">
                                    <i class="bi bi-diagram-3 text-info"></i> Distribución de Carga por Área de Sistemas
                                </h6>
                                <div id="statModalAreasContainer" class="d-flex flex-column gap-2">
                                    <!-- Generado dinámicamente -->
                                </div>
                            </div>

                            <!-- Historial de Últimos Tickets Atendidos -->
                            <div>
                                <h6 class="text-white small fw-bold text-uppercase mb-2 d-flex align-items-center gap-1.5">
                                    <i class="bi bi-clock-history text-warning"></i> Últimos Requerimientos Asignados
                                </h6>
                                <div class="table-responsive rounded-3 border border-secondary border-opacity-25" style="max-height: 220px; overflow-y: auto;">
                                    <table class="table table-sm table-dark table-hover mb-0" style="font-size: 0.78rem;">
                                        <thead>
                                            <tr style="background: rgba(15, 23, 42, 0.9);">
                                                <th>Folio</th>
                                                <th>Área</th>
                                                <th>Asunto</th>
                                                <th>Sucursal</th>
                                                <th>Estado</th>
                                                <th class="text-center">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody id="statModalTicketsTbody">
                                            <tr><td colspan="6" class="text-center text-secondary py-3">Sin tickets registrados aún.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-top border-secondary border-opacity-25" style="background: rgba(14, 165, 233, 0.04);">
                <button type="button" class="btn btn-outline-secondary px-4 rounded-3 text-white" data-bs-dismiss="modal">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function imprimirReporteServicioOficial() {
    window.open('reporte_servicio_imprimir.php?id=<?php echo $ticketActivo['id'] ?? 0; ?>&auto=1', '_blank');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function previsualizarFotoModal(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const imgFoto = document.getElementById('modalAgenteFotoImg');
            const phFoto = document.getElementById('modalAgenteFotoPlaceholder');
            if (imgFoto) {
                imgFoto.src = e.target.result;
                imgFoto.style.display = 'inline-block';
            }
            if (phFoto) phFoto.style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function abrirModalAgenteSuperAdmin(nombreAgente) {
    if (!nombreAgente || !nombreAgente.trim()) return;
    const modalEl = document.getElementById('modalAgenteSuperAdmin');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    const loading = document.getElementById('modalAgenteLoading');
    const contenido = document.getElementById('modalAgenteContenido');
    if (loading) loading.style.display = 'block';
    if (contenido) contenido.style.display = 'none';

    fetch('tickets.php?ajax_agente_stats=1&agente=' + encodeURIComponent(nombreAgente))
        .then(res => res.json())
        .then(data => {
            if (loading) loading.style.display = 'none';
            if (contenido) contenido.style.display = 'block';

            if (data.status === 'ok') {
                const u = data.agente || {};
                const s = data.stats || {};

                // Actualizar Foto
                const imgFoto = document.getElementById('modalAgenteFotoImg');
                const phFoto = document.getElementById('modalAgenteFotoPlaceholder');
                const formDel = document.getElementById('formEliminarFotoAgente');

                if (u.foto && u.foto.trim() !== '') {
                    if (imgFoto) {
                        imgFoto.src = u.foto;
                        imgFoto.style.display = 'inline-block';
                    }
                    if (phFoto) phFoto.style.display = 'none';
                    if (formDel) formDel.style.display = 'block';
                } else {
                    if (imgFoto) {
                        imgFoto.src = '';
                        imgFoto.style.display = 'none';
                    }
                    if (phFoto) phFoto.style.display = 'flex';
                    if (formDel) formDel.style.display = 'none';
                }

                // Datos de Usuario
                document.getElementById('modalAgenteNombreTxt').innerText = u.nombre || nombreAgente;
                document.getElementById('modalAgenteCargoTxt').innerText = u.cargo || 'Especialista en Soporte TI';
                document.getElementById('modalAgenteUsuarioTxt').innerText = '@' + (u.usuario || 'agente');
                document.getElementById('modalAgenteRolTxt').innerText = u.rol || 'Admin';
                document.getElementById('modalAgenteActivoTxt').innerText = (u.activo == 1 || u.activo === undefined) ? 'Activo' : 'Inactivo';
                document.getElementById('modalAgenteEmailTxt').innerText = u.email || 'No registrado';
                document.getElementById('modalAgenteAgenciaTxt').innerText = u.agencia || 'Oficina Central Grupo Huerta';
                document.getElementById('modalAgenteTelTxt').innerText = u.telefono || 'Sin registrar';
                document.getElementById('modalAgenteAreasTxt').innerText = u.areas_tickets || 'TODOS';
                document.getElementById('modalAgenteFechaTxt').innerText = u.creado_en || '---';

                // Campos del Formulario de Fotografía
                document.getElementById('formFotoAgenteId').value = u.id || 0;
                document.getElementById('formFotoAgenteNombre').value = u.nombre || nombreAgente;
                document.getElementById('formFotoAgenteCargo').value = u.cargo || '';
                document.getElementById('formFotoAgenteTelefono').value = u.telefono || '';
                document.getElementById('formDelFotoAgenteId').value = u.id || 0;
                document.getElementById('formDelFotoAgenteNombre').value = u.nombre || nombreAgente;

                // Estadísticas KPIs
                document.getElementById('statModalTotal').innerText = s.total || 0;
                document.getElementById('statModalAbiertos').innerText = s.abiertos || 0;
                document.getElementById('statModalEnProceso').innerText = s.en_proceso || 0;
                document.getElementById('statModalResueltos').innerText = (s.resueltos || 0) + (s.cerrados || 0);

                const efect = s.efectividad !== undefined ? s.efectividad : 0;
                document.getElementById('statModalEfectividad').innerText = efect + '%';
                const bar = document.getElementById('statModalEfectividadBar');
                if (bar) bar.style.width = efect + '%';

                document.getElementById('statModalReportesFirmadosTxt').innerHTML = '<i class="bi bi-patch-check-fill text-success me-1"></i>' + (s.con_reporte_firmado || 0) + ' Reportes Firmados Digitalmente';

                // Distribución de Áreas
                const areasCont = document.getElementById('statModalAreasContainer');
                if (areasCont) {
                    if (s.por_area && s.por_area.length > 0) {
                        let htmlAreas = '';
                        s.por_area.forEach(a => {
                            const cantA = parseInt(a.cant) || 0;
                            const pctA = s.total > 0 ? Math.round((cantA / s.total) * 100) : 0;
                            htmlAreas += `
                                <div>
                                    <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 0.75rem;">
                                        <span class="text-light fw-semibold">${a.area_sistemas || 'INFRAESTRUCTURA'}</span>
                                        <span class="text-secondary">${cantA} tickets (${pctA}%)</span>
                                    </div>
                                    <div class="progress" style="height: 6px; background: rgba(255, 255, 255, 0.08);">
                                        <div class="progress-bar bg-info" style="width: ${pctA}%"></div>
                                    </div>
                                </div>
                            `;
                        });
                        areasCont.innerHTML = htmlAreas;
                    } else {
                        areasCont.innerHTML = '<div class="text-secondary small">Sin distribución de áreas disponible.</div>';
                    }
                }

                // Tabla de Últimos Tickets
                const tbody = document.getElementById('statModalTicketsTbody');
                if (tbody) {
                    if (s.ultimos_tickets && s.ultimos_tickets.length > 0) {
                        let htmlTk = '';
                        s.ultimos_tickets.forEach(t => {
                            const stClass = (t.estado === 'Resuelto' || t.estado === 'Cerrado') ? 'badge bg-success' : (t.estado === 'En Proceso' ? 'badge bg-info text-dark' : 'badge bg-warning text-dark');
                            htmlTk += `
                                <tr>
                                    <td class="font-monospace text-info fw-bold">${t.folio || ('#TK-' + t.id)}</td>
                                    <td><span class="badge bg-secondary bg-opacity-40 text-light">${t.area_sistemas || 'TI'}</span></td>
                                    <td class="text-truncate text-white" style="max-width: 140px;" title="${t.titulo || ''}">${t.titulo || ''}</td>
                                    <td class="text-secondary">${t.solicitante_agencia || 'General'}</td>
                                    <td><span class="${stClass} py-0 px-1.5" style="font-size: 0.68rem;">${t.estado || 'Abierto'}</span></td>
                                    <td class="text-center">
                                        <a href="tickets.php?ver=${t.id}" class="btn btn-xs btn-outline-info rounded-pill py-0 px-2" style="font-size: 0.7rem;">
                                            Ver
                                        </a>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = htmlTk;
                    } else {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary py-3">Sin tickets recientes asignados.</td></tr>';
                    }
                }
            } else {
                alert('No se pudieron consultar los datos del agente: ' + (data.message || 'Error desconocido'));
            }
        })
        .catch(err => {
            if (loading) loading.style.display = 'none';
            if (contenido) contenido.style.display = 'block';
            console.error('Error al consultar agente:', err);
        });
}

const agenciasData = <?php echo json_encode($agenciasList); ?>;
const totalesGlobales = {
    total: <?php echo $totalTickets; ?>,
    abiertos: <?php echo $totalAbiertos; ?>,
    en_proceso: <?php echo $totalEnProceso; ?>,
    resueltos: <?php echo $totalResueltos; ?>
};

let filtroAgenciaActual = 'TODAS';
let filtroAreaActual = 'TODAS';
let filtroCitaEstadoActual = 'TODOS';

// Control de Tabs en Menú Lateral
function cambiarTab(tabName, updateUrl = true) {
    const tabsPermitidos = ['tickets', 'citas', 'estadisticas'<?php echo $esSuperAdmin ? ", 'agentes'" : ""; ?>];
    if (!tabsPermitidos.includes(tabName)) {
        tabName = 'tickets';
    }

    const secDetalle = document.getElementById('seccionTab_detalle');
    if (secDetalle) {
        secDetalle.style.display = 'none';
    }

    // Actualizar botones de navegación en sidebar
    tabsPermitidos.forEach(t => {
        const btn = document.getElementById('sidebarBtn_' + t);
        const sec = document.getElementById('seccionTab_' + t);
        if (btn) {
            if (t === tabName) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }
        if (sec) {
            sec.style.display = (t === tabName) ? 'block' : 'none';
        }
    });

    // Actualizar título superior
    const titleEl = document.getElementById('topNavTituloSeccion');
    if (titleEl) {
        if (tabName === 'tickets') {
            titleEl.innerHTML = '<i class="bi bi-ticket-detailed-fill text-info me-1"></i> Tickets de Soporte';
        } else if (tabName === 'citas') {
            titleEl.innerHTML = '<i class="bi bi-calendar-event-fill text-success me-1"></i> Agenda de Citas Técnicas';
        } else if (tabName === 'estadisticas') {
            titleEl.innerHTML = '<i class="bi bi-bar-chart-line-fill text-primary me-1"></i> Estadísticas e Inteligencia TI';
        } else if (tabName === 'agentes') {
            titleEl.innerHTML = '<i class="bi bi-people-fill text-warning me-1"></i> Agentes y Especialistas TI';
        }
    }

    // Si entramos a estadísticas, inicializar gráficos Chart.js
    if (tabName === 'estadisticas') {
        setTimeout(renderizarGraficasEstadisticas, 80);
    }

    // Si entramos a citas, restaurar modo de vista (lista o calendario mensual)
    if (tabName === 'citas') {
        const modoGuardado = localStorage.getItem('portal_citas_modo_vista') || 'lista';
        if (typeof cambiarVistaModoCitas === 'function') {
            cambiarVistaModoCitas(modoGuardado);
        }
    }

    // Actualizar URL sin recargar
    if (updateUrl) {
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('tab', tabName);
        currentUrl.searchParams.delete('ver');
        currentUrl.searchParams.delete('ticket_id');
        if (tabName !== 'tickets') {
            currentUrl.searchParams.delete('agencia');
        }
        history.pushState(null, '', currentUrl.toString());
    }

    // Cerrar sidebar en dispositivos móviles
    cerrarSidebarMobile();
}

// Búsqueda en tiempo real en la cuadrícula de agentes (SuperAdmin)
function filtrarAgentesGrid() {
    const input = document.getElementById('filtroAgentesInput');
    const query = input ? input.value.toLowerCase().trim() : '';
    const cards = document.querySelectorAll('.agente-card-item');
    cards.forEach(card => {
        const nombre = (card.getAttribute('data-nombre') || '').toLowerCase();
        const user = (card.getAttribute('data-usuario') || '').toLowerCase();
        const email = (card.getAttribute('data-email') || '').toLowerCase();
        const areas = (card.getAttribute('data-areas') || '').toLowerCase();
        const cargo = (card.getAttribute('data-cargo') || '').toLowerCase();
        const coincide = !query || nombre.includes(query) || user.includes(query) || email.includes(query) || areas.includes(query) || cargo.includes(query);
        card.style.display = coincide ? 'block' : 'none';
    });
}

function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const btnTab = document.getElementById('btnShowSidebarTab');
    if (!sidebar) return;

    const isMobile = window.innerWidth <= 991;

    if (isMobile) {
        sidebar.classList.toggle('show');
        if (backdrop) backdrop.classList.toggle('show');
    } else {
        // En escritorio: colapsar / expandir con guardado de preferencia
        const isCollapsed = sidebar.classList.toggle('collapsed');
        if (btnTab) {
            btnTab.style.display = isCollapsed ? 'block' : 'none';
        }
        localStorage.setItem('sidebar_tickets_collapsed', isCollapsed ? '1' : '0');
    }
}

function cerrarSidebarMobile() {
    if (window.innerWidth <= 991) {
        const sidebar = document.getElementById('appSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (sidebar) sidebar.classList.remove('show');
        if (backdrop) backdrop.classList.remove('show');
    }
}

function mostrarVistaAgencias() {
    const vistaAg = document.getElementById('vistaAgencias');
    const vistaHist = document.getElementById('vistaHistorialTickets');
    if (vistaAg && vistaHist) {
        vistaAg.style.display = 'block';
        vistaHist.style.display = 'none';
    }
    if (typeof actualizarCoverflow3D === 'function') {
        actualizarCoverflow3D();
    }
    if (typeof iniciarAutoCoverflow === 'function' && autoCoverflowActivo) {
        iniciarAutoCoverflow();
    }
    history.pushState(null, '', 'tickets.php?tab=tickets');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function mostrarHistorialAgencia(agencia) {
    filtroAgenciaActual = agencia;
    if (typeof detenerAutoCoverflow === 'function') {
        detenerAutoCoverflow();
    }

    const vistaAg = document.getElementById('vistaAgencias');
    const vistaHist = document.getElementById('vistaHistorialTickets');
    if (vistaAg && vistaHist) {
        vistaAg.style.display = 'none';
        vistaHist.style.display = 'block';
    }

    const tituloEl = document.getElementById('tituloHistorialAgencia');
    const subtituloEl = document.getElementById('subtituloHistorialAgencia');
    const kpisEl = document.getElementById('kpisAgenciaSeleccionada');
    const btnExcel = document.getElementById('btnExportarExcel');
    const selectModalAgencia = document.getElementById('form_solicitante_agencia');

    let metrics = totalesGlobales;
    if (agencia !== 'TODAS' && agenciasData[agencia]) {
        metrics = agenciasData[agencia];
        if (tituloEl) tituloEl.innerHTML = `<span class="text-info">${agencia}</span> &bull; Historial de Tickets`;
        if (subtituloEl) subtituloEl.textContent = `Listado cronológico de incidencias y requerimientos de ${agencia}`;
        if (btnExcel) btnExcel.href = `tickets.php?export=excel&agencia=${encodeURIComponent(agencia)}`;
        if (selectModalAgencia) selectModalAgencia.value = agencia;
        history.pushState(null, '', `tickets.php?tab=tickets&agencia=${encodeURIComponent(agencia)}`);
    } else {
        filtroAgenciaActual = 'TODAS';
        if (tituloEl) tituloEl.innerHTML = `Todas las Agencias &bull; Historial Consolidado`;
        if (subtituloEl) subtituloEl.textContent = `Mostrando incidencias globales de todas las sucursales conectadas`;
        if (btnExcel) btnExcel.href = `tickets.php?export=excel`;
        history.pushState(null, '', `tickets.php?tab=tickets&agencia=TODAS`);
    }

    // Mini recuadros en la barra de historial
    if (kpisEl) {
        kpisEl.innerHTML = `
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);">
                <span class="small text-secondary">Totales:</span>
                <strong class="text-white">${metrics.total}</strong>
            </div>
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(234,179,8,0.12); border: 1px solid rgba(234,179,8,0.3);">
                <span class="small text-warning">Sin atender:</span>
                <strong class="text-warning">${metrics.abiertos}</strong>
            </div>
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.3);">
                <span class="small text-info">En proceso:</span>
                <strong class="text-info">${metrics.en_proceso}</strong>
            </div>
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3);">
                <span class="small text-success">Resueltos:</span>
                <strong class="text-success">${metrics.resueltos}</strong>
            </div>
        `;
    }

    // Sincronizar pills activas
    document.querySelectorAll('#agenciasFilterContainer .agency-pill-btn').forEach(btn => {
        const btnAg = btn.dataset.agencia || '';
        if (btnAg.toLowerCase() === filtroAgenciaActual.toLowerCase()) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    filtrarTickets();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function filtrarPorAgencia(agencia, elem) {
    mostrarHistorialAgencia(agencia);
}

function filtrarPorArea(area, elem) {
    filtroAreaActual = area;
    document.querySelectorAll('#areasFilterContainer .area-pill').forEach(pill => pill.classList.remove('active'));
    if (elem) elem.classList.add('active');
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
    const id = (typeof ticket === 'object' && ticket) ? (ticket.id || '') : ticket;
    if (id) {
        window.location.href = 'tickets.php?ver=' + id;
        return;
    }
}

function volverAListaTickets(e) {
    if (e && e.preventDefault) e.preventDefault();
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.delete('ver');
    currentUrl.searchParams.delete('ticket_id');
    currentUrl.searchParams.delete('enviado');
    currentUrl.searchParams.delete('actualizado');
    currentUrl.searchParams.set('tab', 'tickets');
    history.pushState(null, '', currentUrl.toString());

    const secDetalle = document.getElementById('seccionTab_detalle');
    const secTickets = document.getElementById('seccionTab_tickets');
    if (secDetalle) secDetalle.style.display = 'none';
    if (secTickets) secTickets.style.display = 'block';

    cambiarTab('tickets', false);
    const agParam = currentUrl.searchParams.get('agencia');
    if (agParam && agParam !== 'TODAS') {
        mostrarHistorialAgencia(agParam);
    } else {
        mostrarVistaAgencias();
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function copiarFolio(folio) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(folio).then(() => {
            alert('Folio copiado al portapapeles: ' + folio);
        }).catch(() => {
            prompt('Copia el folio:', folio);
        });
    } else {
        prompt('Copia el folio:', folio);
    }
}

function mostrarArchivoMsgSeleccionado(input) {
    const txtEl = document.getElementById('txtArchivoMsgSeleccionado');
    if (txtEl && input.files && input.files[0]) {
        txtEl.innerText = '📎 ' + input.files[0].name;
    }
}

function asignarmeEnFormulario(nombre) {
    const input = document.getElementById('inputAsignadoAgente');
    if (input) {
        input.value = nombre;
    }
}

function alSeleccionarTicketParaCita(ticketId) {
    const select = document.getElementById('citaTicketSelect');
    if (!select) return;
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) {
        if (document.getElementById('citaTicketFolio')) document.getElementById('citaTicketFolio').value = '';
        return;
    }
    const folio = opt.getAttribute('data-folio') || '';
    const titulo = opt.getAttribute('data-titulo') || '';
    const agencia = opt.getAttribute('data-agencia') || '';
    const area = opt.getAttribute('data-area') || '';
    const solicitante = opt.getAttribute('data-solicitante') || '';
    const email = opt.getAttribute('data-email') || '';
    const desc = opt.getAttribute('data-desc') || '';

    if (document.getElementById('citaTicketFolio')) {
        document.getElementById('citaTicketFolio').value = folio;
    }
    if (agencia && document.getElementById('citaAgencia')) {
        document.getElementById('citaAgencia').value = agencia;
    }
    if (area && document.getElementById('citaAreaSistemas')) {
        document.getElementById('citaAreaSistemas').value = area;
    }
    if (solicitante && document.getElementById('citaSolNombre')) {
        document.getElementById('citaSolNombre').value = solicitante;
    }
    if (email && document.getElementById('citaSolEmail')) {
        document.getElementById('citaSolEmail').value = email;
    }
    if (document.getElementById('citaAsunto')) {
        document.getElementById('citaAsunto').value = 'Atención ' + folio + ': ' + titulo;
    }
    if (document.getElementById('citaDescripcion')) {
        document.getElementById('citaDescripcion').value = 'Visita técnica de soporte para ' + (solicitante || 'usuario') + ' (' + (email || '') + ')\nMotivo del ticket: ' + desc;
    }
}

function agendarCitaParaTicket(ticket) {
    const modalEl = document.getElementById('modalNuevaCita');
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    // Asignar origen ticket para redirección
    const origInput = document.getElementById('citaOrigenTicketId');
    if (origInput) origInput.value = ticket.id;

    const tkSelect = document.getElementById('citaTicketSelect');
    const tkFolio = document.getElementById('citaTicketFolio');
    const asuntoInp = document.getElementById('citaAsunto');
    const agSelect = document.getElementById('citaAgencia');
    const descInp = document.getElementById('citaDescripcion');
    const solNom = document.getElementById('citaSolNombre');
    const solEmail = document.getElementById('citaSolEmail');
    const areaSelect = document.getElementById('citaAreaSistemas');

    if (tkSelect) {
        tkSelect.value = ticket.id;
    }
    if (tkFolio) tkFolio.value = ticket.folio || ('TK-' + ticket.id);
    if (agSelect && ticket.solicitante_agencia) agSelect.value = ticket.solicitante_agencia;
    if (solNom && ticket.solicitante_nombre) solNom.value = ticket.solicitante_nombre;
    if (solEmail && ticket.solicitante_email) solEmail.value = ticket.solicitante_email;
    if (areaSelect && ticket.area_sistemas) areaSelect.value = ticket.area_sistemas;
    if (asuntoInp) asuntoInp.value = 'Atención ' + (ticket.folio || ('TK-' + ticket.id)) + ': ' + (ticket.titulo || '');
    if (descInp) descInp.value = 'Visita técnica para seguimiento de ticket ' + (ticket.folio || '') + ' (' + (ticket.titulo || '') + ').\nDescripción del usuario: ' + (ticket.descripcion || '');

    modal.show();
}

// ----------------------------------------------------
// Gestión de Citas y Calendario Mensual
// ----------------------------------------------------
const todasLasCitas = <?php echo json_encode($citas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

let calAnio = new Date().getFullYear();
let calMes = new Date().getMonth(); // 0 = Enero, 11 = Diciembre

const NOMBRES_MESES = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
];

function cambiarVistaModoCitas(modo) {
    const vistaLista = document.getElementById('vistaCitasLista');
    const vistaCal = document.getElementById('vistaCitasCalendario');
    const btnLista = document.getElementById('btnVistaCitasLista');
    const btnCal = document.getElementById('btnVistaCitasCalendario');

    if (modo === 'calendario') {
        if (vistaLista) vistaLista.style.display = 'none';
        if (vistaCal) vistaCal.style.display = 'block';
        if (btnLista) btnLista.classList.remove('active');
        if (btnCal) btnCal.classList.add('active');
        renderizarCalendarioCitas(calAnio, calMes);
    } else {
        if (vistaLista) vistaLista.style.display = 'block';
        if (vistaCal) vistaCal.style.display = 'none';
        if (btnLista) btnLista.classList.add('active');
        if (btnCal) btnCal.classList.remove('active');
    }
    localStorage.setItem('portal_citas_modo_vista', modo);
}

function cambiarMesCalendario(delta) {
    calMes += delta;
    if (calMes < 0) {
        calMes = 11;
        calAnio--;
    } else if (calMes > 11) {
        calMes = 0;
        calAnio++;
    }
    renderizarCalendarioCitas(calAnio, calMes);
}

function irAHoyCalendario() {
    const hoy = new Date();
    calAnio = hoy.getFullYear();
    calMes = hoy.getMonth();
    renderizarCalendarioCitas(calAnio, calMes);
}

function agendarCitaEnFecha(fechaStr) {
    const inputFecha = document.getElementById('modalNuevaCita_fecha');
    if (inputFecha) {
        inputFecha.value = fechaStr;
    }
    const modalEl = document.getElementById('modalNuevaCita');
    if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}

function renderizarCalendarioCitas(anio, mes) {
    const grid = document.getElementById('calendarGridDays');
    const titleEl = document.getElementById('calendarMonthTitle');
    const countEl = document.getElementById('calendarCitasMonthCount');
    if (!grid) return;

    if (titleEl) {
        titleEl.innerText = `${NOMBRES_MESES[mes]} ${anio}`;
    }

    grid.innerHTML = '';

    const hoy = new Date();
    const hoyStr = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;

    // Primer día del mes (0 = Domingo, 1 = Lunes, ..., 6 = Sábado)
    const primerDia = new Date(anio, mes, 1);
    let diaSemanaInicio = primerDia.getDay() - 1; // Lunes = 0, Domingo = 6
    if (diaSemanaInicio === -1) diaSemanaInicio = 6;

    const diasEnMes = new Date(anio, mes + 1, 0).getDate();
    const diasEnMesAnterior = new Date(anio, mes, 0).getDate();

    // Filtros activos
    const inputBusq = document.getElementById('busquedaCitasInput');
    const textoBusq = inputBusq ? inputBusq.value.toLowerCase().trim() : '';

    let citasEnEsteMes = 0;

    // Días de relleno mes anterior
    for (let p = diaSemanaInicio - 1; p >= 0; p--) {
        const diaNum = diasEnMesAnterior - p;
        const cell = document.createElement('div');
        cell.className = 'calendar-cell other-month';
        cell.innerHTML = `
            <div class="calendar-cell-header">
                <span class="calendar-cell-num">${diaNum}</span>
            </div>
        `;
        grid.appendChild(cell);
    }

    // Días del mes actual
    for (let d = 1; d <= diasEnMes; d++) {
        const fechaStr = `${anio}-${String(mes + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const esHoy = (fechaStr === hoyStr);

        // Filtrar citas de este día
        const citasDelDia = todasLasCitas.filter(c => {
            if (!c.fecha_cita) return false;
            const fCita = c.fecha_cita.substring(0, 10);
            if (fCita !== fechaStr) return false;

            // Filtro estado
            const est = (c.estado || 'Programada').toLowerCase();
            const matchEst = (filtroCitaEstadoActual === 'TODAS' || filtroCitaEstadoActual === 'TODOS' || est === filtroCitaEstadoActual.toLowerCase());
            if (!matchEst) return false;

            // Filtro texto
            if (textoBusq !== '') {
                const todoTxt = `${c.folio || ''} ${c.agencia || ''} ${c.asunto || ''} ${c.tecnico_asignado || ''} ${c.descripcion || ''}`.toLowerCase();
                if (!todoTxt.includes(textoBusq)) return false;
            }

            return true;
        });

        citasEnEsteMes += citasDelDia.length;

        const cell = document.createElement('div');
        cell.className = `calendar-cell ${esHoy ? 'today' : ''}`;

        let eventsHtml = '';
        citasDelDia.forEach(cita => {
            const est = (cita.estado || 'Programada').toLowerCase();
            let statusClass = 'status-programada';
            if (est.includes('curso') || est.includes('atencion')) statusClass = 'status-en-curso';
            else if (est.includes('realizada') || est.includes('completada')) statusClass = 'status-realizada';
            else if (est.includes('cancelada')) statusClass = 'status-cancelada';

            const horaDisplay = (cita.hora_cita || '10:00').substring(0, 5);
            const jsonCita = JSON.stringify(cita).replace(/"/g, '&quot;');

            eventsHtml += `
                <div class="calendar-event-item ${statusClass}" onclick="event.stopPropagation(); abrirModalActualizarCita(${jsonCita})" title="Folio: ${cita.folio || ('CTA-'+cita.id)} | Asunto: ${cita.asunto || ''} | Sede: ${cita.agencia || ''}">
                    <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                        <span class="fw-bold" style="font-size: 0.68rem;"><i class="bi bi-clock me-1"></i>${horaDisplay}</span>
                        <span class="badge bg-dark bg-opacity-60 text-white text-truncate" style="font-size: 0.6rem; max-width: 80px;">${cita.agencia || ''}</span>
                    </div>
                    <div class="fw-semibold text-truncate" style="font-size: 0.72rem;">${cita.asunto || 'Cita Técnica'}</div>
                </div>
            `;
        });

        cell.innerHTML = `
            <div class="calendar-cell-header">
                <span class="calendar-cell-num">${d}</span>
                <button type="button" class="calendar-btn-quick-add" onclick="agendarCitaEnFecha('${fechaStr}')" title="Agendar cita el ${d} de ${NOMBRES_MESES[mes]}">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
            <div class="calendar-events-list">
                ${eventsHtml}
            </div>
        `;

        grid.appendChild(cell);
    }

    // Días de relleno siguiente mes
    const totalCeldasActuales = diaSemanaInicio + diasEnMes;
    const celdasRestantes = (7 - (totalCeldasActuales % 7)) % 7;
    for (let n = 1; n <= celdasRestantes; n++) {
        const cell = document.createElement('div');
        cell.className = 'calendar-cell other-month';
        cell.innerHTML = `
            <div class="calendar-cell-header">
                <span class="calendar-cell-num">${n}</span>
            </div>
        `;
        grid.appendChild(cell);
    }

    if (countEl) {
        countEl.innerText = `${citasEnEsteMes} ${citasEnEsteMes === 1 ? 'Cita en este mes' : 'Citas en este mes'}`;
    }
}

function filtrarCitasPorEstado(estado, btn) {
    filtroCitaEstadoActual = estado;
    document.querySelectorAll('.filter-cita-pill').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filtrarCitas();
}

function filtrarCitasTexto() {
    filtrarCitas();
}

function filtrarCitas() {
    const input = document.getElementById('busquedaCitasInput');
    const texto = input ? input.value.toLowerCase().trim() : '';
    const filas = document.querySelectorAll('#tbodyCitas tr.cita-row');
    let visibles = 0;

    filas.forEach(row => {
        const estadoRow = (row.getAttribute('data-estado') || '').toLowerCase();
        const textoRow = row.innerText.toLowerCase();

        const matchEstado = (filtroCitaEstadoActual === 'TODAS' || filtroCitaEstadoActual === 'TODOS' || estadoRow === filtroCitaEstadoActual.toLowerCase());
        const matchTexto = (texto === '' || textoRow.includes(texto));

        if (matchEstado && matchTexto) {
            row.style.display = '';
            visibles++;
        } else {
            row.style.display = 'none';
        }
    });

    const filaSin = document.getElementById('filaSinCitas');
    if (filaSin) {
        filaSin.style.display = (visibles === 0) ? '' : 'none';
    }

    // Sincronizar calendario mensual
    renderizarCalendarioCitas(calAnio, calMes);
}

function abrirModalActualizarCita(cita, origenTicketId = 0) {
    document.getElementById('citaActualizarId').value = cita.id;
    document.getElementById('citaActualizarOrigenTicketId').value = origenTicketId || cita.ticket_id || 0;
    document.getElementById('citaActualizarFolio').innerText = cita.folio || ('CTA-' + cita.id);
    document.getElementById('citaActualizarAgencia').innerText = cita.agencia || '';
    document.getElementById('citaActualizarAsunto').innerText = (cita.ticket_folio ? '[' + cita.ticket_folio + '] ' : '') + (cita.asunto || '');
    document.getElementById('citaActualizarEstado').value = cita.estado || 'Programada';
    document.getElementById('citaActualizarFecha').value = cita.fecha_cita || '';
    document.getElementById('citaActualizarHora').value = cita.hora_cita || '';
    document.getElementById('citaActualizarTecnico').value = cita.tecnico_asignado || '';
    document.getElementById('citaActualizarNotas').value = cita.notas_atencion || '';

    // Guardar fecha y hora originales para detectar cambios
    document.getElementById('citaOriginalFecha').value = (cita.fecha_cita || '').trim();
    document.getElementById('citaOriginalHora').value = (cita.hora_cita || '').trim();
    document.getElementById('citaMotivoCambio').value = '';
    document.getElementById('contenedorMotivoCambioCita').style.display = 'none';
    document.getElementById('citaMotivoCambio').removeAttribute('required');

    // Mostrar historial previo si existe
    const secHist = document.getElementById('citaHistorialReprogSec');
    const txtHist = document.getElementById('citaHistorialReprogTexto');
    if (secHist && txtHist) {
        if (cita.historial_cambios || cita.motivo_reprogramacion) {
            secHist.style.display = 'block';
            txtHist.innerText = cita.historial_cambios || cita.motivo_reprogramacion;
        } else {
            secHist.style.display = 'none';
            txtHist.innerText = '';
        }
    }

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalActualizarCita'));
    modal.show();
}

function verificarCambioFechaHoraCita() {
    const fOrig = (document.getElementById('citaOriginalFecha').value || '').trim();
    const hOrig = (document.getElementById('citaOriginalHora').value || '').trim();
    const fNueva = (document.getElementById('citaActualizarFecha').value || '').trim();
    const hNueva = (document.getElementById('citaActualizarHora').value || '').trim();

    const contenedor = document.getElementById('contenedorMotivoCambioCita');
    const txtMotivo = document.getElementById('citaMotivoCambio');

    if (fOrig !== '' && (fNueva !== fOrig || hNueva !== hOrig)) {
        if (contenedor) contenedor.style.display = 'block';
        if (txtMotivo) txtMotivo.setAttribute('required', 'required');
    } else {
        if (contenedor) contenedor.style.display = 'none';
        if (txtMotivo) txtMotivo.removeAttribute('required');
    }
}

function validarActualizarCita(form) {
    const fOrig = (document.getElementById('citaOriginalFecha').value || '').trim();
    const hOrig = (document.getElementById('citaOriginalHora').value || '').trim();
    const fNueva = (document.getElementById('citaActualizarFecha').value || '').trim();
    const hNueva = (document.getElementById('citaActualizarHora').value || '').trim();
    const txtMotivo = (document.getElementById('citaMotivoCambio').value || '').trim();

    if (fOrig !== '' && (fNueva !== fOrig || hNueva !== hOrig)) {
        if (!txtMotivo) {
            alert('⚠️ Has modificado la fecha u hora de la cita. Por política del departamento de TI, es obligatorio especificar el motivo / comentario del cambio.');
            document.getElementById('citaMotivoCambio').focus();
            return false;
        }
    }
    return true;
}

// ----------------------------------------------------
// Gráficas de Estadísticas (Chart.js)
// ----------------------------------------------------
let chartEstado = null;
let chartArea = null;
let chartAgencia = null;

function renderizarGraficasEstadisticas() {
    if (typeof Chart === 'undefined') return;

    // Configuración visual dark mode
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.08)';
    Chart.defaults.font.family = "'Segoe UI', Roboto, system-ui, -apple-system, sans-serif";

    // 1. Gráfica Estado (Doughnut)
    const ctxEstado = document.getElementById('chartTicketsEstado');
    if (ctxEstado) {
        if (chartEstado) chartEstado.destroy();
        const dataEst = <?php echo json_encode($conteoPorEstado); ?>;
        chartEstado = new Chart(ctxEstado, {
            type: 'doughnut',
            data: {
                labels: Object.keys(dataEst),
                datasets: [{
                    data: Object.values(dataEst),
                    backgroundColor: [
                        '#ef4444', // Abierto
                        '#f59e0b', // En Proceso
                        '#10b981', // Resuelto
                        '#64748b'  // Cerrado
                    ],
                    borderWidth: 2,
                    borderColor: '#1e293b'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 15 }
                    }
                },
                cutout: '65%'
            }
        });
    }

    // 2. Gráfica Por Área (Bar)
    const ctxArea = document.getElementById('chartTicketsArea');
    if (ctxArea) {
        if (chartArea) chartArea.destroy();
        const dataArea = <?php echo json_encode($conteoPorArea); ?>;
        const colorsArea = <?php 
            $cList = [];
            foreach (array_keys($conteoPorArea) as $ak) {
                $cList[] = $AREAS_SISTEMAS[$ak]['color'] ?? '#6366f1';
            }
            echo json_encode($cList);
        ?>;
        chartArea = new Chart(ctxArea, {
            type: 'bar',
            data: {
                labels: Object.keys(dataArea),
                datasets: [{
                    label: 'Tickets',
                    data: Object.values(dataArea),
                    backgroundColor: colorsArea.length > 0 ? colorsArea : '#6366f1',
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
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }

    // 3. Gráfica Por Agencia (Horizontal Bar)
    const ctxAgencia = document.getElementById('chartTicketsAgencia');
    if (ctxAgencia) {
        if (chartAgencia) chartAgencia.destroy();
        const dataAg = <?php 
            $labelsAg = [];
            $valsAg = [];
            foreach ($agenciasList as $nomAg => $infoAg) {
                $labelsAg[] = $nomAg;
                $valsAg[] = $infoAg['total'];
            }
            echo json_encode(['labels' => $labelsAg, 'values' => $valsAg]);
        ?>;
        chartAgencia = new Chart(ctxAgencia, {
            type: 'bar',
            data: {
                labels: dataAg.labels,
                datasets: [{
                    label: 'Total Tickets',
                    data: dataAg.values,
                    backgroundColor: '#06b6d4',
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }
}

// ----------------------------------------------------
// Galería 3D Coverflow de Sucursales
// ----------------------------------------------------
let indiceCoverflowActual = 0;
const totalCardsCoverflow = <?php echo count($agenciasList); ?>;
let autoCoverflowInterval = null;
let autoCoverflowActivo = true;

function actualizarCoverflow3D() {
    if (totalCardsCoverflow === 0) return;

    const cards = document.querySelectorAll('.coverflow-card');
    const dots = document.querySelectorAll('.coverflow-dot');
    const badge = document.getElementById('coverflowContadorBadge');

    cards.forEach((card, i) => {
        card.classList.remove('pos-center', 'pos-left', 'pos-right', 'pos-hidden-left', 'pos-hidden-right');

        if (totalCardsCoverflow === 1) {
            card.classList.add('pos-center');
            return;
        }

        if (totalCardsCoverflow === 2) {
            if (i === indiceCoverflowActual) {
                card.classList.add('pos-center');
            } else {
                card.classList.add('pos-right');
            }
            return;
        }

        // Distancia circular mínima para carrusel infinito en 3D
        let diff = i - indiceCoverflowActual;
        while (diff > totalCardsCoverflow / 2) diff -= totalCardsCoverflow;
        while (diff < -totalCardsCoverflow / 2) diff += totalCardsCoverflow;

        if (diff === 0) {
            card.classList.add('pos-center');
        } else if (diff === -1) {
            card.classList.add('pos-left');
        } else if (diff === 1) {
            card.classList.add('pos-right');
        } else if (diff < -1) {
            card.classList.add('pos-hidden-left');
        } else {
            card.classList.add('pos-hidden-right');
        }
    });

    // Actualizar dots indicadores
    dots.forEach((dot, i) => {
        if (i === indiceCoverflowActual) {
            dot.classList.add('active');
        } else {
            dot.classList.remove('active');
        }
    });

    if (badge) {
        badge.innerText = `Agencia ${indiceCoverflowActual + 1} de ${totalCardsCoverflow}`;
    }
}

function cambiar3DCoverflow(delta) {
    if (totalCardsCoverflow <= 1) return;
    indiceCoverflowActual = (indiceCoverflowActual + delta + totalCardsCoverflow) % totalCardsCoverflow;
    actualizarCoverflow3D();
}

function irA3DCoverflow(idx) {
    if (idx < 0 || idx >= totalCardsCoverflow) return;
    indiceCoverflowActual = idx;
    actualizarCoverflow3D();
}

function clickCard3D(idx, nomAgencia) {
    if (idx === indiceCoverflowActual) {
        mostrarHistorialAgencia(nomAgencia);
    } else {
        irA3DCoverflow(idx);
    }
}

function iniciarAutoCoverflow() {
    detenerAutoCoverflow();
    if (totalCardsCoverflow > 1 && autoCoverflowActivo) {
        autoCoverflowInterval = setInterval(() => {
            cambiar3DCoverflow(1);
        }, 5500);
    }
}

function detenerAutoCoverflow() {
    if (autoCoverflowInterval) {
        clearInterval(autoCoverflowInterval);
        autoCoverflowInterval = null;
    }
}

function toggleAutoCoverflow() {
    autoCoverflowActivo = !autoCoverflowActivo;
    const icon = document.getElementById('iconAutoCoverflow');
    const txt = document.getElementById('txtAutoCoverflow');
    if (autoCoverflowActivo) {
        if (icon) icon.className = 'bi bi-pause-fill';
        if (txt) txt.innerText = 'Pausar';
        iniciarAutoCoverflow();
    } else {
        if (icon) icon.className = 'bi bi-play-fill';
        if (txt) txt.innerText = 'Reanudar';
        detenerAutoCoverflow();
    }
}

// Navegación con teclado (Flechas izquierda y derecha)
window.addEventListener('keydown', (e) => {
    const vistaAg = document.getElementById('vistaAgencias');
    if (vistaAg && vistaAg.style.display !== 'none') {
        if (e.key === 'ArrowLeft') {
            cambiar3DCoverflow(-1);
        } else if (e.key === 'ArrowRight') {
            cambiar3DCoverflow(1);
        }
    }
});

// ----------------------------------------------------
// Router de inicio según URL
// ----------------------------------------------------
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const hasTicketVer = urlParams.has('ver') || urlParams.has('ticket_id');

    if (hasTicketVer) {
        // Vista de detalle de ticket activo: Mantener abierta y visible
        const secDetalle = document.getElementById('seccionTab_detalle');
        const secTickets = document.getElementById('seccionTab_tickets');
        const secCitas = document.getElementById('seccionTab_citas');
        const secStats = document.getElementById('seccionTab_estadisticas');
        const secAgentes = document.getElementById('seccionTab_agentes');
        if (secDetalle) secDetalle.style.display = 'block';
        if (secTickets) secTickets.style.display = 'none';
        if (secCitas) secCitas.style.display = 'none';
        if (secStats) secStats.style.display = 'none';
        if (secAgentes) secAgentes.style.display = 'none';

        ['tickets', 'citas', 'estadisticas', 'agentes'].forEach(t => {
            const btn = document.getElementById('sidebarBtn_' + t);
            if (btn) btn.classList.remove('active');
        });
        const btnTk = document.getElementById('sidebarBtn_tickets');
        if (btnTk) btnTk.classList.add('active');

        const titleEl = document.getElementById('topNavTituloSeccion');
        if (titleEl) {
            titleEl.innerHTML = '<i class="bi bi-headset text-info me-1"></i> Espacio de Trabajo del Ticket';
        }

        // Si se acaba de enviar una respuesta o actualización, scroll al chat
        if (urlParams.has('enviado')) {
            setTimeout(() => {
                const chatBox = document.querySelector('.chat-thread-container');
                if (chatBox) {
                    chatBox.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }
            }, 120);
        }
    } else {
        const tabParam = urlParams.get('tab') || 'tickets';
        cambiarTab(tabParam, false);

        if (tabParam === 'tickets') {
            const agParam = urlParams.get('agencia');
            if (agParam) {
                mostrarHistorialAgencia(agParam);
            } else {
                mostrarVistaAgencias();
            }
        }
    }

    // Inicializar Coverflow 3D y listeners de pausa en hover
    actualizarCoverflow3D();
    const stage = document.getElementById('coverflowStage');
    if (stage) {
        stage.addEventListener('mouseenter', detenerAutoCoverflow);
        stage.addEventListener('mouseleave', () => {
            if (autoCoverflowActivo) iniciarAutoCoverflow();
        });
    }
    // Restaurar estado de sidebar colapsado en escritorio
    if (window.innerWidth > 991 && localStorage.getItem('sidebar_tickets_collapsed') === '1') {
        const sidebar = document.getElementById('appSidebar');
        const btnTab = document.getElementById('btnShowSidebarTab');
        if (sidebar) sidebar.classList.add('collapsed');
        if (btnTab) btnTab.style.display = 'block';
    }

    iniciarAutoCoverflow();
});

// Navegación atrás / adelante del navegador
window.addEventListener('popstate', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const hasTicketVer = urlParams.has('ver') || urlParams.has('ticket_id');

    if (hasTicketVer) {
        window.location.reload();
        return;
    }

    const secDetalle = document.getElementById('seccionTab_detalle');
    if (secDetalle) secDetalle.style.display = 'none';

    const tabParam = urlParams.get('tab') || 'tickets';
    cambiarTab(tabParam, false);

    if (tabParam === 'tickets') {
        const agParam = urlParams.get('agencia');
        if (agParam) {
            mostrarHistorialAgencia(agParam);
        } else {
            mostrarVistaAgencias();
        }
    }
});
</script>
</body>
</html>
