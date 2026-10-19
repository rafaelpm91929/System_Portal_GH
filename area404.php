<?php
session_start();

// Control de Sesión Obligatorio - Identidad Blindada de Portal
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'conexion.php';
require_once 'permisos_helper.php';

// Refrescar permisos si hay PDO activo
if ($pdo && isset($_SESSION['usuario_id'])) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
}

// Datos de Identidad Oficial (No editables por el usuario)
$usuarioId      = $_SESSION['usuario_id'];
$nombreUsuario  = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Piloto GH');
$agenciaUsuario = $_SESSION['agencia'] ?? 'Oficina Central Grupo Huerta';
$rolUsuario     = $_SESSION['usuario_rol'] ?? ($_SESSION['rol'] ?? 'Piloto');
$emailUsuario   = $_SESSION['usuario_email'] ?? ($_SESSION['email'] ?? '');

// Auto-crear tabla de récords y avatares de Área 404 si no existen
if ($pdo) {
    try {
        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS area404_records (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    usuario_id INTEGER,
                    nombre_piloto VARCHAR(100),
                    agencia VARCHAR(100),
                    juego VARCHAR(30),
                    mundo VARCHAR(50),
                    vehiculo VARCHAR(50),
                    puntaje INTEGER DEFAULT 0,
                    tiempo_ms INTEGER DEFAULT 0,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS area404_avatares (
                    usuario_id INTEGER PRIMARY KEY,
                    config_json TEXT,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `area404_records` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `usuario_id` INT NOT NULL,
                    `nombre_piloto` VARCHAR(100) NOT NULL,
                    `agencia` VARCHAR(100) NOT NULL,
                    `juego` VARCHAR(30) NOT NULL,
                    `mundo` VARCHAR(50) NOT NULL,
                    `vehiculo` VARCHAR(50) DEFAULT 'auto',
                    `puntaje` INT DEFAULT 0,
                    `tiempo_ms` INT DEFAULT 0,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `area404_avatares` (
                    `usuario_id` INT PRIMARY KEY,
                    `config_json` TEXT NOT NULL,
                    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    } catch (Throwable $e) {}
}

// Endpoint AJAX: Guardar Récord
if (isset($_GET['ajax_guardar_record']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $juego = trim($input['juego'] ?? 'carreras_3d');
    $mundo = trim($input['mundo'] ?? 'taller');
    $vehiculo = trim($input['vehiculo'] ?? 'auto');
    $puntaje = intval($input['puntaje'] ?? 0);
    $tiempoMs = intval($input['tiempo_ms'] ?? 0);

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO area404_records (usuario_id, nombre_piloto, agencia, juego, mundo, vehiculo, puntaje, tiempo_ms)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$usuarioId, $nombreUsuario, $agenciaUsuario, $juego, $mundo, $vehiculo, $puntaje, $tiempoMs]);
            echo json_encode(['status' => 'ok', 'mensaje' => 'Récord guardado exitosamente.']);
            exit();
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'mensaje' => $e->getMessage()]);
            exit();
        }
    }
    echo json_encode(['status' => 'ok', 'mensaje' => 'Guardado localmente.']);
    exit();
}

