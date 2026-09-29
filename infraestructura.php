<?php
require_once 'php_helpers/infraestructura_controller.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infraestructura SITE / IDF - Portal de Sistemas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/TransformControls.js"></script>
    <style>
        #canvas3DContainer:fullscreen, #canvas3DContainer:-webkit-full-screen {
            width: 100vw !important;
            height: 100vh !important;
            min-height: 100vh !important;
            max-height: 100vh !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 999999 !important;
        }

        body {
            background-color: #061325;
            background-image: radial-gradient(#0e2440 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }
        .top-navbar {
            background: rgba(6, 19, 37, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }
        .sidebar-wrapper {
            background: #091a30;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            position: sticky;
            top: 61px;
            height: calc(100vh - 61px);
            overflow-y: auto;
            padding: 20px 14px;
            z-index: 100;
        }

        .sidebar-wrapper::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-wrapper::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        /* ESTILOS DE ALTO CONTRASTE PARA TABLAS Y MODALES */
        .table-custom {
            --bs-table-bg: transparent !important;
            --bs-table-accent-bg: transparent !important;
            --bs-table-striped-bg: rgba(255, 255, 255, 0.02) !important;
            --bs-table-hover-bg: rgba(56, 189, 248, 0.08) !important;
            color: #e2e8f0 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        .table-custom th {
            background-color: #09182b !important;
            color: #94a3b8 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            font-size: 0.75rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }

        .table-custom td {
            background-color: transparent !important;
            color: #e2e8f0 !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }

        .futuristic-big-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            cursor: pointer;
        }

        .futuristic-big-card:hover {
            transform: translateY(-8px) scale(1.03) !important;
            border-color: rgba(56, 189, 248, 0.8) !important;
            box-shadow: 0 20px 45px rgba(56, 189, 248, 0.25) !important;
        }

        /* ESTILOS FUTURISTAS DE NODOS RJ45 FLOTANTES */
        .nodo-floating-card {
            background: linear-gradient(145deg, rgba(15, 23, 42, 0.95), rgba(14, 165, 233, 0.08));
            border: 1.5px solid rgba(56, 189, 248, 0.35);
            backdrop-filter: blur(12px);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4), inset 0 0 15px rgba(56, 189, 248, 0.05);
        }

        .nodo-floating-card:hover {
            transform: translateY(-8px) scale(1.05);
            border-color: rgba(56, 189, 248, 0.85);
            box-shadow: 0 15px 35px rgba(56, 189, 248, 0.3), inset 0 0 25px rgba(56, 189, 248, 0.15);
        }

        .nodo-icon-glow {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(56, 189, 248, 0.15);
            border: 2px solid rgba(56, 189, 248, 0.6);
            color: #38bdf8;
            font-size: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.4);
            transition: all 0.3s ease;
        }

        .nodo-floating-card:hover .nodo-icon-glow {
            background: rgba(56, 189, 248, 0.3);
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 30px rgba(56, 189, 248, 0.8);
            transform: rotate(5deg) scale(1.1);
        }

        .modal-content-custom {
            background: #0f172a !important;
            background-color: #0f172a !important;
            color: #ffffff !important;
            border: 1px solid rgba(56, 189, 248, 0.4) !important;
            border-radius: 16px !important;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8) !important;
        }

        .modal-content-custom .text-secondary {
            color: #94a3b8 !important;
        }

        .modal-content-custom .text-light {
            color: #e2e8f0 !important;
        }

        .modal-content-custom .modal-header,
        .modal-content-custom .modal-footer {
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        /* PALETA DE HERRAMIENTAS FUTURISTA Y ACORDEÓN COMBO */
        .futuristic-palette {
            background: rgba(9, 23, 44, 0.95);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.6), inset 0 0 20px rgba(56, 189, 248, 0.04);
        }

        .futuristic-accordion-item {
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px !important;
            margin-bottom: 10px;
            overflow: hidden;
            transition: all 0.25s ease;
        }

        .futuristic-accordion-item:hover {
            border-color: rgba(56, 189, 248, 0.4);
            box-shadow: 0 0 12px rgba(56, 189, 248, 0.12);
        }

        .futuristic-accordion-btn {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.95));
            color: #f8fafc;
            font-weight: 700;
            font-size: 0.78rem;
            letter-spacing: 0.5px;
            padding: 10px 14px;
            border: none;
            width: 100%;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .futuristic-accordion-btn:not(.collapsed) {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.22), rgba(15, 23, 42, 0.95));
            color: #38bdf8;
            border-bottom: 1px solid rgba(56, 189, 248, 0.3);
        }

        .futuristic-accordion-btn .chevron-icon {
            transition: transform 0.25s ease;
        }

        .futuristic-accordion-btn:not(.collapsed) .chevron-icon {
            transform: rotate(180deg);
        }

        .futuristic-tool-btn {
            background: rgba(30, 41, 59, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #e2e8f0;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 7px 10px;
            border-radius: 8px;
            transition: all 0.2s ease;
            text-align: left;
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: grab;
            user-select: none;
        }

        .futuristic-tool-btn:hover {
            background: rgba(56, 189, 248, 0.2);
            border-color: rgba(56, 189, 248, 0.5);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .futuristic-tool-btn:active {
            cursor: grabbing;
        }

        .sidebar-section-title {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 12px;
            padding-left: 10px;
        }
        .nav-link-custom {
            color: #94a3b8;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
            text-decoration: none;
            margin-bottom: 4px;
            border: 1px solid transparent;
        }
        .nav-link-custom:hover {
            color: #ffffff;
            background: rgba(37, 99, 235, 0.15);
            border-color: rgba(37, 99, 235, 0.3);
        }
        .nav-link-custom.active {
            color: #ffffff;
            background: #2563eb;
            border-color: #2563eb;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
        }
        .nav-icon-box {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.05);
        }
        .content-wrapper {
            padding: 24px 30px;
        }
        .card-custom {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }
        .sidebar-palette-container {
            max-height: calc(100vh - 120px);
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 6px;
        }
        .sidebar-palette-container::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-palette-container::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.6);
            border-radius: 4px;
        }
        .sidebar-palette-container::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
        .sidebar-palette-container::-webkit-scrollbar-thumb:hover {
            background: #38bdf8;
        }
        .table-custom {
            color: #cbd5e1;
            margin-bottom: 0;
        }
        .table-custom th {
            background: #071527;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px 14px;
        }
        .table-custom td {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 14px;
            vertical-align: middle;
            font-size: 0.88rem;
        }

        /* ESTILOS DEL EDITOR CANVAS 2D DE PLANOS DE AGENCIA */
        .canvas-container-outer {
            position: relative;
            background: #ffffff;
            background-image: 
                linear-gradient(rgba(0, 0, 0, 0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 0, 0, 0.06) 1px, transparent 1px);
            background-size: 25px 25px;
            border: 2px solid #cbd5e1;
            border-radius: 16px;
            min-height: 650px;
            overflow: auto;
            user-select: none;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.03);
        }
        .canvas-bg-img {
            position: absolute;
            top: 0;
            left: 0;
            pointer-events: none;
            opacity: 0.9;
            max-width: none;
        }
        .canvas-node-item {
            position: absolute;
            cursor: move;
            transform: translate(-50%, -50%);
            z-index: 25;
            transition: transform 0.1s ease;
        }
        .canvas-node-item:hover {
            z-index: 55;
        }
        .node-pin {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35), 0 0 0 2.5px rgba(255, 255, 255, 0.95);
            border: 2.5px solid #ffffff;
            transition: all 0.2s;
        }
        .node-pin.selected {
            box-shadow: 0 0 0 4.5px #f59e0b, 0 8px 25px rgba(245, 158, 11, 0.6);
            transform: scale(1.22);
        }
        .node-label {
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(9, 26, 48, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            white-space: nowrap;
            margin-top: 4px;
            pointer-events: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
        }
        
        /* FIGURAS GEOMÉTRICAS Y POLÍGONOS 2D */
        .canvas-shape-item {
            position: absolute;
            cursor: move;
            z-index: 10;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
        }
        .canvas-shape-item.selected-shape {
            outline: 2.5px dashed #f59e0b !important;
            outline-offset: 4px;
            z-index: 40;
        }
        .shape-label-text {
            font-size: 0.75rem;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 1px 4px rgba(0,0,0,0.8);
            pointer-events: none;
            text-align: center;
            padding: 2px 6px;
        }

        /* PUNTOS CONTORNO DE AJUSTE DE TAMAÑO (RESIZE HANDLES) */
        .resize-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #f59e0b;
            border: 1px solid #ffffff;
            border-radius: 2px;
            z-index: 55;
            box-shadow: 0 0 6px rgba(245, 158, 11, 0.9);
        }
        .handle-nw { top: -5px; left: -5px; cursor: nwse-resize; }
        .handle-ne { top: -5px; right: -5px; cursor: nesw-resize; }
        .handle-se { bottom: -5px; right: -5px; cursor: nwse-resize; }
        .handle-sw { bottom: -5px; left: -5px; cursor: nesw-resize; }
        .handle-n { top: -5px; left: 50%; transform: translateX(-50%); cursor: ns-resize; }
        .handle-s { bottom: -5px; left: 50%; transform: translateX(-50%); cursor: ns-resize; }
        .handle-w { top: 50%; left: -5px; transform: translateY(-50%); cursor: ew-resize; }
        .handle-e { top: 50%; right: -5px; transform: translateY(-50%); cursor: ew-resize; }

        /* PUNTOS DE VÉRTICE / DOBLAJE PARA POLÍGONOS IRREGULARES */
        .vertex-point {
            position: absolute;
            width: 13px;
            height: 13px;
            background: #ef4444;
            border: 2px solid #ffffff;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            cursor: crosshair;
            z-index: 60;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.9);
            transition: transform 0.1s ease;
        }
        .vertex-point:hover {
            transform: translate(-50%, -50%) scale(1.3);
            background: #dc2626;
        }

        /* Cobertura Wi-Fi & Cámaras para Fondo Blanco */
        .wifi-radius-circle {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: rgba(5, 150, 105, 0.12);
            border: 2px dashed #059669;
            pointer-events: none;
            z-index: 5;
        }
        .camera-cone {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            transform: translate(-50%, 0);
            pointer-events: none;
            z-index: 1;
        }

        .tool-btn {
            background: #091a30;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tool-btn:hover {
            color: #ffffff;
            background: rgba(37, 99, 235, 0.2);
            border-color: #3b82f6;
        }
    </style>
