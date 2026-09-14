<?php
// Cargar los datos recibidos desde el archivo de caché local en cPanel
$archivoCache = __DIR__ . '/reporte_cache.json';

if (file_exists($archivoCache)) {
    $json = file_get_contents($archivoCache);
    $data = json_decode($json, true);
    $resumen = $data['resumen'] ?? ['Abiertas' => 0, 'Cerradas' => 0, 'Canceladas' => 0, 'TotalOrdenes' => 0, 'MontoAbiertas' => 0, 'MontoCerradas' => 0];
    $ultimasOrdenes = $data['ultimasOrdenes'] ?? [];
    $fechaActualizacion = $data['fecha_actualizacion'] ?? 'Desconocida';
    $error = null;
} else {
    $resumen = ['Abiertas' => 0, 'Cerradas' => 0, 'Canceladas' => 0, 'TotalOrdenes' => 0, 'MontoAbiertas' => 0, 'MontoCerradas' => 0];
    $ultimasOrdenes = [];
    $fechaActualizacion = 'Sin datos';
    $error = 'Aún no se ha recibido el primer reporte enviado desde el servidor local.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Órdenes de Servicio - Divolavilla</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card-stat { border: none; border-radius: 12px; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-3px); }
        .bg-open { background: linear-gradient(135deg, #2193b0, #6dd5ed); color: white; }
        .bg-closed { background: linear-gradient(135deg, #11998e, #38ef7d); color: white; }
        .bg-canceled { background: linear-gradient(135deg, #eb3349, #f45c43); color: white; }
        .bg-total { background: linear-gradient(135deg, #4b6cb7, #182848); color: white; }
        .table-responsive { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold text-dark"><i class="bi bi-speedometer2 text-primary"></i> Reporte de Órdenes de Servicio</h2>
                <p class="text-muted mb-0">Enviado mediante HTTPS Push desde SQL Server Local (GEDAS - 192.168.26.5)</p>
            </div>
            <span class="badge bg-success p-2"><i class="bi bi-clock-history"></i> Actualizado: <?= htmlspecialchars($fechaActualizacion) ?></span>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i>
                <strong>Esperando reporte:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Tarjetas de Resumen -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card card-stat bg-open p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 text-uppercase fw-semibold mb-1">Órdenes Abiertas</h6>
                            <h2 class="fw-bold mb-0"><?= number_format($resumen['Abiertas']) ?></h2>
                            <small>$<?= number_format($resumen['MontoAbiertas'], 2) ?> MXN</small>
                        </div>
                        <i class="bi bi-folder2-open display-5 opacity-75"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-stat bg-closed p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 text-uppercase fw-semibold mb-1">Órdenes Cerradas</h6>
                            <h2 class="fw-bold mb-0"><?= number_format($resumen['Cerradas']) ?></h2>
                            <small>$<?= number_format($resumen['MontoCerradas'], 2) ?> MXN</small>
                        </div>
                        <i class="bi bi-check-circle display-5 opacity-75"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-stat bg-canceled p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 text-uppercase fw-semibold mb-1">Canceladas</h6>
                            <h2 class="fw-bold mb-0"><?= number_format($resumen['Canceladas']) ?></h2>
                            <small>Histórico</small>
                        </div>
                        <i class="bi bi-x-circle display-5 opacity-75"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-stat bg-total p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 text-uppercase fw-semibold mb-1">Total Registradas</h6>
                            <h2 class="fw-bold mb-0"><?= number_format($resumen['TotalOrdenes']) ?></h2>
                            <small>Base de Datos GEDAS</small>
                        </div>
                        <i class="bi bi-database display-5 opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de Últimas Órdenes -->
        <div class="table-responsive">
            <h5 class="fw-bold mb-3"><i class="bi bi-list-task text-primary"></i> Últimas Órdenes Registradas</h5>
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
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
                                <td class="fw-bold">#<?= htmlspecialchars($ord['NumeroOrden']) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($ord['Fecha'])) ?></td>
                                <td><?= htmlspecialchars($ord['Cliente']) ?></td>
                                <td><?= htmlspecialchars($ord['ModeloVehiculo']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($ord['Placas']) ?></span></td>
                                <td class="fw-bold">$<?= number_format($ord['Monto'], 2) ?></td>
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
                            <td colspan="7" class="text-center text-muted py-4">No se han recibido reportes recientes desde el servidor local.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
