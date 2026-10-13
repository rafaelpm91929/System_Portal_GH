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

// 2. Extraer datos del ticket actualizado por TI
$folio = trim($datos['folio'] ?? ($_POST['folio'] ?? ''));
$estado = trim($datos['estado'] ?? ($_POST['estado'] ?? 'En Proceso'));
$asignadoA = trim($datos['asignado_a'] ?? ($_POST['asignado_a'] ?? ''));
$prioridad = trim($datos['prioridad'] ?? ($_POST['prioridad'] ?? 'Media'));
$notasResolucion = trim($datos['notas_resolucion'] ?? ($_POST['notas_resolucion'] ?? ''));
$actualizadoEn = trim($datos['actualizado_en'] ?? ($_POST['actualizado_en'] ?? date('Y-m-d H:i:s')));
if (!empty($actualizadoEn)) {
    $tsAct = strtotime($actualizadoEn);
    if ($tsAct !== false && $tsAct > (time() + 1800)) {
        $actualizadoEn = date('Y-m-d H:i:s', $tsAct - (6 * 3600));
    }
}

if (empty($folio)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'mensaje' => 'Folio de ticket requerido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE tickets_soporte 
        SET estado = :estado, 
            asignado_a = :asignado, 
            prioridad = :prioridad, 
            notas_resolucion = :notas, 
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

    $filasAfectadas = $stmt->rowCount();

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
