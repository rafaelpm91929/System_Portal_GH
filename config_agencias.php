<?php
// Catálogo Maestro de Agencias - Grupo Huerta
// Cada agencia almacena sus propios datos en su cPanel local.
// Ubicación estándar en cPanel: public_html/sistemas/api_obtener_datos.php
// El Portal Maestro consulta los datos bajo demanda en tiempo real vía HTTPS.

$CATALOGO_AGENCIAS = [
    'divolavilla' => [
        'id'          => 'divolavilla',
        'nombre'      => 'VW Divol La Villa',
        'cpanel'      => 'divolavilla.com',
        'subdominio'  => 'portal.divolavilla.com',
        'endpoint'    => 'https://portal.divolavilla.com/sistemas/api_obtener_datos.php',
        'token'       => 'GedasDivolavilla2026!',
        'icono'       => 'bi-building-fill-check',
        'color'       => '#2563eb',
        'descripcion' => 'Agencia Volkswagen La Villa - Equipos e Inventario de Red (public_html/sistemas)'
    ],
    'seatlavilla' => [
        'id'          => 'seatlavilla',
        'nombre'      => 'Seat La Villa',
        'cpanel'      => 'seat.grupohuerta.mx',
        'subdominio'  => 'seat.grupohuerta.mx',
        'endpoint'    => 'https://seat.grupohuerta.mx/sistemas/api_obtener_datos.php',
        'token'       => 'GedasSeat2026!',
        'icono'       => 'bi-car-front-fill',
        'color'       => '#d97706',
        'descripcion' => 'Agencia SEAT La Villa - Servicios de Taller y Sistemas (public_html/sistemas)'
    ],
    'cupragarage' => [
        'id'          => 'cupragarage',
        'nombre'      => 'Cupra Garage La Villa',
        'cpanel'      => 'cupra.grupohuerta.mx',
        'subdominio'  => 'cupra.grupohuerta.mx',
        'endpoint'    => 'https://cupra.grupohuerta.mx/sistemas/api_obtener_datos.php',
        'token'       => 'GedasCupra2026!',
        'icono'       => 'bi-speedometer2',
        'color'       => '#7c3aed',
        'descripcion' => 'Showroom y Taller Cupra Garage - Infraestructura IT (public_html/sistemas)'
    ]
];

// Cargar agencias personalizadas registradas dinámicamente
$archivoAgenciasCustom = __DIR__ . '/agencias_custom.json';
if (file_exists($archivoAgenciasCustom)) {
    $custom = json_decode(file_get_contents($archivoAgenciasCustom), true);
    if (is_array($custom)) {
        $CATALOGO_AGENCIAS = array_merge($CATALOGO_AGENCIAS, $custom);
    }
}
?>