// Endpoint AJAX: Obtener Leaderboard
if (isset($_GET['ajax_leaderboard'])) {
    header('Content-Type: application/json; charset=utf-8');
    $juego = trim($_GET['juego'] ?? 'carreras_3d');
    $records = [];
    if ($pdo) {
        try {
            if ($juego === 'carreras_3d') {
                $stmt = $pdo->prepare("
                    SELECT nombre_piloto, agencia, vehiculo, mundo, MIN(tiempo_ms) as mejor_tiempo, creado_en 
                    FROM area404_records 
                    WHERE juego = 'carreras_3d' AND tiempo_ms > 0
                    GROUP BY usuario_id, mundo
                    ORDER BY mejor_tiempo ASC 
                    LIMIT 20
                ");
                $stmt->execute();
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("
                    SELECT nombre_piloto, agencia, mundo, MAX(puntaje) as max_puntaje, creado_en 
                    FROM area404_records 
                    WHERE juego = 'super_agente_2d'
                    GROUP BY usuario_id, mundo
                    ORDER BY max_puntaje DESC 
                    LIMIT 20
                ");
                $stmt->execute();
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {}
    }
    echo json_encode(['status' => 'ok', 'records' => $records]);
    exit();
}

// Endpoint AJAX: Guardar Avatar en BD
if (isset($_GET['ajax_guardar_avatar']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $rawJson = file_get_contents('php://input');
    if ($pdo && !empty($rawJson)) {
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO area404_avatares (usuario_id, config_json, actualizado_en) VALUES (?, ?, CURRENT_TIMESTAMP)");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO area404_avatares (usuario_id, config_json) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), actualizado_en = CURRENT_TIMESTAMP
                ");
            }
            $stmt->execute([$usuarioId, $rawJson]);
            echo json_encode(['status' => 'ok']);
            exit();
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'mensaje' => $e->getMessage()]);
            exit();
        }
    }
    echo json_encode(['status' => 'ok']);
    exit();
}

// Cargar Avatar previo de BD si existe
$avatarConfigDb = null;
if ($pdo) {
    try {
        $stmtAv = $pdo->prepare("SELECT config_json FROM area404_avatares WHERE usuario_id = ?");
        $stmtAv->execute([$usuarioId]);
        $rowAv = $stmtAv->fetch(PDO::FETCH_ASSOC);
        if ($rowAv && !empty($rowAv['config_json'])) {
            $avatarConfigDb = $rowAv['config_json'];
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área 404 | Gaming & Recreación Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Librerías de Audio y 3D en CDN ultrarrápido -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --neon-blue: #00d2ff;
            --neon-purple: #9d4edd;
            --neon-pink: #ff007f;
            --neon-green: #39ff14;
            --neon-yellow: #ffe600;
            --dark-bg: #030a16;
            --panel-bg: rgba(6, 21, 43, 0.88);
            --border-glow: rgba(0, 210, 255, 0.35);
        }

        body {
            background-color: var(--dark-bg);
            background-image: 
                radial-gradient(circle at 15% 20%, rgba(157, 78, 221, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(0, 210, 255, 0.12) 0%, transparent 40%),
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 35px 35px, 35px 35px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Top Bar Retro Cyberpunk */
        .arcade-navbar {
            background: rgba(3, 10, 22, 0.95);
            backdrop-filter: blur(15px);
            border-bottom: 1px solid var(--border-glow);
            padding: 12px 28px;
            position: sticky;
            top: 0;
            z-index: 1050;
        }

        .arcade-logo {
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: 2px;
            background: linear-gradient(135deg, #00d2ff 0%, #9d4edd 50%, #ff007f 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 20px rgba(0, 210, 255, 0.5);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cyber-badge {
            font-size: 0.72rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Tarjeta de Identidad Oficial Blindada */
        .user-identity-chip {
            background: rgba(0, 210, 255, 0.08);
            border: 1px solid rgba(0, 210, 255, 0.3);
            border-radius: 14px;
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Navegación de Pestañas del Módulo */
        .arcade-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .arcade-tab-btn {
            background: rgba(6, 21, 43, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            padding: 12px 24px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .arcade-tab-btn:hover {
            color: #ffffff;
            border-color: var(--neon-blue);
            transform: translateY(-2px);
            background: rgba(0, 210, 255, 0.12);
        }
        .arcade-tab-btn.active {
            color: #ffffff;
            background: linear-gradient(135deg, rgba(0, 210, 255, 0.25) 0%, rgba(157, 78, 221, 0.3) 100%);
            border-color: var(--neon-blue);
            box-shadow: 0 0 20px rgba(0, 210, 255, 0.35);
        }

        /* Tarjetas de Selección de Juegos y Mundos */
        .arcade-card {
            background: var(--panel-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .arcade-card:hover {
            border-color: var(--neon-blue);
            transform: translateY(-6px);
            box-shadow: 0 16px 35px rgba(0, 210, 255, 0.2);
        }

        /* Selector de Mundos */
        .world-pill {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 14px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .world-pill:hover:not(.locked) {
            background: rgba(0, 210, 255, 0.15);
            border-color: var(--neon-blue);
        }
        .world-pill.active {
            background: linear-gradient(135deg, rgba(0, 210, 255, 0.25), rgba(157, 78, 221, 0.35));
            border-color: var(--neon-blue);
            box-shadow: 0 0 15px rgba(0, 210, 255, 0.3);
        }
        .world-pill.locked {
            opacity: 0.55;
            cursor: not-allowed;
            filter: grayscale(0.6);
        }

        /* Selector de Vehículo */
        .vehicle-btn {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .vehicle-btn:hover {
            border-color: var(--neon-blue);
            background: rgba(0, 210, 255, 0.1);
        }
        .vehicle-btn.active {
            background: rgba(0, 210, 255, 0.22);
            border-color: var(--neon-blue);
            box-shadow: 0 0 15px rgba(0, 210, 255, 0.4);
        }

        /* Canvas Containers */
        #contenedorJuego3D, #contenedorJuego2D {
            position: relative;
            width: 100%;
            height: 640px;
            border-radius: 20px;
            overflow: hidden;
            background: #020610;
            border: 2px solid var(--border-glow);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.8), inset 0 0 30px rgba(0, 210, 255, 0.1);
        }

        #contenedorJuego3D.fullscreen, #contenedorJuego2D.fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw !important;
            height: 100vh !important;
            border-radius: 0;
            z-index: 9999;
            border: none;
        }

        /* HUD Overlay (Velocímetro, Cronómetro, Vidas) */
        .game-hud {
            position: absolute;
            pointer-events: none;
            z-index: 100;
        }

        .hud-speedometer {
            bottom: 24px;
            right: 28px;
            background: rgba(3, 10, 24, 0.85);
            border: 2px solid var(--neon-blue);
            backdrop-filter: blur(8px);
            border-radius: 18px;
            padding: 14px 22px;
            text-align: center;
            box-shadow: 0 0 25px rgba(0, 210, 255, 0.3);
        }

        .hud-timer {
            top: 24px;
            left: 28px;
            background: rgba(3, 10, 24, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            border-radius: 14px;
            padding: 10px 18px;
            font-family: monospace;
        }

        .hud-controls-hint {
            bottom: 24px;
            left: 28px;
            background: rgba(3, 10, 24, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(6px);
            border-radius: 12px;
            padding: 8px 16px;
            font-size: 0.8rem;
        }

        /* Estilo Avatar Canvas Preview */
        #avatarPreviewCanvas {
            background: radial-gradient(circle, #0b254a 0%, #030b17 100%);
            border-radius: 18px;
            border: 2px solid rgba(0, 210, 255, 0.3);
        }

        /* Paleta de Colores de Avatar */
        .color-dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid #ffffff;
            transition: all 0.2s;
        }
        .color-dot:hover, .color-dot.active {
            transform: scale(1.25);
            box-shadow: 0 0 10px #ffffff;
        }

        /* Tabla de Récords */
        .table-arcade {
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.1);
        }
        .table-arcade th {
            background: rgba(0, 210, 255, 0.1);
            color: var(--neon-blue);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border: none;
        }
        .table-arcade td {
            background: transparent;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            vertical-align: middle;
        }
    </style>
</head>
<body>

<!-- Top Navigation -->
<div class="arcade-navbar d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-light btn-sm rounded-pill px-3 py-1 d-flex align-items-center gap-2">
            <i class="bi bi-arrow-left"></i> <span>Menú Portal</span>
        </a>
        <div class="arcade-logo">
            <i class="bi bi-controller"></i>
            <span>ÁREA 404</span>
        </div>
        <span class="cyber-badge" style="background: rgba(0, 210, 255, 0.15); color: #00d2ff; border-color: rgba(0, 210, 255, 0.4);">
            Gaming Hub GH
        </span>
    </div>

    <!-- Identidad del Usuario Oficial (Protegida) -->
    <div class="d-flex align-items-center gap-3">
        <div class="user-identity-chip" title="Nombre y sucursal verificados oficialmente en el Portal de Sistemas">
            <i class="bi bi-lock-fill text-warning" title="Identidad protegida"></i>
            <div class="text-start">
                <div class="small fw-bold text-white lh-1"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                <div class="text-secondary" style="font-size: 0.72rem;"><?php echo htmlspecialchars($agenciaUsuario); ?> &bull; <span class="text-info"><?php echo htmlspecialchars($rolUsuario); ?></span></div>
            </div>
            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(0, 210, 255, 0.2); color: #00d2ff;">
                <i class="bi bi-person-fill"></i>
            </div>
        </div>

        <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" onclick="toggleAudio()" id="btnAudioMute">
            <i class="bi bi-volume-up-fill" id="audioIcon"></i> Sonido
        </button>
    </div>
</div>

<div class="container-fluid px-4 py-4">

    <!-- Pestañas Principales del Módulo -->
    <div class="arcade-tabs">
        <button type="button" class="arcade-tab-btn active" onclick="cambiarSeccion('hub')" id="tab_hub">
            <i class="bi bi-grid-fill"></i> Sala Principal & Avatar
        </button>
        <button type="button" class="arcade-tab-btn" onclick="cambiarSeccion('carreras')" id="tab_carreras">
            <i class="bi bi-flag-fill text-danger"></i> Gran Premio 3D (Autos y Motos)
        </button>
        <button type="button" class="arcade-tab-btn" onclick="cambiarSeccion('plataformas')" id="tab_plataformas">
            <i class="bi bi-tools text-warning"></i> Super Taller Run 2D (Mario Bros)
        </button>
        <button type="button" class="arcade-tab-btn" onclick="cambiarSeccion('records')" id="tab_records">
            <i class="bi bi-trophy-fill text-warning"></i> Salón de la Fama
        </button>
    </div>

    <!-- ======================================================= -->
    <!-- 1. SALA PRINCIPAL (HUB & CREADOR DE AVATAR / VEHÍCULO)  -->
    <!-- ======================================================= -->
    <div id="seccion_hub" class="seccion-modulo">
        <div class="row g-4">
            
            <!-- Tarjetas de Acceso Rápido a los 2 Juegos -->
            <div class="col-lg-7">
                <h4 class="fw-bold mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-joystick text-info"></i> Selecciona tu Modo de Juego
                </h4>
                
                <div class="row g-3 mb-4">
                    <!-- Tarjeta Juego 1: Carreras 3D -->
                    <div class="col-md-6">
                        <div class="arcade-card h-100 d-flex flex-column justify-content-between" style="border-left: 4px solid var(--neon-blue);">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="cyber-badge" style="background: rgba(0,210,255,0.2); color:#00d2ff;">3D WebGL</span>
                                    <i class="bi bi-speedometer2 fs-2 text-info"></i>
                                </div>
                                <h5 class="fw-bold text-white mb-2">Gran Premio 3D</h5>
                                <p class="text-secondary small mb-3">
                                    Pistas con curvas reales, derrapes, velocímetro y cronómetro de vueltas. Conduce autos deportivos de Grupo Huerta o motos de pista <strong>Suzuki GSX-R</strong> y <strong>Kawasaki Ninja</strong>.
                                </p>
                            </div>
                            <button type="button" class="btn btn-info w-100 rounded-pill fw-bold" onclick="cambiarSeccion('carreras')">
                                <i class="bi bi-play-fill fs-5"></i> Jugar Carreras 3D
                            </button>
                        </div>
                    </div>

                    <!-- Tarjeta Juego 2: Plataformas 2D -->
                    <div class="col-md-6">
                        <div class="arcade-card h-100 d-flex flex-column justify-content-between" style="border-left: 4px solid var(--neon-yellow);">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="cyber-badge" style="background: rgba(255,230,0,0.2); color:#ffe600;">2D Retro</span>
                                    <i class="bi bi-wrench-adjustable-circle fs-2 text-warning"></i>
                                </div>
                                <h5 class="fw-bold text-white mb-2">Super Taller Run</h5>
                                <p class="text-secondary small mb-3">
                                    Aventura de plataformas estilo Mario Bros ambientada en taller de autos y motos. Salta sobre charcos de aceite, recolecta llaves y herramientas para llegar al Showroom.
                                </p>
                            </div>
                            <button type="button" class="btn btn-warning w-100 rounded-pill fw-bold text-dark" onclick="cambiarSeccion('plataformas')">
                                <i class="bi bi-play-fill fs-5"></i> Jugar Plataformas 2D
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Selección y Desbloqueo de Mundos -->
                <h4 class="fw-bold mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-globe-americas text-primary"></i> Circuitos y Mundos Disponibles
                </h4>

                <div class="row g-3">
                    <!-- Mundo 1: Agencia y Taller -->
                    <div class="col-md-4">
                        <div class="world-pill active" id="pill_mundo_taller" onclick="seleccionarMundo('taller')">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-buildings-fill text-info fs-5"></i>
                                <span class="fw-bold small text-white">Agencia & Taller</span>
                            </div>
                            <div class="small text-secondary" style="font-size: 0.75rem;">
                                Circuito central de autos y motos. Elevadores y boxes.
                            </div>
                            <span class="badge bg-success bg-opacity-25 text-success rounded-pill mt-2" style="font-size: 0.65rem;">
                                <i class="bi bi-check-circle-fill"></i> Desbloqueado
                            </span>
                        </div>
                    </div>

                    <!-- Mundo 2: Corporativo Grupo Huerta -->
                    <div class="col-md-4">
                        <div class="world-pill" id="pill_mundo_corporativo" onclick="seleccionarMundo('corporativo')">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-building-fill-lock text-warning fs-5"></i>
                                <span class="fw-bold small text-white">Corporativo</span>
                            </div>
                            <div class="small text-secondary" style="font-size: 0.75rem;">
                                Rascacielos nocturnos, oficinas ejecutivas y túnel de cristal.
                            </div>
                            <span class="badge bg-warning bg-opacity-25 text-warning rounded-pill mt-2" id="badge_mundo_corporativo" style="font-size: 0.65rem;">
                                <i class="bi bi-lock-fill"></i> 1,000 pts o 3 Vueltas
                            </span>
                        </div>
                    </div>

                    <!-- Mundo 3: El Rancho -->
                    <div class="col-md-4">
                        <div class="world-pill" id="pill_mundo_rancho" onclick="seleccionarMundo('rancho')">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-tree-fill text-success fs-5"></i>
                                <span class="fw-bold small text-white">El Rancho</span>
                            </div>
                            <div class="small text-secondary" style="font-size: 0.75rem;">
                                Pista campestre de terracería, pacas de heno y establos.
                            </div>
                            <span class="badge bg-secondary bg-opacity-25 text-light rounded-pill mt-2" id="badge_mundo_rancho" style="font-size: 0.65rem;">
                                <i class="bi bi-lock-fill"></i> 2,500 pts o Trofeo
                            </span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Columna Derecha: Taller de Personalización de Avatar & Vehículo -->
            <div class="col-lg-5">
                <div class="arcade-card">
                    <h5 class="fw-bold mb-3 d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-palette-fill text-info me-2"></i>Personaliza tu Avatar y Vehículo</span>
                        <span class="badge bg-primary bg-opacity-20 text-info rounded-pill small">Auto-Guardado</span>
                    </h5>

                    <!-- Preview Visual del Avatar y Vehículo -->
                    <div class="text-center mb-3">
                        <canvas id="avatarPreviewCanvas" width="340" height="180" class="shadow-lg"></canvas>
                        <div class="small text-secondary mt-1">
                            Piloto: <strong class="text-white"><?php echo htmlspecialchars($nombreUsuario); ?></strong> (<span class="text-info"><?php echo htmlspecialchars($agenciaUsuario); ?></span>)
                        </div>
                    </div>

                    <!-- 1. Tipo de Vehículo Grupo Huerta -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Vehículo de Carreras (Agencias Grupo Huerta)</label>
                        <div class="row g-2">
                            <!-- VW Jetta -->
                            <div class="col-4">
                                <div class="vehicle-btn active" id="veh_auto_jetta" onclick="setVehiculo('auto_jetta')">
                                    <i class="bi bi-car-front-fill fs-4 text-info d-block mb-1"></i>
                                    <div class="fw-bold small">VW Jetta</div>
                                    <div class="text-secondary" style="font-size: 0.65rem;">Sportline</div>
                                </div>
                            </div>
                            <!-- Omoda C5 -->
                            <div class="col-4">
                                <div class="vehicle-btn" id="veh_auto_omoda" onclick="setVehiculo('auto_omoda')">
                                    <i class="bi bi-car-front-fill fs-4 text-warning d-block mb-1"></i>
                                    <div class="fw-bold small">Omoda C5</div>
                                    <div class="text-secondary" style="font-size: 0.65rem;">Crossover</div>
                                </div>
                            </div>
                            <!-- Cupra Formentor -->
                            <div class="col-4">
                                <div class="vehicle-btn" id="veh_auto_cupra" onclick="setVehiculo('auto_cupra')">
                                    <i class="bi bi-lightning-charge-fill fs-4 d-block mb-1" style="color: #ea580c;"></i>
                                    <div class="fw-bold small" style="color: #ea580c;">Cupra</div>
                                    <div class="text-secondary" style="font-size: 0.65rem;">Formentor</div>
                                </div>
                            </div>
                            <!-- Suzuki GSX-R -->
                            <div class="col-6">
                                <div class="vehicle-btn" id="veh_moto_suzuki" onclick="setVehiculo('moto_suzuki')">
                                    <i class="bi bi-bicycle fs-4 text-primary d-block mb-1"></i>
                                    <div class="fw-bold small" style="color: #38bdf8;">Suzuki GSX-R</div>
                                    <div class="text-secondary" style="font-size: 0.65rem;">Superbike Ecstar</div>
                                </div>
                            </div>
                            <!-- Kawasaki Ninja -->
                            <div class="col-6">
                                <div class="vehicle-btn" id="veh_moto_kawasaki" onclick="setVehiculo('moto_kawasaki')">
                                    <i class="bi bi-bicycle fs-4 text-success d-block mb-1"></i>
                                    <div class="fw-bold small" style="color: #4ade80;">Kawasaki Ninja</div>
                                    <div class="text-secondary" style="font-size: 0.65rem;">ZX-10R Verde KRT</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Casco / Equipo del Piloto -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Casco y Estilo de Cabeza</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <input type="radio" class="btn-check" name="optCasco" id="casco1" value="carreras" checked onchange="setCasco('carreras')">
                            <label class="btn btn-outline-info" for="casco1">🪖 Casco Racing</label>

                            <input type="radio" class="btn-check" name="optCasco" id="casco2" value="gorra" onchange="setCasco('gorra')">
                            <label class="btn btn-outline-info" for="casco2">🧢 Gorra Taller</label>

                            <input type="radio" class="btn-check" name="optCasco" id="casco3" value="gamer" onchange="setCasco('gamer')">
                            <label class="btn btn-outline-info" for="casco3">🎧 Gamer Headset</label>
                        </div>
                    </div>

                    <!-- 3. Color del Traje / Overol -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Color del Traje / Overol de Escudería</label>
                        <div class="d-flex align-items-center gap-2">
                            <div class="color-dot active" style="background: #00d2ff;" onclick="setColorTraje('#00d2ff', this)" title="Azul Cyan GH"></div>
                            <div class="color-dot" style="background: #2563eb;" onclick="setColorTraje('#2563eb', this)" title="Azul Suzuki"></div>
                            <div class="color-dot" style="background: #22c55e;" onclick="setColorTraje('#22c55e', this)" title="Verde Kawasaki"></div>
                            <div class="color-dot" style="background: #ef4444;" onclick="setColorTraje('#ef4444', this)" title="Rojo Racing"></div>
                            <div class="color-dot" style="background: #eab308;" onclick="setColorTraje('#eab308', this)" title="Dorado Corporativo"></div>
                            <div class="color-dot" style="background: #a855f7;" onclick="setColorTraje('#a855f7', this)" title="Nitro Púrpura"></div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-outline-info btn-sm w-100 rounded-pill" onclick="guardarAvatarServidor()">
                        <i class="bi bi-cloud-arrow-up-fill me-1"></i> Guardar Avatar en mi Perfil
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- ======================================================= -->
    <!-- 2. JUEGO 1: GRAN PREMIO 3D (CARRERAS THREE.JS)          -->
    <!-- ======================================================= -->
    <div id="seccion_carreras" class="seccion-modulo d-none">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <h4 class="fw-bold mb-0 text-white d-flex align-items-center gap-2">
                    <i class="bi bi-speedometer2 text-info"></i> Gran Premio GH 3D
                </h4>
                <span class="badge bg-info bg-opacity-20 text-info border border-info rounded-pill" id="lblMundoActivo3D">
                    Circuito: Agencia & Taller
                </span>
                <span class="badge bg-primary bg-opacity-20 text-primary border border-primary rounded-pill" id="lblVehiculoActivo3D">
                    Vehículo: Auto GT
                </span>
            </div>

            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-outline-warning btn-sm rounded-pill" onclick="reiniciarCarrera3D()">
                    <i class="bi bi-arrow-counterclockwise"></i> Reiniciar
                </button>
                <button type="button" class="btn btn-outline-light btn-sm rounded-pill" onclick="togglePantallaCompleta('contenedorJuego3D')">
                    <i class="bi bi-arrows-fullscreen"></i> Pantalla Completa
                </button>
            </div>
        </div>

        <!-- Contenedor del Lienzo Three.js -->
        <div id="contenedorJuego3D">
            <!-- HUD: Banner Central Animado de Vueltas y Victoria -->
            <div id="hud_banner_overlay" class="game-hud text-center" style="top: 38%; left: 50%; transform: translate(-50%, -50%) scale(0); transition: transform 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.3s; opacity: 0; pointer-events: none; z-index: 200;">
                <h1 id="hud_banner_text" style="font-size: 3.2rem; font-weight: 900; font-style: italic; letter-spacing: 2px; text-shadow: 0 0 30px rgba(0,0,0,0.9), 0 0 25px currentColor; margin: 0; text-transform: uppercase;">¡VUELTA 2!</h1>
            </div>

            <!-- HUD: Cronómetro, Posición y Vueltas -->
            <div class="game-hud hud-timer">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <div class="text-secondary" style="font-size: 0.65rem; font-weight: 700;">POSICIÓN</div>
                        <div class="fs-5 fw-bold" style="color: #ffe600;" id="hud_race_position"><i class="bi bi-trophy-fill me-1"></i>1° / 4</div>
                    </div>
                    <div class="border-start border-secondary ps-3">
                        <div class="text-secondary" style="font-size: 0.65rem; font-weight: 700;">TIEMPO DE VUELTA</div>
                        <div class="fs-5 fw-bold text-warning" id="hud_lap_time">00:00.00</div>
                    </div>
                    <div class="border-start border-secondary ps-3">
                        <div class="text-secondary" style="font-size: 0.65rem; font-weight: 700;">MEJOR VUELTA</div>
                        <div class="fs-5 fw-bold text-success" id="hud_best_lap">--:--.--</div>
                    </div>
                    <div class="border-start border-secondary ps-3">
                        <div class="text-secondary" style="font-size: 0.65rem; font-weight: 700;">VUELTA</div>
                        <div class="fs-5 fw-bold text-info" id="hud_lap_count">1 / 3</div>
                    </div>
                </div>
            </div>

            <!-- HUD: Item Box Estilo Mario Kart (Centro Superior) -->
            <div class="game-hud" style="top: 20px; left: 50%; transform: translateX(-50%);">
                <div id="hud_item_box" class="d-flex align-items-center justify-content-center" style="width: 66px; height: 66px; background: rgba(3, 10, 24, 0.9); border: 3px solid #ffe600; border-radius: 18px; box-shadow: 0 0 25px rgba(255, 230, 0, 0.45); font-size: 2.1rem; cursor: pointer;" onclick="usarItem3D()" title="Presiona [E] o haz clic para usar">
                    <span id="hud_item_icon" style="filter: drop-shadow(0 0 8px rgba(255,255,255,0.8));">❓</span>
                </div>
                <div class="text-center mt-1" style="font-size: 0.65rem; color: #ffe600; font-weight: 800; letter-spacing: 1.5px; text-shadow: 0 0 8px #000;">
                    [E] USAR PODER
                </div>
            </div>

            <!-- HUD: Radar / Mini-Mapa Circular del Circuito (Esquina Superior Derecha) -->
            <canvas id="radar3DCanvas" width="120" height="120" class="game-hud" style="top: 20px; right: 28px; background: rgba(3, 10, 24, 0.88); border: 2px solid var(--neon-blue); border-radius: 50%; box-shadow: 0 0 20px rgba(0, 210, 255, 0.4);"></canvas>

            <!-- HUD: Indicador de Chispas / Mini-Turbo al Derrapar -->
            <div id="hud_drift_indicator" class="game-hud" style="bottom: 110px; right: 30px; font-weight: 900; font-size: 1.15rem; letter-spacing: 1.5px; text-shadow: 0 0 12px currentColor; display: none;">
                ⚡ MINI-TURBO!
            </div>

            <!-- HUD: Velocímetro Digital, Marchas y Tacómetro LED -->
            <div class="game-hud hud-speedometer">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-secondary small fw-bold" style="font-size: 0.65rem; letter-spacing: 1.5px;">VELOCIDAD</span>
                    <span class="badge bg-primary bg-opacity-25 text-info fw-bold" id="hud_gear">D</span>
                </div>
                <div class="display-6 fw-bold text-white font-monospace lh-1" id="hud_speed">0</div>
                <div class="text-info small fw-bold mt-1" style="font-size: 0.72rem;">KM / H</div>

                <!-- Tacómetro LED de 8 segmentos -->
                <div class="d-flex gap-1 mt-2 justify-content-center" id="hud_rpm_leds">
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                    <span class="rpm-led" style="width: 7px; height: 10px; border-radius: 2px; background: #1e293b; display: inline-block;"></span>
                </div>
            </div>

            <!-- HUD: Teclas de Control -->
            <div class="game-hud hud-controls-hint text-secondary">
                <i class="bi bi-controller text-info me-1"></i>
                <kbd class="bg-dark text-white">W / ↑</kbd> Acelerar &bull; 
                <kbd class="bg-dark text-white">S / ↓</kbd> Freno / Reversa &bull; 
                <kbd class="bg-dark text-white">A / D</kbd> Dirección &bull; 
                <kbd class="bg-dark text-white">ESPACIO</kbd> Derrape &bull; 
                <kbd class="bg-dark text-white">E</kbd> Poder
            </div>
        </div>
    </div>

    <!-- ======================================================= -->
    <!-- 3. JUEGO 2: SUPER TALLER RUN 2D (MARIO BROS AUTOMOTRIZ) -->
    <!-- ======================================================= -->
    <div id="seccion_plataformas" class="seccion-modulo d-none">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <h4 class="fw-bold mb-0 text-white d-flex align-items-center gap-2">
                    <i class="bi bi-tools text-warning"></i> Super Taller Run 2D
                </h4>
                <span class="badge bg-warning bg-opacity-20 text-warning border border-warning rounded-pill" id="lblMundoActivo2D">
                    Nivel: Taller de Autos y Motos
                </span>
            </div>

            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-outline-warning btn-sm rounded-pill" onclick="reiniciarJuego2D()">
                    <i class="bi bi-arrow-counterclockwise"></i> Reiniciar Nivel
                </button>
                <button type="button" class="btn btn-outline-light btn-sm rounded-pill" onclick="togglePantallaCompleta('contenedorJuego2D')">
                    <i class="bi bi-arrows-fullscreen"></i> Pantalla Completa
                </button>
            </div>
        </div>

        <!-- Contenedor del Lienzo Canvas 2D -->
        <div id="contenedorJuego2D">
            <canvas id="canvasJuego2D" width="1200" height="640" style="width:100%; height:100%; display:block;"></canvas>

            <!-- HUD 2D: Vidas, Llaves, Puntos -->
            <div class="game-hud hud-timer">
                <div class="d-flex align-items-center gap-4">
                    <div>
                        <div class="text-secondary" style="font-size: 0.68rem;">VIDAS</div>
                        <div class="fs-5 fw-bold text-danger" id="hud2d_vidas">❤️❤️❤️</div>
                    </div>
                    <div class="border-start border-secondary ps-3">
                        <div class="text-secondary" style="font-size: 0.68rem;">LLAVES / PIEZAS</div>
                        <div class="fs-5 fw-bold text-warning" id="hud2d_llaves">🔑 0 / 10</div>
                    </div>
                    <div class="border-start border-secondary ps-3">
                        <div class="text-secondary" style="font-size: 0.68rem;">PUNTUACIÓN</div>
                        <div class="fs-5 fw-bold text-info" id="hud2d_puntos">00000</div>
                    </div>
                </div>
            </div>

            <!-- HUD: Teclas de Control 2D -->
            <div class="game-hud hud-controls-hint text-secondary">
                <i class="bi bi-controller text-warning me-1"></i>
                <kbd class="bg-dark text-white">A / D / ← →</kbd> Moverse &bull; 
                <kbd class="bg-dark text-white">W / ↑ / ESPACIO</kbd> Saltar &bull; 
                <kbd class="bg-dark text-white">SHIFT</kbd> Correr
            </div>
        </div>
    </div>

    <!-- ======================================================= -->
    <!-- 4. SALÓN DE LA FAMA (RANKING Y RÉCORDS)                 -->
    <!-- ======================================================= -->
    <div id="seccion_records" class="seccion-modulo d-none">
        <div class="arcade-card">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <h4 class="fw-bold mb-0 text-white d-flex align-items-center gap-2">
                    <i class="bi bi-trophy-fill text-warning"></i> Salón de la Fama de Grupo Huerta
                </h4>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-info btn-sm active" id="btnRank3D" onclick="cargarRanking('carreras_3d')">
                        🏎️ Carreras 3D
                    </button>
                    <button type="button" class="btn btn-outline-warning btn-sm" id="btnRank2D" onclick="cargarRanking('super_agente_2d')">
                        🔧 Super Taller 2D
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-arcade align-middle">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Pos</th>
                            <th>Piloto / Jugador</th>
                            <th>Agencia / Sucursal</th>
                            <th>Mundo</th>
                            <th id="th_metrica">Mejor Tiempo</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody id="tablaRankingBody">
                        <tr>
                            <td colspan="6" class="text-center py-4 text-secondary">
                                <span class="spinner-border spinner-border-sm me-2"></span> Cargando clasificación...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Audio Sintetizado Web Audio API (Cero descargas externas, 100% confiable) -->
<script>
let audioCtx = null;
let sonidoActivo = true;

function initAudio() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
}

function toggleAudio() {
    sonidoActivo = !sonidoActivo;
    const btn = document.getElementById('btnAudioMute');
    const icon = document.getElementById('audioIcon');
    if (sonidoActivo) {
        icon.className = 'bi bi-volume-up-fill';
        btn.classList.replace('btn-outline-secondary', 'btn-outline-info');
    } else {
        icon.className = 'bi bi-volume-mute-fill';
        btn.classList.replace('btn-outline-info', 'btn-outline-secondary');
    }
}

function reproducirTono(freq, dur, type = 'sine') {
    if (!sonidoActivo) return;
    try {
        initAudio();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
        gain.gain.setValueAtTime(0.12, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + dur);
    } catch(e) {}
}

function sfxSalto() { reproducirTono(450, 0.15, 'square'); }
function sfxMoneda() { reproducirTono(950, 0.12, 'sine'); setTimeout(() => reproducirTono(1300, 0.18, 'sine'), 80); }
function sfxDerrape() { reproducirTono(140, 0.22, 'sawtooth'); }
function sfxImpacto() { reproducirTono(90, 0.25, 'triangle'); }
function sfxTurbo() { 
    reproducirTono(280, 0.35, 'sawtooth'); 
    setTimeout(() => reproducirTono(560, 0.45, 'sawtooth'), 80); 
    setTimeout(() => reproducirTono(880, 0.5, 'sine'), 160); 
}
function sfxItemBox() {
    [700, 850, 1050, 1300].forEach((freq, idx) => {
        setTimeout(() => reproducirTono(freq, 0.1, 'sine'), idx * 50);
    });
}
function sfxVictoria() {
    [523, 659, 783, 1046].forEach((f, i) => {
        setTimeout(() => reproducirTono(f, 0.2, 'triangle'), i * 140);
    });
}
function sfxLag() {
    reproducirTono(180, 0.15, 'sawtooth');
    setTimeout(() => reproducirTono(120, 0.2, 'square'), 70);
    setTimeout(() => reproducirTono(90, 0.25, 'sawtooth'), 150);
}
function sfxLanzarLaptop() {
    reproducirTono(520, 0.1, 'sine');
    setTimeout(() => reproducirTono(980, 0.15, 'sawtooth'), 50);
    setTimeout(() => reproducirTono(1300, 0.18, 'sine'), 110);
}
function sfxLaptopImpacto() {
    reproducirTono(220, 0.15, 'sawtooth');
    setTimeout(() => reproducirTono(110, 0.25, 'triangle'), 60);
    setTimeout(() => reproducirTono(60, 0.35, 'square'), 120);
}
</script>

<!-- Script de Gestión de Avatar, Progresión de Mundos e Identidad -->
<script>
const USUARIO_OFICIAL = {
    id: <?php echo json_encode($usuarioId); ?>,
    nombre: <?php echo json_encode($nombreUsuario); ?>,
    agencia: <?php echo json_encode($agenciaUsuario); ?>,
    rol: <?php echo json_encode($rolUsuario); ?>
};

// Estado del Avatar
let avatarConfig = {
    vehiculo: 'auto', // 'auto', 'moto_suzuki', 'moto_kawasaki'
    casco: 'carreras', // 'carreras', 'gorra', 'gamer'
    colorTraje: '#00d2ff',
    mundoSeleccionado: 'taller',
    mundosDesbloqueados: ['taller']
};

// Cargar de DB o LocalStorage
try {
    const dbConfig = <?php echo json_encode($avatarConfigDb); ?>;
    if (dbConfig) {
        avatarConfig = Object.assign(avatarConfig, JSON.parse(dbConfig));
    } else {
        const local = localStorage.getItem('area404_avatar_' + USUARIO_OFICIAL.id);
        if (local) avatarConfig = Object.assign(avatarConfig, JSON.parse(local));
    }
} catch(e) {}

function dibujarAvatarPreview() {
    const canvas = document.getElementById('avatarPreviewCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Fondo degradado futurista
    const grad = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
    grad.addColorStop(0, '#041026');
    grad.addColorStop(1, '#020612');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // Línea de suelo neón
    ctx.strokeStyle = 'rgba(0, 210, 255, 0.4)';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(20, 150);
    ctx.lineTo(320, 150);
    ctx.stroke();

    // Dibujar Vehículo
    const cx = 170;
    const cy = 110;

    if (avatarConfig.vehiculo.startsWith('auto') || avatarConfig.vehiculo === 'auto') {
        const isCupra = (avatarConfig.vehiculo === 'auto_cupra');
        const isOmoda = (avatarConfig.vehiculo === 'auto_omoda');
        const isJetta = (!isCupra && !isOmoda);

        const bodyColor = isCupra ? '#1e293b' : avatarConfig.colorTraje;
        ctx.fillStyle = bodyColor;

        if (isJetta) {
            // VW Jetta (Sedán Clásico)
            ctx.beginPath();
            ctx.roundRect(cx - 72, cy + 6, 144, 26, 6);
            ctx.fill();
            // Cabina de Sedán
            ctx.fillStyle = '#0a1d37';
            ctx.beginPath();
            ctx.roundRect(cx - 36, cy - 14, 68, 20, 4);
            ctx.fill();
            ctx.strokeStyle = '#94a3b8';
            ctx.stroke();
            // Parrilla VW y línea roja GLI
            ctx.fillStyle = '#cbd5e1';
            ctx.fillRect(cx + 64, cy + 10, 8, 8);
            ctx.fillStyle = '#ef4444';
            ctx.fillRect(cx + 62, cy + 22, 10, 3);
            // Insignia VW
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 9px sans-serif';
            ctx.fillText('VW', cx + 46, cy + 18);
        } else if (isOmoda) {
            // Omoda C5 (SUV Coupé Futurista)
            ctx.beginPath();
            ctx.roundRect(cx - 68, cy - 2, 136, 34, 10);
            ctx.fill();
            // Techo Flotante Oscuro
            ctx.fillStyle = '#0f172a';
            ctx.beginPath();
            ctx.roundRect(cx - 32, cy - 18, 64, 22, 6);
            ctx.fill();
            // Parrilla Diamantes
            ctx.fillStyle = '#f59e0b';
            for (let d = 0; d < 3; d++) {
                ctx.fillRect(cx + 60, cy + 4 + (d * 8), 6, 4);
            }
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 8px monospace';
            ctx.fillText('OMODA', cx - 18, cy + 22);
        } else if (isCupra) {
            // Cupra Formentor (SUV Deportiva Cobre)
            ctx.fillStyle = '#1e293b';
            ctx.beginPath();
            ctx.roundRect(cx - 70, cy + 2, 140, 30, 8);
            ctx.fill();
            // Acentos Cobre Cupra
            ctx.fillStyle = '#ea580c';
            ctx.fillRect(cx + 60, cy + 20, 10, 4);
            ctx.fillRect(cx - 68, cy + 18, 6, 4);
            // Insignia Tribal Cobre
            ctx.fillStyle = '#ea580c';
            ctx.font = 'bold 12px sans-serif';
            ctx.fillText('⚡ CUPRA', cx - 24, cy + 20);
        }

        // Llantas deportivas
        const rimColor = isCupra ? '#ea580c' : '#94a3b8';
        ctx.fillStyle = '#111827';
        ctx.beginPath();
        ctx.arc(cx - 45, cy + 32, 13, 0, Math.PI * 2);
        ctx.arc(cx + 45, cy + 32, 13, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = rimColor;
        ctx.beginPath();
        ctx.arc(cx - 45, cy + 32, 5, 0, Math.PI * 2);
        ctx.arc(cx + 45, cy + 32, 5, 0, Math.PI * 2);
        ctx.fill();

        // Faros LED
        ctx.fillStyle = '#00d2ff';
        ctx.fillRect(cx + 64, cy + 10, 6, 6);
    } else {
        // Motos Suzuki / Kawasaki Superbike
        const esKawa = (avatarConfig.vehiculo === 'moto_kawasaki');
        const colorMoto = esKawa ? '#22c55e' : '#0284c7';

        // Llantas grandes de Superbike con disco de freno
        ctx.fillStyle = '#0f172a';
        ctx.beginPath();
        ctx.arc(cx - 52, cy + 26, 16, 0, Math.PI * 2);
        ctx.arc(cx + 52, cy + 26, 16, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = colorMoto;
        ctx.lineWidth = 2.5;
        ctx.stroke();

        // Chasis viga de aluminio
        ctx.fillStyle = '#94a3b8';
        ctx.fillRect(cx - 10, cy + 10, 24, 12);

        // Carenado aerodinámico Superbike
        ctx.fillStyle = colorMoto;
        ctx.beginPath();
        ctx.moveTo(cx - 44, cy + 22);
        ctx.lineTo(cx - 12, cy - 6);
        ctx.lineTo(cx + 36, cy - 12);
        ctx.lineTo(cx + 54, cy + 12);
        ctx.lineTo(cx + 22, cy + 26);
        ctx.closePath();
        ctx.fill();

        // Cúpula / Parabrisas tintado
        ctx.fillStyle = '#0f172a';
        ctx.beginPath();
        ctx.moveTo(cx + 20, cy - 10);
        ctx.lineTo(cx + 32, cy - 24);
        ctx.lineTo(cx + 42, cy - 12);
        ctx.fill();

        // Escape deportivo lateral
        ctx.fillStyle = '#64748b';
        ctx.fillRect(cx - 36, cy + 18, 22, 6);

        // Texto de marca
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 9px monospace';
        ctx.fillText(esKawa ? 'NINJA' : 'GSX-R', cx - 18, cy + 12);
    }

    // Dibujar Piloto
    ctx.fillStyle = avatarConfig.colorTraje;
    ctx.beginPath();
    ctx.arc(cx, cy - 32, 14, 0, Math.PI * 2); // Cabeza / Casco
    ctx.fill();

    // Detalle de Casco / Visor
    if (avatarConfig.casco === 'carreras') {
        ctx.fillStyle = '#0f172a';
        ctx.beginPath();
        ctx.roundRect(cx - 4, cy - 36, 16, 8, 2);
        ctx.fill();
        ctx.fillStyle = '#00d2ff';
        ctx.fillRect(cx - 2, cy - 34, 12, 3);
    } else if (avatarConfig.casco === 'gorra') {
        ctx.fillStyle = '#ef4444';
        ctx.fillRect(cx - 14, cy - 42, 28, 6);
        ctx.fillRect(cx + 2, cy - 38, 16, 4);
    } else {
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.arc(cx, cy - 34, 15, Math.PI, 0);
        ctx.stroke();
    }
}

function setVehiculo(tipo) {
    avatarConfig.vehiculo = tipo;
    ['auto_jetta', 'auto_omoda', 'auto_cupra', 'moto_suzuki', 'moto_kawasaki', 'auto'].forEach(v => {
        const el = document.getElementById('veh_' + v);
        if (el) el.classList.toggle('active', v === tipo || (v === 'auto_jetta' && tipo === 'auto'));
    });
    actualizarLabelsHUD();
    dibujarAvatarPreview();
    guardarAvatarServidor();
    if (scene3D && typeof construirVehiculo3D === 'function') {
        construirVehiculo3D();
    }
}

function setCasco(tipo) {
    avatarConfig.casco = tipo;
    dibujarAvatarPreview();
    guardarAvatarServidor();
}

function setColorTraje(hex, elem) {
    avatarConfig.colorTraje = hex;
    document.querySelectorAll('.color-dot').forEach(d => d.classList.remove('active'));
    if (elem) elem.classList.add('active');
    dibujarAvatarPreview();
    guardarAvatarServidor();
}

function seleccionarMundo(mundoId) {
    if (!avatarConfig.mundosDesbloqueados.includes(mundoId)) {
        Swal.fire({
            icon: 'info',
            title: 'Mundo Bloqueado',
            text: '¡Supera 3 vueltas en el circuito inicial o alcanza 1,000 pts en Super Taller Run para desbloquear este mundo!',
            background: '#06152b',
            color: '#fff',
            confirmButtonColor: '#00d2ff'
        });
        return;
    }
    avatarConfig.mundoSeleccionado = mundoId;
    ['taller', 'corporativo', 'rancho'].forEach(m => {
        const pill = document.getElementById('pill_mundo_' + m);
        if (pill) pill.classList.toggle('active', m === mundoId);
    });
    actualizarLabelsHUD();
    guardarAvatarServidor();
}

function desbloquearMundo(mundoId) {
    if (!avatarConfig.mundosDesbloqueados.includes(mundoId)) {
        avatarConfig.mundosDesbloqueados.push(mundoId);
        sfxVictoria();
        const pill = document.getElementById('pill_mundo_' + mundoId);
        const badge = document.getElementById('badge_mundo_' + mundoId);
        if (pill) pill.classList.remove('locked');
        if (badge) {
            badge.className = 'badge bg-success bg-opacity-25 text-success rounded-pill mt-2';
            badge.innerHTML = '<i class="bi bi-unlock-fill"></i> ¡Desbloqueado!';
        }
        Swal.fire({
            icon: 'success',
            title: '¡NUEVO MUNDO DESBLOQUEADO!',
            text: 'Has desbloqueado el circuito: ' + (mundoId === 'corporativo' ? 'Corporativo Grupo Huerta' : 'El Rancho'),
            background: '#06152b',
            color: '#fff',
            confirmButtonColor: '#00d2ff'
        });
        guardarAvatarServidor();
    }
}

function actualizarLabelsHUD() {
    const nombresMundos = { taller: 'Agencia & Taller Central', corporativo: 'Corporativo Grupo Huerta', rancho: 'El Rancho (Terracería)' };
    const nombresVeh = { auto: 'Auto Deportivo GT', moto_suzuki: 'Moto Suzuki GSX-R', moto_kawasaki: 'Kawasaki Ninja' };

    const lblM3D = document.getElementById('lblMundoActivo3D');
    const lblV3D = document.getElementById('lblVehiculoActivo3D');
    const lblM2D = document.getElementById('lblMundoActivo2D');

    if (lblM3D) lblM3D.textContent = 'Circuito: ' + (nombresMundos[avatarConfig.mundoSeleccionado] || 'Agencia');
    if (lblV3D) lblV3D.textContent = 'Vehículo: ' + (nombresVeh[avatarConfig.vehiculo] || 'Auto');
    if (lbl2D => lblM2D) lblM2D.textContent = 'Nivel: ' + (nombresMundos[avatarConfig.mundoSeleccionado] || 'Taller');
}

function guardarAvatarServidor() {
    try {
        localStorage.setItem('area404_avatar_' + USUARIO_OFICIAL.id, JSON.stringify(avatarConfig));
        fetch('area404.php?ajax_guardar_avatar=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(avatarConfig)
        });
    } catch(e) {}
}

function cambiarSeccion(seccionId) {
    document.querySelectorAll('.seccion-modulo').forEach(s => s.classList.add('d-none'));
    document.querySelectorAll('.arcade-tab-btn').forEach(b => b.classList.remove('active'));

    const sec = document.getElementById('seccion_' + seccionId);
    const tab = document.getElementById('tab_' + seccionId);
    if (sec) sec.classList.remove('d-none');
    if (tab) tab.classList.add('active');

    if (seccionId === 'carreras') {
        initCarrera3D();
    } else if (seccionId === 'plataformas') {
        initJuego2D();
    } else if (seccionId === 'records') {
        cargarRanking('carreras_3d');
    }
}

function togglePantallaCompleta(elemId) {
    const elem = document.getElementById(elemId);
    if (!elem) return;
    elem.classList.toggle('fullscreen');
    if (elemId === 'contenedorJuego3D' && renderer3D) {
        setTimeout(onWindowResize3D, 100);
    }
}
</script>

<!-- ======================================================= -->
<!-- MOTOR DE JUEGO 1: CARRERAS 3D (THREE.JS PROCEDURAL)     -->
<!-- ======================================================= -->
<script>
let scene3D, camera3D, renderer3D;
let vehiculo3D = null;
let animId3D = null;
let ruedaDelanteraIzq, ruedaDelanteraDer;
let escapeFlames = [];
let itemBoxes3D = [];
let boostPads3D = [];
let trackPoints3D = [];
let laptopProjectiles3D = [];
let rivales3D = [];
let radarCanvas3D, radarCtx3D;

// Estado Físico y Arcade del Vehículo 3D (Estilo Mario Kart)
let carState = {
    x: 0,
    z: -80,
    speed: 0,
    maxSpeed: 118, // km/h velocidad deportiva base
    accel: 0.62,
    brake: 1.35,
    friction: 0.984,
    angle: -Math.PI / 2, // Apuntando al inicio de la recta hacia +X
    angularVelocity: 0,
    steerAngle: 0,
    spinTimer: 0,
    hitObstacleTimer: 0,
    apLentoTimer: 0,
    apMeshOverCar: null,
    
    // Sistema de Derrape y Mini-Turbo (Mario Kart)
    isDrifting: false,
    driftDirection: 0,
    driftTimer: 0,
    turboTimer: 0,
    currentTurboLevel: 0, // 0: None, 1: Blue (Mini), 2: Orange (Super)
    
    // Items y Poderes
    itemActual: null, // 'turbo', 'laptop', 'escudo'
    itemTimer: 0,
    
    lap: 1,
    maxLaps: 3,
    lapStartTime: 0,
    bestLapTime: null,
    raceFinished: false,
    checkpoints: [false, false, false],
    lastTrackIdx: 0
};

// Controles Teclado
const keysPressed = {};
window.addEventListener('keydown', e => {
    keysPressed[e.code] = true;
    if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Space','KeyE'].includes(e.code)) {
        e.preventDefault();
    }
    if (e.code === 'KeyE') {
        usarItem3D();
    }
});
window.addEventListener('keyup', e => {
    keysPressed[e.code] = false;
    
    // Al soltar ESPACIO mientras derrapaba -> Disparar Mini-Turbo!
    if (e.code === 'Space' && carState.isDrifting) {
        soltarDerrapeTurbo();
    }
});

function initCarrera3D() {
    const container = document.getElementById('contenedorJuego3D');
    if (!container || scene3D) return;

    // Radar Canvas
    radarCanvas3D = document.getElementById('radar3DCanvas');
    if (radarCanvas3D) radarCtx3D = radarCanvas3D.getContext('2d');

    // 1. Escena y Niebla Arcade
    scene3D = new THREE.Scene();
    const esCorp = (avatarConfig.mundoSeleccionado === 'corporativo');
    const esRancho = (avatarConfig.mundoSeleccionado === 'rancho');

    scene3D.background = new THREE.Color(esCorp ? 0x050d1a : (esRancho ? 0x64b5f6 : 0x091e3a));
    scene3D.fog = new THREE.FogExp2(scene3D.background, 0.0055);

    camera3D = new THREE.PerspectiveCamera(65, container.clientWidth / container.clientHeight, 0.1, 1000);

    // 2. Renderer con Sombras Suaves
    renderer3D = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
    renderer3D.setSize(container.clientWidth, container.clientHeight);
    renderer3D.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer3D.shadowMap.enabled = true;
    renderer3D.shadowMap.type = THREE.PCFSoftShadowMap;
    container.appendChild(renderer3D.domElement);

    // 3. Iluminación Vibrante Mario Kart
    const hemiLight = new THREE.HemisphereLight(0xffffff, esRancho ? 0x795548 : 0x111827, 0.65);
    scene3D.add(hemiLight);

    const sunLight = new THREE.DirectionalLight(0xfff5e6, 1.1);
    sunLight.position.set(70, 140, 80);
    sunLight.castShadow = true;
    sunLight.shadow.mapSize.width = 2048;
    sunLight.shadow.mapSize.height = 2048;
    sunLight.shadow.camera.near = 0.5;
    sunLight.shadow.camera.far = 400;
    const d = 160;
    sunLight.shadow.camera.left = -d;
    sunLight.shadow.camera.right = d;
    sunLight.shadow.camera.top = d;
    sunLight.shadow.camera.bottom = -d;
    scene3D.add(sunLight);

    // 4. Construir Circuito Estilo Mario Kart (Pista, Pianos, Arco, Boosts, Cajas)
    construirPista3DMarioKart();

    // 5. Construir Vehículo / Kart 3D
    construirVehiculo3D();

    // 5.5. Construir Rivales IA de Agencias Grupo Huerta
    crearRivales3D();

    // 6. Configurar salida en parrilla y bucle
    carState.x = 0;
    carState.z = -80;
    carState.speed = 0;
    carState.angle = -Math.PI / 2;
    carState.steerAngle = 0;
    carState.spinTimer = 0;
    carState.hitObstacleTimer = 0;
    carState.apLentoTimer = 0;
    if (carState.apMeshOverCar) carState.apMeshOverCar.visible = false;
    carState.lap = 1;
    carState.raceFinished = false;
    carState.checkpoints = [false, false, false];
    carState.lapStartTime = performance.now();

    window.addEventListener('resize', onWindowResize3D);
    animar3D();
    mostrarBannerHUD('¡A CORRER!', '#00d2ff');
}

function onWindowResize3D() {
    const container = document.getElementById('contenedorJuego3D');
    if (!container || !camera3D || !renderer3D) return;
    camera3D.aspect = container.clientWidth / container.clientHeight;
    camera3D.updateProjectionMatrix();
    renderer3D.setSize(container.clientWidth, container.clientHeight);
}

// ==============================================================
// GENERADOR DE PISTA Y PAREDES DE SEGURIDAD (ESTILO KARTÓDROMO)
// ==============================================================
let wallSegments3D = []; // Barreras para colisiones físicas

function construirPista3DMarioKart() {
    itemBoxes3D = [];
    boostPads3D = [];
    wallSegments3D = [];

    const esRancho = (avatarConfig.mundoSeleccionado === 'rancho');
    const esCorp = (avatarConfig.mundoSeleccionado === 'corporativo');

    // 1. Suelo Base de Césped / Entorno con Textura a Cuadros Mario Kart
    const groundCanvas = document.createElement('canvas');
    groundCanvas.width = 256; groundCanvas.height = 256;
    const gCtx = groundCanvas.getContext('2d');
    gCtx.fillStyle = esRancho ? '#8d6e63' : (esCorp ? '#050d1a' : '#15803d');
    gCtx.fillRect(0, 0, 256, 256);
    gCtx.fillStyle = esRancho ? '#a1887f' : (esCorp ? '#0a192f' : '#16a34a');
    for (let gx = 0; gx < 256; gx += 32) {
        for (let gy = 0; gy < 256; gy += 32) {
            if ((gx / 32 + gy / 32) % 2 === 0) gCtx.fillRect(gx, gy, 32, 32);
        }
    }
    const groundTex = new THREE.CanvasTexture(groundCanvas);
    groundTex.wrapS = THREE.RepeatWrapping;
    groundTex.wrapT = THREE.RepeatWrapping;
    groundTex.repeat.set(50, 50);

    const groundGeo = new THREE.PlaneGeometry(900, 900);
    const groundMat = new THREE.MeshStandardMaterial({ map: groundTex, roughness: 0.9, metalness: 0.1 });
    const ground = new THREE.Mesh(groundGeo, groundMat);
    ground.rotation.x = -Math.PI / 2;
    ground.receiveShadow = true;
    scene3D.add(ground);

    // 2. Trazado Curvilíneo Diseñado para Carreras de Karts
    const curvePoints = [
        new THREE.Vector3(0, 0, -80),     // Recta de Salida / Meta
        new THREE.Vector3(55, 0, -80),    // Curva 1
        new THREE.Vector3(95, 0, -45),    // Enlace
        new THREE.Vector3(105, 0, 10),    // Horquilla Este
        new THREE.Vector3(75, 0, 65),     // Curva S
        new THREE.Vector3(25, 0, 50),     // Chicane central
        new THREE.Vector3(-25, 0, 80),    // Curva Sur
        new THREE.Vector3(-85, 0, 70),    // Horquilla Oeste
        new THREE.Vector3(-105, 0, 15),   // Curva Rápida Oeste
        new THREE.Vector3(-90, 0, -45),   // Curva previa a recta
        new THREE.Vector3(-45, 0, -80)    // Entrada a recta principal
    ];
    const catmull = new THREE.CatmullRomCurve3(curvePoints, true, 'centripetal');
    trackPoints3D = catmull.getPoints(180);
    trackPoints3D.pop(); // Eliminar punto 181 duplicado para prevenir len=0 y división por cero

    const trackWidth = 20; // Ancho de pista (20 unidades)
    const halfWidth = trackWidth / 2;

    const roadPositions = [];
    const roadUVs = [];
    const kerbLeftPositions = [];
    const kerbLeftUVs = [];
    const kerbRightPositions = [];
    const kerbRightUVs = [];
    const roadIndices = [];
    const kerbIndices = [];

    // Muros / Paredes 3D a lo largo de toda la pista
    const wallHeight = 2.4;
    const wallLeftPos = [];
    const wallLeftUVs = [];
    const wallRightPos = [];
    const wallRightUVs = [];
    const wallIndices = [];

    const numPts = trackPoints3D.length;
    for (let i = 0; i < numPts; i++) {
        const p1 = trackPoints3D[i];
        const p2 = trackPoints3D[(i + 1) % numPts];
        const dx = p2.x - p1.x;
        const dz = p2.z - p1.z;
        let len = Math.hypot(dx, dz);
        if (len < 0.0001) len = 0.0001; // Evita división por cero
        const nx = (-dz / len);
        const nz = (dx / len);

        const uvY = (i / numPts) * 45;

        // Bordes de la pista
        const leftEdge = new THREE.Vector3(p1.x + nx * halfWidth, 0.08, p1.z + nz * halfWidth);
        const rightEdge = new THREE.Vector3(p1.x - nx * halfWidth, 0.08, p1.z - nz * halfWidth);

        roadPositions.push(leftEdge.x, leftEdge.y, leftEdge.z);
        roadPositions.push(rightEdge.x, rightEdge.y, rightEdge.z);
        roadUVs.push(0, uvY);
        roadUVs.push(1, uvY);

        // Pianos exteriores (Kerbs) con relieve
        const kerbWidth = 1.8;
        const klOuter = new THREE.Vector3(p1.x + nx * (halfWidth + kerbWidth), 0.22, p1.z + nz * (halfWidth + kerbWidth));
        const krOuter = new THREE.Vector3(p1.x - nx * (halfWidth + kerbWidth), 0.22, p1.z - nz * (halfWidth + kerbWidth));

        kerbLeftPositions.push(leftEdge.x, 0.12, leftEdge.z);
        kerbLeftPositions.push(klOuter.x, 0.22, klOuter.z);
        kerbLeftUVs.push(0, uvY); kerbLeftUVs.push(1, uvY);

        kerbRightPositions.push(rightEdge.x, 0.12, rightEdge.z);
        kerbRightPositions.push(krOuter.x, 0.22, krOuter.z);
        kerbRightUVs.push(0, uvY); kerbRightUVs.push(1, uvY);

        // Muros de Contención / Guardrails Elevados
        const wallMargin = halfWidth + kerbWidth + 0.2;
        const wlBase = new THREE.Vector3(p1.x + nx * wallMargin, 0, p1.z + nz * wallMargin);
        const wlTop  = new THREE.Vector3(p1.x + nx * wallMargin, wallHeight, p1.z + nz * wallMargin);
        wallLeftPos.push(wlBase.x, wlBase.y, wlBase.z);
        wallLeftPos.push(wlTop.x, wlTop.y, wlTop.z);
        wallLeftUVs.push(0, uvY); wallLeftUVs.push(1, uvY);

        const wrBase = new THREE.Vector3(p1.x - nx * wallMargin, 0, p1.z - nz * wallMargin);
        const wrTop  = new THREE.Vector3(p1.x - nx * wallMargin, wallHeight, p1.z - nz * wallMargin);
        wallRightPos.push(wrBase.x, wrBase.y, wrBase.z);
        wallRightPos.push(wrTop.x, wrTop.y, wrTop.z);
        wallRightUVs.push(0, uvY); wallRightUVs.push(1, uvY);
    }

    for (let i = 0; i < numPts; i++) {
        const i0 = i * 2;
        const i1 = i0 + 1;
        const i2 = ((i + 1) % numPts) * 2;
        const i3 = i2 + 1;

        roadIndices.push(i0, i1, i2);
        roadIndices.push(i1, i3, i2);

        kerbIndices.push(i0, i1, i2);
        kerbIndices.push(i1, i3, i2);

        wallIndices.push(i0, i1, i2);
        wallIndices.push(i1, i3, i2);
    }

    // Textura de Asfalto con Líneas de Carril y Bordes Pintados
    const asphaltCanvas = document.createElement('canvas');
    asphaltCanvas.width = 512; asphaltCanvas.height = 512;
    const aCtx = asphaltCanvas.getContext('2d');
    aCtx.fillStyle = '#1e293b'; // Asfalto gris oscuro
    aCtx.fillRect(0, 0, 512, 512);

    for (let i = 0; i < 2500; i++) {
        aCtx.fillStyle = (Math.random() > 0.5 ? '#15202e' : '#2d3748');
        aCtx.fillRect(Math.random() * 512, Math.random() * 512, 2, 2);
    }

    // Líneas blancas en los bordes de la pista
    aCtx.fillStyle = '#ffffff';
    aCtx.fillRect(16, 0, 14, 512);
    aCtx.fillRect(482, 0, 14, 512);

    // Línea discontinua amarilla en el centro
    aCtx.fillStyle = '#fde047';
    for (let y = 0; y < 512; y += 48) {
        aCtx.fillRect(248, y, 16, 28);
    }

    const texAsphalt = new THREE.CanvasTexture(asphaltCanvas);
    texAsphalt.wrapS = THREE.RepeatWrapping;
    texAsphalt.wrapT = THREE.RepeatWrapping;
    texAsphalt.repeat.set(1, 45);

    const roadGeo = new THREE.BufferGeometry();
    roadGeo.setAttribute('position', new THREE.Float32BufferAttribute(roadPositions, 3));
    roadGeo.setAttribute('uv', new THREE.Float32BufferAttribute(roadUVs, 2));
    roadGeo.setIndex(roadIndices);
    roadGeo.computeVertexNormals();

    const roadMat = new THREE.MeshStandardMaterial({ map: texAsphalt, roughness: 0.82, metalness: 0.15 });
    const roadMesh = new THREE.Mesh(roadGeo, roadMat);
    roadMesh.receiveShadow = true;
    scene3D.add(roadMesh);

    // Textura Pianos Rayados (Rojo y Blanco)
    const kerbCanvas = document.createElement('canvas');
    kerbCanvas.width = 64; kerbCanvas.height = 32;
    const kCtx = kerbCanvas.getContext('2d');
    kCtx.fillStyle = '#e11d48'; kCtx.fillRect(0, 0, 32, 32);
    kCtx.fillStyle = '#ffffff'; kCtx.fillRect(32, 0, 32, 32);
    const texKerb = new THREE.CanvasTexture(kerbCanvas);
    texKerb.wrapS = THREE.RepeatWrapping;
    texKerb.wrapT = THREE.RepeatWrapping;
    texKerb.repeat.set(45, 1);

    const kerbMat = new THREE.MeshStandardMaterial({ map: texKerb, roughness: 0.6 });

    const kerbGeoL = new THREE.BufferGeometry();
    kerbGeoL.setAttribute('position', new THREE.Float32BufferAttribute(kerbLeftPositions, 3));
    kerbGeoL.setAttribute('uv', new THREE.Float32BufferAttribute(kerbLeftUVs, 2));
    kerbGeoL.setIndex(kerbIndices);
    kerbGeoL.computeVertexNormals();
    scene3D.add(new THREE.Mesh(kerbGeoL, kerbMat));

    const kerbGeoR = new THREE.BufferGeometry();
    kerbGeoR.setAttribute('position', new THREE.Float32BufferAttribute(kerbRightPositions, 3));
    kerbGeoR.setAttribute('uv', new THREE.Float32BufferAttribute(kerbRightUVs, 2));
    kerbGeoR.setIndex(kerbIndices);
    kerbGeoR.computeVertexNormals();
    scene3D.add(new THREE.Mesh(kerbGeoR, kerbMat));

    // PAREDES / BARRERAS DE SEGURIDAD 3D (GUARDRAILS & MUROS)
    const wallCanvas = document.createElement('canvas');
    wallCanvas.width = 512; wallCanvas.height = 64;
    const wCtx = wallCanvas.getContext('2d');
    wCtx.fillStyle = '#0f172a'; wCtx.fillRect(0, 0, 512, 64);
    // Franja reflectante superior
    wCtx.fillStyle = '#0284c7'; wCtx.fillRect(0, 6, 512, 14);
    // Chevrons amarillos y negros en extremos
    for (let cx = 0; cx < 512; cx += 64) {
        wCtx.fillStyle = '#facc15';
        wCtx.beginPath();
        wCtx.moveTo(cx, 22); wCtx.lineTo(cx + 20, 22); wCtx.lineTo(cx + 34, 46); wCtx.lineTo(cx + 14, 46);
        wCtx.fill();
    }
    wCtx.fillStyle = '#ffffff'; wCtx.font = 'bold 20px sans-serif'; wCtx.textAlign = 'center';
    wCtx.fillText('★ GRUPO HUERTA ★ SUZUKI ★ KAWASAKI ★', 256, 42);

    const texWall = new THREE.CanvasTexture(wallCanvas);
    texWall.wrapS = THREE.RepeatWrapping;
    texWall.wrapT = THREE.RepeatWrapping;
    texWall.repeat.set(30, 1);

    const wallMat = new THREE.MeshStandardMaterial({ map: texWall, side: THREE.DoubleSide, metalness: 0.7, roughness: 0.35 });

    const wallGeoL = new THREE.BufferGeometry();
    wallGeoL.setAttribute('position', new THREE.Float32BufferAttribute(wallLeftPos, 3));
    wallGeoL.setAttribute('uv', new THREE.Float32BufferAttribute(wallLeftUVs, 2));
    wallGeoL.setIndex(wallIndices);
    wallGeoL.computeVertexNormals();
    const meshWallL = new THREE.Mesh(wallGeoL, wallMat);
    meshWallL.castShadow = true;
    scene3D.add(meshWallL);

    const wallGeoR = new THREE.BufferGeometry();
    wallGeoR.setAttribute('position', new THREE.Float32BufferAttribute(wallRightPos, 3));
    wallGeoR.setAttribute('uv', new THREE.Float32BufferAttribute(wallRightUVs, 2));
    wallGeoR.setIndex(wallIndices);
    wallGeoR.computeVertexNormals();
    const meshWallR = new THREE.Mesh(wallGeoR, wallMat);
    meshWallR.castShadow = true;
    scene3D.add(meshWallR);

    // Pilas de Neumáticos 3D en las Curvas Peligrosas
    const indicesCurvas = [20, 55, 85, 120, 155];
    indicesCurvas.forEach(idx => {
        const pt = trackPoints3D[idx % trackPoints3D.length];
        for (let t = -3; t <= 3; t += 3) {
            construirPilaNeumaticos(pt.x * 1.15 + t, pt.z * 1.15);
        }
    });

    // Marcas de Derrape sobre el Asfalto (Skid marks) en curvas
    construirMarcasDerrape(55, -80, 0.4);
    construirMarcasDerrape(95, -45, 0.8);
    construirMarcasDerrape(105, 10, 1.2);
    construirMarcasDerrape(-85, 70, -0.6);

    // Arco Monumental de Meta
    construirArcoMeta(new THREE.Vector3(0, 0, -80));

    // Grada Principal con Público (Tribuna)
    construirGradaPrincipal(18, -98, 0);

    // Boxes de Taller & Concesionaria en la Recta Principal
    construirBoxesTaller(-20, -98, 0);

    // 4 Torres de Iluminación de Circuito (Stadium Lights)
    construirTorreIluminacion(65, -92);
    construirTorreIluminacion(118, 15);
    construirTorreIluminacion(-92, 85);
    construirTorreIluminacion(-115, -45);

    // Obstáculos TI en pista (Impresoras con atasco de papel y Cables de Red UTP)
    construirObstaculosPista();
    const boostIndices = [22, 75, 130];
    boostIndices.forEach(idx => {
        const pt = trackPoints3D[idx % trackPoints3D.length];
        const nextPt = trackPoints3D[(idx + 1) % trackPoints3D.length];
        const padGroup = new THREE.Group();

        const padMesh = new THREE.Mesh(
            new THREE.PlaneGeometry(14, 5.5),
            new THREE.MeshBasicMaterial({ color: 0x00ffff, transparent: true, opacity: 0.95 })
        );
        padMesh.rotation.x = -Math.PI / 2;
        padMesh.position.y = 0.12;
        padGroup.add(padMesh);

        for (let a = -3.5; a <= 3.5; a += 3.5) {
            const arr = new THREE.Mesh(
                new THREE.ConeGeometry(1.4, 2.5, 3),
                new THREE.MeshBasicMaterial({ color: 0xffe600 })
            );
            arr.rotation.x = Math.PI / 2;
            arr.position.set(a, 0.18, 0);
            padGroup.add(arr);
        }

        padGroup.position.set(pt.x, 0, pt.z);
        padGroup.lookAt(nextPt.x, 0, nextPt.z);
        scene3D.add(padGroup);
        boostPads3D.push({ x: pt.x, z: pt.z, radius: 8, mesh: padGroup });
    });

    // Cajas de Objetos Misteriosos Flotantes
    const itemIndices = [12, 45, 95, 145];
    itemIndices.forEach(idx => {
        const pt = trackPoints3D[idx % trackPoints3D.length];
        for (let lane = -5; lane <= 5; lane += 5) {
            const boxGroup = new THREE.Group();
            const cube = new THREE.Mesh(
                new THREE.BoxGeometry(2.2, 2.2, 2.2),
                new THREE.MeshStandardMaterial({
                    color: 0xffe600,
                    emissive: 0xffaa00,
                    emissiveIntensity: 0.7,
                    transparent: true,
                    opacity: 0.85,
                    roughness: 0.1,
                    metalness: 0.8
                })
            );
            boxGroup.add(cube);

            const wire = new THREE.LineSegments(
                new THREE.EdgesGeometry(cube.geometry),
                new THREE.LineBasicMaterial({ color: 0xffffff, linewidth: 2 })
            );
            boxGroup.add(wire);

            boxGroup.position.set(pt.x + (lane * 0.8), 2.2, pt.z);
            scene3D.add(boxGroup);

            itemBoxes3D.push({
                group: boxGroup,
                x: pt.x + (lane * 0.8),
                z: pt.z,
                activo: true,
                respawnTimer: 0
            });
        }
    });

    // Decoraciones de Mundo y Vegetación
    for (let i = 0; i < trackPoints3D.length; i += 8) {
        const pt = trackPoints3D[i];
        const bannerPos = { x: pt.x * 1.35, z: pt.z * 1.35 };

        if (esCorp) {
            const h = 45 + Math.random() * 80;
            const bld = new THREE.Mesh(
                new THREE.BoxGeometry(18, h, 18),
                new THREE.MeshStandardMaterial({
                    color: 0x0a1c38,
                    emissive: (i % 16 === 0 ? 0x00d2ff : 0x020817),
                    emissiveIntensity: 0.45
                })
            );
            bld.position.set(bannerPos.x * 1.25, h / 2, bannerPos.z * 1.25);
            scene3D.add(bld);
        } else if (esRancho) {
            if (i % 24 === 0) construirMolinoViento(bannerPos.x, bannerPos.z);
            else construirArbolCartoon(bannerPos.x, bannerPos.z);
        } else {
            if (i % 16 === 0) construirFarolaNeón(bannerPos.x, bannerPos.z);
            else construirArbolCartoon(bannerPos.x, bannerPos.z);
        }
    }
}

// Pilas de Neumáticos de Seguridad (Tire Walls)
function construirPilaNeumaticos(x, z) {
    const stack = new THREE.Group();
    const colors = [0xe11d48, 0xffffff, 0x0284c7];
    for (let h = 0; h < 3; h++) {
        const tireGeo = new THREE.TorusGeometry(0.7, 0.35, 8, 16);
        tireGeo.rotateX(Math.PI / 2);
        const tireMat = new THREE.MeshLambertMaterial({ color: colors[h % 3] });
        const tire = new THREE.Mesh(tireGeo, tireMat);
        tire.position.y = 0.4 + (h * 0.65);
        stack.add(tire);
    }
    stack.position.set(x, 0, z);
    scene3D.add(stack);
}

// Grada Principal con Público y Banderas
function construirGradaPrincipal(x, z, rotY) {
    const grandstand = new THREE.Group();
    for (let step = 0; step < 4; step++) {
        const stepGeo = new THREE.BoxGeometry(28, 1.4, 3.5);
        const stepMat = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.8 });
        const stepMesh = new THREE.Mesh(stepGeo, stepMat);
        stepMesh.position.set(0, 0.7 + (step * 1.4), -step * 3.2);
        grandstand.add(stepMesh);

        const fanColors = [0xef4444, 0x3b82f6, 0x10b981, 0xf59e0b, 0x8b5cf6, 0xffffff];
        for (let fan = -12; fan <= 12; fan += 2.4) {
            const fanGeo = new THREE.SphereGeometry(0.48, 6, 6);
            const fanMat = new THREE.MeshLambertMaterial({ color: fanColors[Math.floor(Math.random() * fanColors.length)] });
            const fanMesh = new THREE.Mesh(fanGeo, fanMat);
            fanMesh.position.set(fan + (Math.random() * 0.4), 1.9 + (step * 1.4), -step * 3.2);
            grandstand.add(fanMesh);
        }
    }

    const roofGeo = new THREE.BoxGeometry(30, 0.4, 16);
    const roofMat = new THREE.MeshStandardMaterial({ color: 0x0284c7, metalness: 0.6, roughness: 0.3 });
    const roof = new THREE.Mesh(roofGeo, roofMat);
    roof.position.set(0, 8.5, -5.5);
    roof.rotation.x = 0.15;
    grandstand.add(roof);

    const bannerCanvas = document.createElement('canvas');
    bannerCanvas.width = 512; bannerCanvas.height = 64;
    const bCtx = bannerCanvas.getContext('2d');
    bCtx.fillStyle = '#0f172a'; bCtx.fillRect(0, 0, 512, 64);
    bCtx.fillStyle = '#00d2ff'; bCtx.font = 'bold 24px sans-serif'; bCtx.textAlign = 'center';
    bCtx.fillText('★ TRIBUNA PRINCIPAL GRUPO HUERTA ★', 256, 42);
    const bTex = new THREE.CanvasTexture(bannerCanvas);
    const bMesh = new THREE.Mesh(new THREE.PlaneGeometry(28, 2.2), new THREE.MeshBasicMaterial({ map: bTex, side: THREE.DoubleSide }));
    bMesh.position.set(0, 7.2, 1.8);
    grandstand.add(bMesh);

    grandstand.position.set(x, 0, z);
    grandstand.rotation.y = rotY;
    scene3D.add(grandstand);
}

// Boxes de Taller Mecánico & Concesionarias
function construirBoxesTaller(x, z, rotY) {
    const pit = new THREE.Group();
    const buildingGeo = new THREE.BoxGeometry(32, 7, 10);
    const buildingMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.4, roughness: 0.6 });
    const building = new THREE.Mesh(buildingGeo, buildingMat);
    building.position.set(0, 3.5, 0);
    pit.add(building);

    for (let p = -10; p <= 10; p += 10) {
        const doorGeo = new THREE.BoxGeometry(7, 5, 0.4);
        const doorMat = new THREE.MeshStandardMaterial({ color: 0x0284c7, metalness: 0.7, roughness: 0.3 });
        const door = new THREE.Mesh(doorGeo, doorMat);
        door.position.set(p, 2.5, 5.1);
        pit.add(door);
    }

    const signCanvas = document.createElement('canvas');
    signCanvas.width = 512; signCanvas.height = 64;
    const sCtx = signCanvas.getContext('2d');
    sCtx.fillStyle = '#0284c7'; sCtx.fillRect(0, 0, 512, 64);
    sCtx.fillStyle = '#ffffff'; sCtx.font = 'bold 24px sans-serif'; sCtx.textAlign = 'center';
    sCtx.fillText('🔧 TALLER DE SERVICIO & RACING HUERTA 🔧', 256, 42);
    const sTex = new THREE.CanvasTexture(signCanvas);
    const sMesh = new THREE.Mesh(new THREE.PlaneGeometry(30, 2), new THREE.MeshBasicMaterial({ map: sTex }));
    sMesh.position.set(0, 6.0, 5.2);
    pit.add(sMesh);

    pit.position.set(x, 0, z);
    pit.rotation.y = rotY;
    scene3D.add(pit);
}

// Torres de Iluminación de Circuito (Stadium Floodlights)
function construirTorreIluminacion(x, z) {
    const tower = new THREE.Group();
    const poleGeo = new THREE.CylinderGeometry(0.5, 0.8, 22, 8);
    const poleMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.8, roughness: 0.3 });
    const pole = new THREE.Mesh(poleGeo, poleMat);
    pole.position.y = 11;
    tower.add(pole);

    const headGeo = new THREE.BoxGeometry(6, 2.5, 1.2);
    const headMat = new THREE.MeshStandardMaterial({ color: 0x0f172a });
    const head = new THREE.Mesh(headGeo, headMat);
    head.position.set(0, 22, 0);
    tower.add(head);

    for (let f = -2; f <= 2; f += 1.3) {
        const spot = new THREE.Mesh(new THREE.CircleGeometry(0.5, 12), new THREE.MeshBasicMaterial({ color: 0xffffff }));
        spot.position.set(f, 22, 0.65);
        tower.add(spot);
    }

    tower.position.set(x, 0, z);
    tower.lookAt(0, 0, 0);
    scene3D.add(tower);
}

// Marcas de Derrape sobre el Asfalto (Skid Marks)
function construirMarcasDerrape(x, z, angle) {
    const markGeo = new THREE.PlaneGeometry(1.6, 6);
    const markMat = new THREE.MeshBasicMaterial({ color: 0x090d16, transparent: true, opacity: 0.65, polygonOffset: true, polygonOffsetFactor: -2 });
    const markL = new THREE.Mesh(markGeo, markMat);
    markL.rotation.x = -Math.PI / 2;
    markL.rotation.z = angle;
    markL.position.set(x - 1.2, 0.09, z);
    scene3D.add(markL);

    const markR = markL.clone();
    markR.position.set(x + 1.2, 0.09, z);
    scene3D.add(markR);
}

// Arco Monumental de Meta con Línea de Salida a Cuadros (Perpendicular a la pista)
function construirArcoMeta(pos) {
    const archGroup = new THREE.Group();

    // Columnas metálicas trusses en los extremos exteriores de la pista (ancho 20 en Z, columnas en +/-13.5)
    const colGeo = new THREE.BoxGeometry(2, 14, 2);
    const colMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.3 });
    const colL = new THREE.Mesh(colGeo, colMat); colL.position.set(0, 7, -13.5); archGroup.add(colL);
    const colR = new THREE.Mesh(colGeo, colMat); colR.position.set(0, 7, 13.5); archGroup.add(colR);

    // Viga superior transversal cruzando toda la pista a lo largo de Z
    const beamGeo = new THREE.BoxGeometry(3, 3.5, 29);
    const beamMat = new THREE.MeshStandardMaterial({ color: 0x00d2ff, emissive: 0x0077b6, emissiveIntensity: 0.5 });
    const beam = new THREE.Mesh(beamGeo, beamMat);
    beam.position.set(0, 13.5, 0);
    archGroup.add(beam);

    // Letrero 3D "🏁 GRUPO HUERTA GRAND PRIX 🏁"
    const signCanvas = document.createElement('canvas');
    signCanvas.width = 512; signCanvas.height = 128;
    const ctxS = signCanvas.getContext('2d');
    ctxS.fillStyle = '#030a16'; ctxS.fillRect(0, 0, 512, 128);
    for (let bx = 0; bx < 512; bx += 16) {
        ctxS.fillStyle = (bx % 32 === 0) ? '#ffffff' : '#000000';
        ctxS.fillRect(bx, 0, 16, 12);
        ctxS.fillRect(bx, 116, 16, 12);
    }
    ctxS.strokeStyle = '#00d2ff'; ctxS.lineWidth = 4; ctxS.strokeRect(4, 14, 504, 100);
    ctxS.fillStyle = '#ffffff'; ctxS.font = 'bold 30px sans-serif'; ctxS.textAlign = 'center';
    ctxS.fillText('🏁 GRUPO HUERTA GRAND PRIX 🏁', 256, 74);
    const signTex = new THREE.CanvasTexture(signCanvas);

    // Cara Frontal: mira hacia -X (de donde viene el auto en la recta principal)
    const signGeo = new THREE.PlaneGeometry(24, 5);
    const signMat = new THREE.MeshBasicMaterial({ map: signTex, side: THREE.DoubleSide });
    const signMeshFront = new THREE.Mesh(signGeo, signMat);
    signMeshFront.rotation.y = -Math.PI / 2;
    signMeshFront.position.set(-1.6, 13.5, 0);
    archGroup.add(signMeshFront);

    // Cara Posterior: mira hacia +X
    const signMeshBack = new THREE.Mesh(signGeo, signMat);
    signMeshBack.rotation.y = Math.PI / 2;
    signMeshBack.position.set(1.6, 13.5, 0);
    archGroup.add(signMeshBack);

    // Línea de meta de cuadros en el suelo (perpendicular a la marcha del auto: 4m de espesor en X, 20m de ancho en Z)
    const checkCanvas = document.createElement('canvas');
    checkCanvas.width = 128; checkCanvas.height = 512;
    const chCtx = checkCanvas.getContext('2d');
    for (let cy = 0; cy < 512; cy += 32) {
        for (let cx = 0; cx < 128; cx += 32) {
            chCtx.fillStyle = ((cx / 32 + cy / 32) % 2 === 0) ? '#ffffff' : '#111827';
            chCtx.fillRect(cx, cy, 32, 32);
        }
    }
    const checkTex = new THREE.CanvasTexture(checkCanvas);
    const checkGeo = new THREE.PlaneGeometry(4, 20);
    const checkMat = new THREE.MeshBasicMaterial({ map: checkTex, polygonOffset: true, polygonOffsetFactor: -1 });
    const checkMesh = new THREE.Mesh(checkGeo, checkMat);
    checkMesh.rotation.x = -Math.PI / 2;
    checkMesh.position.set(0, 0.12, 0);
    archGroup.add(checkMesh);

    archGroup.position.copy(pos);
    scene3D.add(archGroup);
}

// ==============================================================
// OBSTÁCULOS TI EN PISTA (IMPRESORAS, CABLES DE RED UTP)
// ==============================================================
let obstacles3D = [];

function construirObstaculosPista() {
    obstacles3D = [];

    // 1. Impresoras Láser de Oficina (HP / Kyocera) - Provocan trompos de atasco o falta de hojas
    const posImpresoras = [
        { idx: 28, offsetLat: 3.5, msgTipo: 'atasco' },
        { idx: 68, offsetLat: -4.0, msgTipo: 'hojas' },
        { idx: 108, offsetLat: 2.8, msgTipo: 'atasco' },
        { idx: 148, offsetLat: -3.2, msgTipo: 'hojas' }
    ];

    posImpresoras.forEach(p => {
        const pt = trackPoints3D[p.idx % trackPoints3D.length];
        const nextPt = trackPoints3D[(p.idx + 1) % trackPoints3D.length];
        const dx = nextPt.x - pt.x;
        const dz = nextPt.z - pt.z;
        const len = Math.hypot(dx, dz) || 1;
        const nx = -dz / len;
        const nz = dx / len;

        const posX = pt.x + nx * p.offsetLat;
        const posZ = pt.z + nz * p.offsetLat;

        const printerGroup = new THREE.Group();

        // Chasis de la impresora
        const bodyGeo = new THREE.BoxGeometry(2.4, 1.4, 2.0);
        const bodyMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.5 });
        const body = new THREE.Mesh(bodyGeo, bodyMat);
        body.position.y = 0.7;
        printerGroup.add(body);

        // Bandeja de salida negra
        const trayGeo = new THREE.BoxGeometry(1.6, 0.25, 1.2);
        const trayMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.8 });
        const tray = new THREE.Mesh(trayGeo, trayMat);
        tray.position.set(0, 0.9, -1.0);
        printerGroup.add(tray);

        // Hojas de papel volando
        const paperGeo = new THREE.PlaneGeometry(1.2, 0.9);
        const paperMat = new THREE.MeshBasicMaterial({ color: 0xffffff, side: THREE.DoubleSide });
        const paper = new THREE.Mesh(paperGeo, paperMat);
        paper.rotation.x = -Math.PI / 3;
        paper.position.set(0, 1.15, -0.9);
        printerGroup.add(paper);

        // Pantalla LCD dinámica con Error 404 o Falta de hojas
        const scrCanvas = document.createElement('canvas');
        scrCanvas.width = 128; scrCanvas.height = 64;
        const sCtx = scrCanvas.getContext('2d');
        sCtx.fillStyle = '#0f172a'; sCtx.fillRect(0, 0, 128, 64);
        if (p.msgTipo === 'hojas') {
            sCtx.fillStyle = '#38bdf8'; sCtx.font = 'bold 15px monospace'; sCtx.textAlign = 'center';
            sCtx.fillText('SIN HOJAS', 64, 26);
            sCtx.fillStyle = '#facc15'; sCtx.font = 'bold 9px sans-serif';
            sCtx.fillText('FALTARON HOJAS', 64, 46);
        } else {
            sCtx.fillStyle = '#ef4444'; sCtx.font = 'bold 18px monospace'; sCtx.textAlign = 'center';
            sCtx.fillText('ERR 404', 64, 28);
            sCtx.fillStyle = '#f59e0b'; sCtx.font = 'bold 12px sans-serif';
            sCtx.fillText('ATASCO', 64, 48);
        }
        const scrTex = new THREE.CanvasTexture(scrCanvas);
        const scr = new THREE.Mesh(new THREE.PlaneGeometry(0.9, 0.45), new THREE.MeshBasicMaterial({ map: scrTex }));
        scr.position.set(0.6, 1.42, 0.4);
        scr.rotation.x = -Math.PI / 4;
        printerGroup.add(scr);

        // Luz LED roja intermitente
        const led = new THREE.Mesh(new THREE.SphereGeometry(0.12, 8, 8), new THREE.MeshBasicMaterial({ color: 0xef4444 }));
        led.position.set(-0.7, 1.42, 0.4);
        printerGroup.add(led);

        printerGroup.position.set(posX, 0, posZ);
        printerGroup.rotation.y = Math.random() * Math.PI * 2;
        scene3D.add(printerGroup);

        obstacles3D.push({
            tipo: 'impresora',
            msgTipo: p.msgTipo,
            x: posX,
            z: posZ,
            radius: 2.2,
            group: printerGroup,
            led: led
        });
    });

    // 2. Cables de Red Ethernet UTP (Bobinas y rollos azules con conectores RJ45)
    const posCables = [
        { idx: 45, offsetLat: -2.5 },
        { idx: 92, offsetLat: 3.0 },
        { idx: 132, offsetLat: 0.0 }
    ];

    posCables.forEach(c => {
        const pt = trackPoints3D[c.idx % trackPoints3D.length];
        const nextPt = trackPoints3D[(c.idx + 1) % trackPoints3D.length];
        const dx = nextPt.x - pt.x;
        const dz = nextPt.z - pt.z;
        const len = Math.hypot(dx, dz) || 1;
        const nx = -dz / len;
        const nz = dx / len;

        const posX = pt.x + nx * c.offsetLat;
        const posZ = pt.z + nz * c.offsetLat;

        const cableGroup = new THREE.Group();

        // Rollos de cable UTP azul
        const torusMat = new THREE.MeshStandardMaterial({ color: 0x0284c7, roughness: 0.6 });
        for (let r = 0; r < 3; r++) {
            const cableTorus = new THREE.Mesh(new THREE.TorusGeometry(1.2 + (r * 0.4), 0.16, 8, 18), torusMat);
            cableTorus.rotation.x = Math.PI / 2;
            cableTorus.position.set((r - 1) * 0.5, 0.14, (r % 2 === 0 ? 0.3 : -0.3));
            cableGroup.add(cableTorus);
        }

        // Conectores RJ45 amarillos
        const rjMat = new THREE.MeshStandardMaterial({ color: 0xfacc15, metalness: 0.6 });
        const rj1 = new THREE.Mesh(new THREE.BoxGeometry(0.35, 0.25, 0.5), rjMat);
        rj1.position.set(1.4, 0.15, 0.8);
        cableGroup.add(rj1);

        cableGroup.position.set(posX, 0, posZ);
        scene3D.add(cableGroup);

        obstacles3D.push({
            tipo: 'cable_red',
            x: posX,
            z: posZ,
            radius: 2.4,
            group: cableGroup
        });
    });

    // 3. Puntos de Acceso WiFi (Access Points - AP UniFi / Cisco)
    const posAPs = [
        { idx: 50, offsetLat: 2.8 },
        { idx: 98, offsetLat: -2.8 },
        { idx: 155, offsetLat: 2.2 }
    ];

    posAPs.forEach(a => {
        const pt = trackPoints3D[a.idx % trackPoints3D.length];
        const nextPt = trackPoints3D[(a.idx + 1) % trackPoints3D.length];
        const dx = nextPt.x - pt.x;
        const dz = nextPt.z - pt.z;
        const len = Math.hypot(dx, dz) || 1;
        const nx = -dz / len;
        const nz = dx / len;

        const posX = pt.x + nx * a.offsetLat;
        const posZ = pt.z + nz * a.offsetLat;

        const apGroup = new THREE.Group();

        // Mástil soporte
        const poleGeo = new THREE.CylinderGeometry(0.12, 0.16, 2.6, 8);
        const poleMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.8 });
        const pole = new THREE.Mesh(poleGeo, poleMat);
        pole.position.y = 1.3;
        apGroup.add(pole);

        // Plato AP UniFi blanco
        const plateGeo = new THREE.CylinderGeometry(1.2, 1.3, 0.35, 16);
        const plateMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.3 });
        const plate = new THREE.Mesh(plateGeo, plateMat);
        plate.position.y = 2.6;
        plate.rotation.x = Math.PI / 6;
        apGroup.add(plate);

        // Anillo LED circular en el plato
        const ringGeo = new THREE.TorusGeometry(1.15, 0.08, 8, 20);
        const ringMat = new THREE.MeshBasicMaterial({ color: 0x00d2ff });
        const ring = new THREE.Mesh(ringGeo, ringMat);
        ring.position.y = 2.65;
        ring.rotation.x = Math.PI / 6;
        apGroup.add(ring);

        // Antenas WiFi gemelas
        for (let ant = -1; ant <= 1; ant += 2) {
            const antMesh = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 1.1, 6), new THREE.MeshStandardMaterial({ color: 0x0f172a }));
            antMesh.position.set(ant * 0.8, 3.2, 0);
            antMesh.rotation.z = ant * 0.25;
            apGroup.add(antMesh);
        }

        // Rótulo Flotante: "AP WIFI 📶"
        const lblCanvas = document.createElement('canvas');
        lblCanvas.width = 128; lblCanvas.height = 64;
        const lCtx = lblCanvas.getContext('2d');
        lCtx.fillStyle = '#0284c7'; lCtx.fillRect(0, 0, 128, 64);
        lCtx.fillStyle = '#ffffff'; lCtx.font = 'bold 16px sans-serif'; lCtx.textAlign = 'center';
        lCtx.fillText('AP WIFI', 64, 28);
        lCtx.font = 'bold 12px monospace';
        lCtx.fillText('INTERNET', 64, 48);
        const lblTex = new THREE.CanvasTexture(lblCanvas);
        const lblMesh = new THREE.Mesh(new THREE.PlaneGeometry(1.4, 0.7), new THREE.MeshBasicMaterial({ map: lblTex, side: THREE.DoubleSide }));
        lblMesh.position.set(0, 3.5, 0);
        apGroup.add(lblMesh);

        apGroup.position.set(posX, 0, posZ);
        scene3D.add(apGroup);

        obstacles3D.push({
            tipo: 'ap_wifi',
            x: posX,
            z: posZ,
            radius: 2.6,
            group: apGroup,
            ringLed: ring
        });
    });
}

