<?php
session_start();

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

include_once 'config_agencias.php';

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'SuperAdmin Grupo Huerta';
$agenciaUsuario = $_SESSION['agencia'] ?? 'Oficina Central Grupo Huerta';
$rolUsuario = $_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'SuperAdmin';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal de Sistemas - Dirección Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        html {
            scroll-behavior: smooth;
        }
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
        .nav-link-custom {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 8px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .nav-link-custom:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
        }
        .nav-link-custom.active {
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.12);
        }
        .header-section {
            padding: 35px 40px 10px 40px;
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
            font-size: 2.5rem;
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
            margin: 25px auto;
            padding: 0 40px;
        }
        .section-subtitle-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .section-subtitle-title {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #e2e8f0;
        }

        /* Tarjetas de Módulos Principales */
        .main-modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 24px;
            margin-bottom: 45px;
        }
        .main-module-card {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        .main-module-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
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

        .module-icon-box {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 20px;
        }
        .module-badge {
            position: absolute;
            top: 22px;
            right: 22px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
            letter-spacing: 0.8px;
        }
        .module-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 8px;
        }
        .module-desc {
            color: #94a3b8;
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 26px;
            flex-grow: 1;
        }
        .btn-module {
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 13px 20px;
            border-radius: 12px;
            border: none;
            width: 100%;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-module:hover {
            color: #ffffff;
            filter: brightness(1.15);
        }

        /* Grid Agencias */
        .agency-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 24px;
        }
        .agency-card {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 28px;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .agency-card:hover {
            transform: translateY(-5px);
            border-color: #2563eb;
            box-shadow: 0 16px 35px rgba(0, 0, 0, 0.5);
            background: #0f233f;
        }
        .agency-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            margin-bottom: 18px;
        }
        .online-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .agency-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 4px;
        }
        .agency-subdomain {
            color: #60a5fa;
            font-size: 0.86rem;
            font-weight: 600;
            margin-bottom: 12px;
            font-family: monospace;
        }
        .agency-desc {
            color: #94a3b8;
            font-size: 0.88rem;
            line-height: 1.55;
            margin-bottom: 24px;
            flex-grow: 1;
        }
        .btn-agency {
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.92rem;
            padding: 12px 18px;
            border-radius: 12px;
            border: none;
            width: 100%;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-agency:hover {
            background: #1d4ed8;
            color: #ffffff;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
        }
        .add-agency-card {
            background: rgba(255, 255, 255, 0.02);
            border: 2px dashed rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
            min-height: 280px;
        }
        .add-agency-card:hover {
            border-color: #60a5fa;
            color: #94a3b8;
        }
        .zero-storage-note {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.25);
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
            <span class="fw-bold tracking-wide">GRUPO HUERTA <span class="text-secondary fw-normal">| Portal Maestro Central</span></span>
        </div>
        <!-- Enlaces rápidos de navegación -->
        <div class="d-none d-lg-flex align-items-center gap-1 ms-3">
            <a href="usuarios.php" class="nav-link-custom">
                <i class="bi bi-people-fill text-primary"></i> Usuarios
            </a>
            <a href="#catalogo-agencias" class="nav-link-custom">
                <i class="bi bi-buildings-fill text-success"></i> Agencias
            </a>
            <a href="tickets.php" class="nav-link-custom">
                <i class="bi bi-ticket-detailed-fill text-info"></i> Tickets Soporte
            </a>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="text-end d-none d-md-block">
            <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.75rem;"><?php echo htmlspecialchars($agenciaUsuario); ?> &bull; <span class="badge bg-primary text-uppercase"><?php echo htmlspecialchars($rolUsuario); ?></span></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Cerrar sesión
        </a>
    </div>
</div>

<!-- Header -->
<div class="header-section">
    <div class="master-badge"><i class="bi bi-shield-check"></i> DIRECCIÓN CENTRAL MAESTRA</div>
    <h1 class="header-title">Portal Dirección de Sistemas Grupo Huerta</h1>
    <p class="header-desc">Control centralizado de usuarios, canalización de tickets de soporte TI y catálogo de agencias sucursales.</p>
</div>

<div class="section-container">
    
    <!-- ============================================== -->
    <!-- MÓDULOS PRINCIPALES (USUARIOS, AGENCIAS, TICKETS) -->
    <!-- ============================================== -->
    <div class="section-subtitle-bar">
        <div class="section-subtitle-title">
            <i class="bi bi-grid-1x2-fill text-primary"></i> Módulos Principales de Dirección
        </div>
        <span class="small text-secondary">Acceso rápido a las funciones directivas</span>
    </div>

    <div class="main-modules-grid">

        <!-- 1. GESTIÓN DE USUARIOS -->
        <div class="main-module-card card-usuarios">
            <span class="module-badge" style="background: rgba(37, 99, 235, 0.15); color: #60a5fa; border: 1px solid rgba(37, 99, 235, 0.3);">
                <i class="bi bi-shield-lock-fill"></i> SEGURIDAD TI
            </span>

            <div class="module-icon-box" style="background: rgba(37, 99, 235, 0.15); color: #3b82f6;">
                <i class="bi bi-people-fill"></i>
            </div>

            <div class="module-title">Gestión de Usuarios</div>
            <div class="module-desc">
                Administración de cuentas, roles (SuperAdmin, Admin, Usuario), contraseñas, asignación de agencias y control granular de permisos a módulos.
            </div>

            <a href="usuarios.php" class="btn-module" style="background: #2563eb; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.35);">
                Ingresar a Usuarios <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>

        <!-- 2. AGENCIAS -->
        <div class="main-module-card card-agencias">
            <span class="module-badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                <i class="bi bi-buildings-fill"></i> SUCURSALES
            </span>

            <div class="module-icon-box" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                <i class="bi bi-building-gear"></i>
            </div>

            <div class="module-title">Agencias Grupo Huerta</div>
            <div class="module-desc">
                Catálogo y monitoreo en vivo de las agencias (VW Divol La Villa, Seat La Villa, Cupra Garage La Villa) y sus bases de datos locales cPanel.
            </div>

            <a href="#catalogo-agencias" class="btn-module" style="background: #059669; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);">
                Ver Agencias <i class="bi bi-arrow-down-circle ms-1"></i>
            </a>
        </div>

        <!-- 3. TICKETS SOPORTE DIRECCIÓN SISTEMAS -->
        <div class="main-module-card card-tickets">
            <span class="module-badge" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8; border: 1px solid rgba(14, 165, 233, 0.3);">
                <i class="bi bi-headset"></i> SOPORTE TI
            </span>

            <div class="module-icon-box" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8;">
                <i class="bi bi-ticket-detailed-fill"></i>
            </div>

            <div class="module-title">Tickets Soporte Dirección Sistemas</div>
            <div class="module-desc">
                Recepción, asignación y seguimiento de requerimientos para: Desarrollo, Cyberseguridad, Infraestructura, Redes Sociales, Auditoría y Corporativo.
            </div>

            <a href="tickets.php" class="btn-module" style="background: #0284c7; box-shadow: 0 4px 15px rgba(14, 165, 233, 0.35);">
                Ingresar a Tickets <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>

    </div>

    <!-- ============================================== -->
    <!-- CATÁLOGO DE AGENCIAS Y SUCURSALES -->
    <!-- ============================================== -->
    <div id="catalogo-agencias" class="pt-2">
        <div class="section-subtitle-bar">
            <div class="section-subtitle-title">
                <i class="bi bi-building-fill-gear text-success"></i> Catálogo de Sucursales y Agencias
            </div>
            <span class="small text-secondary">Acceso a los portales cPanel locales de cada sucursal</span>
        </div>

        <!-- Nota de Arquitectura Zero-Storage -->
        <div class="zero-storage-note">
            <i class="bi bi-shield-check text-success fs-3"></i>
            <div>
                <div class="fw-bold text-success">Arquitectura Descentralizada (Zero-Storage cPanel Maestro)</div>
                <div class="small text-secondary">
                    Los datos de inventarios, reportes y órdenes residen 100% en el cPanel local de cada sucursal. El Portal Maestro realiza consultas cifradas en tiempo real bajo demanda.
                </div>
            </div>
        </div>

        <!-- Tarjetas de cada Agencia -->
        <div class="agency-card-grid">
            
            <?php foreach ($CATALOGO_AGENCIAS as $key => $ag): ?>
                <div class="agency-card">
                    <span class="online-badge">
                        <i class="bi bi-circle-fill" style="font-size: 0.45rem;"></i> cPanel Local Activo
                    </span>
                    
                    <div class="agency-icon" style="background: rgba(37, 99, 235, 0.15); color: <?php echo $ag['color']; ?>;">
                        <i class="bi <?php echo $ag['icono']; ?>"></i>
                    </div>

                    <div class="agency-title"><?php echo htmlspecialchars($ag['nombre']); ?></div>
                    <div class="agency-subdomain">
                        <i class="bi bi-globe me-1"></i> <?php echo htmlspecialchars($ag['subdominio']); ?>
                    </div>

                    <div class="agency-desc">
                        <?php echo htmlspecialchars($ag['descripcion']); ?>
                    </div>

                    <a href="modulos.php?agencia=<?php echo $key; ?>" class="btn-agency">
                        Ingresar a Sucursal <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            <?php endforeach; ?>

            <!-- Tarjeta para agregar nueva sucursal -->
            <div class="add-agency-card">
                <i class="bi bi-plus-circle-dotted display-5 mb-3 text-secondary"></i>
                <h5 class="fw-bold text-white mb-1">Registrar Nueva Sucursal</h5>
                <p class="small text-secondary mb-0">
                    Para vincular un nuevo cPanel, agrega sus datos en <code>config_agencias.php</code>
                </p>
            </div>

        </div>
    </div>

</div>

</body>
</html>
