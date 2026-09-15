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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Agencias - Portal Maestro Grupo Huerta</title>
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
            padding: 15px 40px;
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
            font-size: 2.6rem;
            font-weight: 800;
            margin-bottom: 6px;
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
        .agency-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 24px;
        }
        .agency-card {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .agency-card:hover {
            transform: translateY(-6px);
            border-color: #2563eb;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
            background: #0f233f;
        }
        .agency-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 20px;
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
            font-size: 1.4rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 4px;
        }
        .agency-subdomain {
            color: #60a5fa;
            font-size: 0.88rem;
            font-weight: 600;
            margin-bottom: 12px;
            font-family: monospace;
        }
        .agency-desc {
            color: #94a3b8;
            font-size: 0.88rem;
            line-height: 1.55;
            margin-bottom: 25px;
            flex-grow: 1;
        }
        .btn-agency {
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
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
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
        <span class="fw-bold tracking-wide">GRUPO HUERTA <span class="text-secondary fw-normal">| Portal Maestro Central</span></span>
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
    <div class="master-badge"><i class="bi bi-building-fill-gear"></i> DIRECCIÓN CENTRAL MAESTRA</div>
    <h1 class="header-title">Catálogo de Agencias Grupo Huerta</h1>
    <p class="header-desc">Selecciona una agencia para acceder a la consulta en vivo de su base de datos cPanel local</p>
</div>

<div class="section-container">
    
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

    <!-- CATÁLOGO MAESTRO DE AGENCIAS Y SUCURSALES -->
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
                    Ingresar <i class="bi bi-arrow-right ms-1"></i>
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

</body>
</html>

