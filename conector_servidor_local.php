<?php
// ====================================================================
// CONECTOR AUTOMÁTICO DE BASE DE DATOS LOCAL -> CPANEL DE AGENCIA
// Coloca este archivo en tu servidor local (XAMPP, IIS o ejecútalo vía PHP CLI)
// ====================================================================

// --- 1. CONFIGURACIÓN DE TU BASE DE DATOS LOCAL (DMS / ERP) ---
$LOCAL_DB_HOST = '127.0.0.1';
$LOCAL_DB_PORT = 3306;
$LOCAL_DB_NAME = 'dms_agencia';
$LOCAL_DB_USER = 'root';
$LOCAL_DB_PASS = '';
$LOCAL_DB_TYPE = 'mysql'; // 'mysql' o 'sqlserver'

// --- 2. CREDENCIALES DE TU PORTAL CPANEL ---
$LOCAL_API_TOKEN     = 'TK_LOCAL_DEFAULT_2026';
$CPANEL_RECEPTOR_URL = 'https://portal.divolavilla.com/api_receptor_reportes_locales.php';

// Si existe archivo de configuración externo .env o local_config.php, cargarlo
if (file_exists(__DIR__ . '/local_config.php')) {
    include __DIR__ . '/local_config.php';
}

$isCli = (php_sapi_name() === 'cli');
$modoTest = isset($_GET['test']) || in_array('--test', $argv ?? []);

// --- 3. PRUEBA DE CONEXIÓN A BASE DE DATOS LOCAL ---
$pdoLocal = null;
$dbError = null;
$dbVersion = null;