// AP Flotante que se monta encima del vehículo al chocar con un AP
function crearAPFlotanteMesh() {
    const apGroup = new THREE.Group();

    // Disco AP UniFi
    const discGeo = new THREE.CylinderGeometry(0.7, 0.75, 0.18, 16);
    const discMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.3 });
    const disc = new THREE.Mesh(discGeo, discMat);
    apGroup.add(disc);

    // Anillo LED
    const ringGeo = new THREE.TorusGeometry(0.68, 0.05, 8, 20);
    const ringMat = new THREE.MeshBasicMaterial({ color: 0xef4444 });
    const ring = new THREE.Mesh(ringGeo, ringMat);
    ring.rotation.x = Math.PI / 2;
    apGroup.add(ring);

    // Arcos WiFi flotantes
    for (let a = 1; a <= 3; a++) {
        const arcGeo = new THREE.TorusGeometry(0.25 * a, 0.04, 6, 16, Math.PI * 0.7);
        const arcMat = new THREE.MeshBasicMaterial({ color: 0xf59e0b });
        const arc = new THREE.Mesh(arcGeo, arcMat);
        arc.position.set(0, 0.25 + a * 0.22, 0);
        arc.rotation.z = Math.PI * 0.65;
        apGroup.add(arc);
    }

    // Cartel "📶 INTERNET LENTO"
    const signCanvas = document.createElement('canvas');
    signCanvas.width = 256; signCanvas.height = 64;
    const sCtx = signCanvas.getContext('2d');
    sCtx.fillStyle = 'rgba(15, 23, 42, 0.9)';
    sCtx.roundRect(4, 4, 248, 56, 12);
    sCtx.fill();
    sCtx.strokeStyle = '#ef4444'; sCtx.lineWidth = 3;
    sCtx.roundRect(4, 4, 248, 56, 12);
    sCtx.stroke();
    sCtx.fillStyle = '#f87171'; sCtx.font = 'bold 22px sans-serif'; sCtx.textAlign = 'center';
    sCtx.fillText('📶 INTERNET LENTO', 128, 38);
    const signTex = new THREE.CanvasTexture(signCanvas);
    const signMesh = new THREE.Mesh(new THREE.PlaneGeometry(2.2, 0.55), new THREE.MeshBasicMaterial({ map: signTex, transparent: true, side: THREE.DoubleSide }));
    signMesh.position.set(0, 1.45, 0);
    apGroup.add(signMesh);

    apGroup.visible = false;
    scene3D.add(apGroup);
    return apGroup;
}