</head>
<body>

<!-- Pestaña Flotante para Mostrar Sidebar Oculto -->
<button type="button" id="btnShowSidebarTab" class="btn btn-success btn-sm rounded-end-3 shadow-lg" onclick="toggleSidebarNavegacion()" style="display: none; position: fixed; left: 0; top: 78px; z-index: 1050; padding: 8px 10px; border-left: none;" title="Mostrar Menú Lateral (Navegación)">
    <i class="bi bi-layout-sidebar-reverse fs-5 text-white"></i>
</button>

<?php require_once 'views/infraestructura/navbar.php'; ?>

<div class="container-fluid p-0">
    <div class="row g-0">
        <?php require_once 'views/infraestructura/sidebar.php'; ?>

        <div id="colContentWrapper" class="col-md-9 col-lg-10 content-wrapper">
            <?php
            if ($seccion_activa === 'site') {
                require_once 'views/infraestructura/sec_site.php';
            } elseif ($seccion_activa === 'red') {
                require_once 'views/infraestructura/sec_red.php';
            } else {
                require_once 'views/infraestructura/sec_diagramas.php';
            }
            ?>
        </div>
    </div>
</div>

<?php require_once 'views/infraestructura/modals.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.equiposInventarioMap = <?php echo json_encode(array_column($equiposInventario, null, 'key')); ?>;
    window.planoActualData = <?php echo json_encode($planoActual ?? ['id' => 1, 'nombre_plano' => 'Plano Principal', 'elementos_json' => '[]']); ?>;
    window.savedSiteRacksData = <?php 
        $obsJson = $siteData['observaciones'] ?? ($registros[0]['observaciones'] ?? '');
        echo !empty($obsJson) ? json_encode($obsJson) : 'null'; 
    ?>;
    window.esModoLectura = <?php echo (isset($_GET['modo']) && $_GET['modo'] === 'configurar') ? 'false' : 'true'; ?>;
    window.currentRedSubseccion = '<?php echo $subseccion_red ?? 'menu'; ?>';
    window.seccionActiva = '<?php echo $seccion_activa ?? 'diagramas'; ?>';
    window.modoUrl = '<?php echo isset($_GET['modo']) ? htmlspecialchars($_GET['modo'], ENT_QUOTES, 'UTF-8') : 'inicio'; ?>';
</script>
<script src="js/infraestructura/diagramas_2d_3d.js?v=<?php echo file_exists('js/infraestructura/diagramas_2d_3d.js') ? filemtime('js/infraestructura/diagramas_2d_3d.js') : '1.0'; ?>"></script>
<script src="js/infraestructura/red_vlans.js?v=<?php echo file_exists('js/infraestructura/red_vlans.js') ? filemtime('js/infraestructura/red_vlans.js') : '1.0'; ?>"></script>
<script src="js/infraestructura/site_engine.js?v=<?php echo file_exists('js/infraestructura/site_engine.js') ? filemtime('js/infraestructura/site_engine.js') : '1.0'; ?>"></script>
</body>
</html>
