<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (file_exists('conexion.php')) {
    include_once 'conexion.php';
} else {
    $pdo = null;
}

// Si el usuario ya está autenticado, redirigir al menú maestro
if (isset($_SESSION['usuario_id'])) {
    header("Location: menu.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['usuario'] ?? '');
    $password_input = trim($_POST['password'] ?? '');

    if (!empty($user_input) && !empty($password_input)) {
        $login_exitoso = false;

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE (usuario = :user OR email = :user) AND activo = 1 LIMIT 1");
                $stmt->execute(['user' => $user_input]);
                $user = $stmt->fetch();

                if ($user && password_verify($password_input, $user['password'])) {
                    $_SESSION['usuario_id'] = $user['id'];
                    $_SESSION['usuario_nombre'] = $user['nombre'];
                    $_SESSION['usuario_rol'] = $user['rol'];
                    $_SESSION['agencia'] = $user['agencia'];

                    $login_exitoso = true;
                    header("Location: menu.php");
                    exit();
                }
            } catch (Throwable $ex) {
                // Si la tabla usuarios no existe aún en cPanel MySQL, continuar al fallback maestro
                $login_exitoso = false;
            }
        }

        if (!$login_exitoso) {
            // Fallback Maestro (Para ingreso inmediato sin importar el estado de la BD en cPanel)
            if (($user_input === 'admin' || $user_input === 'grupohuerta') && $password_input === 'Admin123!') {
                $_SESSION['usuario_id'] = 1;
                $_SESSION['usuario_nombre'] = 'SuperAdmin Grupo Huerta';
                $_SESSION['usuario_rol'] = 'SuperAdmin';
                $_SESSION['agencia'] = 'Oficina Central Grupo Huerta';

                header("Location: menu.php");
                exit();
            } else {
                $error = "Usuario o contraseña incorrectos (Para ingresar prueba con: admin / Admin123!).";
            }
        }
    } else {
        $error = "Por favor completa todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Maestro de Sistemas - Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #040d1a;
            background-image: radial-gradient(#0c2340 1px, transparent 1px);
            background-size: 24px 24px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 1100px;
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 45px;
            align-items: center;
        }
        @media (max-width: 880px) {
            .login-wrapper {
                grid-template-columns: 1fr;
            }
        }
        .hero-section {
            padding: 20px;
        }
        .master-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(37, 99, 235, 0.15);
            border: 1px solid rgba(37, 99, 235, 0.4);
            color: #60a5fa;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 15px;
        }
        .hero-title {
            font-size: 2.8rem;
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-desc {
            color: #94a3b8;
            font-size: 1.05rem;
            line-height: 1.6;
            margin-bottom: 30px;
            max-width: 500px;
        }
        .agency-tags {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .agency-pill {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 500;
        }
        .login-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 40px 35px;
            color: #1e293b;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }
        .icon-badge {
            width: 52px;
            height: 52px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 20px;
        }
        .card-brand {
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .card-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .card-subtitle {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 25px;
        }
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }
        .btn-submit {
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            padding: 12px;
            border-radius: 10px;
            border: none;
            width: 100%;
            margin-top: 20px;
            font-size: 1rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover {
            background: #1d4ed8;
        }
        .footer-note {
            text-align: center;
            color: #94a3b8;
            font-size: 0.78rem;
            margin-top: 25px;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <!-- Left Hero Section -->
    <div class="hero-section">
        <div class="master-badge">
            <i class="bi bi-shield-lock-fill"></i> PORTAL MAESTRO CENTRAL
        </div>
        <h1 class="hero-title">Portal de Sistemas Grupo Huerta</h1>
        <p class="hero-desc">
            Consolidado maestro de inventarios, evidencias, órdenes de servicio y cumplimiento global para todas las agencias del grupo.
        </p>
        <div class="agency-tags">
            <span class="agency-pill"><i class="bi bi-building me-1"></i> VW Divol La Villa</span>
            <span class="agency-pill"><i class="bi bi-building me-1"></i> Seat La Villa</span>
            <span class="agency-pill"><i class="bi bi-building me-1"></i> Cupra Garage La Villa</span>
            <span class="agency-pill"><i class="bi bi-diagram-3 me-1"></i> Red Sucursales Grupo Huerta</span>
        </div>
    </div>

    <!-- Right Form Card -->
    <div class="login-card">
        <div class="icon-badge">
            <i class="bi bi-shield-lock"></i>
        </div>
        <div class="card-brand">GRUPO HUERTA</div>
        <h2 class="card-title">Iniciar sesión</h2>
        <div class="card-subtitle">Acceso al Portal Maestro Central</div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 px-3 small rounded-3 mb-3">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label for="usuario" class="form-label">Usuario Maestro</label>
                <div class="input-group">
                    <span class="input-group-text bg-white text-muted border-end-0 rounded-start-3"><i class="bi bi-person-badge"></i></span>
                    <input type="text" class="form-control border-start-0 rounded-end-3" id="usuario" name="usuario" placeholder="Ej. admin o grupohuerta" value="<?php echo htmlspecialchars($_POST['usuario'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text bg-white text-muted border-end-0 rounded-start-3"><i class="bi bi-key-fill"></i></span>
                    <input type="password" class="form-control border-start-0 rounded-end-3" id="password" name="password" placeholder="••••••••" required>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label small text-muted" for="remember">Recordarme</label>
                </div>
                <a href="#" class="small text-decoration-none fw-semibold" style="color: #2563eb;">¿Olvidaste tu contraseña?</a>
            </div>

            <button type="submit" class="btn-submit">
                Iniciar sesión en Portal Maestro <i class="bi bi-arrow-right"></i>
            </button>

            <div class="footer-note">
                Acceso restringido para la Dirección y Administración Central de Grupo Huerta
            </div>
        </form>
    </div>
</div>

</body>
</html>