// Árboles Cartoon 3D
function construirArbolCartoon(x, z) {
    const tree = new THREE.Group();
    const trunkGeo = new THREE.CylinderGeometry(0.8, 1.2, 4, 8);
    const trunkMat = new THREE.MeshLambertMaterial({ color: 0x5d4037 });
    const trunk = new THREE.Mesh(trunkGeo, trunkMat);
    trunk.position.y = 2;
    tree.add(trunk);

    const leavesGeo = new THREE.DodecahedronGeometry(3.5, 1);
    const leavesMat = new THREE.MeshLambertMaterial({ color: 0x22c55e });
    const leaves = new THREE.Mesh(leavesGeo, leavesMat);
    leaves.position.y = 5.5;
    tree.add(leaves);

    tree.position.set(x, 0, z);
    scene3D.add(tree);
}

// Farolas Neón
function construirFarolaNeón(x, z) {
    const poleGeo = new THREE.CylinderGeometry(0.2, 0.3, 10, 8);
    const poleMat = new THREE.MeshStandardMaterial({ color: 0x334155 });
    const pole = new THREE.Mesh(poleGeo, poleMat);
    pole.position.set(x, 5, z);
    scene3D.add(pole);

    const lightMesh = new THREE.Mesh(new THREE.SphereGeometry(0.8, 8, 8), new THREE.MeshBasicMaterial({ color: 0x00d2ff }));
    lightMesh.position.set(x, 10, z);
    scene3D.add(lightMesh);
}

