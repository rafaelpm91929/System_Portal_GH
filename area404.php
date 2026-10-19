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
// GENERADOR DE PISTA ESTILO MARIO KART (CURVAS, PIANOS, ITEMS)
// ==============================================================
function construirPista3DMarioKart() {
    itemBoxes3D = [];
    boostPads3D = [];

    const esRancho = (avatarConfig.mundoSeleccionado === 'rancho');
    const esCorp = (avatarConfig.mundoSeleccionado === 'corporativo');

    // 1. Suelo Base con Césped / Entorno
    const groundGeo = new THREE.PlaneGeometry(900, 900);
    const groundMat = new THREE.MeshLambertMaterial({
        color: esRancho ? 0x689f38 : (esCorp ? 0x071326 : 0x15803d)
    });
    const ground = new THREE.Mesh(groundGeo, groundMat);
    ground.rotation.x = -Math.PI / 2;
    ground.receiveShadow = true;
    scene3D.add(ground);

    // 2. Trazado Curvilíneo Emocionante (Estilo Mario Kart)
    const curvePoints = [
        new THREE.Vector3(0, 0, -85),
        new THREE.Vector3(80, 0, -80),
        new THREE.Vector3(135, 0, -20),
        new THREE.Vector3(120, 0, 60),
        new THREE.Vector3(50, 0, 95),
        new THREE.Vector3(-10, 0, 70),
        new THREE.Vector3(-60, 0, 95),
        new THREE.Vector3(-135, 0, 50),
        new THREE.Vector3(-120, 0, -35),
        new THREE.Vector3(-60, 0, -85)
    ];
    const catmull = new THREE.CatmullRomCurve3(curvePoints, true, 'centripetal');
    trackPoints3D = catmull.getPoints(160);

    const trackWidth = 22; // Pista ancha estilo Mario Kart
    const roadPositions = [];
    const kerbLeftPositions = [];
    const kerbRightPositions = [];
    const roadIndices = [];
    const kerbIndices = [];

    for (let i = 0; i < trackPoints3D.length; i++) {
        const p1 = trackPoints3D[i];
        const p2 = trackPoints3D[(i + 1) % trackPoints3D.length];
        const dx = p2.x - p1.x;
        const dz = p2.z - p1.z;
        const len = Math.hypot(dx, dz);
        const nx = (-dz / len);
        const nz = (dx / len);

        const leftEdge = new THREE.Vector3(p1.x + nx * (trackWidth / 2), 0.08, p1.z + nz * (trackWidth / 2));
        const rightEdge = new THREE.Vector3(p1.x - nx * (trackWidth / 2), 0.08, p1.z - nz * (trackWidth / 2));

        roadPositions.push(leftEdge.x, leftEdge.y, leftEdge.z);
        roadPositions.push(rightEdge.x, rightEdge.y, rightEdge.z);

        // Pianos exteriores a rayas (Kerbs)
        const kerbWidth = 2.4;
        const klOuter = new THREE.Vector3(p1.x + nx * (trackWidth / 2 + kerbWidth), 0.14, p1.z + nz * (trackWidth / 2 + kerbWidth));
        const krOuter = new THREE.Vector3(p1.x - nx * (trackWidth / 2 + kerbWidth), 0.14, p1.z - nz * (trackWidth / 2 + kerbWidth));

        kerbLeftPositions.push(leftEdge.x, 0.12, leftEdge.z);
        kerbLeftPositions.push(klOuter.x, 0.14, klOuter.z);

        kerbRightPositions.push(rightEdge.x, 0.12, rightEdge.z);
        kerbRightPositions.push(krOuter.x, 0.14, krOuter.z);
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
    }

    // Geometría Asfalto
    const roadGeo = new THREE.BufferGeometry();
    roadGeo.setAttribute('position', new THREE.Float32BufferAttribute(roadPositions, 3));
    roadGeo.setIndex(roadIndices);
    roadGeo.computeVertexNormals();

    const roadColor = esRancho ? 0x8d6e63 : (esCorp ? 0x111827 : 0x1e293b);
    const roadMat = new THREE.MeshStandardMaterial({ color: roadColor, roughness: 0.8, metalness: 0.1 });
    const roadMesh = new THREE.Mesh(roadGeo, roadMat);
    roadMesh.receiveShadow = true;
    scene3D.add(roadMesh);

    // Pianos Rojos y Blancos (Bordillos Rayados Mario Kart)
    const kerbGeoL = new THREE.BufferGeometry();
    kerbGeoL.setAttribute('position', new THREE.Float32BufferAttribute(kerbLeftPositions, 3));
    kerbGeoL.setIndex(kerbIndices);
    kerbGeoL.computeVertexNormals();

    const kerbGeoR = new THREE.BufferGeometry();
    kerbGeoR.setAttribute('position', new THREE.Float32BufferAttribute(kerbRightPositions, 3));
    kerbGeoR.setIndex(kerbIndices);
    kerbGeoR.computeVertexNormals();

    // Textura generada por código para pianos bicolores
    const canvasKerb = document.createElement('canvas');
    canvasKerb.width = 64; canvasKerb.height = 16;
    const ctxK = canvasKerb.getContext('2d');
    ctxK.fillStyle = '#e11d48'; ctxK.fillRect(0, 0, 32, 16);
    ctxK.fillStyle = '#ffffff'; ctxK.fillRect(32, 0, 32, 16);
    const texKerb = new THREE.CanvasTexture(canvasKerb);
    texKerb.wrapS = THREE.RepeatWrapping;
    texKerb.wrapT = THREE.RepeatWrapping;
    texKerb.repeat.set(35, 1);

    const kerbMat = new THREE.MeshLambertMaterial({ map: texKerb });
    const meshKL = new THREE.Mesh(kerbGeoL, kerbMat);
    const meshKR = new THREE.Mesh(kerbGeoR, kerbMat);
    scene3D.add(meshKL);
    scene3D.add(meshKR);

    // 3. Arco Monumental de Meta Mario Kart (Grand Prix Grupo Huerta)
    construirArcoMeta(new THREE.Vector3(0, 0, -85));

    // 4. Paneles Aceleradores Neón (Boost Pads Mario Kart)
    const boostIndices = [25, 70, 115];
    boostIndices.forEach(idx => {
        const pt = trackPoints3D[idx];
        const nextPt = trackPoints3D[(idx + 1) % trackPoints3D.length];
        const padGroup = new THREE.Group();

        // Placa en suelo
        const padGeo = new THREE.PlaneGeometry(16, 6);
        const padMat = new THREE.MeshBasicMaterial({ 
            color: 0x00ffff, 
            wireframe: false,
            transparent: true,
            opacity: 0.95
        });
        const padMesh = new THREE.Mesh(padGeo, padMat);
        padMesh.rotation.x = -Math.PI / 2;
        padMesh.position.y = 0.12;
        padGroup.add(padMesh);

        // Flechas chevron animadas
        for (let a = -4; a <= 4; a += 4) {
            const arrGeo = new THREE.ConeGeometry(1.6, 3, 3);
            const arrMat = new THREE.MeshBasicMaterial({ color: 0xffe600 });
            const arr = new THREE.Mesh(arrGeo, arrMat);
            arr.rotation.x = Math.PI / 2;
            arr.position.set(a, 0.18, 0);
            padGroup.add(arr);
        }

        padGroup.position.set(pt.x, 0, pt.z);
        padGroup.lookAt(nextPt.x, 0, nextPt.z);
        scene3D.add(padGroup);
        boostPads3D.push({ x: pt.x, z: pt.z, radius: 9, mesh: padGroup });
    });

    // 5. Cajas de Objetos Flotantes (Floating Mystery Item Boxes)
    const itemIndices = [15, 50, 90, 135];
    itemIndices.forEach(idx => {
        const pt = trackPoints3D[idx];
        for (let lane = -6; lane <= 6; lane += 6) {
            const boxGroup = new THREE.Group();

            // Cubo dorado semitransparente
            const boxGeo = new THREE.BoxGeometry(2.4, 2.4, 2.4);
            const boxMat = new THREE.MeshStandardMaterial({
                color: 0xffe600,
                emissive: 0xffaa00,
                emissiveIntensity: 0.65,
                transparent: true,
                opacity: 0.85,
                roughness: 0.1,
                metalness: 0.8
            });
            const cube = new THREE.Mesh(boxGeo, boxMat);
            boxGroup.add(cube);

            // Borde neón
            const wireGeo = new THREE.EdgesGeometry(boxGeo);
            const wireMat = new THREE.LineBasicMaterial({ color: 0xffffff, linewidth: 2 });
            const wire = new THREE.LineSegments(wireGeo, wireMat);
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

    // 6. Elementos Escénicos según Mundo
    for (let i = 0; i < trackPoints3D.length; i += 6) {
        const pt = trackPoints3D[i];
        const bannerPos = { x: pt.x * 1.25, z: pt.z * 1.25 };

        if (esCorp) {
            // Rascacielos iluminados
            const h = 45 + Math.random() * 85;
            const bGeo = new THREE.BoxGeometry(18, h, 18);
            const bMat = new THREE.MeshStandardMaterial({
                color: 0x0a1c38,
                emissive: (i % 12 === 0 ? 0x00d2ff : 0x020817),
                emissiveIntensity: 0.5
            });
            const bld = new THREE.Mesh(bGeo, bMat);
            bld.position.set(bannerPos.x * 1.35, h / 2, bannerPos.z * 1.35);
            scene3D.add(bld);
        } else if (esRancho) {
            // Molinos de viento y árboles campestres
            if (i % 18 === 0) {
                construirMolinoViento(bannerPos.x, bannerPos.z);
            } else {
                construirArbolCartoon(bannerPos.x, bannerPos.z);
            }
        } else {
            // Farolas y vallas de Agencias Grupo Huerta / Suzuki / Divol
            if (i % 12 === 0) {
                construirFarolaNeón(bannerPos.x, bannerPos.z);
            } else {
                construirVallaSponsor(bannerPos.x, bannerPos.z, i);
            }
        }
    }
}

function construirArcoMeta(pos) {
    const archGroup = new THREE.Group();

    // Columnas metálicas trusses
    const colGeo = new THREE.BoxGeometry(2, 14, 2);
    const colMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.3 });
    const colL = new THREE.Mesh(colGeo, colMat); colL.position.set(-14, 7, 0); archGroup.add(colL);
    const colR = new THREE.Mesh(colGeo, colMat); colR.position.set(14, 7, 0); archGroup.add(colR);

    // Viga superior
    const beamGeo = new THREE.BoxGeometry(30, 3.5, 3);
    const beamMat = new THREE.MeshStandardMaterial({ color: 0x00d2ff, emissive: 0x0077b6, emissiveIntensity: 0.5 });
    const beam = new THREE.Mesh(beamGeo, beamMat);
    beam.position.set(0, 13, 0);
    archGroup.add(beam);

    // Letrero 3D "🏁 GRUPO HUERTA GP"
    const signCanvas = document.createElement('canvas');
    signCanvas.width = 512; signCanvas.height = 128;
    const ctxS = signCanvas.getContext('2d');
    ctxS.fillStyle = '#030a16'; ctxS.fillRect(0, 0, 512, 128);
    ctxS.strokeStyle = '#00d2ff'; ctxS.lineWidth = 6; ctxS.strokeRect(4, 4, 504, 120);
    ctxS.fillStyle = '#ffffff'; ctxS.font = 'bold 36px sans-serif'; ctxS.textAlign = 'center';
    ctxS.fillText('🏁 GRUPO HUERTA GRAND PRIX 🏁', 256, 75);
    const signTex = new THREE.CanvasTexture(signCanvas);

    const signGeo = new THREE.PlaneGeometry(24, 5);
    const signMat = new THREE.MeshBasicMaterial({ map: signTex, side: THREE.DoubleSide });
    const signMesh = new THREE.Mesh(signGeo, signMat);
    signMesh.position.set(0, 13, 1.6);
    archGroup.add(signMesh);

    archGroup.position.copy(pos);
    scene3D.add(archGroup);
}

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

function construirVallaSponsor(x, z, i) {
    const vallaGeo = new THREE.BoxGeometry(10, 3.2, 0.8);
    const color = (i % 16 === 0 ? 0x2563eb : (i % 24 === 0 ? 0x22c55e : 0x0284c7));
    const vallaMat = new THREE.MeshLambertMaterial({ color: color });
    const valla = new THREE.Mesh(vallaGeo, vallaMat);
    valla.position.set(x, 1.6, z);
    valla.lookAt(0, 1.6, 0);
    scene3D.add(valla);
}

// ==============================================================
// CONSTRUCTOR DE VEHÍCULO 3D DETALLADO ESTILO MARIO KART
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
        // RACING KART ESTILO MARIO KART
        // ==========================================
        // Chasis principal de kart
        const bodyGeo = new THREE.BoxGeometry(2.4, 0.75, 4.2);
        const bodyMat = new THREE.MeshStandardMaterial({ color: colorPrimario, metalness: 0.7, roughness: 0.25 });
        const carBody = new THREE.Mesh(bodyGeo, bodyMat);
        carBody.position.y = 0.7;
        carBody.castShadow = true;
        vehiculo3D.add(carBody);

        // Morro redondeado con emblema
        const noseGeo = new THREE.CylinderGeometry(0.9, 1.2, 1.2, 16);
        noseGeo.rotateX(Math.PI / 2);
        const nose = new THREE.Mesh(noseGeo, bodyMat);
        nose.position.set(0, 0.65, -2.1);
        vehiculo3D.add(nose);

        // Asiento deportivo bucket
        const seatGeo = new THREE.BoxGeometry(1.4, 1.4, 0.8);
        const seatMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.9 });
        const seat = new THREE.Mesh(seatGeo, seatMat);
        seat.position.set(0, 1.2, 0.3);
        vehiculo3D.add(seat);

        // Volante de carreras
        const wheelGeo = new THREE.TorusGeometry(0.35, 0.08, 8, 16);
        const wheelMat = new THREE.MeshStandardMaterial({ color: 0x334155 });
        const steeringWheel = new THREE.Mesh(wheelGeo, wheelMat);
        steeringWheel.rotation.x = Math.PI / 4;
        steeringWheel.position.set(0, 1.15, -0.6);
        vehiculo3D.add(steeringWheel);

        // Alerón trasero elevado estilo kart GP
        const wingPillarGeo = new THREE.BoxGeometry(0.2, 1.0, 0.4);
        const pL = new THREE.Mesh(wingPillarGeo, seatMat); pL.position.set(-0.9, 1.3, 1.8); vehiculo3D.add(pL);
        const pR = new THREE.Mesh(wingPillarGeo, seatMat); pR.position.set(0.9, 1.3, 1.8); vehiculo3D.add(pR);

        const wingGeo = new THREE.BoxGeometry(2.8, 0.15, 0.9);
        const wingMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.6 });
        const wing = new THREE.Mesh(wingGeo, wingMat);
        wing.position.set(0, 1.8, 1.8);
        vehiculo3D.add(wing);

        // Tubos de escape dobles cromados
        const exhGeo = new THREE.CylinderGeometry(0.2, 0.2, 0.8, 12);
        exhGeo.rotateX(Math.PI / 2);
        const exhMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, metalness: 0.95, roughness: 0.1 });
        const exL = new THREE.Mesh(exhGeo, exhMat); exL.position.set(-0.6, 0.65, 2.3); vehiculo3D.add(exL);
        const exR = new THREE.Mesh(exhGeo, exhMat); exR.position.set(0.6, 0.65, 2.3); vehiculo3D.add(exR);

        // Llamaradas animadas en escapes (Efecto Nitro Mario Kart)
        const flameGeo = new THREE.ConeGeometry(0.25, 1.2, 8);
        flameGeo.rotateX(-Math.PI / 2);
        const flameMat = new THREE.MeshBasicMaterial({ color: 0x00d2ff, transparent: true, opacity: 0 });
        const flL = new THREE.Mesh(flameGeo, flameMat); flL.position.set(-0.6, 0.65, 3.1); vehiculo3D.add(flL);
        const flR = new THREE.Mesh(flameGeo, flameMat); flR.position.set(0.6, 0.65, 3.1); vehiculo3D.add(flR);
        escapeFlames.push(flL, flR);

        // Ruedas anchas con rines cromados
        const rTireGeo = new THREE.CylinderGeometry(0.48, 0.48, 0.45, 18);
        rTireGeo.rotateZ(Math.PI / 2);
        const tireMat = new THREE.MeshStandardMaterial({ color: 0x111827, roughness: 0.9 });

        ruedaDelanteraIzq = new THREE.Mesh(rTireGeo, tireMat); ruedaDelanteraIzq.position.set(-1.35, 0.48, -1.3); vehiculo3D.add(ruedaDelanteraIzq);
        ruedaDelanteraDer = new THREE.Mesh(rTireGeo, tireMat); ruedaDelanteraDer.position.set(1.35, 0.48, -1.3); vehiculo3D.add(ruedaDelanteraDer);
        const rti = new THREE.Mesh(rTireGeo, tireMat); rti.position.set(-1.35, 0.48, 1.4); vehiculo3D.add(rti);
        const rtd = new THREE.Mesh(rTireGeo, tireMat); rtd.position.set(1.35, 0.48, 1.4); vehiculo3D.add(rtd);

        // Piloto 3D detallado con casco
        const driver = new THREE.Group();
        const headGeo = new THREE.SphereGeometry(0.42, 14, 14);
        const headMat = new THREE.MeshStandardMaterial({ color: new THREE.Color(avatarConfig.colorTraje).getHex() });
        const head = new THREE.Mesh(headGeo, headMat);
        head.position.set(0, 1.7, 0.2);
        driver.add(head);

        // Visor de carreras
        const visorGeo = new THREE.BoxGeometry(0.45, 0.18, 0.3);
        const visorMat = new THREE.MeshBasicMaterial({ color: 0x00d2ff });
        const visor = new THREE.Mesh(visorGeo, visorMat);
        visor.position.set(0, 1.7, -0.15);
        driver.add(visor);

        vehiculo3D.add(driver);
    } else {
        // ==========================================
        // MOTO RACING SUPERBIKE (SUZUKI / KAWASAKI)
        // ==========================================
        const bikeBody = new THREE.Group();

        // Carenado aerodinámico frontal
        const noseGeo = new THREE.ConeGeometry(0.6, 2.2, 8);
        noseGeo.rotateX(-Math.PI / 2);
        const cowlMat = new THREE.MeshStandardMaterial({ color: colorPrimario, metalness: 0.8, roughness: 0.2 });
        const nose = new THREE.Mesh(noseGeo, cowlMat);
        nose.position.set(0, 1.1, -1.1);
        bikeBody.add(nose);

        // Depósito y colín
        const tankGeo = new THREE.BoxGeometry(0.7, 0.8, 2.2);
        const tank = new THREE.Mesh(tankGeo, cowlMat);
        tank.position.set(0, 1.05, 0.1);
        bikeBody.add(tank);

        // Parabrisas burbuja ahumado
        const screenGeo = new THREE.BoxGeometry(0.5, 0.45, 0.8);
        const screenMat = new THREE.MeshBasicMaterial({ color: 0x0f172a });
        const screen = new THREE.Mesh(screenGeo, screenMat);
        screen.position.set(0, 1.5, -0.6);
        bikeBody.add(screen);

        // Tubo de escape Akrapovic con llamaradas
        const exhMGeo = new THREE.CylinderGeometry(0.16, 0.22, 1.4, 12);
        exhMGeo.rotateX(Math.PI / 2);
        const exhMMat = new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.9 });
        const exhM = new THREE.Mesh(exhMGeo, exhMMat);
        exhM.position.set(0.45, 0.7, 1.1);
        bikeBody.add(exhM);

        const flM = new THREE.Mesh(new THREE.ConeGeometry(0.2, 1.0, 8), new THREE.MeshBasicMaterial({ color: 0x00d2ff, transparent: true, opacity: 0 }));
        flM.rotation.x = -Math.PI / 2;
        flM.position.set(0.45, 0.7, 2.0);
        bikeBody.add(flM);
        escapeFlames.push(flM);

        // Ruedas delantera y trasera
        const mWheelGeo = new THREE.CylinderGeometry(0.52, 0.52, 0.28, 18);
        mWheelGeo.rotateZ(Math.PI / 2);
        const mWheelMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.9 });

        ruedaDelanteraIzq = new THREE.Mesh(mWheelGeo, mWheelMat);
        ruedaDelanteraIzq.position.set(0, 0.52, -1.4);
        bikeBody.add(ruedaDelanteraIzq);
        ruedaDelanteraDer = ruedaDelanteraIzq;

        const rearW = new THREE.Mesh(mWheelGeo, mWheelMat);
        rearW.position.set(0, 0.52, 1.3);
        bikeBody.add(rearW);

        // Piloto agachado con rodilla afuera
        const rider = new THREE.Group();
        const rHead = new THREE.Mesh(new THREE.SphereGeometry(0.38, 12, 12), new THREE.MeshStandardMaterial({ color: new THREE.Color(avatarConfig.colorTraje).getHex() }));
        rHead.position.set(0, 1.65, -0.1);
        rider.add(rHead);

        bikeBody.add(rider);
        vehiculo3D.add(bikeBody);
    }

    // Posición inicial en parrilla de salida
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
        // Super Mini-Turbo (Naranja / Fuego)
        carState.turboTimer = 90; // 1.5 segs
        carState.speed = Math.min(carState.speed + 60, 240);
        sfxTurbo();
    } else if (carState.currentTurboLevel === 1) {
        // Mini-Turbo (Azul)
        carState.turboTimer = 50; // 0.8 segs
        carState.speed = Math.min(carState.speed + 35, 215);
        sfxTurbo();
    }

    carState.isDrifting = false;
    carState.driftTimer = 0;
    carState.currentTurboLevel = 0;
}

