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

                    <!-- 1. Tipo de Vehículo -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Tipo de Vehículo para Carreras</label>
                        <div class="row g-2">
                            <div class="col-4">
                                <div class="vehicle-btn active" id="veh_auto" onclick="setVehiculo('auto')">
                                    <i class="bi bi-car-front-fill fs-4 text-info d-block mb-1"></i>
                                    <div class="fw-bold small">Auto GT</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="vehicle-btn" id="veh_moto_suzuki" onclick="setVehiculo('moto_suzuki')">
                                    <i class="bi bi-bicycle fs-4 text-primary d-block mb-1"></i>
                                    <div class="fw-bold small" style="color: #38bdf8;">Moto Suzuki</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="vehicle-btn" id="veh_moto_kawasaki" onclick="setVehiculo('moto_kawasaki')">
                                    <i class="bi bi-bicycle fs-4 text-success d-block mb-1"></i>
                                    <div class="fw-bold small" style="color: #4ade80;">Kawasaki Ninja</div>
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
            <!-- HUD: Cronómetro y Vueltas -->
            <div class="game-hud hud-timer">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <div class="text-secondary" style="font-size: 0.68rem;">TIEMPO DE VUELTA</div>
                        <div class="fs-5 fw-bold text-warning" id="hud_lap_time">00:00.00</div>
                    </div>
                    <div class="border-start border-secondary ps-3">
                        <div class="text-secondary" style="font-size: 0.68rem;">MEJOR VUELTA</div>
                        <div class="fs-5 fw-bold text-success" id="hud_best_lap">--:--.--</div>
                    </div>
                    <div class="border-start border-secondary ps-3">
                        <div class="text-secondary" style="font-size: 0.68rem;">VUELTA</div>
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

            <!-- HUD: Velocímetro Digital y Marchas -->
            <div class="game-hud hud-speedometer">
                <div class="text-secondary small fw-bold" style="font-size: 0.65rem; letter-spacing: 1.5px;">VELOCIDAD</div>
                <div class="display-6 fw-bold text-white font-monospace lh-1" id="hud_speed">0</div>
                <div class="text-info small fw-bold mt-1" style="font-size: 0.75rem;">KM / H</div>
            </div>

            <!-- HUD: Teclas de Control -->
            <div class="game-hud hud-controls-hint text-secondary">
                <i class="bi bi-controller text-info me-1"></i>
                <kbd class="bg-dark text-white">W / ↑</kbd> Acelerar &bull; 
                <kbd class="bg-dark text-white">S / ↓</kbd> Freno &bull; 
                <kbd class="bg-dark text-white">A / D</kbd> Dirección &bull; 
                <kbd class="bg-dark text-white">ESPACIO</kbd> Derrape (Chispas) &bull; 
                <kbd class="bg-dark text-white">E</kbd> Item
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

    if (avatarConfig.vehiculo === 'auto') {
        // Carro deportivo
        ctx.fillStyle = avatarConfig.colorTraje;
        ctx.beginPath();
        ctx.roundRect(cx - 70, cy + 5, 140, 28, 8);
        ctx.fill();

        // Cabina
        ctx.fillStyle = '#0a1d37';
        ctx.beginPath();
        ctx.roundRect(cx - 35, cy - 14, 70, 22, 6);
        ctx.fill();
        ctx.strokeStyle = '#00d2ff';
        ctx.stroke();

        // Llantas
        ctx.fillStyle = '#111827';
        ctx.beginPath();
        ctx.arc(cx - 45, cy + 32, 12, 0, Math.PI * 2);
        ctx.arc(cx + 45, cy + 32, 12, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#94a3b8';
        ctx.beginPath();
        ctx.arc(cx - 45, cy + 32, 5, 0, Math.PI * 2);
        ctx.arc(cx + 45, cy + 32, 5, 0, Math.PI * 2);
        ctx.fill();

        // Faro y Alerón
        ctx.fillStyle = '#ffe600';
        ctx.fillRect(cx + 62, cy + 12, 6, 8);
        ctx.fillStyle = '#0284c7';
        ctx.fillRect(cx - 72, cy - 8, 8, 16);
    } else {
        // Moto de Pista (Suzuki o Kawasaki)
        const esKawa = (avatarConfig.vehiculo === 'moto_kawasaki');
        const colorMoto = esKawa ? '#22c55e' : '#2563eb';

        // Llantas de Moto
        ctx.fillStyle = '#0f172a';
        ctx.beginPath();
        ctx.arc(cx - 50, cy + 28, 14, 0, Math.PI * 2);
        ctx.arc(cx + 50, cy + 28, 14, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = colorMoto;
        ctx.lineWidth = 2;
        ctx.stroke();

        // Chasis y carenado
        ctx.fillStyle = colorMoto;
        ctx.beginPath();
        ctx.moveTo(cx - 40, cy + 25);
        ctx.lineTo(cx - 10, cy);
        ctx.lineTo(cx + 35, cy - 5);
        ctx.lineTo(cx + 50, cy + 15);
        ctx.lineTo(cx + 25, cy + 28);
        ctx.closePath();
        ctx.fill();

        // Escape y manubrio
        ctx.strokeStyle = '#94a3b8';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(cx + 25, cy - 2);
        ctx.lineTo(cx + 15, cy - 14);
        ctx.stroke();

        // Letrero de Marca
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 9px monospace';
        ctx.fillText(esKawa ? 'KAWASAKI' : 'SUZUKI', cx - 22, cy + 18);
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
    ['auto', 'moto_suzuki', 'moto_kawasaki'].forEach(v => {
        const el = document.getElementById('veh_' + v);
        if (el) el.classList.toggle('active', v === tipo);
    });
    actualizarLabelsHUD();
    dibujarAvatarPreview();
    guardarAvatarServidor();
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
let radarCanvas3D, radarCtx3D;

// Estado Físico y Arcade del Vehículo 3D (Estilo Mario Kart)
let carState = {
    x: 0,
    z: 0,
    speed: 0,
    maxSpeed: 175, // km/h
    accel: 0.72,
    brake: 0.90,
    friction: 0.985,
    angle: 0,
    angularVelocity: 0,
    steerAngle: 0,
    
    // Sistema de Derrape y Mini-Turbo (Mario Kart)
    isDrifting: false,
    driftDirection: 0,
    driftTimer: 0,
    turboTimer: 0,
    currentTurboLevel: 0, // 0: None, 1: Blue (Mini), 2: Orange (Super)
    
    // Items y Poderes
    itemActual: null, // 'nitro', 'hongo', 'escudo'
    itemTimer: 0,
    
    lap: 1,
    maxLaps: 3,
    lapStartTime: 0,
    bestLapTime: null,
    checkpointPassed: false
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

    // 6. Iniciar Bucle
    carState.lapStartTime = performance.now();
    window.addEventListener('resize', onWindowResize3D);
    animar3D();
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

    // 1. Suelo Base de Césped / Entorno
    const groundGeo = new THREE.PlaneGeometry(800, 800);
    const groundMat = new THREE.MeshLambertMaterial({
        color: esRancho ? 0x556b2f : (esCorp ? 0x050d1a : 0x166534)
    });
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

    const trackWidth = 20; // Ancho perfecto de pista (20 unidades)
    const halfWidth = trackWidth / 2;

    const roadPositions = [];
    const kerbLeftPositions = [];
    const kerbRightPositions = [];
    const roadIndices = [];
    const kerbIndices = [];

    // Muros / Paredes 3D a lo largo de toda la pista
    const wallHeight = 2.2;
    const wallLeftPos = [];
    const wallRightPos = [];
    const wallIndices = [];

    for (let i = 0; i < trackPoints3D.length; i++) {
        const p1 = trackPoints3D[i];
        const p2 = trackPoints3D[(i + 1) % trackPoints3D.length];
        const dx = p2.x - p1.x;
        const dz = p2.z - p1.z;
        const len = Math.hypot(dx, dz);
        const nx = (-dz / len);
        const nz = (dx / len);

        // Bordes de la pista
        const leftEdge = new THREE.Vector3(p1.x + nx * halfWidth, 0.08, p1.z + nz * halfWidth);
        const rightEdge = new THREE.Vector3(p1.x - nx * halfWidth, 0.08, p1.z - nz * halfWidth);

        roadPositions.push(leftEdge.x, leftEdge.y, leftEdge.z);
        roadPositions.push(rightEdge.x, rightEdge.y, rightEdge.z);

        // Pianos exteriores (Kerbs) con relieve
        const kerbWidth = 2.0;
        const klOuter = new THREE.Vector3(p1.x + nx * (halfWidth + kerbWidth), 0.22, p1.z + nz * (halfWidth + kerbWidth));
        const krOuter = new THREE.Vector3(p1.x - nx * (halfWidth + kerbWidth), 0.22, p1.z - nz * (halfWidth + kerbWidth));

        kerbLeftPositions.push(leftEdge.x, 0.12, leftEdge.z);
        kerbLeftPositions.push(klOuter.x, 0.22, klOuter.z);

        kerbRightPositions.push(rightEdge.x, 0.12, rightEdge.z);
        kerbRightPositions.push(krOuter.x, 0.22, krOuter.z);

        // Muros de Contención / Guardrails Elevados (Paredes de Pista)
        const wlBase = new THREE.Vector3(p1.x + nx * (halfWidth + kerbWidth + 0.3), 0, p1.z + nz * (halfWidth + kerbWidth + 0.3));
        const wlTop  = new THREE.Vector3(p1.x + nx * (halfWidth + kerbWidth + 0.3), wallHeight, p1.z + nz * (halfWidth + kerbWidth + 0.3));
        wallLeftPos.push(wlBase.x, wlBase.y, wlBase.z);
        wallLeftPos.push(wlTop.x, wlTop.y, wlTop.z);

        const wrBase = new THREE.Vector3(p1.x - nx * (halfWidth + kerbWidth + 0.3), 0, p1.z - nx * (halfWidth + kerbWidth + 0.3));
        const wrTop  = new THREE.Vector3(p1.x - nx * (halfWidth + kerbWidth + 0.3), wallHeight, p1.z - nx * (halfWidth + kerbWidth + 0.3));
        wallRightPos.push(wrBase.x, wrBase.y, wrBase.z);
        wallRightPos.push(wrTop.x, wrTop.y, wrTop.z);
    }

    for (let i = 0; i < trackPoints3D.length; i++) {
        const i0 = i * 2;
        const i1 = i0 + 1;
        const i2 = ((i + 1) % trackPoints3D.length) * 2;
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

    // Textura de granulado de asfalto
    for (let i = 0; i < 2500; i++) {
        aCtx.fillStyle = (Math.random() > 0.5 ? '#15202e' : '#2d3748');
        aCtx.fillRect(Math.random() * 512, Math.random() * 512, 2, 2);
    }

    // Líneas blancas en los bordes de la pista
    aCtx.fillStyle = '#ffffff';
    aCtx.fillRect(16, 0, 12, 512); // Borde lateral izquierdo
    aCtx.fillRect(484, 0, 12, 512); // Borde lateral derecho

    // Línea discontinua amarilla/blanca en el centro de la pista
    aCtx.fillStyle = '#fde047';
    for (let y = 0; y < 512; y += 48) {
        aCtx.fillRect(250, y, 12, 26);
    }

    const texAsphalt = new THREE.CanvasTexture(asphaltCanvas);
    texAsphalt.wrapS = THREE.RepeatWrapping;
    texAsphalt.wrapT = THREE.RepeatWrapping;
    texAsphalt.repeat.set(1, 45);

    const roadGeo = new THREE.BufferGeometry();
    roadGeo.setAttribute('position', new THREE.Float32BufferAttribute(roadPositions, 3));
    roadGeo.setIndex(roadIndices);
    roadGeo.computeVertexNormals();

    const roadMat = new THREE.MeshStandardMaterial({ 
        map: texAsphalt, 
        roughness: 0.85, 
        metalness: 0.15 
    });
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

    const kerbMat = new THREE.MeshLambertMaterial({ map: texKerb });

    const kerbGeoL = new THREE.BufferGeometry();
    kerbGeoL.setAttribute('position', new THREE.Float32BufferAttribute(kerbLeftPositions, 3));
    kerbGeoL.setIndex(kerbIndices);
    kerbGeoL.computeVertexNormals();
    scene3D.add(new THREE.Mesh(kerbGeoL, kerbMat));

    const kerbGeoR = new THREE.BufferGeometry();
    kerbGeoR.setAttribute('position', new THREE.Float32BufferAttribute(kerbRightPositions, 3));
    kerbGeoR.setIndex(kerbIndices);
    kerbGeoR.computeVertexNormals();
    scene3D.add(new THREE.Mesh(kerbGeoR, kerbMat));

    // ==============================================================
    // PAREDES / BARRERAS DE SEGURIDAD 3D (GUARDRAILS & MUROS)
    // ==============================================================
    const wallCanvas = document.createElement('canvas');
    wallCanvas.width = 256; wallCanvas.height = 64;
    const wCtx = wallCanvas.getContext('2d');
    wCtx.fillStyle = '#0f172a'; wCtx.fillRect(0, 0, 256, 64);
    // Franjas de seguridad reflectantes azul y blanco
    wCtx.fillStyle = '#0284c7'; wCtx.fillRect(0, 8, 256, 18);
    wCtx.fillStyle = '#ffffff'; wCtx.font = 'bold 16px sans-serif'; wCtx.textAlign = 'center';
    wCtx.fillText('★ GRUPO HUERTA ★ SUZUKI ★ KAWASAKI ★', 128, 44);
    const texWall = new THREE.CanvasTexture(wallCanvas);
    texWall.wrapS = THREE.RepeatWrapping;
    texWall.wrapT = THREE.RepeatWrapping;
    texWall.repeat.set(30, 1);

    const wallMat = new THREE.MeshStandardMaterial({ 
        map: texWall, 
        side: THREE.DoubleSide, 
        metalness: 0.6, 
        roughness: 0.4 
    });

    const wallGeoL = new THREE.BufferGeometry();
    wallGeoL.setAttribute('position', new THREE.Float32BufferAttribute(wallLeftPos, 3));
    wallGeoL.setIndex(wallIndices);
    wallGeoL.computeVertexNormals();
    const meshWallL = new THREE.Mesh(wallGeoL, wallMat);
    meshWallL.castShadow = true;
    scene3D.add(meshWallL);

    const wallGeoR = new THREE.BufferGeometry();
    wallGeoR.setAttribute('position', new THREE.Float32BufferAttribute(wallRightPos, 3));
    wallGeoR.setIndex(wallIndices);
    wallGeoR.computeVertexNormals();
    const meshWallR = new THREE.Mesh(wallGeoR, wallMat);
    meshWallR.castShadow = true;
    scene3D.add(meshWallR);

    // Pilas de Neumáticos 3D en las Curvas Peligrosas
    const indicesCurvas = [20, 55, 85, 120, 155];
    indicesCurvas.forEach(idx => {
        const pt = trackPoints3D[idx];
        for (let t = -3; t <= 3; t += 3) {
            construirPilaNeumaticos(pt.x * 1.15 + t, pt.z * 1.15);
        }
    });

    // Arco Monumental de Meta
    construirArcoMeta(new THREE.Vector3(0, 0, -80));

    // Paneles Aceleradores Neón (Boost Pads)
    const boostIndices = [22, 75, 130];
    boostIndices.forEach(idx => {
        const pt = trackPoints3D[idx];
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
        const pt = trackPoints3D[idx];
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

    // Decoraciones de Mundo
    for (let i = 0; i < trackPoints3D.length; i += 8) {
        const pt = trackPoints3D[i];
        const bannerPos = { x: pt.x * 1.32, z: pt.z * 1.32 };

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

// Arco Monumental de Meta con Línea de Salida a Cuadros
function construirArcoMeta(pos) {
    const archGroup = new THREE.Group();

    // Columnas metálicas trusses
    const colGeo = new THREE.BoxGeometry(2, 14, 2);
    const colMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.3 });
    const colL = new THREE.Mesh(colGeo, colMat); colL.position.set(-14, 7, 0); archGroup.add(colL);
    const colR = new THREE.Mesh(colGeo, colMat); colR.position.set(14, 7, 0); archGroup.add(colR);

    // Viga superior transversal
    const beamGeo = new THREE.BoxGeometry(30, 3.5, 3);
    const beamMat = new THREE.MeshStandardMaterial({ color: 0x00d2ff, emissive: 0x0077b6, emissiveIntensity: 0.5 });
    const beam = new THREE.Mesh(beamGeo, beamMat);
    beam.position.set(0, 13, 0);
    archGroup.add(beam);

    // Letrero 3D "🏁 GRUPO HUERTA GRAND PRIX 🏁"
    const signCanvas = document.createElement('canvas');
    signCanvas.width = 512; signCanvas.height = 128;
    const ctxS = signCanvas.getContext('2d');
    ctxS.fillStyle = '#030a16'; ctxS.fillRect(0, 0, 512, 128);
    // Borde de cuadros blanco y negro
    for (let bx = 0; bx < 512; bx += 16) {
        ctxS.fillStyle = (bx % 32 === 0) ? '#ffffff' : '#000000';
        ctxS.fillRect(bx, 0, 16, 10);
        ctxS.fillRect(bx, 118, 16, 10);
    }
    ctxS.strokeStyle = '#00d2ff'; ctxS.lineWidth = 4; ctxS.strokeRect(4, 12, 504, 104);
    ctxS.fillStyle = '#ffffff'; ctxS.font = 'bold 32px sans-serif'; ctxS.textAlign = 'center';
    ctxS.fillText('🏁 GRUPO HUERTA GRAND PRIX 🏁', 256, 72);
    const signTex = new THREE.CanvasTexture(signCanvas);

    const signGeo = new THREE.PlaneGeometry(24, 5);
    const signMat = new THREE.MeshBasicMaterial({ map: signTex, side: THREE.DoubleSide });
    const signMesh = new THREE.Mesh(signGeo, signMat);
    signMesh.position.set(0, 13, 1.6);
    archGroup.add(signMesh);

    // Línea de meta de cuadros en el suelo
    const checkCanvas = document.createElement('canvas');
    checkCanvas.width = 256; checkCanvas.height = 64;
    const chCtx = checkCanvas.getContext('2d');
    for (let cy = 0; cy < 64; cy += 16) {
        for (let cx = 0; cx < 256; cx += 16) {
            chCtx.fillStyle = ((cx / 16 + cy / 16) % 2 === 0) ? '#ffffff' : '#111827';
            chCtx.fillRect(cx, cy, 16, 16);
        }
    }
    const checkTex = new THREE.CanvasTexture(checkCanvas);
    const checkGeo = new THREE.PlaneGeometry(20, 4);
    const checkMat = new THREE.MeshBasicMaterial({ map: checkTex, polygonOffset: true, polygonOffsetFactor: -1 });
    const checkMesh = new THREE.Mesh(checkGeo, checkMat);
    checkMesh.rotation.x = -Math.PI / 2;
    checkMesh.position.set(0, 0.15, 0);
    archGroup.add(checkMesh);

    archGroup.position.copy(pos);
    scene3D.add(archGroup);
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
// CONSTRUCTOR DE GO-KART 3D AUTÉNTICO (MARIO KART STYLE)
// ==============================================================
function construirVehiculo3D() {
    if (vehiculo3D) scene3D.remove(vehiculo3D);

    vehiculo3D = new THREE.Group();
    escapeFlames = [];

    const esMoto = (avatarConfig.vehiculo.startsWith('moto'));
    const esKawa = (avatarConfig.vehiculo === 'moto_kawasaki');
    const colorPrimario = esMoto ? (esKawa ? 0x22c55e : 0x2563eb) : new THREE.Color(avatarConfig.colorTraje).getHex();

    if (!esMoto) {
        // ==========================================
        // GO-KART PROFESIONAL DE CARRERAS
        // ==========================================
        const kartGroup = new THREE.Group();

        // 1. Chasis Tubular de Karting
        const frameGeo = new THREE.BoxGeometry(1.8, 0.25, 3.6);
        const frameMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.8, roughness: 0.3 });
        const frame = new THREE.Mesh(frameGeo, frameMat);
        frame.position.y = 0.38;
        kartGroup.add(frame);

        // 2. Parachoques y Pontones Laterales (Side Pods)
        const podGeo = new THREE.BoxGeometry(0.45, 0.4, 2.0);
        const bodyMat = new THREE.MeshStandardMaterial({ color: colorPrimario, metalness: 0.7, roughness: 0.25 });
        
        const podL = new THREE.Mesh(podGeo, bodyMat); podL.position.set(-1.0, 0.45, 0); kartGroup.add(podL);
        const podR = new THREE.Mesh(podGeo, bodyMat); podR.position.set(1.0, 0.45, 0); kartGroup.add(podR);

        // Morro delantero con alerón bajo
        const noseGeo = new THREE.BoxGeometry(2.0, 0.3, 0.9);
        const nose = new THREE.Mesh(noseGeo, bodyMat);
        nose.position.set(0, 0.42, -1.9);
        kartGroup.add(nose);

        // Dorsal con número #404
        const numCanvas = document.createElement('canvas');
        numCanvas.width = 128; numCanvas.height = 128;
        const nCtx = numCanvas.getContext('2d');
        nCtx.fillStyle = '#ffffff'; nCtx.beginPath(); nCtx.arc(64, 64, 55, 0, Math.PI*2); nCtx.fill();
        nCtx.fillStyle = '#0f172a'; nCtx.font = 'bold 54px monospace'; nCtx.textAlign = 'center'; nCtx.fillText('404', 64, 82);
        const numTex = new THREE.CanvasTexture(numCanvas);
        const numMesh = new THREE.Mesh(new THREE.PlaneGeometry(0.7, 0.7), new THREE.MeshBasicMaterial({ map: numTex }));
        numMesh.rotation.x = -Math.PI / 4;
        numMesh.position.set(0, 0.65, -1.8);
        kartGroup.add(numMesh);

        // 3. Volante deportivo y Columna de Dirección
        const steerCol = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 0.9), frameMat);
        steerCol.rotation.x = Math.PI / 4;
        steerCol.position.set(0, 0.85, -0.6);
        kartGroup.add(steerCol);

        const wheelMesh = new THREE.Mesh(new THREE.TorusGeometry(0.32, 0.06, 8, 16), new THREE.MeshStandardMaterial({ color: 0x334155 }));
        wheelMesh.rotation.x = Math.PI / 4;
        wheelMesh.position.set(0, 1.15, -0.9);
        kartGroup.add(wheelMesh);

        // 4. Asiento Bucket y Motor Trasero
        const seat = new THREE.Mesh(new THREE.BoxGeometry(1.1, 1.1, 0.5), new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.9 }));
        seat.position.set(0, 0.9, 0.3);
        kartGroup.add(seat);

        // Bloque de Motor con Filtro de Aire y Culata
        const engBlock = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.6, 0.7), new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.9 }));
        engBlock.position.set(0.65, 0.65, 1.0);
        kartGroup.add(engBlock);

        // Tubos de Escape Cromados dobles
        const exhMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9, metalness: 0.95, roughness: 0.1 });
        const pipeL = new THREE.Mesh(new THREE.CylinderGeometry(0.14, 0.16, 0.8, 10), exhMat);
        pipeL.rotation.x = Math.PI / 2;
        pipeL.position.set(-0.4, 0.55, 1.9);
        kartGroup.add(pipeL);

        const pipeR = new THREE.Mesh(new THREE.CylinderGeometry(0.14, 0.16, 0.8, 10), exhMat);
        pipeR.rotation.x = Math.PI / 2;
        pipeR.position.set(0.4, 0.55, 1.9);
        kartGroup.add(pipeR);

        // Llamaradas animadas en escapes
        const flMat = new THREE.MeshBasicMaterial({ color: 0x00d2ff, transparent: true, opacity: 0 });
        const flL = new THREE.Mesh(new THREE.ConeGeometry(0.22, 1.2, 8), flMat); flL.rotation.x = -Math.PI / 2; flL.position.set(-0.4, 0.55, 2.7); kartGroup.add(flL);
        const flR = new THREE.Mesh(new THREE.ConeGeometry(0.22, 1.2, 8), flMat); flR.rotation.x = -Math.PI / 2; flR.position.set(0.4, 0.55, 2.7); kartGroup.add(flR);
        escapeFlames.push(flL, flR);

        // 5. Neumáticos Anchos de Karting (Slick Tires)
        const tireMat = new THREE.MeshStandardMaterial({ color: 0x111827, roughness: 0.95 });
        const tireGeoF = new THREE.CylinderGeometry(0.40, 0.40, 0.38, 18); tireGeoF.rotateZ(Math.PI / 2);
        const tireGeoR = new THREE.CylinderGeometry(0.46, 0.46, 0.52, 18); tireGeoR.rotateZ(Math.PI / 2);

        ruedaDelanteraIzq = new THREE.Mesh(tireGeoF, tireMat); ruedaDelanteraIzq.position.set(-1.25, 0.40, -1.2); kartGroup.add(ruedaDelanteraIzq);
        ruedaDelanteraDer = new THREE.Mesh(tireGeoF, tireMat); ruedaDelanteraDer.position.set(1.25, 0.40, -1.2); kartGroup.add(ruedaDelanteraDer);

        const rearL = new THREE.Mesh(tireGeoR, tireMat); rearL.position.set(-1.30, 0.46, 1.3); kartGroup.add(rearL);
        const rearR = new THREE.Mesh(tireGeoR, tireMat); rearR.position.set(1.30, 0.46, 1.3); kartGroup.add(rearR);

        // 6. Piloto sentado con casco
        const driver = new THREE.Group();
        const head = new THREE.Mesh(new THREE.SphereGeometry(0.38, 14, 14), new THREE.MeshStandardMaterial({ color: new THREE.Color(avatarConfig.colorTraje).getHex() }));
        head.position.set(0, 1.6, 0.15);
        driver.add(head);

        const visor = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.16, 0.28), new THREE.MeshBasicMaterial({ color: 0x00d2ff }));
        visor.position.set(0, 1.6, -0.15);
        driver.add(visor);

        kartGroup.add(driver);
        vehiculo3D.add(kartGroup);
    } else {
        // ==========================================
        // MOTO RACING SUPERBIKE (SUZUKI / KAWASAKI)
        // ==========================================
        const bikeGroup = new THREE.Group();
        const cowlMat = new THREE.MeshStandardMaterial({ color: colorPrimario, metalness: 0.8, roughness: 0.2 });

        const nose = new THREE.Mesh(new THREE.ConeGeometry(0.55, 2.0, 8), cowlMat);
        nose.rotation.x = -Math.PI / 2; nose.position.set(0, 1.05, -1.1); bikeGroup.add(nose);

        const tank = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.75, 2.0), cowlMat);
        tank.position.set(0, 1.0, 0.1); bikeGroup.add(tank);

        const wind = new THREE.Mesh(new THREE.BoxGeometry(0.45, 0.4, 0.7), new THREE.MeshBasicMaterial({ color: 0x0f172a }));
        wind.position.set(0, 1.45, -0.6); bikeGroup.add(wind);

        const exhM = new THREE.Mesh(new THREE.CylinderGeometry(0.15, 0.2, 1.3, 10), new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.9 }));
        exhM.rotation.x = Math.PI / 2; exhM.position.set(0.4, 0.65, 1.0); bikeGroup.add(exhM);

        const flM = new THREE.Mesh(new THREE.ConeGeometry(0.2, 1.0, 8), new THREE.MeshBasicMaterial({ color: 0x00d2ff, transparent: true, opacity: 0 }));
        flM.rotation.x = -Math.PI / 2; flM.position.set(0.4, 0.65, 1.9); bikeGroup.add(flM);
        escapeFlames.push(flM);

        const mWheelMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.9 });
        const mWheelGeo = new THREE.CylinderGeometry(0.5, 0.5, 0.26, 18); mWheelGeo.rotateZ(Math.PI / 2);

        ruedaDelanteraIzq = new THREE.Mesh(mWheelGeo, mWheelMat); ruedaDelanteraIzq.position.set(0, 0.5, -1.35); bikeGroup.add(ruedaDelanteraIzq);
        ruedaDelanteraDer = ruedaDelanteraIzq;

        const rearW = new THREE.Mesh(mWheelGeo, mWheelMat); rearW.position.set(0, 0.5, 1.25); bikeGroup.add(rearW);

        const rHead = new THREE.Mesh(new THREE.SphereGeometry(0.36, 12, 12), new THREE.MeshStandardMaterial({ color: new THREE.Color(avatarConfig.colorTraje).getHex() }));
        rHead.position.set(0, 1.6, -0.1); bikeGroup.add(rHead);

        vehiculo3D.add(bikeGroup);
    }

    vehiculo3D.position.set(0, 0, -80);
    scene3D.add(vehiculo3D);

    carState.x = 0;
    carState.z = -80;
    carState.speed = 0;
    carState.angle = 0;
    carState.turboTimer = 0;
    carState.itemActual = null;
    actualizarHUDItem();
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
    const itemsPosibles = ['nitro', 'hongo', 'escudo'];
    const itemElegido = itemsPosibles[Math.floor(Math.random() * itemsPosibles.length)];
    carState.itemActual = itemElegido;
    actualizarHUDItem();
}