// Molino de Viento para El Rancho
function construirMolinoViento(x, z) {
    const mill = new THREE.Group();
    const baseGeo = new THREE.CylinderGeometry(2, 4, 12, 8);
    const baseMat = new THREE.MeshLambertMaterial({ color: 0x8d6e63 });
    const base = new THREE.Mesh(baseGeo, baseMat);
    base.position.y = 6;
    mill.add(base);

    const bladeGeo = new THREE.BoxGeometry(0.6, 12, 0.2);
    const bladeMat = new THREE.MeshLambertMaterial({ color: 0xffffff });
    const b1 = new THREE.Mesh(bladeGeo, bladeMat); b1.position.y = 11; mill.add(b1);
    const b2 = new THREE.Mesh(bladeGeo, bladeMat); b2.position.y = 11; b2.rotation.z = Math.PI / 2; mill.add(b2);

    mill.position.set(x, 0, z);
    scene3D.add(mill);
}

// ==============================================================
// LANZADOR DE PROYECTILES 3D: LAPTOPS DE SISTEMAS (BSOD CRASH)
// ==============================================================
function crearMeshLaptop3D() {
    const lapGroup = new THREE.Group();

    // 1. Chasis Base (Teclado)
    const baseGeo = new THREE.BoxGeometry(1.6, 0.1, 1.1);
    const baseMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.4, metalness: 0.8 });
    const base = new THREE.Mesh(baseGeo, baseMat);
    lapGroup.add(base);

    // Teclado con relieve
    const kbGeo = new THREE.PlaneGeometry(1.4, 0.85);
    const kbMat = new THREE.MeshBasicMaterial({ color: 0x27272a });
    const kb = new THREE.Mesh(kbGeo, kbMat);
    kb.rotation.x = -Math.PI / 2;
    kb.position.set(0, 0.06, 0.05);
    lapGroup.add(kb);

    // TrackPoint rojo característico de laptops de Sistemas
    const tp = new THREE.Mesh(new THREE.SphereGeometry(0.04, 6, 6), new THREE.MeshBasicMaterial({ color: 0xef4444 }));
    tp.position.set(0, 0.07, 0.05);
    lapGroup.add(tp);

    // 2. Tapa y Pantalla Abierta
    const screenPivot = new THREE.Group();
    screenPivot.position.set(0, 0.05, -0.5);

    const lid = new THREE.Mesh(new THREE.BoxGeometry(1.6, 1.05, 0.07), baseMat);
    lid.position.set(0, 0.5, 0);
    screenPivot.add(lid);

    // Textura Pantalla Azul (BSOD)
    const scrCanvas = document.createElement('canvas');
    scrCanvas.width = 128; scrCanvas.height = 80;
    const sCtx = scrCanvas.getContext('2d');
    sCtx.fillStyle = '#0050ef'; sCtx.fillRect(0, 0, 128, 80);
    sCtx.fillStyle = '#ffffff'; sCtx.font = 'bold 22px sans-serif';
    sCtx.fillText(':(', 14, 28);
    sCtx.font = 'bold 10px monospace';
    sCtx.fillText('CRASH 404', 14, 46);
    sCtx.font = '8px monospace';
    sCtx.fillText('SYS_MEM_DUMP', 14, 62);
    const scrTex = new THREE.CanvasTexture(scrCanvas);

    const scr = new THREE.Mesh(new THREE.PlaneGeometry(1.45, 0.9), new THREE.MeshBasicMaterial({ map: scrTex }));
    scr.position.set(0, 0.5, 0.04);
    screenPivot.add(scr);

    // Inclinación de la pantalla abierta (115°)
    screenPivot.rotation.x = -0.45;
    lapGroup.add(screenPivot);

    // Resplandor de energía cibernética
    const glowGeo = new THREE.PlaneGeometry(1.8, 1.8);
    const glowMat = new THREE.MeshBasicMaterial({ color: 0xa855f7, transparent: true, opacity: 0.45, side: THREE.DoubleSide });
    const glow = new THREE.Mesh(glowGeo, glowMat);
    glow.rotation.x = -Math.PI / 2;
    lapGroup.add(glow);

    return lapGroup;
}

function dispararLaptop3D() {
    if (!scene3D) return;

    const lapMesh = crearMeshLaptop3D();
    const spawnDist = 3.6;
    const startX = carState.x - Math.sin(carState.angle) * spawnDist;
    const startZ = carState.z - Math.cos(carState.angle) * spawnDist;

    lapMesh.position.set(startX, 1.25, startZ);
    scene3D.add(lapMesh);

    const speedUnits = 2.1; // Velocidad de proyectil
    const vx = -Math.sin(carState.angle) * speedUnits;
    const vz = -Math.cos(carState.angle) * speedUnits;

    laptopProjectiles3D.push({
        group: lapMesh,
        x: startX,
        z: startZ,
        vx: vx,
        vz: vz,
        life: 140
    });
}

