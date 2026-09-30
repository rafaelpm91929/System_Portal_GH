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

$tokenEsperado = getenv('TOKEN_SECRETO') ?: 'GedasDivolavilla2026!';
if ($tokenEnviado !== $tokenEsperado && $tokenEnviado !== 'GH_SISTEMAS_TICKETS_2026!') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'mensaje' => 'No autorizado: Token de central inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Extraer datos del ticket actualizado por TI
$folio = trim($datos['folio'] ?? ($_POST['folio'] ?? ''));
$estado = trim($datos['estado'] ?? ($_POST['estado'] ?? 'En Proceso'));
$asignadoA = trim($datos['asignado_a'] ?? ($_POST['asignado_a'] ?? ''));
$prioridad = trim($datos['prioridad'] ?? ($_POST['prioridad'] ?? 'Media'));
$notasResolucion = trim($datos['notas_resolucion'] ?? ($_POST['notas_resolucion'] ?? ''));
$actualizadoEn = trim($datos['actualizado_en'] ?? ($_POST['actualizado_en'] ?? date('Y-m-d H:i:s')));

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
