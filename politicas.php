<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'conexion.php';
require_once 'permisos_helper.php';

// Asegurar existencia de tablas
if ($pdo) {
    asegurarTablaPoliticas($pdo);
    asegurarTablasPermisos($pdo);
}

requerirPermiso('politicas', 'puede_ver');


// Datos de sesión del usuario
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Colaborador');
$loginUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? 'usuario');
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

// Obtener IP real del usuario para la marca de agua forense
$ipUsuario = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (strpos($ipUsuario, ',') !== false) {
    $ipUsuario = trim(explode(',', $ipUsuario)[0]);
}

// Cargar información de la agencia registrada
$agenciaInfo = null;
if ($pdo) {
    try {
        $stmtAg = $pdo->query("SELECT nombre, razon_social, logo_url FROM agencias ORDER BY id ASC LIMIT 1");
        $agenciaInfo = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {}
}
$agenciaNombre = !empty($agenciaInfo['nombre']) ? trim($agenciaInfo['nombre']) : ($_SESSION['agencia'] ?? 'Agencia');
$logoAgencia = (!empty($agenciaInfo['logo_url']) && file_exists(__DIR__ . '/' . $agenciaInfo['logo_url'])) ? $agenciaInfo['logo_url'] : '';

$mensaje = '';
$error = '';

// 2. Consulta Automática de Políticas desde el Portal Central GH (portal.grupohuerta.mx)
$politicasLista = [];
$urlCentral = 'https://portal.grupohuerta.mx/api_politicas.php?action=listar';
$ctx = stream_context_create([
    'http' => [
        'timeout' => 6,
        'header'  => "User-Agent: PortalAgencia/1.0\r\n"
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
]);

$resp = @file_get_contents($urlCentral, false, $ctx);
if ($resp) {
    $dataJson = json_decode($resp, true);
    if (!empty($dataJson['exito']) && !empty($dataJson['politicas'])) {
        $politicasLista = $dataJson['politicas'];
    }
}

// Fallback: si no hay conexión con Central GH, consultar base de datos local
if (empty($politicasLista) && $pdo) {
    try {
        $stmtList = $pdo->query("
            SELECT * FROM politicas_corporativas 
            WHERE estatus = 1 
            ORDER BY categoria ASC, titulo ASC
        ");
        $politicasLista = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}

// Extraer categorías únicas para filtro
$categorias = array_unique(array_filter(array_column($politicasLista, 'categoria')));
sort($categorias);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Políticas Corporativas Protegidas - PORTAL <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <?php include_once 'pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- PDF.js de Mozilla para renderizado nativo en Canvas (Sin descarga) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
    </script>

    <style>
        body {
            background-color: #061325;
            background-image: radial-gradient(#0e2440 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            user-select: none;
            -webkit-user-select: none;
        }

        .top-navbar {
            background: rgba(6, 19, 37, 0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .policy-card {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 22px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .policy-card:hover {
            transform: translateY(-4px);
            border-color: rgba(56, 189, 248, 0.5);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5), 0 0 20px rgba(56, 189, 248, 0.15);
        }

        .badge-mandatory {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.35);
            font-size: 0.68rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
        }

        /* ESTILOS DEL VISOR BLINDADO FULLSCREEN */
        #visorModalOverlay {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: #030a14;
            z-index: 999990;
            display: none;
            flex-direction: column;
        }

        .visor-header {
            background: #061325;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999995;
            flex-wrap: wrap;
            gap: 10px;
        }

        #visorCanvasContainer {
            flex-grow: 1;
            height: calc(100vh - 65px);
            overflow-y: auto;
            overflow-x: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px 20px;
            gap: 30px;
            position: relative;
            background: #050e1c;
        }

        /* CONTENEDOR DE CADA PÁGINA CON CAPAS DE SEGURIDAD */
        .pdf-page-wrapper {
            position: relative;
            flex-shrink: 0 !important;
            display: block;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.85);
            border-radius: 6px;
            overflow: hidden;
            background: #ffffff;
            margin: 0 auto;
        }

        .pdf-page-canvas {
            display: block;
            width: 100%;
            height: 100%;
        }

        /* CAPA 1: MARCA DE AGUA FORENSE DINÁMICA REPETIDA EN MOSAICO */
        .forensic-watermark-overlay {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            z-index: 5;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            grid-auto-rows: 150px;
            opacity: 0.24;
            overflow: hidden;
        }

        /* CAPA DE SEGURIDAD ÓPTICA TIPO BILLETE BANCARIO (ANTI-SENSOR CÁMARA MOIRÉ) */
        .banknote-security-pattern {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            z-index: 4;
            opacity: 0.16;
            background-image: 
                radial-gradient(#0f172a 1px, transparent 1px),
                repeating-linear-gradient(45deg, rgba(15,23,42,0.4) 0, rgba(15,23,42,0.4) 1px, transparent 0, transparent 3px),
                repeating-linear-gradient(-45deg, rgba(15,23,42,0.4) 0, rgba(15,23,42,0.4) 1px, transparent 0, transparent 3px);
            background-size: 6px 6px, 4px 4px, 4px 4px;
            mix-blend-mode: multiply;
        }

        .watermark-stamp {
            transform: rotate(-30deg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #dc2626;
            font-size: 0.72rem;
            font-weight: 800;
            text-align: center;
            line-height: 1.2;
            user-select: none;
            font-family: monospace;
            text-shadow: 0 0 1px rgba(0,0,0,0.25);
            letter-spacing: 0.5px;
        }

        
        @media print {
            * {
                display: none !important;
                visibility: hidden !important;
            }
            html, body {
                background: #000000 !important;
                background-color: #000000 !important;
                display: block !important;
            }
        }
    </style>
</head>
<body oncontextmenu="return false;">

<?php include_once 'pwa_body.php'; ?>



<!-- NAVBAR -->
<div class="top-navbar d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="d-flex align-items-center gap-2 gap-md-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3 py-1 px-2 px-md-3" title="Regresar al Menú Principal">
            <i class="bi bi-arrow-left"></i> <span class="d-none d-sm-inline ms-1">Menú Principal</span>
        </a>
        <span class="fw-bold fs-6 fs-md-5 text-truncate">
            PORTAL <span class="text-primary"><?php echo htmlspecialchars($agenciaNombre); ?></span> 
            <span class="text-secondary small d-none d-md-inline">| Políticas Corporativas</span>
        </span>
    </div>

    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-30 px-3 py-1.5 rounded-pill small d-none d-sm-inline-flex align-items-center gap-1.5">
            <i class="bi bi-cloud-check-fill text-info"></i> Sincronizado con Central GH
        </span>
        <span class="badge bg-success bg-opacity-25 text-success border border-success px-3 py-1.5 rounded-pill small d-none d-md-inline-flex align-items-center gap-1">
            <i class="bi bi-shield-lock-fill"></i> Visor Blindado Anti-Captura
        </span>
        <a href="politicas.php" class="btn btn-outline-secondary btn-sm rounded-3 py-1.5 px-2.5 text-light" title="Actualizar políticas">
            <i class="bi bi-arrow-clockwise"></i>
        </a>
    </div>
</div>

<div class="container-fluid py-4 px-3 px-md-4" style="max-width: 1400px;">

    <!-- MENSAJES DE NOTIFICACIÓN -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4 shadow" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4 shadow" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ENCABEZADO Y BUSCADOR -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom border-secondary border-opacity-25">
        <div>
            <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-lock2-fill text-primary"></i> Políticas y Normativas Institucionales
            </h3>
            <p class="text-secondary small mb-0">
                Documentos oficiales de observancia obligatoria para el personal de la agencia. Lectura protegida sin descarga permitida.
            </p>
        </div>

        <!-- BUSCADOR EN TIEMPO REAL -->
        <div class="d-flex align-items-center gap-2 w-100 w-md-auto">
            <div class="input-group input-group-sm" style="max-width: 320px;">
                <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                <input type="text" id="filtroTextoPolitica" class="form-control bg-dark text-white border-secondary" placeholder="Buscar por título o categoría..." oninput="filtrarPoliticasEnTiempoReal()">
            </div>
        </div>
    </div>

    <!-- PESTAÑAS DE CATEGORÍAS -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <button type="button" class="btn btn-sm btn-outline-primary active btn-cat-filtro rounded-pill px-3 fw-bold" onclick="filtrarPorCategoria('todas', this)">
            Todas (<?php echo count($politicasLista); ?>)
        </button>
        <?php foreach ($categorias as $cat): 
            $countCat = count(array_filter($politicasLista, function($p) use ($cat) { return $p['categoria'] === $cat; }));
        ?>
            <button type="button" class="btn btn-sm btn-outline-secondary text-light btn-cat-filtro rounded-pill px-3" onclick="filtrarPorCategoria('<?php echo htmlspecialchars($cat); ?>', this)">
                <?php echo htmlspecialchars($cat); ?> (<?php echo $countCat; ?>)
            </button>
        <?php endforeach; ?>
    </div>

    <!-- REJILLA DE TARJETAS DE POLÍTICAS -->
    <?php if (empty($politicasLista)): ?>
        <div class="text-center py-5 rounded-4 border border-secondary border-opacity-25 p-5" style="background: rgba(13, 30, 54, 0.4);">
            <i class="bi bi-shield-shaded display-1 text-secondary opacity-50 d-block mb-3"></i>
            <h4 class="fw-bold text-white">No hay políticas disponibles actualmente</h4>
            <p class="text-secondary small mb-0">
                Las políticas y normativas institucionales publicadas por la Dirección Central de Grupo Huerta aparecerán aquí automáticamente.
            </p>
        </div>
    <?php else: ?>
        <div class="row g-4" id="contenedorTarjetasPoliticas">
            <?php foreach ($politicasLista as $pol): ?>
                <div class="col-md-6 col-lg-4 item-politica-card" data-cat="<?php echo htmlspecialchars($pol['categoria']); ?>" data-titulo="<?php echo htmlspecialchars(strtolower($pol['titulo'] . ' ' . $pol['descripcion'])); ?>">
                    <div class="policy-card h-100">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                                <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-30 rounded-pill font-monospace" style="font-size: 0.7rem;">
                                    <?php echo htmlspecialchars($pol['categoria']); ?> &bull; v<?php echo htmlspecialchars($pol['version']); ?>
                                </span>
                                <?php if (!empty($pol['obligatorio_lectura'])): ?>
                                    <span class="badge-mandatory">
                                        <i class="bi bi-exclamation-circle-fill me-1"></i> Obligatorio
                                    </span>
                                <?php endif; ?>
                            </div>

                            <h5 class="fw-bold text-white mb-2" style="line-height: 1.3;">
                                <?php echo htmlspecialchars($pol['titulo']); ?>
                            </h5>
                            
                            <p class="text-secondary small mb-3" style="font-size: 0.82rem; min-height: 38px;">
                                <?php echo htmlspecialchars($pol['descripcion'] ?: 'Documento normativo corporativo de cumplimiento oficial.'); ?>
                            </p>
                        </div>

                        <div>
                            <div class="d-flex justify-content-between align-items-center pt-3 border-top border-secondary border-opacity-25 mb-3 small text-secondary" style="font-size: 0.72rem;">
                                <span><i class="bi bi-calendar3 me-1"></i> Vigencia: <?php echo !empty($pol['fecha_vigencia']) ? date('d/m/Y', strtotime($pol['fecha_vigencia'])) : 'Permanente'; ?></span>
                                <span><i class="bi bi-file-earmark-pdf text-danger me-1"></i> PDF Protegido</span>
                            </div>

                            <div>
                                <button type="button" class="btn btn-primary btn-sm w-100 rounded-3 fw-bold py-2 d-flex align-items-center justify-content-center gap-1.5 shadow" onclick="abrirVisorBlindado(<?php echo $pol['id']; ?>, '<?php echo addslashes(htmlspecialchars($pol['titulo'])); ?>')">
                                    <i class="bi bi-eye-fill"></i> Leer Documento Seguro
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- VISOR BLINDADO FULLSCREEN (MODAL CONTAINER) -->
<div id="visorModalOverlay">
    <!-- BARRA SUPERIOR DEL VISOR -->
    <div class="visor-header">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger px-2.5 py-1 rounded-pill small font-monospace">
                <i class="bi bi-shield-fill-x me-1"></i> LECTURA PROTEGIDA
            </span>
            <h6 class="fw-bold text-white mb-0 text-truncate" id="visorTituloDoc" style="max-width: 45vw;">
                Cargando Documento...
            </h6>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- CONTROLES DE ZOOM -->
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-dark text-white border-secondary" onclick="cambiarZoomVisor(-0.15)" title="Alejar Zoom">
                    <i class="bi bi-dash-lg"></i>
                </button>
                <span id="visorZoomBadge" class="badge bg-dark border-top border-bottom border-secondary text-info d-flex align-items-center px-2 font-monospace">
                    100%
                </span>
                <button type="button" class="btn btn-dark text-white border-secondary" onclick="cambiarZoomVisor(0.15)" title="Acercar Zoom">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>

            <!-- CONTROLES DE PÁGINA -->
            <div class="d-flex align-items-center gap-1.5 text-secondary small font-monospace">
                <span>Página <b id="lblPaginaActual" class="text-white">1</b> de <b id="lblTotalPaginas" class="text-white">1</b></span>
            </div>

            <!-- BADGE DE SEGURIDAD ÓPTICA -->
            <span class="badge bg-dark border border-secondary text-info rounded-pill px-2.5 py-1.5 small font-monospace d-none d-md-inline-flex align-items-center">
                <i class="bi bi-shield-lock-fill text-warning me-1"></i> Trama Óptica Anti-Cámara
            </span>

            
            <!-- BOTÓN CERRAR VISOR -->
            <button type="button" class="btn btn-outline-danger btn-sm rounded-3 fw-bold px-3 ms-2" onclick="cerrarVisorBlindado()">
                <i class="bi bi-x-lg me-1"></i> Cerrar
            </button>
        </div>
    </div>

    <!-- ÁREA DE RENDERIZADO DE PÁGINAS PDF CON MARCA DE AGUA Y MALLA MOIRÉ -->
    <div id="visorCanvasContainer">
        <div id="visorLoader" class="text-center py-5">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
            <h5 class="fw-bold text-white">Desencriptando y renderizando documento protegido...</h5>
            <p class="text-secondary small">Aplicando marcas de agua forenses y filtros ópticos anti-captura.</p>
        </div>
    </div>

        </div>
</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- LÓGICA DE CONTROL Y SEGURIDAD DEL VISOR BLINDADO -->
<script>
    // Datos forenses del usuario actual para la marca de agua
    const FORENSIC_USER_NAME = "<?php echo addslashes($nombreUsuario); ?>";
    const FORENSIC_USER_LOGIN = "<?php echo addslashes($loginUsuario); ?>";
    const FORENSIC_AGENCIA = "<?php echo addslashes($agenciaNombre); ?>";
    const FORENSIC_IP = "<?php echo addslashes($ipUsuario); ?>";

    let currentPdfDoc = null;
    let currentZoom = 1.0;
    let totalPdfPages = 0;
    let isViewerActive = false;

    // 1. PROTECCIÓN DE ATAJOS DE TECLADO (IMPRESIÓN, GUARDADO E INSPECCIÓN)
    window.addEventListener('keydown', function(e) {
        if (!isViewerActive) return;

        // PrintScreen / Recortes
        if (e.key === 'PrintScreen' || e.keyCode === 44 || e.key === 'Snapshot' || (e.shiftKey && (e.key === 's' || e.key === 'S'))) {
            e.preventDefault();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText('');
            }
            return false;
        }

        // Ctrl+P (Imprimir)
        if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            return false;
        }

        // Ctrl+S (Guardar)
        if (e.ctrlKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            return false;
        }

        // F12 o Ctrl+Shift+I (Inspeccionar)
        if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j'))) {
            e.preventDefault();
            return false;
        }

        // Ctrl+U (Ver código fuente)
        if (e.ctrlKey && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }
    }, true);

    // 2. FUNCIÓN PARA ABRIR Y RENDERIZAR EL VISOR BLINDADO
    function abrirVisorBlindado(docId, tituloDoc) {
        const modal = document.getElementById('visorModalOverlay');
        const container = document.getElementById('visorCanvasContainer');
        const tituloLbl = document.getElementById('visorTituloDoc');

        if (!modal || !container) return;

        tituloLbl.textContent = tituloDoc;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        isViewerActive = true;



        // Registrar lectura en bitácora auditable
        registrarLecturaAuditoria(docId, tituloDoc);

        // Limpiar visor y mostrar loader
        container.innerHTML = `
            <div id="visorLoader" class="text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
                <h5 class="fw-bold text-white">Desencriptando y renderizando documento protegido...</h5>
                <p class="text-secondary small">Aplicando marcas de agua forenses y filtros ópticos anti-captura.</p>
            </div>
        `;

        // URL segura del stream (sin exponer el archivo directo)
        const pdfUrl = 'api_politicas.php?action=stream_pdf&id=' + encodeURIComponent(docId);

        currentZoom = (window.innerWidth < 768) ? 0.9 : 1.25;
        document.getElementById('visorZoomBadge').textContent = Math.round(currentZoom * 100) + '%';

        pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true }).promise.then(function(pdf) {
            currentPdfDoc = pdf;
            totalPdfPages = pdf.numPages;
            document.getElementById('lblTotalPaginas').textContent = totalPdfPages;

            container.innerHTML = ''; // Quitar loader
            renderizarTodasLasPaginas(pdf, container);

        }).catch(function(error) {
            container.innerHTML = `
                <div class="alert alert-danger my-5 p-4 rounded-4 text-center">
                    <i class="bi bi-exclamation-triangle-fill fs-1 d-block mb-2"></i>
                    <h5 class="fw-bold">Error al abrir el documento</h5>
                    <p class="mb-0 small">${error.message || 'El documento no está disponible o no tienes permisos suficientes.'}</p>
                </div>
            `;
        });
    }

    // 3. RENDERIZAR CADA PÁGINA EN CANVAS CON CAPAS DE SEGURIDAD (EN ORDEN SECUENCIAL)
    function renderizarTodasLasPaginas(pdf, container) {
        container.innerHTML = '';
        const wrappers = [];

        // 1. Crear los contenedores individuales en orden secuencial estricto
        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const pageWrapper = document.createElement('div');
            pageWrapper.className = 'pdf-page-wrapper';
            pageWrapper.id = 'page-slot-' + pageNum;
            pageWrapper.style.flexShrink = '0';
            pageWrapper.innerHTML = `
                <div class="text-center py-5 text-secondary" style="min-height: 250px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="spinner-border spinner-border-sm text-primary mb-2"></div>
                    <span style="font-size: 0.8rem;">Cargando página ${pageNum} de ${pdf.numPages}...</span>
                </div>
            `;
            container.appendChild(pageWrapper);
            wrappers[pageNum] = pageWrapper;
        }

        // 2. Renderizar cada página en su respectivo contenedor
        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            (function(num, wrapper) {
                pdf.getPage(num).then(function(page) {
                    const viewport = page.getViewport({ scale: currentZoom });
                    const w = Math.round(viewport.width);
                    const h = Math.round(viewport.height);

                    // Dimensiones fijas inalterables contra aplastamiento de flexbox
                    wrapper.style.width = w + 'px';
                    wrapper.style.height = h + 'px';
                    wrapper.style.minHeight = h + 'px';
                    wrapper.style.maxHeight = h + 'px';
                    wrapper.style.flexShrink = '0';
                    wrapper.innerHTML = ''; // Limpiar loader de página

                    // Canvas para dibujo del PDF
                    const canvas = document.createElement('canvas');
                    canvas.className = 'pdf-page-canvas';
                    canvas.width = w;
                    canvas.height = h;
                    canvas.style.width = w + 'px';
                    canvas.style.height = h + 'px';
                    const ctx = canvas.getContext('2d');

                    // Renderizar PDF sobre Canvas
                    page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function() {
                        // 1. Trama de Seguridad Óptica Tipo Billete Bancario (Anti-Sensor Moiré)
                        const securityPattern = document.createElement('div');
                        securityPattern.className = 'banknote-security-pattern';
                        wrapper.appendChild(securityPattern);

                        // 2. Marca de Agua Forense Auditable
                        const watermarkLayer = document.createElement('div');
                        watermarkLayer.className = 'forensic-watermark-overlay';

                        const ahora = new Date().toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'medium' });
                        const textoSello = `<span style="font-size:0.62rem; color:#dc2626; font-weight:900; letter-spacing:1px;">DOCUMENTO PROTEGIDO</span><br><b style="color:#0f172a;">${FORENSIC_USER_NAME}</b><br>${FORENSIC_AGENCIA}<br>${ahora}`;

                        const numSellos = Math.max(8, Math.floor((w * h) / 40000));
                        for (let s = 0; s < numSellos; s++) {
                            const stamp = document.createElement('div');
                            stamp.className = 'watermark-stamp';
                            stamp.innerHTML = textoSello;
                            watermarkLayer.appendChild(stamp);
                        }

                        wrapper.appendChild(watermarkLayer);
                    });

                    wrapper.appendChild(canvas);
                });
            })(pageNum, wrappers[pageNum]);
        }
    }

    // 4. CONTROLES DE ZOOM Y CIERRE
    function cambiarZoomVisor(delta) {
        if (!currentPdfDoc) return;
        currentZoom = Math.max(0.5, Math.min(2.5, currentZoom + delta));
        document.getElementById('visorZoomBadge').textContent = Math.round(currentZoom * 100) + '%';
        const container = document.getElementById('visorCanvasContainer');
        container.innerHTML = '';
        renderizarTodasLasPaginas(currentPdfDoc, container);
    }

    function cerrarVisorBlindado() {
        const modal = document.getElementById('visorModalOverlay');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
        isViewerActive = false;
        currentPdfDoc = null;
    }

    // 5. FILTROS DE POLÍTICAS EN TIEMPO REAL
    function filtrarPorCategoria(categoria, btn) {
        document.querySelectorAll('.btn-cat-filtro').forEach(b => {
            b.classList.remove('active', 'btn-primary');
            b.classList.add('btn-outline-secondary');
        });
        btn.classList.add('active', 'btn-primary');
        btn.classList.remove('btn-outline-secondary');

        const items = document.querySelectorAll('.item-politica-card');
        items.forEach(item => {
            const itemCat = item.getAttribute('data-cat');
            if (categoria === 'todas' || itemCat === categoria) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function filtrarPoliticasEnTiempoReal() {
        const q = (document.getElementById('filtroTextoPolitica').value || '').toLowerCase().trim();
        const items = document.querySelectorAll('.item-politica-card');
        items.forEach(item => {
            const txt = item.getAttribute('data-titulo') || '';
            if (!q || txt.includes(q)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }

    // Registrar lectura y notificar a la bitácora corporativa
    function registrarLecturaAuditoria(docId, tituloDoc) {
        try {
            const formData = new FormData();
            formData.append('action', 'registrar_lectura');
            formData.append('politica_id', docId);
            formData.append('politica_titulo', tituloDoc);
            formData.append('usuario_nombre', FORENSIC_USER_NAME);
            formData.append('usuario_login', FORENSIC_USER_LOGIN);
            formData.append('agencia', FORENSIC_AGENCIA);
            formData.append('ip', FORENSIC_IP);

            fetch('api_politicas.php', {
                method: 'POST',
                body: formData
            }).catch(e => console.warn('Audit log notice:', e));
        } catch(err) {}
    }
</script>
</body>
</html>
