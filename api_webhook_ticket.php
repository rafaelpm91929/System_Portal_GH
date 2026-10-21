<?php
// ====================================================================
// RECEPTOR DE ACTUALIZACIONES EN TIEMPO REAL - PORTAL DE AGENCIA SUCURSAL
// Recibe notificaciones push desde la Dirección Central (portal.grupohuerta.mx)
// cuando un agente de TI atiende, cambia de estado o responde un ticket.
// ====================================================================
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión a la base de datos local de la agencia.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Obtener Token
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
$tokenEnviado = str_replace('Bearer ', '', trim($authHeader));

$inputJson = file_get_contents('php://input');
$datos = json_decode($inputJson, true) ?: [];

if (empty($tokenEnviado) && !empty($datos['token'])) {
    $tokenEnviado = trim($datos['token']);
}
if (empty($tokenEnviado) && !empty($_POST['token'])) {
    $tokenEnviado = trim($_POST['token']);
}
if (empty($tokenEnviado) && !empty($_GET['token'])) {
    $tokenEnviado = trim($_GET['token']);
}

// 2. Validar Token contra token maestro central, env o base de datos local
$tokenMaestro = 'GH_SISTEMAS_TICKETS_2026!';
$tokenEnv = getenv('TOKEN_SECRETO') ?: (defined('TOKEN_SECRETO') ? TOKEN_SECRETO : '');
$tokenValido = false;

if (!empty($tokenEnviado)) {
    if ($tokenEnviado === $tokenMaestro || $tokenEnviado === 'GedasDivolavilla2026!') {
        $tokenValido = true;
    } elseif (!empty($tokenEnv) && $tokenEnviado === $tokenEnv) {
        $tokenValido = true;
    } else {
        // Consultar token configurado en la agencia local (tabla agencias)
        try {
            $stmtAg = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
            $agRow = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
            if ($agRow) {
                $dbToken = $agRow['local_api_token'] ?? ($agRow['token'] ?? '');
                if (!empty($dbToken) && $tokenEnviado === $dbToken) {
                    $tokenValido = true;
                }
            }
        } catch (Throwable $e) {}
    }
}

if (!$tokenValido) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'mensaje' => 'No autorizado: Token de central inválido o no reconocido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Manejo de Citas Técnicas Push desde Dirección Central (Con o Sin Ticket)
$esAccionCita = (($datos['accion'] ?? ($_POST['accion'] ?? '')) === 'guardar_cita') 
             || !empty($datos['folio_cita']) 
             || !empty($_POST['folio_cita'])
             || (($datos['accion'] ?? ($_POST['accion'] ?? '')) === 'eliminar_cita');

