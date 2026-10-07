<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (file_exists('conexion.php')) {
    include_once 'conexion.php';
} else {
    $pdo = null;
}

$logoGH = 'img/logo_gh.jpg';
$nombrePortal = 'PORTAL GRUPO HUERTA';

// Si el usuario ya está autenticado, redirigir al menú
if (isset($_SESSION['usuario_id'])) {
    header("Location: menu.php");
    exit();
}

$error = '';
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
       || (isset($_GET['ajax']) && $_GET['ajax'] == '1')
       || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['usuario'] ?? '');
    $password_input = trim($_POST['password'] ?? '');

    if (!empty($user_input) && !empty($password_input)) {
        if ($pdo) {
            try {
                // 1. Buscar usuario por login o email
                $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE (LOWER(usuario) = LOWER(?) OR LOWER(email) = LOWER(?)) LIMIT 1");
                $stmt->execute([$user_input, $user_input]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $estaActivo = isset($user['activo']) ? intval($user['activo']) : 1;
                    $tieneAccesoPortal = isset($user['acceso_portal']) ? intval($user['acceso_portal']) : 1;

                    if ($estaActivo !== 1) {
                        $error = "Tu cuenta se encuentra inactiva. Por favor contacta al administrador.";
                    } elseif ($tieneAccesoPortal !== 1) {
                        $error = "Este usuario está registrado únicamente como información de personal y no cuenta con acceso al portal.";
                    } else {
                        // 2. Verificar contraseña
                        $passwordValida = false;

                        if (password_verify($password_input, $user['password'])) {
                            $passwordValida = true;
                        } elseif ($user['password'] === $password_input) {
                            $passwordValida = true;
                            try {
                                $newHash = password_hash($password_input, PASSWORD_DEFAULT);
                                $updateStmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                                $updateStmt->execute([$newHash, $user['id']]);
                            } catch (Throwable $e) {}
                        }

                        if ($passwordValida) {
                            $_SESSION['usuario_id'] = $user['id'];
                            $_SESSION['usuario_nombre'] = $user['nombre'];
                            $_SESSION['usuario_login'] = $user['usuario'] ?? '';
                            $_SESSION['usuario_email'] = $user['email'] ?? '';
                            $_SESSION['usuario_rol'] = $user['rol'] ?? 'SuperAdmin';
                            $_SESSION['agencia'] = $user['agencia'] ?? 'Oficina Central Grupo Huerta';

                            // Cargar permisos en la sesión
                            if (file_exists('permisos_helper.php')) {
                                require_once 'permisos_helper.php';
                                cargarPermisosSesion($pdo, $user['id']);
                            }

                            if ($isAjax) {
                                header('Content-Type: application/json; charset=utf-8');
                                echo json_encode([
                                    'exito' => true,
                                    'usuario' => $user['usuario'] ?? $user_input,
                                    'nombre' => $user['nombre'] ?? $user_input,
                                    'rol' => $user['rol'] ?? 'SuperAdmin',
                                    'agencia' => 'Grupo Huerta',
                                    'logo' => $logoGH,
                                    'redirect' => 'menu.php'
                                ], JSON_UNESCAPED_UNICODE);
                                exit();
                            }

                            header("Location: menu.php");
                            exit();
                        } else {
                            $error = "La contraseña ingresada es incorrecta.";
                        }
                    }
                } else {
                    $error = "El usuario <strong>".htmlspecialchars($user_input)."</strong> no existe en el sistema.";
                }
            } catch (PDOException $e) {
                $error = "Error al verificar credenciales en la base de datos: " . $e->getMessage();
            }
        } else {
            // Fallback de emergencia solo si no hay conexión a MySQL
            if (($user_input === 'tilavilla' || $user_input === 'admin' || $user_input === 'sistemas') && $password_input === 'Admin123!') {
                $_SESSION['usuario_id'] = 1;
                $_SESSION['usuario_nombre'] = 'SuperAdmin Grupo Huerta';
                $_SESSION['usuario_login'] = 'admin';
                $_SESSION['usuario_rol'] = 'SuperAdmin';
                $_SESSION['agencia'] = 'Oficina Central Grupo Huerta';

                if (file_exists('permisos_helper.php')) {
                    require_once 'permisos_helper.php';
                    cargarPermisosSesion($pdo, 1);
                }

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'exito' => true,
                        'usuario' => 'admin',
                        'nombre' => 'SuperAdmin Grupo Huerta',
                        'rol' => 'SuperAdmin',
                        'agencia' => 'Grupo Huerta',
                        'logo' => $logoGH,
                        'redirect' => 'menu.php'
                    ], JSON_UNESCAPED_UNICODE);
                    exit();
                }

                header("Location: menu.php");
                exit();
            } else {
                $msgDetalle = isset($GLOBALS['conexion_error']) ? $GLOBALS['conexion_error'] : (isset($conexion_error) ? $conexion_error : 'Detalle no disponible');
                $error = "No hay conexión a la base de datos MySQL en cPanel. <br><small class='text-warning'>Detalle técnico: " . htmlspecialchars($msgDetalle) . "</small>";
            }
        }
    } else {
        $error = "Por favor ingresa tu usuario y contraseña.";
    }

    if ($isAjax && !empty($error)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['exito' => false, 'mensaje' => $error], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORTAL GRUPO HUERTA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Three.js para renderizado interactivo en 3D -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <style>
        :root {
            --bg-white: #ffffff;
            --bg-soft: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --gold-accent: #d4af37;
            --gold-dark: #b89728;
            --gold-glow: rgba(212, 175, 55, 0.28);
            --charcoal: #0f172a;
            --border-soft: #e2e8f0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-white);
            color: var(--text-primary);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        /* LIENZO 3D INTERACTIVO (FONDO THREE.JS SOBRE BLANCO) */
        #webgl-canvas-3d {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 1;
            pointer-events: none;
            display: block;
        }

        /* Fondo ambiental mesh limpio */
        .ambient-mesh {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 0;
            background: 
                radial-gradient(circle at 15% 20%, rgba(212, 175, 55, 0.08) 0%, transparent 45%),
                radial-gradient(circle at 85% 75%, rgba(15, 23, 42, 0.04) 0%, transparent 45%),
                radial-gradient(circle at 50% 50%, #ffffff 0%, #f8fafc 100%);
            pointer-events: none;
        }

        /* CONTENEDOR PRINCIPAL */
        .login-wrapper {
            width: 100%;
            max-width: 1060px;
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 48px;
            align-items: center;
            padding: 24px;
            position: relative;
            z-index: 10;
            perspective: 1400px;
        }

        @media (max-width: 900px) {
            .login-wrapper {
                grid-template-columns: 1fr;
                gap: 20px;
                max-width: 480px;
            }
            .hero-section {
                display: none !important;
            }
        }

        /* SECCIÓN HERO (LATERAL IZQUIERDO) */
        .hero-section {
            padding: 20px;
            user-select: none;
        }

        .hero-logo-box {
            margin-bottom: 24px;
            display: inline-block;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.08));
            animation: floatSlow 5s ease-in-out infinite;
        }

        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }

        .hero-title {
            font-size: 2.9rem;
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 0;
            letter-spacing: -0.5px;
            color: var(--charcoal);
        }

        .gold-gradient-text {
            background: linear-gradient(135deg, #0f172a 0%, #d4af37 60%, #b89728 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* TARJETA DE LOGIN CON EFECTO 3D TILT */
        .login-card-container {
            perspective: 1200px;
            position: relative;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(212, 175, 55, 0.35);
            border-radius: 24px;
            padding: 44px 38px;
            box-shadow: 
                0 25px 60px -15px rgba(15, 23, 42, 0.12),
                0 0 30px rgba(212, 175, 55, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            position: relative;
            overflow: hidden;
            transform-style: preserve-3d;
            transition: transform 0.15s ease-out, box-shadow 0.25s ease;
        }

        /* Brillo de luz que sigue el cursor */
        .card-sheen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            background: radial-gradient(circle 280px at 50% 50%, rgba(212, 175, 55, 0.12), transparent 70%);
            border-radius: 24px;
            transition: opacity 0.3s;
            opacity: 0;
            z-index: 2;
        }

        .login-card-container:hover .card-sheen {
            opacity: 1;
        }

        .login-card.card-shaking {
            animation: shakeCard 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
        }

        @keyframes shakeCard {
            10%, 90% { transform: translate3d(-3px, 0, 0); }
            20%, 80% { transform: translate3d(5px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-6px, 0, 0); }
            40%, 60% { transform: translate3d(6px, 0, 0); }
        }

        /* ENCABEZADO DE TARJETA CON LOGO REGISTRADO */
        .card-header-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            padding: 12px 22px;
            background: #ffffff;
            border: 1px solid rgba(212, 175, 55, 0.35);
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.06);
            transition: all 0.3s ease;
        }

        .card-header-logo img {
            max-height: 60px;
            max-width: 190px;
            object-fit: contain;
            transition: transform 0.3s ease;
        }

        .login-card-container:hover .card-header-logo img {
            transform: scale(1.03);
        }

        .card-title-text {
            color: #0f172a;
        }

        .card-subtitle-text {
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .form-label {
            color: #334155;
            font-size: 0.86rem;
            font-weight: 700;
            margin-bottom: 7px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i {
            color: #d4af37;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-left {
            position: absolute;
            left: 15px;
            color: #64748b;
            font-size: 1.05rem;
            z-index: 4;
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-control-custom {
            background-color: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            color: #0f172a;
            padding: 13px 16px 13px 44px;
            font-size: 0.95rem;
            width: 100%;
            transition: all 0.25s ease;
        }

        .form-control-custom:focus {
            background-color: #ffffff;
            border-color: #d4af37;
            color: #0f172a;
            outline: none;
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.18);
        }

        .form-control-custom:focus + .input-icon-left,
        .input-group-custom:focus-within .input-icon-left {
            color: #d4af37;
        }

        .form-control-custom::placeholder {
            color: #94a3b8;
        }

        .btn-toggle-pwd {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            color: #64748b;
            padding: 6px;
            cursor: pointer;
            z-index: 4;
            font-size: 1.1rem;
            transition: color 0.2s;
        }

        .btn-toggle-pwd:hover {
            color: #0f172a;
        }

        /* BOTÓN DE ACCESO 3D EJECUTIVO */
        .btn-submit-3d {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border: 1px solid rgba(212, 175, 55, 0.45);
            color: #ffffff;
            font-weight: 700;
            font-size: 1rem;
            padding: 14px;
            border-radius: 12px;
            width: 100%;
            margin-top: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.22), 0 0 15px rgba(212, 175, 55, 0.15);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-submit-3d::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(60deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transform: rotate(25deg) translateY(-100%);
            transition: transform 0.6s ease;
        }

        .btn-submit-3d:hover {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.28), 0 0 25px rgba(212, 175, 55, 0.35);
        }

        .btn-submit-3d:hover::after {
            transform: rotate(25deg) translateY(100%);
        }

        .btn-submit-3d:active {
            transform: translateY(0);
        }

        .btn-submit-3d:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ==============================================================
           HOLOGRAPHIC 3D SUCCESS PORTAL (ANIMACIÓN CUANDO INGRESA BIEN)
           ============================================================== */
        #loginSuccessPortal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at center, rgba(255, 255, 255, 0.96) 0%, rgba(248, 250, 252, 0.98) 100%);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            opacity: 0;
            transition: opacity 0.4s ease;
            padding: 20px;
        }

        .holo-center-stage {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            max-width: 540px;
            width: 100%;
            position: relative;
            transform: scale(0.85);
            transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        #loginSuccessPortal.active .holo-center-stage {
            transform: scale(1);
        }

        /* Anillos de energía 3D */
        .holo-ring-wrapper {
            position: relative;
            width: 210px;
            height: 210px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 28px;
        }

        .holo-ring-outer {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px dashed rgba(212, 175, 55, 0.7);
            box-shadow: 0 0 35px rgba(212, 175, 55, 0.3), inset 0 0 25px rgba(212, 175, 55, 0.15);
            animation: spinHolo 8s linear infinite;
        }

        .holo-ring-inner {
            position: absolute;
            inset: 16px;
            border-radius: 50%;
            border: 2px solid rgba(15, 23, 42, 0.85);
            border-top-color: #d4af37;
            border-bottom-color: #d4af37;
            box-shadow: 0 0 25px rgba(212, 175, 55, 0.25);
            animation: spinHoloRev 4.5s linear infinite;
        }

        .holo-pulse-wave {
            position: absolute;
            inset: -20px;
            border-radius: 50%;
            border: 1px solid rgba(212, 175, 55, 0.55);
            opacity: 0;
            animation: shockwavePulse 1.8s cubic-bezier(0.1, 0.8, 0.3, 1) infinite;
        }

        @keyframes spinHolo {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes spinHoloRev {
            0% { transform: rotate(360deg); }
            100% { transform: rotate(0deg); }
        }

        @keyframes shockwavePulse {
            0% { transform: scale(0.6); opacity: 0.9; }
            100% { transform: scale(1.6); opacity: 0; }
        }

        /* Cápsula central flotante del Logo registrado */
        .holo-logo-capsule {
            width: 145px;
            height: 145px;
            border-radius: 50%;
            background: #ffffff;
            border: 2.5px solid #d4af37;
            box-shadow: 
                0 0 35px rgba(212, 175, 55, 0.4),
                0 12px 30px rgba(15, 23, 42, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            position: relative;
            z-index: 5;
            animation: floatLogo 2.5s ease-in-out infinite;
        }

        @keyframes floatLogo {
            0%, 100% { transform: translateY(0px) scale(1); }
            50% { transform: translateY(-7px) scale(1.03); }
        }

        .holo-logo-capsule img {
            max-width: 105px;
            max-height: 85px;
            object-fit: contain;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.1));
        }

        .holo-logo-capsule .fallback-icon {
            font-size: 3.2rem;
            color: #d4af37;
            filter: drop-shadow(0 0 15px rgba(212, 175, 55, 0.4));
        }

        /* Insignia y Títulos */
        .badge-acceso-ok {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 20px;
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.45);
            color: #16a34a;
            font-size: 0.85rem;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            border-radius: 30px;
            margin-bottom: 16px;
            box-shadow: 0 0 20px rgba(34, 197, 94, 0.2);
            animation: pulseGlowGreen 2s infinite;
        }

        @keyframes pulseGlowGreen {
            0%, 100% { box-shadow: 0 0 12px rgba(34, 197, 94, 0.2); }
            50% { box-shadow: 0 0 25px rgba(34, 197, 94, 0.4); }
        }

        .holo-welcome-name {
            font-size: 2.3rem;
            font-weight: 900;
            margin-bottom: 8px;
            color: #0f172a;
            letter-spacing: -0.5px;
        }

        .holo-welcome-name span {
            background: linear-gradient(135deg, #0f172a 0%, #d4af37 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .holo-agency-name {
            color: #64748b;
            font-size: 1.05rem;
            margin-bottom: 24px;
        }

        .holo-agency-name strong {
            color: #0f172a;
        }

        /* Barra de progreso */
        .holo-progress-container {
            width: 100%;
            max-width: 380px;
            background: #e2e8f0;
            border: 1px solid rgba(212, 175, 55, 0.35);
            border-radius: 12px;
            height: 10px;
            overflow: hidden;
            position: relative;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.08);
            margin-bottom: 12px;
        }

        .holo-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #d4af37 0%, #0f172a 100%);
            box-shadow: 0 0 15px rgba(212, 175, 55, 0.5);
            border-radius: 12px;
            transition: width 0.05s linear;
        }

        .holo-status-text {
            color: #64748b;
            font-family: monospace;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>

<!-- Fondo Ambiental Mesh Limpio -->
<div class="ambient-mesh"></div>

<!-- Lienzo 3D con Three.js (Rotación interactiva de partículas y geometrías espaciales) -->
<canvas id="webgl-canvas-3d"></canvas>

<!-- ==========================================
     PORTAL HOLOGRÁFICO DE BIENVENIDA (3D)
     ========================================== -->
<div id="loginSuccessPortal">
    <div class="holo-center-stage">
        <!-- Anillos con Logo Grupo Huerta -->
        <div class="holo-ring-wrapper">
            <div class="holo-pulse-wave"></div>
            <div class="holo-ring-outer"></div>
            <div class="holo-ring-inner"></div>

            <div class="holo-logo-capsule">
                <img src="img/logo_gh.jpg" alt="Logo Grupo Huerta" id="holoSuccessLogo">
            </div>
        </div>

        <!-- Insignia de Acceso Autorizado -->
        <div class="badge-acceso-ok">
            <i class="bi bi-shield-fill-check fs-6"></i>
            <span>ACCESO AUTORIZADO</span>
        </div>

        <!-- Saludo Personalizado -->
        <h2 class="holo-welcome-name">
            ¡Bienvenido, <span id="holoUserNameSpan">Usuario</span>!
        </h2>
        <div class="holo-agency-name">
            Iniciando sesión en <strong>PORTAL GRUPO HUERTA</strong>
        </div>

        <!-- Barra de Progreso -->
        <div class="holo-progress-container">
            <div class="holo-progress-bar" id="holoProgressBar"></div>
        </div>
        <div class="holo-status-text" id="holoStatusText">Desencriptando entorno seguro... 0%</div>
    </div>
</div>

<!-- ==========================================
     FORMULARIO DE LOGIN PRINCIPAL
     ========================================== -->
<div class="login-wrapper" id="mainLoginWrapper">

    <!-- Hero Informativo Lateral Izquierdo -->
    <div class="hero-section">
        <div class="hero-logo-box mb-3">
            <img src="img/logo_gh.jpg" alt="Logo Grupo Huerta" class="img-fluid rounded-3" style="max-height: 110px; max-width: 290px; object-fit: contain;">
        </div>

        <h1 class="hero-title mb-0">
            PORTAL<br>
            <span class="gold-gradient-text">GRUPO HUERTA</span>
        </h1>
    </div>

    <!-- Tarjeta de Login con Efecto 3D Tilt -->
    <div class="login-card-container" id="cardTiltContainer">
        <div class="login-card" id="loginCard">
            <!-- Capa de brillo especular -->
            <div class="card-sheen" id="cardSheen"></div>

            <div class="text-center mb-4">
                <div class="card-header-logo">
                    <img src="img/logo_gh.jpg" alt="Logo Grupo Huerta">
                </div>
                <h3 class="fw-bold mb-1 card-title-text">Iniciar Sesión</h3>
                <p class="card-subtitle-text small mb-0">PORTAL GRUPO HUERTA</p>
            </div>

            <!-- Contenedor dinámico para alertas de error -->
            <div id="loginAlertBox">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger border-0 rounded-3 small mb-4 text-start" role="alert" style="background: rgba(239, 68, 68, 0.12); border-left: 4px solid #ef4444 !important; color: #b91c1c;">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
            </div>

            <form id="formLogin" method="POST" action="login.php">
                <div class="mb-3.5">
                    <label for="usuario" class="form-label">
                        <i class="bi bi-person-fill"></i> Usuario o Correo Institucional
                    </label>
                    <div class="input-group-custom">
                        <i class="bi bi-person input-icon-left"></i>
                        <input type="text" name="usuario" id="usuario" class="form-control-custom" placeholder="ej. usuario, sistemas..." required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="bi bi-key-fill"></i> Contraseña
                    </label>
                    <div class="input-group-custom">
                        <i class="bi bi-lock input-icon-left"></i>
                        <input type="password" name="password" id="password" class="form-control-custom" placeholder="••••••••" required autocomplete="current-password">
                        <button type="button" class="btn-toggle-pwd" id="btnTogglePassword" title="Mostrar/Ocultar contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit-3d" id="btnSubmitLogin">
                    <i class="bi bi-box-arrow-in-right fs-5"></i>
                    <span id="btnSubmitText">Ingresar al Sistema</span>
                </button>
            </form>

            <div class="text-center mt-4">
                <span class="text-secondary small" style="font-size: 0.78rem;">
                    <i class="bi bi-shield-check text-warning me-1"></i> Dirección de Sistemas Grupo Huerta &bull; 2026
                </span>
            </div>
        </div>
    </div>

</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- ==============================================================
     MOTOR GRÁFICO 3D (THREE.JS) + EFECTO TILT + ANIMACIÓN DE ACCESO
     ============================================================== -->
<script>
    /* ==========================================
       1. ESCENARIO 3D INTERACTIVO CON THREE.JS
       ========================================== */
    let scene, camera, renderer;
    let particles, particleGeo, particleMat;
    let wireMeshMain, wireMeshInner, satelliteGroup;
    let targetCameraX = 0, targetCameraY = 0;
    let currentCameraX = 0, currentCameraY = 0;
    let isWarpSpeed = false;
    let particleSpeeds = [];

    function initThreeScene() {
        const canvas = document.getElementById('webgl-canvas-3d');
        if (!canvas || typeof THREE === 'undefined') return;

        // Escena
        scene = new THREE.Scene();

        // Cámara
        camera = new THREE.PerspectiveCamera(65, window.innerWidth / window.innerHeight, 1, 1000);
        camera.position.z = 85;

        // Renderer con fondo transparente
        renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true, powerPreference: 'high-performance' });
        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        // Partículas en constelación 3D para fondo claro
        const particleCount = 700;
        particleGeo = new THREE.BufferGeometry();
        const positions = new Float32Array(particleCount * 3);
        const colors = new Float32Array(particleCount * 3);
        particleSpeeds = [];

        // Paleta sofisticada para fondo blanco: grafito, oro y pizarra
        const colorCharcoal = new THREE.Color(0x0f172a);
        const colorGold = new THREE.Color(0xd4af37);
        const colorSlate = new THREE.Color(0x64748b);

        for (let i = 0; i < particleCount; i++) {
            positions[i * 3]     = (Math.random() - 0.5) * 220;
            positions[i * 3 + 1] = (Math.random() - 0.5) * 160;
            positions[i * 3 + 2] = (Math.random() - 0.5) * 160;

            const rand = Math.random();
            const c = (rand < 0.45) ? colorCharcoal : ((rand < 0.8) ? colorGold : colorSlate);
            colors[i * 3]     = c.r;
            colors[i * 3 + 1] = c.g;
            colors[i * 3 + 2] = c.b;

            particleSpeeds.push({
                z: 0.05 + Math.random() * 0.08,
                rot: (Math.random() - 0.5) * 0.002
            });
        }

        particleGeo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        particleGeo.setAttribute('color', new THREE.BufferAttribute(colors, 3));

        // Crear textura circular suave para cada partícula
        const spriteCanvas = document.createElement('canvas');
        spriteCanvas.width = 32;
        spriteCanvas.height = 32;
        const ctx = spriteCanvas.getContext('2d');
        const grad = ctx.createRadialGradient(16, 16, 0, 16, 16, 16);
        grad.addColorStop(0, 'rgba(15,23,42,0.9)');
        grad.addColorStop(0.35, 'rgba(212,175,55,0.75)');
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(16, 16, 16, 0, Math.PI * 2);
        ctx.fill();

        const spriteTexture = new THREE.CanvasTexture(spriteCanvas);

        particleMat = new THREE.PointsMaterial({
            size: 2.2,
            vertexColors: true,
            map: spriteTexture,
            transparent: true,
            opacity: 0.75,
            blending: THREE.NormalBlending,
            depthWrite: false
        });

        particles = new THREE.Points(particleGeo, particleMat);
        scene.add(particles);

        // Geometría 3D Principal (Icosaedro wireframe)
        const icosaGeo = new THREE.IcosahedronGeometry(18, 1);
        const icosaMat = new THREE.MeshBasicMaterial({
            color: 0x0f172a,
            wireframe: true,
            transparent: true,
            opacity: 0.18
        });
        wireMeshMain = new THREE.Mesh(icosaGeo, icosaMat);
        wireMeshMain.position.set(-35, 8, -25);
        scene.add(wireMeshMain);

        // Geometría concéntrica interior dorada
        const innerGeo = new THREE.OctahedronGeometry(10, 0);
        const innerMat = new THREE.MeshBasicMaterial({
            color: 0xd4af37,
            wireframe: true,
            transparent: true,
            opacity: 0.35
        });
        wireMeshInner = new THREE.Mesh(innerGeo, innerMat);
        wireMeshInner.position.set(-35, 8, -25);
        scene.add(wireMeshInner);

        // Satélites orbitantes flotantes
        satelliteGroup = new THREE.Group();
        satelliteGroup.position.set(-35, 8, -25);
        for (let j = 0; j < 6; j++) {
            const satGeo = new THREE.TetrahedronGeometry(1.6, 0);
            const satMat = new THREE.MeshBasicMaterial({
                color: (j % 2 === 0) ? 0xd4af37 : 0x475569,
                wireframe: true,
                transparent: true,
                opacity: 0.55
            });
            const sat = new THREE.Mesh(satGeo, satMat);
            const angle = (j / 6) * Math.PI * 2;
            const dist = 28 + (j % 2) * 6;
            sat.position.set(Math.cos(angle) * dist, (Math.sin(angle) * dist) * 0.5, Math.sin(angle) * (dist * 0.7));
            satelliteGroup.add(sat);
        }
        scene.add(satelliteGroup);

        // Eventos
        window.addEventListener('resize', onWindowResize);
        document.addEventListener('mousemove', onMouseMove);
        if (window.DeviceOrientationEvent) {
            window.addEventListener('deviceorientation', onDeviceOrientation, { passive: true });
        }

        // Bucle de animación
        animateThree();
    }

    function onWindowResize() {
        if (!camera || !renderer) return;
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(window.innerWidth, window.innerHeight);
    }

    function onMouseMove(e) {
        const halfX = window.innerWidth / 2;
        const halfY = window.innerHeight / 2;
        targetCameraX = (e.clientX - halfX) * 0.035;
        targetCameraY = -(e.clientY - halfY) * 0.035;
    }

    function onDeviceOrientation(e) {
        if (!e.gamma || !e.beta) return;
        targetCameraX = (e.gamma) * 0.6;
        targetCameraY = (e.beta - 45) * 0.6;
    }

    function animateThree() {
        requestAnimationFrame(animateThree);

        // Suavizado (lerp) de la cámara por movimiento de mouse
        currentCameraX += (targetCameraX - currentCameraX) * 0.05;
        currentCameraY += (targetCameraY - currentCameraY) * 0.05;
        camera.position.x = currentCameraX;
        camera.position.y = currentCameraY;
        camera.lookAt(0, 0, 0);

        // Rotación de geometrías espaciales
        const speedMult = isWarpSpeed ? 8 : 1;

        if (wireMeshMain) {
            wireMeshMain.rotation.x += 0.003 * speedMult;
            wireMeshMain.rotation.y += 0.005 * speedMult;
        }
        if (wireMeshInner) {
            wireMeshInner.rotation.x -= 0.006 * speedMult;
            wireMeshInner.rotation.y += 0.004 * speedMult;
        }
        if (satelliteGroup) {
            satelliteGroup.rotation.y += 0.004 * speedMult;
            satelliteGroup.rotation.z += 0.002 * speedMult;
        }

        // Animación de partículas hacia adelante (profundidad Z)
        if (particles && particleGeo) {
            const pos = particleGeo.attributes.position.array;
            const count = pos.length / 3;

            for (let i = 0; i < count; i++) {
                const spd = isWarpSpeed ? 5.2 : particleSpeeds[i].z;
                pos[i * 3 + 2] += spd;

                if (pos[i * 3 + 2] > 70) {
                    pos[i * 3 + 2] = -90;
                    pos[i * 3] = (Math.random() - 0.5) * 220;
                    pos[i * 3 + 1] = (Math.random() - 0.5) * 160;
                }
            }
            particleGeo.attributes.position.needsUpdate = true;
        }

        renderer.render(scene, camera);
    }

    function activateWarpSpeed() {
        isWarpSpeed = true;
    }

    /* ==========================================
       2. EFECTO 3D TILT EN LA TARJETA DE LOGIN
       ========================================== */
    function initCardTilt() {
        const container = document.getElementById('cardTiltContainer');
        const card = document.getElementById('loginCard');
        const sheen = document.getElementById('cardSheen');
        if (!container || !card) return;

        container.addEventListener('mousemove', function(e) {
            const rect = container.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            const centerX = rect.width / 2;
            const centerY = rect.height / 2;

            const rotateX = -((y - centerY) / centerY) * 10;
            const rotateY = ((x - centerX) / centerX) * 10;

            card.style.transform = `perspective(1200px) rotateX(${rotateX.toFixed(2)}deg) rotateY(${rotateY.toFixed(2)}deg) scale3d(1.015, 1.015, 1.015)`;

            if (sheen) {
                const percentX = (x / rect.width) * 100;
                const percentY = (y / rect.height) * 100;
                sheen.style.background = `radial-gradient(circle 320px at ${percentX}% ${percentY}%, rgba(212, 175, 55, 0.16) 0%, transparent 70%)`;
            }
        });

        container.addEventListener('mouseleave', function() {
            card.style.transform = 'perspective(1200px) rotateX(0deg) rotateY(0deg) scale3d(1, 1, 1)';
        });
    }

    /* ==========================================
       3. EFECTO DE SONIDO SINTETIZADO (WEB AUDIO API)
       ========================================== */
    function playSciFiSuccessSound() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            const ctx = new AudioContext();

            const playTone = (freq, startTime, duration, gainVal) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, startTime);
                osc.frequency.exponentialRampToValueAtTime(freq * 1.05, startTime + duration);

                gain.gain.setValueAtTime(0, startTime);
                gain.gain.linearRampToValueAtTime(gainVal, startTime + 0.04);
                gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.start(startTime);
                osc.stop(startTime + duration);
            };

            const now = ctx.currentTime;
            playTone(523.25, now, 0.45, 0.25);        // C5
            playTone(659.25, now + 0.1, 0.5, 0.28);    // E5
            playTone(783.99, now + 0.2, 0.6, 0.32);    // G5
            playTone(1046.50, now + 0.3, 0.85, 0.35);  // C6
        } catch (e) {
            // Audio silencioso en caso de no soportarse
        }
    }

    /* ==========================================
       4. ANIMACIÓN AL INGRESAR CON CREDENCIALES
       ========================================== */
    function triggerLoginSuccessAnimation(userData) {
        // A. Acelerar el fondo 3D a velocidad hipersónica
        activateWarpSpeed();

        // B. Reproducir sonido de confirmación ejecutiva
        playSciFiSuccessSound();

        // C. Ocultar y desvanecer la tarjeta de login con transformación 3D
        const loginCard = document.getElementById('loginCard');
        if (loginCard) {
            loginCard.style.transition = 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
            loginCard.style.transform = 'perspective(1200px) scale(0.7) translateZ(-150px)';
            loginCard.style.opacity = '0';
            loginCard.style.filter = 'blur(12px)';
        }

        // D. Preparar datos del portal holográfico
        const portal = document.getElementById('loginSuccessPortal');
        const userSpan = document.getElementById('holoUserNameSpan');
        const progressBar = document.getElementById('holoProgressBar');
        const statusText = document.getElementById('holoStatusText');

        if (userSpan) {
            userSpan.textContent = userData.nombre || userData.usuario || 'Usuario';
        }

        // E. Mostrar portal holográfico
        if (portal) {
            portal.style.display = 'flex';
            setTimeout(() => {
                portal.style.opacity = '1';
                portal.classList.add('active');
            }, 30);
        }

        // F. Animación de barra de progreso en vivo
        let progress = 0;
        const duration = 1600; // 1.6 segundos
        const intervalTime = 30;
        const step = 100 / (duration / intervalTime);

        const progressTimer = setInterval(() => {
            progress += step;
            if (progress >= 100) {
                progress = 100;
                clearInterval(progressTimer);
                if (progressBar) progressBar.style.width = '100%';
                if (statusText) statusText.textContent = '¡Acceso concedido! Entrando al sistema...';

                // Redirigir al menú principal
                setTimeout(() => {
                    window.location.href = userData.redirect || 'menu.php';
                }, 300);
            } else {
                if (progressBar) progressBar.style.width = Math.round(progress) + '%';
                if (statusText) {
                    if (progress < 35) {
                        statusText.textContent = `Validando credenciales... ${Math.round(progress)}%`;
                    } else if (progress < 75) {
                        statusText.textContent = `Cargando perfil institucional... ${Math.round(progress)}%`;
                    } else {
                        statusText.textContent = `Sincronizando permisos de usuario... ${Math.round(progress)}%`;
                    }
                }
            }
        }, intervalTime);
    }

    /* ==========================================
       5. MANEJO DEL FORMULARIO VÍA AJAX INTERACTIVO
       ========================================== */
    document.addEventListener('DOMContentLoaded', function() {
        initThreeScene();
        initCardTilt();

        // Toggle mostrar/ocultar contraseña
        const btnToggle = document.getElementById('btnTogglePassword');
        const inputPassword = document.getElementById('password');
        if (btnToggle && inputPassword) {
            btnToggle.addEventListener('click', function() {
                const isPwd = inputPassword.getAttribute('type') === 'password';
                inputPassword.setAttribute('type', isPwd ? 'text' : 'password');
                btnToggle.innerHTML = isPwd ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            });
        }

        // Intercepción del Formulario de Login
        const formLogin = document.getElementById('formLogin');
        const btnSubmit = document.getElementById('btnSubmitLogin');
        const btnText = document.getElementById('btnSubmitText');
        const alertBox = document.getElementById('loginAlertBox');
        const card = document.getElementById('loginCard');

        if (formLogin) {
            formLogin.addEventListener('submit', function(e) {
                e.preventDefault();

                const formData = new FormData(formLogin);
                formData.append('ajax', '1');

                // Estado de carga en botón
                if (btnSubmit) {
                    btnSubmit.disabled = true;
                    btnText.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Verificando...';
                }
                if (alertBox) {
                    alertBox.innerHTML = '';
                }

                fetch('login.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.exito) {
                        // ¡INGRESO EXITOSO! Disparar la animación ejecutiva con el logo y el 3D
                        triggerLoginSuccessAnimation(data);
                    } else {
                        // Error de credenciales: Sacudir tarjeta y mostrar mensaje
                        if (btnSubmit) {
                            btnSubmit.disabled = false;
                            btnText.textContent = 'Ingresar al Sistema';
                        }
                        if (alertBox) {
                            alertBox.innerHTML = `
                                <div class="alert alert-danger border-0 rounded-3 small mb-4 text-start shadow-sm" role="alert" style="background: rgba(239, 68, 68, 0.12); border-left: 4px solid #ef4444 !important; color: #b91c1c;">
                                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> ${data.mensaje || 'Credenciales incorrectas.'}
                                </div>
                            `;
                        }
                        if (card) {
                            card.classList.remove('card-shaking');
                            void card.offsetWidth; // Forzar reflow
                            card.classList.add('card-shaking');
                        }
                    }
                })
                .catch(err => {
                    // Si ocurre cualquier falla de red, enviar vía POST estándar
                    console.warn('Error en AJAX, enviando vía fallback estándar:', err);
                    formLogin.submit();
                });
            });
        }
    });
</script>

</body>
</html>
