<?php
session_start();

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'conexion.php';
require_once 'permisos_helper.php';

// Refrescar permisos actualizados en sesión
if ($pdo && isset($_SESSION['usuario_id'])) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
}

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'SuperAdmin Grupo Huerta';
$agenciaUsuario = $_SESSION['agencia'] ?? 'Oficina Central Grupo Huerta';
$rolUsuario = $_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'SuperAdmin';

// Validación de permisos por módulo
$puedeVerUsuarios = tienePermiso('usuarios', 'puede_ver');
$puedeVerAgencias = tienePermiso('agencias', 'puede_ver');
$puedeVerTickets  = tienePermiso('tickets', 'puede_ver');
$puedeVerPoliticas = tienePermiso('politicas', 'puede_ver');
$puedeVerCompliance = tienePermiso('compliance', 'puede_ver');

$totalModulosVisibles = ($puedeVerUsuarios ? 1 : 0) + ($puedeVerAgencias ? 1 : 0) + ($puedeVerTickets ? 1 : 0) + ($puedeVerPoliticas ? 1 : 0) + ($puedeVerCompliance ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #040d1a;
            background-image: radial-gradient(#0b223e 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 60px;
        }
        .top-navbar {
            background: rgba(4, 13, 26, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 40px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .header-section {
            padding: 45px 40px 15px 40px;
            max-width: 1400px;
            margin: 0 auto;
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
            margin-bottom: 12px;
        }
        .header-title {
            font-size: 2.6rem;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }
        .header-desc {
            color: #94a3b8;
            font-size: 1.05rem;
        }
        .section-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 40px;
        }

        /* Tarjetas de los 3 Módulos Principales */
        .main-modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 28px;
            margin-top: 20px;
        }
        .main-module-card {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 22px;
            padding: 35px 30px;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        .main-module-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 22px 45px rgba(0, 0, 0, 0.65);
        }
        .card-usuarios:hover {
            border-color: #3b82f6;
            background: #0d2242;
        }
        .card-agencias:hover {
            border-color: #10b981;
            background: #09282b;
        }
        .card-tickets:hover {
            border-color: #0ea5e9;
            background: #0b263e;
        }
        .card-politicas:hover {
            border-color: #a855f7;
            background: #190e2b;
        }

        .module-icon-box {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin-bottom: 22px;
        }
        .module-badge {
            position: absolute;
            top: 24px;
            right: 24px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.8px;
        }
        .module-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 10px;
        }
        .module-desc {
            color: #94a3b8;
            font-size: 0.92rem;
            line-height: 1.6;
            margin-bottom: 30px;
            flex-grow: 1;
        }
        .btn-module {
            color: #ffffff;
            font-weight: 700;
            font-size: 1rem;
            padding: 14px 22px;
            border-radius: 14px;
            border: none;
            width: 100%;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-module:hover {
            color: #ffffff;
            filter: brightness(1.15);
        }

        .empty-modules-card {
            background: #0a192e;
            border: 1px dashed rgba(255, 255, 255, 0.2);
            border-radius: 22px;
            padding: 50px 30px;
            text-align: center;
            max-width: 650px;
            margin: 40px auto;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
        <span class="fw-bold tracking-wide">Portal Grupo Huerta</span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <?php include_once __DIR__ . '/componente_notificaciones.php'; ?>
        <div class="text-end d-none d-md-block">
            <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.75rem;"><?php echo htmlspecialchars($agenciaUsuario); ?> &bull; <span class="badge bg-primary text-uppercase"><?php echo htmlspecialchars($rolUsuario); ?></span></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Cerrar sesión
        </a>
    </div>
</div>

<!-- Header Principal -->
<div class="header-section">
    <div class="master-badge"><i class="bi bi-shield-check"></i> DIRECCIÓN CENTRAL MAESTRA</div>
    <h1 class="header-title">Portal Grupo Huerta</h1>
    <p class="header-desc">Módulos autorizados para tu perfil en los servicios de Grupo Huerta.</p>
</div>

<!-- Contenedor con los Módulos Permitidos -->
<div class="section-container">

    <?php if ($totalModulosVisibles > 0): ?>
        <div class="main-modules-grid">

            <!-- 1. GESTIÓN DE USUARIOS -->
            <?php if ($puedeVerUsuarios): ?>
                <div class="main-module-card card-usuarios">
                    <span class="module-badge" style="background: rgba(37, 99, 235, 0.15); color: #60a5fa; border: 1px solid rgba(37, 99, 235, 0.3);">
                        <i class="bi bi-shield-lock-fill"></i> SEGURIDAD TI
                    </span>

                    <div class="module-icon-box" style="background: rgba(37, 99, 235, 0.15); color: #3b82f6;">
                        <i class="bi bi-people-fill"></i>
                    </div>

                    <div class="module-title">Gestión de Usuarios</div>
                    <div class="module-desc">
                        Administración de cuentas, asignación de roles (SuperAdmin, Admin, Usuario), restablecimiento de contraseñas y control granular de permisos a módulos.
                    </div>

                    <a href="usuarios.php" class="btn-module" style="background: #2563eb; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.35);">
                        Ingresar a Usuarios <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            <?php endif; ?>

            <!-- 2. AGENCIAS -->
            <?php if ($puedeVerAgencias): ?>
                <div class="main-module-card card-agencias">
                    <span class="module-badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                        <i class="bi bi-buildings-fill"></i> SUCURSALES
                    </span>

                    <div class="module-icon-box" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                        <i class="bi bi-buildings-fill"></i>
                    </div>

                    <div class="module-title">Agencias</div>
                    <div class="module-desc">
                        Catálogo de sucursales Grupo Huerta. Acceso y monitoreo de las bases de datos cPanel locales (VW Divol La Villa, Seat La Villa, Cupra Garage).
                    </div>

                    <a href="agencias.php" class="btn-module" style="background: #059669; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);">
                        Ingresar a Agencias <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            <?php endif; ?>

            <!-- 3. TICKETS SOPORTE DIRECCIÓN SISTEMAS -->
            <?php if ($puedeVerTickets): ?>
                <div class="main-module-card card-tickets">
                    <span class="module-badge" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8; border: 1px solid rgba(14, 165, 233, 0.3);">
                        <i class="bi bi-headset"></i> SOPORTE TI
                    </span>

                    <div class="module-icon-box" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8;">
                        <i class="bi bi-ticket-detailed-fill"></i>
                    </div>

                    <div class="module-title">Tickets</div>
                    <div class="module-desc">
                        Recepción, seguimiento y resolución de tickets de soporte para: Desarrollo, Cyberseguridad, Infraestructura, Redes Sociales, Auditoría y Corporativo.
                    </div>

                    <a href="tickets.php" class="btn-module" style="background: #0284c7; box-shadow: 0 4px 15px rgba(14, 165, 233, 0.35);">
                        Ingresar a Tickets <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            <?php endif; ?>

            <!-- 4. POLÍTICAS CORPORATIVAS -->
            <?php if ($puedeVerPoliticas): ?>
                <div class="main-module-card card-politicas" style="border: 1px solid rgba(212, 175, 55, 0.4); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35), 0 0 15px rgba(212, 175, 55, 0.15);">
                    <span class="module-badge" style="background: rgba(212, 175, 55, 0.15); color: #f5df9e; border: 1px solid rgba(212, 175, 55, 0.4);">
                        <i class="bi bi-shield-check"></i> ALTA DIRECCIÓN
                    </span>

                    <div class="module-icon-box" style="background: rgba(212, 175, 55, 0.15); color: #d4af37; border: 1px solid rgba(212, 175, 55, 0.3);">
                        <i class="bi bi-file-earmark-lock2-fill"></i>
                    </div>

                    <div class="module-title">Políticas Corporativas</div>
                    <div class="module-desc">
                        Directrices oficiales, códigos de conducta y normativas de Grupo Huerta organizadas por áreas y subáreas, con visor blindado anti-captura.
                    </div>

                    <a href="politicas.php" class="btn-module" style="background: linear-gradient(135deg, #b8972e 0%, #8c711e 100%); border: 1px solid rgba(245, 223, 158, 0.4); box-shadow: 0 4px 15px rgba(212, 175, 55, 0.35);">
                        Ingresar a Políticas <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            <?php endif; ?>

            <!-- 5. COMPLIANCE -->
            <?php if ($puedeVerCompliance): ?>
                <div class="main-module-card card-compliance" style="border: 1px solid rgba(212, 175, 55, 0.4); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35), 0 0 15px rgba(212, 175, 55, 0.15);">
                    <span class="module-badge" style="background: rgba(212, 175, 55, 0.15); color: #f5df9e; border: 1px solid rgba(212, 175, 55, 0.4);">
                        <i class="bi bi-shield-check"></i> GOBIERNO & CONTROL
                    </span>

                    <div class="module-icon-box" style="background: rgba(212, 175, 55, 0.15); color: #d4af37; border: 1px solid rgba(212, 175, 55, 0.3);">
                        <i class="bi bi-journal-check"></i>
                    </div>

                    <div class="module-title">Compliance</div>
                    <div class="module-desc">
                        Formatos oficiales, manuales de procedimientos y avisos institucionales de cumplimiento organizados con menú lateral interactivo.
                    </div>

                    <a href="compliance.php" class="btn-module" style="background: linear-gradient(135deg, #b8972e 0%, #8c711e 100%); border: 1px solid rgba(245, 223, 158, 0.4); box-shadow: 0 4px 15px rgba(212, 175, 55, 0.35);">
                        Ingresar a Compliance <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            <?php endif; ?>

        </div>
    <?php else: ?>
        <!-- Mensaje cuando el usuario no tiene módulos asignados -->
        <div class="empty-modules-card">
            <i class="bi bi-shield-slash-fill text-warning display-4 mb-3 d-block"></i>
            <h4 class="fw-bold text-white mb-2">Sin Módulos Asignados</h4>
            <p class="text-secondary small mb-4">
                Tu usuario está activo pero actualmente no tiene permisos de acceso a los módulos del portal. 
                Por favor solicita a tu <strong>Administrador de Sistemas</strong> que configure tus permisos en Gestión de Usuarios.
            </p>
            <a href="logout.php" class="btn btn-outline-danger rounded-3 px-4">
                <i class="bi bi-box-arrow-right me-1"></i> Cerrar Sesión
            </a>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
