<?php
// Endpoint en cPanel de Agencia para responder las consultas en vivo del Portal Maestro
header('Content-Type: application/json; charset=utf-8');

include_once 'conexion.php';

define('TOKEN_AGENCIA', 'GedasDivolavilla2026!'); // Ajustar según token de la agencia

$token_recibido = $_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$token_recibido = str_replace('Bearer ', '', $token_recibido);

if ($token_recibido !== TOKEN_AGENCIA) {
    http_response_code(401);
    echo json_encode(["status" => "error", "mensaje" => "Acceso no autorizado: Token inválido."], JSON_UNESCAPED_UNICODE);
    exit;
}

$archivoCache = __DIR__ . '/reporte_cache.json';
$datos_reporte = [];
$info_agencia_perfil = [];

// 1. Intentar consultar datos desde la Base de Datos cPanel local de la agencia
if (isset($pdo) && $pdo !== null) {
    try {
        $stmt = $pdo->query("SELECT datos_json FROM reportes_agencias ORDER BY fecha_reporte DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['datos_json'])) {
            $datos_reporte = json_decode($row['datos_json'], true);
        }
    } catch (Throwable $e) {}

    // Cargar perfil y ubicación física de la agencia
    try {
        $stmtAg = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
        $rowAg = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
        if ($rowAg) {
            $info_agencia_perfil = [
                'nombre'             => $rowAg['nombre'] ?? '',
                'razon_social'       => $rowAg['razon_social'] ?? '',
                'rfc'                => $rowAg['rfc'] ?? '',
                'direccion'          => $rowAg['direccion'] ?? '',
                'maps_url'           => $rowAg['maps_url'] ?? '',
                'latitud'            => $rowAg['latitud'] ?? '',
                'longitud'           => $rowAg['longitud'] ?? '',
                'encargado_sistemas' => $rowAg['encargado_sistemas'] ?? '',
                'telefono_sistemas'  => $rowAg['telefono_sistemas'] ?? '',
                'correo_sistemas'    => $rowAg['correo_sistemas'] ?? '',
                'logo_url'           => $rowAg['logo_url'] ?? '',
                'foto_url'           => $rowAg['foto_url'] ?? ''
            ];
        }
    } catch (Throwable $e) {}
}

// 2. Fallback a archivo de caché local si la BD local no entregó datos
if (empty($datos_reporte) && file_exists($archivoCache)) {
    $datos_reporte = json_decode(file_get_contents($archivoCache), true);
}

// Respuesta estructurada para el Portal Maestro Central
echo json_encode([
    'status'           => 'ok',
    'agencia'          => $info_agencia_perfil['nombre'] ?? 'VW Divol La Villa',
    'timestamp'        => date('Y-m-d H:i:s'),
    'perfil'           => $info_agencia_perfil,
    'total_equipos'    => count($datos_reporte['inventario'] ?? []),
    'total_ordenes'    => count($datos_reporte['ordenes_servicio'] ?? []),
    'inventario'       => $datos_reporte['inventario'] ?? [],
    'ordenes_servicio' => $datos_reporte['ordenes_servicio'] ?? [],
    'evidencias'       => $datos_reporte['evidencias'] ?? []
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
