<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (file_exists('conexion.php')) {
    include_once 'conexion.php';
} else {
    $pdo = null;
}

// Si el usuario ya está autenticado, redirigir al menú
if (isset($_SESSION['usuario_id'])) {
    header("Location: menu.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['usuario'] ?? '');
    $password_input = trim($_POST['password'] ?? '');

    if (!empty($user_input) && !empty($password_input)) {
        if ($pdo) {
            try {
                // 1. Buscar usuario por login o email (usando marcadores posicionales para evitar error HY093 en PDO native prepares)
                $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE (LOWER(usuario) = LOWER(?) OR LOWER(email) = LOWER(?)) LIMIT 1");
                $stmt->execute([$user_input, $user_input]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Verificar si el usuario está activo
                    $estaActivo = isset($user['activo']) ? intval($user['activo']) : 1;
                    if ($estaActivo !== 1) {
                        $error = "Tu cuenta se encuentra inactiva. Por favor contacta al administrador.";
                    } else {
                        // 2. Verificar contraseña (soporta hash password_verify Y texto plano legacy)
                        $passwordValida = false;

                        if (password_verify($password_input, $user['password'])) {
                            $passwordValida = true;
                        } elseif ($user['password'] === $password_input) {
                            // Si la contraseña estaba en texto plano en la BD, aceptarla y actualizarla a Hash seguro
                            $passwordValida = true;
                            try {
                                $newHash = password_hash($password_input, PASSWORD_DEFAULT);
                                $updateStmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                                $updateStmt->execute([$newHash, $user['id']]);
                            } catch (Throwable $e) {
                                // Ignorar si no se pudo actualizar el hash
                            }
                        }

                        if ($passwordValida) {
                            $_SESSION['usuario_id'] = $user['id'];
                            $_SESSION['usuario_nombre'] = $user['nombre'];
                            $_SESSION['usuario_rol'] = $user['rol'] ?? 'Usuario';
                            $_SESSION['agencia'] = $user['agencia'] ?? 'VW Divol La Villa';

                            // Cargar permisos en la sesión
                            if (file_exists('permisos_helper.php')) {
                                require_once 'permisos_helper.php';
                                cargarPermisosSesion($pdo, $user['id']);
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
            if (($user_input === 'tilavilla' || $user_input === 'admin') && $password_input === 'Admin123!') {
                $_SESSION['usuario_id'] = 1;
                $_SESSION['usuario_nombre'] = 'Administrador Divol La Villa';
                $_SESSION['usuario_rol'] = 'SuperAdmin';
                $_SESSION['agencia'] = 'VW Divol La Villa';

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
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Portal de Sistemas VW Divol La Villa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #061325;
            background-image: radial-gradient(#102847 1px, transparent 1px);
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
            max-width: 1000px;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 40px;
            align-items: center;
        }
        @media (max-width: 850px) {
            .login-wrapper { grid-template-columns: 1fr; }
        }
        .hero-section { padding: 20px; }
        .brand-subtitle {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .hero-title {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-desc {
            color: #94a3b8;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .feature-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(37, 99, 235, 0.12);
            border: 1px solid rgba(37, 99, 235, 0.3);
            color: #60a5fa;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 20px;
        }
        .login-card {
            background: rgba(10, 25, 46, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .form-label {
            color: #cbd5e1;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .form-control-custom {
            background-color: #0f223d;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            color: #ffffff;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .form-control-custom:focus {
            background-color: #132a4b;
            border-color: #3b82f6;
            color: #ffffff;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }
        .form-control-custom::placeholder { color: #475569; }
        .btn-submit {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 14px;
            border-radius: 10px;
            width: 100%;
            margin-top: 10px;
            transition: all 0.2s;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
        }
    </style>
</head>
<body>

<div class="login-wrapper">

    <!-- Hero Informativo -->
    <div class="hero-section d-none d-md-block">
        <div class="brand-subtitle">GRUPO HUERTA</div>
        <h1 class="hero-title">Portal de Sistemas<br>VW Divol La Villa</h1>
        <p class="hero-desc">
            Acceso seguro al sistema de gestión de la sucursal Divol La Villa. Gestiona usuarios, monitorea órdenes de servicio y supervisa el inventario de la agencia.
        </p>
        <div class="feature-badge">
            <i class="bi bi-shield-check"></i> Acceso Cifrado & Control de Roles
        </div>
    </div>

    <!-- Tarjeta de Login -->
    <div class="login-card">
        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle p-3 mb-2" style="width: 60px; height: 60px;">
                <i class="bi bi-building fs-3"></i>
            </div>
            <h3 class="fw-bold mb-1">Iniciar Sesión</h3>
            <p class="text-secondary small">Ingresa tus credenciales para acceder</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger border-0 rounded-3 small mb-4 text-start" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label for="usuario" class="form-label">Usuario o Correo Electrónico</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-person"></i></span>
                    <input type="text" name="usuario" id="usuario" class="form-control form-control-custom" placeholder="ej. tilavilla" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-key"></i></span>
                    <input type="password" name="password" id="password" class="form-control form-control-custom" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="bi bi-box-arrow-in-right me-2"></i> Ingresar al Sistema
            </button>
        </form>
    </div>

</div>

</body>
</html>
