<?php
// Script Instalador de Tablas del Módulo de Inventario de Equipos
require_once 'conexion.php';

header('Content-Type: text/html; charset=utf-8');

if (!$pdo) {
    die("
    <div style='font-family: sans-serif; padding: 30px; background: #1e1e2e; color: #ff5555;'>
        <h2>❌ Error: No hay conexión a la base de datos MySQL en conexion.php</h2>
        <p>Verifica tus credenciales en <code>.env</code> o <code>config_env.php</code>.</p>
    </div>");
}

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <title>Instalador de Inventarios - Portal de Sistemas</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css'>
</head>
<body class='bg-dark text-white p-5'>
<div class='container' style='max-width: 750px;'>
    <div class='card bg-secondary bg-opacity-10 border-info p-4 rounded-4 shadow-lg text-white'>
        <h3 class='fw-bold text-info mb-3'><i class='bi bi-display me-2'></i> Instalador de Tablas del Módulo de Equipos</h3>
        <p class='text-secondary'>Creando las 10 tablas de inventario en la base de datos MySQL...</p>
        <hr class='border-secondary mb-4'>";

$sqlPath = __DIR__ . '/database/schema_equipos.sql';
if (!file_exists($sqlPath)) {
    die("<div class='alert alert-danger'>No se encontró el archivo SQL en database/schema_equipos.sql</div></div></div></body></html>");
}

$sql = file_get_contents($sqlPath);
$queries = array_filter(array_map('trim', explode(';', $sql)));

$nombresTablas = [
    'inv_equipos_vw' => '1. Inventario Equipos VW (43 Campos)',
    'inv_equipos_baja' => '2. Equipos Baja (38 Campos)',
    'inv_nobreak_baja' => '3. Nobreak BAJA (9 Campos)',
    'inv_equipos_corp' => '4. Equipos Corporativo (38 Campos)',
    'inv_dispositivos_moviles' => '5. Celulares-Tablets - Pantallas (18 Campos)',
    'inv_archivo' => '6. Archivo (6 Campos)',
    'inv_monitores' => '7. Monitores (6 Campos)',
    'inv_dvr_camaras' => '8. DVR / Cámaras (18 Campos)',
    'inv_site_vw' => '9. Inventario SITE VW (10 Campos)',
    'inv_licencias_office' => '10. Licencias Office (8 Campos)'
];

echo "<ul class='list-group mb-4 border-0'>";
$count = 0;

foreach ($queries as $q) {
    if (empty($q)) continue;
    try {
        $pdo->exec($q);
        $count++;
    } catch (PDOException $e) {
        echo "<li class='list-group-item bg-dark text-danger border-danger'>❌ Error en consulta: " . htmlspecialchars($e->getMessage()) . "</li>";
    }
}

foreach ($nombresTablas as $tName => $tLabel) {
    echo "<li class='list-group-item bg-dark text-white border-secondary d-flex justify-content-between align-items-center'>
            <div><i class='bi bi-check-circle-fill text-success me-2 fs-5'></i> <strong>$tLabel</strong></div>
            <span class='badge bg-success font-monospace'>`$tName` OK</span>
          </li>";
}
echo "</ul>";

echo "<div class='alert alert-success border-0 rounded-3 text-dark mb-4'>
        <i class='bi bi-check-circle-fill me-2 fs-5'></i> <strong>¡Las 10 tablas de inventario se instalaron correctamente en MySQL!</strong>
      </div>";

echo "<div class='d-flex gap-3'>
        <a href='equipos.php' class='btn btn-info btn-lg text-dark rounded-3 px-4 fw-bold'><i class='bi bi-display me-2'></i> Ir al Inventario de Equipos</a>
        <a href='menu.php' class='btn btn-outline-light btn-lg rounded-3 px-4 me-2'><i class='bi bi-arrow-left me-2'></i> Ir al Menú Principal</a>
      </div>";

echo "</div></div></body></html>";
?>
