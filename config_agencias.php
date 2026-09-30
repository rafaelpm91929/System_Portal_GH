<?php
// Catálogo Maestro de Agencias - Grupo Huerta
// Cada agencia almacena sus propios datos en su cPanel local.
// Ubicación estándar en cPanel: public_html/sistemas/api_obtener_datos.php
// El Portal Maestro consulta los datos bajo demanda en tiempo real vía HTTPS.

$CATALOGO_AGENCIAS = [
    'divolavilla' => [
        'id'          => 'divolavilla',
        'nombre'      => 'Divol La Villa',
        'cpanel'      => 'divolavilla.com',
        'subdominio'  => 'portal.divolavilla.com',
        'endpoint'    => 'https://portal.divolavilla.com/sistemas/api_obtener_datos.php',
        'token'       => 'GedasDivolavilla2026!',
        'icono'       => 'bi-building-fill-check',
        'color'       => '#2563eb',
        'descripcion' => 'Agencia Divol La Villa - Sistemas e Infraestructura IT'
    ]
];

// Cargar agencias personalizadas registradas dinámicamente y deduplicar
$archivoAgenciasCustom = __DIR__ . '/agencias_custom.json';
if (file_exists($archivoAgenciasCustom)) {
    $custom = json_decode(file_get_contents($archivoAgenciasCustom), true);
    if (is_array($custom)) {
        foreach ($custom as $k => $ag) {
            $kLower = strtolower($k);
            $nomLower = strtolower($ag['nombre'] ?? '');
            $subLower = strtolower($ag['subdominio'] ?? '');
            // Consolidar cualquier variante de Divol en la clave única 'divolavilla'
            if (strpos($kLower, 'divol') !== false || strpos($nomLower, 'divol') !== false || strpos($subLower, 'divol') !== false) {
                $CATALOGO_AGENCIAS['divolavilla'] = array_merge($CATALOGO_AGENCIAS['divolavilla'], $ag, [
                    'id'          => 'divolavilla',
                    'nombre'      => 'Divol La Villa',
                    'cpanel'      => 'divolavilla.com',
                    'subdominio'  => 'portal.divolavilla.com',
                    'endpoint'    => 'https://portal.divolavilla.com/sistemas/api_obtener_datos.php'
                ]);
            } else {
                $CATALOGO_AGENCIAS[$k] = $ag;
            }
        }
    }
}
?>