try {
    if ($LOCAL_DB_TYPE === 'sqlserver') {
        $dsn = "sqlsrv:Server=$LOCAL_DB_HOST,$LOCAL_DB_PORT;Database=$LOCAL_DB_NAME";
        $pdoLocal = new PDO($dsn, $LOCAL_DB_USER, $LOCAL_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
    } else {
        $dsn = "mysql:host=$LOCAL_DB_HOST;port=$LOCAL_DB_PORT;dbname=$LOCAL_DB_NAME;charset=utf8mb4";
        $pdoLocal = new PDO($dsn, $LOCAL_DB_USER, $LOCAL_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
    }
    $dbVersion = $pdoLocal->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// --- 4. FUNCIÓN PARA ENVIAR PAYLOAD HACIA CPANEL ---
function enviarACpanel($url, $token, $payload) {
    $payload['token'] = $token;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'User-Agent: ConectorAgenciaLocal/1.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response'  => $response,
        'curl_error'=> $curlError,
        'json'      => json_decode($response, true)
    ];
}

// --- 5. MODO TEST INTERACTIVO ---
if ($modoTest || (!$isCli && !isset($_GET['ejecutar']))) {
    $testCpanel = enviarACpanel($CPANEL_RECEPTOR_URL, $LOCAL_API_TOKEN, [
        'accion' => 'ping',
        'origen' => 'conector_local_test'
    ]);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Diagnóstico del Conector Local - Portal Grupo Huerta</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <style>
            body { background: #030a16; color: #fff; font-family: 'Segoe UI', system-ui, sans-serif; padding: 40px 20px; }
            .card-box { background: #0b1a30; border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; padding: 25px; margin-bottom: 20px; }
            .badge-status { padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 0.85rem; }
        </style>
    </head>
    <body>
        <div class="container" style="max-width: 800px;">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h3 class="fw-bold text-primary mb-1"><i class="bi bi-hdd-network-fill me-2"></i>Conector Local de Agencia</h3>
                    <p class="text-secondary small mb-0">Comprobación de enlace entre Base de Datos Local y cPanel</p>
                </div>
                <span class="badge bg-dark border border-secondary text-secondary p-2">v2.0 Standalone</span>
            </div>

            <!-- Card 1: Base de Datos Local -->
            <div class="card-box">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-white"><i class="bi bi-database-fill-gear text-info me-2"></i>Base de Datos Local (DMS/ERP)</h5>
                    <?php if ($pdoLocal): ?>
                        <span class="badge-status bg-success bg-opacity-25 text-success border border-success">
                            <i class="bi bi-check-circle-fill me-1"></i> Conectado
                        </span>
                    <?php else: ?>
                        <span class="badge-status bg-danger bg-opacity-25 text-danger border border-danger">
                            <i class="bi bi-x-circle-fill me-1"></i> Error de Conexión
                        </span>
                    <?php endif; ?>
                </div>
                <div class="row g-2 small text-secondary">
                    <div class="col-sm-6"><strong>Host:</strong> <span class="text-light"><?php echo htmlspecialchars($LOCAL_DB_HOST . ':' . $LOCAL_DB_PORT); ?></span></div>
                    <div class="col-sm-6"><strong>Motor:</strong> <span class="text-light text-uppercase"><?php echo htmlspecialchars($LOCAL_DB_TYPE); ?></span></div>
                    <div class="col-sm-6"><strong>Base de Datos:</strong> <span class="text-light"><?php echo htmlspecialchars($LOCAL_DB_NAME ?: '(No asignada)'); ?></span></div>
                    <div class="col-sm-6"><strong>Usuario:</strong> <span class="text-light"><?php echo htmlspecialchars($LOCAL_DB_USER); ?></span></div>
                </div>
                <?php if ($pdoLocal): ?>
                    <div class="alert alert-success mt-3 py-2 px-3 small mb-0 border-0 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check2 me-1"></i> Conexión exitosa a motor local. Versión detectada: <strong><?php echo htmlspecialchars($dbVersion); ?></strong>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger mt-3 py-2 px-3 small mb-0 border-0 bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i> <?php echo htmlspecialchars($dbError); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card 2: Enlace hacia cPanel -->
            <div class="card-box">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-white"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Endpoint cPanel Receptor</h5>
                    <?php if ($testCpanel['http_code'] === 200): ?>
                        <span class="badge-status bg-success bg-opacity-25 text-success border border-success">
                            <i class="bi bi-check-circle-fill me-1"></i> 200 OK (Autorizado)
                        </span>
                    <?php else: ?>
                        <span class="badge-status bg-warning bg-opacity-25 text-warning border border-warning">
                            <i class="bi bi-exclamation-circle-fill me-1"></i> Código HTTP: <?php echo $testCpanel['http_code'] ?: 'Sin respuesta'; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="small text-secondary mb-2">
                    <strong>URL Destino:</strong> <span class="text-info"><?php echo htmlspecialchars($CPANEL_RECEPTOR_URL); ?></span>
                </div>
                <div class="small text-secondary mb-3">
                    <strong>Token API:</strong> <code class="text-warning"><?php echo htmlspecialchars(substr($LOCAL_API_TOKEN, 0, 8) . '••••••••'); ?></code>
                </div>
                <?php if ($testCpanel['http_code'] === 200): ?>
                    <div class="alert alert-success py-2 px-3 small mb-0 border-0 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle me-1"></i> cPanel respondió correctamente: <strong><?php echo htmlspecialchars($testCpanel['json']['mensaje'] ?? 'Ping OK'); ?></strong>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning py-2 px-3 small mb-0 border-0 bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-info-circle me-1"></i> <?php echo htmlspecialchars($testCpanel['curl_error'] ?: ($testCpanel['json']['mensaje'] ?? 'Verifica la URL y el Token en cPanel')); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Acciones -->
            <div class="d-flex gap-2">
                <a href="?ejecutar=1" class="btn btn-primary rounded-3 px-4 py-2">
                    <i class="bi bi-arrow-repeat me-1"></i> Ejecutar Sincronización Ahora
                </a>
                <a href="<?php echo htmlspecialchars($CPANEL_RECEPTOR_URL); ?>" target="_blank" class="btn btn-outline-secondary text-white rounded-3 px-3 py-2">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Abrir cPanel
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- 6. MODO DE EJECUCIÓN (EXTRACCIÓN Y ENVÍO DE REPORTES) ---
echo "[" . date('Y-m-d H:i:s') . "] Iniciando extracción de inventario desde GEDAS / Servidor Local...\n";

$reportesAEnviar = [];

if ($pdoLocal) {
    try {
        $sqlGedas = "SELECT 
            AUAUTOS.AUAlmCve                        AS [CveAlmacen],
            RTRIM(DEALER.AlmConce)                  AS [NombreAlmacen],
            AUAUTOS.AUCveAut                        AS [Inventario],
            AUAUTOS.AUDesAut                        AS [Descripcion],
            AUAUTOS.AUNumCha                        AS [Chasis],
            AUAUTOS.AUColExt                        AS [Color],
            AUAUTOS.AUNumMot                        AS [Motor],
            AUAUTOS.AUEquiOp                        AS [Equipamiento],
            AUAUTOS.AUDm                            AS [Marca],
            AUAUTOS.AUAnoAut                        AS [Año],
            AUAUTOS.AUStatus                        AS [Status],
            AUAUTOS.AUCtoVta                        AS [Precio de Venta],
            AUAUTOS.AUPrecioAd                      AS [Costo Inventario],
            AUAUTOS.AUImpLiq                        AS [Importe Inventario],
            AUAUTOS.AUFecha                         AS [Fecha Alta]
        FROM gedas.dbo.AUAUTOS AUAUTOS WITH (NOLOCK)
        LEFT JOIN gedas.dbo.GNCATMA DEALER WITH (NOLOCK) 
            ON AUAUTOS.AUAlmCve = DEALER.AlmCve
        WHERE AUAUTOS.AUStatus = 'Disponible'
        ORDER BY AUAUTOS.AUAlmCve, AUAUTOS.AUFecha DESC";

        $stmt = $pdoLocal->query($sqlGedas);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $reportesAEnviar[] = [
                'cve_almacen'        => trim($row['CveAlmacen'] ?? ''),
                'nombre_almacen'     => trim($row['NombreAlmacen'] ?? ''),
                'inventario'         => trim($row['Inventario'] ?? ''),
                'descripcion'        => trim($row['Descripcion'] ?? ''),
                'chasis'             => trim($row['Chasis'] ?? ''),
                'color'              => trim($row['Color'] ?? ''),
                'motor'              => trim($row['Motor'] ?? ''),
                'equipamiento'       => trim($row['Equipamiento'] ?? ''),
                'marca'              => trim($row['Marca'] ?? ''),
                'anio'               => trim($row['Año'] ?? ($row['Anio'] ?? '')),
                'status'             => trim($row['Status'] ?? 'Disponible'),
                'precio_venta'       => floatval($row['Precio de Venta'] ?? 0),
                'costo_inventario'   => floatval($row['Costo Inventario'] ?? 0),
                'importe_inventario' => floatval($row['Importe Inventario'] ?? 0),
                'fecha_alta'         => trim($row['Fecha Alta'] ?? date('Y-m-d H:i:s')),
                'datos_json'         => $row
            ];
        }
        echo "[" . date('Y-m-d H:i:s') . "] ✅ " . count($reportesAEnviar) . " autos disponibles extraídos de GEDAS con éxito.\n";
    } catch (Throwable $eSql) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ Error al ejecutar consulta GEDAS: " . $eSql->getMessage() . "\n";
    }
} else {
    echo "[" . date('Y-m-d H:i:s') . "] ❌ Error en conexión local: $dbError\n";
}

// 7. Enviar a cPanel
echo "[" . date('Y-m-d H:i:s') . "] Transmitiendo " . count($reportesAEnviar) . " reporte(s) a cPanel ($CPANEL_RECEPTOR_URL)...\n";
$resCpanel = enviarACpanel($CPANEL_RECEPTOR_URL, $LOCAL_API_TOKEN, [
    'tipo_reporte' => 'operativo_local',
    'reportes'     => $reportesAEnviar
]);

if ($resCpanel['http_code'] === 200) {
    echo "[" . date('Y-m-d H:i:s') . "] ✅ Sincronización exitosa: " . ($resCpanel['json']['mensaje'] ?? 'OK') . "\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] ⚠️ Falló el envío a cPanel (HTTP " . $resCpanel['http_code'] . "): " . ($resCpanel['curl_error'] ?: $resCpanel['response']) . "\n";
}
