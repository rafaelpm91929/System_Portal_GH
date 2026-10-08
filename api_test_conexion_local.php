<?php
// ====================================================================
// TEST DE CONEXIÓN AL SERVIDOR LOCAL / DMS - PORTAL DE AGENCIA
// Valida sockets, credenciales PDO y conectividad entre cPanel y la agencia
// ====================================================================
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

// Verificación de sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['exito' => false, 'error' => 'Sesión expirada o no autorizada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$inputJson = file_get_contents('php://input');
$data = json_decode($inputJson, true) ?: [];
if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

// Cargar parámetros enviados o de la base de datos de agencias
$host    = trim($data['host'] ?? '');
$port    = intval($data['port'] ?? 3306);
$dbname  = trim($data['dbname'] ?? '');
$user    = trim($data['user'] ?? '');
$pass    = trim($data['pass'] ?? '');
$tipo_db = strtolower(trim($data['tipo_db'] ?? 'mysql'));
$token   = trim($data['token'] ?? '');

// Si vienen vacíos, consultar los guardados en la BD
if (empty($host) && $pdo) {
    try {
        $stmtAg = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
        $ag = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
        if ($ag) {
            $host    = $ag['local_db_host'] ?: '127.0.0.1';
            $port    = intval($ag['local_db_port'] ?: 3306);
            $dbname  = $ag['local_db_name'] ?: '';
            $user    = $ag['local_db_user'] ?: '';
            $pass    = $ag['local_db_pass'] ?: '';
            $tipo_db = strtolower($ag['local_tipo_db'] ?: 'mysql');
            $token   = $ag['local_api_token'] ?: '';
        }
    } catch (Throwable $t) {}
}

if (empty($host)) {
    echo json_encode([
        'exito' => false,
        'mensaje' => 'Debes especificar la IP o Host del servidor local.',
        'detalles' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($port <= 0) {
    $port = ($tipo_db === 'sqlserver') ? 1433 : 3306;
}

// 1. Detectar si la IP es de Red Privada / Intranet Local (RFC 1918)
$esIpPrivada = false;
$ipResolved = gethostbyname($host);
if (
    $host === 'localhost' ||
    $host === '127.0.0.1' ||
    strpos($ipResolved, '127.') === 0 ||
    strpos($ipResolved, '192.168.') === 0 ||
    strpos($ipResolved, '10.') === 0 ||
    preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ipResolved)
) {
    $esIpPrivada = true;
}

// 2. Comprobar socket TCP (Timeout corto de 2.5 segundos)
$socketOk = false;
$socketErrorMsg = '';
$startSocket = microtime(true);
$fp = @fsockopen($host, $port, $errno, $errstr, 2.5);
$socketMs = round((microtime(true) - $startSocket) * 1000, 2);

if ($fp) {
    $socketOk = true;
    fclose($fp);
} else {
    $socketErrorMsg = $errstr ?: "No se pudo establecer socket TCP con $host en puerto $port (Código: $errno)";
}

// 3. Probar conexión PDO si el socket fue alcanzable o si estamos en localhost/misma red
$pdoOk = false;
$pdoMsg = '';
$dbVersion = '';

if ($socketOk && !empty($user)) {
    try {
        if ($tipo_db === 'sqlserver') {
            $dsn = "sqlsrv:Server=$host,$port;Database=$dbname";
            $pdoLocal = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 4
            ]);
        } else {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $pdoLocal = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 4
            ]);
        }
        $pdoOk = true;
        try {
            $dbVersion = $pdoLocal->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $tV) {}
        $pdoMsg = "Conexión PDO establecida con éxito a la base de datos '$dbname'.";
    } catch (Throwable $ePdo) {
        $pdoOk = false;
        $pdoMsg = $ePdo->getMessage();
    }
}

// 4. Consultar última sincronización registrada en cPanel
$ultimaSync = null;
$totalRegistrosSync = 0;
if ($pdo) {
    try {
        $stmtSync = $pdo->query("SELECT local_ultima_sincronizacion FROM agencias ORDER BY id ASC LIMIT 1");
        $ultimaSync = $stmtSync ? $stmtSync->fetchColumn() : null;

        $stmtCount = $pdo->query("SELECT COUNT(*) FROM reportes_agencia_datos");
        $totalRegistrosSync = $stmtCount ? intval($stmtCount->fetchColumn()) : 0;
    } catch (Throwable $tS) {}
}

// 5. Construir diagnóstico detallado
$tipoDiagnostico = 'exito_directo';
$mensajeGeneral = '';

if ($pdoOk) {
    $tipoDiagnostico = 'conexion_directa_exitosa';
    $mensajeGeneral = "✅ ¡Conexión directa exitosa con la base de datos local! ($tipo_db en $host:$port)";
} elseif ($socketOk) {
    $tipoDiagnostico = 'socket_abierto_credencial_pendiente';
    $mensajeGeneral = "⚠️ Servidor local alcanzable en puerto $port, pero la autenticación a la base de datos devolvió: " . $pdoMsg;
} elseif ($esIpPrivada) {
    $tipoDiagnostico = 'lan_privada_requiere_conector';
    $mensajeGeneral = "ℹ️ Servidor Local en Red Privada ($host). Tu portal cPanel está en la nube y no puede conectarse hacia una IP privada. ¡El conector local automático resolverá esto enviando los datos por HTTPS hacia tu cPanel!";
} else {
    $tipoDiagnostico = 'host_no_alcanzable';
    $mensajeGeneral = "❌ No se pudo conectar al host $host:$port desde este servidor web ($socketErrorMsg).";
}

echo json_encode([
    'exito' => ($pdoOk || $esIpPrivada || $socketOk),
    'tipo_diagnostico' => $tipoDiagnostico,
    'mensaje' => $mensajeGeneral,
    'detalles' => [
        'host' => $host,
        'puerto' => $port,
        'ip_resuelta' => $ipResolved,
        'es_ip_privada' => $esIpPrivada,
        'socket_abierto' => $socketOk,
        'socket_tiempo_ms' => $socketMs,
        'socket_error' => $socketErrorMsg,
        'pdo_conectado' => $pdoOk,
        'pdo_mensaje' => $pdoMsg,
        'version_motor' => $dbVersion,
        'token_configurado' => !empty($token),
        'ultima_sincronizacion' => $ultimaSync,
        'total_registros_en_cpanel' => $totalRegistrosSync
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