// ==============================================================
// COMPETIDORES Y RIVALES IA DE LAS AGENCIAS GRUPO HUERTA
// ==============================================================
function crearMeshRival(tipoVeh, colorHex, nombre) {
    const group = new THREE.Group();
    const mat = new THREE.MeshStandardMaterial({ color: colorHex, metalness: 0.8, roughness: 0.3 });
    const esMoto = tipoVeh.startsWith('moto');

    if (!esMoto) {
        // Chasis del auto rival
        const body = new THREE.Mesh(new THREE.BoxGeometry(2.1, 0.7, 4.2), mat);
        body.position.y = 0.55;
        group.add(body);

        // Cabina
        const cab = new THREE.Mesh(new THREE.BoxGeometry(1.7, 0.65, 2.0), new THREE.MeshStandardMaterial({ color: 0x0a1d37, roughness: 0.2 }));
        cab.position.set(0, 1.1, 0);
        group.add(cab);

        // Alerón trasero
        const wing = new THREE.Mesh(new THREE.BoxGeometry(2.0, 0.1, 0.5), mat);
        wing.position.set(0, 1.0, 1.9);
        group.add(wing);

        // 4 Ruedas
        const wGeo = new THREE.CylinderGeometry(0.48, 0.48, 0.32, 12);
        const wMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.9 });
        [[-1.1, 0.48, 1.3], [1.1, 0.48, 1.3], [-1.1, 0.48, -1.3], [1.1, 0.48, -1.3]].forEach(pos => {
            const w = new THREE.Mesh(wGeo, wMat);
            w.rotation.z = Math.PI / 2;
            w.position.set(pos[0], pos[1], pos[2]);
            group.add(w);
        });

        // Faros delanteros
        const flMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
        const fl1 = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.2, 0.1), flMat); fl1.position.set(-0.7, 0.6, -2.1); group.add(fl1);
        const fl2 = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.2, 0.1), flMat); fl2.position.set(0.7, 0.6, -2.1); group.add(fl2);
    } else {
        // Moto rival
        const frame = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.9, 2.8), mat);
        frame.position.y = 0.8;
        group.add(frame);

        // 2 Ruedas de moto
        const wGeo = new THREE.CylinderGeometry(0.55, 0.55, 0.25, 12);
        const wMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.9 });
        const wr1 = new THREE.Mesh(wGeo, wMat); wr1.rotation.z = Math.PI / 2; wr1.position.set(0, 0.55, -1.2); group.add(wr1);
        const wr2 = new THREE.Mesh(wGeo, wMat); wr2.rotation.z = Math.PI / 2; wr2.position.set(0, 0.55, 1.2); group.add(wr2);

        // Piloto rival
        const rider = new THREE.Mesh(new THREE.SphereGeometry(0.35, 8, 8), new THREE.MeshLambertMaterial({ color: colorHex }));
        rider.position.set(0, 1.5, -0.2);
        group.add(rider);
    }

    // Cartel flotante con nombre de la agencia
    const nCanvas = document.createElement('canvas');
    nCanvas.width = 256; nCanvas.height = 64;
    const nCtx = nCanvas.getContext('2d');
    nCtx.fillStyle = 'rgba(2, 6, 23, 0.85)';
    nCtx.roundRect(4, 4, 248, 56, 12);
    nCtx.fill();
    nCtx.strokeStyle = '#' + new THREE.Color(colorHex).getHexString();
    nCtx.lineWidth = 3;
    nCtx.roundRect(4, 4, 248, 56, 12);
    nCtx.stroke();
    nCtx.fillStyle = '#ffffff'; nCtx.font = 'bold 20px sans-serif'; nCtx.textAlign = 'center';
    nCtx.fillText(nombre, 128, 38);
    const nTex = new THREE.CanvasTexture(nCanvas);
    const nMesh = new THREE.Mesh(new THREE.PlaneGeometry(2.4, 0.6), new THREE.MeshBasicMaterial({ map: nTex, transparent: true, side: THREE.DoubleSide }));
    nMesh.position.set(0, 2.3, 0);
    group.add(nMesh);

    return group;
}

function crearRivales3D() {
    if (!scene3D) return;

    // Limpiar anteriores si existían
    rivales3D.forEach(r => {
        if (r.mesh) scene3D.remove(r.mesh);
    });
    rivales3D = [];

    const defsRivales = [
        {
            nombre: 'Jetta #4 Angelópolis',
            vehiculo: 'auto_jetta',
            color: 0x0284c7,
            idxInicial: 35,
            laneOffset: -3.2,
            speedKmh: 94
        },
        {
            nombre: 'Cupra #7 Garage Serdán',
            vehiculo: 'auto_cupra',
            color: 0xd97706,
            idxInicial: 80,
            laneOffset: 3.2,
            speedKmh: 100
        },
        {
            nombre: 'Ninja #99 Motos Huerta',
            vehiculo: 'moto_kawasaki',
            color: 0x16a34a,
            idxInicial: 125,
            laneOffset: 0.0,
            speedKmh: 104
        }
    ];

    defsRivales.forEach(def => {
        const mesh = crearMeshRival(def.vehiculo, def.color, def.nombre);
        scene3D.add(mesh);

        rivales3D.push({
            nombre: def.nombre,
            vehiculo: def.vehiculo,
            color: def.color,
            mesh: mesh,
            trackIdx: def.idxInicial,
            subProgress: 0,
            laneOffset: def.laneOffset,
            speed: def.speedKmh,
            baseSpeed: def.speedKmh,
            spinTimer: 0,
            lap: 1,
            x: 0,
            z: 0
        });
    });
}

// ==============================================================
// CONSTRUCTOR DE VEHÍCULOS OFICIALES GRUPO HUERTA (3D DETALLADO)
// JETTA • OMODA C5 • CUPRA FORMENTOR • SUZUKI GSX-R • KAWASAKI NINJA
// ==============================================================
function construirVehiculo3D() {
    if (vehiculo3D) scene3D.remove(vehiculo3D);

    vehiculo3D = new THREE.Group();
    escapeFlames = [];

    const tipoVeh = avatarConfig.vehiculo || 'auto_jetta';
    const esMoto = tipoVeh.startsWith('moto');

    if (!esMoto) {
        // ==========================================================
        // AUTOS GRUPO HUERTA: JETTA, OMODA C5 O CUPRA FORMENTOR
        // ==========================================================
        const carGroup = new THREE.Group();
        const isCupra = (tipoVeh === 'auto_cupra');
        const isOmoda = (tipoVeh === 'auto_omoda');
        const isJetta = (!isCupra && !isOmoda);

        let colorCarroceria = new THREE.Color(avatarConfig.colorTraje).getHex();
        if (isCupra) colorCarroceria = 0x1b2838; // Azul Petróleo / Grafito mate característico de Cupra
        
        const bodyMat = new THREE.MeshStandardMaterial({ 
            color: colorCarroceria, 
            metalness: 0.85, 
            roughness: 0.25 
        });

        // 1. Chasis Inferior
        const baseGeo = new THREE.BoxGeometry(2.0, 0.35, 4.2);
        const baseMesh = new THREE.Mesh(baseGeo, new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.8 }));
        baseMesh.position.y = 0.35;
        carGroup.add(baseMesh);

        if (isJetta) {
            // ==================== VOLKSWAGEN JETTA SPORTLINE ====================
            // Capó alargado de sedán
            const hood = new THREE.Mesh(new THREE.BoxGeometry(1.95, 0.35, 1.6), bodyMat);
            hood.position.set(0, 0.65, -1.2);
            carGroup.add(hood);

            // Cabina de Sedán con parabrisas inclinado
            const cabin = new THREE.Mesh(new THREE.BoxGeometry(1.75, 0.65, 1.8), new THREE.MeshStandardMaterial({ color: 0x0a1d37, roughness: 0.1, metalness: 0.9 }));
            cabin.position.set(0, 1.05, 0.2);
            carGroup.add(cabin);

            // Techo de color carrocería
            const roof = new THREE.Mesh(new THREE.BoxGeometry(1.65, 0.1, 1.5), bodyMat);
            roof.position.set(0, 1.4, 0.2);
            carGroup.add(roof);

            // Maletero de sedán con alerón tipo lip
            const trunk = new THREE.Mesh(new THREE.BoxGeometry(1.9, 0.4, 1.0), bodyMat);
            trunk.position.set(0, 0.65, 1.55);
            carGroup.add(trunk);

            // Parrilla delantera cromada con emblema VW
            const grille = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.3, 0.2), new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.95 }));
            grille.position.set(0, 0.65, -2.05);
            carGroup.add(grille);

            // Línea roja deportiva GLI en fascia delantera
            const redLine = new THREE.Mesh(new THREE.BoxGeometry(1.7, 0.08, 0.2), new THREE.MeshBasicMaterial({ color: 0xef4444 }));
            redLine.position.set(0, 0.45, -2.06);
            carGroup.add(redLine);

            // Faros LED afilados Jetta
            const fLightMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
            const flL = new THREE.Mesh(new THREE.BoxGeometry(0.45, 0.18, 0.2), fLightMat); flL.position.set(-0.7, 0.68, -2.05); carGroup.add(flL);
            const flR = new THREE.Mesh(new THREE.BoxGeometry(0.45, 0.18, 0.2), fLightMat); flR.position.set(0.7, 0.68, -2.05); carGroup.add(flR);

        } else if (isOmoda) {
            // ==================== OMODA C5 CROSSOVER ====================
            // Carrocería SUV Coupé
            const bodyOmoda = new THREE.Mesh(new THREE.BoxGeometry(2.1, 0.55, 3.9), bodyMat);
            bodyOmoda.position.set(0, 0.75, 0);
            carGroup.add(bodyOmoda);

            // Techo Flotante con pilares negros
            const roofOmoda = new THREE.Mesh(new THREE.BoxGeometry(1.7, 0.6, 2.1), new THREE.MeshStandardMaterial({ color: 0x050d1a, roughness: 0.2 }));
            roofOmoda.position.set(0, 1.25, 0.1);
            carGroup.add(roofOmoda);

            // Doble alerón trasero deportivo Omoda
            const spoilerOmoda = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.15, 0.6), new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.5 }));
            spoilerOmoda.position.set(0, 1.55, 1.3);
            carGroup.add(spoilerOmoda);

            // Parrilla Diamante Paramétrica Omoda (Textura de tachuelas)
            const oGrille = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.55, 0.2), new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8 }));
            oGrille.position.set(0, 0.7, -2.0);
            carGroup.add(oGrille);

            // DRLs delgados en T Omoda
            const drlMat = new THREE.MeshBasicMaterial({ color: 0x00d2ff });
            const drlL = new THREE.Mesh(new THREE.BoxGeometry(0.5, 0.08, 0.2), drlMat); drlL.position.set(-0.75, 0.95, -2.02); carGroup.add(drlL);
            const drlR = new THREE.Mesh(new THREE.BoxGeometry(0.5, 0.08, 0.2), drlMat); drlR.position.set(0.75, 0.95, -2.02); carGroup.add(drlR);

        } else if (isCupra) {
            // ==================== CUPRA FORMENTOR ====================
            // Carrocería deportiva musculosa
            const bodyCupra = new THREE.Mesh(new THREE.BoxGeometry(2.15, 0.5, 4.1), bodyMat);
            bodyCupra.position.set(0, 0.72, 0);
            carGroup.add(bodyCupra);

            // Cabina baja deportiva con lunas tintadas
            const cabinCupra = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.6, 2.0), new THREE.MeshStandardMaterial({ color: 0x091424, roughness: 0.1, metalness: 0.85 }));
            cabinCupra.position.set(0, 1.15, 0.15);
            carGroup.add(cabinCupra);

            // EMBLEMA TRIBAL CUPRA COLOR COBRE / BRONCE
            const copperMat = new THREE.MeshStandardMaterial({ color: 0xd97706, metalness: 0.95, roughness: 0.2 });
            const emblemCupra = new THREE.Mesh(new THREE.ConeGeometry(0.25, 0.4, 3), copperMat);
            emblemCupra.rotation.x = Math.PI / 2;
            emblemCupra.position.set(0, 0.72, -2.08);
            carGroup.add(emblemCupra);

            // Retrovisores y tomas de aire con acentos de cobre
            const mirrorL = new THREE.Mesh(new THREE.BoxGeometry(0.25, 0.15, 0.2), copperMat); mirrorL.position.set(-1.15, 1.1, -0.4); carGroup.add(mirrorL);
            const mirrorR = new THREE.Mesh(new THREE.BoxGeometry(0.25, 0.15, 0.2), copperMat); mirrorR.position.set(1.15, 1.1, -0.4); carGroup.add(mirrorR);

            // Luz trasera LED continua "coast-to-coast"
            const lightBar = new THREE.Mesh(new THREE.BoxGeometry(1.9, 0.1, 0.15), new THREE.MeshBasicMaterial({ color: 0xef4444 }));
            lightBar.position.set(0, 0.9, 2.05);
            carGroup.add(lightBar);
        }

        // 4 Ruedas de Aleación Deportiva con Cálipers
        const tireMat = new THREE.MeshStandardMaterial({ color: 0x111827, roughness: 0.9 });
        const tireGeo = new THREE.CylinderGeometry(0.42, 0.42, 0.36, 18);
        tireGeo.rotateZ(Math.PI / 2);

        ruedaDelanteraIzq = new THREE.Mesh(tireGeo, tireMat); ruedaDelanteraIzq.position.set(-1.15, 0.42, -1.25); carGroup.add(ruedaDelanteraIzq);
        ruedaDelanteraDer = new THREE.Mesh(tireGeo, tireMat); ruedaDelanteraDer.position.set(1.15, 0.42, -1.25); carGroup.add(ruedaDelanteraDer);

        const rWheelL = new THREE.Mesh(tireGeo, tireMat); rWheelL.position.set(-1.15, 0.42, 1.25); carGroup.add(rWheelL);
        const rWheelR = new THREE.Mesh(tireGeo, tireMat); rWheelR.position.set(1.15, 0.42, 1.25); carGroup.add(rWheelR);

        // Doble Escape con Fuego de Nitro
        const pipeMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9, metalness: 0.95 });
        const pL = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.14, 0.6, 8), pipeMat); pL.rotation.x = Math.PI / 2; pL.position.set(-0.5, 0.42, 2.15); carGroup.add(pL);
        const pR = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.14, 0.6, 8), pipeMat); pR.rotation.x = Math.PI / 2; pR.position.set(0.5, 0.42, 2.15); carGroup.add(pR);

        const flMat = new THREE.MeshBasicMaterial({ color: 0x00d2ff, transparent: true, opacity: 0 });
        const flL = new THREE.Mesh(new THREE.ConeGeometry(0.2, 1.2, 8), flMat); flL.rotation.x = -Math.PI / 2; flL.position.set(-0.5, 0.42, 2.9); carGroup.add(flL);
        const flR = new THREE.Mesh(new THREE.ConeGeometry(0.2, 1.2, 8), flMat); flR.rotation.x = -Math.PI / 2; flR.position.set(0.5, 0.42, 2.9); carGroup.add(flR);
        escapeFlames.push(flL, flR);

        vehiculo3D.add(carGroup);

    } else {
        // ==========================================================
        // MOTOS SUPERBIKE GRUPO HUERTA: SUZUKI GSX-R & KAWASAKI NINJA
        // ==========================================================
        const bikeGroup = new THREE.Group();
        const esKawa = (tipoVeh === 'moto_kawasaki');
        const colorCarenado = esKawa ? 0x22c55e : 0x0284c7; // Verde KRT o Azul Ecstar

        // 1. Chasis Perimetral de Doble Viga de Aluminio
        const frameMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.95, roughness: 0.2 });
        const frameL = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.35, 1.8), frameMat); frameL.position.set(-0.25, 0.95, 0); bikeGroup.add(frameL);
        const frameR = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.35, 1.8), frameMat); frameR.position.set(0.25, 0.95, 0); bikeGroup.add(frameR);

        // 2. Carenado Frontal Aerodinámico de Pista
        const fairingMat = new THREE.MeshStandardMaterial({ color: colorCarenado, metalness: 0.85, roughness: 0.2 });
        const noseCowl = new THREE.Mesh(new THREE.ConeGeometry(0.45, 1.8, 8), fairingMat);
        noseCowl.rotation.x = -Math.PI / 2;
        noseCowl.position.set(0, 1.15, -1.1);
        bikeGroup.add(noseCowl);

        // Alerones Winglets en Kawasaki Ninja
        if (esKawa) {
            const wingletMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.5 });
            const wL = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.06, 0.3), wingletMat); wL.position.set(-0.45, 1.1, -1.0); bikeGroup.add(wL);
            const wR = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.06, 0.3), wingletMat); wR.position.set(0.45, 1.1, -1.0); bikeGroup.add(wR);
        }

        // Cúpula / Parabrisas transparente tintado
        const screenMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.1, transparent: true, opacity: 0.85 });
        const windscreen = new THREE.Mesh(new THREE.BoxGeometry(0.38, 0.35, 0.7), screenMat);
        windscreen.position.set(0, 1.48, -0.6);
        windscreen.rotation.x = -0.3;
        bikeGroup.add(windscreen);

        // Tanque de Gasolina ergonómico y Colín Monoplaza
        const tank = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.65, 1.2), fairingMat);
        tank.position.set(0, 1.2, -0.1);
        bikeGroup.add(tank);

        const tail = new THREE.Mesh(new THREE.ConeGeometry(0.35, 1.3, 6), fairingMat);
        tail.rotation.x = Math.PI / 2.2;
        tail.position.set(0, 1.25, 0.95);
        bikeGroup.add(tail);

        // 3. Horquilla Telescópica Dorada (Showa / Öhlins)
        const forkMat = new THREE.MeshStandardMaterial({ color: 0xeab308, metalness: 0.9, roughness: 0.2 });
        const forkL = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 1.4, 8), forkMat); forkL.position.set(-0.2, 0.75, -1.2); forkL.rotation.x = -0.25; bikeGroup.add(forkL);
        const forkR = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 1.4, 8), forkMat); forkR.position.set(0.2, 0.75, -1.2); forkR.rotation.x = -0.25; bikeGroup.add(forkR);

        // 4. Llantas Superbike con Frenos de Disco
        const bWheelMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.9 });
        const bWheelGeo = new THREE.CylinderGeometry(0.55, 0.55, 0.28, 20);
        bWheelGeo.rotateZ(Math.PI / 2);

        ruedaDelanteraIzq = new THREE.Mesh(bWheelGeo, bWheelMat);
        ruedaDelanteraIzq.position.set(0, 0.55, -1.4);
        bikeGroup.add(ruedaDelanteraIzq);
        ruedaDelanteraDer = ruedaDelanteraIzq;

        const rearWheel = new THREE.Mesh(bWheelGeo, bWheelMat);
        rearWheel.position.set(0, 0.55, 1.35);
        bikeGroup.add(rearWheel);

        // 5. Escape Deportivo Elevado (Yoshimura / Akrapovic)
        const exhaust = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.16, 1.2, 10), new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.9 }));
        exhaust.rotation.x = Math.PI / 2.3;
        exhaust.rotation.z = -0.15;
        exhaust.position.set(0.42, 0.75, 1.1);
        bikeGroup.add(exhaust);

        const flM = new THREE.Mesh(new THREE.ConeGeometry(0.18, 1.1, 8), new THREE.MeshBasicMaterial({ color: 0x00d2ff, transparent: true, opacity: 0 }));
        flM.rotation.x = -Math.PI / 2.3;
        flM.position.set(0.42, 0.75, 1.95);
        bikeGroup.add(flM);
        escapeFlames.push(flM);

        // 6. Piloto en posición aerodinámica de carrera (Tucked-in)
        const pilot = new THREE.Group();
        const leathersMat = new THREE.MeshStandardMaterial({ color: new THREE.Color(avatarConfig.colorTraje).getHex(), roughness: 0.8 });
        
        // Torso inclinado hacia adelante sobre el tanque
        const torso = new THREE.Mesh(new THREE.BoxGeometry(0.6, 0.5, 0.9), leathersMat);
        torso.position.set(0, 1.5, 0.15);
        torso.rotation.x = 0.45;
        pilot.add(torso);

        // Casco con visor de carreras
        const helmet = new THREE.Mesh(new THREE.SphereGeometry(0.34, 14, 14), leathersMat);
        helmet.position.set(0, 1.75, -0.3);
        pilot.add(helmet);

        const visor = new THREE.Mesh(new THREE.BoxGeometry(0.38, 0.14, 0.24), new THREE.MeshBasicMaterial({ color: 0x00d2ff }));
        visor.position.set(0, 1.75, -0.52);
        pilot.add(visor);

        bikeGroup.add(pilot);
        vehiculo3D.add(bikeGroup);
    }

    vehiculo3D.position.set(carState.x || 0, 0, carState.z || -80);
    vehiculo3D.rotation.y = carState.angle || (-Math.PI / 2);
    scene3D.add(vehiculo3D);
}

// ==============================================================
// MECÁNICA DE DERRAPE CON CHISPAS Y MINI-TURBO MARIO KART
// ==============================================================
function soltarDerrapeTurbo() {
    const indicator = document.getElementById('hud_drift_indicator');
    if (indicator) indicator.style.display = 'none';

    if (carState.currentTurboLevel === 2) {
        carState.turboTimer = 80; // Super Mini-Turbo
        carState.speed = Math.min(carState.speed + 35, 130);
        sfxTurbo();
    } else if (carState.currentTurboLevel === 1) {
        carState.turboTimer = 45; // Mini-Turbo
        carState.speed = Math.min(carState.speed + 20, 115);
        sfxTurbo();
    }

    carState.isDrifting = false;
    carState.driftTimer = 0;
    carState.currentTurboLevel = 0;
}

function agarrarCajaMisteriosa() {
    sfxItemBox();
    const itemsPosibles = ['turbo', 'laptop', 'turbo', 'laptop', 'escudo'];
    const itemElegido = itemsPosibles[Math.floor(Math.random() * itemsPosibles.length)];
    carState.itemActual = itemElegido;
    actualizarHUDItem();
}

function usarItem3D() {
    if (!carState.itemActual) return;

    if (carState.itemActual === 'turbo') {
        carState.turboTimer = 95;
        carState.speed = 145;
        sfxTurbo();
        mostrarBannerHUD('⚡ ¡SUPER TURBO ACTIVADO!', '#00d2ff');
    } else if (carState.itemActual === 'laptop') {
        dispararLaptop3D();
        sfxLanzarLaptop();
        mostrarBannerHUD('💻 ¡LANZASTE UNA LAPTOP DE SISTEMAS!', '#c084fc');
    } else if (carState.itemActual === 'escudo') {
        carState.itemTimer = 180;
        sfxVictoria();
        mostrarBannerHUD('🛡️ ¡ESCUDO FIREWALL ACTIVADO!', '#00ff88');
    }

    carState.itemActual = null;
    actualizarHUDItem();
}