function usarItem3D() {
    if (!carState.itemActual) return;

    if (carState.itemActual === 'nitro') {
        carState.turboTimer = 110;
        carState.speed = 135;
        sfxTurbo();
    } else if (carState.itemActual === 'hongo') {
        carState.turboTimer = 70;
        carState.speed = 125;
        sfxTurbo();
    } else if (carState.itemActual === 'escudo') {
        carState.itemTimer = 180;
        sfxVictoria();
    }

    carState.itemActual = null;
    actualizarHUDItem();
}

function actualizarHUDItem() {
    const icon = document.getElementById('hud_item_icon');
    const box = document.getElementById('hud_item_box');
    if (!icon || !box) return;

    const iconos = { nitro: '🚀', hongo: '🍄', escudo: '⚡' };
    if (carState.itemActual) {
        icon.textContent = iconos[carState.itemActual] || '❓';
        box.style.borderColor = '#00d2ff';
        box.style.boxShadow = '0 0 30px rgba(0, 210, 255, 0.7)';
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

    // 2. Aceleración Suave y Velocidad Equilibrada (Velocidad Karting Real: ~95 km/h)
    const accelBase = (carState.turboTimer > 0 ? 0.65 : 0.38);
    if (adelante) {
        carState.speed += accelBase;
    } else if (atras) {
        carState.speed -= 0.65;
    } else {
        carState.speed *= 0.982; // Fricción suave
    }

    const maxKmh = (carState.turboTimer > 0) ? 130 : 95;
    if (carState.speed > maxKmh) carState.speed = maxKmh;
    if (carState.speed < -25) carState.speed = -25;

    // 3. Sistema de Derrape y Carga de Mini-Turbo
    if (barraEspacio && Math.abs(carState.speed) > 35 && (izquierda || derecha)) {
        carState.isDrifting = true;
        carState.driftTimer++;

        const indicator = document.getElementById('hud_drift_indicator');
        if (indicator) {
            indicator.style.display = 'block';
            if (carState.driftTimer > 75) {
                carState.currentTurboLevel = 2; // Super Mini-Turbo (Naranja)
                indicator.style.color = '#ff6b00';
                indicator.innerHTML = '🔥 SUPER MINI-TURBO!';
            } else if (carState.driftTimer > 35) {
                carState.currentTurboLevel = 1; // Mini-Turbo (Azul)
                indicator.style.color = '#00d2ff';
                indicator.innerHTML = '⚡ MINI-TURBO!';
            }
        }
        sfxDerrape();
    }

    // 4. Maniobrabilidad y Dirección (Giro Natural)
    const factorGiro = Math.min(Math.abs(carState.speed) / 50, 1.0);
    const anguloGiro = (carState.isDrifting ? 0.048 : 0.035) * (carState.speed >= 0 ? 1 : -1);

    if (izquierda) {
        carState.angle += anguloGiro * factorGiro;
        carState.steerAngle = -0.32;
    } else if (derecha) {
        carState.angle -= anguloGiro * factorGiro;
        carState.steerAngle = 0.32;
    } else {
        carState.steerAngle = 0;
    }

    // 5. Desplazamiento Calibrado (Unidades Suaves por Segundo)
    const velUnits = (carState.speed / 3.6) * 0.016 * 1.55;
    let nextX = carState.x - Math.sin(carState.angle) * velUnits * 40;
    let nextZ = carState.z - Math.cos(carState.angle) * velUnits * 40;

    // ==============================================================
    // 6. DETECCIÓN DE COLISIÓN CON PAREDES DE PISTA Y REBOTE
    // ==============================================================
    if (trackPoints3D.length > 0) {
        let minDistSq = Infinity;
        let closestIdx = 0;

        for (let i = 0; i < trackPoints3D.length; i += 2) {
            const pt = trackPoints3D[i];
            const dSq = (nextX - pt.x) ** 2 + (nextZ - pt.z) ** 2;
            if (dSq < minDistSq) {
                minDistSq = dSq;
                closestIdx = i;
            }
        }

        const centerPt = trackPoints3D[closestIdx];
        const nextPt = trackPoints3D[(closestIdx + 1) % trackPoints3D.length];

        const segDx = nextPt.x - centerPt.x;
        const segDz = nextPt.z - centerPt.z;
        const segLen = Math.hypot(segDx, segDz);
        const normX = -segDz / segLen;
        const normZ = segDx / segLen;

        // Distancia lateral relativa al eje central de la pista
        const relX = nextX - centerPt.x;
        const relZ = nextZ - centerPt.z;
        const latDist = relX * normX + relZ * normZ;

        const maxAllowedDist = (20 / 2) - 0.6; // Mitad del ancho de pista (pared en 9.4)

        if (Math.abs(latDist) > maxAllowedDist) {
            // ¡CHOQUE CONTRA LA PARED DE SEGURIDAD!
            const sign = Math.sign(latDist);
            const clampedLatDist = sign * maxAllowedDist;

            // Proyectar el kart de vuelta dentro de la pista
            nextX = centerPt.x + normX * clampedLatDist;
            nextZ = centerPt.z + normZ * clampedLatDist;

            // Pérdida de velocidad y sonido de impacto
            if (Math.abs(carState.speed) > 25) {
                sfxImpacto();
                carState.speed *= 0.58; // Frenado por impacto
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
            const lean = (izquierda ? 0.42 : (derecha ? -0.42 : 0)) * (Math.abs(carState.speed) / 70);
            vehiculo3D.rotation.z = THREE.MathUtils.lerp(vehiculo3D.rotation.z, lean, 0.15);
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
        const distCam = avatarConfig.vehiculo.startsWith('moto') ? 8.0 : 9.5;
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
                carState.turboTimer = 60;
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

    // 9. Chequeo de Vueltas y Meta
    const distMeta = Math.hypot(carState.x, carState.z - (-80));
    if (carState.z > 50) carState.checkpointPassed = true;

    if (distMeta < 16 && carState.checkpointPassed) {
        carState.checkpointPassed = false;
        const lapTime = (performance.now() - carState.lapStartTime);
        carState.lapStartTime = performance.now();

        if (!carState.bestLapTime || lapTime < carState.bestLapTime) {
            carState.bestLapTime = lapTime;
            document.getElementById('hud_best_lap').textContent = formatearTiempo(lapTime);
            guardarRecordServidor('carreras_3d', 0, Math.round(lapTime));
        }

        carState.lap++;
        sfxVictoria();

        if (carState.lap > carState.maxLaps) {
            desbloquearMundo('corporativo');
            carState.lap = 1;
        }
        document.getElementById('hud_lap_count').textContent = carState.lap + ' / ' + carState.maxLaps;
    }

    // 10. Actualizar HUD
    const kmh = Math.max(0, Math.round(Math.abs(carState.speed)));
    document.getElementById('hud_speed').textContent = kmh;
    document.getElementById('hud_lap_time').textContent = formatearTiempo(performance.now() - carState.lapStartTime);

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

    // Punto del Jugador
    const px = centerX + carState.x * scale;
    const py = centerY + carState.z * scale;
    ctx.fillStyle = '#ffe600';
    ctx.beginPath();
    ctx.arc(px, py, 4, 0, Math.PI * 2);
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

function reiniciarCarrera3D() {
    construirVehiculo3D();
    carState.lapStartTime = performance.now();
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
