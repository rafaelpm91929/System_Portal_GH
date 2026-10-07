<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (file_exists('conexion.php')) {
    include_once 'conexion.php';
} else {
    $pdo = null;
}

if (file_exists('permisos_helper.php')) {
    require_once 'permisos_helper.php';
}

// Cargar información de la agencia registrada en la BD
$agenciaInfo = null;
if ($pdo) {
    try {
        $stmtAg = $pdo->query("SELECT nombre, razon_social, logo_url, color_tema FROM agencias ORDER BY id ASC LIMIT 1");
        $agenciaInfo = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {}
}

$nombreAgencia = !empty($agenciaInfo['nombre']) ? trim($agenciaInfo['nombre']) : 'AGENCIA';
$colorTemaClave = $agenciaInfo['color_tema'] ?? ($_SESSION['color_tema'] ?? 'azul');
$catalogoTemas = function_exists('obtenerCatalogoTemasOscuros') ? obtenerCatalogoTemasOscuros() : [];
$temaActual = $catalogoTemas[$colorTemaClave] ?? ($catalogoTemas['azul'] ?? [
    'clave' => 'azul',
    'nombre' => 'Azul Obscuro (Medianoche)',
    'bg_dark' => '#040d1a',
    'bg_deep' => '#02070e',
    'bg_card' => '#08162a',
    'primary' => '#0284c7',
    'accent' => '#38bdf8',
    'secondary' => '#2563eb',
    'glow' => 'rgba(2, 132, 199, 0.45)',
    'glow_soft' => 'rgba(56, 189, 248, 0.22)',
    'border' => 'rgba(56, 189, 248, 0.25)',
    'three_p1' => '0x38bdf8',
    'three_p2' => '0x2563eb',
    'three_p3' => '0x818cf8',
    'hex_swatch' => '#0284c7'
]);

// Detección robusta del logo registrado de la agencia
$logoAgencia = '';
if (!empty($agenciaInfo['logo_url'])) {
    $cleanLogo = ltrim($agenciaInfo['logo_url'], '/\\');
    if (file_exists(__DIR__ . '/' . $cleanLogo)) {
        $logoAgencia = $cleanLogo;
    } elseif (file_exists(__DIR__ . '/uploads/agencias/' . basename($cleanLogo))) {
        $logoAgencia = 'uploads/agencias/' . basename($cleanLogo);
    }
}
// Fallback: Si no está en BD pero existe en uploads/agencias/, usar el más reciente
if (empty($logoAgencia)) {
    $logosExistentes = glob(__DIR__ . '/uploads/agencias/logo_*.*');
    if (!empty($logosExistentes)) {
        usort($logosExistentes, function($a, $b) { return filemtime($b) - filemtime($a); });
        $logoAgencia = 'uploads/agencias/' . basename($logosExistentes[0]);
    }
}

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
                            $_SESSION['usuario_rol'] = $user['rol'] ?? 'Usuario';
                            $stmtAgReg = $pdo ? $pdo->query("SELECT nombre FROM agencias ORDER BY id ASC LIMIT 1") : null;
                            $nomAgReg = $stmtAgReg ? $stmtAgReg->fetchColumn() : null;
                            $_SESSION['agencia'] = !empty($nomAgReg) ? $nomAgReg : ($user['agencia'] ?? $nombreAgencia);

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
                                    'rol' => $user['rol'] ?? 'Usuario',
                                    'agencia' => $_SESSION['agencia'] ?? $nombreAgencia,
                                    'logo' => $logoAgencia,
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
            // Fallback de emergencia solo si no hay conexión a la BD MySQL
            if (($user_input === 'sistemas' || $user_input === 'admin') && $password_input === 'Admin123!') {
                $_SESSION['usuario_id'] = 1;
                $_SESSION['usuario_nombre'] = 'Administrador de Sistemas';
                $_SESSION['usuario_rol'] = 'SuperAdmin';
                $_SESSION['agencia'] = 'Cupra la villa';

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'exito' => true,
                        'usuario' => 'admin',
                        'nombre' => 'Administrador de Sistemas',
                        'rol' => 'SuperAdmin',
                        'agencia' => 'Cupra la villa',
                        'logo' => $logoAgencia,
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
    <title>Iniciar Sesión - PORTAL <?php echo htmlspecialchars($nombreAgencia); ?></title>
    <?php include_once 'pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Three.js para renderizado de fondo interactivo 3D -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <style>
        :root {
            --primary-glow: <?php echo $temaActual['glow']; ?>;
            --cyan-glow: <?php echo $temaActual['accent']; ?>;
            --blue-accent: <?php echo $temaActual['secondary']; ?>;
            --bg-dark: <?php echo $temaActual['bg_dark']; ?>;
            --bg-deep: <?php echo $temaActual['bg_deep']; ?>;
            --card-bg: <?php echo $temaActual['bg_card']; ?>;
            --card-border: <?php echo $temaActual['border']; ?>;
            --primary-color: <?php echo $temaActual['primary']; ?>;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-dark);
            color: #ffffff;
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

        /* LIENZO 3D INTERACTIVO (FONDO THREE.JS) */
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

        /* Gradientes sutiles de fondo */
        .ambient-mesh {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 0;
            background: 
                radial-gradient(circle at 15% 20%, <?php echo $temaActual['glow']; ?> 0%, transparent 45%),
                radial-gradient(circle at 85% 75%, <?php echo $temaActual['glow_soft']; ?> 0%, transparent 45%),
                radial-gradient(circle at 50% 50%, <?php echo $temaActual['bg_card']; ?> 0%, <?php echo $temaActual['bg_dark']; ?> 100%);
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

        .hero-badge-live {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-radius: 30px;
            color: #38bdf8;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 20px;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.2);
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #38bdf8;
            box-shadow: 0 0 10px #38bdf8;
            animation: pulseGlow 1.8s infinite;
        }

        @keyframes pulseGlow {
            0% { transform: scale(0.9); opacity: 0.7; box-shadow: 0 0 4px #38bdf8; }
            50% { transform: scale(1.3); opacity: 1; box-shadow: 0 0 14px #38bdf8; }
            100% { transform: scale(0.9); opacity: 0.7; box-shadow: 0 0 4px #38bdf8; }
        }

        .hero-logo-box {
            margin-bottom: 24px;
            display: inline-block;
            filter: drop-shadow(0 10px 25px rgba(0, 0, 0, 0.6)) drop-shadow(0 0 15px rgba(56, 189, 248, 0.3));
            animation: floatSlow 5s ease-in-out infinite;
        }

        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }

        .hero-title {
            font-size: 2.8rem;
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 18px;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 50%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-desc {
            color: #94a3b8;
            font-size: 1.05rem;
            line-height: 1.6;
            margin-bottom: 28px;
            max-width: 440px;
        }

        .hero-stats-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .hero-stat-card {
            background: rgba(15, 28, 49, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(8px);
        }

        .hero-stat-card i {
            font-size: 1.4rem;
            color: #38bdf8;
        }

        /* TARJETA DE LOGIN CON EFECTO 3D TILT */
        .login-card-container {
            perspective: 1200px;
            position: relative;
        }

        .login-card {
            background: <?php echo $temaActual['bg_card']; ?>;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid <?php echo $temaActual['border']; ?>;
            border-radius: 24px;
            padding: 44px 36px;
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.75),
                0 0 35px <?php echo $temaActual['glow_soft']; ?>,
                inset 0 1px 0 rgba(255, 255, 255, 0.15);
            position: relative;
            overflow: hidden;
            transform-style: preserve-3d;
            transition: transform 0.15s ease-out, box-shadow 0.25s ease;
        }

        /* Brillo dinámico de cristal que sigue el cursor */
        .card-sheen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            background: radial-gradient(circle 280px at 50% 50%, rgba(255, 255, 255, 0.07), transparent 70%);
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
            padding: 12px 20px;
            background: <?php echo $temaActual['bg_deep']; ?>;
            border: 1px solid <?php echo $temaActual['border']; ?>;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.35);
            transition: all 0.3s ease;
        }

        .card-header-logo img {
            max-height: 62px;
            max-width: 190px;
            object-fit: contain;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.5));
            transition: transform 0.3s ease;
        }

        .login-card-container:hover .card-header-logo img {
            transform: scale(1.03);
        }

        .form-label {
            color: #cbd5e1;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 7px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-left {
            position: absolute;
            left: 14px;
            color: #64748b;
            font-size: 1.05rem;
            z-index: 4;
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-control-custom {
            background-color: rgba(9, 24, 45, 0.85) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            border-radius: 12px !important;
            color: #ffffff !important;
            padding: 13px 16px 13px 44px !important;
            font-size: 0.96rem !important;
            width: 100% !important;
            transition: all 0.2s ease !important;
        }

        .form-control-custom:focus {
            background-color: rgba(12, 32, 60, 0.95) !important;
            border-color: #38bdf8 !important;
            color: #ffffff !important;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.18), 0 0 20px rgba(56, 189, 248, 0.15) !important;
        }

        .form-control-custom:focus + .input-icon-left,
        .input-group-custom:focus-within .input-icon-left {
            color: #38bdf8;
        }

        .form-control-custom::placeholder {
            color: #475569;
        }

        .btn-toggle-pwd {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            color: #64748b;
            padding: 4px;
            z-index: 5;
            cursor: pointer;
            transition: color 0.2s;
        }
        .btn-toggle-pwd:hover {
            color: #38bdf8;
        }

        .btn-submit-3d {
            background: linear-gradient(135deg, <?php echo $temaActual['primary']; ?> 0%, <?php echo $temaActual['secondary']; ?> 100%);
            border: 1px solid <?php echo $temaActual['border']; ?>;
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
            box-shadow: 0 6px 20px <?php echo $temaActual['glow']; ?>;
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
            background: linear-gradient(135deg, <?php echo $temaActual['secondary']; ?> 0%, <?php echo $temaActual['primary']; ?> 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px <?php echo $temaActual['glow']; ?>, 0 0 25px <?php echo $temaActual['glow_soft']; ?>;
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
            background: radial-gradient(circle at center, <?php echo $temaActual['bg_deep']; ?> 0%, <?php echo $temaActual['bg_dark']; ?> 100%);
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
            border: 2px dashed <?php echo $temaActual['accent']; ?>;
            box-shadow: 0 0 35px <?php echo $temaActual['glow']; ?>, inset 0 0 25px <?php echo $temaActual['glow_soft']; ?>;
            animation: spinHolo 8s linear infinite;
        }

        .holo-ring-inner {
            position: absolute;
            inset: 16px;
            border-radius: 50%;
            border: 2px solid <?php echo $temaActual['secondary']; ?>;
            border-top-color: <?php echo $temaActual['accent']; ?>;
            border-bottom-color: <?php echo $temaActual['accent']; ?>;
            box-shadow: 0 0 25px <?php echo $temaActual['glow_soft']; ?>;
            animation: spinHoloRev 4.5s linear infinite;
        }

        .holo-pulse-wave {
            position: absolute;
            inset: -20px;
            border-radius: 50%;
            border: 1px solid <?php echo $temaActual['accent']; ?>;
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
            background: radial-gradient(circle at 35% 35%, <?php echo $temaActual['bg_card']; ?> 0%, <?php echo $temaActual['bg_dark']; ?> 100%);
            border: 2px solid <?php echo $temaActual['accent']; ?>;
            box-shadow: 
                0 0 40px <?php echo $temaActual['glow']; ?>,
                inset 0 0 20px <?php echo $temaActual['glow_soft']; ?>;
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
            filter: drop-shadow(0 4px 15px rgba(0,0,0,0.7)) drop-shadow(0 0 10px <?php echo $temaActual['glow_soft']; ?>);
        }

        .holo-logo-capsule .fallback-icon {
            font-size: 3.2rem;
            color: <?php echo $temaActual['accent']; ?>;
            filter: drop-shadow(0 0 15px <?php echo $temaActual['accent']; ?>);
        }

        /* Insignia y Títulos */
        .badge-acceso-ok {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 20px;
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.45);
            color: #4ade80;
            font-size: 0.85rem;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            border-radius: 30px;
            margin-bottom: 16px;
            box-shadow: 0 0 25px rgba(34, 197, 94, 0.3);
            animation: pulseGlowGreen 2s infinite;
        }

        @keyframes pulseGlowGreen {
            0%, 100% { box-shadow: 0 0 15px rgba(34, 197, 94, 0.25); }
            50% { box-shadow: 0 0 30px rgba(34, 197, 94, 0.5); }
        }

        .holo-welcome-name {
            font-size: 2.3rem;
            font-weight: 900;
            margin-bottom: 8px;
            color: #ffffff;
            letter-spacing: -0.5px;
            text-shadow: 0 0 25px <?php echo $temaActual['glow_soft']; ?>;
        }

        .holo-welcome-name span {
            background: linear-gradient(135deg, <?php echo $temaActual['accent']; ?> 0%, #ffffff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .holo-agency-name {
            color: #94a3b8;
            font-size: 1.05rem;
            margin-bottom: 24px;
        }

        .holo-agency-name strong {
            color: <?php echo $temaActual['accent']; ?>;
        }

        /* Barra de progreso de carga cibernética */
        .holo-progress-container {
            width: 100%;
            max-width: 380px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid <?php echo $temaActual['border']; ?>;
            border-radius: 12px;
            height: 10px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
            margin-bottom: 12px;
        }

        .holo-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, <?php echo $temaActual['primary']; ?> 0%, <?php echo $temaActual['accent']; ?> 50%, <?php echo $temaActual['secondary']; ?> 100%);
            box-shadow: 0 0 18px <?php echo $temaActual['accent']; ?>;
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

<!-- Fondo Ambiental Mesh -->
<div class="ambient-mesh"></div>

<!-- Lienzo 3D con Three.js (Rotación interactiva de partículas y geometrías espaciales) -->
<canvas id="webgl-canvas-3d"></canvas>

<!-- ==========================================
     PORTAL HOLOGRÁFICO DE BIENVENIDA (3D)
     ========================================== -->
<div id="loginSuccessPortal">
    <div class="holo-center-stage">
        <!-- Anillos Holográficos con el Logo de la Agencia -->
        <div class="holo-ring-wrapper">
            <div class="holo-pulse-wave"></div>
            <div class="holo-ring-outer"></div>
            <div class="holo-ring-inner"></div>

            <div class="holo-logo-capsule">
                <?php if (!empty($logoAgencia)): ?>
                    <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="Logo <?php echo htmlspecialchars($nombreAgencia); ?>" id="holoSuccessLogo">
                <?php else: ?>
                    <i class="bi bi-shield-check fallback-icon"></i>
                <?php endif; ?>
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
            Iniciando sesión en <strong>PORTAL <?php echo htmlspecialchars($nombreAgencia); ?></strong>
        </div>

        <!-- Barra de Progreso Cibernética -->
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
        <?php if (!empty($logoAgencia)): ?>
            <div class="hero-logo-box mb-3">
                <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="<?php echo htmlspecialchars($nombreAgencia); ?>" class="img-fluid rounded-3" style="max-height: 100px; max-width: 290px; object-fit: contain;">
            </div>
        <?php endif; ?>

        <h1 class="hero-title mb-0">
            PORTAL<br>
            <span style="color: <?php echo $temaActual['accent']; ?>;"><?php echo htmlspecialchars($nombreAgencia); ?></span>
        </h1>
    </div>

    <!-- Tarjeta de Login con Efecto 3D Tilt -->
    <div class="login-card-container" id="cardTiltContainer">
        <div class="login-card" id="loginCard">
            <!-- Capa de brillo especular de cristal -->
            <div class="card-sheen" id="cardSheen"></div>

            <div class="text-center mb-4">
                <?php if (!empty($logoAgencia)): ?>
                    <div class="card-header-logo">
                        <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="Logo <?php echo htmlspecialchars($nombreAgencia); ?>">
                    </div>
                <?php else: ?>
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-15 text-info rounded-circle p-3 mb-2" style="width: 65px; height: 65px; border: 1px solid rgba(56, 189, 248, 0.3);">
                        <i class="bi bi-building fs-2"></i>
                    </div>
                <?php endif; ?>
                <h3 class="fw-bold mb-1 text-white">Iniciar Sesión</h3>
                <p class="text-secondary small mb-0">PORTAL <?php echo htmlspecialchars($nombreAgencia); ?></p>
            </div>

            <!-- Contenedor dinámico para alertas de error -->
            <div id="loginAlertBox">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger border-0 rounded-3 small mb-4 text-start" role="alert" style="background: rgba(239, 68, 68, 0.15); border-left: 4px solid #ef4444 !important; color: #fca5a5;">
                        <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
            </div>

            <form id="formLogin" method="POST" action="login.php">
                <div class="mb-3.5">
                    <label for="usuario" class="form-label">
                        <i class="bi bi-person-fill text-info"></i> Usuario o Correo Institucional
                    </label>
                    <div class="input-group-custom">
                        <i class="bi bi-person input-icon-left"></i>
                        <input type="text" name="usuario" id="usuario" class="form-control-custom" placeholder="ej. usuario, sistemas..." required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="bi bi-key-fill text-info"></i> Contraseña
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
                    <i class="bi bi-shield-check text-info me-1"></i> Dirección de Sistemas Grupo Huerta &bull; 2026
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

        // Renderer
        renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true, powerPreference: 'high-performance' });
        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        // Partículas en constelación 3D
        const particleCount = 750;
        particleGeo = new THREE.BufferGeometry();
        const positions = new Float32Array(particleCount * 3);
        const colors = new Float32Array(particleCount * 3);
        particleSpeeds = [];

        const colorCyan = new THREE.Color(<?php echo $temaActual['three_p1']; ?>);
        const colorBlue = new THREE.Color(<?php echo $temaActual['three_p2']; ?>);
        const colorIndigo = new THREE.Color(<?php echo $temaActual['three_p3']; ?>);

        for (let i = 0; i < particleCount; i++) {
            positions[i * 3]     = (Math.random() - 0.5) * 220;
            positions[i * 3 + 1] = (Math.random() - 0.5) * 160;
            positions[i * 3 + 2] = (Math.random() - 0.5) * 160;

            const c = (Math.random() < 0.6) ? colorCyan : ((Math.random() < 0.5) ? colorBlue : colorIndigo);
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
        grad.addColorStop(0, 'rgba(255,255,255,1)');
        grad.addColorStop(0.3, '<?php echo $temaActual['accent']; ?>');
        grad.addColorStop(1, 'rgba(0,0,0,0)');
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
            opacity: 0.85,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        });

        particles = new THREE.Points(particleGeo, particleMat);
        scene.add(particles);

        // Geometría 3D Principal (Icosaedro wireframe con resplandor)
        const icosaGeo = new THREE.IcosahedronGeometry(18, 1);
        const icosaMat = new THREE.MeshBasicMaterial({
            color: <?php echo $temaActual['three_p1']; ?>,
            wireframe: true,
            transparent: true,
            opacity: 0.28
        });
        wireMeshMain = new THREE.Mesh(icosaGeo, icosaMat);
        wireMeshMain.position.set(-35, 8, -25);
        scene.add(wireMeshMain);

        // Geometría concéntrica interior
        const innerGeo = new THREE.OctahedronGeometry(10, 0);
        const innerMat = new THREE.MeshBasicMaterial({
            color: <?php echo $temaActual['three_p2']; ?>,
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
                color: (j % 2 === 0) ? <?php echo $temaActual['three_p1']; ?> : <?php echo $temaActual['three_p3']; ?>,
                wireframe: true,
                transparent: true,
                opacity: 0.65
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
                sheen.style.background = `radial-gradient(circle 320px at ${percentX}% ${percentY}%, rgba(56, 189, 248, 0.16) 0%, transparent 70%)`;
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

        // B. Reproducir sonido de confirmación cibernética
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
                        statusText.textContent = `Cargando perfil de ${userData.agencia || 'Agencia'}... ${Math.round(progress)}%`;
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
                        // ¡INGRESO EXITOSO! Disparar la animación padre con el logo y el 3D
                        triggerLoginSuccessAnimation(data);
                    } else {
                        // Error de credenciales: Sacudir tarjeta y mostrar mensaje
                        if (btnSubmit) {
                            btnSubmit.disabled = false;
                            btnText.textContent = 'Ingresar al Sistema';
                        }
                        if (alertBox) {
                            alertBox.innerHTML = `
                                <div class="alert alert-danger border-0 rounded-3 small mb-4 text-start shadow-sm" role="alert" style="background: rgba(239, 68, 68, 0.18); border-left: 4px solid #ef4444 !important; color: #fca5a5;">
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

<?php include_once 'pwa_body.php'; ?>
</body>
</html>
