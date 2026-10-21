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
        'descripcion' => 'Agencia Divol La Villa - Sistemas e Infraestructura IT',
        'direccion'   => 'Ferrocarril Hidalgo, Cam. San Juan de Aragón 890, Gustavo A. Madero, 07000 CDMX, México',
        'maps_url'    => 'https://www.google.com/maps/search/?api=1&query=' . urlencode('Ferrocarril Hidalgo, Cam. San Juan de Aragón 890, Gustavo A. Madero, CDMX'),
        'latitud'     => '19.47822',
        'longitud'    => '-99.11115',
        'encargado_sistemas' => 'Heriberto Rojo',
        'telefono_sistemas'  => '5555866655',
        'correo_sistemas'    => 'sistemas@divolavilla.com',
        'logo_url'    => 'uploads/agencias/logo_2_1789832448.png',
        'foto_url'    => 'uploads/agencias/foto_2_1789832448.jpg',
        'es_personalizada'   => false
    ]
];

// 1. Cargar agencias desde la Base de Datos si la conexión PDO está activa
if (isset($pdo) && $pdo !== null) {
    try {
        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS agencias (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    clave VARCHAR(50) UNIQUE,
                    nombre VARCHAR(100) UNIQUE,
                    razon_social VARCHAR(200),
                    rfc VARCHAR(20),
                    subdominio VARCHAR(255),
                    cpanel VARCHAR(255),
                    endpoint TEXT,
                    token VARCHAR(255),
                    local_api_token VARCHAR(255),
                    icono VARCHAR(50) DEFAULT 'bi-building-fill-check',
                    color VARCHAR(20) DEFAULT '#2563eb',
                    descripcion TEXT,
                    direccion TEXT,
                    maps_url TEXT,
                    latitud VARCHAR(50),
                    longitud VARCHAR(50),
                    encargado_sistemas VARCHAR(150),
                    telefono_sistemas VARCHAR(100),
                    correo_sistemas VARCHAR(150),
                    logo_url TEXT,
                    foto_url TEXT,
                    estatus INTEGER DEFAULT 1,
                    ultima_sincronizacion_ubicacion DATETIME,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `agencias` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `clave` VARCHAR(50) UNIQUE,
                    `nombre` VARCHAR(100) NOT NULL UNIQUE,
                    `razon_social` VARCHAR(200) DEFAULT '',
                    `rfc` VARCHAR(20) DEFAULT '',
                    `subdominio` VARCHAR(255) DEFAULT '',
                    `cpanel` VARCHAR(255) DEFAULT '',
                    `endpoint` TEXT,
                    `token` VARCHAR(255) DEFAULT 'GH_SISTEMAS_TICKETS_2026!',
                    `local_api_token` VARCHAR(255) DEFAULT 'GH_SISTEMAS_TICKETS_2026!',
                    `icono` VARCHAR(50) DEFAULT 'bi-building-fill-check',
                    `color` VARCHAR(20) DEFAULT '#2563eb',
                    `descripcion` TEXT,
                    `direccion` TEXT,
                    `maps_url` TEXT,
                    `latitud` VARCHAR(50) DEFAULT '',
                    `longitud` VARCHAR(50) DEFAULT '',
                    `encargado_sistemas` VARCHAR(150) DEFAULT '',
                    `telefono_sistemas` VARCHAR(100) DEFAULT '',
                    `correo_sistemas` VARCHAR(150) DEFAULT '',
                    `logo_url` TEXT,
                    `foto_url` TEXT,
                    `estatus` TINYINT(1) DEFAULT 1,
                    `ultima_sincronizacion_ubicacion` DATETIME DEFAULT NULL,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // Auto-asegurar columnas para esquemas previos si ya existía la tabla
        $cols = [
            'clave'               => 'VARCHAR(50)',
            'subdominio'          => 'VARCHAR(255)',
            'cpanel'              => 'VARCHAR(255)',
            'endpoint'            => 'TEXT',
            'token'               => 'VARCHAR(255)',
            'local_api_token'     => 'VARCHAR(255)',
            'icono'               => 'VARCHAR(50)',
            'color'               => 'VARCHAR(20)',
            'descripcion'         => 'TEXT',
            'direccion'           => 'TEXT',
            'maps_url'            => 'TEXT',
            'latitud'             => 'VARCHAR(50)',
            'longitud'            => 'VARCHAR(50)',
            'encargado_sistemas'  => 'VARCHAR(150)',
            'telefono_sistemas'   => 'VARCHAR(100)',
            'correo_sistemas'     => 'VARCHAR(150)',
            'logo_url'            => 'TEXT',
            'foto_url'            => 'TEXT',
            'estatus'             => 'INTEGER DEFAULT 1',
            'ultima_sincronizacion_ubicacion' => 'DATETIME'
        ];
        foreach ($cols as $cName => $cType) {
            try { $pdo->exec("ALTER TABLE agencias ADD COLUMN $cName $cType"); } catch (Throwable $e) {}
        }

        $stmtAgs = $pdo->query("SELECT * FROM agencias WHERE (estatus = 1 OR estatus IS NULL) ORDER BY id ASC");
        $dbRows = $stmtAgs ? $stmtAgs->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($dbRows as $r) {
            $nomAg = trim($r['nombre']);
            $k = !empty($r['clave']) ? $r['clave'] : preg_replace('/[^a-z0-9]/', '', strtolower($nomAg));
            if (empty($k)) $k = 'agencia_' . $r['id'];
            if (strcasecmp($nomAg, 'Divol La Villa') === 0 || $k === 'divolavilla' || $k === 'divollavilla') {
                $k = 'divolavilla';
            }

            $sub = $r['subdominio'] ?? '';
            if (empty($sub) && $k === 'divolavilla') $sub = 'portal.divolavilla.com';

            $cp = $r['cpanel'] ?? '';
            if (empty($cp) && $k === 'divolavilla') $cp = 'divolavilla.com';

            $endp = $r['endpoint'] ?? '';
            if (empty($endp) && $k === 'divolavilla') $endp = 'https://portal.divolavilla.com/sistemas/api_obtener_datos.php';

            $tok = $r['token'] ?? '';
            if (empty($tok) && $k === 'divolavilla') $tok = 'GedasDivolavilla2026!';

            $dir = !empty($r['direccion']) ? $r['direccion'] : ($k === 'divolavilla' ? 'Ferrocarril Hidalgo, Cam. San Juan de Aragón 890, Gustavo A. Madero, 07000 CDMX, México' : '');
            $lat = !empty($r['latitud']) ? $r['latitud'] : ($k === 'divolavilla' ? '19.47822' : '');
            $lng = !empty($r['longitud']) ? $r['longitud'] : ($k === 'divolavilla' ? '-99.11115' : '');

            $maps = !empty($r['maps_url']) ? $r['maps_url'] : ($dir ? ('https://www.google.com/maps/search/?api=1&query=' . urlencode($dir)) : '');

            $CATALOGO_AGENCIAS[$k] = [
                'id'          => $k,
                'db_id'       => $r['id'],
                'nombre'      => (strcasecmp($nomAg, 'Divol La Villa') === 0) ? 'Divol La Villa' : $nomAg,
                'razon_social'=> $r['razon_social'] ?? '',
                'rfc'         => $r['rfc'] ?? '',
                'cpanel'      => $cp ?: $sub,
                'subdominio'  => $sub,
                'endpoint'    => $endp ?: ('https://' . $sub . '/sistemas/api_obtener_datos.php'),
                'token'       => $tok ?: ($r['local_api_token'] ?: 'GH_SISTEMAS_TICKETS_2026!'),
                'icono'       => $r['icono'] ?: 'bi-building-fill-check',
                'color'       => $r['color'] ?: '#2563eb',
                'descripcion' => $r['descripcion'] ?: ("Agencia " . $nomAg . " - Sistemas e Infraestructura IT"),
                'direccion'   => $dir,
                'maps_url'    => $maps,
                'latitud'     => $lat,
                'longitud'    => $lng,
                'encargado_sistemas' => $r['encargado_sistemas'] ?? '',
                'telefono_sistemas'  => $r['telefono_sistemas'] ?? '',
                'correo_sistemas'    => $r['correo_sistemas'] ?? '',
                'logo_url'    => $r['logo_url'] ?? '',
                'foto_url'    => $r['foto_url'] ?? '',
                'ultima_sincronizacion_ubicacion' => $r['ultima_sincronizacion_ubicacion'] ?? '',
                'es_personalizada' => ($k !== 'divolavilla')
            ];
        }
    } catch (Throwable $e) {}
}

// 2. Cargar agencias personalizadas registradas desde agencias_custom.json
$archivoAgenciasCustom = __DIR__ . '/agencias_custom.json';
if (file_exists($archivoAgenciasCustom)) {
    $custom = json_decode(file_get_contents($archivoAgenciasCustom), true);
    if (is_array($custom)) {
        foreach ($custom as $k => $ag) {
            if (!empty($ag['nombre'])) {
                $targetKey = $k;
                if (strcasecmp(trim($ag['nombre']), 'Divol La Villa') === 0) {
                    $targetKey = 'divolavilla';
                }
                if (!isset($CATALOGO_AGENCIAS[$targetKey])) {
                    $CATALOGO_AGENCIAS[$targetKey] = $ag;
                } else {
                    foreach ($ag as $f => $v) {
                        // NO sobreescribir con valores vacíos si la BD o catálogo ya tiene datos
                        if ($v !== '' && $v !== null) {
                            $CATALOGO_AGENCIAS[$targetKey][$f] = $v;
                        }
                    }
                }
            }
        }
    }
}
?>