// ==============================================================
// SISTEMA DE ITEMS (RULETA Y ACTIVACIÓN [E])
// ==============================================================
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
        carState.turboTimer = 140;
        carState.speed = 240;
        sfxTurbo();
    } else if (carState.itemActual === 'hongo') {
        carState.turboTimer = 90;
        carState.speed = 220;
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
// BUCLE DE ANIMACIÓN Y RENDERIZADO 3D (60 FPS)
// ==============================================================
function animar3D() {
    animId3D = requestAnimationFrame(animar3D);

    const adelante = (keysPressed['KeyW'] || keysPressed['ArrowUp']);
    const atras = (keysPressed['KeyS'] || keysPressed['ArrowDown']);
    const izquierda = (keysPressed['KeyA'] || keysPressed['ArrowLeft']);
    const derecha = (keysPressed['KeyD'] || keysPressed['ArrowRight']);
    const barraEspacio = keysPressed['Space'];

    // 1. Manejo del Turbo
    if (carState.turboTimer > 0) {
        carState.turboTimer--;
        carState.speed = Math.max(carState.speed, 210);
        camera3D.fov = THREE.MathUtils.lerp(camera3D.fov, 80, 0.15); // Zoom dinámico de velocidad
    } else {
        camera3D.fov = THREE.MathUtils.lerp(camera3D.fov, 65, 0.1);
    }
    camera3D.updateProjectionMatrix();

    // 2. Aceleración y Fricción
    if (adelante) {
        carState.speed += (carState.turboTimer > 0 ? carState.accel * 1.6 : carState.accel);
    } else if (atras) {
        carState.speed -= carState.brake;
    } else {
        carState.speed *= carState.friction;
    }

    const maxKmh = (carState.turboTimer > 0) ? 245 : carState.maxSpeed;
    if (carState.speed > maxKmh) carState.speed = maxKmh;
    if (carState.speed < -40) carState.speed = -40;

    // 3. Sistema de Derrape Mario Kart
    if (barraEspacio && Math.abs(carState.speed) > 40 && (izquierda || derecha)) {
        carState.isDrifting = true;
        carState.driftTimer++;

        const indicator = document.getElementById('hud_drift_indicator');
        if (indicator) {
            indicator.style.display = 'block';
            if (carState.driftTimer > 90) {
                // Nivel 2: Super Mini-Turbo (Naranja)
                carState.currentTurboLevel = 2;
                indicator.style.color = '#ff6b00';
                indicator.innerHTML = '🔥 SUPER MINI-TURBO!';
            } else if (carState.driftTimer > 40) {
                // Nivel 1: Mini-Turbo (Azul)
                carState.currentTurboLevel = 1;
                indicator.style.color = '#00d2ff';
                indicator.innerHTML = '⚡ MINI-TURBO!';
            }
        }
        sfxDerrape();
    }

    // 4. Dirección y Giro
    const factorGiro = Math.min(Math.abs(carState.speed) / 60, 1.0);
    const anguloGiro = (carState.isDrifting ? 0.055 : 0.038) * (carState.speed >= 0 ? 1 : -1);

    if (izquierda) {
        carState.angle += anguloGiro * factorGiro;
        carState.steerAngle = -0.35;
    } else if (derecha) {
        carState.angle -= anguloGiro * factorGiro;
        carState.steerAngle = 0.35;
    } else {
        carState.steerAngle = 0;
    }

    // 5. Mover Vehículo
    const velMs = (carState.speed / 3.6) * 0.016;
    carState.x -= Math.sin(carState.angle) * velMs * 50;
    carState.z -= Math.cos(carState.angle) * velMs * 50;

    if (vehiculo3D) {
        vehiculo3D.position.x = carState.x;
        vehiculo3D.position.z = carState.z;
        vehiculo3D.rotation.y = carState.angle;

        // Inclinación realista en motos
        if (avatarConfig.vehiculo.startsWith('moto')) {
            const lean = (izquierda ? 0.45 : (derecha ? -0.45 : 0)) * (Math.abs(carState.speed) / 120);
            vehiculo3D.rotation.z = THREE.MathUtils.lerp(vehiculo3D.rotation.z, lean, 0.12);
        }

        // Ruedas y Llamaradas de Nitro
        if (ruedaDelanteraIzq) ruedaDelanteraIzq.rotation.y = carState.steerAngle;
        if (ruedaDelanteraDer && ruedaDelanteraDer !== ruedaDelanteraIzq) ruedaDelanteraDer.rotation.y = carState.steerAngle;

        // Animar fuego de escape al acelerar o turbo
        const opFuego = (carState.turboTimer > 0) ? 0.95 : (adelante && carState.speed > 80 ? 0.45 : 0);
        escapeFlames.forEach(fl => {
            fl.material.opacity = opFuego;
            fl.scale.set(1 + Math.random() * 0.3, 1 + Math.random() * 0.4, 1);
        });

        // Cámara de persecución dinámica
        const distCam = avatarConfig.vehiculo.startsWith('moto') ? 7.2 : 8.8;
        const altoCam = avatarConfig.vehiculo.startsWith('moto') ? 3.6 : 4.2;
        const targetCamX = carState.x + Math.sin(carState.angle) * distCam;
        const targetCamZ = carState.z + Math.cos(carState.angle) * distCam;

        camera3D.position.x = THREE.MathUtils.lerp(camera3D.position.x, targetCamX, 0.12);
        camera3D.position.y = THREE.MathUtils.lerp(camera3D.position.y, altoCam, 0.12);
        camera3D.position.z = THREE.MathUtils.lerp(camera3D.position.z, targetCamZ, 0.12);
        camera3D.lookAt(carState.x, 1.3, carState.z);
    }

    // 6. Colisión con Paneles Aceleradores (Boost Pads)
    for (const pad of boostPads3D) {
        if (Math.hypot(carState.x - pad.x, carState.z - pad.z) < pad.radius) {
            if (carState.turboTimer <= 10) {
                carState.turboTimer = 70;
                carState.speed = 230;
                sfxTurbo();
            }
        }
    }

    // 7. Colisión y Animación de Cajas de Objetos (Item Boxes)
    for (const box of itemBoxes3D) {
        if (box.activo) {
            box.group.rotation.y += 0.04;
            box.group.position.y = 2.2 + Math.sin(performance.now() * 0.005) * 0.3;

            if (Math.hypot(carState.x - box.x, carState.z - box.z) < 3.2) {
                box.activo = false;
                box.group.visible = false;
                box.respawnTimer = 250; // Reaparece en ~4 segundos
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

    // 8. Chequeo de Vueltas y Meta
    const distMeta = Math.hypot(carState.x, carState.z - (-80));
    if (carState.z > 60) carState.checkpointPassed = true;

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

    // 9. Actualizar HUD
    const kmh = Math.max(0, Math.round(Math.abs(carState.speed)));
    document.getElementById('hud_speed').textContent = kmh;
    document.getElementById('hud_lap_time').textContent = formatearTiempo(performance.now() - carState.lapStartTime);

    // 10. Actualizar Mini-Mapa Radar
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
    const scale = 0.38;

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
