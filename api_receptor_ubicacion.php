<?php
// ====================================================================
// RECEPTOR CENTRAL DE UBICACIÓN DE AGENCIAS - PORTAL MAESTRO GRUPO HUERTA
// Recibe la ubicación física y contacto registrados en el portal de agencia
// URL en cPanel Central: public_html/sistemas/api_receptor_ubicacion.php
// ====================================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config_agencias.php';

date_default_timezone_set('America/Mexico_City');

if (!isset($pdo) || $pdo === null) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión a la base de datos central.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Obtener cuerpo de la petición (JSON o POST)
$inputRaw = file_get_contents('php://input');
$payload = json_decode($inputRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$token = trim($payload['token'] ?? ($_GET['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '')));
$token = str_replace('Bearer ', '', $token);

$agenciaId = trim($payload['agencia'] ?? ($payload['agencia_id'] ?? ($payload['clave'] ?? '')));
$nombre = trim($payload['nombre'] ?? '');
$direccion = trim($payload['direccion'] ?? '');
$maps_url = trim($payload['maps_url'] ?? '');
$latitud = trim($payload['latitud'] ?? '');
$longitud = trim($payload['longitud'] ?? '');
$encargado = trim($payload['encargado_sistemas'] ?? '');
$telefono = trim($payload['telefono_sistemas'] ?? '');
$correo = trim($payload['correo_sistemas'] ?? '');
$logo_url = trim($payload['logo_url'] ?? '');
$foto_url = trim($payload['foto_url'] ?? '');

if (empty($agenciaId) && !empty($nombre)) {
    $agenciaId = $nombre;
}

if (empty($agenciaId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'mensaje' => 'Identificador de agencia requerido (agencia o nombre).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Extraer coordenadas si vienen dentro del maps_url
if ((empty($latitud) || empty($longitud)) && !empty($maps_url)) {
    if (preg_match('/@?(-?\d+\.\d+),\s*(-?\d+\.\d+)/', $maps_url, $mCoords)) {
        $latitud = $mCoords[1];
        $longitud = $mCoords[2];
    }
}

// 2. Validar token
$agEncontrada = null;
$claveAgencia = '';
$nomLower = strtolower($agenciaId);

foreach ($CATALOGO_AGENCIAS as $k => $ag) {
    if (strtolower($k) === $nomLower || strtolower($ag['nombre']) === $nomLower || strpos($nomLower, strtolower($k)) !== false) {
        $agEncontrada = $ag;
        $claveAgencia = $k;
        break;
    }
}

$tokenValido = false;
$tokenMaestro = 'GH_SISTEMAS_TICKETS_2026!';

if ($token === $tokenMaestro) {
    $tokenValido = true;
} elseif ($agEncontrada && !empty($agEncontrada['token']) && $token === $agEncontrada['token']) {
    $tokenValido = true;
} elseif (isset($_SESSION['usuario_id'])) {
    $tokenValido = true;
}

if (!$tokenValido) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'mensaje' => 'No autorizado: Token de agencia inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Actualizar la ubicación en la Base de Datos Central
try {
    $targetName = $agEncontrada ? $agEncontrada['nombre'] : ($nombre ?: $agenciaId);
    $targetClave = $claveAgencia ?: preg_replace('/[^a-z0-9]/', '', strtolower($agenciaId));

    if (empty($maps_url) && !empty($direccion)) {
        $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($direccion);
    }

    $stmt = $pdo->prepare("
        UPDATE agencias 
        SET direccion = COALESCE(NULLIF(?, ''), direccion),
            maps_url = COALESCE(NULLIF(?, ''), maps_url),
            latitud = COALESCE(NULLIF(?, ''), latitud),
            longitud = COALESCE(NULLIF(?, ''), longitud),
            encargado_sistemas = COALESCE(NULLIF(?, ''), encargado_sistemas),
            telefono_sistemas = COALESCE(NULLIF(?, ''), telefono_sistemas),
            correo_sistemas = COALESCE(NULLIF(?, ''), correo_sistemas),
            logo_url = COALESCE(NULLIF(?, ''), logo_url),
            foto_url = COALESCE(NULLIF(?, ''), foto_url),
            ultima_sincronizacion_ubicacion = CURRENT_TIMESTAMP
        WHERE clave = ? OR LOWER(nombre) = LOWER(?)
    ");
    $stmt->execute([
        $direccion,
        $maps_url,
        $latitud,
        $longitud,
        $encargado,
        $telefono,
        $correo,
        $logo_url,
        $foto_url,
        $targetClave,
        $targetName
    ]);

    // Respaldo en JSON
    $archivoCustom = __DIR__ . '/agencias_custom.json';
    if (file_exists($archivoCustom)) {
        $custom = json_decode(file_get_contents($archivoCustom), true) ?: [];
        if (isset($custom[$targetClave])) {
            if ($direccion) $custom[$targetClave]['direccion'] = $direccion;
            if ($maps_url) $custom[$targetClave]['maps_url'] = $maps_url;
            if ($latitud) $custom[$targetClave]['latitud'] = $latitud;
            if ($longitud) $custom[$targetClave]['longitud'] = $longitud;
            if ($encargado) $custom[$targetClave]['encargado_sistemas'] = $encargado;
            if ($telefono) $custom[$targetClave]['telefono_sistemas'] = $telefono;
            if ($correo) $custom[$targetClave]['correo_sistemas'] = $correo;
            @file_put_contents($archivoCustom, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    echo json_encode([
        'status'    => 'ok',
        'mensaje'   => 'Ubicación física sincronizada con éxito en Central TI para ' . $targetName,
        'agencia'   => $targetName,
        'direccion' => $direccion,
        'maps_url'  => $maps_url,
        'latitud'   => $latitud,
        'longitud'  => $longitud,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al guardar ubicación en Central: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
