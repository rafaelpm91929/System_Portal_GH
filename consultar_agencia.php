<?php
// Proxy de Consulta Bajo Demanda - Portal Maestro Grupo Huerta
// No almacena datos en la BD del Portal Maestro; consulta directamente el cPanel de la agencia seleccionada.
header('Content-Type: application/json; charset=utf-8');

include_once 'config_agencias.php';

$agencia_id = trim($_GET['agencia'] ?? $_POST['agencia'] ?? '');

if (empty($agencia_id) || !isset($CATALOGO_AGENCIAS[$agencia_id])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'Agencia no válida o no especificada en el Catálogo Maestro.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$info_agencia = $CATALOGO_AGENCIAS[$agencia_id];
$endpoint     = $info_agencia['endpoint'];
$token        = $info_agencia['token'];
$subdominio   = $info_agencia['subdominio'];

// Rutas candidatas automáticas (soporta tanto public_html/sistemas/ como public_html/)
$candidatos = [$endpoint];
if (strpos($endpoint, '/sistemas/') === false) {
    $candidatos[] = str_replace('/api_obtener_datos.php', '/sistemas/api_obtener_datos.php', $endpoint);
} else {
    $candidatos[] = str_replace('/sistemas/api_obtener_datos.php', '/api_obtener_datos.php', $endpoint);
}
if (strpos($subdominio, 'portal.') === 0) {
    $dominioBase = substr($subdominio, 7);
    $candidatos[] = "https://{$dominioBase}/sistemas/api_obtener_datos.php";
}
$candidatos = array_unique($candidatos);

$response = false;
$http_code = 0;
$curl_error = '';
$endpoint_usado = $endpoint;

foreach ($candidatos as $urlTest) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $urlTest . '?token=' . urlencode($token),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: PortalMaestroGrupoHuerta/2.0',
            'X-Requested-With: XMLHttpRequest',
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code === 200 && $res !== false) {
        $response = $res;
        $http_code = $code;
        $endpoint_usado = $urlTest;
        break;
    } else {
        $response = $res;
        $http_code = $code;
        $curl_error = $err;
        $endpoint_usado = $urlTest;
    }
}

if ($response === false || $http_code !== 200) {
    // Si la agencia aún no tiene endpoint HTTPS activo o falla la red, intentar leer caché de respaldo local si existe
    $cache_fallback = __DIR__ . "/reporte_cache_{$agencia_id}.json";
    if (file_exists($cache_fallback)) {
        $data_cache = json_decode(file_get_contents($cache_fallback), true);
        echo json_encode([
            'status' => 'ok',
            'origen' => 'cache_respaldo',
            'agencia' => $info_agencia['nombre'],
            'datos' => $data_cache,
            'mensaje' => 'Mostrando último reporte de respaldo en vivo.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(502);
    echo json_encode([
        'status' => 'error',
        'agencia' => $info_agencia['nombre'],
        'mensaje' => "No se pudo consultar el cPanel de {$info_agencia['nombre']} ({$info_agencia['subdominio']}). Detalle: " . ($curl_error ?: "HTTP $http_code"),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Retornar la respuesta en vivo de la agencia
$data_decoded = json_decode($response, true);
echo json_encode([
    'status' => 'ok',
    'origen' => 'cpanel_remoto_en_vivo',
    'agencia' => $info_agencia['nombre'],
    'datos' => $data_decoded
], JSON_UNESCAPED_UNICODE);
?>
