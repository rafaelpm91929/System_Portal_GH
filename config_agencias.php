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
                    subdominio VARCHAR(255),
                    cpanel VARCHAR(255),
                    endpoint TEXT,
                    token VARCHAR(255),
                    local_api_token VARCHAR(255),
                    icono VARCHAR(50) DEFAULT 'bi-building-fill-check',
                    color VARCHAR(20) DEFAULT '#2563eb',
                    descripcion TEXT,
                    estatus INTEGER DEFAULT 1,
                    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `agencias` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `clave` VARCHAR(50) UNIQUE,
                    `nombre` VARCHAR(100) NOT NULL UNIQUE,
                    `subdominio` VARCHAR(255) DEFAULT '',
                    `cpanel` VARCHAR(255) DEFAULT '',
                    `endpoint` TEXT,
                    `token` VARCHAR(255) DEFAULT 'GH_SISTEMAS_TICKETS_2026!',
                    `local_api_token` VARCHAR(255) DEFAULT 'GH_SISTEMAS_TICKETS_2026!',
                    `icono` VARCHAR(50) DEFAULT 'bi-building-fill-check',
                    `color` VARCHAR(20) DEFAULT '#2563eb',
                    `descripcion` TEXT,
                    `estatus` TINYINT(1) DEFAULT 1,
                    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        $stmtAgs = $pdo->query("SELECT * FROM agencias WHERE estatus = 1 ORDER BY id ASC");
        $dbRows = $stmtAgs ? $stmtAgs->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($dbRows as $r) {
            $nomAg = trim($r['nombre']);
            $k = !empty($r['clave']) ? $r['clave'] : preg_replace('/[^a-z0-9]/', '', strtolower($nomAg));
            if (empty($k)) $k = 'agencia_' . $r['id'];
            if (strcasecmp($nomAg, 'Divol La Villa') === 0 || $k === 'divolavilla' || $k === 'divollavilla') {
                $k = 'divolavilla';
            }
            $CATALOGO_AGENCIAS[$k] = [
                'id'          => $k,
                'db_id'       => $r['id'],
                'nombre'      => (strcasecmp($nomAg, 'Divol La Villa') === 0) ? 'Divol La Villa' : $nomAg,
                'cpanel'      => $r['cpanel'] ?: $r['subdominio'],
                'subdominio'  => $r['subdominio'],
                'endpoint'    => $r['endpoint'] ?: ('https://' . $r['subdominio'] . '/api_webhook_ticket.php'),
                'token'       => $r['token'] ?: ($r['local_api_token'] ?: 'GH_SISTEMAS_TICKETS_2026!'),
                'icono'       => $r['icono'] ?: 'bi-building-fill-check',
                'color'       => $r['color'] ?: '#2563eb',
                'descripcion' => $r['descripcion'] ?: ("Agencia " . $nomAg . " - Sistemas e Infraestructura IT"),
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
                $CATALOGO_AGENCIAS[$targetKey] = $ag;
            }
        }
    }
}
?>