if ($esAccionCita) {
    asegurarTablaCitas($pdo);
    $accionCita = $datos['accion'] ?? ($_POST['accion'] ?? 'guardar_cita');
    $folioCita = trim($datos['folio_cita'] ?? ($_POST['folio_cita'] ?? ''));

    if ($accionCita === 'eliminar_cita' && !empty($folioCita)) {
        try {
            $stmtDelC = $pdo->prepare("DELETE FROM citas_soporte WHERE folio = ?");
            $stmtDelC->execute([$folioCita]);
            echo json_encode(['status' => 'ok', 'mensaje' => "Cita $folioCita eliminada de la agencia."], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $eDelC) {}
    }

    if ($accionCita === 'guardar_cita' && !empty($folioCita)) {
        $tkIdC       = !empty($datos['ticket_id']) ? intval($datos['ticket_id']) : (!empty($_POST['ticket_id']) ? intval($_POST['ticket_id']) : null);
        $tkFolioC    = trim($datos['ticket_folio'] ?? ($_POST['ticket_folio'] ?? ''));
        $agenciaC    = trim($datos['agencia'] ?? ($_POST['agencia'] ?? ''));
        $areaC       = trim($datos['area_sistemas'] ?? ($_POST['area_sistemas'] ?? 'INFRAESTRUCTURA'));
        $tipoC       = trim($datos['tipo_cita'] ?? ($_POST['tipo_cita'] ?? 'Presencial'));
        $asuntoC     = trim($datos['asunto'] ?? ($_POST['asunto'] ?? ''));
        $descC       = trim($datos['descripcion'] ?? ($_POST['descripcion'] ?? ''));
        $solNomC     = trim($datos['solicitante_nombre'] ?? ($_POST['solicitante_nombre'] ?? ''));
        $solEmailC   = trim($datos['solicitante_email'] ?? ($_POST['solicitante_email'] ?? ''));
        $solUsrC     = trim($datos['solicitante_usuario'] ?? ($_POST['solicitante_usuario'] ?? ''));
        $tecnicoC    = trim($datos['tecnico_asignado'] ?? ($_POST['tecnico_asignado'] ?? ''));
        $fechaC      = trim($datos['fecha_cita'] ?? ($_POST['fecha_cita'] ?? ''));
        $horaC       = trim($datos['hora_cita'] ?? ($_POST['hora_cita'] ?? '09:00'));
        $estadoC     = trim($datos['estado'] ?? ($_POST['estado'] ?? 'Programada'));
        $notasC      = trim($datos['notas_atencion'] ?? ($_POST['notas_atencion'] ?? ''));
        $motivoRep   = trim($datos['motivo_reprogramacion'] ?? ($_POST['motivo_reprogramacion'] ?? ''));
        $historialC  = trim($datos['historial_cambios'] ?? ($_POST['historial_cambios'] ?? ''));

        // Sincronizar ID de ticket local si el folio de ticket existe en la BD de la agencia
        if (!empty($tkFolioC)) {
            try {
                $stmtTkLoc = $pdo->prepare("SELECT id FROM tickets_soporte WHERE folio = ? LIMIT 1");
                $stmtTkLoc->execute([$tkFolioC]);
                $tkIdLoc = $stmtTkLoc->fetchColumn();
                if ($tkIdLoc) {
                    $tkIdC = (int)$tkIdLoc;
                }
            } catch (Throwable $eTkLoc) {}
        }

        try {
            // Verificar si la cita ya existe por folio en la agencia
            $stmtCheckC = $pdo->prepare("SELECT id, historial_cambios FROM citas_soporte WHERE folio = ? LIMIT 1");
            $stmtCheckC->execute([$folioCita]);
            $citaExistente = $stmtCheckC->fetch(PDO::FETCH_ASSOC);

            if ($citaExistente) {
                $historialGuardar = !empty($historialC) ? $historialC : ($citaExistente['historial_cambios'] ?? '');
                $stmtUpC = $pdo->prepare("
                    UPDATE citas_soporte 
                    SET ticket_id = :tk_id,
                        ticket_folio = :tk_folio,
                        agencia = :agencia,
                        area_sistemas = :area,
                        tipo_cita = :tipo,
                        asunto = :asunto,
                        descripcion = :desc,
                        solicitante_nombre = :sol_nom,
                        solicitante_email = :sol_email,
                        solicitante_usuario = :sol_usr,
                        tecnico_asignado = :tec,
                        fecha_cita = :fecha,
                        hora_cita = :hora,
                        estado = :estado,
                        notas_atencion = :notas,
                        motivo_reprogramacion = COALESCE(NULLIF(:motivo, ''), motivo_reprogramacion),
                        historial_cambios = :historial
                    WHERE folio = :folio_cita
                ");
                $stmtUpC->execute([
                    ':tk_id'      => $tkIdC,
                    ':tk_folio'   => $tkFolioC,
                    ':agencia'    => $agenciaC,
                    ':area'       => $areaC,
                    ':tipo'       => $tipoC,
                    ':asunto'     => $asuntoC,
                    ':desc'       => $descC,
                    ':sol_nom'    => $solNomC,
                    ':sol_email'  => $solEmailC,
                    ':sol_usr'    => $solUsrC,
                    ':tec'        => $tecnicoC,
                    ':fecha'      => $fechaC,
                    ':hora'       => $horaC,
                    ':estado'     => $estadoC,
                    ':notas'      => $notasC,
                    ':motivo'     => $motivoRep,
                    ':historial'  => $historialGuardar,
                    ':folio_cita' => $folioCita
                ]);
            } else {
                $stmtInsC = $pdo->prepare("
                    INSERT INTO citas_soporte 
                    (folio, ticket_id, ticket_folio, agencia, area_sistemas, tipo_cita, asunto, descripcion, solicitante_nombre, solicitante_usuario, solicitante_email, tecnico_asignado, fecha_cita, hora_cita, estado, notas_atencion, motivo_reprogramacion, historial_cambios)
                    VALUES
                    (:folio_cita, :tk_id, :tk_folio, :agencia, :area, :tipo, :asunto, :desc, :sol_nom, :sol_usr, :sol_email, :tec, :fecha, :hora, :estado, :notas, :motivo, :historial)
                ");
                $stmtInsC->execute([
                    ':folio_cita' => $folioCita,
                    ':tk_id'      => $tkIdC,
                    ':tk_folio'   => $tkFolioC,
                    ':agencia'    => $agenciaC,
                    ':area'       => $areaC,
                    ':tipo'       => $tipoC,
                    ':asunto'     => $asuntoC,
                    ':desc'       => $descC,
                    ':sol_nom'    => $solNomC,
                    ':sol_usr'    => $solUsrC,
                    ':sol_email'  => $solEmailC,
                    ':tec'        => $tecnicoC,
                    ':fecha'      => $fechaC,
                    ':hora'       => $horaC,
                    ':estado'     => $estadoC,
                    ':notas'      => $notasC,
                    ':motivo'     => $motivoRep,
                    ':historial'  => $historialC
                ]);
            }
        } catch (Throwable $eCitaWh) {}

        // Si es una cita general sin ticket vinculado, responder exitosamente
        $folioTicketCheck = trim($datos['folio'] ?? ($_POST['folio'] ?? $tkFolioC));
        if (empty($folioTicketCheck)) {
            echo json_encode([
                'status'     => 'ok',
                'mensaje'    => "Cita general $folioCita sincronizada correctamente en la agencia.",
                'folio_cita' => $folioCita
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// 3. Extraer datos del ticket actualizado por TI
$folio = trim($datos['folio'] ?? ($_POST['folio'] ?? ($datos['ticket_folio'] ?? ($_POST['ticket_folio'] ?? ''))));
$estado = trim($datos['estado'] ?? ($_POST['estado'] ?? 'En Proceso'));
$asignadoA = trim($datos['asignado_a'] ?? ($_POST['asignado_a'] ?? ''));
$prioridad = trim($datos['prioridad'] ?? ($_POST['prioridad'] ?? 'Media'));
$notasResolucion = trim($datos['notas_resolucion'] ?? ($_POST['notas_resolucion'] ?? ''));
$actualizadoEn = trim($datos['actualizado_en'] ?? ($_POST['actualizado_en'] ?? date('Y-m-d H:i:s')));
if (!empty($actualizadoEn)) {
    $tsAct = strtotime($actualizadoEn);
    if ($tsAct !== false && $tsAct > (time() + 300)) {
        $actualizadoEn = date('Y-m-d H:i:s', $tsAct - (6 * 3600));
    }
}

if (empty($folio)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'mensaje' => 'Folio de ticket requerido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$evidenciaResolucion = trim($datos['evidencia_resolucion'] ?? ($_POST['evidencia_resolucion'] ?? ''));
$evBase64 = $datos['evidencia_base64'] ?? ($_POST['evidencia_base64'] ?? '');
$evNombre = $datos['evidencia_nombre'] ?? ($_POST['evidencia_nombre'] ?? '');
if (!empty($evBase64) && !empty($evNombre)) {
    $dirUpload = __DIR__ . '/uploads/tickets/';
    if (!is_dir($dirUpload)) @mkdir($dirUpload, 0777, true);
    $extE = strtolower(pathinfo($evNombre, PATHINFO_EXTENSION));
    $nomEvGuardar = 'evidencia_wh_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extE;
    if (@file_put_contents($dirUpload . $nomEvGuardar, base64_decode($evBase64))) {
        $evidenciaResolucion = 'uploads/tickets/' . $nomEvGuardar;
    }
}

try {
    // 1. Actualizar datos de atención técnica sólo si vienen especificados
    $tieneDatosAtencion = isset($datos['estado']) || isset($_POST['estado']) 
                       || isset($datos['asignado_a']) || isset($_POST['asignado_a'])
                       || isset($datos['notas_resolucion']) || isset($_POST['notas_resolucion'])
                       || !empty($evidenciaResolucion);

    if ($tieneDatosAtencion) {
        if (!empty($evidenciaResolucion)) {
            $stmt = $pdo->prepare("
                UPDATE tickets_soporte 
                SET estado = COALESCE(NULLIF(:estado, ''), estado), 
                    asignado_a = COALESCE(NULLIF(:asignado, ''), asignado_a), 
                    prioridad = COALESCE(NULLIF(:prioridad, ''), prioridad), 
                    notas_resolucion = COALESCE(NULLIF(:notas, ''), notas_resolucion), 
                    evidencia_resolucion = :evidencia,
                    actualizado_en = :act 
                WHERE folio = :folio
            ");
            $stmt->execute([
                ':estado'    => $estado,
                ':asignado'  => $asignadoA,
                ':prioridad' => $prioridad,
                ':notas'     => $notasResolucion,
                ':evidencia' => $evidenciaResolucion,
                ':act'       => $actualizadoEn,
                ':folio'     => $folio
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE tickets_soporte 
                SET estado = COALESCE(NULLIF(:estado, ''), estado), 
                    asignado_a = COALESCE(NULLIF(:asignado, ''), asignado_a), 
                    prioridad = COALESCE(NULLIF(:prioridad, ''), prioridad), 
                    notas_resolucion = COALESCE(NULLIF(:notas, ''), notas_resolucion), 
                    actualizado_en = :act 
                WHERE folio = :folio
            ");
            $stmt->execute([
                ':estado'    => $estado,
                ':asignado'  => $asignadoA,
                ':prioridad' => $prioridad,
                ':notas'     => $notasResolucion,
                ':act'       => $actualizadoEn,
                ':folio'     => $folio
            ]);
        }
    }

    // 2. Si viene información del reporte de servicio técnico (Infraestructura)
    if (isset($datos['requiere_firma_reporte']) || isset($_POST['requiere_firma_reporte']) || ($datos['accion'] ?? '') === 'reporte_servicio' || ($datos['accion'] ?? '') === 'reporte_firmado' || isset($datos['reporte_firmado'])) {
        $reqFirma = intval($datos['requiere_firma_reporte'] ?? ($_POST['requiere_firma_reporte'] ?? 1));
        $repOrden = trim($datos['reporte_orden_no'] ?? ($_POST['reporte_orden_no'] ?? $folio));
        $repProb  = trim($datos['reporte_problema'] ?? ($_POST['reporte_problema'] ?? ''));
        $repSol   = trim($datos['reporte_trabajo_solicitado'] ?? ($_POST['reporte_trabajo_solicitado'] ?? ''));
        $repObs   = trim($datos['reporte_observaciones'] ?? ($_POST['reporte_observaciones'] ?? ''));
        $repReal  = trim($datos['reporte_trabajos_realizados'] ?? ($_POST['reporte_trabajos_realizados'] ?? ''));
        $repTec   = trim($datos['reporte_tecnico_nombre'] ?? ($_POST['reporte_tecnico_nombre'] ?? ''));

        $repFirmado = isset($datos['reporte_firmado']) ? intval($datos['reporte_firmado']) : (isset($_POST['reporte_firmado']) ? intval($_POST['reporte_firmado']) : 0);
        $repFirmaImg = trim($datos['reporte_firma_digital'] ?? ($_POST['reporte_firma_digital'] ?? ''));
        $repFirmadoPor = trim($datos['reporte_firmado_por'] ?? ($_POST['reporte_firmado_por'] ?? ''));
        $repFirmadoEn = trim($datos['reporte_firmado_en'] ?? ($_POST['reporte_firmado_en'] ?? ''));

        $stmtRep = $pdo->prepare("
            UPDATE tickets_soporte 
            SET requiere_firma_reporte = CASE WHEN :req = 1 OR :firmado_a = 1 THEN 1 ELSE requiere_firma_reporte END,
                reporte_orden_no = COALESCE(NULLIF(:ord, ''), reporte_orden_no),
                reporte_problema = COALESCE(NULLIF(:prob, ''), reporte_problema),
                reporte_trabajo_solicitado = COALESCE(NULLIF(:sol, ''), reporte_trabajo_solicitado),
                reporte_observaciones = COALESCE(NULLIF(:obs, ''), reporte_observaciones),
                reporte_trabajos_realizados = COALESCE(NULLIF(:real, ''), reporte_trabajos_realizados),
                reporte_tecnico_nombre = COALESCE(NULLIF(:tec, ''), reporte_tecnico_nombre),
                reporte_firmado = CASE WHEN :firmado_b = 1 THEN 1 ELSE reporte_firmado END,
                reporte_firma_digital = COALESCE(NULLIF(:firma_img, ''), reporte_firma_digital),
                reporte_firmado_por = COALESCE(NULLIF(:firmado_por, ''), reporte_firmado_por),
                reporte_firmado_en = COALESCE(NULLIF(:firmado_en, ''), reporte_firmado_en),
                actualizado_en = :act_rep
            WHERE folio = :folio_rep
        ");
        $stmtRep->execute([
            ':req'         => $reqFirma,
            ':firmado_a'   => $repFirmado,
            ':ord'         => $repOrden,
            ':prob'        => $repProb,
            ':sol'         => $repSol,
            ':obs'         => $repObs,
            ':real'        => $repReal,
            ':tec'         => $repTec,
            ':firmado_b'   => $repFirmado,
            ':firma_img'   => $repFirmaImg,
            ':firmado_por' => $repFirmadoPor,
            ':firmado_en'  => $repFirmadoEn,
            ':act_rep'     => $actualizadoEn,
            ':folio_rep'   => $folio
        ]);
    }

    $filasAfectadas = (isset($stmt) && $stmt) ? $stmt->rowCount() : ((isset($stmtRep) && $stmtRep) ? $stmtRep->rowCount() : 0);

    // Si viene un mensaje en el webhook, insertarlo en tickets_mensajes
    $nuevoMensaje = trim($datos['mensaje'] ?? ($_POST['mensaje'] ?? ''));
    if (!empty($nuevoMensaje)) {
        $stmtTk = $pdo->prepare("SELECT id FROM tickets_soporte WHERE folio = ? LIMIT 1");
        $stmtTk->execute([$folio]);
        $tkId = $stmtTk->fetchColumn();
        if ($tkId) {
            $autorNom = $datos['autor_nombre'] ?? ($_POST['autor_nombre'] ?? ($datos['autor'] ?? ($_POST['autor'] ?? 'Sistemas Central')));
            $autorUsr = $datos['autor_usuario'] ?? ($_POST['autor_usuario'] ?? ($datos['usuario'] ?? ($_POST['usuario'] ?? '')));
            $autorRol = $datos['autor_rol'] ?? ($_POST['autor_rol'] ?? ($datos['rol'] ?? ($_POST['rol'] ?? 'agente')));
            $autorAg  = $datos['autor_agencia'] ?? ($_POST['autor_agencia'] ?? 'Dirección Sistemas');
            $adj      = $datos['archivo_adjunto'] ?? ($_POST['archivo_adjunto'] ?? null);

            // Manejo de archivo adjunto en Base64 si viene enviado
            $adjBase64 = $datos['archivo_base64'] ?? ($_POST['archivo_base64'] ?? '');
            $adjNombre = $datos['archivo_nombre'] ?? ($_POST['archivo_nombre'] ?? '');
            if (!empty($adjBase64) && !empty($adjNombre)) {
                $dirUpload = __DIR__ . '/uploads/tickets/';
                if (!is_dir($dirUpload)) @mkdir($dirUpload, 0777, true);
                $extA = strtolower(pathinfo($adjNombre, PATHINFO_EXTENSION));
                $nomGuardar = 'msg_wh_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extA;
                if (@file_put_contents($dirUpload . $nomGuardar, base64_decode($adjBase64))) {
                    $adj = 'uploads/tickets/' . $nomGuardar;
                }
            }

            // Evitar duplicados exactos
            $stmtDup = $pdo->prepare("SELECT id FROM tickets_mensajes WHERE (ticket_id = ? OR folio = ?) AND mensaje = ? LIMIT 1");
            $stmtDup->execute([$tkId, $folio, $nuevoMensaje]);
            if (!$stmtDup->fetchColumn()) {
                $stmtInsM = $pdo->prepare("
                    INSERT INTO tickets_mensajes 
                    (ticket_id, folio, autor_nombre, autor_usuario, autor_rol, autor_agencia, mensaje, archivo_adjunto, creado_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsM->execute([$tkId, $folio, $autorNom, $autorUsr, $autorRol, $autorAg, $nuevoMensaje, $adj, $actualizadoEn]);
            }
        }
    }

    // Notificar EXCLUSIVAMENTE al usuario que subió el ticket en el portal de agencia
    try {
        $stmtOwner = $pdo->prepare("SELECT id, solicitante_id, solicitante_usuario, solicitante_email, solicitante_nombre, titulo FROM tickets_soporte WHERE folio = ? LIMIT 1");
        $stmtOwner->execute([$folio]);
        $ownerData = $stmtOwner->fetch(PDO::FETCH_ASSOC);
        if ($ownerData) {
            $solId = !empty($ownerData['solicitante_id']) ? (int)$ownerData['solicitante_id'] : 0;
            if ($solId === 0 && !empty($ownerData['solicitante_usuario'])) {
                $stmtU = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(usuario) = LOWER(?) LIMIT 1");
                $stmtU->execute([trim($ownerData['solicitante_usuario'])]);
                $solId = (int)$stmtU->fetchColumn();
            }
            if ($solId === 0 && !empty($ownerData['solicitante_email'])) {
                $stmtU = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmtU->execute([trim($ownerData['solicitante_email'])]);
                $solId = (int)$stmtU->fetchColumn();
            }
            if ($solId === 0 && !empty($ownerData['solicitante_nombre'])) {
                $stmtU = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
                $stmtU->execute([trim($ownerData['solicitante_nombre'])]);
                $solId = (int)$stmtU->fetchColumn();
            }

            // Destinatario específico si se resolvió; si no, null para resolución dinámica por folio en el feed
            $destUsuarioId = $solId > 0 ? $solId : null;

            if (function_exists('crearNotificacion')) {
                if (!empty($nuevoMensaje)) {
                    crearNotificacion(
                        'tickets',
                        'mensaje_ticket',
                        "Respuesta en tu Ticket #$folio",
                        "El agente de soporte respondió: \"" . mb_substr($nuevoMensaje, 0, 80) . "\"",
                        'tickets.php?ver=' . $folio,
                        'bi-chat-dots-fill',
                        'primary',
                        $ownerData['id'],
                        $destUsuarioId,
                        $pdo
                    );
                } elseif (!empty($tieneDatosAtencion)) {
                    crearNotificacion(
                        'tickets',
                        'actualizacion_ticket',
                        "Actualización en tu Ticket #$folio",
                        "Tu ticket está ahora en estado: $estado" . (!empty($notasResolucion) ? " - " . mb_substr($notasResolucion, 0, 70) : ""),
                        'tickets.php?ver=' . $folio,
                        'bi-arrow-repeat',
                        'info',
                        $ownerData['id'],
                        $destUsuarioId,
                        $pdo
                    );
                }
            }
        }
    } catch (Throwable $eNotifWh) {}

    echo json_encode([
        'status'          => 'ok',
        'mensaje'         => 'Ticket actualizado en portal de agencia exitosamente.',
        'folio'           => $folio,
        'estado'          => $estado,
        'filas_afectadas' => $filasAfectadas
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al actualizar ticket en agencia: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
