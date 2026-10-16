<?php
session_start();

require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión y Control de Acceso Estricto
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

if ($pdo) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
}
requerirPermiso('agencias', 'puede_ver');

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'SuperAdmin Grupo Huerta';
$agenciaUsuario = $_SESSION['agencia'] ?? 'Oficina Central Grupo Huerta';
$rolUsuario = $_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'SuperAdmin';

$archivoAgenciasCustom = __DIR__ . '/agencias_custom.json';
$mensaje = '';
$error = '';

// ====================================================
// 1. DESCARGA AUTOMÁTICA DEL ARCHIVO API PARA CPANEL
// ====================================================
if (isset($_GET['descargar_api'])) {
    include_once 'config_agencias.php';
    $agId = trim($_GET['descargar_api']);
    if (isset($CATALOGO_AGENCIAS[$agId])) {
        $ag = $CATALOGO_AGENCIAS[$agId];
        $tokenDef = addslashes($ag['token']);
        $nombreDef = addslashes($ag['nombre']);

        $phpCode = '<?php
// ====================================================================
// ENDPOINT RECEPTOR EN CPANEL: ' . $ag['nombre'] . '
// Ubicación exacta en cPanel: public_html/sistemas/api_obtener_datos.php
// ====================================================================
header("Content-Type: application/json; charset=utf-8");

include_once __DIR__ . "/conexion.php";

define("TOKEN_AGENCIA", "' . $tokenDef . '");

$token_recibido = $_GET["token"] ?? $_POST["token"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$token_recibido = str_replace("Bearer ", "", $token_recibido);

if ($token_recibido !== TOKEN_AGENCIA) {
    http_response_code(401);
    echo json_encode(["status" => "error", "mensaje" => "Acceso no autorizado: Token inválido."], JSON_UNESCAPED_UNICODE);
    exit;
}

$archivoCache = __DIR__ . "/reporte_cache.json";
$datos_reporte = [];
$info_agencia_perfil = [];

// 1. Consultar Base de Datos cPanel local de la agencia si existe
if (isset($pdo) && $pdo !== null) {
    try {
        $stmt = $pdo->query("SELECT datos_json FROM reportes_agencias ORDER BY fecha_reporte DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row["datos_json"])) {
            $datos_reporte = json_decode($row["datos_json"], true);
        }
    } catch (Throwable $e) {}

    // Cargar perfil y ubicación física registrada en la agencia
    try {
        $stmtAg = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
        $rowAg = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
        if ($rowAg) {
            $info_agencia_perfil = [
                "nombre"             => $rowAg["nombre"] ?? "",
                "razon_social"       => $rowAg["razon_social"] ?? "",
                "rfc"                => $rowAg["rfc"] ?? "",
                "direccion"          => $rowAg["direccion"] ?? "",
                "maps_url"           => $rowAg["maps_url"] ?? "",
                "latitud"            => $rowAg["latitud"] ?? "",
                "longitud"           => $rowAg["longitud"] ?? "",
                "encargado_sistemas" => $rowAg["encargado_sistemas"] ?? "",
                "telefono_sistemas"  => $rowAg["telefono_sistemas"] ?? "",
                "correo_sistemas"    => $rowAg["correo_sistemas"] ?? "",
                "logo_url"           => $rowAg["logo_url"] ?? "",
                "foto_url"           => $rowAg["foto_url"] ?? ""
            ];
        }
    } catch (Throwable $e) {}
}

// 2. Fallback a archivo de respaldo local si la tabla aún no tiene datos
if (empty($datos_reporte) && file_exists($archivoCache)) {
    $datos_reporte = json_decode(file_get_contents($archivoCache), true);
}

// 3. Respuesta JSON al Portal Maestro Central
echo json_encode([
    "status"           => "ok",
    "agencia"          => $info_agencia_perfil["nombre"] ?? "' . $nombreDef . '",
    "timestamp"        => date("Y-m-d H:i:s"),
    "perfil"           => $info_agencia_perfil,
    "total_equipos"    => count($datos_reporte["inventario"] ?? []),
    "total_ordenes"    => count($datos_reporte["ordenes_servicio"] ?? []),
    "inventario"       => $datos_reporte["inventario"] ?? [],
    "ordenes_servicio" => $datos_reporte["ordenes_servicio"] ?? [],
    "evidencias"       => $datos_reporte["evidencias"] ?? []
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="api_obtener_datos.php"');
        header('Content-Length: ' . strlen($phpCode));
        echo $phpCode;
        exit();
    }
}

// ====================================================
// 2. AJAX: OBTENER UBICACIONES DE AGENCIAS PARA EL MAPA
// ====================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'obtener_ubicaciones_mapa') {
    header('Content-Type: application/json; charset=utf-8');
    include_once 'config_agencias.php';

    $lista = [];
    foreach ($CATALOGO_AGENCIAS as $k => $ag) {
        $lista[] = [
            'id'          => $k,
            'nombre'      => $ag['nombre'],
            'subdominio'  => $ag['subdominio'],
            'cpanel'      => $ag['cpanel'],
            'direccion'   => $ag['direccion'] ?? '',
            'maps_url'    => $ag['maps_url'] ?? '',
            'latitud'     => $ag['latitud'] ?? '',
            'longitud'    => $ag['longitud'] ?? '',
            'icono'       => $ag['icono'] ?? 'bi-building-fill-check',
            'color'       => $ag['color'] ?? '#2563eb',
            'descripcion' => $ag['descripcion'] ?? '',
            'encargado_sistemas' => $ag['encargado_sistemas'] ?? '',
            'telefono_sistemas'  => $ag['telefono_sistemas'] ?? '',
            'correo_sistemas'    => $ag['correo_sistemas'] ?? '',
            'logo_url'    => $ag['logo_url'] ?? '',
            'foto_url'    => $ag['foto_url'] ?? '',
            'ultima_sincronizacion_ubicacion' => $ag['ultima_sincronizacion_ubicacion'] ?? ''
        ];
    }

    echo json_encode(['status' => 'ok', 'agencias' => $lista], JSON_UNESCAPED_UNICODE);
    exit();
}

// ====================================================
// 3. AJAX: SINCRONIZAR UBICACIÓN DESDE CPANEL DE AGENCIA
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'sincronizar_ubicacion') {
    header('Content-Type: application/json; charset=utf-8');
    include_once 'config_agencias.php';

    $agId = trim($_POST['agencia_id'] ?? '');
    if (!isset($CATALOGO_AGENCIAS[$agId])) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Agencia no encontrada en el catálogo.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $infoAg = $CATALOGO_AGENCIAS[$agId];
    $endpoint = $infoAg['endpoint'];
    $token = $infoAg['token'];
    $subdominio = $infoAg['subdominio'];

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

    $respuesta = false;
    $httpCode = 0;
    $errCurl = '';

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
            $respuesta = $res;
            $httpCode = $code;
            break;
        } else {
            $errCurl = $err;
        }
    }

    if ($respuesta !== false && $httpCode === 200) {
        $jsonRes = json_decode($respuesta, true);
        $perfil = $jsonRes['perfil'] ?? ($jsonRes['agencia_info'] ?? null);

        if ($perfil && is_array($perfil)) {
            $dirRemota  = trim($perfil['direccion'] ?? '');
            $mapsRemoto = trim($perfil['maps_url'] ?? '');
            $latRemota  = trim($perfil['latitud'] ?? '');
            $lngRemota  = trim($perfil['longitud'] ?? '');
            $encargado  = trim($perfil['encargado_sistemas'] ?? '');
            $tel        = trim($perfil['telefono_sistemas'] ?? '');
            $correo     = trim($perfil['correo_sistemas'] ?? '');

            if ((empty($latRemota) || empty($lngRemota)) && !empty($mapsRemoto)) {
                if (preg_match('/@?(-?\d+\.\d+),\s*(-?\d+\.\d+)/', $mapsRemoto, $mCoords)) {
                    $latRemota = $mCoords[1];
                    $lngRemota = $mCoords[2];
                }
            }

            if ($pdo && (!empty($dirRemota) || !empty($mapsRemoto) || !empty($latRemota))) {
                try {
                    $stmtUpd = $pdo->prepare("
                        UPDATE agencias 
                        SET direccion = COALESCE(NULLIF(?, ''), direccion),
                            maps_url = COALESCE(NULLIF(?, ''), maps_url),
                            latitud = COALESCE(NULLIF(?, ''), latitud),
                            longitud = COALESCE(NULLIF(?, ''), longitud),
                            encargado_sistemas = COALESCE(NULLIF(?, ''), encargado_sistemas),
                            telefono_sistemas = COALESCE(NULLIF(?, ''), telefono_sistemas),
                            correo_sistemas = COALESCE(NULLIF(?, ''), correo_sistemas),
                            ultima_sincronizacion_ubicacion = CURRENT_TIMESTAMP
                        WHERE clave = ? OR LOWER(nombre) = LOWER(?)
                    ");
                    $stmtUpd->execute([
                        $dirRemota, $mapsRemoto, $latRemota, $lngRemota,
                        $encargado, $tel, $correo,
                        $agId, $infoAg['nombre']
                    ]);
                } catch (Throwable $e) {}
            }

            echo json_encode([
                'status'    => 'ok',
                'mensaje'   => '¡Ubicación de ' . $infoAg['nombre'] . ' sincronizada con éxito!',
                'agencia'   => $infoAg['nombre'],
                'direccion' => $dirRemota ?: ($infoAg['direccion'] ?? ''),
                'maps_url'  => $mapsRemoto ?: ($infoAg['maps_url'] ?? ''),
                'latitud'   => $latRemota ?: ($infoAg['latitud'] ?? ''),
                'longitud'  => $lngRemota ?: ($infoAg['longitud'] ?? '')
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    if (!empty($infoAg['direccion']) || !empty($infoAg['latitud'])) {
        echo json_encode([
            'status'    => 'ok',
            'mensaje'   => 'Ubicación local cargada correctamente.',
            'agencia'   => $infoAg['nombre'],
            'direccion' => $infoAg['direccion'] ?? '',
            'maps_url'  => $infoAg['maps_url'] ?? '',
            'latitud'   => $infoAg['latitud'] ?? '',
            'longitud'  => $infoAg['longitud'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode([
        'status'  => 'error',
        'mensaje' => 'No se pudo consultar el cPanel de ' . $infoAg['nombre'] . '. Detalle: ' . ($errCurl ?: "HTTP $httpCode")
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ====================================================
// 4. AJAX: GUARDAR UBICACIÓN MANUAL O COORDENADAS
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_ubicacion_manual') {
    header('Content-Type: application/json; charset=utf-8');
    include_once 'config_agencias.php';

    $agId      = trim($_POST['agencia_id'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $maps_url  = trim($_POST['maps_url'] ?? '');
    $latitud   = trim($_POST['latitud'] ?? '');
    $longitud  = trim($_POST['longitud'] ?? '');

    if (empty($agId) || !isset($CATALOGO_AGENCIAS[$agId])) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Agencia inválida.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $nom = $CATALOGO_AGENCIAS[$agId]['nombre'];

    if ((empty($latitud) || empty($longitud)) && !empty($maps_url)) {
        if (preg_match('/@?(-?\d+\.\d+),\s*(-?\d+\.\d+)/', $maps_url, $mCoords)) {
            $latitud = $mCoords[1];
            $longitud = $mCoords[2];
        }
    }

    if (empty($maps_url) && !empty($direccion)) {
        $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($direccion);
    }

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                UPDATE agencias 
                SET direccion = ?, maps_url = ?, latitud = ?, longitud = ?, ultima_sincronizacion_ubicacion = CURRENT_TIMESTAMP
                WHERE clave = ? OR LOWER(nombre) = LOWER(?)
            ");
            $stmt->execute([$direccion, $maps_url, $latitud, $longitud, $agId, $nom]);
        } catch (Throwable $e) {}
    }

    $archivoCustom = __DIR__ . '/agencias_custom.json';
    if (file_exists($archivoCustom)) {
        $custom = json_decode(file_get_contents($archivoCustom), true) ?: [];
        if (isset($custom[$agId])) {
            $custom[$agId]['direccion'] = $direccion;
            $custom[$agId]['maps_url']  = $maps_url;
            $custom[$agId]['latitud']   = $latitud;
            $custom[$agId]['longitud']  = $longitud;
            @file_put_contents($archivoCustom, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    echo json_encode([
        'status'    => 'ok',
        'mensaje'   => 'Ubicación guardada correctamente.',
        'agencia'   => $nom,
        'direccion' => $direccion,
        'maps_url'  => $maps_url,
        'latitud'   => $latitud,
        'longitud'  => $longitud
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ====================================================
// 5. GUARDAR / REGISTRAR NUEVA AGENCIA
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_agencia') {
    $nombre = trim($_POST['nombre'] ?? '');
    $subdominio = trim($_POST['subdominio'] ?? '');
    $token = trim($_POST['token'] ?? '');
    $icono = trim($_POST['icono'] ?? 'bi-building-fill-check');
    $color = trim($_POST['color'] ?? '#2563eb');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $maps_url = trim($_POST['maps_url'] ?? '');

    // Limpieza de subdominio (quitar https:// y barras finales)
    $subdominio = preg_replace('#^https?://#', '', rtrim($subdominio, '/'));

    if (empty($maps_url) && !empty($direccion)) {
        $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($direccion);
    }

    $latitud = '';
    $longitud = '';
    if (!empty($maps_url) && preg_match('/@?(-?\d+\.\d+),\s*(-?\d+\.\d+)/', $maps_url, $mCoords)) {
        $latitud = $mCoords[1];
        $longitud = $mCoords[2];
    }

    if (empty($nombre) || empty($subdominio) || empty($token)) {
        $error = "Por favor completa los campos obligatorios: Nombre, Subdominio/URL y Token Secreto.";
    } else {
        // Generar slug/identificador único limpio y normalizado
        $idLimpio = preg_replace('/[^a-z0-9]/', '', strtolower($nombre));
        if (empty($idLimpio)) {
            $idLimpio = 'agencia_' . time();
        }

        $endpoint = 'https://' . $subdominio . '/sistemas/api_obtener_datos.php';

        // 1. Guardar en Base de Datos MySQL (tabla agencias)
        if ($pdo) {
            try {
                $stmtAg = $pdo->prepare("
                    INSERT INTO agencias (clave, nombre, subdominio, cpanel, endpoint, token, local_api_token, icono, color, descripcion, direccion, maps_url, latitud, longitud, estatus)
                    VALUES (:clave, :nombre, :subdominio, :cpanel, :endpoint, :token, :token, :icono, :color, :descripcion, :direccion, :maps_url, :latitud, :longitud, 1)
                    ON DUPLICATE KEY UPDATE
                        clave = VALUES(clave),
                        subdominio = VALUES(subdominio),
                        cpanel = VALUES(cpanel),
                        endpoint = VALUES(endpoint),
                        token = VALUES(token),
                        local_api_token = VALUES(token),
                        icono = VALUES(icono),
                        color = VALUES(color),
                        descripcion = VALUES(descripcion),
                        direccion = VALUES(direccion),
                        maps_url = VALUES(maps_url),
                        latitud = VALUES(latitud),
                        longitud = VALUES(longitud),
                        estatus = 1
                ");
                $stmtAg->execute([
                    ':clave'       => $idLimpio,
                    ':nombre'      => $nombre,
                    ':subdominio'  => $subdominio,
                    ':cpanel'      => $subdominio,
                    ':endpoint'    => $endpoint,
                    ':token'       => $token,
                    ':icono'       => $icono,
                    ':color'       => $color,
                    ':descripcion' => $descripcion ?: "Agencia {$nombre} - Sistemas e Infraestructura IT",
                    ':direccion'   => $direccion,
                    ':maps_url'    => $maps_url,
                    ':latitud'     => $latitud,
                    ':longitud'    => $longitud
                ]);
            } catch (Throwable $eDb) {}
        }

        // 2. Guardar en archivo JSON de respaldo agencias_custom.json
        $custom = [];
        if (file_exists($archivoAgenciasCustom)) {
            $custom = json_decode(file_get_contents($archivoAgenciasCustom), true) ?: [];
        }

        $custom[$idLimpio] = [
            'id'          => $idLimpio,
            'nombre'      => $nombre,
            'subdominio'  => $subdominio,
            'endpoint'    => $endpoint,
            'token'       => $token,
            'icono'       => $icono,
            'color'       => $color,
            'descripcion' => $descripcion ?: "Agencia {$nombre} - Sistemas e Infraestructura IT",
            'direccion'   => $direccion,
            'maps_url'    => $maps_url,
            'latitud'     => $latitud,
            'longitud'    => $longitud,
            'es_personalizada' => true
        ];

        @file_put_contents($archivoAgenciasCustom, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $mensaje = "¡Agencia <strong>" . htmlspecialchars($nombre) . "</strong> registrada exitosamente en el catálogo!";
    }
}

// ====================================================
// 3. ELIMINAR AGENCIA PERSONALIZADA
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar_agencia') {
    $eliminarId = trim($_POST['agencia_id'] ?? '');

    // 1. Eliminar de Base de Datos
    if ($pdo) {
        try {
            $stmtDel = $pdo->prepare("DELETE FROM agencias WHERE clave = ? OR LOWER(nombre) = LOWER(?)");
            $stmtDel->execute([$eliminarId, $eliminarId]);
        } catch (Throwable $e) {}
    }

    // 2. Eliminar del archivo JSON
    if (file_exists($archivoAgenciasCustom)) {
        $custom = json_decode(file_get_contents($archivoAgenciasCustom), true) ?: [];
        if (isset($custom[$eliminarId])) {
            unset($custom[$eliminarId]);
        }
        @file_put_contents($archivoAgenciasCustom, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    $mensaje = "La agencia ha sido eliminada del catálogo.";
}

// Cargar catálogo actualizado
include 'config_agencias.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Agencias - Portal Maestro Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <style>
        body {
            background-color: #040d1a;
            background-image: radial-gradient(#0b223e 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 60px;
        }
        .top-navbar {
            background: rgba(4, 13, 26, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 40px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .header-section {
            padding: 35px 40px 10px 40px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .master-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #34d399;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .header-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .header-desc {
            color: #94a3b8;
            font-size: 1.05rem;
        }
        .section-container {
            max-width: 1400px;
            margin: 25px auto;
            padding: 0 40px;
        }
        .agency-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 24px;
        }
        .agency-card {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 28px;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        .agency-card:hover {
            transform: translateY(-6px);
            border-color: #2563eb;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
            background: #0f233f;
        }
        .agency-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 18px;
        }
        .online-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .agency-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 4px;
        }
        .agency-subdomain {
            color: #60a5fa;
            font-size: 0.88rem;
            font-weight: 600;
            margin-bottom: 12px;
            font-family: monospace;
        }
        .agency-desc {
            color: #94a3b8;
            font-size: 0.88rem;
            line-height: 1.55;
            margin-bottom: 20px;
            flex-grow: 1;
        }
        .card-actions-row {
            display: flex;
            gap: 8px;
            margin-top: auto;
        }
        .btn-agency {
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 11px 16px;
            border-radius: 12px;
            border: none;
            flex-grow: 1;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-agency:hover {
            background: #1d4ed8;
            color: #ffffff;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
        }
        .btn-test {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #cbd5e1;
            border-radius: 12px;
            padding: 0 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .btn-test:hover {
            background: rgba(56, 189, 248, 0.2);
            border-color: #38bdf8;
            color: #38bdf8;
        }
        .add-agency-card {
            background: rgba(255, 255, 255, 0.02);
            border: 2px dashed rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #94a3b8;
            min-height: 280px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .add-agency-card:hover {
            border-color: #38bdf8;
            background: rgba(56, 189, 248, 0.04);
            color: #ffffff;
            transform: translateY(-5px);
        }
        .zero-storage-note {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.25);
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        /* Modal Styles */
        .modal-content {
            background: #09192e;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 20px;
        }
        .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 20px 25px;
        }
        .modal-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding: 15px 25px;
        }
        .form-control, .form-select {
            background: #040e1d;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 10px;
        }
        .form-control:focus, .form-select:focus {
            background: #07152b;
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25);
        }

        /* Estilos del Mapa de Agencias */
        #mapaAgencias {
            height: 600px;
            width: 100%;
            background: #030a14;
        }
        .leaflet-container {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif !important;
            background: #030a14 !important;
        }
        .leaflet-popup-content-wrapper {
            background: #09192e !important;
            color: #ffffff !important;
            border: 1px solid rgba(56, 189, 248, 0.4) !important;
            border-radius: 14px !important;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.7) !important;
            padding: 4px;
        }
        .leaflet-popup-tip {
            background: #09192e !important;
            border: 1px solid rgba(56, 189, 248, 0.4) !important;
        }
        .leaflet-popup-content {
            margin: 12px 14px !important;
            line-height: 1.4 !important;
        }
        .agency-map-marker-wrap {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }
        .agency-map-marker {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            color: #ffffff;
            font-size: 1.1rem;
            border: 2px solid #ffffff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.6);
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 2;
            position: relative;
        }
        .agency-map-marker:hover {
            transform: scale(1.22);
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.8);
        }
        .agency-map-pulse {
            position: absolute;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            animation: agencyPulse 2.2s infinite ease-out;
            opacity: 0.7;
            pointer-events: none;
            z-index: 1;
        }
        @keyframes agencyPulse {
            0% { transform: scale(0.6); opacity: 0.9; }
            100% { transform: scale(1.7); opacity: 0; }
        }
        .map-sidebar-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .map-sidebar-item:hover, .map-sidebar-item.active {
            background: rgba(56, 189, 248, 0.12);
            border-color: #38bdf8;
            transform: translateX(4px);
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-light btn-sm rounded-3 px-3 py-1 d-flex align-items-center gap-2">
            <i class="bi bi-arrow-left"></i> <span>Menú Principal</span>
        </a>
        <div class="d-flex align-items-center gap-2 border-start border-secondary ps-3">
            <i class="bi bi-buildings-fill text-success fs-5"></i>
            <span class="fw-bold tracking-wide">Módulo Agencias <span class="text-secondary fw-normal d-none d-sm-inline">| Catálogo de cPanels Grupo Huerta</span></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <button class="btn btn-primary btn-sm rounded-3 px-3 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalNuevaAgencia">
            <i class="bi bi-plus-circle-fill"></i> <span>Registrar Nueva Agencia</span>
        </button>
        <div class="text-end d-none d-md-block">
            <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.75rem;"><?php echo htmlspecialchars($agenciaUsuario); ?> &bull; <span class="badge bg-primary text-uppercase"><?php echo htmlspecialchars($rolUsuario); ?></span></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Cerrar sesión
        </a>
    </div>
</div>

<!-- Header -->
<div class="header-section">
    <div class="master-badge"><i class="bi bi-building-fill-gear"></i> DIRECCIÓN CENTRAL MAESTRA</div>
    <h1 class="header-title">Catálogo de Agencias Grupo Huerta</h1>
    <p class="header-desc">Administra y consulta en tiempo real las conexiones a las bases de datos de cada cPanel sucursal.</p>
</div>

<div class="section-container">

    <!-- Notificaciones -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 bg-success bg-opacity-25 text-white border-success mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 bg-danger bg-opacity-25 text-white border-danger mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo $error; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Nota de Arquitectura Zero-Storage -->
    <div class="zero-storage-note">
        <i class="bi bi-shield-check text-success fs-3"></i>
        <div class="flex-grow-1">
            <div class="fw-bold text-success">Arquitectura Descentralizada (Zero-Storage cPanel Maestro)</div>
            <div class="small text-secondary">
                Cada agencia almacena sus datos en su propio cPanel. Para conectar una sucursal nueva, solo sube el archivo receptor <code>api_obtener_datos.php</code> y regístrala aquí.
            </div>
        </div>
        <button class="btn btn-outline-success btn-sm rounded-3 px-3 d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#modalGuiaConexion">
            <i class="bi bi-question-circle"></i> Guía de Conexión
        </button>
    </div>

    <!-- BARRA DE ACCIONES SUPERIOR: BOTÓN MAPA Y HERRAMIENTAS -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 p-3 rounded-4" style="background: rgba(15, 34, 61, 0.7); border: 1px solid rgba(56, 189, 248, 0.25); backdrop-filter: blur(10px);">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 p-2 px-3 d-flex align-items-center justify-content-center" style="background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.3);">
                <i class="bi bi-buildings-fill text-info fs-3"></i>
            </div>
            <div>
                <h4 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                    Agencias y Sucursales Conectadas
                    <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-30 rounded-pill fs-7 ms-1"><?php echo count($CATALOGO_AGENCIAS); ?> activas</span>
                </h4>
                <div class="text-secondary small">Localización física, terminales remotas y monitoreo de Grupo Huerta.</div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- BOTÓN PRINCIPAL SOLICITADO: MAPA -->
            <button type="button" class="btn btn-info btn-lg rounded-pill px-4 py-2 fw-bold shadow-lg d-flex align-items-center gap-2" id="btnAbrirMapa" onclick="abrirModalMapaAgencias()">
                <i class="bi bi-map-fill fs-5"></i>
                <span class="fs-6">Mapa</span>
                <span class="badge bg-dark text-info rounded-pill ms-1" style="font-size: 0.72rem;">GPS</span>
            </button>

            <!-- BOTÓN SINCRONIZAR UBICACIONES -->
            <button type="button" class="btn btn-outline-info rounded-pill px-3 py-2 d-flex align-items-center gap-2" onclick="sincronizarTodasUbicaciones(true)" title="Consultar las ubicaciones registradas en los portales de agencias">
                <i class="bi bi-arrow-repeat"></i>
                <span class="d-none d-sm-inline">Sincronizar Ubicaciones</span>
            </button>

            <!-- BOTÓN NUEVA AGENCIA -->
            <button type="button" class="btn btn-outline-light rounded-pill px-3 py-2 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalNuevaAgencia">
                <i class="bi bi-plus-circle"></i>
                <span class="d-none d-sm-inline">Nueva Sucursal</span>
            </button>
        </div>
    </div>

    <!-- CATÁLOGO DE AGENCIAS -->
    <div class="agency-card-grid">
        
        <?php foreach ($CATALOGO_AGENCIAS as $key => $ag): ?>
            <div class="agency-card">
                <span class="online-badge" id="badge-status-<?php echo $key; ?>">
                    <i class="bi bi-circle-fill" style="font-size: 0.45rem;"></i> cPanel Vinculado
                </span>
                
                <div class="agency-icon" style="background: rgba(37, 99, 235, 0.15); color: <?php echo $ag['color']; ?>;">
                    <i class="bi <?php echo $ag['icono']; ?>"></i>
                </div>

                <div class="agency-title"><?php echo htmlspecialchars($ag['nombre']); ?></div>
                <div class="agency-subdomain">
                    <i class="bi bi-globe me-1"></i> <?php echo htmlspecialchars($ag['subdominio']); ?>
                </div>

                <div class="agency-desc">
                    <?php echo htmlspecialchars($ag['descripcion']); ?>
                </div>

                <!-- Ubicación física registrada en el portal -->
                <?php if (!empty($ag['direccion'])): ?>
                    <div class="d-flex align-items-start gap-2 mb-3 p-2 rounded-3" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); font-size: 0.78rem;">
                        <i class="bi bi-geo-alt-fill text-danger flex-shrink-0 mt-1"></i>
                        <div class="text-secondary text-truncate" style="line-height: 1.35;" title="<?php echo htmlspecialchars($ag['direccion']); ?>">
                            <strong class="text-light d-block mb-1">Ubicación registrada:</strong>
                            <?php echo htmlspecialchars($ag['direccion']); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Botones de Acción -->
                <div class="card-actions-row">
                    <a href="modulos.php?agencia=<?php echo $key; ?>" class="btn-agency">
                        Ingresar a Sucursal <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                    <button class="btn btn-test text-info" title="Ver en el Mapa" onclick="verAgenciaEnMapa('<?php echo $key; ?>')">
                        <i class="bi bi-geo-alt"></i>
                    </button>
                    <?php if (!empty($ag['maps_url'])): ?>
                        <a href="<?php echo htmlspecialchars($ag['maps_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-test text-success" title="Abrir en Google Maps">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-test" title="Probar conexión en vivo con cPanel" onclick="probarConexion('<?php echo $key; ?>', '<?php echo addslashes($ag['nombre']); ?>')">
                        <i class="bi bi-broadcast"></i>
                    </button>
                    <a href="agencias.php?descargar_api=<?php echo $key; ?>" class="btn btn-test" title="Descargar archivo api_obtener_datos.php para el cPanel de esta agencia">
                        <i class="bi bi-cloud-arrow-down"></i>
                    </a>
                    <?php if (!empty($ag['es_personalizada'])): ?>
                        <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar esta agencia del catálogo?');" style="display:inline;">
                            <input type="hidden" name="accion" value="eliminar_agencia">
                            <input type="hidden" name="agencia_id" value="<?php echo $key; ?>">
                            <button type="submit" class="btn btn-test text-danger" title="Eliminar agencia">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Tarjeta para agregar nueva sucursal (Click abre modal) -->
        <div class="add-agency-card" data-bs-toggle="modal" data-bs-target="#modalNuevaAgencia">
            <i class="bi bi-plus-circle-dotted display-4 mb-3 text-info"></i>
            <h5 class="fw-bold text-white mb-1">Registrar Nueva Sucursal</h5>
            <p class="small text-secondary mb-0">
                Haz clic aquí para vincular un nuevo cPanel de sucursal con Token seguro.
            </p>
        </div>

    </div>

</div>

<!-- ============================================== -->
<!-- MODAL: REGISTRAR NUEVA SUCURSAL CPANEL -->
<!-- ============================================== -->
<div class="modal fade" id="modalNuevaAgencia" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_agencia">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-building-add text-primary"></i> Registrar Nueva Sucursal / cPanel
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Nombre de la Agencia *</label>
                            <input type="text" name="nombre" class="form-control" placeholder="Ej: Nissan La Villa o Audi Central" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Subdominio o Dominio cPanel *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-secondary">https://</span>
                                <input type="text" name="subdominio" class="form-control" placeholder="nissan.grupohuerta.mx" required>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label small text-secondary fw-semibold">Token Secreto de Seguridad (Compartido) *</label>
                            <div class="input-group">
                                <input type="text" id="inputToken" name="token" class="form-control font-monospace" placeholder="GedasNissan2026!" required>
                                <button type="button" class="btn btn-outline-info" onclick="generarTokenAleatorio()">
                                    <i class="bi bi-magic me-1"></i> Generar
                                </button>
                            </div>
                            <div class="form-text text-secondary" style="font-size: 0.75rem;">
                                Este token protege las consultas entre el Portal Maestro y el cPanel de la agencia.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Color de Marca</label>
                            <input type="color" name="color" class="form-control form-control-color w-100" value="#2563eb">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Icono Representativo</label>
                            <select name="icono" class="form-select">
                                <option value="bi-building-fill-check">🏢 Edificio / Agencia General</option>
                                <option value="bi-car-front-fill" selected>🚗 Automotriz / Autos</option>
                                <option value="bi-speedometer2">🏎️ Deportivo / Cupra</option>
                                <option value="bi-truck-front-fill">🚚 Camiones / Comerciales</option>
                                <option value="bi-tools">🔧 Taller Mecánico / Refacciones</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Descripción Corta</label>
                            <input type="text" name="descripcion" class="form-control" placeholder="Ej: Concesionaria Nissan - Equipos e Inventario">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small text-secondary fw-semibold"><i class="bi bi-geo-alt me-1 text-danger"></i> Dirección Física de la Sucursal</label>
                            <input type="text" name="direccion" class="form-control" placeholder="Calle, número, colonia, alcaldía/municipio, C.P., estado">
                            <small class="text-secondary" style="font-size: 0.75rem;">Esta dirección se visualizará en el mapa de agencias.</small>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small text-secondary fw-semibold"><i class="bi bi-pin-map me-1 text-info"></i> Enlace de Google Maps (Opcional)</label>
                            <input type="text" name="maps_url" class="form-control" placeholder="https://maps.app.goo.gl/... o https://maps.google.com/?q=lat,lng">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold">
                        <i class="bi bi-check-lg me-1"></i> Guardar y Vincular Sucursal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- MODAL: GUÍA RÁPIDA DE CONEXIÓN CPANEL -->
<!-- ============================================== -->
<div class="modal fade" id="modalGuiaConexion" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle text-success"></i> ¿Cómo vincular un nuevo cPanel?
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <h6 class="fw-bold text-primary"><span class="badge bg-primary me-2">Paso 1</span> Dar de alta la agencia aquí</h6>
                    <p class="small text-secondary mb-0">Usa el botón <strong>"Registrar Nueva Agencia"</strong> para definir el nombre, subdominio y token secreto.</p>
                </div>
                <div class="mb-4">
                    <h6 class="fw-bold text-success"><span class="badge bg-success me-2">Paso 2</span> Descargar el archivo receptor</h6>
                    <p class="small text-secondary mb-0">En la tarjeta de la agencia, da clic en el icono de descarga <i class="bi bi-cloud-arrow-down text-info"></i> para descargar su archivo <code>api_obtener_datos.php</code> ya configurado.</p>
                </div>
                <div class="mb-4">
                    <h6 class="fw-bold text-warning"><span class="badge bg-warning text-dark me-2">Paso 3</span> Subirlo al cPanel de la sucursal</h6>
                    <p class="small text-secondary mb-0">Entra al cPanel de la sucursal, abre el <strong>Administrador de Archivos</strong> y coloca <code>api_obtener_datos.php</code> dentro de la carpeta <code>public_html/sistemas/</code>.</p>
                </div>
                <div>
                    <h6 class="fw-bold text-info"><span class="badge bg-info text-dark me-2">Paso 4</span> Probar la conexión en vivo</h6>
                    <p class="small text-secondary mb-0">Haz clic en el botón de antena <i class="bi bi-broadcast text-info"></i> en la tarjeta para verificar que responda en verde.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Entendido</button>
            </div>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: MAPA DE UBICACIÓN DE AGENCIAS GRUPO HUERTA  -->
<!-- =================================================== -->
<div class="modal fade" id="modalMapaAgencias" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-2xl overflow-hidden" style="background: #06152b; border: 1px solid rgba(56, 189, 248, 0.3) !important;">
            
            <div class="modal-header px-4 py-3" style="background: rgba(4, 13, 26, 0.95); border-bottom: 1px solid rgba(56, 189, 248, 0.2);">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 42px; height: 42px; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.4); color: #38bdf8;">
                        <i class="bi bi-geo-alt-fill fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0 d-flex align-items-center gap-2">
                            Mapa de Ubicación de Agencias
                            <span class="badge bg-info bg-opacity-20 text-info border border-info border-opacity-30 rounded-pill small" id="mapa_badge_conteo">0 Agencias</span>
                        </h5>
                        <small class="text-secondary">Ubicaciones físicas registradas en los portales de cada agencia Grupo Huerta.</small>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 fw-semibold d-flex align-items-center gap-1" onclick="sincronizarTodasUbicaciones(false)" id="btn_modal_sync_all" title="Consultar las ubicaciones registradas en los portales de todas las agencias">
                        <i class="bi bi-arrow-repeat"></i> <span>Sincronizar cPanels</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1" onclick="centrarMapaEnTodas()" title="Ajustar zoom para ver todas las agencias">
                        <i class="bi bi-arrows-fullscreen"></i> Ver Todas
                    </button>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="row g-0">
                    
                    <!-- Lado Izquierdo: Mapa Interactivo Leaflet -->
                    <div class="col-lg-8 position-relative">
                        <div id="mapaAgencias"></div>
                        
                        <!-- Barra de Estilos de Mapa Flotante -->
                        <div class="position-absolute top-0 end-0 m-3 d-flex flex-column gap-2" style="z-index: 1000;">
                            <div class="btn-group btn-group-sm rounded-pill shadow-lg overflow-hidden border border-secondary border-opacity-30" role="group">
                                <button type="button" class="btn btn-dark active" id="btnTileDark" onclick="cambiarCapaMapa('dark')">🌙 Oscuro</button>
                                <button type="button" class="btn btn-dark" id="btnTileOsm" onclick="cambiarCapaMapa('osm')">☀️ Calles</button>
                            </div>
                        </div>

                        <!-- Indicador Flotante de Estado -->
                        <div id="mapa_status_banner" class="position-absolute bottom-0 start-0 m-3 px-3 py-2 rounded-3 small d-none" style="z-index: 1000; background: rgba(4, 13, 26, 0.92); border: 1px solid rgba(56, 189, 248, 0.3); backdrop-filter: blur(8px);">
                            <span class="spinner-border spinner-border-sm text-info me-2"></span>
                            <span class="text-light" id="mapa_status_text">Consultando agencias...</span>
                        </div>
                    </div>

                    <!-- Lado Derecho: Lista de Agencias y Datos de Ubicación -->
                    <div class="col-lg-4 d-flex flex-column" style="height: 600px; background: rgba(4, 14, 29, 0.98); border-left: 1px solid rgba(255, 255, 255, 0.08);">
                        
                        <!-- Buscador de Agencia -->
                        <div class="p-3 border-bottom border-secondary border-opacity-25">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-dark border-secondary border-opacity-50 text-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" id="filtroAgenciasMapa" class="form-control bg-dark border-secondary border-opacity-50 text-white" placeholder="Buscar agencia por nombre o dirección..." oninput="filtrarListaMapa(this.value)">
                            </div>
                        </div>

                        <!-- Lista Scrolleable de Agencias -->
                        <div class="p-3 overflow-y-auto flex-grow-1 d-flex flex-column gap-2" id="listaAgenciasMapaContainer">
                            <!-- Inyectado dinámicamente con JS -->
                        </div>

                        <!-- Pie del Panel Lateral -->
                        <div class="p-3 border-top border-secondary border-opacity-25 small text-secondary d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-info-circle me-1 text-info"></i> Clic en una agencia para ubicarla.</span>
                            <span class="text-light fw-bold" id="mapa_conteo_gps">0 con GPS</span>
                        </div>

                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: AJUSTAR COORDENADAS / UBICACIÓN MANUAL      -->
<!-- =================================================== -->
<div class="modal fade" id="modalEditarCoordenadas" tabindex="-1" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #09192e; border: 1px solid rgba(56, 189, 248, 0.3);">
            <div class="modal-header">
                <h6 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                    <i class="bi bi-pin-map-fill text-warning"></i> Ajustar Coordenadas GPS
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="edit_coord_agencia_id">
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-semibold">Agencia</label>
                    <input type="text" id="edit_coord_agencia_nombre" class="form-control" readonly disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-semibold">Dirección Física</label>
                    <textarea id="edit_coord_direccion" class="form-control" rows="2" placeholder="Calle, número, colonia, código postal, estado"></textarea>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small text-secondary fw-semibold">Latitud</label>
                        <input type="text" id="edit_coord_lat" class="form-control font-monospace" placeholder="19.4774">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-secondary fw-semibold">Longitud</label>
                        <input type="text" id="edit_coord_lng" class="form-control font-monospace" placeholder="-99.1121">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-semibold">Enlace de Google Maps (maps_url)</label>
                    <input type="text" id="edit_coord_maps" class="form-control" placeholder="https://maps.google.com/...">
                </div>
                <button type="button" class="btn btn-outline-info btn-sm w-100 rounded-pill mb-2" onclick="geocodificarDireccionModal()">
                    <i class="bi bi-search me-1"></i> Auto-detectar Coordenadas desde la Dirección
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" onclick="guardarCoordenadasManuales()">
                    <i class="bi bi-check-lg me-1"></i> Guardar Ubicación
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
function generarTokenAleatorio() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$';
    let token = 'GH_';
    for (let i = 0; i < 20; i++) {
        token += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('inputToken').value = token;
}

function probarConexion(agenciaKey, agenciaNombre) {
    const badge = document.getElementById('badge-status-' + agenciaKey);
    if (badge) {
        badge.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Probando...';
        badge.className = 'online-badge bg-warning bg-opacity-25 text-warning border-warning';
    }

    const tInicio = performance.now();
    fetch('consultar_agencia.php?agencia=' + encodeURIComponent(agenciaKey))
        .then(response => {
            const tiempoMs = Math.round(performance.now() - tInicio);
            return response.json().then(data => ({ ok: response.ok, status: response.status, data, tiempoMs }));
        })
        .then(res => {
            if (res.ok && res.data.status === 'ok') {
                if (badge) {
                    badge.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> cPanel Activo (' + res.tiempoMs + 'ms)';
                    badge.className = 'online-badge bg-success bg-opacity-25 text-success border-success';
                }
                alert('✅ ¡Conexión Exitosa con ' + agenciaNombre + '!\n\nTiempo de respuesta: ' + res.tiempoMs + ' ms\nOrigen: ' + (res.data.origen || 'En vivo') + '\nTotal Equipos: ' + (res.data.datos ? (res.data.datos.total_equipos || 0) : 0));
            } else {
                if (badge) {
                    badge.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-danger"></i> Sin Respuesta';
                    badge.className = 'online-badge bg-danger bg-opacity-25 text-danger border-danger';
                }
                alert('⚠️ No se pudo conectar con el cPanel de ' + agenciaNombre + ':\n\n' + (res.data.mensaje || 'Error HTTP ' + res.status) + '\n\nVerifica que el archivo api_obtener_datos.php esté subido en ese cPanel y que el dominio responda.');
            }
        })
        .catch(err => {
            if (badge) {
                badge.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i> Error Red';
                badge.className = 'online-badge bg-danger bg-opacity-25 text-danger border-danger';
            }
            alert('❌ Error de red al probar conexión con ' + agenciaNombre + ':\n' + err.message);
        });
}

// ==============================================================
// CONTROLADOR DEL MAPA INTERACTIVO DE AGENCIAS (LEAFLET)
// ==============================================================
let mapaInstancia = null;
let capaTilesActual = null;
let markersGroup = null;
let agenciasDataGlobal = [];

const TILE_LAYERS = {
    dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    osm:  'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
};

function abrirModalMapaAgencias() {
    const modalEl = document.getElementById('modalMapaAgencias');
    const modalInst = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInst.show();

    modalEl.addEventListener('shown.bs.modal', function onShown() {
        initMapaAgencias();
        cargarUbicacionesAgencias();
        modalEl.removeEventListener('shown.bs.modal', onShown);
    });

    if (mapaInstancia) {
        setTimeout(() => {
            mapaInstancia.invalidateSize();
            cargarUbicacionesAgencias();
        }, 250);
    }
}

function initMapaAgencias() {
    if (mapaInstancia) {
        mapaInstancia.invalidateSize();
        return;
    }

    mapaInstancia = L.map('mapaAgencias', {
        zoomControl: true,
        attributionControl: false
    }).setView([19.4326, -99.1332], 11);

    capaTilesActual = L.tileLayer(TILE_LAYERS.dark, {
        maxZoom: 19,
        subdomains: 'abcd'
    }).addTo(mapaInstancia);

    markersGroup = L.layerGroup().addTo(mapaInstancia);
}

function cambiarCapaMapa(capa) {
    if (!mapaInstancia) return;
    if (capaTilesActual) mapaInstancia.removeLayer(capaTilesActual);

    const url = TILE_LAYERS[capa] || TILE_LAYERS.dark;
    capaTilesActual = L.tileLayer(url, { maxZoom: 19, subdomains: 'abcd' }).addTo(mapaInstancia);

    document.getElementById('btnTileDark').classList.toggle('active', capa === 'dark');
    document.getElementById('btnTileOsm').classList.toggle('active', capa === 'osm');
}

function cargarUbicacionesAgencias(centrarEnId = null) {
    mostrarBannerStatus('Cargando ubicaciones de agencias...');

    fetch('agencias.php?accion=obtener_ubicaciones_mapa')
        .then(res => res.json())
        .then(data => {
            ocultarBannerStatus();
            if (data.status === 'ok') {
                agenciasDataGlobal = data.agencias || [];
                renderizarMarcadoresYLista(centrarEnId);
            } else {
                alert('No se pudieron obtener las ubicaciones: ' + (data.mensaje || 'Error desconocido'));
            }
        })
        .catch(err => {
            ocultarBannerStatus();
            console.error('Error al cargar agencias:', err);
        });
}

function renderizarMarcadoresYLista(centrarEnId = null) {
    if (!mapaInstancia || !markersGroup) return;
    markersGroup.clearLayers();

    const listaContainer = document.getElementById('listaAgenciasMapaContainer');
    listaContainer.innerHTML = '';

    const bounds = L.latLngBounds();
    let conteoGps = 0;
    const marcadoresMap = {};

    document.getElementById('mapa_badge_conteo').textContent = agenciasDataGlobal.length + ' Agencias';

    agenciasDataGlobal.forEach(ag => {
        const hasGps = ag.latitud && ag.longitud && !isNaN(parseFloat(ag.latitud)) && !isNaN(parseFloat(ag.longitud));
        if (hasGps) conteoGps++;

        const item = document.createElement('div');
        item.className = 'map-sidebar-item' + (centrarEnId === ag.id ? ' active' : '');
        item.id = 'sidebar_item_' + ag.id;
        item.onclick = () => enfocarAgencia(ag.id);

        item.innerHTML = `
            <div class="d-flex align-items-center justify-content-between mb-1">
                <div class="fw-bold text-white small d-flex align-items-center gap-2">
                    <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background: ${ag.color || '#38bdf8'};"></span>
                    ${escapeHtml(ag.nombre)}
                </div>
                <span class="badge ${hasGps ? 'bg-success bg-opacity-20 text-success border border-success border-opacity-30' : 'bg-warning bg-opacity-20 text-warning border border-warning border-opacity-30'} rounded-pill" style="font-size: 0.68rem;">
                    ${hasGps ? '<i class="bi bi-geo-alt-fill me-1"></i>GPS' : '<i class="bi bi-geo me-1"></i>Sin GPS'}
                </span>
            </div>
            <div class="text-secondary small mb-2 text-truncate" style="font-size: 0.74rem;" title="${escapeHtml(ag.direccion || 'Sin dirección registrada')}">
                <i class="bi bi-pin-map text-secondary me-1"></i> ${escapeHtml(ag.direccion || 'Pendiente de registrar en portal de agencia')}
            </div>
            <div class="d-flex align-items-center gap-1">
                <button type="button" class="btn btn-outline-info btn-sm py-0 px-2 rounded-pill" style="font-size: 0.7rem;" onclick="event.stopPropagation(); sincronizarUbicacionAgencia('${ag.id}')" title="Sincronizar ubicación desde su cPanel">
                    <i class="bi bi-arrow-repeat"></i> Sincronizar
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 rounded-pill" style="font-size: 0.7rem;" onclick="event.stopPropagation(); abrirModalEditarCoordenadas('${ag.id}')" title="Ajustar o ingresar coordenadas manualmente">
                    <i class="bi bi-pencil-square"></i> Editar GPS
                </button>
                ${ag.maps_url ? `<a href="${escapeHtml(ag.maps_url)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-success btn-sm py-0 px-2 rounded-pill" style="font-size: 0.7rem;" onclick="event.stopPropagation();" title="Abrir en Google Maps"><i class="bi bi-box-arrow-up-right"></i></a>` : ''}
            </div>
        `;
        listaContainer.appendChild(item);

        if (hasGps) {
            const lat = parseFloat(ag.latitud);
            const lng = parseFloat(ag.longitud);
            bounds.extend([lat, lng]);

            const customHtml = `
                <div class="agency-map-marker-wrap">
                    <div class="agency-map-pulse" style="background: ${ag.color || '#38bdf8'};"></div>
                    <div class="agency-map-marker" style="background: ${ag.color || '#2563eb'};">
                        <i class="bi ${ag.icono || 'bi-building-fill'}"></i>
                    </div>
                </div>
            `;

            const icon = L.divIcon({
                className: '',
                html: customHtml,
                iconSize: [40, 40],
                iconAnchor: [20, 20],
                popupAnchor: [0, -22]
            });

            const marker = L.marker([lat, lng], { icon: icon }).addTo(markersGroup);

            const popupHtml = `
                <div style="min-width: 250px; max-width: 310px;">
                    <div class="d-flex align-items-center gap-2 mb-2 pb-2 border-bottom border-secondary border-opacity-25">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; background: ${ag.color || '#2563eb'};">
                            <i class="bi ${ag.icono || 'bi-building-fill'}"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-white fs-6 mb-0">${escapeHtml(ag.nombre)}</div>
                            <small class="text-secondary font-monospace">${escapeHtml(ag.subdominio || ag.cpanel)}</small>
                        </div>
                    </div>

                    <div class="mb-2">
                        <strong class="text-light small d-block mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Dirección Registrada:</strong>
                        <div class="text-secondary small" style="font-size: 0.78rem; line-height: 1.4;">
                            ${escapeHtml(ag.direccion || 'Dirección registrada en portal de agencia')}
                        </div>
                    </div>

                    ${ag.encargado_sistemas || ag.telefono_sistemas ? `
                        <div class="mb-3 p-2 rounded-2" style="background: rgba(255,255,255,0.04); font-size: 0.74rem;">
                            ${ag.encargado_sistemas ? `<div class="text-light"><i class="bi bi-person me-1 text-info"></i> ${escapeHtml(ag.encargado_sistemas)}</div>` : ''}
                            ${ag.telefono_sistemas ? `<div class="text-secondary"><i class="bi bi-telephone me-1 text-success"></i> ${escapeHtml(ag.telefono_sistemas)}</div>` : ''}
                        </div>
                    ` : ''}

                    <div class="d-flex gap-2 pt-1 border-top border-secondary border-opacity-25">
                        <a href="modulos.php?agencia=${encodeURIComponent(ag.id)}" class="btn btn-primary btn-sm rounded-pill w-100 py-1" style="font-size: 0.76rem;">
                            <i class="bi bi-door-open me-1"></i> Sucursal
                        </a>
                        ${ag.maps_url ? `
                            <a href="${escapeHtml(ag.maps_url)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-success btn-sm rounded-pill py-1 px-3" style="font-size: 0.76rem;" title="Abrir en Google Maps">
                                <i class="bi bi-map"></i>
                            </a>
                        ` : ''}
                        <button type="button" class="btn btn-outline-info btn-sm rounded-pill py-1 px-3" onclick="sincronizarUbicacionAgencia('${ag.id}')" title="Sincronizar datos desde su cPanel">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                    </div>
                </div>
            `;

            marker.bindPopup(popupHtml);
            marcadoresMap[ag.id] = marker;
        }
    });

    document.getElementById('mapa_conteo_gps').textContent = conteoGps + ' con GPS';
    mapaInstancia._marcadoresMap = marcadoresMap;

    if (centrarEnId && marcadoresMap[centrarEnId]) {
        enfocarAgencia(centrarEnId);
    } else if (bounds.isValid()) {
        mapaInstancia.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
    }
}

function enfocarAgencia(agId) {
    if (!mapaInstancia) return;

    document.querySelectorAll('.map-sidebar-item').forEach(el => el.classList.remove('active'));
    const sidebarItem = document.getElementById('sidebar_item_' + agId);
    if (sidebarItem) {
        sidebarItem.classList.add('active');
        sidebarItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    const markers = mapaInstancia._marcadoresMap || {};
    const marker = markers[agId];

    if (marker) {
        const latLng = marker.getLatLng();
        mapaInstancia.flyTo(latLng, 15, { duration: 1.2 });
        setTimeout(() => marker.openPopup(), 1300);
    } else {
        const ag = agenciasDataGlobal.find(a => a.id === agId);
        if (ag && ag.direccion) {
            if (confirm(`La agencia "${ag.nombre}" tiene dirección registrada pero aún no cuenta con coordenadas GPS exactas.\n\n¿Deseas asignar o auto-detectar sus coordenadas GPS ahora?`)) {
                abrirModalEditarCoordenadas(agId);
            }
        } else {
            alert(`La agencia seleccionada aún no ha registrado su ubicación física.\nPuedes hacer clic en "Sincronizar" o "Editar GPS" para asignarla.`);
        }
    }
}

function verAgenciaEnMapa(agId) {
    abrirModalMapaAgencias();
    setTimeout(() => {
        cargarUbicacionesAgencias(agId);
    }, 350);
}

function centrarMapaEnTodas() {
    if (!mapaInstancia) return;
    const bounds = L.latLngBounds();
    agenciasDataGlobal.forEach(ag => {
        if (ag.latitud && ag.longitud && !isNaN(parseFloat(ag.latitud))) {
            bounds.extend([parseFloat(ag.latitud), parseFloat(ag.longitud)]);
        }
    });
    if (bounds.isValid()) {
        mapaInstancia.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
    } else {
        mapaInstancia.setView([19.4326, -99.1332], 11);
    }
}

function sincronizarUbicacionAgencia(agId) {
    mostrarBannerStatus('Consultando cPanel de la agencia...');

    const fd = new FormData();
    fd.append('accion', 'sincronizar_ubicacion');
    fd.append('agencia_id', agId);

    fetch('agencias.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        ocultarBannerStatus();
        if (data.status === 'ok') {
            alert('✅ ' + data.mensaje);
            cargarUbicacionesAgencias(agId);
        } else {
            alert('⚠️ ' + (data.mensaje || 'No se pudo sincronizar la ubicación con el cPanel.'));
        }
    })
    .catch(err => {
        ocultarBannerStatus();
        alert('❌ Error al sincronizar con cPanel: ' + err.message);
    });
}

function sincronizarTodasUbicaciones(abrirMapaDespues = false) {
    if (abrirMapaDespues) {
        abrirModalMapaAgencias();
    }

    const btn = document.getElementById('btn_modal_sync_all');
    if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sincronizando...';
    mostrarBannerStatus('Sincronizando ubicaciones con todos los cPanels...');

    const promesas = agenciasDataGlobal.map(ag => {
        const fd = new FormData();
        fd.append('accion', 'sincronizar_ubicacion');
        fd.append('agencia_id', ag.id);
        return fetch('agencias.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .catch(() => null);
    });

    Promise.all(promesas).then(() => {
        ocultarBannerStatus();
        if (btn) btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> <span>Sincronizar cPanels</span>';
        cargarUbicacionesAgencias();
    });
}

function abrirModalEditarCoordenadas(agId) {
    const ag = agenciasDataGlobal.find(a => a.id === agId);
    if (!ag) return;

    document.getElementById('edit_coord_agencia_id').value = ag.id;
    document.getElementById('edit_coord_agencia_nombre').value = ag.nombre;
    document.getElementById('edit_coord_direccion').value = ag.direccion || '';
    document.getElementById('edit_coord_lat').value = ag.latitud || '';
    document.getElementById('edit_coord_lng').value = ag.longitud || '';
    document.getElementById('edit_coord_maps').value = ag.maps_url || '';

    const modal = new bootstrap.Modal(document.getElementById('modalEditarCoordenadas'));
    modal.show();
}

function guardarCoordenadasManuales() {
    const agId = document.getElementById('edit_coord_agencia_id').value;
    const dir = document.getElementById('edit_coord_direccion').value.trim();
    const lat = document.getElementById('edit_coord_lat').value.trim();
    const lng = document.getElementById('edit_coord_lng').value.trim();
    const maps = document.getElementById('edit_coord_maps').value.trim();

    const fd = new FormData();
    fd.append('accion', 'guardar_ubicacion_manual');
    fd.append('agencia_id', agId);
    fd.append('direccion', dir);
    fd.append('latitud', lat);
    fd.append('longitud', lng);
    fd.append('maps_url', maps);

    fetch('agencias.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'ok') {
            bootstrap.Modal.getInstance(document.getElementById('modalEditarCoordenadas')).hide();
            cargarUbicacionesAgencias(agId);
        } else {
            alert('Error: ' + (data.mensaje || 'No se pudo guardar la ubicación.'));
        }
    })
    .catch(err => {
        alert('Error de red: ' + err.message);
    });
}

function geocodificarDireccionModal() {
    const dir = document.getElementById('edit_coord_direccion').value.trim();
    if (!dir) {
        alert('Por favor escribe primero una dirección en el campo correspondiente.');
        return;
    }

    const url = 'https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(dir);
    mostrarBannerStatus('Buscando coordenadas satelitales...');

    fetch(url)
        .then(r => r.json())
        .then(results => {
            ocultarBannerStatus();
            if (results && results.length > 0) {
                const primer = results[0];
                document.getElementById('edit_coord_lat').value = parseFloat(primer.lat).toFixed(6);
                document.getElementById('edit_coord_lng').value = parseFloat(primer.lon).toFixed(6);
                if (!document.getElementById('edit_coord_maps').value) {
                    document.getElementById('edit_coord_maps').value = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(dir)}`;
                }
                alert(`✅ Coordenadas detectadas:\nLat: ${primer.lat}\nLng: ${primer.lon}`);
            } else {
                alert('No se encontraron coordenadas automáticas para esa dirección. Puedes escribirlas manualmente.');
            }
        })
        .catch(err => {
            ocultarBannerStatus();
            alert('Error al consultar geocodificador: ' + err.message);
        });
}

function filtrarListaMapa(texto) {
    const q = (texto || '').toLowerCase().trim();
    document.querySelectorAll('.map-sidebar-item').forEach(el => {
        const contenido = el.textContent.toLowerCase();
        el.style.display = contenido.includes(q) ? 'block' : 'none';
    });
}

function mostrarBannerStatus(msg) {
    const banner = document.getElementById('mapa_status_banner');
    const txt = document.getElementById('mapa_status_text');
    if (banner && txt) {
        txt.textContent = msg;
        banner.classList.remove('d-none');
    }
}

function ocultarBannerStatus() {
    const banner = document.getElementById('mapa_status_banner');
    if (banner) banner.classList.add('d-none');
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' })[m]);
}
</script>
</body>
</html>
