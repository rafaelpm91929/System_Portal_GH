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

    <!-- Módulo 1: Órdenes de Servicio (NUEVO & FUNCIONAL) -->
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

    <!-- Módulo 2: Inventario de Equipos -->
    <div class="module-card">
        <div class="icon-box icon-cyan">
            <i class="bi bi-display"></i>
        </div>
        <div class="module-title">Inventario de Equipos</div>
        <div class="module-desc">
            Administra PCs, laptops, servidores e impresoras de cada sucursal, con su nomenclatura y responsiva.
        </div>
        <a href="reportes.php" class="btn-ingresar">Ingresar</a>
    </div>

    <!-- Módulo 3: Inventario de Celulares -->
    <div class="module-card">
        <div class="icon-box icon-purple">
            <i class="bi bi-phone"></i>
        </div>
        <div class="module-title">Inventario de Celulares</div>
        <div class="module-desc">
            Controla los equipos móviles asignados por sucursal: modelo, línea, usuario responsable y estado.
        </div>
        <a href="#" class="btn-ingresar text-secondary" style="pointer-events: none;">Próximamente</a>
    </div>

    <!-- Módulo 4: Licencias -->
    <div class="module-card">
        <div class="icon-box icon-yellow">
            <i class="bi bi-key"></i>
        </div>
        <div class="module-title">Licencias</div>
        <div class="module-desc">
            Matriz de licenciamiento de software por equipo y sucursal, con vigencias y alertas de vencimiento.
        </div>
        <a href="#" class="btn-ingresar text-secondary" style="pointer-events: none;">Próximamente</a>
    </div>

    <!-- Módulo 5: Infraestructura (SITE / IDF) -->
    <div class="module-card">
        <div class="icon-box icon-green">
            <i class="bi bi-hdd-rack"></i>
        </div>
        <div class="module-title">Infraestructura (SITE / IDF)</div>
        <div class="module-desc">
            Fichas de SITE e IDF, racks, cableado y red por agencia, con sus diagramas y evidencia asociada.
        </div>
        <a href="#" class="btn-ingresar text-secondary" style="pointer-events: none;">Próximamente</a>
    </div>

    <!-- Módulo 6: Respaldos -->
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

    <!-- Módulo 7: Mantenimiento -->
    <div class="module-card">
        <div class="icon-box icon-orange">
            <i class="bi bi-wrench"></i>
        </div>
        <div class="module-title">Mantenimiento</div>
        <div class="module-desc">
            Calendario anual de mantenimiento a infraestructura, con evidencia y responsable por evento.
        </div>
        <a href="#" class="btn-ingresar text-secondary" style="pointer-events: none;">Próximamente</a>
    </div>

    <!-- Módulo 8: Correo Institucional -->
    <div class="module-card">
        <div class="icon-box icon-pink">
            <i class="bi bi-envelope"></i>
        </div>
        <div class="module-title">Correo Institucional</div>
        <div class="module-desc">
            Cuentas oficiales por sucursal, con seguimiento de altas, bajas y reactivaciones.
        </div>
        <a href="#" class="btn-ingresar text-secondary" style="pointer-events: none;">Próximamente</a>
    </div>

    <!-- Módulo 9: Estadísticas de Cumplimiento -->
    <div class="module-card">
        <div class="icon-box icon-blue">
            <i class="bi bi-graph-up-arrow"></i>
        </div>
        <div class="module-title">Estadísticas de Cumplimiento</div>
        <div class="module-desc">
            Semáforo consolidado por agencia y sucursal, alertas activas y tendencia de cumplimiento.
        </div>
        <a href="reportes.php" class="btn-ingresar">Ingresar</a>
    </div>

</div>

</body>
</html>
