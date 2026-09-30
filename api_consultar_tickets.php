<?php
// ====================================================================
// API CONSULTA Y SINCRONIZACIÓN DE TICKETS - PORTAL MAESTRO GRUPO HUERTA
// Permite a los portales de agencias sucursales (ej. rama main) consultar
// en vivo el estado, técnico asignado y notas de resolución de sus tickets.
// ====================================================================
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';
include_once __DIR__ . '/config_agencias.php';

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión a la base de datos central.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Obtener Token de la petición
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

// 2. Validar Token contra Catálogo de Agencias o Token Maestro
$tokenMaestro = 'GH_SISTEMAS_TICKETS_2026!';
$tokenValido = ($tokenEnviado === $tokenMaestro);

if (!$tokenValido && !empty($CATALOGO_AGENCIAS)) {
    foreach ($CATALOGO_AGENCIAS as $ag) {
        if (!empty($ag['token']) && $ag['token'] === $tokenEnviado) {
            $tokenValido = true;
            break;
        }
    }
}

// Token por defecto configurado en agencias
if (!$tokenValido && $tokenEnviado === 'GedasDivolavilla2026!') {
    $tokenValido = true;
}

if (!$tokenValido) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'mensaje' => 'No autorizado: Token de agencia inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Parámetros de búsqueda
$foliosParam = $datos['folios'] ?? ($_POST['folios'] ?? ($_GET['folios'] ?? null));
$agenciaParam = trim($datos['agencia'] ?? ($_POST['agencia'] ?? ($_GET['agencia'] ?? '')));
$desdeFecha = trim($datos['desde_fecha'] ?? ($_POST['desde_fecha'] ?? ($_GET['desde_fecha'] ?? '')));

$whereClauses = [];
$params = [];

// A. Consulta específica por lista de folios
if (!empty($foliosParam)) {
    $foliosArr = is_array($foliosParam) ? $foliosParam : array_filter(array_map('trim', explode(',', $foliosParam)));
    if (!empty($foliosArr)) {
        $placeholders = implode(',', array_fill(0, count($foliosArr), '?'));
        $whereClauses[] = "folio IN ($placeholders)";
        foreach ($foliosArr as $f) {
            $params[] = $f;
        }
    }
}

// B. Consulta por Agencia
if (!empty($agenciaParam) && empty($foliosParam)) {
    $whereClauses[] = "LOWER(solicitante_agencia) LIKE ?";
    $params[] = '%' . strtolower($agenciaParam) . '%';
}

// C. Filtro opcional por fecha de última actualización
if (!empty($desdeFecha)) {
    $whereClauses[] = "(actualizado_en >= ? OR creado_en >= ?)";
    $params[] = $desdeFecha;
    $params[] = $desdeFecha;
}

$sql = "SELECT id, folio, area_sistemas, titulo, descripcion, prioridad, estado, asignado_a, notas_resolucion, creado_en, actualizado_en, solicitante_agencia FROM tickets_soporte";
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(' AND ', $whereClauses);
}
$sql .= " ORDER BY id DESC LIMIT 150";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'         => 'ok',
        'mensaje'        => 'Tickets sincronizados correctamente.',
        'total'          => count($tickets),
        'timestamp'      => date('Y-m-d H:i:s'),
        'tickets'        => $tickets
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al consultar tickets: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
