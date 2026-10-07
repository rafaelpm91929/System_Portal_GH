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
$agenciaRazonSocial = !empty($agenciaInfo['razon_social']) ? trim($agenciaInfo['razon_social']) : '';
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

// Conteo de normativas obligatorias
$totalObligatorias = count(array_filter($politicasLista, function($p) {
    return !empty($p['obligatorio_lectura']);
}));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gobierno Corporativo & Políticas de Alta Dirección - <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <?php include_once 'pwa_head.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Cinzel:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- PDF.js de Mozilla para renderizado nativo en Canvas (Sin descarga) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
    </script>

    <style>
        :root {
            --bg-executive: #050b16;
            --surface-card: #091528;
            --surface-card-hover: #0e203c;
            --gold-accent: #cfa859;
            --gold-accent-light: #f5df9e;
            --gold-border: rgba(207, 168, 89, 0.28);
            --gold-glow: rgba(207, 168, 89, 0.15);
            --sapphire-accent: #1e3a8a;
            --sapphire-border: rgba(59, 130, 246, 0.25);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
        }

        body {
            background-color: var(--bg-executive);
            background-image: 
                radial-gradient(circle at 15% 15%, rgba(30, 58, 138, 0.25) 0%, transparent 45%),
                radial-gradient(circle at 85% 10%, rgba(207, 168, 89, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 50% 85%, rgba(15, 23, 42, 0.8) 0%, transparent 60%);
            background-attachment: fixed;
            color: var(--text-primary);
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            user-select: none;
            -webkit-user-select: none;
            overflow-x: hidden;
        }

        /* BARRA SUPERIOR EJECUTIVA */
        .executive-navbar {
            background: rgba(5, 11, 22, 0.94);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
            padding: 12px 28px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .brand-crest {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(207, 168, 89, 0.25) 0%, rgba(30, 58, 138, 0.3) 100%);
            border: 1px solid var(--gold-border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold-accent-light);
            font-size: 1.15rem;
            box-shadow: 0 0 15px var(--gold-glow);
        }

        .agency-logo-thumb {
            max-height: 34px;
            max-width: 120px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));
            border-radius: 4px;
        }

        /* HERO DE GOBIERNO CORPORATIVO */
        .hero-boardroom {
            background: linear-gradient(135deg, rgba(10, 24, 46, 0.85) 0%, rgba(6, 14, 28, 0.92) 100%);
            border: 1px solid rgba(207, 168, 89, 0.2);
            border-radius: 20px;
            padding: 32px 36px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .hero-boardroom::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(207, 168, 89, 0.12) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .badge-executive-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(207, 168, 89, 0.12);
            border: 1px solid var(--gold-border);
            color: var(--gold-accent-light);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            padding: 5px 14px;
            border-radius: 50px;
            margin-bottom: 12px;
        }

        .hero-title {
            font-size: 2.1rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #ffffff;
            margin-bottom: 10px;
            line-height: 1.2;
        }

        .hero-subtitle {
            color: #cbd5e1;
            font-size: 0.95rem;
            line-height: 1.6;
            max-width: 820px;
            margin-bottom: 18px;
        }

        /* STATS / KPIS DE ALTA DIRECCIÓN */
        .executive-kpi-card {
            background: rgba(6, 16, 32, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.25s ease;
            height: 100%;
        }

        .executive-kpi-card:hover {
            border-color: var(--gold-border);
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.35);
        }

        .kpi-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .kpi-icon-gold {
            background: rgba(207, 168, 89, 0.15);
            color: var(--gold-accent-light);
            border: 1px solid var(--gold-border);
        }

        .kpi-icon-blue {
            background: rgba(37, 99, 235, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(37, 99, 235, 0.3);
        }

        .kpi-icon-green {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .kpi-icon-red {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .kpi-value {
            font-size: 1.55rem;
            font-weight: 800;
            color: #ffffff;
            line-height: 1;
            margin-bottom: 4px;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .kpi-label {
            font-size: 0.74rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0;
        }

        /* BUSCADOR Y FILTROS */
        .executive-search-input {
            background: rgba(7, 18, 36, 0.8) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            color: #ffffff !important;
            border-radius: 12px;
            padding: 10px 16px;
            font-size: 0.9rem;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
            transition: all 0.2s ease;
        }

        .executive-search-input:focus {
            border-color: var(--gold-accent) !important;
            box-shadow: 0 0 0 3px rgba(207, 168, 89, 0.15) !important;
        }

        .btn-filter-pill {
            background: rgba(10, 22, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 7px 18px;
            border-radius: 50px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-filter-pill:hover {
            background: rgba(20, 38, 70, 0.85);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.25);
        }

        .btn-filter-pill.active {
            background: linear-gradient(135deg, rgba(207, 168, 89, 0.25) 0%, rgba(30, 58, 138, 0.4) 100%);
            border-color: var(--gold-accent);
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 0 15px rgba(207, 168, 89, 0.2);
        }

        .filter-count-badge {
            background: rgba(0, 0, 0, 0.35);
            font-size: 0.7rem;
            padding: 2px 7px;
            border-radius: 20px;
            font-weight: 700;
        }

        /* TARJETAS DE POLÍTICAS DE ALTA DIRECCIÓN */
        .executive-policy-card {
            background: linear-gradient(160deg, #09172d 0%, #061122 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 24px;
            transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
        }

        .executive-policy-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--gold-accent), transparent);
            opacity: 0.4;
            transition: opacity 0.3s ease;
        }

        .executive-policy-card:hover {
            transform: translateY(-5px);
            border-color: var(--gold-border);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.5), 0 0 25px var(--gold-glow);
        }

        .executive-policy-card:hover::before {
            opacity: 1;
        }

        .policy-folio-badge {
            background: rgba(207, 168, 89, 0.1);
            color: var(--gold-accent-light);
            border: 1px solid var(--gold-border);
            font-family: 'Plus Jakarta Sans', monospace;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 8px;
            letter-spacing: 0.5px;
        }

        .badge-exec-mandatory {
            background: rgba(239, 68, 68, 0.14);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
            font-size: 0.68rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            letter-spacing: 0.3px;
        }

        .badge-exec-standard {
            background: rgba(59, 130, 246, 0.12);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.28);
            font-size: 0.68rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .policy-domain-pill {
            font-size: 0.72rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
        }

        .policy-title-exec {
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 800;
            line-height: 1.35;
            letter-spacing: -0.2px;
            margin-bottom: 10px;
        }

        .policy-desc-exec {
            color: #94a3b8;
            font-size: 0.86rem;
            line-height: 1.55;
            min-height: 48px;
            margin-bottom: 18px;
        }

        .policy-metadata-box {
            background: rgba(5, 12, 24, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .btn-access-exec {
            background: linear-gradient(135deg, #1e3a8a 0%, #172554 100%);
            border: 1px solid rgba(96, 165, 250, 0.35);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.88rem;
            padding: 10px 18px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
            width: 100%;
        }

        .btn-access-exec:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            border-color: rgba(147, 197, 253, 0.7);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(37, 99, 235, 0.35);
        }

        /* PIE DE GOBIERNO INSTITUCIONAL */
        .executive-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            margin-top: 50px;
            padding: 24px 20px;
            color: var(--text-muted);
            font-size: 0.8rem;
            line-height: 1.6;
        }

        /* VISOR BLINDADO FULLSCREEN (MODAL CONTAINER) */
        #visorModalOverlay {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: #020610;
            z-index: 999990;
            display: none;
            flex-direction: column;
        }

        .visor-header {
            background: #050b16;
            border-bottom: 1px solid rgba(207, 168, 89, 0.2);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999995;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
        }

        #visorCanvasContainer {
            flex-grow: 1;
            height: calc(100vh - 65px);
            overflow-y: auto;
            overflow-x: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 35px 20px;
            gap: 32px;
            position: relative;
            background: #030814;
        }

        /* CONTENEDOR DE CADA PÁGINA CON CAPAS DE SEGURIDAD */
        .pdf-page-wrapper {
            position: relative;
            flex-shrink: 0 !important;
            display: block;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.9);
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
            margin: 0 auto;
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: filter 0.18s ease, opacity 0.18s ease;
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
            opacity: 0.15;
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
            line-height: 1.25;
            user-select: none;
            font-family: 'Plus Jakarta Sans', monospace;
            text-shadow: 0 0 1px rgba(0,0,0,0.25);
            letter-spacing: 0.5px;
        }

        /* OVERLAY DE BLOQUEO POR CURSOR FUERA DEL PDF */
        #pdfLockOverlay {
            position: absolute;
            top: 60px;
            left: 0;
            width: 100%;
            height: calc(100% - 60px);
            background: rgba(2, 6, 16, 0.95);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            z-index: 999998;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            opacity: 0;
            visibility: hidden;
        }

        #pdfLockOverlay.activo {
            opacity: 1;
            visibility: visible;
        }

        .pdf-lock-box-exec {
            max-width: 580px;
            background: rgba(9, 21, 40, 0.85);
            border: 1px solid var(--gold-border);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7), 0 0 30px var(--gold-glow);
            border-radius: 20px;
            padding: 36px 32px;
            text-align: center;
        }

        .pdf-lock-icon-wrap {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px auto;
            border-radius: 50%;
            background: rgba(207, 168, 89, 0.15);
            border: 2px solid var(--gold-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.3rem;
            color: var(--gold-accent-light);
            box-shadow: 0 0 30px rgba(207, 168, 89, 0.35);
            animation: pulseSecurityLock 2s infinite ease-in-out;
        }

        @keyframes pulseSecurityLock {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 20px rgba(207, 168, 89, 0.3);
            }
            50% {
                transform: scale(1.06);
                box-shadow: 0 0 40px rgba(207, 168, 89, 0.6);
            }
        }

        /* EFECTO BLUR SOBRE LAS PÁGINAS PDF CUANDO ESTÁ BLOQUEADO */
        .visor-bloqueado .pdf-page-wrapper {
            filter: blur(28px) grayscale(95%) !important;
            opacity: 0.1 !important;
        }

        .pulsing-dot {
            width: 8px;
            height: 8px;
            background-color: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px #10b981;
            animation: pulseDot 2s infinite;
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
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

<!-- BARRA DE NAVEGACIÓN EJECUTIVA -->
<nav class="executive-navbar d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3 py-1.5 px-3 d-flex align-items-center gap-1.5" title="Regresar al Menú Principal">
            <i class="bi bi-arrow-left"></i> <span class="d-none d-sm-inline">Menú Principal</span>
        </a>

        <div class="d-flex align-items-center gap-2.5">
            <?php if (!empty($logoAgencia)): ?>
                <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="Logo Agencia" class="agency-logo-thumb me-1">
            <?php else: ?>
                <div class="brand-crest">
                    <i class="bi bi-shield-check"></i>
                </div>
            <?php endif; ?>

            <div>
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold fs-6 text-white text-uppercase tracking-wider">
                        <?php echo htmlspecialchars($agenciaNombre); ?>
                    </span>
                    <span class="badge bg-dark border border-secondary text-secondary small px-2 py-0.5 rounded-pill font-monospace d-none d-md-inline" style="font-size: 0.65rem;">
                        PORTAL CORPORATIVO
                    </span>
                </div>
                <div class="small text-secondary" style="font-size: 0.72rem; letter-spacing: 0.6px;">
                    <span class="text-gold" style="color: var(--gold-accent-light);"><i class="bi bi-award-fill me-1"></i>Alta Dirección</span> &bull; Marco Normativo Institucional
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <!-- BADGE SINCRONIZACIÓN CENTRAL GH -->
        <span class="badge bg-dark border border-secondary text-light px-3 py-1.5 rounded-pill small d-none d-sm-inline-flex align-items-center gap-2 shadow-sm font-monospace">
            <span class="pulsing-dot"></span> Sincronizado Central GH
        </span>

        <!-- BADGE DLP DE AUDITORÍA -->
        <span class="badge bg-dark border border-secondary px-3 py-1.5 rounded-pill small d-none d-lg-inline-flex align-items-center gap-1.5 shadow-sm" style="color: var(--gold-accent-light); border-color: var(--gold-border) !important;">
            <i class="bi bi-shield-lock-fill"></i> Nivel 1 &bull; Confidencial
        </span>

        <!-- BOTÓN ACTUALIZAR REPOSITORIO -->
        <a href="politicas.php" class="btn btn-outline-secondary btn-sm rounded-3 py-1.5 px-2.5 text-light" title="Actualizar repositorio de políticas">
            <i class="bi bi-arrow-clockwise"></i>
        </a>
    </div>
</nav>

<div class="container-fluid py-4 px-3 px-md-4" style="max-width: 1440px;">

    <!-- MENSAJES DE NOTIFICACIÓN -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 mb-4 shadow-lg" role="alert" style="background: rgba(6, 78, 59, 0.85); color: #a7f3d0;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-4 mb-4 shadow-lg" role="alert" style="background: rgba(127, 29, 29, 0.85); color: #fecaca;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo $error; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- HERO DE GOBIERNO CORPORATIVO Y ALTA DIRECCIÓN -->
    <div class="hero-boardroom">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="badge-executive-pill">
                    <i class="bi bi-award-fill"></i> Gobierno Corporativo &bull; Consejo & Dirección
                </div>
                <h1 class="hero-title">
                    Políticas Institucionales y Normativas Oficiales
                </h1>
                <p class="hero-subtitle">
                    Repositorio oficial de directrices estratégicas, códigos de conducta y normativas corporativas emitidas para accionistas, alta dirección y personal de <strong><?php echo htmlspecialchars($agenciaNombre); ?></strong>. Lectura protegida mediante custodia criptográfica y bitácora forense auditable.
                </p>

                <div class="d-flex flex-wrap align-items-center gap-3 pt-1 text-secondary small">
                    <span class="d-inline-flex align-items-center gap-1.5">
                        <i class="bi bi-shield-check text-success fs-6"></i> Custodia Digital Anti-Exfiltración
                    </span>
                    <span class="text-secondary opacity-50">&bull;</span>
                    <span class="d-inline-flex align-items-center gap-1.5">
                        <i class="bi bi-fingerprint text-info fs-6"></i> Registro Forense Inmutable
                    </span>
                    <span class="text-secondary opacity-50">&bull;</span>
                    <span class="d-inline-flex align-items-center gap-1.5" style="color: var(--gold-accent-light);">
                        <i class="bi bi-building-check fs-6"></i> Dirección General Grupo Huerta
                    </span>
                </div>
            </div>

            <!-- KPIS EJECUTIVOS EN REJILLA -->
            <div class="col-lg-4">
                <div class="row g-2.5">
                    <div class="col-6">
                        <div class="executive-kpi-card">
                            <div class="kpi-icon-box kpi-icon-gold">
                                <i class="bi bi-file-earmark-lock2-fill"></i>
                            </div>
                            <div>
                                <div class="kpi-value"><?php echo count($politicasLista); ?></div>
                                <div class="kpi-label">Normativas</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="executive-kpi-card">
                            <div class="kpi-icon-box kpi-icon-blue">
                                <i class="bi bi-diagram-3-fill"></i>
                            </div>
                            <div>
                                <div class="kpi-value"><?php echo count($categorias); ?></div>
                                <div class="kpi-label">Dominios</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="executive-kpi-card">
                            <div class="kpi-icon-box kpi-icon-red">
                                <i class="bi bi-shield-fill-exclamation"></i>
                            </div>
                            <div>
                                <div class="kpi-value"><?php echo $totalObligatorias; ?></div>
                                <div class="kpi-label">Obligatorias</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="executive-kpi-card">
                            <div class="kpi-icon-box kpi-icon-green">
                                <i class="bi bi-patch-check-fill"></i>
                            </div>
                            <div>
                                <div class="kpi-value">100%</div>
                                <div class="kpi-label">Vigencia</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BARRA DE FILTROS Y BÚSQUEDA EN TIEMPO REAL -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-2">
        <!-- PESTAÑAS DE CATEGORÍAS -->
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn-filter-pill active btn-cat-filtro" onclick="filtrarPorCategoria('todas', this)">
                <i class="bi bi-grid-fill"></i> Todas <span class="filter-count-badge"><?php echo count($politicasLista); ?></span>
            </button>
            <?php foreach ($categorias as $cat): 
                $countCat = count(array_filter($politicasLista, function($p) use ($cat) { return $p['categoria'] === $cat; }));
            ?>
                <button type="button" class="btn-filter-pill btn-cat-filtro" onclick="filtrarPorCategoria('<?php echo htmlspecialchars($cat); ?>', this)">
                    <?php echo htmlspecialchars($cat); ?> <span class="filter-count-badge"><?php echo $countCat; ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- BUSCADOR EJECUTIVO -->
        <div class="w-100 w-md-auto" style="min-width: 320px;">
            <div class="input-group">
                <span class="input-group-text bg-dark border-secondary text-secondary" style="border-radius: 12px 0 0 12px;">
                    <i class="bi bi-search"></i>
                </span>
                <input type="text" id="filtroTextoPolitica" class="form-control executive-search-input" style="border-radius: 0 12px 12px 0;" placeholder="Buscar directriz por título, área o palabra clave..." oninput="filtrarPoliticasEnTiempoReal()">
            </div>
        </div>
    </div>

    <!-- REJILLA DE TARJETAS DE POLÍTICAS DE ALTA DIRECCIÓN -->
    <?php if (empty($politicasLista)): ?>
        <div class="text-center py-5 rounded-4 border border-secondary border-opacity-25 p-5" style="background: rgba(9, 21, 40, 0.6);">
            <div class="brand-crest mx-auto mb-3" style="width: 64px; height: 64px; font-size: 2rem;">
                <i class="bi bi-shield-lock"></i>
            </div>
            <h4 class="fw-bold text-white mb-2">No hay políticas disponibles actualmente</h4>
            <p class="text-secondary small mb-0" style="max-width: 520px; margin: 0 auto;">
                Las directrices, códigos y normativas institucionales emitidas por la Dirección Central de Grupo Huerta se sincronizarán en este repositorio de manera automática.
            </p>
        </div>
    <?php else: ?>
        <div class="row g-4" id="contenedorTarjetasPoliticas">
            <?php foreach ($politicasLista as $pol): 
                $folioCode = 'GH-POL-' . str_pad($pol['id'], 3, '0', STR_PAD_LEFT);
                $catLower = strtolower($pol['categoria'] ?? '');
                
                // Icono contextual por categoría
                $catIcon = 'bi-file-earmark-text';
                if (strpos($catLower, 'sistemas') !== false || strpos($catLower, 'ti') !== false || strpos($catLower, 'equipo') !== false) {
                    $catIcon = 'bi-cpu-fill';
                } elseif (strpos($catLower, 'ética') !== false || strpos($catLower, 'conducta') !== false) {
                    $catIcon = 'bi-award-fill';
                } elseif (strpos($catLower, 'seguridad') !== false) {
                    $catIcon = 'bi-shield-check';
                } elseif (strpos($catLower, 'humano') !== false || strpos($catLower, 'personal') !== false) {
                    $catIcon = 'bi-people-fill';
                } elseif (strpos($catLower, 'finanzas') !== false || strpos($catLower, 'operacion') !== false) {
                    $catIcon = 'bi-briefcase-fill';
                }
            ?>
                <div class="col-md-6 col-lg-4 item-politica-card" data-cat="<?php echo htmlspecialchars($pol['categoria']); ?>" data-titulo="<?php echo htmlspecialchars(strtolower($pol['titulo'] . ' ' . $pol['descripcion'] . ' ' . $folioCode)); ?>">
                    <div class="executive-policy-card h-100">
                        <div>
                            <!-- ENCABEZADO DE FOLIO Y OBSERVANCIA -->
                            <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                                <span class="policy-folio-badge">
                                    <i class="bi bi-bookmark-fill me-1"></i><?php echo $folioCode; ?>
                                </span>

                                <?php if (!empty($pol['obligatorio_lectura'])): ?>
                                    <span class="badge-exec-mandatory">
                                        <i class="bi bi-shield-fill-exclamation"></i> Obligatoria
                                    </span>
                                <?php else: ?>
                                    <span class="badge-exec-standard">
                                        <i class="bi bi-check2-circle"></i> Institucional
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- DOMINIO Y VERSIÓN -->
                            <div class="policy-domain-pill">
                                <i class="bi <?php echo $catIcon; ?> text-primary"></i>
                                <span><?php echo htmlspecialchars($pol['categoria']); ?></span>
                                <span class="text-secondary opacity-50">&bull;</span>
                                <span class="text-white-50 font-monospace">v<?php echo htmlspecialchars($pol['version'] ?? '1.0'); ?></span>
                            </div>

                            <!-- TÍTULO DE LA POLÍTICA -->
                            <h4 class="policy-title-exec">
                                <?php echo htmlspecialchars($pol['titulo']); ?>
                            </h4>
                            
                            <!-- DESCRIPCIÓN EJECUTIVA -->
                            <p class="policy-desc-exec">
                                <?php echo htmlspecialchars($pol['descripcion'] ?: 'Directriz institucional de observancia y cumplimiento para las operaciones de la agencia.'); ?>
                            </p>
                        </div>

                        <div>
                            <!-- CAJA DE METADATOS DE GOBIERNO -->
                            <div class="policy-metadata-box">
                                <div>
                                    <span class="text-secondary opacity-75 d-block" style="font-size: 0.68rem;">EMISIÓN Y VIGENCIA</span>
                                    <span class="text-white fw-semibold">
                                        <i class="bi bi-calendar3 me-1 text-primary"></i><?php echo !empty($pol['fecha_vigencia']) ? date('d/m/Y', strtotime($pol['fecha_vigencia'])) : 'Permanente Oficial'; ?>
                                    </span>
                                </div>
                                <div class="text-end">
                                    <span class="text-secondary opacity-75 d-block" style="font-size: 0.68rem;">CUSTODIA</span>
                                    <span class="text-info fw-semibold font-monospace" style="font-size: 0.72rem;">
                                        <i class="bi bi-shield-lock-fill me-1"></i>DLP Cripto
                                    </span>
                                </div>
                            </div>

                            <!-- BOTÓN DE APERTURA EJECUTIVO -->
                            <div>
                                <button type="button" class="btn-access-exec" onclick="abrirVisorBlindado(<?php echo $pol['id']; ?>, '<?php echo addslashes(htmlspecialchars($pol['titulo'])); ?>', '<?php echo $folioCode; ?>')">
                                    <i class="bi bi-eye-fill"></i> Consultar Documento Oficial
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- PIE DE PÁGINA INSTITUCIONAL DE GOBIERNO CORPORATIVO -->
    <footer class="executive-footer text-center">
        <div class="d-flex align-items-center justify-content-center gap-2 mb-2 text-white">
            <i class="bi bi-shield-shaded" style="color: var(--gold-accent-light);"></i>
            <span class="fw-bold tracking-wider">GRUPO HUERTA &bull; GOBIERNO CORPORATIVO Y CUMPLIMIENTO REGULATORIO</span>
        </div>
        <p class="mb-0 text-secondary" style="max-width: 820px; margin: 0 auto; font-size: 0.76rem;">
            Aviso de Confidencialidad y Trazabilidad: Las normativas y directrices contenidas en este portal son instrumentos confidenciales propiedad de Grupo Huerta. Toda apertura, tiempo de lectura y terminal de acceso quedan registradas en la bitácora criptográfica de auditoría interna para fines de control institucional y gobernanza.
        </p>
    </footer>

</div>

<!-- VISOR BLINDADO FULLSCREEN (MODAL CONTAINER) -->
<div id="visorModalOverlay">
    <!-- BARRA SUPERIOR DEL VISOR -->
    <div class="visor-header">
        <div class="d-flex align-items-center gap-2.5">
            <div class="brand-crest" style="width: 32px; height: 32px; font-size: 1rem;">
                <i class="bi bi-shield-shaded"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-danger bg-opacity-25 text-danger border border-danger px-2 py-0.5 rounded-pill small font-monospace" style="font-size: 0.65rem;">
                        <i class="bi bi-shield-fill-x me-1"></i>LECTURA PROTEGIDA &bull; ALTA DIRECCIÓN
                    </span>
                    <span class="text-secondary small font-monospace d-none d-md-inline" id="visorFolioDoc" style="font-size: 0.75rem;">
                        GH-POL-000
                    </span>
                </div>
                <h6 class="fw-bold text-white mb-0 text-truncate" id="visorTituloDoc" style="max-width: 48vw;">
                    Cargando Documento Oficial...
                </h6>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- CONTROLES DE ZOOM -->
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-dark text-white border-secondary" onclick="cambiarZoomVisor(-0.15)" title="Alejar Zoom">
                    <i class="bi bi-dash-lg"></i>
                </button>
                <span id="visorZoomBadge" class="badge bg-dark border-top border-bottom border-secondary text-info d-flex align-items-center px-2.5 font-monospace">
                    100%
                </span>
                <button type="button" class="btn btn-dark text-white border-secondary" onclick="cambiarZoomVisor(0.15)" title="Acercar Zoom">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>

            <!-- CONTROLES DE PÁGINA -->
            <div class="d-flex align-items-center gap-1.5 text-secondary small font-monospace px-2">
                <span>Página <b id="lblPaginaActual" class="text-white">1</b> de <b id="lblTotalPaginas" class="text-white">1</b></span>
            </div>

            <!-- BADGE DE SEGURIDAD ÓPTICA -->
            <span class="badge bg-dark border border-secondary text-info rounded-pill px-2.5 py-1.5 small font-monospace d-none d-lg-inline-flex align-items-center">
                <i class="bi bi-shield-lock-fill text-warning me-1"></i> Trama Óptica Anti-Cámara
            </span>

            <!-- BADGE DE BLOQUEO POR CURSOR -->
            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger rounded-pill px-2.5 py-1.5 small font-monospace d-none d-md-inline-flex align-items-center">
                <i class="bi bi-cursor-fill text-danger me-1"></i> Sensor de Cursor Activo
            </span>
            
            <!-- BOTÓN CERRAR VISOR -->
            <button type="button" class="btn btn-outline-danger btn-sm rounded-3 fw-bold px-3 ms-2 d-flex align-items-center gap-1.5" onclick="cerrarVisorBlindado()">
                <i class="bi bi-x-lg"></i> <span>Cerrar Lectura</span>
            </button>
        </div>
    </div>

    <!-- OVERLAY DE BLOQUEO POR CURSOR FUERA DEL PDF (VIRTUAL DATA ROOM SECURITY) -->
    <div id="pdfLockOverlay">
        <div class="pdf-lock-box-exec">
            <div class="pdf-lock-icon-wrap">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <div class="badge-executive-pill mb-2">
                <i class="bi bi-award-fill"></i> Protocolo de Privacidad y Custodia Documental
            </div>
            <h3 class="fw-bold text-white mb-2" style="letter-spacing: -0.3px;">LECTURA PAUSADA POR SEGURIDAD</h3>
            <p class="text-secondary mb-4 small" style="line-height: 1.6;">
                El cursor se encuentra fuera del área del documento. Por normativas de Confidencialidad de Alta Dirección y Gobierno Corporativo, la visualización se protege automáticamente contra capturas no autorizadas.
            </p>
            <div class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-40 px-3.5 py-2.5 rounded-pill font-monospace small d-inline-flex align-items-center gap-2 shadow-lg mb-3">
                <span class="spinner-grow spinner-grow-sm text-danger" role="status"></span>
                Sitúe el cursor sobre el documento PDF para reanudar la lectura
            </div>
            <div class="text-secondary small opacity-75 font-monospace" style="font-size: 0.72rem;">
                Usuario: <?php echo htmlspecialchars($nombreUsuario); ?> &bull; IP: <?php echo htmlspecialchars($ipUsuario); ?> &bull; <?php echo htmlspecialchars($agenciaNombre); ?>
            </div>
        </div>
    </div>

    <!-- ÁREA DE RENDERIZADO DE PÁGINAS PDF CON MARCA DE AGUA Y MALLA MOIRÉ -->
    <div id="visorCanvasContainer">
        <div id="visorLoader" class="text-center py-5">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 3.2rem; height: 3.2rem;"></div>
            <h5 class="fw-bold text-white">Desencriptando y renderizando documento protegido...</h5>
            <p class="text-secondary small">Aplicando marcas de agua forenses de alta dirección y trama óptica anti-captura.</p>
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
    let isPdfRenderCompleted = false;
    let isCursorOverDocument = false;
    let ultimoMouseX = null;
    let ultimoMouseY = null;

    // =========================================================================
    // SEGURIDAD DLP: BLOQUEO AUTOMÁTICO CUANDO EL CURSOR SALE DEL PDF
    // =========================================================================
    function actualizarEstadoBloqueoPdf(bloquear) {
        if (!isViewerActive || !isPdfRenderCompleted) return;

        const overlay = document.getElementById('pdfLockOverlay');
        const container = document.getElementById('visorCanvasContainer');
        if (!overlay || !container) return;

        if (bloquear) {
            overlay.classList.add('activo');
            container.classList.add('visor-bloqueado');
        } else {
            overlay.classList.remove('activo');
            container.classList.remove('visor-bloqueado');
        }
    }

    function verificarCursorSobrePdf(clientX, clientY) {
        ultimoMouseX = clientX;
        ultimoMouseY = clientY;

        if (!isViewerActive || !isPdfRenderCompleted) return;

        const wrappers = document.querySelectorAll('.pdf-page-wrapper');
        if (!wrappers || wrappers.length === 0) return;

        // 1. Detección directa del elemento bajo el cursor
        const elUnderCursor = document.elementFromPoint(clientX, clientY);
        if (elUnderCursor && (elUnderCursor.classList.contains('pdf-page-wrapper') || elUnderCursor.closest('.pdf-page-wrapper'))) {
            isCursorOverDocument = true;
            actualizarEstadoBloqueoPdf(false);
            return;
        }

        // 2. Detección de la columna vertical del documento PDF (tolerancia de lectura continua)
        let dentroDeColumna = false;
        for (let i = 0; i < wrappers.length; i++) {
            const rect = wrappers[i].getBoundingClientRect();
            // Comprobar si el cursor está dentro del ancho de la página con tolerancia de 20px
            if (clientX >= (rect.left - 20) && clientX <= (rect.right + 20)) {
                if (clientY >= (rect.top - 25) && clientY <= (rect.bottom + 25)) {
                    dentroDeColumna = true;
                    break;
                }
            }
        }

        if (dentroDeColumna) {
            isCursorOverDocument = true;
            actualizarEstadoBloqueoPdf(false);
        } else {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    }

    // Escuchadores de eventos para rastrear el cursor y pérdida de foco
    window.addEventListener('mousemove', function(e) {
        if (!isViewerActive) return;
        verificarCursorSobrePdf(e.clientX, e.clientY);
    }, { passive: true });

    document.addEventListener('mouseleave', function() {
        if (isViewerActive && isPdfRenderCompleted) {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    });

    window.addEventListener('blur', function() {
        if (isViewerActive && isPdfRenderCompleted) {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    });

    document.addEventListener('visibilitychange', function() {
        if (document.hidden && isViewerActive && isPdfRenderCompleted) {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    });

    window.addEventListener('touchmove', function(e) {
        if (!isViewerActive) return;
        if (e.touches && e.touches.length > 0) {
            verificarCursorSobrePdf(e.touches[0].clientX, e.touches[0].clientY);
        }
    }, { passive: true });

    window.addEventListener('touchstart', function(e) {
        if (!isViewerActive) return;
        if (e.touches && e.touches.length > 0) {
            verificarCursorSobrePdf(e.touches[0].clientX, e.touches[0].clientY);
        }
    }, { passive: true });

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
    function abrirVisorBlindado(docId, tituloDoc, folioDoc) {
        const modal = document.getElementById('visorModalOverlay');
        const container = document.getElementById('visorCanvasContainer');
        const tituloLbl = document.getElementById('visorTituloDoc');
        const folioLbl = document.getElementById('visorFolioDoc');

        if (!modal || !container) return;

        tituloLbl.textContent = tituloDoc;
        if (folioLbl && folioDoc) {
            folioLbl.textContent = folioDoc;
        }
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        isViewerActive = true;
        isPdfRenderCompleted = false;
        isCursorOverDocument = false;
        const lockOv = document.getElementById('pdfLockOverlay');
        if (lockOv) lockOv.classList.remove('activo');
        container.classList.remove('visor-bloqueado');

        // Registrar lectura en bitácora auditable
        registrarLecturaAuditoria(docId, tituloDoc);

        // Limpiar visor y mostrar loader
        container.innerHTML = `
            <div id="visorLoader" class="text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status" style="width: 3.2rem; height: 3.2rem;"></div>
                <h5 class="fw-bold text-white">Desencriptando y renderizando documento protegido...</h5>
                <p class="text-secondary small">Aplicando marcas de agua forenses de alta dirección y trama óptica anti-captura.</p>
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
        let paginasRenderizadas = 0;

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

                        // 2. Marca de Agua Forense Auditable de Alta Dirección
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

                        paginasRenderizadas++;
                        if (paginasRenderizadas === pdf.numPages) {
                            isPdfRenderCompleted = true;
                            setTimeout(function() {
                                if (ultimoMouseX !== null && ultimoMouseY !== null) {
                                    verificarCursorSobrePdf(ultimoMouseX, ultimoMouseY);
                                } else {
                                    actualizarEstadoBloqueoPdf(true);
                                }
                            }, 120);
                        }
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
        isPdfRenderCompleted = false;
        const lockOv = document.getElementById('pdfLockOverlay');
        if (lockOv) lockOv.classList.remove('activo');
        container.classList.remove('visor-bloqueado');
        renderizarTodasLasPaginas(currentPdfDoc, container);
    }

    function cerrarVisorBlindado() {
        const modal = document.getElementById('visorModalOverlay');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
        isViewerActive = false;
        isPdfRenderCompleted = false;
        isCursorOverDocument = false;
        const lockOv = document.getElementById('pdfLockOverlay');
        if (lockOv) lockOv.classList.remove('activo');
        const container = document.getElementById('visorCanvasContainer');
        if (container) container.classList.remove('visor-bloqueado');
        currentPdfDoc = null;
    }

    // Re-verificar posición en caso de desplazamiento con rueda del ratón
    const containerCanvasEl = document.getElementById('visorCanvasContainer');
    if (containerCanvasEl) {
        containerCanvasEl.addEventListener('scroll', function() {
            if (isViewerActive && isPdfRenderCompleted && ultimoMouseX !== null && ultimoMouseY !== null) {
                verificarCursorSobrePdf(ultimoMouseX, ultimoMouseY);
            }
        }, { passive: true });
    }

    // 5. FILTROS DE POLÍTICAS EN TIEMPO REAL
    function filtrarPorCategoria(categoria, btn) {
        document.querySelectorAll('.btn-cat-filtro').forEach(b => {
            b.classList.remove('active');
        });
        btn.classList.add('active');

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
