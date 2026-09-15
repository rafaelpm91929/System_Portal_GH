<?php
// Catálogo Maestro de Agencias - Grupo Huerta
// Cada agencia almacena sus propios datos en su cPanel local.
// El Portal Maestro consulta los datos bajo demanda en tiempo real vía HTTPS.

$CATALOGO_AGENCIAS = [
    'divolavilla' => [
        'id'          => 'divolavilla',
        'nombre'      => 'VW Divol La Villa',
        'subdominio'  => 'portal.divolavilla.com',
        'endpoint'    => 'https://portal.divolavilla.com/api_obtener_datos.php',
        'token'       => 'GedasDivolavilla2026!',
        'icono'       => 'bi-building-fill-check',
        'color'       => '#2563eb',
        'descripcion' => 'Agencia Volkswagen La Villa - Equipos e Inventario de Red'
    ],
    'seatlavilla' => [
        'id'          => 'seatlavilla',
        'nombre'      => 'Seat La Villa',
        'subdominio'  => 'seat.grupohuerta.mx',
        'endpoint'    => 'https://seat.grupohuerta.mx/api_obtener_datos.php',
        'token'       => 'GedasSeat2026!',
        'icono'       => 'bi-car-front-fill',
        'color'       => '#d97706',
        'descripcion' => 'Agencia SEAT La Villa - Servicios de Taller y Sistemas'
    ],
    'cupragarage' => [
        'id'          => 'cupragarage',
        'nombre'      => 'Cupra Garage La Villa',
        'subdominio'  => 'cupra.grupohuerta.mx',
        'endpoint'    => 'https://cupra.grupohuerta.mx/api_obtener_datos.php',
        'token'       => 'GedasCupra2026!',
        'icono'       => 'bi-speedometer2',
        'color'       => '#7c3aed',
        'descripcion' => 'Showroom y Taller Cupra Garage - Infraestructura IT'
    ]
];
?>