function actualizarHUDItem() {
    const icon = document.getElementById('hud_item_icon');
    const box = document.getElementById('hud_item_box');
    if (!icon || !box) return;

    const iconos = { turbo: '🚀', laptop: '💻', escudo: '🛡️' };
    if (carState.itemActual) {
        icon.textContent = iconos[carState.itemActual] || '❓';
        if (carState.itemActual === 'laptop') {
            box.style.borderColor = '#c084fc';
            box.style.boxShadow = '0 0 30px rgba(192, 132, 252, 0.85)';
        } else if (carState.itemActual === 'turbo') {
            box.style.borderColor = '#00d2ff';
            box.style.boxShadow = '0 0 30px rgba(0, 210, 255, 0.85)';
        } else {
            box.style.borderColor = '#22c55e';
            box.style.boxShadow = '0 0 30px rgba(34, 197, 94, 0.85)';
        }
    } else {
        icon.textContent = '❓';
        box.style.borderColor = '#ffe600';
        box.style.boxShadow = '0 0 20px rgba(255, 230, 0, 0.45)';
    }
}

// ==============================================================
// BUCLE DE ANIMACIÓN Y FÍSICA CALIBRADA (VELOCIDAD REAL DE KART)
// ==============================================================
function animar3D() {
    animId3D = requestAnimationFrame(animar3D);

    const adelante = (keysPressed['KeyW'] || keysPressed['ArrowUp']);
    const atras = (keysPressed['KeyS'] || keysPressed['ArrowDown']);
    const izquierda = (keysPressed['KeyA'] || keysPressed['ArrowLeft']);
    const derecha = (keysPressed['KeyD'] || keysPressed['ArrowRight']);
    const barraEspacio = keysPressed['Space'];

    // 1. Manejo del Turbo con Efecto FOV
    if (carState.turboTimer > 0) {
        carState.turboTimer--;
        camera3D.fov = THREE.MathUtils.lerp(camera3D.fov, 78, 0.12);
    } else {
        camera3D.fov = THREE.MathUtils.lerp(camera3D.fov, 65, 0.08);
    }
    camera3D.updateProjectionMatrix();

    // 2. Aceleración Deportiva y Velocidad Calibrada (Top Speed: 118 km/h, Turbo: 158 km/h)
    let accelBase = (carState.turboTimer > 0 ? 0.95 : 0.62);
    let maxKmh = (carState.turboTimer > 0) ? 158 : 118;

    // Si tiene Internet Lento (AP sobre el auto): velocidad limitada
    if (carState.apLentoTimer > 0) {
        maxKmh = 48;
        accelBase = 0.28;
    }

    if (adelante) {
        carState.speed += accelBase;
    } else if (atras) {
        if (carState.speed > 5) carState.speed -= 1.35; // Freno enérgico
        else carState.speed -= 0.45; // Marcha atrás
    } else {
        carState.speed *= 0.984; // Fricción suave
    }

    if (carState.speed > maxKmh) carState.speed = maxKmh;
    if (carState.speed < -28) carState.speed = -28;

    // 3. Sistema de Derrape y Carga de Mini-Turbo
    if (barraEspacio && Math.abs(carState.speed) > 30 && (izquierda || derecha)) {
        carState.isDrifting = true;
        carState.driftTimer++;

        const indicator = document.getElementById('hud_drift_indicator');
        if (indicator) {
            indicator.style.display = 'block';
            if (carState.driftTimer > 70) {
                carState.currentTurboLevel = 2; // Super Mini-Turbo (Naranja)
                indicator.style.color = '#ff6b00';
                indicator.innerHTML = '🔥 SUPER MINI-TURBO!';
            } else if (carState.driftTimer > 30) {
                carState.currentTurboLevel = 1; // Mini-Turbo (Azul)
                indicator.style.color = '#00d2ff';
                indicator.innerHTML = '⚡ MINI-TURBO!';
            }
        }
        sfxDerrape();
    }

    // 4. Maniobrabilidad y Dirección (Giro Natural & Progresivo)
    if (carState.itemTimer > 0) {
        carState.itemTimer--;
        if (carState.itemTimer === 0) actualizarHUDItem();
    }

    if (carState.spinTimer > 0) {
        carState.spinTimer--;
        carState.angle += 0.32; // Trompo descontrolado por atasco de impresora
        carState.speed *= 0.93;
        carState.steerAngle = 0;
    } else {
        const factorGiro = Math.min(Math.abs(carState.speed) / 38, 1.0);
        const anguloGiro = (carState.isDrifting ? 0.052 : 0.038) * (carState.speed >= 0 ? 1 : -1);

        if (izquierda) {
            carState.angle += anguloGiro * factorGiro;
            carState.steerAngle = -0.34;
        } else if (derecha) {
            carState.angle -= anguloGiro * factorGiro;
            carState.steerAngle = 0.34;
        } else {
            carState.steerAngle = 0;
        }
    }

    // 4.5. Gestión del AP Flotante de Internet Lento sobre el vehículo
    if (carState.apLentoTimer > 0) {
        carState.apLentoTimer--;
        if (!carState.apMeshOverCar) {
            carState.apMeshOverCar = crearAPFlotanteMesh();
        }
        carState.apMeshOverCar.visible = true;
        carState.apMeshOverCar.position.set(carState.x, 3.4, carState.z);
        carState.apMeshOverCar.rotation.y += 0.06;
    } else if (carState.apMeshOverCar && carState.apMeshOverCar.visible) {
        carState.apMeshOverCar.visible = false;
        mostrarBannerHUD('✅ ¡INTERNET RESTABLECIDO!', '#00ff88');
    }

    // 5. Desplazamiento Físico Calibrado (Escala 1 unidad = 1 metro)
    // A 90 km/h (25 m/s): en 1 frame (0.016s) = 0.40 unidades/frame
    const velUnits = (carState.speed / 3.6) * 0.016;
    let nextX = carState.x - Math.sin(carState.angle) * velUnits;
    let nextZ = carState.z - Math.cos(carState.angle) * velUnits;

    // ==============================================================
    // 6. DETECCIÓN DE COLISIÓN CON PAREDES DE PISTA Y REBOTE EXACTO
    // ==============================================================
    let closestIdx = 0;
    if (trackPoints3D.length > 0) {
        let minDistSq = Infinity;

        for (let i = 0; i < trackPoints3D.length; i++) {
            const pt = trackPoints3D[i];
            const dSq = (nextX - pt.x) ** 2 + (nextZ - pt.z) ** 2;
            if (dSq < minDistSq) {
                minDistSq = dSq;
                closestIdx = i;
            }
        }

        const pA = trackPoints3D[closestIdx];
        const pB = trackPoints3D[(closestIdx + 1) % trackPoints3D.length];

        let segDx = pB.x - pA.x;
        let segDz = pB.z - pA.z;
        let segLen = Math.hypot(segDx, segDz);
        if (segLen < 0.001) segLen = 1;

        const dirX = segDx / segLen;
        const dirZ = segDz / segLen;
        const normX = -dirZ;
        const normZ = dirX;

        const relX = nextX - pA.x;
        const relZ = nextZ - pA.z;

        const longDist = relX * dirX + relZ * dirZ;
        const latDist  = relX * normX + relZ * normZ;

        const maxAllowedDist = 9.2; // Ancho de pista = 20 (mitad = 10, margen pared = 9.2)

        if (Math.abs(latDist) > maxAllowedDist) {
            // ¡ROCE O IMPACTO CONTRA EL MURO DE SEGURIDAD!
            const sign = Math.sign(latDist);
            const clampedLat = sign * maxAllowedDist;

            // Mantener al vehículo dentro de la pista siguiendo el muro
            nextX = pA.x + dirX * longDist + normX * clampedLat;
            nextZ = pA.z + dirZ * longDist + normZ * clampedLat;

            // Pérdida de velocidad y sonido de impacto al tocar la pared
            if (Math.abs(carState.speed) > 20) {
                sfxImpacto();
                carState.speed *= 0.65;
            }
        }
    }

    carState.x = nextX;
    carState.z = nextZ;

    if (vehiculo3D) {
        vehiculo3D.position.x = carState.x;
        vehiculo3D.position.z = carState.z;
        vehiculo3D.rotation.y = carState.angle;

        if (avatarConfig.vehiculo.startsWith('moto')) {
            const lean = (izquierda ? 0.44 : (derecha ? -0.44 : 0)) * (Math.abs(carState.speed) / 75);
            vehiculo3D.rotation.z = THREE.MathUtils.lerp(vehiculo3D.rotation.z, lean, 0.16);
        }

        if (ruedaDelanteraIzq) ruedaDelanteraIzq.rotation.y = carState.steerAngle;
        if (ruedaDelanteraDer && ruedaDelanteraDer !== ruedaDelanteraIzq) ruedaDelanteraDer.rotation.y = carState.steerAngle;

        // Llamaradas animadas en escapes
        const opFuego = (carState.turboTimer > 0) ? 0.95 : (adelante && carState.speed > 60 ? 0.45 : 0);
        escapeFlames.forEach(fl => {
            fl.material.opacity = opFuego;
            fl.scale.set(1 + Math.random() * 0.3, 1 + Math.random() * 0.4, 1);
        });

        // Cámara de persecución suave con distancia perfecta para ver la pista y paredes
        const distCam = avatarConfig.vehiculo.startsWith('moto') ? 8.2 : 9.6;
        const altoCam = avatarConfig.vehiculo.startsWith('moto') ? 4.2 : 4.8;
        const targetCamX = carState.x + Math.sin(carState.angle) * distCam;
        const targetCamZ = carState.z + Math.cos(carState.angle) * distCam;

        camera3D.position.x = THREE.MathUtils.lerp(camera3D.position.x, targetCamX, 0.12);
        camera3D.position.y = THREE.MathUtils.lerp(camera3D.position.y, altoCam, 0.12);
        camera3D.position.z = THREE.MathUtils.lerp(camera3D.position.z, targetCamZ, 0.12);
        camera3D.lookAt(carState.x, 1.4, carState.z);
    }

    // 7. Colisión con Paneles Aceleradores
    for (const pad of boostPads3D) {
        if (Math.hypot(carState.x - pad.x, carState.z - pad.z) < pad.radius) {
            if (carState.turboTimer <= 10) {
                carState.turboTimer = 65;
                carState.speed = 125;
                sfxTurbo();
            }
        }
    }

    // 8. Colisión y Animación de Cajas de Objetos
    for (const box of itemBoxes3D) {
        if (box.activo) {
            box.group.rotation.y += 0.04;
            box.group.position.y = 2.2 + Math.sin(performance.now() * 0.005) * 0.3;

            if (Math.hypot(carState.x - box.x, carState.z - box.z) < 3.2) {
                box.activo = false;
                box.group.visible = false;
                box.respawnTimer = 220;
                agarrarCajaMisteriosa();
            }
        } else {
            box.respawnTimer--;
            if (box.respawnTimer <= 0) {
                box.activo = true;
                box.group.visible = true;
            }
        }
    }

    // 8.5. Colisión con Obstáculos TI (Impresoras láser, Cables de Red y APs WiFi)
    if (carState.hitObstacleTimer > 0) {
        carState.hitObstacleTimer--;
    }
    for (const obs of obstacles3D) {
        if (!obs.group.visible) {
            if (obs.respawnTimer > 0) {
                obs.respawnTimer--;
                if (obs.respawnTimer === 0) obs.group.visible = true;
            }
            continue;
        }

        // Parpadeo dinámico del LED de error en impresoras o APs
        if (obs.tipo === 'impresora' && obs.led) {
            obs.led.material.color.setHex((Math.floor(performance.now() / 250) % 2 === 0) ? 0xef4444 : 0x222222);
        }
        if (obs.tipo === 'ap_wifi' && obs.ringLed) {
            obs.ringLed.material.color.setHex((Math.floor(performance.now() / 300) % 2 === 0) ? 0x00d2ff : 0xf59e0b);
        }

        if (carState.hitObstacleTimer === 0 && Math.hypot(carState.x - obs.x, carState.z - obs.z) < obs.radius) {
            carState.hitObstacleTimer = 45;
            if (carState.itemTimer > 0) {
                sfxVictoria();
                mostrarBannerHUD('🛡️ ¡ESCUDO TI ABSORBIÓ EL OBSTÁCULO!', '#00ff88');
            } else if (obs.tipo === 'impresora') {
                carState.spinTimer = 35; // 35 frames de trompo
                carState.speed *= 0.35;
                sfxImpacto();
                if (obs.msgTipo === 'hojas') {
                    mostrarBannerHUD('📄 ¡SOLO FALTARON HOJAS JAJA!', '#facc15');
                } else {
                    mostrarBannerHUD('⚠️ ¡ERROR 404: ATASCO DE IMPRESORA!', '#ef4444');
                }
            } else if (obs.tipo === 'cable_red') {
                carState.speed *= 0.45;
                sfxDerrape();
                mostrarBannerHUD('🔌 ¡CABLE DE RED ENREDADO EN LLANTAS!', '#00d2ff');
            } else if (obs.tipo === 'ap_wifi') {
                carState.apLentoTimer = 240; // ~4 segundos con AP arriba del auto
                carState.speed *= 0.50;
                sfxLag();
                mostrarBannerHUD('📶 ¡INTERNET LENTO! (AP SATURADO)', '#f59e0b');
            }
        }
    }

    // 8.6. Proyectiles Laptop 3D Voladoras hacia competidores y obstáculos
    for (let i = laptopProjectiles3D.length - 1; i >= 0; i--) {
        const lap = laptopProjectiles3D[i];
        lap.x += lap.vx;
        lap.z += lap.vz;
        lap.life--;

        lap.group.position.set(lap.x, 1.25, lap.z);
        lap.group.rotation.y += 0.25;
        lap.group.rotation.x += 0.12;

        let impactado = false;

        // Impacto contra Rivales de Agencias
        for (const rival of rivales3D) {
            if (Math.hypot(lap.x - rival.x, lap.z - rival.z) < 3.2) {
                rival.spinTimer = 45;
                rival.speed *= 0.25;
                sfxLaptopImpacto();
                mostrarBannerHUD(`💥 ¡PANTALLAZO AZUL A ${rival.nombre.toUpperCase()}!`, '#c084fc');
                impactado = true;
                break;
            }
        }

        // Impacto contra Obstáculos TI
        if (!impactado) {
            for (const obs of obstacles3D) {
                if (obs.group.visible && Math.hypot(lap.x - obs.x, lap.z - obs.z) < obs.radius) {
                    obs.group.visible = false;
                    obs.respawnTimer = 240;
                    sfxLaptopImpacto();
                    mostrarBannerHUD('💥 ¡LAPTOP REVENTÓ EL OBSTÁCULO!', '#00ff88');
                    impactado = true;
                    break;
                }
            }
        }

        if (impactado || lap.life <= 0) {
            scene3D.remove(lap.group);
            laptopProjectiles3D.splice(i, 1);
        }
    }

    // 8.7. IA de Competidores y Rivales de Agencias Grupo Huerta
    if (trackPoints3D.length > 0) {
        rivales3D.forEach(r => {
            if (r.spinTimer > 0) {
                r.spinTimer--;
                r.mesh.rotation.y += 0.32;
                r.speed *= 0.94;
            } else {
                r.speed = THREE.MathUtils.lerp(r.speed, r.baseSpeed, 0.05);
            }

            const pA = trackPoints3D[r.trackIdx];
            const pB = trackPoints3D[(r.trackIdx + 1) % trackPoints3D.length];
            const dx = pB.x - pA.x;
            const dz = pB.z - pA.z;
            const segLen = Math.hypot(dx, dz) || 1;
            const nx = -dz / segLen;
            const nz = dx / segLen;

            r.subProgress += ((r.speed / 3.6) * 0.016) / segLen;
            if (r.subProgress >= 1.0) {
                r.subProgress -= 1.0;
                r.trackIdx = (r.trackIdx + 1) % trackPoints3D.length;
                if (r.trackIdx === 0) r.lap++;
            }

            const curX = THREE.MathUtils.lerp(pA.x, pB.x, r.subProgress) + nx * r.laneOffset;
            const curZ = THREE.MathUtils.lerp(pA.z, pB.z, r.subProgress) + nz * r.laneOffset;
            r.x = curX;
            r.z = curZ;

            r.mesh.position.set(curX, 0, curZ);
            if (r.spinTimer === 0) {
                r.mesh.rotation.y = Math.atan2(-dx, -dz);
            }
        });

        // Cálculo dinámico de la posición en carrera (1° / 4)
        const playerRankVal = carState.lap * 1000 + closestIdx;
        let posJugador = 1;
        rivales3D.forEach(r => {
            const rivalRankVal = r.lap * 1000 + r.trackIdx;
            if (rivalRankVal > playerRankVal) posJugador++;
        });

        const posEl = document.getElementById('hud_race_position');
        if (posEl) {
            const iconTrophy = (posJugador === 1) ? 'bi-trophy-fill' : 'bi-flag-fill';
            posEl.innerHTML = `<i class="bi ${iconTrophy} me-1"></i>${posJugador}° / ${rivales3D.length + 1}`;
            posEl.style.color = (posJugador === 1) ? '#ffe600' : (posJugador === 2 ? '#00d2ff' : '#f87171');
        }
    }

    // ==============================================================
    // 9. SISTEMA INFALIBLE DE CHECKPOINTS Y VALIDACIÓN DE VUELTAS
    // ==============================================================
    const trackIdx = closestIdx;
    if (trackIdx >= 35 && trackIdx <= 55) carState.checkpoints[0] = true;
    if (trackIdx >= 80 && trackIdx <= 100) carState.checkpoints[1] = true;
    if (trackIdx >= 125 && trackIdx <= 145) carState.checkpoints[2] = true;

    const cercaDeMeta = (trackIdx >= 170 || trackIdx <= 10);
    if (cercaDeMeta && carState.checkpoints[0] && carState.checkpoints[1] && carState.checkpoints[2]) {
        carState.checkpoints = [false, false, false];
        const lapTime = (performance.now() - carState.lapStartTime);
        carState.lapStartTime = performance.now();

        if (!carState.bestLapTime || lapTime < carState.bestLapTime) {
            carState.bestLapTime = lapTime;
            document.getElementById('hud_best_lap').textContent = formatearTiempo(lapTime);
            guardarRecordServidor('carreras_3d', 0, Math.round(lapTime));
        }

        if (carState.lap < carState.maxLaps) {
            carState.lap++;
            sfxVictoria();
            mostrarBannerHUD(carState.lap === carState.maxLaps ? '¡ÚLTIMA VUELTA!' : `¡VUELTA ${carState.lap}!`, '#ffe600');
        } else if (!carState.raceFinished) {
            carState.raceFinished = true;
            sfxVictoria();
            mostrarBannerHUD('🏁 ¡VICTORIA! 🏁', '#00ff88');
            desbloquearMundo('corporativo');
            setTimeout(() => {
                Swal.fire({
                    title: '🏆 ¡GRAN PREMIO COMPLETADO!',
                    text: `¡Felicidades, ${avatarConfig.nombre}! Has completado las 3 vueltas en tiempo récord. Mundo Corporativo desbloqueado.`,
                    icon: 'success',
                    background: '#06152b',
                    color: '#fff',
                    confirmButtonColor: '#00d2ff'
                });
            }, 1200);
        }
        document.getElementById('hud_lap_count').textContent = carState.lap + ' / ' + carState.maxLaps;
    }

    // 10. Actualizar HUD (Velocímetro, Cronómetro, Tacómetro LED y Marchas)
    const kmh = Math.max(0, Math.round(Math.abs(carState.speed)));
    document.getElementById('hud_speed').textContent = kmh;
    document.getElementById('hud_lap_time').textContent = formatearTiempo(performance.now() - carState.lapStartTime);

    // Tacómetro LED de 8 segmentos
    const numLeds = Math.min(8, Math.floor((Math.abs(carState.speed) / 95) * 8));
    const leds = document.querySelectorAll('#hud_rpm_leds .rpm-led');
    leds.forEach((led, idx) => {
        if (carState.turboTimer > 0) {
            led.style.background = (idx % 2 === 0 ? '#a855f7' : '#00d2ff');
            led.style.boxShadow = '0 0 8px #a855f7';
        } else if (idx < numLeds) {
            if (idx < 4) led.style.background = '#22c55e';
            else if (idx < 6) led.style.background = '#eab308';
            else led.style.background = '#ef4444';
            led.style.boxShadow = '0 0 6px currentColor';
        } else {
            led.style.background = '#1e293b';
            led.style.boxShadow = 'none';
        }
    });

    const gearEl = document.getElementById('hud_gear');
    if (gearEl) {
        if (carState.speed < -1) gearEl.textContent = 'R';
        else if (Math.abs(carState.speed) < 2) gearEl.textContent = 'N';
        else gearEl.textContent = 'D';
    }

    // 11. Renderizar Mini-Mapa Radar
    renderizarRadar3D();

    renderer3D.render(scene3D, camera3D);
}

