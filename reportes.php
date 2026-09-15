<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

include_once 'config_agencias.php';

$agencia_seleccionada = $_GET['agencia'] ?? 'divolavilla';
if (!isset($CATALOGO_AGENCIAS[$agencia_seleccionada])) {
    $agencia_seleccionada = 'divolavilla';
}

$info_agencia = $CATALOGO_AGENCIAS[$agencia_seleccionada];

// Cargar los datos recibidos desde la caché local específica de la agencia o general
$archivoCache = __DIR__ . "/reporte_cache_{$agencia_seleccionada}.json";
if (!file_exists($archivoCache)) {
    $archivoCache = __DIR__ . '/reporte_cache.json';
}

if (file_exists($archivoCache)) {
    $json = file_get_contents($archivoCache);
    $data = json_decode($json, true);
    $resumen = $data['resumen'] ?? ['Abiertas' => 0, 'Cerradas' => 0, 'Canceladas' => 0, 'TotalOrdenes' => 0, 'MontoAbiertas' => 0, 'MontoCerradas' => 0];
    $ultimasOrdenes = $data['ultimasOrdenes'] ?? [];
    $inventario = $data['inventario'] ?? [];
    $fechaActualizacion = $data['fecha_actualizacion'] ?? date('Y-m-d H:i:s');
    $error = null;
} else {
    $resumen = ['Abiertas' => 0, 'Cerradas' => 0, 'Canceladas' => 0, 'TotalOrdenes' => 0, 'MontoAbiertas' => 0, 'MontoCerradas' => 0];
    $ultimasOrdenes = [];
    $inventario = [];
    $fechaActualizacion = 'Sin datos';
    $error = 'Aún no se ha recibido el primer reporte enviado desde el cPanel local de la agencia.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidado Maestro - Catálogo de Agencias Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #040d1a; color: #ffffff; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; min-height: 100vh; padding-bottom: 40px; }
        .navbar-custom { background: rgba(10, 25, 46, 0.95); border-bottom: 1px solid rgba(255,255,255,0.08); padding: 15px 30px; }
        .agency-catalog-card { background: #0a192e; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 25px; margin-bottom: 30px; }
        .agency-btn { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.12); color: #cbd5e1; border-radius: 12px; padding: 14px 20px; transition: all 0.2s; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .agency-btn:hover, .agency-btn.active { background: #2563eb; color: #ffffff; border-color: #3b82f6; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3); }
        .card-stat { border: none; border-radius: 14px; background: #0f223d; border: 1px solid rgba(255, 255, 255, 0.08); }
        .table-custom { background: #0a192e; border-radius: 14px; padding: 20px; border: 1px solid rgba(255, 255, 255, 0.08); }
        .table-custom table { color: #e2e8f0; }
        .zero-storage-badge { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #4ade80; font-size: 0.78rem; font-weight: 700; padding: 6px 14px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; }
    </style>
</head>
<body>

    <!-- Navbar Maestro -->
    <div class="navbar-custom d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="menu.php" class="text-white text-decoration-none"><i class="bi bi-arrow-left fs-5"></i></a>
            <span class="fw-bold fs-5">PORTAL MAESTRO <span class="text-primary">| Catálogo de Agencias</span></span>
        </div>
        <div class="zero-storage-badge">
            <i class="bi bi-shield-check"></i> Consulta en Vivo bajo Demanda (Zero-Storage cPanel Maestro)
        </div>
    </div>

    <div class="container-fluid px-4">
        
        <!-- CATÁLOGO DE AGENCIAS DE GRUPO HUERTA -->
        <div class="agency-catalog-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-white"><i class="bi bi-building me-2 text-primary"></i> Catálogo de Agencias Grupo Huerta</h5>
                <span class="small text-secondary">Selecciona una sucursal para consultar su cPanel local en vivo</span>
            </div>
            <div class="row g-3">
                <?php foreach ($CATALOGO_AGENCIAS as $key => $ag): ?>
                    <div class="col-md-4">
                        <a href="reportes.php?agencia=<?php echo $key; ?>" class="agency-btn <?php echo ($agencia_seleccionada === $key) ? 'active' : ''; ?>">
                            <i class="bi <?php echo $ag['icono']; ?> fs-3"></i>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($ag['nombre']); ?></div>
                                <div class="small opacity-75"><?php echo htmlspecialchars($ag['subdominio']); ?></div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ENCABEZADO DE LA AGENCIA SELECCIONADA -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-white mb-1">
                    <i class="bi <?php echo $info_agencia['icono']; ?> text-primary me-2"></i>
                    <?php echo htmlspecialchars($info_agencia['nombre']); ?>
                </h3>
                <p class="text-secondary mb-0">
                    Subdominio: <code><?php echo htmlspecialchars($info_agencia['subdominio']); ?></code> | Almacenamiento local en su propio cPanel
                </p>
            </div>
            <div>
                <span class="badge bg-primary p-2 fs-6"><i class="bi bi-clock-history"></i> Actualizado: <?php echo htmlspecialchars($fechaActualizacion); ?></span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-info border-0 rounded-3 mb-4 text-dark" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- TARJETAS DE MÉTRICAS -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card card-stat p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-secondary small text-uppercase fw-bold">Órdenes Abiertas</div>
                            <h2 class="fw-bold text-info mb-0"><?php echo number_format($resumen['Abiertas']); ?></h2>
                            <small class="text-muted">$<?php echo number_format($resumen['MontoAbiertas'], 2); ?> MXN</small>
                        </div>
                        <i class="bi bi-folder2-open display-6 text-info opacity-75"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-stat p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-secondary small text-uppercase fw-bold">Órdenes Cerradas</div>
                            <h2 class="fw-bold text-success mb-0"><?php echo number_format($resumen['Cerradas']); ?></h2>
                            <small class="text-muted">$<?php echo number_format($resumen['MontoCerradas'], 2); ?> MXN</small>
                        </div>
                        <i class="bi bi-check-circle display-6 text-success opacity-75"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-stat p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-secondary small text-uppercase fw-bold">Canceladas</div>
                            <h2 class="fw-bold text-danger mb-0"><?php echo number_format($resumen['Canceladas']); ?></h2>
                            <small class="text-muted">Histórico</small>
                        </div>
                        <i class="bi bi-x-circle display-6 text-danger opacity-75"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-stat p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-secondary small text-uppercase fw-bold">Total Registros</div>
                            <h2 class="fw-bold text-primary mb-0"><?php echo number_format($resumen['TotalOrdenes']); ?></h2>
                            <small class="text-muted">En cPanel Local</small>
                        </div>
                        <i class="bi bi-database display-6 text-primary opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLA DE ÚLTIMAS ÓRDENES -->
        <div class="table-custom">
            <h5 class="fw-bold mb-3 text-white"><i class="bi bi-list-task text-primary me-2"></i> Órdenes de Servicio en Vivo de <?php echo htmlspecialchars($info_agencia['nombre']); ?></h5>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0">
                    <thead>
                        <tr class="text-secondary border-bottom border-secondary">
                            <th>N° Orden</th>
                            <th>Fecha Alta</th>
                            <th>Cliente</th>
                            <th>Modelo / Vehículo</th>
                            <th>Placas</th>
                            <th>Monto Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($ultimasOrdenes)): ?>
                            <?php foreach ($ultimasOrdenes as $ord): ?>
                                <tr>
                                    <td class="fw-bold text-primary">#<?php echo htmlspecialchars($ord['NumeroOrden']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($ord['Fecha'])); ?></td>
                                    <td><?php echo htmlspecialchars($ord['Cliente']); ?></td>
                                    <td><?php echo htmlspecialchars($ord['ModeloVehiculo']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($ord['Placas']); ?></span></td>
                                    <td class="fw-bold">$<?php echo number_format($ord['Monto'], 2); ?></td>
                                    <td>
                                        <?php if ($ord['Estado'] === 'ABIERTA'): ?>
                                            <span class="badge bg-info text-dark"><i class="bi bi-clock-history"></i> Abierta</span>
                                        <?php elseif ($ord['Estado'] === 'CERRADA'): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Cerrada</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-lg"></i> Cancelada</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No se han recibido datos en vivo desde el cPanel local de esta agencia.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</body>
</html>
