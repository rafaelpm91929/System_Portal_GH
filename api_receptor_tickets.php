<?php
// ====================================================================
// RECEPTOR CENTRAL DE TICKETS DE SOPORTE - PORTAL MAESTRO GRUPO HUERTA
// Recibe tickets enviados desde cualquier cPanel de agencia sucursal
// ====================================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';
include_once __DIR__ . '/config_agencias.php';

if ($pdo) {
    asegurarTablaTickets($pdo);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión a la base de datos central.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Obtener Token de la petición
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
$tokenEnviado = str_replace('Bearer ', '', trim($authHeader));

$inputJson = file_get_contents('php://input');
$datos = json_decode($inputJson, true);

if (empty($tokenEnviado) && !empty($datos['token'])) {
    $tokenEnviado = trim($datos['token']);
}
if (empty($tokenEnviado) && !empty($_POST['token'])) {
    $tokenEnviado = trim($_POST['token']);
}

// 2. Validar Token contra el Catálogo de Agencias o Token Maestro
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

if (!$tokenValido) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'mensaje' => 'No autorizado: Token de agencia inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Procesar datos (soporta JSON o POST tradicional)
if (!$datos && !empty($_POST)) {
    $datos = $_POST;
}

$agenciaNombre = trim($datos['agencia'] ?? ($datos['solicitante_agencia'] ?? 'Agencia Desconocida'));
$area = strtoupper(trim($datos['area_sistemas'] ?? ($datos['area'] ?? 'INFRAESTRUCTURA')));
$titulo = trim($datos['titulo'] ?? '');
$descripcion = trim($datos['descripcion'] ?? '');
$prioridad = ucfirst(strtolower(trim($datos['prioridad'] ?? 'Media')));
$solicitanteNombre = trim($datos['solicitante_nombre'] ?? 'Usuario Sucursal');
$solicitanteEmail = trim($datos['solicitante_email'] ?? '');

if (empty($titulo) || empty($descripcion)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'mensaje' => 'Título y descripción son campos obligatorios.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. Procesar archivo adjunto si viene en base64 o multipart
$rutaAdjunto = null;
$dirUploads = __DIR__ . '/uploads/tickets/';
if (!is_dir($dirUploads)) {
    @mkdir($dirUploads, 0777, true);
}

// A. Adjunto en Base64
if (!empty($datos['archivo_base64']) && !empty($datos['archivo_nombre'])) {
    $ext = strtolower(pathinfo($datos['archivo_nombre'], PATHINFO_EXTENSION));
    $nombreLimpio = 'remoto_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dirUploads . $nombreLimpio;
    $contenido = base64_decode($datos['archivo_base64']);
    if ($contenido !== false && file_put_contents($destino, $contenido)) {
        $rutaAdjunto = 'uploads/tickets/' . $nombreLimpio;
    }
}
// B. Adjunto en $_FILES
elseif (isset($_FILES['archivo_adjunto']) && $_FILES['archivo_adjunto']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['archivo_adjunto']['name'], PATHINFO_EXTENSION));
    $nombreLimpio = 'remoto_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dirUploads . $nombreLimpio;
    if (move_uploaded_file($_FILES['archivo_adjunto']['tmp_name'], $destino)) {
        $rutaAdjunto = 'uploads/tickets/' . $nombreLimpio;
    }
}

// 5. Generar Folio e Insertar en Base de Datos Central
try {
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    // Verificación anti-duplicados por recargas accidentales (últimos 60 segundos)
    $sqlFechaCond = ($driver === 'sqlite') ? "creado_en >= datetime('now', '-60 seconds')" : "creado_en >= DATE_SUB(NOW(), INTERVAL 60 SECOND)";
    $stmtDup = $pdo->prepare("
        SELECT id, folio FROM tickets_soporte 
        WHERE solicitante_agencia = :ag 
          AND titulo = :tit 
          AND area_sistemas = :area 
          AND $sqlFechaCond
        ORDER BY id DESC LIMIT 1
    ");
    $stmtDup->execute([':ag' => $agenciaNombre, ':tit' => $titulo, ':area' => $area]);
    $dup = $stmtDup->fetch(PDO::FETCH_ASSOC);

    if ($dup && !empty($dup['folio'])) {
        echo json_encode([
            'status'         => 'ok',
            'mensaje'        => 'Ticket recibido previamente (duplicado detectado y prevenido).',
            'ticket_id'      => $dup['id'],
            'folio'          => $dup['folio'],
            'agencia'        => $agenciaNombre,
            'area_sistemas'  => $area,
            'duplicado'      => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($driver === 'sqlite') {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM tickets_soporte");
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `tickets_soporte`");
    }
    $nextNum = ($countStmt ? (int)$countStmt->fetchColumn() : 0) + 1;
    $folio = 'TK-' . date('Y') . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO tickets_soporte 
        (folio, area_sistemas, titulo, descripcion, prioridad, estado, solicitante_nombre, solicitante_email, solicitante_agencia, archivo_adjunto)
        VALUES
        (:folio, :area, :titulo, :desc, :prio, 'Abierto', :sol_nom, :sol_em, :sol_ag, :archivo)
    ");
    $stmt->execute([
        ':folio'    => $folio,
        ':area'     => $area,
        ':titulo'   => $titulo,
        ':desc'     => $descripcion,
        ':prio'     => $prioridad,
        ':sol_nom'  => $solicitanteNombre,
        ':sol_em'   => $solicitanteEmail,
        ':sol_ag'   => $agenciaNombre,
        ':archivo'  => $rutaAdjunto
    ]);

    $idGenerado = $pdo->lastInsertId();

    echo json_encode([
        'status'         => 'ok',
        'mensaje'        => 'Ticket recibido y registrado correctamente en la Dirección de Sistemas.',
        'ticket_id'      => $idGenerado,
        'folio'          => $folio,
        'agencia'        => $agenciaNombre,
        'area_sistemas'  => $area,
        'creado_en'      => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al guardar el ticket: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
