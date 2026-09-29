<?php
session_start();

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$agenciaUsuario = $_SESSION['agencia'] ?? 'Grupo Huerta';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal de Sistemas - Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #061325;
            background-image: radial-gradient(#0e2440 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 50px;
        }
        .top-navbar {
            background: rgba(6, 19, 37, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 15px 40px;
        }
        .header-section {
            padding: 40px 40px 20px 40px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .brand-subtitle {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .header-title {
            font-size: 2.4rem;
            font-weight: 800;
            margin-top: 4px;
            margin-bottom: 6px;
        }
        .header-desc {
            color: #94a3b8;
            font-size: 1rem;
        }
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 24px;
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 40px;
        }
        .module-card {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 30px 24px 24px 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }
        .module-card:hover {
            transform: translateY(-5px);
            border-color: rgba(37, 99, 235, 0.4);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5);
            background: #102442;
        }
        .icon-box {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: 20px;
        }
        .icon-blue { background: rgba(37, 99, 235, 0.15); color: #3b82f6; }
        .icon-purple { background: rgba(147, 51, 234, 0.15); color: #a855f7; }
        .icon-yellow { background: rgba(234, 179, 8, 0.15); color: #eab308; }
        .icon-green { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .icon-orange { background: rgba(249, 115, 22, 0.15); color: #f97316; }
        .icon-pink { background: rgba(236, 72, 153, 0.15); color: #ec4899; }
        .icon-cyan { background: rgba(6, 182, 212, 0.15); color: #06b6d4; }
        
        .module-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 10px;
        }
        .module-desc {
            color: #94a3b8;
            font-size: 0.85rem;
            line-height: 1.5;
            margin-bottom: 25px;
            flex-grow: 1;
        }
        .btn-ingresar {
            background: #172a46;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-weight: 600;
            font-size: 0.88rem;
            padding: 8px;
            border-radius: 8px;
            width: 100%;
            transition: all 0.2s;
            text-decoration: none;
            display: block;
        }
        .module-card:hover .btn-ingresar {
            background: #2563eb;
            border-color: #2563eb;
        }
        .active-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-shield-check text-primary fs-4"></i>
        <span class="fw-bold tracking-wide">GRUPO HUERTA <span class="text-secondary fw-normal">| Portal de Sistemas</span></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="text-end d-none d-md-block">
            <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.75rem;"><?php echo htmlspecialchars($agenciaUsuario); ?></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Cerrar sesión
        </a>
    </div>
</div>

<!-- Header -->
<div class="header-section">
    <div class="brand-subtitle">GRUPO HUERTA</div>
    <h1 class="header-title">Portal de Sistemas</h1>
    <p class="header-desc">Selecciona un módulo para continuar</p>
</div>

<!-- Grid de Módulos -->
<div class="modules-grid">

    <!-- Módulo Agencia: Datos de la Agencia -->
    <div class="module-card" style="border-color: rgba(34, 197, 94, 0.4);">
        <span class="active-badge" style="background: rgba(34, 197, 94, 0.15); color: #22c55e; border-color: rgba(34, 197, 94, 0.3);"><i class="bi bi-building-fill me-1"></i> FICHA OFICIAL</span>
        <div class="icon-box icon-green">
            <i class="bi bi-building"></i>
        </div>
        <div class="module-title">Datos de la Agencia</div>
        <div class="module-desc">
            Ficha oficial de la sucursal: Nombre, Razón Social, RFC, Dirección, Foto y Encargado de Sistemas.
        </div>
        <a href="agencia.php" class="btn-ingresar" style="background: #16a34a; border-color: #16a34a;">Ingresar</a>
    </div>

    <!-- Módulo 0: Gestión de Usuarios y Permisos (RBAC) -->
    <div class="module-card" style="border-color: rgba(37, 99, 235, 0.4);">
        <span class="active-badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; border-color: rgba(59, 130, 246, 0.3);"><i class="bi bi-shield-check me-1"></i> CONTROL RBAC</span>
        <div class="icon-box icon-blue">
            <i class="bi bi-people-fill"></i>
        </div>
        <div class="module-title">Gestión de Usuarios</div>
        <div class="module-desc">
            Administra los usuarios de la agencia y configura permisos granulares por módulo (Ver, Crear, Editar, Exportar).
        </div>
        <a href="usuarios.php" class="btn-ingresar" style="background: #2563eb; border-color: #2563eb;">Ingresar</a>
    </div>

    <!-- Módulo 2: Inventario de Equipos (Posición 3) -->
    <div class="module-card">
        <span class="active-badge" style="background: rgba(6, 182, 212, 0.15); color: #06b6d4; border-color: rgba(6, 182, 212, 0.3);"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> ACTIVO</span>
        <div class="icon-box icon-cyan">
            <i class="bi bi-display"></i>
        </div>
        <div class="module-title">Inventario de Equipos</div>
        <div class="module-desc">
            Administra PCs, laptops, servidores e impresoras de cada sucursal, con su nomenclatura y responsiva.
        </div>
        <a href="equipos.php" class="btn-ingresar">Ingresar</a>
    </div>

    <!-- Módulo 5: Infraestructura (SITE / IDF) (Posición 4) -->
    <div class="module-card" style="border-color: rgba(34, 197, 94, 0.4);">
        <span class="active-badge" style="background: rgba(34, 197, 94, 0.15); color: #22c55e; border-color: rgba(34, 197, 94, 0.3);"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> ACTIVO</span>
        <div class="icon-box icon-green">
            <i class="bi bi-hdd-rack"></i>
        </div>
        <div class="module-title">Infraestructura (SITE / IDF)</div>
        <div class="module-desc">
            Fichas de SITE e IDF, racks, cableado y red por agencia, con sus diagramas y evidencia asociada.
        </div>
        <a href="infraestructura.php" class="btn-ingresar" style="background: #16a34a; border-color: #16a34a;">Ingresar</a>
    </div>

    <!-- Módulo DB: Visor de Base de Datos (Posición 5) -->
    <?php 
    $rolSesion = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
    if (in_array($rolSesion, ['superadmin', 'admin'])): 
    ?>
    <div class="module-card" style="border-color: rgba(16, 185, 129, 0.4);">
        <span class="active-badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(16, 185, 129, 0.3);"><i class="bi bi-database-fill me-1"></i> BASE DE DATOS</span>
        <div class="icon-box icon-green">
            <i class="bi bi-database-gear"></i>
        </div>
        <div class="module-title">Visor de Base de Datos</div>
        <div class="module-desc">
            Consulta tablas, columnas, registros y estructura de la base de datos en tiempo real (SQLite / MySQL).
        </div>
        <a href="db_explorer.php" class="btn-ingresar" style="background: #059669; border-color: #059669;">Explorar BD</a>
    </div>
    <?php endif; ?>

    <!-- Módulo 1: Órdenes de Servicio -->
    <div class="module-card">
        <span class="active-badge"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> EN VIVO</span>
        <div class="icon-box icon-blue">
            <i class="bi bi-file-earmark-bar-graph"></i>
        </div>
        <div class="module-title">Órdenes de Servicio</div>
        <div class="module-desc">
            Consulta en tiempo real el resumen de órdenes abiertas, cerradas y montos acumulados por agencia.
        </div>
        <a href="reportes.php" class="btn-ingresar">Ingresar</a>
    </div>

    <!-- Módulo 6: Respaldos (Próximamente) -->
    <div class="module-card">
        <div class="icon-box icon-blue">
            <i class="bi bi-cloud-check"></i>
        </div>
        <div class="module-title">Respaldos</div>
        <div class="module-desc">
            Estado de los respaldos por tipo y sucursal: última ejecución, frecuencia y bitácora de restauración.
        </div>
        <a href="#" class="btn-ingresar text-secondary" style="pointer-events: none;">Próximamente</a>
    </div>

    <!-- Módulo: Directorio Telefónico y Correos -->
    <div class="module-card" style="border-color: rgba(236, 72, 153, 0.4);">
        <span class="active-badge" style="background: rgba(236, 72, 153, 0.15); color: #f472b6; border-color: rgba(236, 72, 153, 0.3);"><i class="bi bi-telephone-inbound-fill me-1"></i> DIRECTORIO</span>
        <div class="icon-box icon-pink">
            <i class="bi bi-person-lines-fill"></i>
        </div>
        <div class="module-title">Directorio</div>
        <div class="module-desc">
            Directorio oficial de la sucursal: colaboradores por área, correos institucionales, extensiones y gestión de contraseñas de correo.
        </div>
        <a href="directorio.php" class="btn-ingresar" style="background: #db2777; border-color: #db2777;">Ingresar</a>
    </div>

</div>

</body>
</html>