// Mini-Mapa Circular Radar
function renderizarRadar3D() {
    if (!radarCtx3D || trackPoints3D.length === 0) return;
    const ctx = radarCtx3D;
    ctx.clearRect(0, 0, 120, 120);

    const centerX = 60;
    const centerY = 60;
    const scale = 0.44;

    // Trazado de la pista en el radar
    ctx.strokeStyle = 'rgba(0, 210, 255, 0.45)';
    ctx.lineWidth = 4;
    ctx.beginPath();
    trackPoints3D.forEach((pt, idx) => {
        const rx = centerX + pt.x * scale;
        const ry = centerY + pt.z * scale;
        if (idx === 0) ctx.moveTo(rx, ry);
        else ctx.lineTo(rx, ry);
    });
    ctx.closePath();
    ctx.stroke();

    // Puntos de los Rivales en el Radar
    rivales3D.forEach(r => {
        const rx = centerX + r.x * scale;
        const ry = centerY + r.z * scale;
        ctx.fillStyle = '#' + new THREE.Color(r.color).getHexString();
        ctx.beginPath();
        ctx.arc(rx, ry, 3.2, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 1;
        ctx.stroke();
    });

    // Punto del Jugador
    const px = centerX + carState.x * scale;
    const py = centerY + carState.z * scale;
    ctx.fillStyle = '#ffe600';
    ctx.beginPath();
    ctx.arc(px, py, 4.5, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = 1.5;
    ctx.stroke();
}

function formatearTiempo(ms) {
    const mins = Math.floor(ms / 60000);
    const secs = Math.floor((ms % 60000) / 1000);
    const millis = Math.floor((ms % 1000) / 10);
    return (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs + '.' + (millis < 10 ? '0' : '') + millis;
}

function mostrarBannerHUD(texto, color) {
    const banner = document.getElementById('hud_banner_overlay');
    const textEl = document.getElementById('hud_banner_text');
    if (!banner || !textEl) return;
    textEl.textContent = texto;
    textEl.style.color = color || '#ffe600';
    banner.style.opacity = '1';
    banner.style.transform = 'translate(-50%, -50%) scale(1.1)';
    setTimeout(() => {
        banner.style.transform = 'translate(-50%, -50%) scale(1)';
    }, 180);
    setTimeout(() => {
        banner.style.opacity = '0';
        banner.style.transform = 'translate(-50%, -50%) scale(0.6)';
    }, 2200);
}

function reiniciarCarrera3D() {
    carState.x = 0;
    carState.z = -80;
    carState.speed = 0;
    carState.angle = -Math.PI / 2;
    carState.steerAngle = 0;
    carState.lap = 1;
    carState.raceFinished = false;
    carState.checkpoints = [false, false, false];
    carState.turboTimer = 0;
    carState.driftTimer = 0;
    carState.isDrifting = false;
    carState.spinTimer = 0;
    carState.hitObstacleTimer = 0;
    carState.apLentoTimer = 0;
    if (carState.apMeshOverCar) carState.apMeshOverCar.visible = false;

    // Limpiar proyectiles de laptops
    laptopProjectiles3D.forEach(l => { if (scene3D) scene3D.remove(l.group); });
    laptopProjectiles3D = [];

    carState.itemActual = null;
    carState.itemTimer = 0;
    actualizarHUDItem();
    document.getElementById('hud_lap_count').textContent = '1 / ' + carState.maxLaps;
    construirVehiculo3D();
    crearRivales3D();
    carState.lapStartTime = performance.now();
    mostrarBannerHUD('¡REINICIADO!', '#ffe600');
}
</script>

<!-- ======================================================= -->
<!-- MOTOR DE JUEGO 2: SUPER TALLER RUN 2D (MARIO BROS AUTO) -->
<!-- ======================================================= -->
<script>
let canvas2D, ctx2D, animId2D;
let p2d = {
    x: 100, y: 400, vx: 0, vy: 0,
    w: 36, h: 48,
    speed: 5.5, jumpForce: 13.5,
    grounded: false,
    vidas: 3, puntos: 0, llaves: 0,
    invulnerable: 0
};

let plataformas2D = [];
let items2D = [];
let enemigos2D = [];
let banderaMeta2D = { x: 2600, y: 280, w: 20, h: 220 };
let camX2D = 0;

function initJuego2D() {
    canvas2D = document.getElementById('canvasJuego2D');
    if (!canvas2D) return;
    ctx2D = canvas2D.getContext('2d');

    reiniciarNivel2D();
    if (!animId2D) loopJuego2D();
}

function reiniciarNivel2D() {
    p2d.x = 100; p2d.y = 400; p2d.vx = 0; p2d.vy = 0;
    p2d.grounded = false;
    p2d.llaves = 0;
    camX2D = 0;

    const esRancho = (avatarConfig.mundoSeleccionado === 'rancho');
    const esCorp = (avatarConfig.mundoSeleccionado === 'corporativo');

    // Generar Plataformas (Pisos, Elevadores, Rampas)
    plataformas2D = [
        // Suelo continuo
        { x: 0, y: 560, w: 900, h: 80, tipo: 'suelo' },
        { x: 980, y: 560, w: 900, h: 80, tipo: 'suelo' },
        { x: 1960, y: 560, w: 1000, h: 80, tipo: 'suelo' },

        // Elevadores / Plataformas flotantes
        { x: 300, y: 440, w: 120, h: 24, tipo: 'elevador' },
        { x: 500, y: 350, w: 140, h: 24, tipo: 'caja_herramientas' },
        { x: 720, y: 260, w: 120, h: 24, tipo: 'elevador' },

        { x: 1100, y: 430, w: 140, h: 24, tipo: 'elevador' },
        { x: 1320, y: 330, w: 160, h: 24, tipo: 'elevador' },
        { x: 1560, y: 240, w: 120, h: 24, tipo: 'caja_herramientas' },

        { x: 2100, y: 440, w: 140, h: 24, tipo: 'elevador' },
        { x: 2320, y: 350, w: 140, h: 24, tipo: 'elevador' }
    ];

    // Items (Llaves de Suzuki/Kawasaki, Herramientas, Aceite)
    items2D = [
        { x: 340, y: 390, tipo: 'llave', recolectado: false },
        { x: 550, y: 300, tipo: 'llave', recolectado: false },
        { x: 760, y: 210, tipo: 'herramienta', recolectado: false },
        { x: 1150, y: 380, tipo: 'llave', recolectado: false },
        { x: 1380, y: 280, tipo: 'laptop', recolectado: false },
        { x: 1600, y: 190, tipo: 'llave', recolectado: false },
        { x: 2150, y: 390, tipo: 'llave', recolectado: false },
        { x: 2370, y: 300, tipo: 'llave', recolectado: false }
    ];

    // Enemigos (Tuercas rodantes y Charcos de aceite)
    enemigos2D = [
        { x: 450, y: 536, w: 26, h: 24, vx: -1.6, tipo: 'tuerca' },
        { x: 680, y: 536, w: 26, h: 24, vx: 1.6, tipo: 'tuerca' },
        { x: 1250, y: 536, w: 26, h: 24, vx: -2.0, tipo: 'tuerca' },
        { x: 1480, y: 536, w: 26, h: 24, vx: 2.0, tipo: 'tuerca' },
        { x: 2200, y: 536, w: 26, h: 24, vx: -2.2, tipo: 'tuerca' }
    ];

    actualizarHUD2D();
}

function loopJuego2D() {
    animId2D = requestAnimationFrame(loopJuego2D);
    actualizarFisica2D();
    renderizar2D();
}

function actualizarFisica2D() {
    const moverIzq = (keysPressed['KeyA'] || keysPressed['ArrowLeft']);
    const moverDer = (keysPressed['KeyD'] || keysPressed['ArrowRight']);
    const saltar = (keysPressed['KeyW'] || keysPressed['ArrowUp'] || keysPressed['Space']);
    const sprint = keysPressed['ShiftLeft'] || keysPressed['ShiftRight'];

    const velActual = sprint ? p2d.speed * 1.45 : p2d.speed;

    if (moverIzq) {
        p2d.vx = -velActual;
    } else if (moverDer) {
        p2d.vx = velActual;
    } else {
        p2d.vx *= 0.75;
    }

    // Salto
    if (saltar && p2d.grounded) {
        p2d.vy = -p2d.jumpForce;
        p2d.grounded = false;
        sfxSalto();
    }

    // Gravedad
    p2d.vy += 0.65;
    if (p2d.vy > 14) p2d.vy = 14;

    p2d.x += p2d.vx;
    p2d.y += p2d.vy;

    // Colisiones con Plataformas
    p2d.grounded = false;
    for (const plat of plataformas2D) {
        if (p2d.x + p2d.w > plat.x && p2d.x < plat.x + plat.w) {
            // Caída sobre plataforma
            if (p2d.y + p2d.h >= plat.y && p2d.y + p2d.h <= plat.y + plat.h + 10 && p2d.vy >= 0) {
                p2d.y = plat.y - p2d.h;
                p2d.vy = 0;
                p2d.grounded = true;
            }
        }
    }

    // Caída al abismo
    if (p2d.y > 680) {
        perderVida2D();
        return;
    }

    // Recolectar Items
    for (const it of items2D) {
        if (!it.recolectado && Math.hypot((p2d.x + 18) - it.x, (p2d.y + 24) - it.y) < 32) {
            it.recolectado = true;
            p2d.llaves++;
            p2d.puntos += (it.tipo === 'laptop' ? 250 : 100);
            sfxMoneda();
            actualizarHUD2D();

            if (p2d.puntos >= 1000) {
                desbloquearMundo('corporativo');
            }
            if (p2d.puntos >= 2500) {
                desbloquearMundo('rancho');
            }
        }
    }

    // Enemigos (Tuercas)
    for (const ene of enemigos2D) {
        ene.x += ene.vx;
        if (ene.x < 100 || ene.x > 2500) ene.vx *= -1;

        if (p2d.x + p2d.w > ene.x && p2d.x < ene.x + ene.w &&
            p2d.y + p2d.h > ene.y && p2d.y < ene.y + ene.h) {
            
            // Si salta encima de la tuerca -> La aplasta estilo Mario!
            if (p2d.vy > 0 && p2d.y + p2d.h < ene.y + 16) {
                ene.y = 9999; // eliminado
                p2d.vy = -9;
                p2d.puntos += 150;
                sfxImpacto();
                actualizarHUD2D();
            } else if (p2d.invulnerable <= 0) {
                perderVida2D();
            }
        }
    }

    if (p2d.invulnerable > 0) p2d.invulnerable--;

    // Meta del Nivel (Llegar al Showroom)
    if (p2d.x > banderaMeta2D.x) {
        sfxVictoria();
        p2d.puntos += 500;
        guardarRecordServidor('super_agente_2d', p2d.puntos, 0);

        if (avatarConfig.mundoSeleccionado === 'taller') {
            desbloquearMundo('corporativo');
        } else if (avatarConfig.mundoSeleccionado === 'corporativo') {
            desbloquearMundo('rancho');
        }

        Swal.fire({
            icon: 'success',
            title: '¡NIVEL COMPLETADO!',
            text: 'Has entregado los vehículos en el Showroom con ' + p2d.puntos + ' puntos.',
            background: '#06152b',
            color: '#fff',
            confirmButtonColor: '#00d2ff'
        }).then(() => {
            reiniciarNivel2D();
        });
    }

    // Cámara sigue al jugador
    camX2D = p2d.x - 300;
    if (camX2D < 0) camX2D = 0;
}

function perderVida2D() {
    p2d.vidas--;
    sfxImpacto();
    actualizarHUD2D();

    if (p2d.vidas <= 0) {
        Swal.fire({
            icon: 'error',
            title: 'Fin de la Partida',
            text: 'Te has quedado sin vidas. ¡Vuelve a intentarlo!',
            background: '#06152b',
            color: '#fff',
            confirmButtonColor: '#ef4444'
        }).then(() => {
            p2d.vidas = 3;
            p2d.puntos = 0;
            reiniciarNivel2D();
        });
    } else {
        p2d.x = 100;
        p2d.y = 400;
        p2d.vx = 0; p2d.vy = 0;
        p2d.invulnerable = 60;
    }
}

function actualizarHUD2D() {
    document.getElementById('hud2d_vidas').textContent = '❤️'.repeat(Math.max(0, p2d.vidas));
    document.getElementById('hud2d_llaves').textContent = '🔑 ' + p2d.llaves + ' / 8';
    document.getElementById('hud2d_puntos').textContent = String(p2d.puntos).padStart(5, '0');
}

function renderizar2D() {
    ctx2D.clearRect(0, 0, canvas2D.width, canvas2D.height);

    const esRancho = (avatarConfig.mundoSeleccionado === 'rancho');
    const esCorp = (avatarConfig.mundoSeleccionado === 'corporativo');

    // Fondo según Mundo
    const grad = ctx2D.createLinearGradient(0, 0, 0, canvas2D.height);
    if (esRancho) {
        grad.addColorStop(0, '#64b5f6');
        grad.addColorStop(1, '#aed581');
    } else if (esCorp) {
        grad.addColorStop(0, '#030814');
        grad.addColorStop(1, '#0a1d3b');
    } else {
        grad.addColorStop(0, '#0a192e');
        grad.addColorStop(1, '#1e293b');
    }
    ctx2D.fillStyle = grad;
    ctx2D.fillRect(0, 0, canvas2D.width, canvas2D.height);

    ctx2D.save();
    ctx2D.translate(-camX2D, 0);

    // Dibujar Plataformas
    for (const plat of plataformas2D) {
        if (plat.tipo === 'suelo') {
            ctx2D.fillStyle = esRancho ? '#5d4037' : '#0f172a';
            ctx2D.fillRect(plat.x, plat.y, plat.w, plat.h);
            ctx2D.fillStyle = esRancho ? '#8d6e63' : '#38bdf8';
            ctx2D.fillRect(plat.x, plat.y, plat.w, 4); // Borde superior
        } else if (plat.tipo === 'elevador') {
            // Plataforma hidráulica de taller
            ctx2D.fillStyle = '#eab308';
            ctx2D.fillRect(plat.x, plat.y, plat.w, plat.h);
            ctx2D.fillStyle = '#ca8a04';
            for (let i = 0; i < plat.w; i += 20) {
                ctx2D.fillRect(plat.x + i, plat.y, 8, plat.h);
            }
        } else {
            ctx2D.fillStyle = '#dc2626';
            ctx2D.fillRect(plat.x, plat.y, plat.w, plat.h);
        }
    }

    // Dibujar Items
    for (const it of items2D) {
        if (!it.recolectado) {
            if (it.tipo === 'llave') {
                ctx2D.fillStyle = '#ffe600';
                ctx2D.beginPath();
                ctx2D.arc(it.x, it.y, 10, 0, Math.PI * 2);
                ctx2D.fill();
                ctx2D.fillRect(it.x, it.y - 2, 14, 4);
            } else if (it.tipo === 'laptop') {
                ctx2D.fillStyle = '#38bdf8';
                ctx2D.fillRect(it.x - 12, it.y - 8, 24, 16);
            } else {
                ctx2D.fillStyle = '#cbd5e1';
                ctx2D.fillRect(it.x - 8, it.y - 8, 16, 16);
            }
        }
    }

    // Dibujar Enemigos (Tuercas)
    for (const ene of enemigos2D) {
        if (ene.y < 1000) {
            ctx2D.fillStyle = '#94a3b8';
            ctx2D.beginPath();
            ctx2D.arc(ene.x + 13, ene.y + 12, 12, 0, Math.PI * 2);
            ctx2D.fill();
            ctx2D.fillStyle = '#0f172a';
            ctx2D.beginPath();
            ctx2D.arc(ene.x + 13, ene.y + 12, 4, 0, Math.PI * 2);
            ctx2D.fill();
        }
    }

    // Dibujar Bandera de Meta (Showroom)
    ctx2D.fillStyle = '#ffffff';
    ctx2D.fillRect(banderaMeta2D.x, banderaMeta2D.y, 6, banderaMeta2D.h);
    ctx2D.fillStyle = '#22c55e';
    ctx2D.beginPath();
    ctx2D.moveTo(banderaMeta2D.x + 6, banderaMeta2D.y);
    ctx2D.lineTo(banderaMeta2D.x + 50, banderaMeta2D.y + 20);
    ctx2D.lineTo(banderaMeta2D.x + 6, banderaMeta2D.y + 40);
    ctx2D.closePath();
    ctx2D.fill();
    ctx2D.fillStyle = '#ffffff';
    ctx2D.font = 'bold 10px sans-serif';
    ctx2D.fillText('SHOWROOM', banderaMeta2D.x + 10, banderaMeta2D.y + 24);

    // Dibujar Jugador 2D (Mecánico/Agente con su Avatar)
    if (p2d.invulnerable % 8 < 4) {
        // Cuerpo y Overol
        ctx2D.fillStyle = avatarConfig.colorTraje;
        ctx2D.fillRect(p2d.x, p2d.y + 16, p2d.w, 32);

        // Cabeza
        ctx2D.fillStyle = '#fbcfe8';
        ctx2D.fillRect(p2d.x + 6, p2d.y, 24, 16);

        // Casco o Gorra según avatar
        if (avatarConfig.casco === 'carreras') {
            ctx2D.fillStyle = '#0f172a';
            ctx2D.fillRect(p2d.x + 4, p2d.y - 4, 28, 10);
            ctx2D.fillStyle = '#00d2ff';
            ctx2D.fillRect(p2d.x + 14, p2d.y + 4, 14, 4); // Visor
        } else if (avatarConfig.casco === 'gorra') {
            ctx2D.fillStyle = '#ef4444';
            ctx2D.fillRect(p2d.x + 2, p2d.y - 6, 32, 8);
        } else {
            ctx2D.strokeStyle = '#38bdf8';
            ctx2D.lineWidth = 3;
            ctx2D.strokeRect(p2d.x + 4, p2d.y - 4, 28, 6);
        }

        // Nombre del Piloto encima de la cabeza
        ctx2D.fillStyle = '#ffffff';
        ctx2D.font = 'bold 9px sans-serif';
        ctx2D.textAlign = 'center';
        ctx2D.fillText(USUARIO_OFICIAL.nombre, p2d.x + (p2d.w / 2), p2d.y - 10);
        ctx2D.textAlign = 'start';
    }

    ctx2D.restore();
}

function reiniciarJuego2D() {
    reiniciarNivel2D();
}
</script>

<!-- Script de Ranking y Envío de Récords a Servidor -->
<script>
function guardarRecordServidor(juego, puntaje, tiempoMs) {
    fetch('area404.php?ajax_guardar_record=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            juego: juego,
            mundo: avatarConfig.mundoSeleccionado,
            vehiculo: avatarConfig.vehiculo,
            puntaje: puntaje,
            tiempo_ms: tiempoMs
        })
    });
}

function cargarRanking(juego) {
    const btn3D = document.getElementById('btnRank3D');
    const btn2D = document.getElementById('btnRank2D');
    const thMetrica = document.getElementById('th_metrica');
    const tbody = document.getElementById('tablaRankingBody');

    if (btn3D) btn3D.classList.toggle('active', juego === 'carreras_3d');
    if (btn2D) btn2D.classList.toggle('active', juego === 'super_agente_2d');
    if (thMetrica) thMetrica.textContent = (juego === 'carreras_3d' ? 'Mejor Tiempo' : 'Puntaje Más Alto');

    fetch('area404.php?ajax_leaderboard=1&juego=' + encodeURIComponent(juego))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'ok' && data.records && data.records.length > 0) {
                let html = '';
                data.records.forEach((r, idx) => {
                    const posBadge = (idx === 0 ? '🥇' : (idx === 1 ? '🥈' : (idx === 2 ? '🥉' : '#' + (idx + 1))));
                    const metrica = (juego === 'carreras_3d' ? formatearTiempo(r.mejor_tiempo) : r.max_puntaje + ' pts');
                    html += `
                        <tr>
                            <td class="fw-bold fs-5">${posBadge}</td>
                            <td><strong class="text-white">${escapeHtml(r.nombre_piloto)}</strong></td>
                            <td><span class="badge bg-primary bg-opacity-20 text-info">${escapeHtml(r.agencia)}</span></td>
                            <td><span class="text-secondary small text-capitalize">${escapeHtml(r.mundo || 'Agencia')}</span></td>
                            <td class="fw-bold ${juego === 'carreras_3d' ? 'text-success' : 'text-warning'}">${metrica}</td>
                            <td class="text-secondary small">${r.creado_en ? r.creado_en.substring(0, 10) : 'Hoy'}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center py-4 text-secondary">
                            <i class="bi bi-flag fs-3 d-block mb-1"></i> Aún no hay récords registrados en este modo. ¡Sé el primero en competir!
                        </td>
                    </tr>
                `;
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Error al cargar récords.</td></tr>`;
        });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
}

// Inicializar Avatar Canvas en arranque
document.addEventListener('DOMContentLoaded', () => {
    dibujarAvatarPreview();
    actualizarLabelsHUD();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
