<?php
if (!function_exists('obtenerTemaColorActivo') && file_exists(__DIR__ . '/permisos_helper.php')) {
    require_once __DIR__ . '/permisos_helper.php';
}
$colorHexMeta = '#0284c7';
if (function_exists('obtenerTemaColorActivo')) {
    $tMeta = obtenerTemaColorActivo();
    $colorHexMeta = $tMeta['hex_swatch'] ?? '#0284c7';
}
?>
<!-- Metadatos PWA (Web App Instalable) -->
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="<?php echo $colorHexMeta; ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Portal GH">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">

<?php
if (function_exists('renderizarEstilosTemaGlobal')) {
    renderizarEstilosTemaGlobal();
}
?>
