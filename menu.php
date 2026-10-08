<?php
session_start();

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';

if (file_exists('conexion.php')) {
    include_once 'conexion.php';
}

if (file_exists('permisos_helper.php')) {
    require_once 'permisos_helper.php';
}

// Recargar permisos actualizados desde la base de datos para la sesión activa
if (isset($pdo) && $pdo && isset($_SESSION['usuario_id'])) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
}

$agenciaInfo = null;
if (isset($pdo) && $pdo) {
    try {
        $stmtAg = $pdo->query("SELECT nombre, logo_url FROM agencias ORDER BY id ASC LIMIT 1");
        $agenciaInfo = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {}
}

$agenciaUsuario = !empty($agenciaInfo['nombre']) ? trim($agenciaInfo['nombre']) : ($_SESSION['agencia'] ?? 'Agencia');
$logoAgencia = (!empty($agenciaInfo['logo_url']) && file_exists(__DIR__ . '/' . $agenciaInfo['logo_url'])) ? $agenciaInfo['logo_url'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PORTAL <?php echo htmlspecialchars($agenciaUsuario); ?></title>
    <?php include_once 'pwa_head.php'; ?>
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
        <?php if (!empty($logoAgencia)): ?>
            <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="Logo" style="max-height: 32px; max-width: 120px; object-fit: contain;">
        <?php else: ?>
            <i class="bi bi-shield-check text-primary fs-4"></i>
        <?php endif; ?>
        <span class="fw-bold tracking-wide">PORTAL <span class="text-primary"><?php echo htmlspecialchars($agenciaUsuario); ?></span></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <?php include_once __DIR__ . '/componente_notificaciones.php'; ?>
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
    <div class="brand-subtitle">PORTAL <?php echo htmlspecialchars($agenciaUsuario); ?></div>
    <h1 class="header-title">Panel de Control</h1>
    <p class="header-desc">Selecciona un módulo para continuar</p>
</div>

<?php
$modulosVisibles = 0;
?>

<!-- Grid de Módulos -->
<div class="modules-grid">

    <!-- Módulo Agencia: Datos de la Agencia -->
    <?php if (tienePermiso('agencia', 'puede_ver')): $modulosVisibles++; ?>
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
    <?php endif; ?>

    <!-- Módulo 0: Gestión de Usuarios y Permisos (RBAC) -->
    <?php if (tienePermiso('usuarios', 'puede_ver')): $modulosVisibles++; ?>
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
    <?php endif; ?>

    <!-- Módulo 2: Inventario de Equipos (Posición 3) -->
    <?php if (tienePermiso('equipos', 'puede_ver')): $modulosVisibles++; ?>
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
    <?php endif; ?>

    <!-- Módulo 5: Infraestructura (SITE / IDF) (Posición 4) -->
    <?php if (tienePermiso('infraestructura', 'puede_ver')): $modulosVisibles++; ?>
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
    <?php endif; ?>

    <!-- Módulo: Políticas Corporativas (Protegido GH) -->
    <?php if (tienePermiso('politicas', 'puede_ver')): $modulosVisibles++; ?>
    <div class="module-card" style="border-color: rgba(147, 51, 234, 0.45);">
        <span class="active-badge" style="background: rgba(147, 51, 234, 0.15); color: #c084fc; border-color: rgba(147, 51, 234, 0.3);"><i class="bi bi-shield-lock-fill me-1"></i> CENTRAL GH</span>
        <div class="icon-box icon-purple">
            <i class="bi bi-file-earmark-lock2-fill"></i>
        </div>
        <div class="module-title">Políticas Corporativas</div>
        <div class="module-desc">
            Consulta oficial y centralizada de políticas y lineamientos con visor blindado anti-captura y sincronización central.
        </div>
        <a href="politicas.php" class="btn-ingresar" style="background: #9333ea; border-color: #9333ea;">Ingresar</a>
    </div>
    <?php endif; ?>

    <!-- Módulo: Compliance Institucional -->
    <?php if (tienePermiso('compliance', 'puede_ver')): $modulosVisibles++; ?>
    <div class="module-card" style="border-color: rgba(212, 175, 55, 0.45); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.35), 0 0 15px rgba(212, 175, 55, 0.12);">
        <span class="active-badge" style="background: rgba(212, 175, 55, 0.15); color: #f5df9e; border-color: rgba(212, 175, 55, 0.35);"><i class="bi bi-shield-check me-1"></i> GOBIERNO & CONTROL</span>
        <div class="icon-box" style="background: rgba(212, 175, 55, 0.15); color: #d4af37; border: 1px solid rgba(212, 175, 55, 0.3);">
            <i class="bi bi-journal-check"></i>
        </div>
        <div class="module-title">Compliance</div>
        <div class="module-desc">
            Formatos oficiales, manuales de procedimientos y avisos institucionales de cumplimiento con menú lateral interactivo.
        </div>
        <a href="compliance.php" class="btn-ingresar" style="background: linear-gradient(135deg, #b8972e 0%, #8c711e 100%); border-color: rgba(245, 223, 158, 0.4); box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);">Ingresar</a>
    </div>
    <?php endif; ?>

    <!-- Módulo: Tickets Soporte Dirección Sistemas -->
    <?php if (tienePermiso('tickets', 'puede_ver')): $modulosVisibles++; ?>
    <div class="module-card" style="border-color: rgba(14, 165, 233, 0.45);">
        <span class="active-badge" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8; border-color: rgba(14, 165, 233, 0.3);"><i class="bi bi-headset me-1"></i> SOPORTE TI</span>
        <div class="icon-box icon-cyan" style="background: rgba(14, 165, 233, 0.15); color: #38bdf8;">
            <i class="bi bi-ticket-detailed-fill"></i>
        </div>
        <div class="module-title">Tickets Soporte Dirección Sistemas</div>
        <div class="module-desc">
            Registro, canalización y seguimiento de requerimientos para Desarrollo, Ciberseguridad, Infraestructura, Redes Sociales, Auditoría y Corporativo.
        </div>
        <a href="tickets.php" class="btn-ingresar" style="background: #0284c7; border-color: #0284c7;">Ingresar</a>
    </div>
    <?php endif; ?>

    <!-- Módulo: Directorio Telefónico y Correos -->
    <?php if (tienePermiso('directorio', 'puede_ver')): $modulosVisibles++; ?>
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
    <?php endif; ?>

    <?php if ($modulosVisibles === 0): ?>
    <div class="col-12 py-5" style="grid-column: 1 / -1;">
        <div class="p-5 rounded-4 border border-secondary border-opacity-25 text-center shadow" style="background: rgba(13, 30, 54, 0.6); max-width: 650px; margin: 0 auto;">
            <i class="bi bi-shield-lock-fill display-1 text-warning opacity-75 d-block mb-3"></i>
            <h4 class="fw-bold text-white mb-2">No tienes módulos asignados</h4>
            <p class="text-secondary small mb-0">Tu usuario no cuenta actualmente con permisos seleccionados para visualizar módulos en el portal. Contacta al administrador del sistema para que configure tus accesos.</p>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include_once 'pwa_body.php'; ?>
</body>
</html>
