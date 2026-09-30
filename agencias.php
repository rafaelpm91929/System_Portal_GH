<?php
session_start();

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

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

// 1. Consultar Base de Datos cPanel local de la agencia si existe
if (isset($pdo) && $pdo !== null) {
    try {
        $stmt = $pdo->query("SELECT datos_json FROM reportes_agencias ORDER BY fecha_reporte DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row["datos_json"])) {
            $datos_reporte = json_decode($row["datos_json"], true);
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
    "agencia"          => "' . $nombreDef . '",
    "timestamp"        => date("Y-m-d H:i:s"),
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
// 2. GUARDAR / REGISTRAR NUEVA AGENCIA
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_agencia') {
    $nombre = trim($_POST['nombre'] ?? '');
    $subdominio = trim($_POST['subdominio'] ?? '');
    $token = trim($_POST['token'] ?? '');
    $icono = trim($_POST['icono'] ?? 'bi-building-fill-check');
    $color = trim($_POST['color'] ?? '#2563eb');
    $descripcion = trim($_POST['descripcion'] ?? '');

    // Limpieza de subdominio (quitar https:// y barras finales)
    $subdominio = preg_replace('#^https?://#', '', rtrim($subdominio, '/'));

    if (empty($nombre) || empty($subdominio) || empty($token)) {
        $error = "Por favor completa los campos obligatorios: Nombre, Subdominio/URL y Token Secreto.";
    } else {
        // Generar slug/identificador único
        $idLimpio = preg_replace('/[^a-z0-9]/', '', strtolower($nombre));
        if (empty($idLimpio)) {
            $idLimpio = 'agencia_' . time();
        }

        $endpoint = 'https://' . $subdominio . '/sistemas/api_obtener_datos.php';

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
            'es_personalizada' => true
        ];

        file_put_contents($archivoAgenciasCustom, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $mensaje = "¡Agencia <strong>" . htmlspecialchars($nombre) . "</strong> registrada exitosamente en el catálogo!";
    }
}

// ====================================================
// 3. ELIMINAR AGENCIA PERSONALIZADA
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar_agencia') {
    $eliminarId = trim($_POST['agencia_id'] ?? '');
    if (file_exists($archivoAgenciasCustom)) {
        $custom = json_decode(file_get_contents($archivoAgenciasCustom), true) ?: [];
        if (isset($custom[$eliminarId])) {
            unset($custom[$eliminarId]);
            file_put_contents($archivoAgenciasCustom, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $mensaje = "La agencia personalizada ha sido eliminada del catálogo.";
        }
    }
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

                <!-- Botones de Acción -->
                <div class="card-actions-row">
                    <a href="modulos.php?agencia=<?php echo $key; ?>" class="btn-agency">
                        Ingresar a Sucursal <i class="bi bi-arrow-right ms-1"></i>
                    </a>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
</script>
</body>
</html>
