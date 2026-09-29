<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión y Permisos
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

requerirPermiso('equipos', 'puede_ver');

// ----------------------------------------------------
// CONSULTA SNMP EN TIEMPO REAL PARA IMPRESORAS (TÓNER, ERRORES, PÁGINAS)
// ----------------------------------------------------
function queryPrinterSnmpValue($ip, $oid, $community = 'public', $timeout = 2) {
    $socket = @stream_socket_client('udp://' . $ip . ':161', $errno, $errstr, $timeout);
    if (!$socket) return null;
    stream_set_timeout($socket, $timeout);

    $oid_parts = array_map('intval', explode('.', trim($oid, '.')));
    $oid_bytes = chr($oid_parts[0] * 40 + $oid_parts[1]);
    for ($i = 2; $i < count($oid_parts); $i++) {
        $v = $oid_parts[$i];
        if ($v == 0) {
            $oid_bytes .= chr(0);
        } else {
            $b = [];
            while ($v > 0) {
                array_unshift($b, ($v & 0x7F) | (count($b) > 0 ? 0x80 : 0));
                $v >>= 7;
            }
            foreach ($b as $byte) $oid_bytes .= chr($byte);
        }
    }

    $oid_tlv = chr(0x06) . chr(strlen($oid_bytes)) . $oid_bytes;
    $varbind = $oid_tlv . chr(0x05) . chr(0x00);
    $varbind_seq = chr(0x30) . chr(strlen($varbind)) . $varbind;
    $varbind_list = chr(0x30) . chr(strlen($varbind_seq)) . $varbind_seq;

    $req_id = chr(0x02) . chr(0x01) . chr(rand(1, 127));
    $err = chr(0x02) . chr(0x01) . chr(0x00);
    $err_idx = chr(0x02) . chr(0x01) . chr(0x00);

    $pdu_content = $req_id . $err . $err_idx . $varbind_list;
    $pdu = chr(0xA0) . chr(strlen($pdu_content)) . $pdu_content;

    $ver = chr(0x02) . chr(0x01) . chr(0x01);
    $comm = chr(0x04) . chr(strlen($community)) . $community;

    $msg_content = $ver . $comm . $pdu;
    $pkt = chr(0x30) . chr(strlen($msg_content)) . $msg_content;

    fwrite($socket, $pkt);
    $resp = fread($socket, 2048);
    fclose($socket);

    if (empty($resp)) return null;

    $pdu_pos = strpos($resp, chr(0xA2));
    if ($pdu_pos === false) return null;

    $last_oid_pos = strpos($resp, $oid_bytes);
    if ($last_oid_pos === false) return null;

    $val_pos = $last_oid_pos + strlen($oid_bytes);
    if ($val_pos + 1 >= strlen($resp)) return null;

    $tag = ord($resp[$val_pos]);
    $len = ord($resp[$val_pos + 1]);
    $val_data = substr($resp, $val_pos + 2, $len);

    if ($tag === 0x04) {
        return trim(mb_convert_encoding($val_data, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'));
    } elseif ($tag === 0x02 || $tag === 0x41) {
        $int_val = 0;
        for ($k = 0; $k < strlen($val_data); $k++) {
            $int_val = ($int_val << 8) | ord($val_data[$k]);
        }
        if ($tag === 0x02 && (ord($val_data[0]) & 0x80)) {
            $int_val = $int_val - (1 << (strlen($val_data) * 8));
        }
        return $int_val;
    }
    return null;
}

function obtenerEstadoImpresoraSNMP($ip) {
    $model = queryPrinterSnmpValue($ip, '1.3.6.1.2.1.1.1.0');
    $status = queryPrinterSnmpValue($ip, '1.3.6.1.2.1.43.16.5.1.2.1.1');
    $tonerName = queryPrinterSnmpValue($ip, '1.3.6.1.2.1.43.11.1.1.6.1.1');
    $tonerMax = queryPrinterSnmpValue($ip, '1.3.6.1.2.1.43.11.1.1.8.1.1');
    $tonerLevel = queryPrinterSnmpValue($ip, '1.3.6.1.2.1.43.11.1.1.9.1.1');

    // Búsqueda inteligente de contador total en tiempo real (Multi-Marca OIDs)
    $counterOids = [
        '1.3.6.1.2.1.43.10.2.1.4.1.1',       // Standard Printer MIB (Ricoh, Kyocera, HP, Xerox, etc.)
        '1.3.6.1.4.1.367.3.2.1.2.19.1.0',    // Ricoh Total Counter
        '1.3.6.1.4.1.1347.43.10.1.1.12.1.1',  // Kyocera Total Counter
        '1.3.6.1.4.1.2699.1.1.1.2.1.3.1.1',   // Brother / Xerox
        '1.3.6.1.4.1.11.2.3.9.4.2.1.4.1.2.5.0'// HP Total Counter
    ];

    $counter = null;
    foreach ($counterOids as $cOid) {
        $valC = queryPrinterSnmpValue($ip, $cOid);
        if (is_numeric($valC) && $valC > 0) {
            $counter = $valC;
            break;
        }
    }

    $tonerPct = null;
    $estado_badge = 'info';
    $es_error = false;

    if (is_numeric($tonerLevel) && is_numeric($tonerMax) && $tonerMax > 0) {
        if ($tonerLevel >= 0) {
            $tonerPct = min(100, max(0, round(($tonerLevel / $tonerMax) * 100)));
        } elseif ($tonerLevel == -3) {
            $tonerPct = 100;
        } elseif ($tonerLevel == -2) {
            $tonerPct = 10;
        }
    }

    $statusClean = $status ? trim($status) : ($model ? 'En Línea' : 'Sin Respuesta (Offline)');
    $statusLower = mb_strtolower($statusClean);

    if (strpos($statusLower, 'error') !== false || strpos($statusLower, 'atasco') !== false || strpos($statusLower, 'puerta') !== false || strpos($statusLower, 'agotado') !== false) {
        $estado_badge = 'danger';
        $es_error = true;
    } elseif (strpos($statusLower, 'bajo') !== false || strpos($statusLower, 'advertencia') !== false || ($tonerPct !== null && $tonerPct <= 20)) {
        $estado_badge = 'warning';
        $es_error = true;
    } else {
        $estado_badge = 'success';
    }

    return [
        'ip' => $ip,
        'online' => ($model !== null),
        'model' => $model ? preg_replace('/[\r\n]+/', ' ', $model) : 'Desconocido',
        'status' => $statusClean,
        'toner_cartridge' => $tonerName ?? 'Tóner Genérico',
        'toner_max' => $tonerMax,
        'toner_level' => $tonerLevel,
        'toner_percent' => $tonerPct,
        'counter_raw' => $counter,
        'counter' => $counter ? number_format($counter) : 'N/A',
        'estado_badge' => $estado_badge,
        'es_error' => $es_error,
        'web_url' => 'http://' . $ip . '/'
    ];
}

// Endpoint AJAX
if (isset($_GET['action']) && $_GET['action'] === 'consultar_impresora_snmp') {
    header('Content-Type: application/json; charset=utf-8');
    $ip = trim($_GET['ip'] ?? '');
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['error' => 'Dirección IP no válida o vacía']);
        exit();
    }
    echo json_encode(obtenerEstadoImpresoraSNMP($ip));
    exit();
}

// Auto-asegurar existencia de las 10 tablas en MySQL (o SQLite local)
if ($pdo) {
    asegurarTablasEquipos($pdo);
}

$seccion_activa = $_GET['sec'] ?? 'equipos_vw';
$mensaje = '';
$error = '';

// Definición Maestra de Secciones y Configuración de Campos
$SECCIONES = [
    'equipos_vw' => [
        'nombre' => 'Inventario Equipos VW',
        'tabla' => 'inv_equipos_vw',
        'icono' => 'bi-display-fill',
        'descripcion' => 'Equipos de cómputo activos (Desktop y Laptops) con expediente completo y credenciales.',
        'columnas_tabla' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'contrasena'],
        'campos_full' => [
            'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo', 'expediente_completo',
            'dominio', 'dd', 'procesador', 'ghz', 'ram', 'ip', 'mac_wifi', 'mac_ethernet', 'sistema_op', 'serie',
            'logmein', 'contrasena', 'office', 'serie_office', 'clave_candado', 'gds', 'remoto', 'usuario_gds', 'contrasena_remoto', 'contrasena_gds',
            'correo', 'correo_oficial_planta', 'extension', 'puerto_patch_panel', 'puerto_sw', 'numero_nodo', 'servidor_sip', 'vlan', 'switch_nombre', 'power_pb', 'contrasena_pb', 'poc', 'contrasena_poc', 'msqp', 'contrasena_msqp', 'grp', 'contrasena_grp', 'etka', 'contrasena_etka',
            'costo', 'fecha_compra', 'folio_factura', 'inicio_garantia', 'fin_garantia', 'garantia2', 'renovacion_equipo', 'compra', 'proveedor', 'antivirus', 'dia_respaldo', 'hora_respaldo', 'no_break', 'modelo_nobreak', 'serie_nobreak'
        ]
    ],
    'equipos_baja' => [
        'nombre' => 'Equipos Baja',
        'tabla' => 'inv_equipos_baja',
        'icono' => 'bi-trash-fill',
        'descripcion' => 'Histórico de computadoras dadas de baja en la sucursal.',
        'columnas_tabla' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'contrasena'],
        'campos_full' => [
            'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo',
            'dominio', 'dd', 'procesador', 'ghz', 'ram', 'ip', 'mac_wifi', 'mac_ethernet', 'sistema_op', 'serie',
            'logmein', 'contrasena', 'office', 'serie_office', 'clave_candado', 'gds', 'remoto', 'usuario_gds', 'contrasena_remoto', 'contrasena_gds',
            'correo', 'correo_oficial_planta', 'extension', 'puerto_patch_panel', 'puerto_sw', 'numero_nodo', 'costo', 'compra', 'proveedor', 'antivirus', 'dia_respaldo', 'hora_respaldo', 'no_break', 'modelo_nobreak', 'serie_nobreak', 'expediente_completo', 'motivo_baja'
        ]
    ],
    'dvr' => [
        'nombre' => 'DVR/NVR, CÁMARAS',
        'tabla' => 'inv_dvr_camaras',
        'icono' => 'bi-camera-video-fill',
        'descripcion' => 'Control de grabadores DVR, NVR y cámaras de circuito cerrado (CCTV).',
        'columnas_tabla' => ['id', 'tipo_registro', 'nombre', 'dvr_vinculado', 'dvr', 'ip', 'contrasena_dvr'],
        'campos_full' => ['tipo_registro', 'subtipo_camara', 'dvr_vinculado', 'nombre', 'modelo', 'ip', 'costo', 'contrasena_camara', 'canal_analogico', 'canal_ip', 'ubicacion', 'camaras', 'dvr', 'numero_serie', 'almacenamiento', 'cam_totales_ip', 'disponibles_ip', 'cam_totales_analogicas', 'disponibles', 'capacidad_actual', 'capacidad_discos', 'numero_discos', 'usuario_dvr', 'contrasena_dvr', 'dias_grabacion']
    ],
    'nobreak_baja' => [
        'nombre' => 'Nobreak BAJA',
        'tabla' => 'inv_nobreak_baja',
        'icono' => 'bi-lightning-charge-fill',
        'descripcion' => 'Registro de reguladores y No-Breaks dados de baja.',
        'columnas_tabla' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'modelo_nobreak'],
        'campos_full' => ['departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'modelo', 'serie_equipo', 'modelo_nobreak', 'serie_nobreak', 'costo', 'motivo_baja']
    ],
    'equipos_corp' => [
        'nombre' => 'Equipos Corporativo',
        'tabla' => 'inv_equipos_corp',
        'icono' => 'bi-building-fill',
        'descripcion' => 'Inventario de computadoras asignadas a la oficina corporativa.',
        'columnas_tabla' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'contrasena'],
        'campos_full' => [
            'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo',
            'dominio', 'dd', 'procesador', 'ghz', 'ram', 'ip', 'mac_wifi', 'mac_ethernet', 'sistema_op', 'serie',
            'logmein', 'contrasena', 'office', 'serie_office', 'clave_candado', 'gds', 'remoto', 'usuario_gds', 'contrasena_remoto', 'contrasena_gds',
            'correo', 'correo_oficial_planta', 'extension', 'puerto_patch_panel', 'puerto_sw', 'numero_nodo', 'costo', 'compra', 'proveedor', 'antivirus', 'dia_respaldo', 'hora_respaldo', 'no_break', 'modelo_nobreak', 'serie_nobreak', 'expediente_completo'
        ]
    ],
    'moviles' => [
        'nombre' => 'Celulares-Tablets-Pantallas',
        'tabla' => 'inv_dispositivos_moviles',
        'icono' => 'bi-phone-fill',
        'descripcion' => 'Control de celulares, tabletas de asesores, pantallas corporativas y otros dispositivos.',
        'columnas_tabla' => ['id', 'tipo_registro', 'nombre', 'marca', 'modelo', 'serie', 'departamento'],
        'campos_full' => ['tipo_registro', 'subtipo_dispositivo', 'marca', 'modelo', 'serie', 'imei_1', 'imei_2', 'numero_telefonico', 'almacenamiento', 'ram', 'color', 'sistema_op', 'mac', 'estado_fisico', 'estatus', 'tamano_pantalla', 'resolucion', 'especificaciones', 'accesorios', 'nombre', 'departamento', 'ubicacion', 'fecha_asignacion', 'costo', 'folio_factura', 'fecha_compra', 'proveedor', 'garantia', 'tiene_plan_celular', 'vencimiento_plan', 'proveedor_plan', 'numero_contrato_plan', 'observaciones']
    ],
    'monitores' => [
        'nombre' => 'Impresoras',
        'tabla' => 'inv_monitores',
        'icono' => 'bi-printer-fill',
        'descripcion' => 'Catálogo e inventario de impresoras corporativas y de agencia.',
        'columnas_tabla' => ['id', 'departamento', 'ubicacion', 'marca', 'modelo_exacto', 'serie', 'ip', 'numero_nodo', 'puerto_sw', 'usuario_impresora'],
        'campos_full' => ['marca', 'modelo_exacto', 'serie', 'departamento', 'ubicacion', 'fecha_adquisicion', 'proveedor', 'contrato', 'ip', 'numero_nodo', 'puerto_sw', 'usuario_impresora', 'contrasena_impresora', 'usuario_impresora_web', 'contrasena_impresora_web']
    ],
    'telefonos_poe' => [
        'nombre' => 'Teléfonos PoE',
        'tabla' => 'inv_telefonos_poe',
        'icono' => 'bi-telephone-fill',
        'descripcion' => 'Inventario exclusivo de teléfonos IP PoE, extensiones, líneas y conectividad en serie.',
        'columnas_tabla' => ['id', 'extension', 'numero_telefonico', 'usuario', 'area', 'numero_nodo'],
        'campos_full' => [
            'usuario', 'area', 'modelo', 'serie', 'mac', 'ip',
            'numero_telefonico', 'extension', 'tipo_licencia', 'correo', 'portabilidad',
            'folio_factura', 'numero_nodo', 'puerto_sw', 'switch_nombre'
        ]
    ],
    'site_vw' => [
        'nombre' => 'Inventario SITE VW',
        'tabla' => 'inv_site_vw',
        'icono' => 'bi-hdd-rack-fill',
        'descripcion' => 'Servidores, switches, routers, NAS, firewalls, gateways y UPS alojados en el SITE principal.',
        'columnas_tabla' => ['id', 'tipo_registro', 'nombre_equipo', 'modelo', 'ip', 'contrasena'],
        'campos_full' => ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'mac_wifi', 'cantidad_puertos', 'velocidad_puertos', 'tipo_switch', 'firmware_version', 'posicion_rack', 'ubicacion', 'estado', 'costo', 'folio_factura', 'garantia', 'usuario', 'contrasena', 'sistema_op', 'procesador', 'ram', 'almacenamiento', 'cantidad_discos', 'tipo_servidor', 'funcion_servicio', 'ambiente', 'criticidad', 'puerto_sw', 'fecha_compra', 'bahias_nas', 'capacidad_disco_ind', 'capacidad_disponible', 'config_raid', 'tipo_discos', 'protocolos_nas', 'proveedor', 'tipo_enlace', 'ancho_banda', 'simetria_enlace', 'tipo_conexion', 'numero_contrato', 'numero_cuenta', 'circuit_id', 'soporte_contacto', 'ip_publica', 'ip_local', 'unifi_os_ver', 'controller_ver', 'velocidad_enlace', 'ssids', 'vlans', 'poe', 'controlador_ap', 'capacidad_va', 'capacidad_w', 'tipo_ups', 'voltaje_entrada', 'voltaje_salida', 'cant_baterias', 'specs_baterias', 'fecha_bateria', 'autonomia']
    ],
    'graficas' => [
        'nombre' => 'Gráficas & Analytics Global',
        'tabla' => 'inv_equipos_vw',
        'icono' => 'bi-pie-chart-fill',
        'descripcion' => 'Dashboard estadístico consolidado con métricas, inversión, garantías y renovaciones de todo el inventario de la agencia.',
        'columnas_tabla' => ['id', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'contrasena'],
        'campos_full' => []
    ]
];

if (!isset($SECCIONES[$seccion_activa])) {
    $seccion_activa = 'equipos_vw';
}

$infoSeccion = $SECCIONES[$seccion_activa];
$tablaActual = $infoSeccion['tabla'];

// =========================================================================
// VALIDACIÓN DE REGLAS DE NEGOCIO Y UNICIDAD EN EL INVENTARIO
// =========================================================================
function validarUnicidadEquipo($pdo, $tablaActual, $id, $fields) {
    $errores = [];

    $tablasInventario = [
        'inv_equipos_vw' => ['nombre' => 'Inventario Equipos VW', 'campo_nombre' => 'nombre_equipo'],
        'inv_equipos_corp' => ['nombre' => 'Equipos Corporativo', 'campo_nombre' => 'nombre_equipo'],
        'inv_site_vw' => ['nombre' => 'Inventario SITE VW', 'campo_nombre' => 'nombre_equipo'],
        'inv_monitores' => ['nombre' => 'Impresoras', 'campo_nombre' => 'modelo_exacto'],
        'inv_dispositivos_moviles' => ['nombre' => 'Celulares/Tablets', 'campo_nombre' => 'nombre'],
        'inv_dvr_camaras' => ['nombre' => 'DVR/Cámaras', 'campo_nombre' => 'nombre']
    ];

    $obtenerNombreItem = function($r, $tbl) use ($tablasInventario) {
        $c = $tablasInventario[$tbl]['campo_nombre'] ?? 'nombre_equipo';
        if (!empty($r[$c])) return $r[$c];
        if (!empty($r['nombre_equipo'])) return $r['nombre_equipo'];
        if (!empty($r['nombre'])) return $r['nombre'];
        if (!empty($r['modelo_exacto'])) return trim(($r['marca'] ?? '') . ' ' . $r['modelo_exacto']);
        if (!empty($r['extension'])) return 'Teléfono Ext. ' . $r['extension'] . (!empty($r['usuario']) ? ' (' . $r['usuario'] . ')' : '');
        if (!empty($r['usuario'])) return 'Usuario: ' . $r['usuario'];
        if (!empty($r['marca']) || !empty($r['modelo'])) return trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''));
        return 'Equipo #' . ($r['id'] ?? '?');
    };

    // 1. REGLA: DIRECCIÓN MAC ÚNICA
    $macsToCheck = [];
    foreach (['mac_ethernet', 'mac_wifi', 'direccion_mac', 'mac'] as $kMac) {
        if (!empty($fields[$kMac])) {
            $rawMac = trim($fields[$kMac]);
            $upperMac = strtoupper($rawMac);
            if (!in_array($upperMac, ['SIN MAC', 'N/A', 'NO TIENE', 'NO APLICA', 'PENDIENTE', '00:00:00:00:00:00', '--', ''])) {
                $cleanMac = preg_replace('/[^A-Za-z0-9]/', '', $upperMac);
                if (strlen($cleanMac) >= 8) {
                    $macsToCheck[$cleanMac] = $rawMac;
                }
            }
        }
    }

    if (!empty($macsToCheck)) {
        $tablasMac = ['inv_equipos_vw', 'inv_equipos_corp', 'inv_site_vw', 'inv_dispositivos_moviles', 'inv_telefonos_poe'];
        foreach ($tablasMac as $tbl) {
            try {
                $cols = [];
                if ($tbl === 'inv_dispositivos_moviles' || $tbl === 'inv_telefonos_poe') {
                    $cols = ['mac'];
                } elseif ($tbl === 'inv_site_vw') {
                    $cols = ['mac_ethernet', 'mac_wifi'];
                } else {
                    $cols = ['mac_ethernet', 'mac_wifi', 'direccion_mac'];
                }
                
                $conditions = [];
                foreach ($cols as $col) {
                    $conditions[] = "($col IS NOT NULL AND $col != '')";
                }
                $sql = "SELECT * FROM `$tbl` WHERE (" . implode(' OR ', $conditions) . ")";
                $params = [];
                if ($tbl === $tablaActual && $id > 0) {
                    $sql .= " AND id != ?";
                    $params[] = $id;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $r) {
                    foreach ($cols as $col) {
                        $dbMac = trim($r[$col] ?? '');
                        if (!empty($dbMac)) {
                            $cleanDbMac = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($dbMac));
                            if (isset($macsToCheck[$cleanDbMac])) {
                                $nomOtro = $obtenerNombreItem($r, $tbl);
                                $secOtro = $tablasInventario[$tbl]['nombre'] ?? $tbl;
                                $errores[] = "La Dirección MAC '<strong>" . htmlspecialchars($macsToCheck[$cleanDbMac]) . "</strong>' ya está registrada en el equipo '<strong>" . htmlspecialchars($nomOtro) . "</strong>' ({$secOtro}). No se permiten direcciones MAC duplicadas.";
                                break 3;
                            }
                        }
                    }
                }
            } catch (Throwable $t) {}
        }
    }

    // 2. REGLA: DIRECCIÓN IP ÚNICA
    $ipsToCheck = [];
    foreach (['ip', 'ip_local'] as $kIp) {
        if (!empty($fields[$kIp])) {
            $rawIp = trim($fields[$kIp]);
            $upperIp = strtoupper($rawIp);
            if (!in_array($upperIp, ['SIN IP', '0.0.0.0', 'DHCP', 'DINAMICA', 'DINÁMICA', 'N/A', 'NO TIENE', 'NO APLICA', 'PENDIENTE', '--', ''])) {
                $ipsToCheck[strtolower($rawIp)] = $rawIp;
            }
        }
    }

    if (!empty($ipsToCheck)) {
        $tablasIp = ['inv_equipos_vw', 'inv_equipos_corp', 'inv_site_vw', 'inv_monitores', 'inv_dvr_camaras', 'inv_telefonos_poe'];
        foreach ($tablasIp as $tbl) {
            try {
                $cols = ($tbl === 'inv_site_vw') ? ['ip', 'ip_local'] : ['ip'];
                $conditions = [];
                $params = [];
                foreach ($cols as $col) {
                    foreach ($ipsToCheck as $cleanIp => $origIp) {
                        $conditions[] = "LOWER(TRIM($col)) = ?";
                        $params[] = $cleanIp;
                    }
                }
                $sql = "SELECT * FROM `$tbl` WHERE (" . implode(' OR ', $conditions) . ")";
                if ($tbl === $tablaActual && $id > 0) {
                    $sql .= " AND id != ?";
                    $params[] = $id;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $matched = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($matched) {
                    $matchedIp = '';
                    foreach ($cols as $col) {
                        $val = strtolower(trim($matched[$col] ?? ''));
                        if (isset($ipsToCheck[$val])) {
                            $matchedIp = $ipsToCheck[$val];
                            break;
                        }
                    }
                    $nomOtro = $obtenerNombreItem($matched, $tbl);
                    $secOtro = $tablasInventario[$tbl]['nombre'] ?? $tbl;
                    $errores[] = "La Dirección IP '<strong>" . htmlspecialchars($matchedIp) . "</strong>' ya está asignada al equipo '<strong>" . htmlspecialchars($nomOtro) . "</strong>' ({$secOtro}). Cada equipo debe tener una IP única en la red.";
                    break;
                }
            } catch (Throwable $t) {}
        }
    }

    // 3. REGLA: NÚMERO DE SERIE ÚNICO
    $seriesToCheck = [];
    foreach (['serie', 'numero_serie', 'serie_equipo'] as $kSer) {
        if (!empty($fields[$kSer])) {
            $rawSer = trim($fields[$kSer]);
            $upperSer = strtoupper($rawSer);
            if (!in_array($upperSer, ['S/N', 'SIN SERIE', 'N/A', 'NO APLICA', 'PENDIENTE', 'DESCONOCIDO', '--', '0', ''])) {
                if (strlen($upperSer) >= 3) {
                    $seriesToCheck[$upperSer] = $rawSer;
                }
            }
        }
    }

    if (!empty($seriesToCheck)) {
        $tablasSerie = ['inv_equipos_vw', 'inv_equipos_corp', 'inv_site_vw', 'inv_monitores', 'inv_dispositivos_moviles', 'inv_dvr_camaras', 'inv_telefonos_poe'];
        foreach ($tablasSerie as $tbl) {
            try {
                $colSerie = ($tbl === 'inv_dvr_camaras') ? 'numero_serie' : 'serie';
                $conditions = [];
                $params = [];
                foreach ($seriesToCheck as $cleanSer => $origSer) {
                    $conditions[] = "UPPER(TRIM($colSerie)) = ?";
                    $params[] = $cleanSer;
                }
                $sql = "SELECT * FROM `$tbl` WHERE (" . implode(' OR ', $conditions) . ")";
                if ($tbl === $tablaActual && $id > 0) {
                    $sql .= " AND id != ?";
                    $params[] = $id;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $matched = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($matched) {
                    $matchedSer = trim($matched[$colSerie] ?? '');
                    $nomOtro = $obtenerNombreItem($matched, $tbl);
                    $secOtro = $tablasInventario[$tbl]['nombre'] ?? $tbl;
                    $errores[] = "El Número de Serie '<strong>" . htmlspecialchars($matchedSer) . "</strong>' ya pertenece al equipo '<strong>" . htmlspecialchars($nomOtro) . "</strong>' ({$secOtro}). No se permiten números de serie duplicados.";
                    break;
                }
            } catch (Throwable $t) {}
        }
    }

    // 4. REGLA: MISMO NODO DE RED ÚNICO ENTRE EQUIPOS ACTIVOS
    // EXCEPCIÓN PERMITIDA: Únicamente 1 computadora/laptop y 1 teléfono PoE pueden compartir el mismo nodo (conectados en serie).
    if (!empty($fields['numero_nodo'])) {
        $rawNodo = trim($fields['numero_nodo']);
        $upperNodo = strtoupper($rawNodo);
        if (!in_array($upperNodo, ['SIN NODO', 'N/A', 'NO TIENE', 'NO APLICA', 'PENDIENTE', '--', ''])) {
            $isTelActual = ($tablaActual === 'inv_telefonos_poe');
            $isPcActual = in_array($tablaActual, ['inv_equipos_vw', 'inv_equipos_corp']);

            $tablasNodo = [
                'inv_equipos_vw' => 'inv_equipos_vw',
                'inv_equipos_corp' => 'inv_equipos_corp',
                'inv_site_vw' => 'inv_site_vw',
                'inv_monitores' => 'inv_monitores',
                'inv_telefonos_poe' => 'inv_telefonos_poe'
            ];

            $equiposConMismoNodo = [];
            foreach ($tablasNodo as $tbl) {
                try {
                    $sql = "SELECT * FROM `$tbl` WHERE LOWER(TRIM(numero_nodo)) = LOWER(TRIM(?))";
                    $params = [$rawNodo];
                    if ($tbl === $tablaActual && $id > 0) {
                        $sql .= " AND id != ?";
                        $params[] = $id;
                    }
                    if ($tbl !== 'inv_site_vw' && $tbl !== 'inv_monitores') {
                        $sql .= " AND (estado IS NULL OR estado != 'Baja')";
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $rowsFound = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rowsFound as $rf) {
                        $equiposConMismoNodo[] = [
                            'tabla' => $tbl,
                            'row' => $rf,
                            'es_telefono' => ($tbl === 'inv_telefonos_poe'),
                            'es_pc' => in_array($tbl, ['inv_equipos_vw', 'inv_equipos_corp'])
                        ];
                    }
                } catch (Throwable $t) {}
            }

            if (!empty($equiposConMismoNodo)) {
                $telCount = 0;
                $nonTelCount = 0;
                $telDetails = [];
                $nonTelDetails = [];

                foreach ($equiposConMismoNodo as $itemEq) {
                    $nomOtro = $obtenerNombreItem($itemEq['row'], $itemEq['tabla']);
                    $secOtro = $tablasInventario[$itemEq['tabla']]['nombre'] ?? $itemEq['tabla'];
                    if ($itemEq['es_telefono']) {
                        $telCount++;
                        $telDetails[] = "{$nomOtro} ({$secOtro})";
                    } else {
                        $nonTelCount++;
                        $nonTelDetails[] = "{$nomOtro} ({$secOtro})";
                    }
                }

                if ($isTelActual) {
                    if ($telCount > 0) {
                        $errores[] = "El Nodo de Red '<strong>" . htmlspecialchars($rawNodo) . "</strong>' ya está asignado al Teléfono PoE '<strong>" . htmlspecialchars(implode(', ', $telDetails)) . "</strong>'. No se pueden tener dos teléfonos PoE en el mismo nodo.";
                    } elseif ($nonTelCount > 1) {
                        $errores[] = "El Nodo de Red '<strong>" . htmlspecialchars($rawNodo) . "</strong>' ya tiene múltiples dispositivos asignados (" . htmlspecialchars(implode(', ', $nonTelDetails)) . ").";
                    } elseif ($nonTelCount === 1) {
                        $itemUnico = $equiposConMismoNodo[0];
                        if (!$itemUnico['es_pc']) {
                            $errores[] = "El Nodo de Red '<strong>" . htmlspecialchars($rawNodo) . "</strong>' está asignado a un equipo no compatible ('<strong>" . htmlspecialchars($nonTelDetails[0]) . "</strong>'). Los teléfonos PoE solo pueden compartir nodo en serie con una computadora o laptop.";
                        }
                    }
                } elseif ($isPcActual) {
                    if ($nonTelCount > 0) {
                        $errores[] = "El Nodo de Red '<strong>" . htmlspecialchars($rawNodo) . "</strong>' ya está asignado a '<strong>" . htmlspecialchars(implode(', ', $nonTelDetails)) . "</strong>'. Solo se permite compartir nodo entre 1 computadora y 1 teléfono PoE conectado en serie.";
                    } elseif ($telCount > 1) {
                        $errores[] = "El Nodo de Red '<strong>" . htmlspecialchars($rawNodo) . "</strong>' excede el límite de equipos permitidos.";
                    }
                } else {
                    $todos = array_merge($nonTelDetails, $telDetails);
                    $errores[] = "El Nodo de Red '<strong>" . htmlspecialchars($rawNodo) . "</strong>' ya está ocupado por '<strong>" . htmlspecialchars(implode(', ', $todos)) . "</strong>'. Este tipo de equipo requiere un nodo exclusivo.";
                }
            }
        }
    }

    // 5. REGLA: MISMO PUERTO DE SWITCH ÚNICO ENTRE EQUIPOS ACTIVOS
    // EXCEPCIÓN PERMITIDA: Únicamente 1 computadora/laptop y 1 teléfono PoE pueden compartir el mismo puerto de switch (conectados en cascada).
    if (!empty($fields['puerto_sw'])) {
        $rawPuerto = trim($fields['puerto_sw']);
        $upperPuerto = strtoupper($rawPuerto);
        if (!in_array($upperPuerto, ['SIN PUERTO', 'N/A', 'NO TIENE', 'NO APLICA', 'PENDIENTE', '--', ''])) {
            $isTelActual = ($tablaActual === 'inv_telefonos_poe');
            $isPcActual = in_array($tablaActual, ['inv_equipos_vw', 'inv_equipos_corp']);

            $tablasPuerto = [
                'inv_equipos_vw' => 'inv_equipos_vw',
                'inv_equipos_corp' => 'inv_equipos_corp',
                'inv_site_vw' => 'inv_site_vw',
                'inv_monitores' => 'inv_monitores',
                'inv_telefonos_poe' => 'inv_telefonos_poe'
            ];

            $equiposConMismoPuerto = [];
            foreach ($tablasPuerto as $tbl) {
                try {
                    $sql = "SELECT * FROM `$tbl` WHERE LOWER(TRIM(puerto_sw)) = LOWER(TRIM(?))";
                    $params = [$rawPuerto];
                    if ($tbl === $tablaActual && $id > 0) {
                        $sql .= " AND id != ?";
                        $params[] = $id;
                    }
                    if ($tbl !== 'inv_site_vw' && $tbl !== 'inv_monitores') {
                        $sql .= " AND (estado IS NULL OR estado != 'Baja')";
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $rowsFound = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rowsFound as $rf) {
                        $equiposConMismoPuerto[] = [
                            'tabla' => $tbl,
                            'row' => $rf,
                            'es_telefono' => ($tbl === 'inv_telefonos_poe'),
                            'es_pc' => in_array($tbl, ['inv_equipos_vw', 'inv_equipos_corp'])
                        ];
                    }
                } catch (Throwable $t) {}
            }

            if (!empty($equiposConMismoPuerto)) {
                $telCount = 0;
                $nonTelCount = 0;
                $telDetails = [];
                $nonTelDetails = [];

                foreach ($equiposConMismoPuerto as $itemEq) {
                    $nomOtro = $obtenerNombreItem($itemEq['row'], $itemEq['tabla']);
                    $secOtro = $tablasInventario[$itemEq['tabla']]['nombre'] ?? $itemEq['tabla'];
                    if ($itemEq['es_telefono']) {
                        $telCount++;
                        $telDetails[] = "{$nomOtro} ({$secOtro})";
                    } else {
                        $nonTelCount++;
                        $nonTelDetails[] = "{$nomOtro} ({$secOtro})";
                    }
                }

                if ($isTelActual) {
                    if ($telCount > 0) {
                        $errores[] = "El Puerto de Switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' ya está asignado al Teléfono PoE '<strong>" . htmlspecialchars(implode(', ', $telDetails)) . "</strong>'. No se pueden conectar dos teléfonos PoE al mismo puerto.";
                    } elseif ($nonTelCount > 1) {
                        $errores[] = "El Puerto de Switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' ya tiene múltiples dispositivos asignados (" . htmlspecialchars(implode(', ', $nonTelDetails)) . ").";
                    } elseif ($nonTelCount === 1) {
                        $itemUnico = $equiposConMismoPuerto[0];
                        if (!$itemUnico['es_pc']) {
                            $errores[] = "El Puerto de Switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' está asignado a un equipo no compatible ('<strong>" . htmlspecialchars($nonTelDetails[0]) . "</strong>'). Los teléfonos PoE solo pueden compartir puerto en cascada con una computadora o laptop.";
                        }
                    }
                } elseif ($isPcActual) {
                    if ($nonTelCount > 0) {
                        $errores[] = "El Puerto de Switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' ya está asignado a '<strong>" . htmlspecialchars(implode(', ', $nonTelDetails)) . "</strong>'. Solo se permite compartir puerto entre 1 computadora y 1 teléfono PoE en cascada.";
                    } elseif ($telCount > 1) {
                        $errores[] = "El Puerto de Switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' excede el límite de equipos permitidos.";
                    }
                } else {
                    $todos = array_merge($nonTelDetails, $telDetails);
                    $errores[] = "El Puerto de Switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' ya está ocupado por '<strong>" . htmlspecialchars(implode(', ', $todos)) . "</strong>'. Este tipo de equipo requiere un puerto exclusivo.";
                }
            }

            // También verificar en infra_switch_puertos (verificando compatibilidad cascada)
            if (empty($errores)) {
                try {
                    $currKey = ($tablaActual === 'inv_site_vw' ? 'SITE-' : ($tablaActual === 'inv_telefonos_poe' ? 'TEL-' : 'EQ-')) . $id;
                    $partsP = explode('/', $rawPuerto);
                    $swNomVal = trim($partsP[0] ?? '');
                    $pNumVal = intval(preg_replace('/[^0-9]/', '', $partsP[1] ?? $rawPuerto));
                    if (!empty($swNomVal) && $pNumVal > 0) {
                        $stmtSwCheck = $pdo->prepare("SELECT equipo_key, equipo_nombre, equipo_tipo, telefono_poe_key, telefono_poe_nombre FROM infra_switch_puertos WHERE (switch_nombre = ? OR switch_key = ?) AND puerto_numero = ?");
                        $stmtSwCheck->execute([$swNomVal, 'SW-' . $swNomVal, $pNumVal]);
                        $swMatch = $stmtSwCheck->fetch(PDO::FETCH_ASSOC);
                        if ($swMatch) {
                            $eqKeyInSw = $swMatch['equipo_key'] ?? '';
                            $telKeyInSw = $swMatch['telefono_poe_key'] ?? '';
                            if ($currKey !== $eqKeyInSw && $currKey !== $telKeyInSw) {
                                if ($isTelActual) {
                                    if (!empty($telKeyInSw) || strpos($eqKeyInSw, 'TEL-') === 0) {
                                        $errores[] = "El puerto de switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' ya tiene un teléfono PoE conectado.";
                                    }
                                } elseif ($isPcActual) {
                                    if (!empty($eqKeyInSw) && strpos($eqKeyInSw, 'TEL-') !== 0) {
                                        $errores[] = "El puerto de switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' ya está ocupado por la computadora '<strong>" . htmlspecialchars($swMatch['equipo_nombre'] ?: $eqKeyInSw) . "</strong>'.";
                                    }
                                } else {
                                    if (!empty($eqKeyInSw) || !empty($telKeyInSw)) {
                                        $errores[] = "El puerto de switch '<strong>" . htmlspecialchars($rawPuerto) . "</strong>' ya está ocupado en la matriz de red.";
                                    }
                                }
                            }
                        }
                    }
                } catch (Throwable $t) {}
            }
        }
    }

    return $errores;
}

// ----------------------------------------------------
// PROCESAMIENTO DE ACCIONES (CREAR / EDITAR / ELIMINAR)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    // Verificación AJAX anticipada de reglas de unicidad
    if (isset($_POST['ajax_validar_unicidad'])) {
        header('Content-Type: application/json');
        $id = intval($_POST['registro_id'] ?? 0);
        $fields = $_POST['f'] ?? [];
        $errores = validarUnicidadEquipo($pdo, $tablaActual, $id, $fields);
        if (!empty($errores)) {
            echo json_encode(['status' => 'error', 'errores' => $errores]);
        } else {
            echo json_encode(['status' => 'ok']);
        }
        exit();
    }

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar_registro') {
        $id = intval($_POST['registro_id'] ?? 0);
        $fields = $_POST['f'] ?? [];

        if ($seccion_activa === 'dvr' || $seccion_activa === 'site_vw') {
            $fields['departamento'] = 'Sistemas';
        }

        if (!empty($fields)) {
            // Validar reglas de negocio y unicidad
            $erroresUnicidad = validarUnicidadEquipo($pdo, $tablaActual, $id, $fields);
            if (!empty($erroresUnicidad)) {
                $error = '<i class="bi bi-shield-x me-2 fs-5"></i> <strong>Conflicto con Reglas de Unicidad:</strong><br>' . implode('<br>', $erroresUnicidad);
            } else {
                try {
                    // Auto-asegurar columnas en la tabla si no existen en la BD
                    try {
                        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                        $colsExistentes = [];
                        if ($driver === 'sqlite') {
                            $stmtCols = $pdo->query("PRAGMA table_info(`$tablaActual`)");
                            if ($stmtCols) {
                                $colsExistentes = array_column($stmtCols->fetchAll(PDO::FETCH_ASSOC), 'name');
                            }
                        } else {
                            $stmtCols = $pdo->query("SHOW COLUMNS FROM `$tablaActual`");
                            if ($stmtCols) {
                                $colsExistentes = array_column($stmtCols->fetchAll(PDO::FETCH_ASSOC), 'Field');
                            }
                        }

                        if (!empty($colsExistentes)) {
                            foreach (array_keys($fields) as $col) {
                                if (!in_array($col, $colsExistentes)) {
                                    try {
                                        $pdo->exec("ALTER TABLE `$tablaActual` ADD COLUMN `$col` TEXT");
                                    } catch (Throwable $tAddCol) {}
                                }
                            }
                        }
                    } catch (Throwable $tSchemaCheck) {}

                    if ($id > 0) {
                        // Update
                        $setPairs = [];
                        $params = [];
                        foreach ($fields as $col => $val) {
                            $setPairs[] = "`$col` = ?";
                            $params[] = trim($val);
                        }
                        $params[] = $id;
                        $sql = "UPDATE `$tablaActual` SET " . implode(', ', $setPairs) . " WHERE id = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $mensaje = "Registro actualizado correctamente.";
                    } else {
                        // Insert
                        $cols = array_keys($fields);
                        $placeholders = array_fill(0, count($cols), '?');
                        $sql = "INSERT INTO `$tablaActual` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute(array_values($fields));
                        $id = intval($pdo->lastInsertId());
                        $mensaje = "Registro guardado exitosamente en " . htmlspecialchars($infoSeccion['nombre']) . ".";
                    }

                // =========================================================================
                // SINCRONIZACIÓN AUTOMÁTICA EN TIEMPO REAL: INVENTARIO <-> SWITCHES <-> NODOS
                // =========================================================================
                if ($id > 0) {
                    try {
                        $swPuertoRaw = trim($fields['puerto_sw'] ?? '');
                        $swNombre = trim($fields['switch_nombre'] ?? '');
                        $nodoCodigo = trim($fields['numero_nodo'] ?? '');
                        $patchPanel = trim($fields['puerto_patch_panel'] ?? '');
                        $eqNombre = trim($fields['nombre_equipo'] ?? $fields['nombre'] ?? $fields['modelo_exacto'] ?? $fields['usuario'] ?? ('Equipo #' . $id));
                        $eqTipo = trim($fields['tipo_equipo'] ?? $fields['tipo_registro'] ?? 'Equipo');
                        $eqIp = trim($fields['ip'] ?? $fields['ip_local'] ?? '');
                        $eqKey = ($tablaActual === 'inv_site_vw' ? 'SITE-' : 'EQ-') . $id;

                        // Asegurar tabla infra_switch_puertos
                        $pdo->exec("CREATE TABLE IF NOT EXISTS infra_switch_puertos (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            switch_key TEXT NOT NULL,
                            switch_nombre TEXT NOT NULL,
                            puerto_numero INTEGER NOT NULL,
                            equipo_key TEXT,
                            equipo_nombre TEXT,
                            equipo_tipo TEXT,
                            equipo_ip TEXT,
                            nodo_codigo TEXT,
                            vlan TEXT,
                            estatus TEXT DEFAULT 'activo',
                            notas TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        )");

                        $puertoNum = 0;
                        if (!empty($swPuertoRaw)) {
                            if (strpos($swPuertoRaw, '/') !== false) {
                                $partsSw = explode('/', $swPuertoRaw);
                                if (empty($swNombre)) $swNombre = trim($partsSw[0]);
                                $puertoNum = intval(preg_replace('/[^0-9]/', '', $partsSw[1] ?? ''));
                            } else {
                                $puertoNum = intval(preg_replace('/[^0-9]/', '', $swPuertoRaw));
                            }
                        }

                        // 1. Desvincular asignaciones previas de este equipo en otros puertos
                        $stmtDelOld = $pdo->prepare("DELETE FROM infra_switch_puertos WHERE equipo_key = ?");
                        $stmtDelOld->execute([$eqKey]);

                        // 2. Si tiene Switch y Puerto asignado, registrar en infra_switch_puertos
                        if (!empty($swNombre) && $puertoNum > 0) {
                            $swKey = 'SW-' . $swNombre;
                            // Liberar si alguien más ocupaba ese puerto
                            $pdo->prepare("DELETE FROM infra_switch_puertos WHERE (switch_nombre = ? OR switch_key = ?) AND puerto_numero = ?")
                                ->execute([$swNombre, $swKey, $puertoNum]);

                            $stmtInsSwP = $pdo->prepare("INSERT INTO infra_switch_puertos 
                                (switch_key, switch_nombre, puerto_numero, equipo_key, equipo_nombre, equipo_tipo, equipo_ip, nodo_codigo, estatus, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo', CURRENT_TIMESTAMP)");
                            $stmtInsSwP->execute([$swKey, $swNombre, $puertoNum, $eqKey, $eqNombre, $eqTipo, $eqIp, $nodoCodigo]);
                        }

                        // 3. Sincronizar con infra_nodos
                        if (!empty($nodoCodigo)) {
                            $stmtCheckNd = $pdo->prepare("SELECT id, switch_puerto, patch_panel FROM infra_nodos WHERE codigo_nodo = ? LIMIT 1");
                            $stmtCheckNd->execute([$nodoCodigo]);
                            $ndExistente = $stmtCheckNd->fetch(PDO::FETCH_ASSOC);

                            $swPuertoFormateado = (!empty($swNombre) && $puertoNum > 0) ? ($swNombre . ' / Puerto ' . $puertoNum) : $swPuertoRaw;

                            if ($ndExistente) {
                                $sqlUpNd = "UPDATE infra_nodos SET estatus = 'Activo'";
                                $paramsUpNd = [];
                                if (!empty($swPuertoFormateado)) {
                                    $sqlUpNd .= ", switch_puerto = ?";
                                    $paramsUpNd[] = $swPuertoFormateado;
                                }
                                if (!empty($patchPanel)) {
                                    $sqlUpNd .= ", patch_panel = ?";
                                    $paramsUpNd[] = $patchPanel;
                                }
                                $sqlUpNd .= " WHERE id = ?";
                                $paramsUpNd[] = $ndExistente['id'];
                                $pdo->prepare($sqlUpNd)->execute($paramsUpNd);
                            } else {
                                // Auto-crear nodo en la matriz si no existía previamente
                                $ubicacionNd = trim($fields['ubicacion'] ?? $fields['departamento'] ?? '');
                                $stmtInsNd = $pdo->prepare("INSERT INTO infra_nodos (codigo_nodo, tipo_nodo, ubicacion, patch_panel, switch_puerto, estatus, notas) VALUES (?, 'Voz y Datos', ?, ?, ?, 'Activo', ?)");
                                $stmtInsNd->execute([$nodoCodigo, $ubicacionNd, $patchPanel, $swPuertoFormateado, 'Auto-creado desde Inventario: ' . $eqNombre]);
                            }
                        }
                    } catch (Throwable $tSync) {}
                }

                // Procesar imágenes subidas directamente en el formulario modal
                if ($id > 0) {
                    $uploadDir = 'uploads/equipos/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    if (isset($_FILES['foto_camara_file']) && $_FILES['foto_camara_file']['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['foto_camara_file']['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                            $destFile = $uploadDir . 'foto_camara_' . $tablaActual . '_' . $id . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['foto_camara_file']['tmp_name'], $destFile)) {
                                try { $pdo->exec("ALTER TABLE `$tablaActual` ADD COLUMN `foto_equipo` TEXT"); } catch (Throwable $t) {}
                                $pdo->prepare("UPDATE `$tablaActual` SET `foto_equipo` = ? WHERE id = ?")->execute([$destFile, $id]);
                            }
                        }
                    }

                    if (isset($_FILES['foto_vista_file']) && $_FILES['foto_vista_file']['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['foto_vista_file']['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                            $destFile = $uploadDir . 'foto_vista_' . $tablaActual . '_' . $id . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['foto_vista_file']['tmp_name'], $destFile)) {
                                try { $pdo->exec("ALTER TABLE `$tablaActual` ADD COLUMN `foto_vista_camara` TEXT"); } catch (Throwable $t) {}
                                $pdo->prepare("UPDATE `$tablaActual` SET `foto_vista_camara` = ? WHERE id = ?")->execute([$destFile, $id]);
                            }
                        }
                    }

                    if (isset($_FILES['factura_file']) && $_FILES['factura_file']['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['factura_file']['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'])) {
                            $destFile = $uploadDir . 'factura_' . $tablaActual . '_' . $id . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['factura_file']['tmp_name'], $destFile)) {
                                try { $pdo->exec("ALTER TABLE `$tablaActual` ADD COLUMN `factura_url` TEXT"); } catch (Throwable $t) {}
                                $pdo->prepare("UPDATE `$tablaActual` SET `factura_url` = ? WHERE id = ?")->execute([$destFile, $id]);
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                $error = "Error al procesar el registro: " . $e->getMessage();
            }
        }
    }
    } elseif ($accion === 'eliminar_registro') {
        $id = intval($_POST['registro_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM `$tablaActual` WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = "Registro eliminado correctamente.";
            } catch (PDOException $e) {
                $error = "Error al eliminar registro: " . $e->getMessage();
            }
        }
    } elseif ($accion === 'pasar_baja') {
        $id = intval($_POST['registro_id'] ?? 0);
        $motivo = trim($_POST['motivo_baja'] ?? '');

        if ($id > 0) {
            try {
                // Consultar registro actual
                $stmt = $pdo->prepare("SELECT * FROM `$tablaActual` WHERE id = ?");
                $stmt->execute([$id]);
                $regActual = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($regActual) {
                    $tablaTargetBaja = (str_contains($tablaActual, 'nobreak')) ? 'inv_nobreak_baja' : 'inv_equipos_baja';

                    // Consultar columnas existentes en la tabla destino
                    $colsExistentes = [];
                    try {
                        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                        if ($driver === 'sqlite') {
                            $stmtCols = $pdo->query("PRAGMA table_info(`$tablaTargetBaja`)");
                            if ($stmtCols) {
                                $colsExistentes = array_column($stmtCols->fetchAll(PDO::FETCH_ASSOC), 'name');
                            }
                        } else {
                            $stmtCols = $pdo->query("SHOW COLUMNS FROM `$tablaTargetBaja`");
                            if ($stmtCols) {
                                $colsExistentes = array_column($stmtCols->fetchAll(PDO::FETCH_ASSOC), 'Field');
                            }
                        }
                    } catch (Throwable $tCols) {}

                    $regActual['estado'] = 'Baja';
                    $regActual['motivo_baja'] = $motivo;

                    $insertData = [];
                    foreach ($regActual as $k => $v) {
                        if ($k === 'id' || $k === 'actualizado_en') continue;
                        if (empty($colsExistentes) || in_array($k, $colsExistentes)) {
                            $insertData[$k] = $v;
                        }
                    }
                    if (empty($colsExistentes) || in_array('motivo_baja', $colsExistentes)) {
                        $insertData['motivo_baja'] = $motivo;
                    }

                    if (!empty($insertData)) {
                        $cols = array_keys($insertData);
                        $placeholders = array_fill(0, count($cols), '?');
                        $sqlInsert = "INSERT INTO `$tablaTargetBaja` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                        $stmtIns = $pdo->prepare($sqlInsert);
                        $stmtIns->execute(array_values($insertData));

                        // Eliminar de la tabla activa origen
                        $stmtDel = $pdo->prepare("DELETE FROM `$tablaActual` WHERE id = ?");
                        $stmtDel->execute([$id]);

                        $nombreRef = $regActual['nombre_equipo'] ?? $regActual['usuario'] ?? ('#' . $id);
                        $mensaje = "El equipo <strong>" . htmlspecialchars($nombreRef) . "</strong> ha sido enviado a <strong>Equipos Baja</strong>. Motivo registrado: <em>" . htmlspecialchars($motivo) . "</em>.";
                    }
                }
            } catch (PDOException $e) {
                $error = "Error al trasladar registro a Equipos Baja: " . $e->getMessage();
            }
        }
    } elseif ($accion === 'subir_documento_equipo') {
        $id = intval($_POST['registro_id'] ?? 0);
        $tipo = $_POST['tipo_documento'] ?? 'factura';
        if ($tipo === 'foto' || $tipo === 'foto_equipo') {
            $col = 'foto_equipo';
            $labelTipo = 'Fotografía del equipo / cámara';
        } elseif ($tipo === 'foto_vista' || $tipo === 'foto_vista_camara') {
            $col = 'foto_vista_camara';
            $labelTipo = 'Fotografía de la vista de la cámara';
        } elseif ($tipo === 'responsiva') {
            $col = 'responsiva_url';
            $labelTipo = 'Carta Responsiva';
        } else {
            $col = 'factura_url';
            $labelTipo = 'Factura digital';
        }

        if ($id > 0 && isset($_FILES['documento_file']) && $_FILES['documento_file']['error'] === UPLOAD_ERR_OK) {
            try {
                // Asegurar existencia de la columna si aún no existe
                try {
                    $pdo->exec("ALTER TABLE `$tablaActual` ADD COLUMN `$col` TEXT");
                } catch (Throwable $tAddCol) {}

                $uploadDir = 'uploads/equipos/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $ext = strtolower(pathinfo($_FILES['documento_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx'])) {
                    $destFile = $uploadDir . $tipo . '_' . $tablaActual . '_' . $id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['documento_file']['tmp_name'], $destFile)) {
                        $stmt = $pdo->prepare("UPDATE `$tablaActual` SET `$col` = ? WHERE id = ?");
                        $stmt->execute([$destFile, $id]);
                        $mensaje = "¡<strong>" . $labelTipo . "</strong> cargada con éxito para el equipo #<strong>" . $id . "</strong>!";
                    } else {
                        $error = "No se pudo mover el archivo subido al directorio de destino.";
                    }
                } else {
                    $error = "Formato de archivo no permitido. Extensiones válidas: JPG, PNG, WEBP, PDF, DOCX, XLSX.";
                }
            } catch (PDOException $e) {
                $error = "Error al guardar archivo: " . $e->getMessage();
            }
        } else {
            $error = "Por favor selecciona un archivo o fotografía válida para subir.";
        }
    } elseif ($accion === 'eliminar_documento_equipo') {
        $id = intval($_POST['registro_id'] ?? 0);
        $tipo = $_POST['tipo_documento'] ?? 'factura';
        if ($tipo === 'foto' || $tipo === 'foto_equipo') {
            $col = 'foto_equipo';
            $labelTipo = 'Fotografía del equipo';
        } elseif ($tipo === 'foto_vista' || $tipo === 'foto_vista_camara') {
            $col = 'foto_vista_camara';
            $labelTipo = 'Fotografía de la vista de la cámara';
        } elseif ($tipo === 'responsiva') {
            $col = 'responsiva_url';
            $labelTipo = 'Carta Responsiva';
        } else {
            $col = 'factura_url';
            $labelTipo = 'Factura digital';
        }

        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE `$tablaActual` SET `$col` = NULL WHERE id = ?");
                $stmt->execute([$id]);
                $mensaje = "La <strong>" . $labelTipo . "</strong> fue eliminada correctamente del equipo #" . $id . ".";
            } catch (PDOException $e) {
                $error = "Error al eliminar elemento: " . $e->getMessage();
            }
        }
    }
}

// ----------------------------------------------------
// CONSULTA DE REGISTROS DE LA SECCIÓN ACTIVA
// ----------------------------------------------------
$registros = [];
$equiposPorSeccionMap = [];
$usuarios_sistema = [];
if ($pdo) {
    if ($seccion_activa === 'graficas') {
        foreach ($SECCIONES as $secKey => $secConf) {
            if ($secKey === 'graficas') continue;
            $tblSec = $secConf['tabla'];
            try {
                $stmtSec = $pdo->query("SELECT * FROM `$tblSec` ORDER BY id DESC");
                $rowsSec = $stmtSec ? $stmtSec->fetchAll(PDO::FETCH_ASSOC) : [];
                $equiposPorSeccionMap[$secConf['nombre']] = count($rowsSec);
                foreach ($rowsSec as $rSec) {
                    $rSec['_seccion_nombre'] = $secConf['nombre'];
                    $registros[] = $rSec;
                }
            } catch (Throwable $tSec) {
                $equiposPorSeccionMap[$secConf['nombre']] = 0;
            }
        }
    } else {
        try {
            $stmt = $pdo->query("SELECT * FROM `$tablaActual` ORDER BY id DESC");
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = "Error al consultar la sección " . htmlspecialchars($infoSeccion['nombre']) . ": " . $e->getMessage();
        }
    }

    $dvrsRegistrados = [];
    try {
        $stmtUsers = $pdo->query("SELECT id, nombre, email, area, puesto FROM usuarios WHERE activo = 1 ORDER BY nombre ASC");
        if ($stmtUsers) {
            $usuarios_sistema = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $tUsers) {
        $usuarios_sistema = [];
    }

    try {
        $stmtDvr = $pdo->query("SELECT id, nombre, dvr, tipo_registro FROM inv_dvr_camaras WHERE tipo_registro = 'DVR/NVR' OR tipo_registro IS NULL OR tipo_registro = '' ORDER BY id ASC");
        if ($stmtDvr) {
            $dvrsRegistrados = $stmtDvr->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $tDvr) {
        $dvrsRegistrados = [];
    }

    $switchesDisponiblesSite = [];
    try {
        $stmtSw = $pdo->query("SELECT id, nombre_equipo, modelo, marca, tipo_registro, ip, cantidad_puertos FROM inv_site_vw WHERE LOWER(tipo_registro) LIKE '%switch%' OR LOWER(nombre_equipo) LIKE '%sw%' OR LOWER(modelo) LIKE '%switch%' OR LOWER(modelo) LIKE '%port%' ORDER BY id ASC");
        if ($stmtSw) {
            $switchesDisponiblesSite = $stmtSw->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $tSw) {
        $switchesDisponiblesSite = [];
    }

    $nodosDisponiblesRed = [];
    try {
        $stmtNd = $pdo->query("SELECT n.id, n.codigo_nodo, n.tipo_nodo, n.ubicacion, n.patch_panel, n.switch_puerto, n.vlan, n.estatus,
               COALESCE(NULLIF(t1.extension, ''), NULLIF(t2.extension, ''), '') AS telefono_extension
        FROM infra_nodos n
        LEFT JOIN inv_telefonos_poe t1 ON (n.telefono_poe_id = t1.id AND n.tiene_telefono_poe = 1)
        LEFT JOIN inv_telefonos_poe t2 ON (n.codigo_nodo = t2.numero_nodo AND t2.numero_nodo != '')
        GROUP BY n.id
        ORDER BY CAST(n.codigo_nodo AS UNSIGNED) ASC, n.codigo_nodo ASC");
        if ($stmtNd) {
            $nodosDisponiblesRed = $stmtNd->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $tNd) {
        $nodosDisponiblesRed = [];
    }

    $puertosSwitchMapa = [];
    try {
        $stmtPsw = $pdo->query("SELECT switch_key, switch_nombre, puerto_numero, nodo_codigo, equipo_key, equipo_nombre FROM infra_switch_puertos");
        if ($stmtPsw) {
            $puertosSwitchMapa = $stmtPsw->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $tPsw) {
        $puertosSwitchMapa = [];
    }

    // Enriquecer bidireccionalmente el mapeo entre Nodos y Puertos de Switch
    foreach ($nodosDisponiblesRed as &$ndRef) {
        $cCode = trim($ndRef['codigo_nodo'] ?? '');
        $ndRef['switch_nombre_asoc'] = '';
        $ndRef['puerto_num_asoc'] = 0;

        // 1. Verificar si está registrado en infra_switch_puertos
        if ($cCode !== '') {
            foreach ($puertosSwitchMapa as $psm) {
                if (trim($psm['nodo_codigo'] ?? '') === $cCode) {
                    $swNomPsm = trim($psm['switch_nombre'] ?? '');
                    $pNumPsm = intval($psm['puerto_numero'] ?? 0);
                    if ($swNomPsm !== '' && $pNumPsm > 0) {
                        $ndRef['switch_nombre_asoc'] = $swNomPsm;
                        $ndRef['puerto_num_asoc'] = $pNumPsm;
                        if (empty($ndRef['switch_puerto'])) {
                            $ndRef['switch_puerto'] = $swNomPsm . ' / Puerto ' . $pNumPsm;
                        }
                    }
                    break;
                }
            }
        }

        // 2. Si no estaba en infra_switch_puertos pero tiene switch_puerto en infra_nodos
        if (empty($ndRef['switch_nombre_asoc']) && !empty($ndRef['switch_puerto'])) {
            $swP = trim($ndRef['switch_puerto']);
            if (strpos($swP, '/') !== false) {
                $p = explode('/', $swP);
                $ndRef['switch_nombre_asoc'] = trim($p[0]);
                preg_match('/(\d+)/', $p[1], $m);
                if (!empty($m[1])) {
                    $ndRef['puerto_num_asoc'] = intval($m[1]);
                }
            } elseif (strpos($swP, '-') !== false) {
                $p = explode('-', $swP);
                $ndRef['switch_nombre_asoc'] = trim($p[0]);
                preg_match('/(\d+)/', $p[1], $m);
                if (!empty($m[1])) {
                    $ndRef['puerto_num_asoc'] = intval($m[1]);
                }
            } else {
                $ndRef['switch_nombre_asoc'] = $swP;
                preg_match('/(\d+)/', $swP, $m);
                if (!empty($m[1])) {
                    $ndRef['puerto_num_asoc'] = intval($m[1]);
                }
            }
        }
    }
    unset($ndRef);

    // Asegurar que $puertosSwitchMapa conozca todos los nodos vinculados
    foreach ($nodosDisponiblesRed as $ndRef) {
        if (!empty($ndRef['switch_nombre_asoc']) && !empty($ndRef['puerto_num_asoc']) && !empty($ndRef['codigo_nodo'])) {
            $swAsocLower = mb_strtolower(trim($ndRef['switch_nombre_asoc']));
            $pAsocNum = intval($ndRef['puerto_num_asoc']);
            $encontrado = false;
            foreach ($puertosSwitchMapa as &$psm) {
                if (mb_strtolower(trim($psm['switch_nombre'])) === $swAsocLower && intval($psm['puerto_numero']) === $pAsocNum) {
                    if (empty($psm['nodo_codigo'])) {
                        $psm['nodo_codigo'] = $ndRef['codigo_nodo'];
                    }
                    $encontrado = true;
                    break;
                }
            }
            unset($psm);
            if (!$encontrado) {
                $puertosSwitchMapa[] = [
                    'switch_key' => '',
                    'switch_nombre' => $ndRef['switch_nombre_asoc'],
                    'puerto_numero' => $pAsocNum,
                    'nodo_codigo' => $ndRef['codigo_nodo'],
                    'equipo_key' => '',
                    'equipo_nombre' => ''
                ];
            }
        }
    }
}

// ----------------------------------------------------
// AGRUPACIÓN DE REGISTROS POR ÁREAS DE LA AGENCIA
// ----------------------------------------------------
$agenciaSesion = $_SESSION['agencia'] ?? 'Agencia Central';
$areasDisponibles = [];
if ($pdo) {
    try {
        $stmtAg = $pdo->prepare("SELECT id FROM agencias WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
        $stmtAg->execute([$agenciaSesion]);
        $agId = $stmtAg->fetchColumn();

        if ($agId) {
            $stmtAr = $pdo->prepare("SELECT nombre FROM agencia_areas WHERE agencia_id = ? AND estatus = 1 ORDER BY nombre ASC");
            $stmtAr->execute([$agId]);
            $areasDisponibles = $stmtAr->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Throwable $tAr) {}
}

if (empty($areasDisponibles)) {
    $areasDisponibles = ['Administración', 'Servicio', 'Refacciones', 'Ventas', 'HyP', 'CRM', 'Sistemas'];
}

if (!in_array('Sistemas', $areasDisponibles)) {
    $areasDisponibles[] = 'Sistemas';
}

$equiposPorAreaMap = [];
foreach ($areasDisponibles as $aName) {
    $equiposPorAreaMap[$aName] = [];
}

foreach ($registros as $reg) {
    if ($seccion_activa === 'dvr' || $seccion_activa === 'site_vw') {
        $deptVal = 'Sistemas';
    } else {
        $deptVal = trim($reg['departamento'] ?? $reg['area'] ?? '');
        if (empty($deptVal) || $deptVal === 'N/A' || is_numeric($deptVal)) {
            $deptVal = 'Sin Área / General';
        }
    }

    $matched = false;
    foreach ($areasDisponibles as $aName) {
        if (mb_strtolower($deptVal) === mb_strtolower($aName)) {
            $equiposPorAreaMap[$aName][] = $reg;
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        $equiposPorAreaMap[$deptVal][] = $reg;
    }
}
// ----------------------------------------------------
// CÁLCULOS ESTADÍSTICOS Y MÉTRICAS PARA DASHBOARD / GRÁFICAS
// ----------------------------------------------------
$totalEquiposSec = count($registros);
$inversionTotalSec = 0;
$proximosRenovarCount = 0;
$requierenRenovarCount = 0;
$vigentesCount = 0;
$sinFechaRenovacionCount = 0;

$costoPorAreaMap = [];
$cantPorAreaMap = [];
$tiposEquipoMap = [];
$ramDistribucionMap = [];

$fechaHoyTime = strtotime('today');
$fecha60DiasTime = strtotime('+60 days', $fechaHoyTime);

foreach ($equiposPorAreaMap as $aKey => $aItems) {
    $costoPorAreaMap[$aKey] = 0;
    $cantPorAreaMap[$aKey] = count($aItems);
}

foreach ($registros as $reg) {
    // 1. Suma de Costo Monetario
    $costoRaw = $reg['costo'] ?? $reg['costo_equipo'] ?? '';
    if (!empty($costoRaw)) {
        $numClean = floatval(preg_replace('/[^0-9.]/', '', $costoRaw));
        if ($numClean > 0) {
            $inversionTotalSec += $numClean;
            if ($seccion_activa === 'dvr' || $seccion_activa === 'site_vw') {
                $deptVal = 'Sistemas';
            } else {
                $deptVal = trim($reg['departamento'] ?? $reg['area'] ?? '');
                if (empty($deptVal) || $deptVal === 'N/A' || is_numeric($deptVal)) $deptVal = 'Sin Área / General';
            }
            foreach ($areasDisponibles as $aName) {
                if (mb_strtolower($deptVal) === mb_strtolower($aName)) {
                    $deptVal = $aName;
                    break;
                }
            }
            if (!isset($costoPorAreaMap[$deptVal])) $costoPorAreaMap[$deptVal] = 0;
            $costoPorAreaMap[$deptVal] += $numClean;
        }
    }

    // 2. Análisis de Fechas de Renovación / Garantía
    $fechaRenovStr = trim($reg['renovacion_equipo'] ?? $reg['fin_garantia'] ?? '');
    if (!empty($fechaRenovStr) && $fechaRenovStr !== 'N/A' && $fechaRenovStr !== '0000-00-00') {
        $timeRenov = strtotime($fechaRenovStr);
        if ($timeRenov) {
            if ($timeRenov < $fechaHoyTime) {
                $requierenRenovarCount++;
            } elseif ($timeRenov <= $fecha60DiasTime) {
                $proximosRenovarCount++;
            } else {
                $vigentesCount++;
            }
        } else {
            $sinFechaRenovacionCount++;
        }
    } else {
        $sinFechaRenovacionCount++;
    }

    // 3. Distribución por Tipo de Equipo
    $tipo = trim($reg['tipo_equipo'] ?? $reg['modelo'] ?? $reg['dvr'] ?? $reg['monitor'] ?? $reg['tablet'] ?? 'Equipo de Cómputo');
    if (empty($tipo)) $tipo = 'Otros / Cómputo';
    if (!isset($tiposEquipoMap[$tipo])) $tiposEquipoMap[$tipo] = 0;
    $tiposEquipoMap[$tipo]++;

    // 4. Distribución por Memoria RAM
    $ramStr = trim($reg['ram'] ?? '');
    if (!empty($ramStr) && $ramStr !== 'N/A') {
        $ramNum = preg_replace('/[^0-9]/', '', $ramStr);
        $labelRam = !empty($ramNum) ? ($ramNum . ' GB') : $ramStr;
        if (!isset($ramDistribucionMap[$labelRam])) $ramDistribucionMap[$labelRam] = 0;
        $ramDistribucionMap[$labelRam]++;
    }
}

// JSON para pasarlo a Chart.js en JS
$jsonSeccionesLabels = json_encode(array_keys($equiposPorSeccionMap));
$jsonSeccionesCounts = json_encode(array_values($equiposPorSeccionMap));

$jsonAreasLabels = json_encode(array_keys($cantPorAreaMap));
$jsonAreasCounts = json_encode(array_values($cantPorAreaMap));
$jsonAreasCosts  = json_encode(array_values($costoPorAreaMap));

$jsonEstatusRenovLabels = json_encode(['Vigentes (En Tiempo)', 'Próximos a Renovar (<60 días)', 'Requieren Renovación (Vencidos)', 'Sin Fecha Definida']);
$jsonEstatusRenovCounts = json_encode([$vigentesCount, $proximosRenovarCount, $requierenRenovarCount, $sinFechaRenovacionCount]);

$jsonTiposLabels = json_encode(array_keys($tiposEquipoMap));
$jsonTiposCounts = json_encode(array_values($tiposEquipoMap));

$jsonRamLabels = json_encode(array_keys($ramDistribucionMap));
$jsonRamCounts = json_encode(array_values($ramDistribucionMap));

// ----------------------------------------------------
// FUNCIONES AUXILIARES DE CATEGORIZACIÓN Y REGLAS DE CAMPO
// ----------------------------------------------------
function obtenerCategoriaCampo($col, $seccion = '') {
    $c = strtolower($col);
    if (in_array($c, ['tipo_registro', 'subtipo_dispositivo', 'fabricante', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo', 'expediente_completo', 'area', 'nombre', 'usuario_equipo', 'usuario_equipo_dominio', 'funcion_servicio', 'ambiente', 'criticidad', 'tipo_servidor', 'ubicacion', 'fecha_asignacion', 'estado_fisico', 'estatus', 'observaciones'])) {
        return ['id' => 'cat_general', 'titulo' => '👤 Datos de Asignación y Dispositivo', 'icono' => 'bi-person-badge-fill'];
    }
    if (in_array($c, ['marca', 'modelo', 'modelo_exacto', 'serie', 'imei_1', 'imei_2', 'almacenamiento', 'ram', 'color', 'sistema_op', 'mac', 'tamano_pantalla', 'resolucion', 'especificaciones', 'accesorios', 'dominio', 'dd', 'procesador', 'ghz', 'mac_wifi', 'mac_ethernet', 'rom', 'pulgadas', 'monitor', 'dvr', 'camaras', 'numero_serie', 'cam_totales_ip', 'disponibles_ip', 'cam_totales_analogicas', 'disponibles', 'capacidad_actual', 'capacidad_discos', 'numero_discos', 'tiene_actual', 'ip', 'tipo_poe', 'cantidad_puertos', 'velocidad_puertos', 'tipo_switch', 'firmware_version', 'posicion_rack', 'cantidad_discos', 'bahias_nas', 'capacidad_disco_ind', 'capacidad_disponible', 'config_raid', 'tipo_discos', 'protocolos_nas', 'unifi_os_ver', 'controller_ver', 'velocidad_enlace', 'ssids', 'vlans', 'poe', 'controlador_ap', 'capacidad_va', 'capacidad_w', 'tipo_ups', 'voltaje_entrada', 'voltaje_salida', 'cant_baterias', 'specs_baterias', 'fecha_bateria', 'autonomia'])) {
        return ['id' => 'cat_specs', 'titulo' => '💻 Especificaciones Técnicas y Hardware', 'icono' => 'bi-cpu-fill'];
    }
    if (in_array($c, ['logmein', 'office', 'serie_office', 'clave_candado', 'gds', 'remoto', 'usuario_gds', 'contrasena_remoto', 'contrasena_gds', 'usuario_dvr', 'contrasena_dvr', 'contrasena_camara', 'licencia', 'serie_licencia', 'serie_equipo', 'contrasena', 'usuario', 'usuario_impresora', 'contrasena_impresora', 'usuario_impresora_web', 'contrasena_impresora_web', 'contrasena_web', 'tipo_licencia'])) {
        return ['id' => 'cat_credenciales', 'titulo' => '🔑 Credenciales y Accesos', 'icono' => 'bi-key-fill'];
    }
    if (in_array($c, ['numero_telefonico', 'portabilidad', 'tiene_plan_celular', 'vencimiento_plan', 'proveedor_plan', 'numero_contrato_plan', 'correo', 'correo_oficial_planta', 'extension', 'servidor_sip', 'vlan', 'switch_nombre', 'puerto_patch_panel', 'puerto_sw', 'numero_nodo', 'power_pb', 'contrasena_pb', 'poc', 'contrasena_poc', 'msqp', 'contrasena_msqp', 'grp', 'contrasena_grp', 'etka', 'contrasena_etka', 'mail', 'celular', 'pantalla', 'tablet', 'proveedor', 'contrato', 'tipo_enlace', 'ancho_banda', 'simetria_enlace', 'tipo_conexion', 'numero_contrato', 'numero_cuenta', 'circuit_id', 'soporte_contacto', 'ip_publica', 'ip_local'])) {
        return ['id' => 'cat_telecom', 'titulo' => '📱 Línea, Plan e ISP', 'icono' => 'bi-diagram-2-fill'];
    }

    $tituloGarantia = ($seccion === 'dvr' || in_array($c, ['dias_grabacion'])) ? '💾 Respaldo' : '🧾 Garantía y Facturación';
    return ['id' => 'cat_garantia', 'titulo' => $tituloGarantia, 'icono' => 'bi-receipt'];
}

function obtenerEtiquetaCampo($col) {
    $c = strtolower($col);
    if ($c === 'ip') return 'Dirección IP';
    if ($c === 'extension') return 'Extensión';
    if ($c === 'numero_telefonico') return 'Número Telefónico';
    if ($c === 'tipo_licencia') return 'Tipo de Licencia';
    if ($c === 'portabilidad') return 'Portabilidad';
    if ($c === 'tipo_poe') return 'Estándar PoE (Alimentación)';
    if ($c === 'servidor_sip') return 'Servidor SIP / PBX Conmutador';
    if ($c === 'contrasena_web') return 'Contraseña Admin Web Teléfono';
    if ($c === 'vlan') return 'VLAN de Voz';
    if ($c === 'departamento' || $c === 'area') return 'Área';
    if ($c === 'ubicacion') return 'Ubicación Física';
    if ($c === 'modelo_exacto') return 'Modelo Exacto';
    if ($c === 'fecha_adquisicion') return 'Fecha de Adquisición';
    if ($c === 'contrato') return 'Contrato';
    if ($c === 'usuario_impresora') return 'Usuario Impresora';
    if ($c === 'contrasena_impresora') return 'Contraseña Impresora';
    if ($c === 'usuario_impresora_web') return 'Usuario Impresora Web';
    if ($c === 'contrasena_impresora_web') return 'Contraseña Impresora Web';
    if ($c === 'dd') return 'Disco Duro';
    if ($c === 'serie') return 'Número de Serie (S/N)';
    if ($c === 'costo' || $c === 'costo_equipo') return 'Costo del Equipo';
    if ($c === 'contrasena') return 'Contraseña Equipo';
    if ($c === 'contrasena_remoto') return 'Contraseña Remoto';
    if ($c === 'contrasena_gds') return 'Contraseña GDS';
    if ($c === 'correo_oficial_planta') return 'Correo Oficial Planta';
    if ($c === 'compra') return 'Tipo de Compra';
    if ($c === 'motivo_baja') return 'Motivo de la Baja';
    if ($c === 'dvr') return 'DVR/NVR';
    if ($c === 'tipo_registro') return 'Tipo de Registro';
    if ($c === 'subtipo_camara') return 'Tecnología / Tipo de Cámara';
    if ($c === 'dvr_vinculado') return 'DVR/NVR al que Pertenece';
    if ($c === 'canal_analogico') return 'Canal Análogo';
    if ($c === 'canal_ip') return 'Canal IP';
    if ($c === 'ubicacion') return 'Ubicación / Instalación';
    if ($c === 'foto_vista_camara') return 'Foto Vista de la Cámara';
    if ($c === 'contrasena_camara') return 'Contraseña de la Cámara';
    if ($c === 'numero_discos' || $c === 'tiene_actual') return 'Número de Discos';
    if ($c === 'dias_grabacion') return 'Días de Grabación';
    if ($c === 'usuario_dvr') return 'Usuario DVR/NVR';
    if ($c === 'puerto_patch_panel') return 'Puerto Patch Panel';
    if ($c === 'puerto_sw') return 'SW Puerto';
    if ($c === 'numero_nodo') return 'Nodo';
    if ($c === 'folio_factura') return 'Folio Factura';
    if ($c === 'fabricante') return 'Fabricante';
    if ($c === 'mac_ethernet') return 'Dirección MAC Ethernet';
    if ($c === 'mac_wifi') return 'Dirección MAC Wi-Fi';
    if ($c === 'cantidad_puertos') return 'Cantidad de Puertos';
    if ($c === 'velocidad_puertos') return 'Velocidad de Puertos';
    if ($c === 'tipo_switch') return 'Tipo de Switch';
    if ($c === 'firmware_version') return 'Versión Firmware / OS';
    if ($c === 'posicion_rack') return 'Rack / Posición U';
    if ($c === 'sistema_op') return 'Sistema Operativo / Firmware';
    if ($c === 'procesador') return 'Procesador / CPU';
    if ($c === 'ram') return 'Memoria RAM';
    if ($c === 'almacenamiento') return 'Almacenamiento Total';
    if ($c === 'cantidad_discos') return 'Cantidad / Tipo de Discos';
    if ($c === 'tipo_servidor') return 'Físico / Virtual';
    if ($c === 'funcion_servicio') return 'Función / Servicio';
    if ($c === 'ambiente') return 'Ambiente';
    if ($c === 'criticidad') return 'Criticidad';
    if ($c === 'bahias_nas') return 'Cantidad de Bahías';
    if ($c === 'capacidad_disco_ind') return 'Capacidad por Disco';
    if ($c === 'capacidad_disponible') return 'Capacidad Disponible';
    if ($c === 'config_raid') return 'Configuración RAID';
    if ($c === 'tipo_discos') return 'Tipo de Discos (HDD/SSD)';
    if ($c === 'protocolos_nas') return 'Protocolos / Servicios Habilitados';
    if ($c === 'proveedor') return 'Proveedor';
    if ($c === 'tipo_enlace') return 'Tipo de Enlace';
    if ($c === 'ancho_banda') return 'Ancho de Banda Contratado';
    if ($c === 'simetria_enlace') return 'Simetría de Enlace';
    if ($c === 'tipo_conexion') return 'Tipo de Conexión';
    if ($c === 'numero_contrato') return 'Número de Contrato';
    if ($c === 'numero_cuenta') return 'Número de Cuenta / Cliente';
    if ($c === 'circuit_id') return 'Circuit ID / ID Enlace';
    if ($c === 'soporte_contacto') return 'Teléfono / Correo Soporte';
    if ($c === 'ip_publica') return 'WAN / IP Pública';
    if ($c === 'ip_local') return 'IP Local / Admin';
    if ($c === 'unifi_os_ver') return 'Versión UniFi OS';
    if ($c === 'controller_ver') return 'Versión Controlador UniFi';
    if ($c === 'velocidad_enlace') return 'Velocidad del Enlace';
    if ($c === 'ssids') return 'SSID(s)';
    if ($c === 'vlans') return 'VLANs';
    if ($c === 'poe') return 'PoE (Sí / No)';
    if ($c === 'controlador_ap') return 'Controlador AP';
    if ($c === 'capacidad_va') return 'Capacidad (VA / kVA)';
    if ($c === 'capacidad_w') return 'Capacidad (W)';
    if ($c === 'tipo_ups') return 'Tipo de UPS';
    if ($c === 'voltaje_entrada') return 'Voltaje Entrada';
    if ($c === 'voltaje_salida') return 'Voltaje Salida';
    if ($c === 'cant_baterias') return 'Cantidad Baterías';
    if ($c === 'specs_baterias') return 'Capacidad / Voltaje Baterías';
    if ($c === 'fecha_bateria') return 'Fecha Cambio Batería';
    if ($c === 'autonomia') return 'Autonomía Estimada';
    if ($c === 'marca') return 'Marca';
    if ($c === 'imei_1') return 'IMEI 1';
    if ($c === 'imei_2') return 'IMEI 2';
    if ($c === 'numero_telefonico') return 'Número Telefónico / SIM';
    if ($c === 'color') return 'Color';
    if ($c === 'accesorios') return 'Accesorios Entregados';
    if ($c === 'fecha_asignacion') return 'Fecha de Asignación';
    if ($c === 'tiene_plan_celular') return '¿Tiene Plan Celular?';
    if ($c === 'vencimiento_plan') return 'Fecha Vencimiento Plan';
    if ($c === 'proveedor_plan') return 'Proveedor del Plan';
    if ($c === 'numero_contrato_plan') return 'Contrato / Línea Plan';
    if ($c === 'estado_fisico') return 'Estado Físico';
    if ($c === 'tamano_pantalla') return 'Tamaño en Pulgadas';
    if ($c === 'resolucion') return 'Resolución';
    if ($c === 'subtipo_dispositivo') return 'Tipo de Dispositivo';
    if ($c === 'especificaciones') return 'Especificaciones Principales';
    if ($c === 'observaciones') return 'Observaciones';
    return ucwords(str_replace('_', ' ', $col));
}

function esCampoNumerico($col) {
    return in_array(strtolower($col), ['ram', 'ghz', 'pulgadas', 'extension', 'cam_totales_ip', 'disponibles_ip', 'cam_totales_analogicas', 'disponibles', 'numero_discos', 'tiene_actual', 'dias_grabacion']);
}

function esCampoFecha($col) {
    return in_array(strtolower($col), ['fecha_compra', 'fecha_adquisicion', 'inicio_garantia', 'fin_garantia', 'renovacion_equipo', 'fecha_asignacion', 'vencimiento_plan', 'fecha_bateria']);
}

function obtenerUnidadCampo($col) {
    $c = strtolower($col);
    if ($c === 'ram' || $c === 'dd') return 'GB';
    if ($c === 'ghz') return 'GHz';
    if ($c === 'pulgadas') return '"';
    if ($c === 'almacenamiento') return 'TB';
    return '';
}

function esCampoPassword($col) {
    $c = strtolower($col);
    return str_contains($c, 'contrasena') || str_contains($c, 'password') || str_contains($c, 'clave');
}

function renderBadgeTipoSite($tipo) {
    $tipoClean = trim($tipo ?? '');
    $tLower = strtolower($tipoClean);

    if (strpos($tLower, 'switch') !== false) {
        $bg = 'rgba(6, 182, 212, 0.18)';
        $color = '#22d3ee';
        $border = 'rgba(34, 211, 238, 0.35)';
        $icon = 'bi-diagram-3-fill';
    } elseif (strpos($tLower, 'servidor') !== false || strpos($tLower, 'server') !== false) {
        $bg = 'rgba(168, 85, 247, 0.18)';
        $color = '#c084fc';
        $border = 'rgba(192, 132, 252, 0.35)';
        $icon = 'bi-server';
    } elseif (strpos($tLower, 'fortinet') !== false || strpos($tLower, 'firewall') !== false) {
        $bg = 'rgba(249, 115, 22, 0.18)';
        $color = '#fb923c';
        $border = 'rgba(251, 146, 60, 0.35)';
        $icon = 'bi-shield-lock-fill';
    } elseif (strpos($tLower, 'nas') !== false) {
        $bg = 'rgba(16, 185, 129, 0.18)';
        $color = '#34d399';
        $border = 'rgba(52, 211, 153, 0.35)';
        $icon = 'bi-hdd-stack-fill';
    } elseif (strpos($tLower, 'router') !== false || strpos($tLower, 'isp') !== false || strpos($tLower, 'módem') !== false || strpos($tLower, 'modem') !== false) {
        $bg = 'rgba(59, 130, 246, 0.18)';
        $color = '#60a5fa';
        $border = 'rgba(96, 165, 250, 0.35)';
        $icon = 'bi-router-fill';
    } elseif (strpos($tLower, 'unifi') !== false || strpos($tLower, 'gateway') !== false) {
        $bg = 'rgba(99, 102, 241, 0.18)';
        $color = '#818cf8';
        $border = 'rgba(129, 140, 248, 0.35)';
        $icon = 'bi-hdd-network-fill';
    } elseif (strpos($tLower, 'access point') !== false || strpos($tLower, 'ap') !== false) {
        $bg = 'rgba(14, 165, 233, 0.18)';
        $color = '#38bdf8';
        $border = 'rgba(56, 189, 248, 0.35)';
        $icon = 'bi-wifi';
    } elseif (strpos($tLower, 'ups') !== false || strpos($tLower, 'pdu') !== false || strpos($tLower, 'nobreak') !== false) {
        $bg = 'rgba(234, 179, 8, 0.18)';
        $color = '#fde047';
        $border = 'rgba(253, 224, 71, 0.35)';
        $icon = 'bi-lightning-charge-fill';
    } elseif (strpos($tLower, 'patch') !== false) {
        $bg = 'rgba(20, 184, 166, 0.18)';
        $color = '#2dd4bf';
        $border = 'rgba(45, 212, 191, 0.35)';
        $icon = 'bi-ethernet';
    } elseif (strpos($tLower, 'kvm') !== false) {
        $bg = 'rgba(236, 72, 153, 0.18)';
        $color = '#f472b6';
        $border = 'rgba(244, 114, 182, 0.35)';
        $icon = 'bi-layers-fill';
    } else {
        $bg = 'rgba(148, 163, 184, 0.18)';
        $color = '#cbd5e1';
        $border = 'rgba(203, 213, 225, 0.35)';
        $icon = 'bi-hdd-rack-fill';
    }

    $label = !empty($tipoClean) ? htmlspecialchars($tipoClean) : 'Switch';

    return sprintf(
        '<span class="badge px-2.5 py-1 small fw-bold rounded-pill" style="background: %s; color: %s; border: 1px solid %s;"><i class="bi %s me-1"></i> %s</span>',
        $bg, $color, $border, $icon, $label
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($infoSeccion['nombre']); ?> - Inventario de Equipos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #040d1a;
            background-image: 
                radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(15, 23, 42, 0.8) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(30, 58, 138, 0.08) 0px, transparent 60%);
            background-attachment: fixed;
            color: #f8fafc;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }

        /* Top Navbar Glass Effect */
        .top-navbar {
            background: rgba(10, 25, 46, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        /* Sidebar Styling */
        .sidebar-wrapper {
            background: rgba(10, 25, 46, 0.7);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            position: sticky;
            top: 65px;
            height: calc(100vh - 65px);
            overflow-y: auto;
            padding: 24px 14px;
            z-index: 100;
        }

        .sidebar-wrapper::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-wrapper::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        .sidebar-section-title {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0 12px;
            margin-bottom: 14px;
        }

        .nav-link-custom {
            color: #94a3b8;
            border-radius: 12px;
            padding: 11px 14px;
            margin-bottom: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
        }

        .nav-link-custom .nav-icon-box {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
            color: #94a3b8;
        }

        .nav-link-custom:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #ffffff;
            transform: translateX(4px);
            border-color: rgba(255, 255, 255, 0.06);
        }

        .nav-link-custom:hover .nav-icon-box {
            background: rgba(37, 99, 235, 0.2);
            color: #60a5fa;
        }

        .nav-link-custom.active {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(147, 197, 253, 0.3);
        }

        .nav-link-custom.active .nav-icon-box {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        .content-wrapper {
            padding: 30px;
        }

        /* Glassmorphism Cards */
        .card-custom {
            background: linear-gradient(145deg, rgba(13, 29, 53, 0.75), rgba(8, 19, 36, 0.85));
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        /* Segmented View Switcher Control */
        .segmented-control {
            background: rgba(4, 13, 26, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 4px;
            display: inline-flex;
            gap: 4px;
        }

        .segmented-control .btn-seg {
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .segmented-control .btn-seg.active {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            box-shadow: 0 2px 10px rgba(37, 99, 235, 0.4);
        }

        /* Area Group Card Styling */
        .area-group-card {
            background: linear-gradient(145deg, rgba(13, 29, 53, 0.85), rgba(8, 19, 36, 0.92));
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-left: 4px solid #3b82f6;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
            transition: all 0.3s ease;
        }

        .area-icon-wrapper {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.25), rgba(29, 78, 216, 0.1));
            border: 1px solid rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .area-count-badge {
            background: rgba(37, 99, 235, 0.12);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .empty-area-box {
            border: 1.5px dashed rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 28px 20px;
            background: rgba(255, 255, 255, 0.015);
            text-align: center;
            transition: all 0.2s ease;
        }

        .empty-area-box:hover {
            border-color: rgba(59, 130, 246, 0.35);
            background: rgba(37, 99, 235, 0.03);
        }

        /* Equipment Grid Cards inside Area */
        .equipo-card-item {
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .equipo-card-inner {
            background: linear-gradient(145deg, #0d213a 0%, #071527 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 18px;
            height: 100%;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .equipo-card-inner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.6), transparent);
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .equipo-card-inner:hover {
            transform: translateY(-4px);
            border-color: rgba(59, 130, 246, 0.4);
            box-shadow: 0 12px 30px -5px rgba(0, 0, 0, 0.5), 0 0 15px rgba(37, 99, 235, 0.15);
        }

        .equipo-card-inner:hover::before {
            opacity: 1;
        }

        /* Tabla Customizada Dark */
        .table-custom {
            --bs-table-bg: transparent !important;
            --bs-table-color: #e2e8f0 !important;
            --bs-table-hover-bg: rgba(30, 58, 138, 0.25) !important;
            --bs-table-hover-color: #ffffff !important;
            color: #e2e8f0 !important;
            font-size: 0.88rem;
            border-color: rgba(255, 255, 255, 0.08) !important;
            margin-bottom: 0;
        }
        .table-custom th {
            background: rgba(15, 34, 61, 0.9) !important;
            color: #94a3b8 !important;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12) !important;
            padding: 14px 16px;
        }
        .table-custom td {
            background-color: transparent !important;
            color: #e2e8f0 !important;
            vertical-align: middle;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
            padding: 14px 16px;
        }
        .table-custom tbody tr {
            transition: background-color 0.15s ease;
        }
        .table-custom tbody tr:hover td {
            background-color: rgba(30, 58, 138, 0.25) !important;
            color: #ffffff !important;
        }
        /* Modales y Formularios */
        .modal-content {
            background: linear-gradient(145deg, #0a192e 0%, #061224 100%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
        }
        .modal-header, .modal-footer {
            border-color: rgba(255, 255, 255, 0.08);
        }
        .form-control, .form-select {
            background-color: #0f223d !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            color: #ffffff !important;
            font-size: 0.9rem;
            border-radius: 10px;
        }
        .form-control::placeholder {
            color: #94a3b8 !important;
            opacity: 1;
        }
        .form-control:focus, .form-select:focus {
            background-color: #132a4b !important;
            color: #ffffff !important;
            border-color: #2563eb !important;
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.25) !important;
        }
        /* Badges & Credenciales */
        .badge-user {
            background-color: rgba(30, 41, 59, 0.8);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.25);
            font-size: 0.85rem;
            padding: 5px 12px;
            border-radius: 8px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
        }
        .pass-cell {
            font-family: monospace;
            background: rgba(15, 34, 61, 0.9);
            padding: 5px 10px;
            border-radius: 6px;
            color: #60a5fa;
            border: 1px solid rgba(96, 165, 250, 0.25);
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
        }
        /* Botones de Acción Especiales */
        .btn-action-expediente {
            background: rgba(37, 99, 235, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(96, 165, 250, 0.3);
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.82rem;
            padding: 6px 12px;
            transition: all 0.2s;
        }
        .btn-action-expediente:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
        .btn-action-edit {
            background: rgba(234, 179, 8, 0.15);
            color: #facc15;
            border: 1px solid rgba(250, 204, 21, 0.3);
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.82rem;
            padding: 6px 10px;
            transition: all 0.2s;
        }
        .btn-action-edit:hover {
            background: #eab308;
            color: #000000;
            border-color: #eab308;
        }
        .btn-action-delete {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(248, 113, 113, 0.3);
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.82rem;
            padding: 6px 10px;
            transition: all 0.2s;
        }
        .btn-action-delete:hover {
            background: #ef4444;
            color: #ffffff;
            border-color: #ef4444;
        }
        .btn-action-baja {
            background: rgba(249, 115, 22, 0.15);
            color: #fb923c;
            border: 1px solid rgba(251, 146, 60, 0.3);
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.82rem;
            padding: 6px 12px;
            transition: all 0.2s;
        }
        .btn-action-baja:hover {
            background: #ea580c;
            color: #ffffff;
            border-color: #ea580c;
        }
        /* Nav Pills Seccionadas en Modales */
        .nav-pills-custom .nav-link {
            color: #94a3b8;
            background: #0f223d;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 8px 14px;
            transition: all 0.2s;
        }
        .nav-pills-custom .nav-link:hover {
            color: #ffffff;
            background: rgba(37, 99, 235, 0.2);
        }
        .nav-pills-custom .nav-link.active {
            color: #ffffff;
            background: #2563eb;
            border-color: #2563eb;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
        }
    </style>
</head>
<body>

<!-- Pestaña Flotante para Mostrar Sidebar Oculto -->
<button type="button" id="btnShowSidebarTab" class="btn btn-primary btn-sm rounded-end-3 shadow-lg" onclick="toggleSidebarNavegacion()" style="display: none; position: fixed; left: 0; top: 78px; z-index: 1050; padding: 8px 10px; border-left: none;" title="Mostrar Menú Lateral (Inventario)">
    <i class="bi bi-layout-sidebar-reverse fs-5 text-white"></i>
</button>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Menú Principal
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm text-white rounded-3 me-1" onclick="toggleSidebarNavegacion()" title="Ocultar / Mostrar Menú Lateral">
            <i class="bi bi-layout-sidebar-inset me-1"></i> <span class="d-none d-md-inline">Menú</span>
        </button>
        <span class="fw-bold fs-5">PORTAL DE SISTEMAS <span class="text-primary">| Inventario de Equipos</span></span>
    </div>
    <div>
        <span class="badge bg-primary p-2 fs-6"><i class="bi bi-display me-1"></i> Control de Hardware</span>
    </div>
</div>

<div class="container-fluid p-0">
    <div class="row g-0">

        <!-- BARRA LATERAL IZQUIERDA (SIDEBAR) -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper" id="mainSidebarWrapper">
            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <div class="sidebar-section-title mb-0 p-0">Secciones de Inventario</div>
                <button type="button" class="btn btn-sm text-secondary p-0 border-0" onclick="toggleSidebarNavegacion()" title="Ocultar Menú Lateral">
                    <i class="bi bi-chevron-left fs-6 text-white" id="iconToggleSidebar"></i>
                </button>
            </div>
            <nav class="nav flex-column">
                <?php foreach ($SECCIONES as $key => $sec): ?>
                    <a href="equipos.php?sec=<?php echo $key; ?>" class="nav-link-custom <?php echo ($seccion_activa === $key) ? 'active' : ''; ?>">
                        <span class="nav-icon-box"><i class="bi <?php echo $sec['icono']; ?>"></i></span>
                        <span class="text-truncate"><?php echo htmlspecialchars($sec['nombre']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- PANEL DE CONTENIDO PRINCIPAL -->
        <div class="col-md-9 col-lg-10 content-wrapper" id="mainContentWrapper">

            <?php if ($mensaje): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Encabezado de la Sección Activa -->
            <div class="card-custom mb-4" style="border-left: 4px solid #3b82f6;">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="area-icon-wrapper" style="width: 48px; height: 48px; font-size: 1.4rem;">
                            <i class="bi <?php echo $infoSeccion['icono']; ?>"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h4 class="fw-bold mb-0 text-white"><?php echo htmlspecialchars($infoSeccion['nombre']); ?></h4>
                                <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 small fw-bold">
                                    <?php echo count($registros); ?> Registros
                                </span>
                            </div>
                            <p class="text-secondary small mb-0 mt-1"><?php echo htmlspecialchars($infoSeccion['descripcion']); ?></p>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if ($seccion_activa === 'graficas'): ?>
                            <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 px-3 py-2 fw-bold fs-7 rounded-pill">
                                <i class="bi bi-bar-chart-line-fill me-1"></i> Dashboard Estadístico Global
                            </span>
                        <?php else: ?>
                            <!-- Conmutador de Vista (Lista Tabla vs Por Áreas) -->
                            <div class="segmented-control me-1">
                                <button type="button" class="btn-seg active" id="btnVistaTablaEquipos" onclick="cambiarVistaEquipos('tabla')">
                                    <i class="bi bi-table me-1"></i> Lista Tabla
                                </button>
                                <button type="button" class="btn-seg" id="btnVistaAreasEquipos" onclick="cambiarVistaEquipos('areas')">
                                    <?php if ($seccion_activa === 'dvr' || $seccion_activa === 'site_vw'): ?>
                                        <i class="bi bi-grid-3x3-gap-fill me-1"></i> Vista Recuadros
                                    <?php else: ?>
                                        <i class="bi bi-diagram-3-fill me-1"></i> Por Áreas
                                    <?php endif; ?>
                                </button>
                            </div>

                            <input type="text" id="busquedaTabla" class="form-control form-control-sm px-3" placeholder="🔍 Buscar registro..." style="width: 210px;" onkeyup="filtrarTabla()">
                            <?php if (tienePermiso('equipos', 'puede_crear')): ?>
                                <?php if ($seccion_activa === 'dvr'): ?>
                                    <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold shadow-sm" onclick="abrirModalNuevoDVR()">
                                        <i class="bi bi-camera-video-fill me-1"></i> Registrar Grabador DVR/NVR
                                    </button>
                                    <button type="button" class="btn btn-info btn-sm rounded-3 px-3 fw-semibold shadow-sm text-white" onclick="abrirModalNuevaCamara()">
                                        <i class="bi bi-camera-fill me-1"></i> Registrar Cámara IP / Análoga
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold shadow-sm" onclick="abrirModalNuevo()">
                                        <i class="bi bi-plus-lg me-1"></i> Nuevo Registro
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (tienePermiso('equipos', 'puede_exportar')): ?>
                                <button type="button" class="btn btn-outline-success btn-sm rounded-3 px-3" onclick="exportarTablaCSV()">
                                    <i class="bi bi-file-earmark-excel me-1"></i> Exportar CSV
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- VISTA 1: TABLA RESUMIDA DE REGISTROS -->
            <div class="card-custom <?php echo ($seccion_activa === 'graficas') ? 'd-none' : ''; ?>" id="vistaTablaEquipos">
                <div class="table-responsive">
                    <table class="table table-custom table-hover align-middle mb-0" id="tablaEquipos">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <?php if ($seccion_activa === 'dvr'): ?>
                                    <th>Tipo</th>
                                    <th>Nombre / Dispositivo</th>
                                    <th>DVR/NVR Vinculado</th>
                                    <th>IP</th>
                                    <th>Contraseña</th>
                                <?php elseif ($seccion_activa === 'site_vw'): ?>
                                    <th>Tipo de Dispositivo</th>
                                    <th>Nombre del Dispositivo</th>
                                    <th>Dirección IP</th>
                                    <th>Contraseña</th>
                                <?php elseif ($seccion_activa === 'monitores'): ?>
                                    <th>Marca / Modelo</th>
                                    <th>Serie (S/N)</th>
                                    <th>Área</th>
                                    <th>IP Impresora (Consola Web)</th>
                                    <th>Estado / Tóner (SNMP en Vivo)</th>
                                <?php elseif ($seccion_activa === 'telefonos_poe'): ?>
                                    <th>Extensión / Teléfono</th>
                                    <th>Usuario Asignado</th>
                                    <th>Área</th>
                                    <th>Nodo</th>
                                <?php else: ?>
                                    <th>Departamento</th>
                                    <th>Puesto</th>
                                    <th>Usuario</th>
                                    <th>Nombre de la Máquina</th>
                                    <th>Contraseña</th>
                                <?php endif; ?>
                                <th class="text-end">Acciones / Expediente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($registros)): ?>
                                <tr>
                                    <td colspan="<?php echo in_array($seccion_activa, ['telefonos_poe', 'site_vw']) ? 6 : 7; ?>" class="text-center py-4 text-secondary">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i> No hay registros capturados en <strong><?php echo htmlspecialchars($infoSeccion['nombre']); ?></strong>. Usa el botón 'Nuevo Registro' para agregar el primero.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($registros as $reg): ?>
                                    <tr class="user-row" onclick='abrirExpedienteSiNoEsBoton(event, <?php echo json_encode($reg); ?>)' title="Haz clic en la fila para abrir el Expediente Completo">
                                        <td class="fw-bold text-secondary">#<?php echo $reg['id']; ?></td>
                                        <?php if ($seccion_activa === 'dvr'): ?>
                                            <td>
                                                <?php if (($reg['tipo_registro'] ?? '') === 'Cámara'): ?>
                                                    <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 rounded-pill px-2.5 py-1 small fw-bold">
                                                        <i class="bi bi-camera-fill me-1"></i> Cámara
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 small fw-bold">
                                                        <i class="bi bi-camera-video-fill me-1"></i> DVR/NVR
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-white">
                                                <i class="bi bi-display text-primary me-1"></i>
                                                <?php echo htmlspecialchars($reg['nombre'] ?? $reg['dvr'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($reg['dvr_vinculado'])): ?>
                                                    <span class="badge bg-dark text-info border border-info border-opacity-25 px-2.5 py-1">
                                                        <i class="bi bi-hdd-network me-1"></i> <?php echo htmlspecialchars($reg['dvr_vinculado']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-secondary small">-- Principal --</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-info font-monospace small"><?php echo htmlspecialchars($reg['ip'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php 
                                                $passVal = $reg['contrasena_dvr'] ?? $reg['contrasena_camara'] ?? $reg['contrasena'] ?? '';
                                                if (!empty($passVal)): 
                                                    $passId = 'pass_tbl_' . $reg['id'];
                                                ?>
                                                    <div class="d-inline-flex align-items-center">
                                                        <span class="pass-cell" id="<?php echo $passId; ?>" data-pass="<?php echo htmlspecialchars($passVal); ?>">••••••••</span>
                                                        <button type="button" class="btn btn-sm text-info p-0 ms-2" onclick="togglePassSpan('<?php echo $passId; ?>', this)" title="Mostrar / Ocultar Contraseña">
                                                            <i class="bi bi-eye-fill"></i>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">---</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php elseif ($seccion_activa === 'site_vw'): ?>
                                            <td>
                                                <?php echo renderBadgeTipoSite($reg['tipo_registro'] ?? 'Switch'); ?>
                                            </td>
                                            <td class="fw-bold text-white">
                                                <i class="bi bi-display text-primary me-1"></i>
                                                <?php echo htmlspecialchars($reg['nombre_equipo'] ?? $reg['equipo'] ?? 'N/A'); ?>
                                            </td>
                                            <td class="text-info font-monospace small"><?php echo htmlspecialchars(!empty($reg['ip']) ? $reg['ip'] : ($reg['ip_publica'] ?? 'N/A')); ?></td>
                                            <td>
                                                <?php 
                                                $passVal = $reg['contrasena'] ?? '';
                                                if (!empty($passVal)): 
                                                    $passId = 'pass_tbl_' . $reg['id'];
                                                ?>
                                                    <div class="d-inline-flex align-items-center">
                                                        <span class="pass-cell" id="<?php echo $passId; ?>" data-pass="<?php echo htmlspecialchars($passVal); ?>">••••••••</span>
                                                        <button type="button" class="btn btn-sm text-info p-0 ms-2" onclick="togglePassSpan('<?php echo $passId; ?>', this)" title="Mostrar / Ocultar Contraseña">
                                                            <i class="bi bi-eye-fill"></i>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">---</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php elseif ($seccion_activa === 'monitores'): ?>
                                            <td class="fw-bold text-white">
                                                <i class="bi bi-printer-fill text-primary me-1"></i> <?php echo htmlspecialchars($reg['marca'] ?? 'Impresora'); ?>
                                                <?php if (!empty($reg['modelo_exacto'])): ?>
                                                    <div class="small text-secondary font-monospace"><?php echo htmlspecialchars($reg['modelo_exacto']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary font-monospace small"><?php echo htmlspecialchars($reg['serie'] ?? 'N/A'); ?></td>
                                            <td class="fw-semibold text-white"><?php echo htmlspecialchars($reg['departamento'] ?? $reg['area'] ?? 'General'); ?></td>
                                            <td class="text-info font-monospace small">
                                                <?php if (!empty($reg['ip'])): ?>
                                                    <a href="http://<?php echo htmlspecialchars($reg['ip']); ?>/" target="_blank" class="text-info text-decoration-none fw-bold" title="Abrir Web Image Monitor / Consola Web en ventana nueva">
                                                        <i class="bi bi-globe me-1"></i><?php echo htmlspecialchars($reg['ip']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td id="snmp_status_cell_<?php echo $reg['id']; ?>">
                                                <?php if (!empty($reg['ip'])): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-2.5 py-0.5 small fw-bold" onclick="consultarSNMPImpresora('<?php echo htmlspecialchars($reg['ip']); ?>', <?php echo $reg['id']; ?>, this)">
                                                        <i class="bi bi-activity me-1"></i> Consultar Estado
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sin IP</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php elseif ($seccion_activa === 'telefonos_poe'): ?>
                                            <td class="fw-bold text-white">
                                                <div class="d-flex align-items-center gap-2">
                                                    <?php if (!empty($reg['extension'])): ?>
                                                        <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25 px-2.5 py-1.5 font-monospace fs-6">
                                                            <i class="bi bi-telephone-fill me-1"></i> Ext. <?php echo htmlspecialchars($reg['extension']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($reg['numero_telefonico'])): ?>
                                                        <span class="text-white small font-monospace"><i class="bi bi-phone me-1 text-success"></i><?php echo htmlspecialchars($reg['numero_telefonico']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (empty($reg['extension']) && empty($reg['numero_telefonico'])): ?>
                                                        <span class="text-muted small">---</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge-user">
                                                    <i class="bi bi-person-fill me-1 opacity-75"></i>
                                                    <?php echo htmlspecialchars($reg['usuario'] ?: 'Sin Asignar'); ?>
                                                </span>
                                            </td>
                                            <td class="fw-semibold text-white">
                                                <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-25 px-2.5 py-1">
                                                    <?php echo htmlspecialchars($reg['area'] ?? $reg['departamento'] ?? 'General'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($reg['numero_nodo'])): ?>
                                                    <span class="badge bg-dark text-info border border-info border-opacity-50 font-monospace px-2.5 py-1" style="font-size: 0.8rem;" title="Nodo de red RJ45 (Compartido en serie con equipo)">
                                                        <i class="bi bi-ethernet me-1"></i>Nodo <?php echo htmlspecialchars($reg['numero_nodo']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sin asignar</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php else: ?>
                                            <td class="fw-semibold text-white"><?php echo htmlspecialchars(($seccion_activa === 'dvr' || $seccion_activa === 'site_vw') ? 'Sistemas' : ($reg['departamento'] ?? $reg['area'] ?? 'General')); ?></td>
                                            <td class="text-secondary fw-semibold"><?php echo htmlspecialchars($reg['puesto'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge-user">
                                                    <i class="bi bi-person-fill me-1 opacity-75"></i>
                                                    <?php echo htmlspecialchars($reg['usuario'] ?? $reg['nombre'] ?? $reg['dvr'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold text-white">
                                                <i class="bi bi-display text-primary me-1"></i>
                                                <?php echo htmlspecialchars($reg['nombre_equipo'] ?? $reg['equipo'] ?? $reg['monitor'] ?? $reg['nombre'] ?? 'N/A'); ?>
                                                <?php if (!empty($reg['ip'])): ?>
                                                    <div class="small font-monospace text-info mt-1" style="font-size: 0.78rem;">
                                                        <i class="bi bi-globe me-1"></i><?php echo htmlspecialchars($reg['ip']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $passVal = $reg['contrasena'] ?? $reg['contrasena_dvr'] ?? $reg['contrasena_pb'] ?? '';
                                                if (!empty($passVal)): 
                                                    $passId = 'pass_tbl_' . $reg['id'];
                                                ?>
                                                    <div class="d-inline-flex align-items-center">
                                                        <span class="pass-cell" id="<?php echo $passId; ?>" data-pass="<?php echo htmlspecialchars($passVal); ?>">••••••••</span>
                                                        <button type="button" class="btn btn-sm text-info p-0 ms-2" onclick="togglePassSpan('<?php echo $passId; ?>', this)" title="Mostrar / Ocultar Contraseña">
                                                            <i class="bi bi-eye-fill"></i>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">---</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <!-- Generar Carta Responsiva PDF -->
                                                <?php if (in_array($seccion_activa, ['equipos_vw', 'equipos_corp', 'moviles'])): ?>
                                                    <a href="generar_responsiva.php?id=<?php echo $reg['id']; ?>&sec=<?php echo $seccion_activa; ?>" target="_blank" class="btn btn-sm btn-outline-warning rounded-2 fw-bold" title="Generar e imprimir Carta Responsiva PDF">
                                                        <i class="bi bi-printer-fill me-1"></i> Responsiva
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Pasar a Equipos Baja -->
                                                <?php if ($seccion_activa !== 'equipos_baja' && $seccion_activa !== 'nobreak_baja'): ?>
                                                    <button type="button" class="btn btn-action-baja" onclick='abrirModalBaja(<?php echo $reg['id']; ?>, <?php echo json_encode($reg['nombre_equipo'] ?? $reg['usuario'] ?? 'Equipo #' . $reg['id']); ?>)' title="Mover este equipo a la sección de Equipos Baja">
                                                        <i class="bi bi-arrow-down-circle-fill me-1"></i> Baja
                                                    </button>
                                                <?php endif; ?>

                                                <!-- Expediente Completo -->
                                                <button type="button" class="btn btn-sm btn-outline-info rounded-2 fw-bold" onclick='verExpedienteCompleto(<?php echo json_encode($reg); ?>)' title="Abrir Expediente Completo">
                                                    <i class="bi bi-folder2-open me-1"></i> Expediente
                                                </button>

                                                <!-- Editar Registro -->
                                                <button type="button" class="btn btn-action-edit" onclick='abrirModalEditar(<?php echo json_encode($reg); ?>)' title="Editar Registro">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>

                                                <!-- Eliminar -->
                                                <?php if (tienePermiso('equipos', 'puede_eliminar')): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas eliminar este registro de inventario?');">
                                                        <input type="hidden" name="accion" value="eliminar_registro">
                                                        <input type="hidden" name="registro_id" value="<?php echo $reg['id']; ?>">
                                                        <button type="submit" class="btn btn-action-delete" title="Eliminar Registro">
                                                            <i class="bi bi-trash-fill"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- VISTA 2: RECUADROS / ÁREAS -->
            <div id="vistaAreasEquipos" class="d-none">
                <?php if (empty($registros)): ?>
                    <div class="card-custom text-center py-5 text-secondary">
                        <i class="bi bi-inbox fs-2 d-block mb-2 text-info"></i>
                        No hay registros capturados en <strong><?php echo htmlspecialchars($infoSeccion['nombre']); ?></strong>. Usa el botón 'Nuevo Registro' para agregar el primero.
                    </div>
                <?php elseif ($seccion_activa === 'dvr' || $seccion_activa === 'site_vw'): ?>
                    <!-- VISTA DE RECUADROS DIRECTA (SIN CABECERAS DE ÁREAS VACÍAS) -->
                    <div class="row g-3">
                        <?php foreach ($registros as $reg): ?>
                            <div class="col-xl-4 col-md-6 equipo-card-item">
                                <div class="equipo-card-inner d-flex flex-column h-100" onclick='abrirExpedienteSiNoEsBoton(event, <?php echo json_encode($reg); ?>)'>
                                    <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom border-secondary border-opacity-20">
                                        <span class="fw-bold text-secondary small">#<?php echo $reg['id']; ?></span>
                                        <?php if ($seccion_activa === 'dvr'): ?>
                                            <?php if (($reg['tipo_registro'] ?? '') === 'Cámara'): ?>
                                                <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 px-2.5 py-1 small fw-bold rounded-pill">
                                                    <i class="bi bi-camera-fill me-1"></i> Cámara (<?php echo htmlspecialchars($reg['subtipo_camara'] ?? 'IP'); ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 px-2.5 py-1 small fw-bold rounded-pill">
                                                    <i class="bi bi-camera-video-fill me-1"></i> DVR/NVR
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo renderBadgeTipoSite($reg['tipo_registro'] ?? 'Switch'); ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mb-2">
                                        <span class="text-secondary small d-block mb-1">Nombre / Equipo:</span>
                                        <div class="fw-bold text-white fs-6 d-flex align-items-center gap-2 mt-0.5">
                                            <i class="bi bi-display text-primary fs-5"></i>
                                            <span class="text-truncate"><?php echo htmlspecialchars($reg['nombre'] ?? $reg['nombre_equipo'] ?? $reg['dvr'] ?? 'N/A'); ?></span>
                                        </div>
                                    </div>

                                    <?php if ($seccion_activa === 'dvr' && !empty($reg['dvr_vinculado'])): ?>
                                        <div class="mb-2">
                                            <span class="text-secondary small d-block mb-1">DVR/NVR al que Pertenece:</span>
                                            <span class="badge bg-dark text-info border border-info border-opacity-25 px-2.5 py-1">
                                                <i class="bi bi-hdd-network me-1"></i> <?php echo htmlspecialchars($reg['dvr_vinculado']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($reg['ip'])): ?>
                                        <div class="mb-2">
                                            <span class="text-secondary small d-block">Dirección IP:</span>
                                            <span class="text-info font-monospace small fw-bold"><?php echo htmlspecialchars($reg['ip']); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <span class="text-secondary small d-block">Contraseña:</span>
                                        <?php 
                                        $passVal = $reg['contrasena_dvr'] ?? $reg['contrasena_camara'] ?? $reg['contrasena'] ?? '';
                                        if (!empty($passVal)): 
                                            $passIdArea = 'pass_area_' . $reg['id'] . '_' . rand(100, 999);
                                        ?>
                                            <div class="d-inline-flex align-items-center mt-1">
                                                <span class="pass-cell" id="<?php echo $passIdArea; ?>" data-pass="<?php echo htmlspecialchars($passVal); ?>">••••••••</span>
                                                <button type="button" class="btn btn-sm text-info p-0 ms-2" onclick="togglePassSpan('<?php echo $passIdArea; ?>', this)" title="Mostrar / Ocultar Contraseña">
                                                    <i class="bi bi-eye-fill"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">---</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($reg['puerto_patch_panel']) || !empty($reg['puerto_sw']) || !empty($reg['numero_nodo'])): ?>
                                        <div class="p-2 mb-3 rounded-2" style="background: rgba(15, 34, 61, 0.7); border: 1px solid rgba(255, 255, 255, 0.05); font-size: 0.78rem;">
                                            <span class="text-info fw-bold d-block mb-1"><i class="bi bi-ethernet me-1"></i> Red / Telecom:</span>
                                            <?php if (!empty($reg['puerto_patch_panel'])): ?><span class="text-light me-2">Patch: <strong><?php echo htmlspecialchars($reg['puerto_patch_panel']); ?></strong></span><?php endif; ?>
                                            <?php if (!empty($reg['puerto_sw'])): ?><span class="text-light me-2">SW: <strong><?php echo htmlspecialchars($reg['puerto_sw']); ?></strong></span><?php endif; ?>
                                            <?php if (!empty($reg['numero_nodo'])): ?><span class="text-light">Nodo: <strong><?php echo htmlspecialchars($reg['numero_nodo']); ?></strong></span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Botones de Acción -->
                                    <div class="mt-auto pt-2 border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center">
                                        <?php if (in_array($seccion_activa, ['equipos_vw', 'equipos_corp', 'moviles'])): ?>
                                            <a href="generar_responsiva.php?id=<?php echo $reg['id']; ?>&sec=<?php echo $seccion_activa; ?>" target="_blank" class="btn btn-sm btn-outline-warning fw-bold rounded-2" title="Generar Carta Responsiva PDF">
                                                <i class="bi bi-printer-fill me-1"></i> Responsiva
                                            </a>
                                        <?php else: ?>
                                            <div></div>
                                        <?php endif; ?>
                                        <div class="d-inline-flex gap-1">
                                            <?php if ($seccion_activa !== 'equipos_baja' && $seccion_activa !== 'nobreak_baja'): ?>
                                                <button type="button" class="btn btn-action-baja btn-sm" onclick='abrirModalBaja(<?php echo $reg['id']; ?>, <?php echo json_encode($reg['nombre_equipo'] ?? $reg['nombre'] ?? 'Equipo #' . $reg['id']); ?>)' title="Baja">
                                                    <i class="bi bi-arrow-down-circle-fill"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-action-edit btn-sm" onclick='abrirModalEditar(<?php echo json_encode($reg); ?>)' title="Editar">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <?php if (tienePermiso('equipos', 'puede_eliminar')): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas eliminar este registro de inventario?');">
                                                    <input type="hidden" name="accion" value="eliminar_registro">
                                                    <input type="hidden" name="registro_id" value="<?php echo $reg['id']; ?>">
                                                    <button type="submit" class="btn btn-action-delete btn-sm" title="Eliminar">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($equiposPorAreaMap as $nomArea => $equiposInArea): ?>
                        <?php $cantEquiposInArea = count($equiposInArea); ?>
                        <div class="area-group-card mb-4 area-group-block">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-3 border-bottom border-secondary border-opacity-25 gap-2">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="area-icon-wrapper">
                                        <i class="bi bi-building-gear"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold text-white mb-0"><?php echo htmlspecialchars($nomArea); ?></h5>
                                        <small class="text-secondary">Área de Inventario & Asset Control</small>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="area-count-badge">
                                        <i class="bi bi-cpu-fill me-1"></i> <?php echo $cantEquiposInArea; ?> <?php echo ($cantEquiposInArea === 1) ? 'Equipo Registrado' : 'Equipos Registrados'; ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($cantEquiposInArea === 0): ?>
                                <div class="empty-area-box">
                                    <i class="bi bi-hdd-stack text-secondary opacity-50 fs-2 d-block mb-2"></i>
                                    <span class="fw-semibold text-white d-block mb-1">Sin equipos asignados</span>
                                    <p class="text-secondary small mb-3">No hay equipos registrados actualmente en el área de <strong><?php echo htmlspecialchars($nomArea); ?></strong>.</p>
                                    <?php if (tienePermiso('equipos', 'puede_crear')): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-3 px-3" onclick='abrirModalNuevoConArea(<?php echo json_encode($nomArea); ?>)'>
                                            <i class="bi bi-plus-lg me-1"></i> Registrar Equipo en <?php echo htmlspecialchars($nomArea); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($equiposInArea as $reg): ?>
                                        <div class="col-xl-4 col-md-6 equipo-card-item">
                                            <div class="equipo-card-inner d-flex flex-column h-100" onclick='abrirExpedienteSiNoEsBoton(event, <?php echo json_encode($reg); ?>)'>
                                                <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom border-secondary border-opacity-20">
                                                    <span class="fw-bold text-secondary small">#<?php echo $reg['id']; ?></span>
                                                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 px-2.5 py-1 small fw-bold rounded-pill">
                                                        <i class="bi bi-diagram-3 me-1"></i> <?php echo htmlspecialchars($reg['departamento'] ?? $reg['area'] ?? $nomArea); ?>
                                                    </span>
                                                </div>

                                                <div class="mb-2">
                                                    <span class="text-secondary small d-block mb-1">Usuario Asignado:</span>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge-user">
                                                            <i class="bi bi-person-circle me-1 opacity-75"></i>
                                                            <?php echo htmlspecialchars($reg['usuario'] ?? $reg['nombre'] ?? $reg['dvr'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                    <small class="text-secondary fw-semibold d-block mt-1 text-truncate"><i class="bi bi-briefcase me-1 text-info opacity-75"></i> <?php echo htmlspecialchars($reg['puesto'] ?? 'N/A'); ?></small>
                                                </div>

                                                <div class="mb-2">
                                                    <span class="text-secondary small d-block">Equipo / Nombre Máquina:</span>
                                                    <div class="fw-bold text-white fs-6 d-flex align-items-center gap-2 mt-0.5">
                                                        <i class="bi bi-display text-primary fs-5"></i>
                                                        <span class="text-truncate"><?php echo htmlspecialchars($reg['nombre_equipo'] ?? $reg['equipo'] ?? $reg['monitor'] ?? $reg['nombre'] ?? 'N/A'); ?></span>
                                                    </div>
                                                </div>

                                                <!-- Micro Specs Badges -->
                                                <?php 
                                                $hasSpecs = !empty($reg['ram']) || !empty($reg['procesador']) || !empty($reg['dd']);
                                                if ($hasSpecs):
                                                ?>
                                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                                        <?php if (!empty($reg['procesador'])): ?>
                                                            <span class="badge bg-dark text-info border border-info border-opacity-25 px-2 py-0.5" style="font-size: 0.72rem;">
                                                                <i class="bi bi-cpu me-1"></i><?php echo htmlspecialchars($reg['procesador']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($reg['ram'])): ?>
                                                            <span class="badge bg-dark text-warning border border-warning border-opacity-25 px-2 py-0.5" style="font-size: 0.72rem;">
                                                                <i class="bi bi-memory me-1"></i><?php echo htmlspecialchars($reg['ram']); ?> GB RAM
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($reg['dd'])): ?>
                                                            <span class="badge bg-dark text-light border border-secondary border-opacity-25 px-2 py-0.5" style="font-size: 0.72rem;">
                                                                <i class="bi bi-hdd me-1"></i><?php echo htmlspecialchars($reg['dd']); ?> GB
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="mb-3">
                                                    <span class="text-secondary small d-block">Contraseña:</span>
                                                    <?php 
                                                    $passVal = $reg['contrasena'] ?? $reg['contrasena_dvr'] ?? $reg['contrasena_pb'] ?? '';
                                                    if (!empty($passVal)): 
                                                        $passIdArea = 'pass_area_' . $reg['id'] . '_' . rand(100, 999);
                                                    ?>
                                                        <div class="d-inline-flex align-items-center mt-1">
                                                            <span class="pass-cell" id="<?php echo $passIdArea; ?>" data-pass="<?php echo htmlspecialchars($passVal); ?>">••••••••</span>
                                                            <button type="button" class="btn btn-sm text-info p-0 ms-2" onclick="togglePassSpan('<?php echo $passIdArea; ?>', this)" title="Mostrar / Ocultar Contraseña">
                                                                <i class="bi bi-eye-fill"></i>
                                                            </button>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted small">---</span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($reg['puerto_patch_panel']) || !empty($reg['puerto_sw']) || !empty($reg['numero_nodo'])): ?>
                                                    <div class="p-2 mb-3 rounded-2" style="background: rgba(15, 34, 61, 0.7); border: 1px solid rgba(255, 255, 255, 0.05); font-size: 0.78rem;">
                                                        <span class="text-info fw-bold d-block mb-1"><i class="bi bi-ethernet me-1"></i> Red / Telecom:</span>
                                                        <?php if (!empty($reg['puerto_patch_panel'])): ?><span class="text-light me-2">Patch: <strong><?php echo htmlspecialchars($reg['puerto_patch_panel']); ?></strong></span><?php endif; ?>
                                                        <?php if (!empty($reg['puerto_sw'])): ?><span class="text-light me-2">SW: <strong><?php echo htmlspecialchars($reg['puerto_sw']); ?></strong></span><?php endif; ?>
                                                        <?php if (!empty($reg['numero_nodo'])): ?><span class="text-light">Nodo: <strong><?php echo htmlspecialchars($reg['numero_nodo']); ?></strong></span><?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Botones de Acción -->
                                                <div class="mt-auto pt-2 border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center">
                                                    <?php if (in_array($seccion_activa, ['equipos_vw', 'equipos_corp', 'moviles'])): ?>
                                                        <a href="generar_responsiva.php?id=<?php echo $reg['id']; ?>&sec=<?php echo $seccion_activa; ?>" target="_blank" class="btn btn-sm btn-outline-warning fw-bold rounded-2" title="Generar Carta Responsiva PDF">
                                                            <i class="bi bi-printer-fill me-1"></i> Responsiva
                                                        </a>
                                                    <?php else: ?>
                                                        <div></div>
                                                    <?php endif; ?>
                                                    <div class="d-inline-flex gap-1">
                                                        <?php if ($seccion_activa !== 'equipos_baja' && $seccion_activa !== 'nobreak_baja'): ?>
                                                            <button type="button" class="btn btn-action-baja btn-sm" onclick='abrirModalBaja(<?php echo $reg['id']; ?>, <?php echo json_encode($reg['nombre_equipo'] ?? $reg['usuario'] ?? 'Equipo #' . $reg['id']); ?>)' title="Baja">
                                                                <i class="bi bi-arrow-down-circle-fill"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-action-edit btn-sm" onclick='abrirModalEditar(<?php echo json_encode($reg); ?>)' title="Editar">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </button>
                                                        <?php if (tienePermiso('equipos', 'puede_eliminar')): ?>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas eliminar este registro de inventario?');">
                                                                <input type="hidden" name="accion" value="eliminar_registro">
                                                                <input type="hidden" name="registro_id" value="<?php echo $reg['id']; ?>">
                                                                <button type="submit" class="btn btn-action-delete btn-sm" title="Eliminar">
                                                                    <i class="bi bi-trash-fill"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- VISTA 3: DASHBOARD DE GRÁFICAS Y MÉTRICAS ANALYTICS -->
            <div id="vistaGraficasEquipos" class="<?php echo ($seccion_activa === 'graficas') ? '' : 'd-none'; ?>">
                
                <!-- ROW 1: TARJETAS KPI DE MÉTRICAS CLAVE -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card-custom h-100 p-3 d-flex align-items-center gap-3" style="border-left: 4px solid #3b82f6;">
                            <div class="area-icon-wrapper" style="width: 48px; height: 48px; font-size: 1.4rem; background: rgba(37, 99, 235, 0.2); color: #60a5fa;">
                                <i class="bi bi-display-fill"></i>
                            </div>
                            <div>
                                <span class="text-secondary small fw-bold text-uppercase d-block" style="font-size: 0.7rem; letter-spacing: 0.05em;">Total de Equipos</span>
                                <h3 class="fw-bold text-white mb-0"><?php echo $totalEquiposSec; ?> <small class="fs-6 text-muted fw-normal">unidades</small></h3>
                                <small class="text-info" style="font-size: 0.75rem;"><i class="bi bi-check-circle me-1"></i> Sección activa</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card-custom h-100 p-3 d-flex align-items-center gap-3" style="border-left: 4px solid #10b981;">
                            <div class="area-icon-wrapper" style="width: 48px; height: 48px; font-size: 1.4rem; background: rgba(16, 185, 129, 0.2); border-color: rgba(16, 185, 129, 0.3); color: #34d399;">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                            <div>
                                <span class="text-secondary small fw-bold text-uppercase d-block" style="font-size: 0.7rem; letter-spacing: 0.05em;">Inversión Total ($)</span>
                                <h3 class="fw-bold text-success mb-0">$<?php echo number_format($inversionTotalSec, 2); ?></h3>
                                <small class="text-secondary" style="font-size: 0.75rem;"><i class="bi bi-tag-fill me-1"></i> Costo capturado acumulado</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card-custom h-100 p-3 d-flex align-items-center gap-3" style="border-left: 4px solid #f59e0b;">
                            <div class="area-icon-wrapper" style="width: 48px; height: 48px; font-size: 1.4rem; background: rgba(245, 158, 11, 0.2); border-color: rgba(245, 158, 11, 0.3); color: #fbbf24;">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                            <div>
                                <span class="text-secondary small fw-bold text-uppercase d-block" style="font-size: 0.7rem; letter-spacing: 0.05em;">Próximos a Renovar</span>
                                <h3 class="fw-bold text-warning mb-0"><?php echo $proximosRenovarCount; ?> <small class="fs-6 text-muted fw-normal">equipos</small></h3>
                                <small class="text-warning opacity-75" style="font-size: 0.75rem;"><i class="bi bi-exclamation-triangle me-1"></i> En los próximos 60 días</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card-custom h-100 p-3 d-flex align-items-center gap-3" style="border-left: 4px solid #ef4444;">
                            <div class="area-icon-wrapper" style="width: 48px; height: 48px; font-size: 1.4rem; background: rgba(239, 68, 68, 0.2); border-color: rgba(239, 68, 68, 0.3); color: #f87171;">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                            <div>
                                <span class="text-secondary small fw-bold text-uppercase d-block" style="font-size: 0.7rem; letter-spacing: 0.05em;">Requieren Renovación</span>
                                <h3 class="fw-bold text-danger mb-0"><?php echo $requierenRenovarCount; ?> <small class="fs-6 text-muted fw-normal">equipos</small></h3>
                                <small class="text-danger opacity-75" style="font-size: 0.75rem;"><i class="bi bi-shield-x me-1"></i> Garantía o ciclo vencido</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ROW 2: GRÁFICAS PRINCIPALES -->
                <div class="row g-4 mb-4">
                    <!-- GRÁFICA 1: EQUIPOS POR ÁREA -->
                    <div class="col-lg-7">
                        <div class="card-custom h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-bar-chart-fill text-primary me-2"></i> Equipos Registrados por Área</h6>
                                    <small class="text-secondary">Distribución de inventario por departamentos de la agencia</small>
                                </div>
                                <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 px-2.5 py-1">Cantidades</span>
                            </div>
                            <div style="position: relative; height: 280px;">
                                <canvas id="chartEquiposPorArea"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- GRÁFICA 2: ESTATUS DE RENOVACIÓN Y GARANTÍAS -->
                    <div class="col-lg-5">
                        <div class="card-custom h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-pie-chart-fill text-warning me-2"></i> Estatus de Garantía y Renovación</h6>
                                    <small class="text-secondary">Vigencia y ciclo de vida de los equipos</small>
                                </div>
                            </div>
                            <div style="position: relative; height: 280px;" class="d-flex align-items-center justify-content-center">
                                <canvas id="chartEstatusRenovacion"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ROW 3: GRÁFICA DE INVERSIÓN Y ESPECIFICACIONES -->
                <div class="row g-4 mb-4">
                    <!-- GRÁFICA 3: INVERSIÓN MONETARIA POR ÁREA -->
                    <div class="col-lg-7">
                        <div class="card-custom h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-currency-dollar text-success me-2"></i> Inversión Monetaria por Área ($)</h6>
                                    <small class="text-secondary">Costo total acumulado de equipos asignados a cada área</small>
                                </div>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25 px-2.5 py-1">Monto $</span>
                            </div>
                            <div style="position: relative; height: 280px;">
                                <canvas id="chartCostoPorArea"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- GRÁFICA 4: DISTRIBUCIÓN POR MEMORIA RAM -->
                    <div class="col-lg-5">
                        <div class="card-custom h-100">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-cpu-fill text-info me-2"></i> Distribución por Memoria RAM</h6>
                                    <small class="text-secondary">Capacidad de memoria en los equipos activos</small>
                                </div>
                            </div>
                            <div style="position: relative; height: 280px;" class="d-flex align-items-center justify-content-center">
                                <canvas id="chartRamDistribucion"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ROW 4: DISTRIBUCIÓN GLOBAL POR SECCIÓN DE INVENTARIO -->
                <div class="row g-4 mb-4">
                    <div class="col-12">
                        <div class="card-custom">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-hdd-stack-fill text-info me-2"></i> Distribución de Equipos por Sección de Inventario</h6>
                                    <small class="text-secondary">Conteo de activos en Equipos VW, Corp, SITE, DVR, Móviles, Monitores y Bajas</small>
                                </div>
                                <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 px-2.5 py-1">Secciones Globales</span>
                            </div>
                            <div style="position: relative; height: 260px;">
                                <canvas id="chartEquiposPorSeccion"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL DE CAPTURA / EDICIÓN COMPLETA (TODOS LOS CAMPOS) -->
<!-- =================================================== -->
<div class="modal fade" id="modalRegistro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="formRegistroEquipos" onsubmit="return validarReglasFormularioEquipos(event, this);">
                <input type="hidden" name="accion" value="guardar_registro">
                <input type="hidden" name="registro_id" id="form_registro_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalRegistroLabel"><i class="bi bi-plus-circle text-primary me-2"></i> Captura de Registro</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="alerta_error_validacion_modal" class="alert alert-danger border-0 rounded-3 mb-3 py-2.5 text-white shadow-sm" style="display: none; background: rgba(239, 68, 68, 0.25); border: 1.5px solid #ef4444 !important;"></div>
                    <?php 
                    $camposPorCat = [];
                    foreach ($infoSeccion['campos_full'] as $col) {
                        $cat = obtenerCategoriaCampo($col);
                        $camposPorCat[$cat['id']]['info'] = $cat;
                        $camposPorCat[$cat['id']]['campos'][] = $col;
                    }
                    ?>
                    <div class="alert border-0 rounded-3 mb-3 py-2 text-white" style="background: rgba(14, 165, 233, 0.15); border: 1px solid rgba(56, 189, 248, 0.3) !important;">
                        <i class="bi bi-info-circle-fill me-2 text-info"></i> Completa los campos del expediente organizados en las pestañas temáticas. Los datos numéricos se guardan en sus unidades correspondientes.
                    </div>

                    <!-- Pestañas de Navegación del Formulario -->
                    <ul class="nav nav-pills nav-pills-custom mb-3 flex-nowrap overflow-auto py-1" id="tabCapturaEquipos" role="tablist">
                        <?php $isFirst = true; foreach ($camposPorCat as $catId => $data): ?>
                            <li class="nav-item me-2" role="presentation">
                                <button class="nav-link text-nowrap <?php echo $isFirst ? 'active' : ''; ?>" id="tab-<?php echo $catId; ?>" data-bs-toggle="pill" data-bs-target="#pane-<?php echo $catId; ?>" type="button" role="tab">
                                    <i class="bi <?php echo $data['info']['icono']; ?> me-1"></i> <?php echo $data['info']['titulo']; ?>
                                </button>
                            </li>
                        <?php $isFirst = false; endforeach; ?>
                    </ul>

                    <!-- Contenido por Pestaña -->
                    <div class="tab-content" id="contenedorCamposForm">
                        <?php $isFirst = true; foreach ($camposPorCat as $catId => $data): ?>
                            <div class="tab-pane fade <?php echo $isFirst ? 'show active' : ''; ?>" id="pane-<?php echo $catId; ?>" role="tabpanel">
                                <div class="row g-3">
                                    <?php foreach ($data['campos'] as $col): 
                                        $esNum = esCampoNumerico($col);
                                        $esFecha = esCampoFecha($col);
                                        $unidad = obtenerUnidadCampo($col);
                                        $esPass = esCampoPassword($col);
                                        $label = obtenerEtiquetaCampo($col);
                                    ?>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-semibold text-secondary mb-1"><?php echo htmlspecialchars($label); ?></label>
                                             <?php if ($col === 'tipo_registro'): ?>
                                                 <?php if ($seccion_activa === 'site_vw'): ?>
                                                     <select name="f[tipo_registro]" id="field_tipo_registro" class="form-select form-select-sm" onchange="onTipoRegistroSiteChange(this.value)">
                                                         <option value="Switch">🔀 Switch</option>
                                                         <option value="Servidor">🖥️ Servidor</option>
                                                         <option value="NAS">💾 NAS (Almacenamiento en Red)</option>
                                                         <option value="Router / ISP">🌐 Router / ISP (Enlace Internet)</option>
                                                         <option value="Fortinet">🛡️ Fortinet (Firewall)</option>
                                                         <option value="Gateway UniFi">🗝️ Gateway UniFi</option>
                                                         <option value="Access Point">📡 Access Point (AP)</option>
                                                         <option value="UPS">⚡ UPS / Regulador SITE</option>
                                                     </select>
                                                 <?php elseif ($seccion_activa === 'moviles'): ?>
                                                     <select name="f[tipo_registro]" id="field_tipo_registro" class="form-select form-select-sm" onchange="onTipoRegistroMovilChange(this.value)">
                                                         <option value="Celular">📱 Celular</option>
                                                         <option value="Tableta">📱 Tableta</option>
                                                         <option value="Pantalla">📺 Pantalla</option>
                                                         <option value="Otro dispositivo">💻 Otro dispositivo</option>
                                                     </select>
                                                 <?php else: ?>
                                                     <select name="f[tipo_registro]" id="field_tipo_registro" class="form-select form-select-sm" onchange="onTipoRegistroChange(this.value)">
                                                         <option value="DVR/NVR">📹 Grabador DVR/NVR</option>
                                                         <option value="Cámara">📷 Cámara IP / Análoga</option>
                                                     </select>
                                                 <?php endif; ?>
                                             <?php elseif ($col === 'tipo_equipo'): ?>
                                                 <select name="f[tipo_equipo]" id="field_tipo_equipo" class="form-select form-select-sm">
                                                     <option value="Laptop">💻 Laptop</option>
                                                     <option value="Desktop">🖥️ Desktop</option>
                                                     <option value="All in One">🖥️ All in One</option>
                                                     <option value="VAS">🔌 VAS</option>
                                                     <option value="Otro">💻 Otro</option>
                                                 </select>
                                             <?php elseif ($col === 'tiene_plan_celular'): ?>
                                                 <select name="f[tiene_plan_celular]" id="field_tiene_plan_celular" class="form-select form-select-sm">
                                                     <option value="No">No</option>
                                                     <option value="Sí">Sí</option>
                                                 </select>
                                             <?php elseif ($col === 'estado_fisico'): ?>
                                                 <select name="f[estado_fisico]" id="field_estado_fisico" class="form-select form-select-sm">
                                                     <option value="Excelente">Excelente</option>
                                                     <option value="Bueno">Bueno</option>
                                                     <option value="Regular">Regular</option>
                                                     <option value="Con Detalles">Con Detalles</option>
                                                     <option value="Malo">Malo</option>
                                                 </select>
                                             <?php elseif ($col === 'estatus'): ?>
                                                 <select name="f[estatus]" id="field_estatus" class="form-select form-select-sm">
                                                     <option value="Activo">Activo</option>
                                                     <option value="En Reparación">En Reparación</option>
                                                     <option value="Respaldo">Respaldo</option>
                                                     <option value="Inactivo">Inactivo</option>
                                                 </select>
                                             <?php elseif ($col === 'subtipo_camara'): ?>
                                                <select name="f[subtipo_camara]" id="field_subtipo_camara" class="form-select form-select-sm" onchange="onSubtipoCamaraChange(this.value)">
                                                    <option value="IP">🌐 Cámara IP</option>
                                                    <option value="Análoga">📹 Cámara Análoga</option>
                                                </select>
                                             <?php elseif ($col === 'dvr_vinculado'): ?>
                                                <select name="f[dvr_vinculado]" id="field_dvr_vinculado" class="form-select form-select-sm">
                                                    <option value="">-- Seleccionar DVR/NVR al que pertenece --</option>
                                                    <?php foreach ($dvrsRegistrados as $dvrItem): ?>
                                                        <?php $nomDvr = !empty($dvrItem['nombre']) ? $dvrItem['nombre'] : ($dvrItem['dvr'] ?? ('DVR #' . $dvrItem['id'])); ?>
                                                        <option value="<?php echo htmlspecialchars($nomDvr); ?>">
                                                            📹 <?php echo htmlspecialchars($nomDvr); ?> (ID #<?php echo $dvrItem['id']; ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                             <?php elseif ($col === 'tipo_poe'): ?>
                                                 <select name="f[tipo_poe]" id="field_tipo_poe" class="form-select form-select-sm">
                                                     <option value="802.3af PoE (15.4W)">⚡ 802.3af PoE (15.4W) - Estándar</option>
                                                     <option value="802.3at PoE+ (30W)">⚡ 802.3at PoE+ (30W) - Alta Potencia</option>
                                                     <option value="802.3bt PoE++ (60W-90W)">⚡ 802.3bt PoE++ (Ultra)</option>
                                                     <option value="PoE Pasivo 24V">⚡ PoE Pasivo 24V</option>
                                                     <option value="PoE Pasivo 48V">⚡ PoE Pasivo 48V</option>
                                                     <option value="Adaptador DC (Sin PoE)">🔌 Adaptador de Corriente DC (Sin PoE)</option>
                                                 </select>
                                             <?php elseif ($col === 'estado' && $seccion_activa === 'telefonos_poe'): ?>
                                                 <select name="f[estado]" id="field_estado" class="form-select form-select-sm">
                                                     <option value="Activo">🟢 Activo / En Uso</option>
                                                     <option value="Almacén">📦 En Almacén / Stock</option>
                                                     <option value="En Reparación">🛠️ En Reparación</option>
                                                     <option value="Dañado">⚠️ Dañado / Inoperativo</option>
                                                     <option value="Baja">❌ Baja Definitiva</option>
                                                 </select>
                                             <?php elseif ($col === 'departamento' || $col === 'area'): ?>
                                                 <select name="f[<?php echo $col; ?>]" id="field_<?php echo $col; ?>" class="form-select form-select-sm">
                                                     <option value="">-- Seleccionar Área Registrada --</option>
                                                     <?php foreach ($areasDisponibles as $aOpt): ?>
                                                         <option value="<?php echo htmlspecialchars($aOpt); ?>"><?php echo htmlspecialchars($aOpt); ?></option>
                                                     <?php endforeach; ?>
                                                 </select>
                                             <?php elseif (in_array($seccion_activa, ['equipos_vw', 'equipos_corp', 'equipos_baja', 'moviles', 'telefonos_poe']) && ($col === 'usuario' || ($col === 'nombre' && $catId === 'cat_general' && $seccion_activa !== 'dvr'))): ?>
                                                <select name="f[<?php echo $col; ?>]" id="field_<?php echo $col; ?>" class="form-select form-select-sm" onchange="autoLlenarDatosUsuario(this)">
                                                    <option value="">-- Seleccionar Usuario Registrado --</option>
                                                    <?php foreach ($usuarios_sistema as $u): ?>
                                                        <option value="<?php echo htmlspecialchars($u['nombre']); ?>" 
                                                                data-area="<?php echo htmlspecialchars($u['area'] ?? ''); ?>" 
                                                                data-puesto="<?php echo htmlspecialchars($u['puesto'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($u['nombre']); ?><?php echo !empty($u['area']) ? ' ('.htmlspecialchars($u['area']).')' : ''; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    <option value="__custom__">✏️ Ingresar Nombre Manualmente...</option>
                                                </select>
                                            <?php elseif ($esFecha): ?>
                                                <input type="date" name="f[<?php echo $col; ?>]" id="field_<?php echo $col; ?>" class="form-control form-control-sm">
                                            <?php elseif ($esNum): ?>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" step="any" min="0" name="f[<?php echo $col; ?>]" id="field_<?php echo $col; ?>" class="form-control" placeholder="Solo números">
                                                    <?php if (!empty($unidad)): ?>
                                                        <span class="input-group-text bg-dark border-secondary text-info fw-semibold"><?php echo $unidad; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($esPass): ?>
                                                <div class="input-group input-group-sm">
                                                    <input type="password" name="f[<?php echo $col; ?>]" id="field_<?php echo $col; ?>" class="form-control" placeholder="<?php echo htmlspecialchars($label); ?>">
                                                    <button class="btn btn-outline-secondary" type="button" onclick="toggleInputPass('field_<?php echo $col; ?>', this)">
                                                        <i class="bi bi-eye-fill text-info"></i>
                                                    </button>
                                                </div>
                                            <?php elseif ($col === 'puerto_sw'): ?>
                                                <div class="p-2 rounded-3 border border-secondary border-opacity-50" style="background: rgba(15, 23, 42, 0.65);">
                                                    <label class="form-label micro text-secondary mb-1 d-block"><i class="bi bi-hdd-network text-info me-1"></i> Switch & Puerto de Red</label>
                                                    <div class="row g-1">
                                                        <div class="col-7">
                                                            <select id="combo_form_switch_select" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="alCambiarSwitchEnFormularioEquipos(this.value)">
                                                                <option value="">-- Seleccionar Switch --</option>
                                                                <?php if (!empty($switchesDisponiblesSite)): ?>
                                                                    <?php foreach ($switchesDisponiblesSite as $swOpt): ?>
                                                                        <?php 
                                                                            $swNomOpt = trim($swOpt['nombre_equipo'] ?: ($swOpt['tipo_registro'] . ' #' . $swOpt['id']));
                                                                            $swPuertosOpt = intval($swOpt['cantidad_puertos'] ?: 48);
                                                                        ?>
                                                                        <option value="<?php echo htmlspecialchars($swNomOpt); ?>" data-puertos="<?php echo $swPuertosOpt; ?>">
                                                                            🖧 <?php echo htmlspecialchars($swNomOpt . " ({$swPuertosOpt}P)"); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-5">
                                                            <select id="combo_form_puerto_select" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="alCambiarPuertoEnFormularioEquipos(this.value)">
                                                                <option value="">-- Puerto --</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="f[puerto_sw]" id="field_puerto_sw" value="">
                                                    <input type="hidden" name="f[switch_nombre]" id="field_switch_nombre" value="">
                                                    <div class="mt-1 d-flex justify-content-between align-items-center">
                                                        <small class="text-info font-monospace" style="font-size: 0.68rem;" id="preview_puerto_sw_text">Sin puerto asignado</small>
                                                        <button type="button" class="btn btn-link btn-sm text-secondary p-0 text-decoration-none micro" onclick="limpiarPuertoSwitchEnFormularioEquipos()">
                                                             <i class="bi bi-x-circle me-1"></i> Limpiar
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php elseif ($col === 'numero_nodo'): ?>
                                                <div class="p-2 rounded-3 border border-secondary border-opacity-50" style="background: rgba(15, 23, 42, 0.65);">
                                                    <label class="form-label micro text-secondary mb-1 d-block"><i class="bi bi-ethernet text-info me-1"></i> Nodo de Red RJ45</label>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <select id="combo_form_nodo_select" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="alCambiarNodoEnFormularioEquipos(this.value)">
                                                            <option value="">-- Seleccionar Nodo --</option>
                                                            <?php if (!empty($nodosDisponiblesRed)): ?>
                                                                <?php foreach ($nodosDisponiblesRed as $ndOpt): ?>
                                                                    <?php 
                                                                        $cCod = trim($ndOpt['codigo_nodo'] ?? '');
                                                                        $cUbic = trim($ndOpt['ubicacion'] ?? 'Sin Ubicación');
                                                                        $cSw = trim($ndOpt['switch_puerto'] ?? '');
                                                                        $cSwNom = trim($ndOpt['switch_nombre_asoc'] ?? '');
                                                                        $cSwPnum = intval($ndOpt['puerto_num_asoc'] ?? 0);
                                                                        $cPatch = trim($ndOpt['patch_panel'] ?? '');
                                                                    ?>
                                                                    <?php 
                                                                        $cExt = trim($ndOpt['telefono_extension'] ?? '');
                                                                    ?>
                                                                    <option value="<?php echo htmlspecialchars($cCod); ?>" 
                                                                            data-ubic="<?php echo htmlspecialchars($cUbic); ?>" 
                                                                            data-sw="<?php echo htmlspecialchars($cSw); ?>" 
                                                                            data-swnom="<?php echo htmlspecialchars($cSwNom); ?>" 
                                                                            data-swpnum="<?php echo $cSwPnum > 0 ? $cSwPnum : ''; ?>" 
                                                                            data-patch="<?php echo htmlspecialchars($cPatch); ?>"
                                                                            data-ext="<?php echo htmlspecialchars($cExt); ?>">
                                                                        🔌 Nodo <?php echo htmlspecialchars($cCod . " (" . $cUbic . ")" . (!empty($cExt) ? " [Ext. {$cExt}]" : "")); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                            <option value="__custom__">✏️ Ingresar Nodo Manual...</option>
                                                        </select>
                                                    </div>
                                                    <div id="box_custom_nodo_input" class="mt-1" style="display: none;">
                                                        <input type="text" id="custom_nodo_input_manual" class="form-control form-control-sm bg-dark text-info border-secondary" placeholder="Escribe el código de nodo..." oninput="alEscribirNodoManual(this.value)">
                                                    </div>
                                                    <input type="hidden" name="f[numero_nodo]" id="field_numero_nodo" value="">
                                                    <div class="mt-1 d-flex justify-content-between align-items-center">
                                                        <small class="text-info font-monospace" style="font-size: 0.68rem;" id="preview_nodo_text">Sin nodo asignado</small>
                                                        <button type="button" class="btn btn-link btn-sm text-secondary p-0 text-decoration-none micro" onclick="limpiarNodoEnFormularioEquipos()">
                                                            <i class="bi bi-x-circle me-1"></i> Limpiar
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <input type="text" name="f[<?php echo $col; ?>]" id="field_<?php echo $col; ?>" class="form-control form-control-sm" placeholder="<?php echo htmlspecialchars($label); ?>">
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php $isFirst = false; endforeach; ?>
                    </div>

                    <?php if ($seccion_activa === 'dvr'): ?>
                        <div class="row g-3 mt-3 pt-3 border-top border-secondary border-opacity-25" id="box_fotos_camara_form" style="display: none;">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-info mb-1"><i class="bi bi-camera-fill me-1"></i> Fotografía Física de la Cámara</label>
                                <input type="file" name="foto_camara_file" id="field_foto_camara_file" class="form-control form-control-sm" accept="image/*,.jpg,.jpeg,.png,.webp">
                                <small class="text-secondary" style="font-size: 0.72rem;">Sube la imagen del equipo/cámara física.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-primary mb-1"><i class="bi bi-camera-reels-fill me-1"></i> Fotografía de la Vista / Video</label>
                                <input type="file" name="foto_vista_file" id="field_foto_vista_file" class="form-control form-control-sm" accept="image/*,.jpg,.jpeg,.png,.webp">
                                <small class="text-secondary" style="font-size: 0.72rem;">Sube la captura de cómo se transmite el video.</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3 mt-3 pt-3 border-top border-secondary border-opacity-25" id="box_adjuntos_generales">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-info mb-1"><i class="bi bi-camera-fill me-1"></i> Fotografía Física del Dispositivo</label>
                                <input type="file" name="foto_camara_file" id="field_foto_camara_file" class="form-control form-control-sm" accept="image/*,.jpg,.jpeg,.png,.webp">
                                <small class="text-secondary" style="font-size: 0.72rem;">Sube una imagen o fotografía del equipo físico.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-success mb-1"><i class="bi bi-file-earmark-pdf-fill me-1"></i> Factura PDF (Opcional)</label>
                                <input type="file" name="factura_file" id="field_factura_file" class="form-control form-control-sm" accept=".pdf,image/*">
                                <small class="text-secondary" style="font-size: 0.72rem;">Sube el comprobante o archivo de factura PDF.</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4"><i class="bi bi-save me-1"></i> Guardar Registro Completo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL DE EXPEDIENTE COMPLETO (VISUALIZACIÓN TOTAL)  -->
<!-- =================================================== -->
<div class="modal fade" id="modalExpediente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold text-info mb-0"><i class="bi bi-eye-fill me-2"></i> Expediente Completo del Equipo</h5>
                    <small class="text-secondary" id="expediente_subtitulo">Ficha técnica y credenciales de sistemas</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Barra de Búsqueda del DATO QUE QUIERO en Expediente -->
                <div class="mb-4">
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-info"><i class="bi bi-search"></i></span>
                        <input type="text" id="busquedaExpediente" class="form-control border-secondary text-white" placeholder="🔍 Buscar dato específico (ej: RAM, Contraseña, Factura, MAC, Serie, Office)..." onkeyup="filtrarDatoExpediente()">
                    </div>
                </div>
                <div class="row g-3" id="contenedorExpedienteDetalles">
                    <!-- Inyectado dinámicamente con JS -->
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 flex-wrap" id="expediente_footer_docs">
                    <!-- Inyectado dinámicamente con JS (Cargar / Visualizar Factura y Responsiva) -->
                </div>
                <button type="button" class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Cerrar Expediente
                </button>
            </div>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- OVERLAY FLOTANTE PARA CARGAR FACTURA O RESPONSIVA  -->
<!-- =================================================== -->
<div id="customDocUploadOverlay" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 999999; background: rgba(3, 8, 20, 0.88); backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; padding: 20px;" onclick="cerrarModalSubirDoc()">
    <div style="background: #0a1b33; border: 2px solid #38bdf8; border-radius: 16px; max-width: 520px; width: 100%; padding: 24px; box-shadow: 0 15px 40px rgba(0,0,0,0.8);" onclick="event.stopPropagation()">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="subir_documento_equipo">
            <input type="hidden" name="registro_id" id="doc_registro_id" value="0">
            <input type="hidden" name="tipo_documento" id="doc_tipo_documento" value="factura">

            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-50">
                <h5 class="modal-title fw-bold text-info mb-0" id="modalSubirDocLabel">
                    <i class="bi bi-cloud-upload-fill me-2"></i> Cargar Documento
                </h5>
                <button type="button" class="btn-close btn-close-white" onclick="cerrarModalSubirDoc()"></button>
            </div>
            
            <p class="text-white mb-3 small" id="doc_instrucciones">
                Selecciona el archivo correspondiente a este equipo.
            </p>

            <div class="mb-4">
                <label class="form-label text-label-contrast small fw-bold">Seleccionar Archivo (PDF, Imagen, Word, Excel)</label>
                <input type="file" name="documento_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" required>
            </div>

            <div class="d-flex justify-content-end gap-2 border-top border-secondary border-opacity-50 pt-3">
                <button type="button" class="btn btn-secondary rounded-3 fw-bold" onclick="cerrarModalSubirDoc()">Cancelar</button>
                <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold">
                    <i class="bi bi-upload me-1"></i> Subir Documento
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL DE CONFIRMACIÓN PARA MOVER EQUIPO A BAJA      -->
<!-- =================================================== -->
<div class="modal fade" id="modalPasarBaja" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border: 1px solid rgba(239, 68, 68, 0.4);">
            <form method="POST">
                <input type="hidden" name="accion" value="pasar_baja">
                <input type="hidden" name="registro_id" id="baja_registro_id" value="0">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Confirmar Baja de Equipo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-white mb-3 fs-6">
                        ¿Estás seguro de que deseas enviar el equipo <strong id="baja_nombre_equipo" class="text-info"></strong> a la sección de <strong>Equipos Baja</strong>?
                    </p>
                    <div class="mb-2">
                        <label class="form-label text-warning small fw-bold">Motivo de la Baja (Por qué se da de baja) *</label>
                        <textarea name="motivo_baja" id="baja_motivo_texto" class="form-control" rows="3" placeholder="Escribe aquí la razón o motivo por el cual este equipo pasa a baja (ej: Daño irreversible en tarjeta madre, obsolescencia, sustitución por equipo nuevo)..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-3 px-4 fw-semibold"><i class="bi bi-arrow-down-circle-fill me-1"></i> Confirmar y Enviar a Baja</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL DE ADVERTENCIA CUANDO NO HAY DVR REGISTRADO   -->
<!-- =================================================== -->
<div class="modal fade" id="modalSinDvrWarning" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border: 1px solid rgba(245, 158, 11, 0.4);">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold text-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> Requiere DVR/NVR Previo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3 text-warning">
                    <i class="bi bi-camera-video-off fs-1 d-block mb-2 opacity-75"></i>
                </div>
                <h6 class="fw-bold text-white mb-2">No hay ningún Grabador DVR/NVR registrado</h6>
                <p class="text-secondary small mb-0">
                    Para registrar una <strong>Cámara IP / Análoga</strong>, primero debes registrar al menos un <strong>Grabador DVR/NVR</strong> al cual vincularla en el sistema.
                </p>
            </div>
            <div class="modal-footer border-secondary justify-content-center">
                <button type="button" class="btn btn-secondary rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary rounded-3 px-4 fw-semibold" data-bs-dismiss="modal" onclick="abrirModalNuevoDVR()">
                    <i class="bi bi-camera-video-fill me-1"></i> Registrar Grabador DVR/NVR Ahora
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleSidebarNavegacion() {
        const sidebar = document.getElementById('mainSidebarWrapper');
        const content = document.getElementById('mainContentWrapper');
        const btnTab = document.getElementById('btnShowSidebarTab');
        const iconBtn = document.getElementById('iconToggleSidebar');

        if (!sidebar || !content) return;

        const isHidden = (sidebar.style.display === 'none');

        if (isHidden) {
            sidebar.style.display = 'block';
            content.classList.remove('col-12');
            content.classList.add('col-md-9', 'col-lg-10');
            if (btnTab) btnTab.style.display = 'none';
            if (iconBtn) iconBtn.className = 'bi bi-chevron-left fs-6 text-white';
            localStorage.setItem('sidebar_collapsed', '0');
        } else {
            sidebar.style.display = 'none';
            content.classList.remove('col-md-9', 'col-lg-10');
            content.classList.add('col-12');
            if (btnTab) btnTab.style.display = 'block';
            if (iconBtn) iconBtn.className = 'bi bi-chevron-right fs-6 text-white';
            localStorage.setItem('sidebar_collapsed', '1');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (localStorage.getItem('sidebar_collapsed') === '1') {
            toggleSidebarNavegacion();
        }
    });

    const listadoDvrs = <?php echo json_encode($dvrsRegistrados ?? []); ?>;
    const currentSeccion = "<?php echo htmlspecialchars($seccion_activa); ?>";
    const CATEGORIAS_EXPEDIENTE = [
        { id: 'cat_general', titulo: '👤 Datos de Asignación y Dispositivo', icono: 'bi-person-badge-fill', campos: ['tipo_registro', 'subtipo_dispositivo', 'fabricante', 'dvr_vinculado', 'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'estatus', 'tipo_equipo', 'expediente_completo', 'area', 'nombre', 'usuario_equipo', 'usuario_equipo_dominio', 'funcion_servicio', 'ambiente', 'criticidad', 'tipo_servidor', 'ubicacion', 'fecha_asignacion', 'estado_fisico', 'extension', 'observaciones'] },
        { id: 'cat_specs', titulo: '💻 Especificaciones Técnicas y Hardware', icono: 'bi-cpu-fill', campos: ['marca', 'modelo', 'modelo_exacto', 'serie', 'imei_1', 'imei_2', 'almacenamiento', 'ram', 'color', 'sistema_op', 'mac', 'tamano_pantalla', 'resolucion', 'especificaciones', 'accesorios', 'dominio', 'dd', 'procesador', 'ghz', 'mac_wifi', 'mac_ethernet', 'rom', 'pulgadas', 'monitor', 'dvr', 'camaras', 'numero_serie', 'cam_totales_ip', 'disponibles_ip', 'cam_totales_analogicas', 'disponibles', 'capacidad_actual', 'capacidad_discos', 'numero_discos', 'tiene_actual', 'ip', 'tipo_poe', 'cantidad_puertos', 'velocidad_puertos', 'tipo_switch', 'firmware_version', 'posicion_rack', 'cantidad_discos', 'bahias_nas', 'capacidad_disco_ind', 'capacidad_disponible', 'config_raid', 'tipo_discos', 'protocolos_nas', 'unifi_os_ver', 'controller_ver', 'velocidad_enlace', 'ssids', 'vlans', 'poe', 'controlador_ap', 'capacidad_va', 'capacidad_w', 'tipo_ups', 'voltaje_entrada', 'voltaje_salida', 'cant_baterias', 'specs_baterias', 'fecha_bateria', 'autonomia'] },
        { id: 'cat_credenciales', titulo: '🔑 Credenciales y Accesos', icono: 'bi-key-fill', campos: ['logmein', 'contrasena', 'office', 'serie_office', 'clave_candado', 'gds', 'remoto', 'usuario_gds', 'contrasena_remoto', 'contrasena_gds', 'usuario_dvr', 'contrasena_dvr', 'contrasena_camara', 'licencia', 'serie_licencia', 'serie_equipo', 'usuario_impresora', 'contrasena_impresora', 'usuario_impresora_web', 'contrasena_impresora_web', 'contrasena_web', 'tipo_licencia'] },
        { id: 'cat_telecom', titulo: '📱 Línea, Plan e ISP', icono: 'bi-diagram-2-fill', campos: ['numero_telefonico', 'portabilidad', 'tiene_plan_celular', 'vencimiento_plan', 'proveedor_plan', 'numero_contrato_plan', 'correo', 'correo_oficial_planta', 'extension', 'puerto_patch_panel', 'puerto_sw', 'switch_nombre', 'numero_nodo', 'power_pb', 'contrasena_pb', 'poc', 'contrasena_poc', 'msqp', 'contrasena_msqp', 'grp', 'contrasena_grp', 'etka', 'contrasena_etka', 'mail', 'celular', 'pantalla', 'tablet', 'proveedor', 'contrato', 'tipo_enlace', 'ancho_banda', 'simetria_enlace', 'tipo_conexion', 'numero_contrato', 'numero_cuenta', 'circuit_id', 'soporte_contacto', 'ip_publica', 'ip_local'] },
        { id: 'cat_garantia', titulo: '🧾 Garantía y Facturación', icono: 'bi-receipt', campos: ['costo', 'folio_factura', 'fecha_compra', 'fecha_adquisicion', 'inicio_garantia', 'fin_garantia', 'garantia2', 'garantia', 'renovacion_equipo', 'compra', 'antivirus', 'dia_respaldo', 'hora_respaldo', 'no_break', 'modelo_nobreak', 'serie_nobreak', 'factura', 'plan', 'motivo_baja', 'dias_grabacion'] }
    ];

    const ETIQUETAS_CUSTOM = {
        'tipo_licencia': 'TIPO DE LICENCIA',
        'portabilidad': 'PORTABILIDAD',
        'extension': 'EXTENSIÓN TELEFÓNICA',
        'tipo_poe': 'ESTÁNDAR POE (ALIMENTACIÓN)',
        'servidor_sip': 'SERVIDOR SIP / PBX CONMUTADOR',
        'contrasena_web': 'CONTRASEÑA ADMIN WEB TELÉFONO',
        'vlan': 'VLAN DE VOZ',
        'marca': 'MARCA',
        'modelo_exacto': 'MODELO EXACTO',
        'ubicacion': 'UBICACIÓN FÍSICA',
        'fecha_adquisicion': 'FECHA DE ADQUISICIÓN',
        'contrato': 'CONTRATO',
        'usuario_impresora': 'USUARIO IMPRESORA',
        'contrasena_impresora': 'CONTRASEÑA IMPRESORA',
        'usuario_impresora_web': 'USUARIO IMPRESORA WEB',
        'contrasena_impresora_web': 'CONTRASEÑA IMPRESORA WEB',
        'imei_1': 'IMEI 1',
        'imei_1': 'IMEI 1',
        'imei_2': 'IMEI 2',
        'numero_telefonico': 'NÚMERO TELEFÓNICO / SIM',
        'color': 'COLOR',
        'accesorios': 'ACCESORIOS ENTREGADOS',
        'fecha_asignacion': 'FECHA DE ASIGNACIÓN',
        'tiene_plan_celular': '¿TIENE PLAN CELULAR?',
        'vencimiento_plan': 'FECHA VENCIMIENTO PLAN',
        'proveedor_plan': 'PROVEEDOR DEL PLAN',
        'numero_contrato_plan': 'CONTRATO / LÍNEA PLAN',
        'estado_fisico': 'ESTADO FÍSICO',
        'tamano_pantalla': 'TAMAÑO EN PULGADAS',
        'resolucion': 'RESOLUCIÓN',
        'subtipo_dispositivo': 'TIPO DE DISPOSITIVO',
        'especificaciones': 'ESPECIFICACIONES PRINCIPALES',
        'observaciones': 'OBSERVACIONES',
        'estatus': 'ESTADO FUNCIONAL',
        'costo': 'COSTO DEL EQUIPO',
        'costo_equipo': 'COSTO DEL EQUIPO',
        'dd': 'DISCO DURO',
        'serie': 'NÚMERO DE SERIE EQUIPO',
        'contrasena': 'CONTRASEÑA EQUIPO',
        'contrasena_remoto': 'CONTRASEÑA REMOTO',
        'contrasena_gds': 'CONTRASEÑA GDS',
        'correo_oficial_planta': 'CORREO OFICIAL PLANTA',
        'compra': 'TIPO DE COMPRA',
        'motivo_baja': 'MOTIVO DE LA BAJA',
        'dvr': 'DVR/NVR',
        'tipo_registro': 'TIPO DE REGISTRO',
        'dvr_vinculado': 'DVR/NVR AL QUE PERTENECE',
        'numero_discos': 'NÚMERO DE DISCOS',
        'tiene_actual': 'NÚMERO DE DISCOS',
        'dias_grabacion': 'DÍAS DE GRABACIÓN',
        'usuario_dvr': 'USUARIO DVR/NVR',
        'contrasena_dvr': 'CONTRASEÑA DVR/NVR',
        'contrasena_camara': 'CONTRASEÑA CÁMARA',
        'puerto_patch_panel': 'PUERTO PATCH PANEL',
        'puerto_sw': 'PUERTO SW (SWITCH)',
        'numero_nodo': 'NÚMERO DE NODO',
        'folio_factura': 'FOLIO FACTURA',
        'fabricante': 'FABRICANTE',
        'mac_ethernet': 'MAC ADDRESS ETHERNET',
        'mac_wifi': 'MAC ADDRESS WI-FI',
        'cantidad_puertos': 'CANTIDAD DE PUERTOS',
        'velocidad_puertos': 'VELOCIDAD DE PUERTOS',
        'tipo_switch': 'TIPO DE SWITCH',
        'firmware_version': 'VERSIÓN FIRMWARE / OS',
        'posicion_rack': 'RACK / POSICIÓN U',
        'sistema_op': 'SISTEMA OPERATIVO / FIRMWARE',
        'procesador': 'PROCESADOR / CPU',
        'ram': 'MEMORIA RAM',
        'almacenamiento': 'ALMACENAMIENTO TOTAL',
        'cantidad_discos': 'CANTIDAD / TIPO DISCOS',
        'tipo_servidor': 'FÍSICO / VIRTUAL',
        'funcion_servicio': 'FUNCIÓN / SERVICIO',
        'ambiente': 'AMBIENTE',
        'criticidad': 'CRITICIDAD',
        'bahias_nas': 'CANTIDAD DE BAHÍAS',
        'capacidad_disco_ind': 'CAPACIDAD POR DISCO',
        'capacidad_disponible': 'CAPACIDAD DISPONIBLE',
        'config_raid': 'CONFIGURACIÓN RAID',
        'tipo_discos': 'TIPO DE DISCOS (HDD/SSD)',
        'protocolos_nas': 'PROTOCOLOS / SERVICIOS',
        'proveedor': 'PROVEEDOR / ISP',
        'tipo_enlace': 'TIPO DE ENLACE',
        'ancho_banda': 'ANCHO DE BANDA CONTRATADO',
        'simetria_enlace': 'SIMETRÍA DE ENLACE',
        'tipo_conexion': 'TIPO DE CONEXIÓN',
        'numero_contrato': 'NÚMERO DE CONTRATO',
        'numero_cuenta': 'NÚMERO DE CUENTA / CLIENTE',
        'circuit_id': 'CIRCUIT ID / ID ENLACE',
        'soporte_contacto': 'TELÉFONO / CORREO SOPORTE',
        'ip_publica': 'WAN / IP PÚBLICA',
        'ip_local': 'IP LOCAL / ADMIN',
        'unifi_os_ver': 'VERSIÓN UNIFI OS',
        'controller_ver': 'VERSIÓN CONTROLADOR UNIFI',
        'velocidad_enlace': 'VELOCIDAD DEL ENLACE',
        'ssids': 'SSID(S)',
        'vlans': 'VLANS',
        'poe': 'POE (SÍ / NO)',
        'controlador_ap': 'CONTROLADOR AP',
        'capacidad_va': 'CAPACIDAD (VA / KVA)',
        'capacidad_w': 'CAPACIDAD (W)',
        'tipo_ups': 'TIPO DE UPS',
        'voltaje_entrada': 'VOLTAJE ENTRADA',
        'voltaje_salida': 'VOLTAJE SALIDA',
        'cant_baterias': 'CANTIDAD BATERÍAS',
        'specs_baterias': 'CAPACIDAD / VOLTAJE BATERÍAS',
        'fecha_bateria': 'FECHA CAMBIO BATERÍA',
        'autonomia': 'AUTONOMÍA ESTIMADA'
    };

    function abrirModalBaja(id, nombre) {
        document.getElementById('baja_registro_id').value = id;
        document.getElementById('baja_nombre_equipo').textContent = nombre;
        document.getElementById('baja_motivo_texto').value = '';
        const modal = new bootstrap.Modal(document.getElementById('modalPasarBaja'));
        modal.show();
    }

    function getFieldLabel(key) {
        const k = key.toLowerCase();
        if (ETIQUETAS_CUSTOM[k]) return ETIQUETAS_CUSTOM[k];
        return key.replace(/_/g, ' ').toUpperCase();
    }

    function togglePassSpan(id, btn) {
        const el = document.getElementById(id);
        if (!el) return;
        const realPass = el.getAttribute('data-pass');
        const isHidden = (el.textContent === '••••••••');
        if (isHidden) {
            el.textContent = realPass;
            btn.innerHTML = '<i class="bi bi-eye-slash-fill text-warning"></i>';
        } else {
            el.textContent = '••••••••';
            btn.innerHTML = '<i class="bi bi-eye-fill text-info"></i>';
        }
    }

    function toggleInputPass(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<i class="bi bi-eye-slash-fill text-warning"></i>';
        } else {
            input.type = 'password';
            btn.innerHTML = '<i class="bi bi-eye-fill text-info"></i>';
        }
    }

    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function formatUnitValue(key, val) {
        if (val === null || val === undefined || val === '') return '<span class="text-muted opacity-50">No especificado</span>';
        let cleanVal = String(val).trim();
        if (!cleanVal || cleanVal === 'No especificado' || cleanVal === 'N/A') return '<span class="text-muted opacity-50">No especificado</span>';

        const k = key.toLowerCase();
        if ((k === 'costo' || k === 'costo_equipo') && !cleanVal.includes('$')) {
            let num = parseFloat(cleanVal.replace(/,/g, ''));
            if (!isNaN(num)) {
                return '$' + num.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }
        if (k === 'ram' && !/gb|mb/i.test(cleanVal) && !isNaN(cleanVal)) return escapeHtml(cleanVal) + ' GB';
        if (k === 'ghz' && !/ghz/i.test(cleanVal) && !isNaN(cleanVal)) return escapeHtml(cleanVal) + ' GHz';
        if (k === 'pulgadas' && !/"|pulg/i.test(cleanVal) && !isNaN(cleanVal)) return escapeHtml(cleanVal) + '"';
        if ((k === 'dd' || k === 'almacenamiento') && !/gb|tb|mb/i.test(cleanVal) && !isNaN(cleanVal)) {
            let num = parseFloat(cleanVal);
            return escapeHtml(cleanVal) + (num <= 32 ? ' TB' : ' GB');
        }
        return escapeHtml(cleanVal);
    }

    function autoLlenarDatosUsuario(selectEl) {
        if (!selectEl) return;
        let selectedVal = selectEl.value;

        if (selectedVal === '__custom__') {
            const customName = prompt("Ingresa el nombre del usuario:");
            if (customName && customName.trim() !== '') {
                const cleanName = customName.trim();
                const newOpt = new Option(cleanName + ' (Manual)', cleanName, true, true);
                selectEl.add(newOpt);
                selectedVal = cleanName;
            } else {
                selectEl.selectedIndex = 0;
                return;
            }
        }

        const selectedOpt = selectEl.options[selectEl.selectedIndex];
        if (!selectedOpt) return;

        const area = selectedOpt.getAttribute('data-area');
        const puesto = selectedOpt.getAttribute('data-puesto');

        // Auto-llenar Departamento / Área si existe el dato
        const inputDept = document.getElementById('field_departamento') || document.getElementById('field_area');
        if (inputDept && area && area.trim() !== '') {
            inputDept.value = area;
        }

        // Auto-llenar Puesto si existe el dato
        // Auto-llenar Puesto si existe el dato
        const inputPuesto = document.getElementById('field_puesto');
        if (inputPuesto && puesto && puesto.trim() !== '') {
            inputPuesto.value = puesto;
        }

        // Auto-llenar Nombre de usuario si existe otro campo
        const inputNombre = document.getElementById('field_nombre');
        if (inputNombre && inputNombre !== selectEl && selectedVal && selectedVal.trim() !== '') {
            inputNombre.value = selectedVal;
        }
        const inputUsuario = document.getElementById('field_usuario');
        if (inputUsuario && inputUsuario !== selectEl && selectedVal && selectedVal.trim() !== '') {
            inputUsuario.value = selectedVal;
        }
    }

    // =========================================================================
    // GESTIÓN Y SINCRONIZACIÓN BIDIRECCIONAL: NODOS <--> SWITCH & PUERTOS
    // =========================================================================
    window.switchesDisponiblesSite = <?php echo json_encode($switchesDisponiblesSite ?? []); ?>;
    window.nodosDisponiblesRed = <?php echo json_encode($nodosDisponiblesRed ?? []); ?>;
    window.puertosSwitchMapa = <?php echo json_encode($puertosSwitchMapa ?? []); ?>;
    window._isSyncingNodoSwitch = false;

    /**
     * Al cambiar el Switch en el formulario de equipos
     */
    function alCambiarSwitchEnFormularioEquipos(swNombre, defaultPuerto, skipReverseSync) {
        const selPuerto = document.getElementById('combo_form_puerto_select');
        const selSwitch = document.getElementById('combo_form_switch_select');
        const hiddenPuerto = document.getElementById('field_puerto_sw');
        const hiddenSwitch = document.getElementById('field_switch_nombre');
        const previewTxt = document.getElementById('preview_puerto_sw_text');

        if (!selPuerto) return;
        selPuerto.innerHTML = '<option value="">-- Puerto --</option>';

        if (!swNombre) {
            if (hiddenPuerto) hiddenPuerto.value = '';
            if (hiddenSwitch) hiddenSwitch.value = '';
            if (previewTxt) previewTxt.textContent = 'Sin puerto asignado';
            return;
        }

        if (hiddenSwitch) hiddenSwitch.value = swNombre;

        let cantPuertos = 48;
        if (selSwitch && selSwitch.selectedOptions.length > 0) {
            const pAttr = selSwitch.selectedOptions[0].getAttribute('data-puertos');
            if (pAttr) cantPuertos = parseInt(pAttr, 10) || 48;
        } else if (window.switchesDisponiblesSite) {
            const found = window.switchesDisponiblesSite.find(s => (s.nombre_equipo || '').trim() === swNombre.trim());
            if (found && found.cantidad_puertos) {
                cantPuertos = parseInt(found.cantidad_puertos, 10) || 48;
            }
        }

        const cleanDefaultNum = defaultPuerto ? String(defaultPuerto).replace(/[^0-9]/g, '') : '';
        let portFound = false;

        for (let i = 1; i <= cantPuertos; i++) {
            const pVal = 'Puerto ' + i;
            const opt = document.createElement('option');
            opt.value = pVal;
            opt.textContent = pVal;
            if (cleanDefaultNum && parseInt(cleanDefaultNum, 10) === i) {
                opt.selected = true;
                portFound = true;
            }
            selPuerto.appendChild(opt);
        }

        const selectedP = selPuerto.value || (portFound ? ('Puerto ' + cleanDefaultNum) : '');
        if (selectedP) {
            selPuerto.value = selectedP;
            if (hiddenPuerto) hiddenPuerto.value = swNombre + ' / ' + selectedP;
            if (previewTxt) previewTxt.textContent = swNombre + ' / ' + selectedP;
            if (!skipReverseSync) {
                verificarNodoAsociadoAPuerto(swNombre, selectedP);
            }
        } else {
            if (hiddenPuerto) hiddenPuerto.value = swNombre;
            if (previewTxt) previewTxt.textContent = swNombre + ' (Sin puerto)';
        }
    }

    /**
     * Al cambiar el Puerto en el formulario de equipos
     */
    function alCambiarPuertoEnFormularioEquipos(puertoVal) {
        const selSwitch = document.getElementById('combo_form_switch_select');
        const hiddenPuerto = document.getElementById('field_puerto_sw');
        const hiddenSwitch = document.getElementById('field_switch_nombre');
        const previewTxt = document.getElementById('preview_puerto_sw_text');
        const swNom = selSwitch ? selSwitch.value : '';

        if (hiddenSwitch) hiddenSwitch.value = swNom;

        if (hiddenPuerto) {
            if (swNom && puertoVal) {
                hiddenPuerto.value = swNom + ' / ' + puertoVal;
            } else if (swNom) {
                hiddenPuerto.value = swNom;
            } else {
                hiddenPuerto.value = puertoVal;
            }
        }
        if (previewTxt) {
            previewTxt.textContent = hiddenPuerto && hiddenPuerto.value ? hiddenPuerto.value : 'Sin puerto asignado';
        }

        if (swNom && puertoVal) {
            verificarNodoAsociadoAPuerto(swNom, puertoVal);
        }
    }

    /**
     * Comprueba si el Switch y Puerto seleccionados ya tienen un Nodo asignado en la infraestructura (VICEVERSA)
     * Si lo tienen -> lo llena automáticamente en el combo de nodos.
     * Si no lo tienen -> se deja para selección o llenado manual.
     */
    function verificarNodoAsociadoAPuerto(swNom, puertoVal) {
        if (window._isSyncingNodoSwitch) return;
        if (!swNom || !puertoVal) return;

        const pNum = parseInt(String(puertoVal).replace(/[^0-9]/g, ''), 10);
        if (!pNum || isNaN(pNum)) return;

        let matchedNodo = '';
        let matchedPatch = '';
        const swClean = swNom.trim().toLowerCase();

        // 1. Buscar en puertosSwitchMapa (matriz de puertos física)
        if (window.puertosSwitchMapa && Array.isArray(window.puertosSwitchMapa)) {
            const foundP = window.puertosSwitchMapa.find(p => {
                const pSw = (p.switch_nombre || '').trim().toLowerCase();
                const pPort = parseInt(p.puerto_numero, 10);
                return (pSw === swClean || pSw.includes(swClean) || swClean.includes(pSw)) && pPort === pNum;
            });
            if (foundP && foundP.nodo_codigo && String(foundP.nodo_codigo).trim() !== '') {
                matchedNodo = String(foundP.nodo_codigo).trim();
            }
        }

        // 2. Si no se encontró, buscar en nodosDisponiblesRed
        if (!matchedNodo && window.nodosDisponiblesRed && Array.isArray(window.nodosDisponiblesRed)) {
            const foundNd = window.nodosDisponiblesRed.find(nd => {
                if (nd.switch_nombre_asoc && nd.puerto_num_asoc) {
                    const ndSw = nd.switch_nombre_asoc.trim().toLowerCase();
                    return (ndSw === swClean || ndSw.includes(swClean) || swClean.includes(ndSw)) && parseInt(nd.puerto_num_asoc, 10) === pNum;
                }
                const ndSwFull = (nd.switch_puerto || '').trim().toLowerCase();
                if (ndSwFull.includes(swClean) || swClean.includes(ndSwFull.split('/')[0].trim())) {
                    const m = ndSwFull.match(/(\d+)/);
                    if (m && parseInt(m[1], 10) === pNum) return true;
                }
                return false;
            });
            if (foundNd) {
                matchedNodo = String(foundNd.codigo_nodo).trim();
                if (foundNd.patch_panel) matchedPatch = foundNd.patch_panel;
            }
        }

        // Si este puerto YA tiene un nodo asignado, llenarlo en automático
        if (matchedNodo) {
            seleccionarNodoAutomatico(matchedNodo, matchedPatch);
        }
        // Si NO tiene nodo asignado: se permite llenarlo manual sin borrar nada
    }

    /**
     * Selecciona y refleja el nodo automáticamente en la interfaz
     */
    function seleccionarNodoAutomatico(nodoCod, patchVal) {
        window._isSyncingNodoSwitch = true;
        try {
            const selNodo = document.getElementById('combo_form_nodo_select');
            const hiddenNodo = document.getElementById('field_numero_nodo');
            const previewNodo = document.getElementById('preview_nodo_text');
            const boxCustom = document.getElementById('box_custom_nodo_input');
            const inpCustom = document.getElementById('custom_nodo_input_manual');
            const inpPatch = document.getElementById('field_puerto_patch_panel');

            if (!selNodo) return;

            const cleanCod = String(nodoCod).trim();
            let found = false;

            for (let i = 0; i < selNodo.options.length; i++) {
                if (selNodo.options[i].value === cleanCod || String(selNodo.options[i].value).trim().toLowerCase() === cleanCod.toLowerCase()) {
                    selNodo.selectedIndex = i;
                    found = true;
                    if (!patchVal) {
                        patchVal = selNodo.options[i].getAttribute('data-patch') || '';
                    }
                    break;
                }
            }

            if (hiddenNodo) hiddenNodo.value = cleanCod;
            if (previewNodo) previewNodo.textContent = 'Nodo: ' + cleanCod;

            if (found) {
                if (boxCustom) boxCustom.style.display = 'none';
            } else {
                selNodo.value = '__custom__';
                if (boxCustom) boxCustom.style.display = 'block';
                if (inpCustom) inpCustom.value = cleanCod;
            }

            if (inpPatch && patchVal && (!inpPatch.value || inpPatch.value.trim() === '')) {
                inpPatch.value = patchVal;
            }
        } finally {
            window._isSyncingNodoSwitch = false;
        }
    }

    /**
     * Limpia la selección de switch y puerto
     */
    function limpiarPuertoSwitchEnFormularioEquipos() {
        const selSwitch = document.getElementById('combo_form_switch_select');
        const selPuerto = document.getElementById('combo_form_puerto_select');
        const hiddenPuerto = document.getElementById('field_puerto_sw');
        const hiddenSwitch = document.getElementById('field_switch_nombre');
        const previewTxt = document.getElementById('preview_puerto_sw_text');

        if (selSwitch) selSwitch.selectedIndex = 0;
        if (selPuerto) selPuerto.innerHTML = '<option value="">-- Puerto --</option>';
        if (hiddenPuerto) hiddenPuerto.value = '';
        if (hiddenSwitch) hiddenSwitch.value = '';
        if (previewTxt) previewTxt.textContent = 'Sin puerto asignado';
    }

    /**
     * Sincroniza combos al cargar o editar un equipo
     */
    function sincronizarCombosSwitchFormulario(puertoSwVal, switchNomVal) {
        const selSwitch = document.getElementById('combo_form_switch_select');
        const selPuerto = document.getElementById('combo_form_puerto_select');
        const hiddenPuerto = document.getElementById('field_puerto_sw');
        const hiddenSwitch = document.getElementById('field_switch_nombre');
        const previewTxt = document.getElementById('preview_puerto_sw_text');

        if (!selSwitch || !selPuerto) return;

        if (!puertoSwVal && !switchNomVal) {
            limpiarPuertoSwitchEnFormularioEquipos();
            return;
        }

        let rawVal = String(puertoSwVal || '').trim();
        let swName = String(switchNomVal || '').trim();
        let portNum = '';

        if (rawVal.includes('/')) {
            const parts = rawVal.split('/');
            swName = parts[0].trim();
            portNum = parts[1].trim();
        } else if (rawVal.includes('-')) {
            const parts = rawVal.split('-');
            swName = parts[0].trim();
            portNum = parts[1].trim();
        } else {
            const m = rawVal.match(/(\d+)/);
            if (m) portNum = 'Puerto ' + m[1];
        }

        if (!swName && switchNomVal) {
            swName = switchNomVal.trim();
        }

        let swFound = false;
        if (swName) {
            for (let i = 0; i < selSwitch.options.length; i++) {
                const optVal = selSwitch.options[i].value.trim().toLowerCase();
                if (optVal === swName.toLowerCase() || optVal.includes(swName.toLowerCase()) || swName.toLowerCase().includes(optVal)) {
                    selSwitch.selectedIndex = i;
                    swFound = true;
                    swName = selSwitch.options[i].value;
                    break;
                }
            }
        }

        if (swFound) {
            alCambiarSwitchEnFormularioEquipos(swName, portNum, true);
        } else {
            if (hiddenPuerto) hiddenPuerto.value = rawVal;
            if (hiddenSwitch) hiddenSwitch.value = swName;
            if (previewTxt) previewTxt.textContent = rawVal || 'Sin asignar';
        }
    }

    /**
     * Al cambiar el Nodo en el formulario de equipos
     * Si ya tiene Switch y Puerto asignado -> se llena en automático.
     * Si no tiene -> se deja para llenado manual.
     */
    function alCambiarNodoEnFormularioEquipos(nodoCod) {
        if (window._isSyncingNodoSwitch) return;

        const selNodo = document.getElementById('combo_form_nodo_select');
        const hiddenNodo = document.getElementById('field_numero_nodo');
        const boxCustom = document.getElementById('box_custom_nodo_input');
        const inpCustom = document.getElementById('custom_nodo_input_manual');
        const previewTxt = document.getElementById('preview_nodo_text');

        if (nodoCod === '__custom__') {
            if (boxCustom) boxCustom.style.display = 'block';
            if (inpCustom) {
                inpCustom.focus();
                if (hiddenNodo) hiddenNodo.value = inpCustom.value.trim();
                if (previewTxt) previewTxt.textContent = inpCustom.value.trim() ? ('Nodo: ' + inpCustom.value.trim()) : 'Nodo Manual';
            }
            return;
        }

        if (boxCustom) boxCustom.style.display = 'none';

        if (!nodoCod) {
            if (hiddenNodo) hiddenNodo.value = '';
            if (previewTxt) previewTxt.textContent = 'Sin nodo asignado';
            return;
        }

        if (hiddenNodo) hiddenNodo.value = nodoCod;
        if (previewTxt) previewTxt.textContent = 'Nodo: ' + nodoCod;

        // Buscar si este nodo tiene Switch y Puerto asignados
        let swAsocNom = '';
        let swAsocPort = '';
        let patchAsoc = '';

        if (selNodo && selNodo.selectedOptions.length > 0) {
            const opt = selNodo.selectedOptions[0];
            swAsocNom = opt.getAttribute('data-swnom') || '';
            swAsocPort = opt.getAttribute('data-swpnum') || '';
            patchAsoc = opt.getAttribute('data-patch') || '';
            if (!swAsocNom) {
                const rawSw = opt.getAttribute('data-sw') || '';
                if (rawSw) {
                    if (rawSw.includes('/')) {
                        const p = rawSw.split('/');
                        swAsocNom = p[0].trim();
                        const m = p[1].match(/(\d+)/);
                        if (m) swAsocPort = m[1];
                    } else {
                        swAsocNom = rawSw.trim();
                    }
                }
            }
        }

        // Si no se obtuvo de los atributos, buscar en window.nodosDisponiblesRed
        if ((!swAsocNom || !swAsocPort) && window.nodosDisponiblesRed) {
            const foundNd = window.nodosDisponiblesRed.find(nd => String(nd.codigo_nodo).trim().toLowerCase() === String(nodoCod).trim().toLowerCase());
            if (foundNd) {
                if (foundNd.switch_nombre_asoc) swAsocNom = foundNd.switch_nombre_asoc;
                if (foundNd.puerto_num_asoc) swAsocPort = String(foundNd.puerto_num_asoc);
                if (!patchAsoc && foundNd.patch_panel) patchAsoc = foundNd.patch_panel;
                if (!swAsocNom && foundNd.switch_puerto) {
                    if (foundNd.switch_puerto.includes('/')) {
                        const p = foundNd.switch_puerto.split('/');
                        swAsocNom = p[0].trim();
                        const m = p[1].match(/(\d+)/);
                        if (m) swAsocPort = m[1];
                    } else {
                        swAsocNom = foundNd.switch_puerto.trim();
                    }
                }
            }
        }

        // Si aún no, buscar en window.puertosSwitchMapa
        if ((!swAsocNom || !swAsocPort) && window.puertosSwitchMapa) {
            const foundP = window.puertosSwitchMapa.find(p => String(p.nodo_codigo).trim().toLowerCase() === String(nodoCod).trim().toLowerCase());
            if (foundP) {
                if (foundP.switch_nombre) swAsocNom = foundP.switch_nombre;
                if (foundP.puerto_numero) swAsocPort = String(foundP.puerto_numero);
            }
        }

        // Auto-llenar Patch Panel si existe y está vacío
        if (patchAsoc) {
            const inpPatch = document.getElementById('field_puerto_patch_panel');
            if (inpPatch && (!inpPatch.value || inpPatch.value.trim() === '')) {
                inpPatch.value = patchAsoc;
            }
        }

        // Si este nodo ya tiene asignado un Teléfono PoE, reflejar su extensión solamente
        const extAsoc = (selNodo && selNodo.selectedOptions.length > 0) ? (selNodo.selectedOptions[0].getAttribute('data-ext') || '') : '';
        const inpExt = document.getElementById('field_extension');
        if (inpExt && currentSeccion !== 'telefonos_poe') {
            if (extAsoc) {
                inpExt.value = extAsoc;
            }
        }

        // Si TIENE Switch y Puerto asignado, llenarlo en automático
        if (swAsocNom && swAsocPort) {
            seleccionarSwitchYPuertoAutomatico(swAsocNom, swAsocPort);
        }
        // Si NO tiene Switch y Puerto asignado: se deja libre para llenado manual sin borrar nada
    }

    /**
     * Selecciona y refleja el switch y puerto automáticamente en la interfaz
     */
    function seleccionarSwitchYPuertoAutomatico(swNom, portNum) {
        window._isSyncingNodoSwitch = true;
        try {
            const selSwitch = document.getElementById('combo_form_switch_select');
            const selPuerto = document.getElementById('combo_form_puerto_select');
            const hiddenPuerto = document.getElementById('field_puerto_sw');
            const hiddenSwitch = document.getElementById('field_switch_nombre');
            const previewTxt = document.getElementById('preview_puerto_sw_text');

            if (!selSwitch || !selPuerto) return;

            const cleanSwTarget = String(swNom).trim().toLowerCase();
            let swMatchedValue = '';
            let swMatchedIndex = -1;

            for (let i = 0; i < selSwitch.options.length; i++) {
                const optVal = selSwitch.options[i].value.trim().toLowerCase();
                if (!optVal) continue;
                if (optVal === cleanSwTarget || optVal.includes(cleanSwTarget) || cleanSwTarget.includes(optVal)) {
                    swMatchedValue = selSwitch.options[i].value;
                    swMatchedIndex = i;
                    break;
                }
            }

            if (swMatchedIndex >= 0) {
                selSwitch.selectedIndex = swMatchedIndex;
                const pTargetNum = String(portNum).replace(/[^0-9]/g, '');
                alCambiarSwitchEnFormularioEquipos(swMatchedValue, 'Puerto ' + pTargetNum, true);
                if (previewTxt) {
                    previewTxt.textContent = swMatchedValue + ' / Puerto ' + pTargetNum;
                }
            } else {
                if (hiddenSwitch) hiddenSwitch.value = swNom;
                const pTargetNum = String(portNum).replace(/[^0-9]/g, '');
                const valFull = swNom + ' / Puerto ' + pTargetNum;
                if (hiddenPuerto) hiddenPuerto.value = valFull;
                if (previewTxt) previewTxt.textContent = valFull;
            }
        } finally {
            window._isSyncingNodoSwitch = false;
        }
    }

    function alEscribirNodoManual(val) {
        const hiddenNodo = document.getElementById('field_numero_nodo');
        const previewTxt = document.getElementById('preview_nodo_text');
        const clean = (val || '').trim();
        if (hiddenNodo) hiddenNodo.value = clean;
        if (previewTxt) previewTxt.textContent = clean ? ('Nodo: ' + clean) : 'Sin nodo asignado';
    }

    function limpiarNodoEnFormularioEquipos() {
        const selNodo = document.getElementById('combo_form_nodo_select');
        const hiddenNodo = document.getElementById('field_numero_nodo');
        const boxCustom = document.getElementById('box_custom_nodo_input');
        const inpCustom = document.getElementById('custom_nodo_input_manual');
        const previewTxt = document.getElementById('preview_nodo_text');

        if (selNodo) selNodo.selectedIndex = 0;
        if (boxCustom) boxCustom.style.display = 'none';
        if (inpCustom) inpCustom.value = '';
        if (hiddenNodo) hiddenNodo.value = '';
        if (previewTxt) previewTxt.textContent = 'Sin nodo asignado';
    }

    function sincronizarComboNodoFormulario(nodoVal, switchPuertoVal, patchVal) {
        const selNodo = document.getElementById('combo_form_nodo_select');
        const hiddenNodo = document.getElementById('field_numero_nodo');
        const boxCustom = document.getElementById('box_custom_nodo_input');
        const inpCustom = document.getElementById('custom_nodo_input_manual');
        const previewTxt = document.getElementById('preview_nodo_text');

        if (!selNodo) return;

        const val = (nodoVal !== null && nodoVal !== undefined) ? String(nodoVal).trim() : '';
        if (hiddenNodo) hiddenNodo.value = val;

        if (!val) {
            if (switchPuertoVal && window.nodosDisponiblesRed) {
                const sClean = String(switchPuertoVal).trim().toLowerCase();
                const matchNd = window.nodosDisponiblesRed.find(nd => (nd.switch_puerto || '').trim().toLowerCase() === sClean);
                if (matchNd) {
                    selNodo.value = matchNd.codigo_nodo;
                    if (hiddenNodo) hiddenNodo.value = matchNd.codigo_nodo;
                    if (previewNodo) previewNodo.textContent = 'Nodo: ' + matchNd.codigo_nodo;
                    if (boxCustom) boxCustom.style.display = 'none';
                    return;
                }
            }
            limpiarNodoEnFormularioEquipos();
            return;
        }

        let found = false;
        for (let i = 0; i < selNodo.options.length; i++) {
            if (selNodo.options[i].value === val) {
                selNodo.selectedIndex = i;
                found = true;
                break;
            }
        }

        if (found) {
            if (boxCustom) boxCustom.style.display = 'none';
            if (previewTxt) previewTxt.textContent = 'Nodo: ' + val;
        } else {
            selNodo.value = '__custom__';
            if (boxCustom) boxCustom.style.display = 'block';
            if (inpCustom) inpCustom.value = val;
            if (previewTxt) previewTxt.textContent = 'Nodo: ' + val;
        }
    }

    // =========================================================================
    // VALIDACIÓN PREVIA DE REGLAS DE UNICIDAD (MAC, IP, SERIE, NODO, PUERTO SW)
    // =========================================================================
    async function validarReglasFormularioEquipos(e, form) {
        e.preventDefault();
        const btnSubmit = form.querySelector('button[type="submit"]');
        const alertBox = document.getElementById('alerta_error_validacion_modal');
        if (alertBox) {
            alertBox.style.display = 'none';
            alertBox.innerHTML = '';
        }

        const formData = new FormData(form);
        formData.append('ajax_validar_unicidad', '1');

        let origBtnHtml = '';
        if (btnSubmit) {
            origBtnHtml = btnSubmit.innerHTML;
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Verificando reglas...';
        }

        try {
            const res = await fetch('equipos.php?sec=' + currentSeccion, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data && data.status === 'error' && data.errores && data.errores.length > 0) {
                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = origBtnHtml || '<i class="bi bi-save me-1"></i> Guardar Registro';
                }
                if (alertBox) {
                    alertBox.innerHTML = '<div class="d-flex align-items-start gap-2"><i class="bi bi-shield-x fs-4 text-warning"></i><div><strong class="d-block mb-1">Conflicto con Reglas de Unicidad:</strong>' + data.errores.join('<br>') + '</div></div>';
                    alertBox.style.display = 'block';
                    alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    alert(data.errores.join('\n'));
                }
                return false;
            }
        } catch (err) {
            console.warn('Error en validación AJAX previa:', err);
        }

        if (btnSubmit) {
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando...';
        }
        form.submit();
        return true;
    }

    function onTipoRegistroSiteChange(val) {
        const siteAllowedByDevice = {
            'Switch': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'cantidad_puertos', 'velocidad_puertos', 'tipo_switch', 'firmware_version', 'posicion_rack', 'ubicacion', 'numero_nodo', 'estado', 'costo', 'folio_factura', 'garantia', 'contrasena'],
            'Servidor': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'sistema_op', 'procesador', 'ram', 'almacenamiento', 'cantidad_discos', 'tipo_servidor', 'posicion_rack', 'ubicacion', 'funcion_servicio', 'ambiente', 'criticidad', 'estado', 'fecha_compra', 'garantia', 'costo', 'folio_factura', 'puerto_sw', 'numero_nodo', 'contrasena'],
            'NAS': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'firmware_version', 'bahias_nas', 'cantidad_discos', 'capacidad_disco_ind', 'almacenamiento', 'capacidad_disponible', 'config_raid', 'tipo_discos', 'protocolos_nas', 'funcion_servicio', 'posicion_rack', 'ubicacion', 'estado', 'fecha_compra', 'garantia', 'costo', 'folio_factura', 'puerto_sw', 'numero_nodo', 'contrasena'],
            'Router / ISP': ['tipo_registro', 'proveedor', 'nombre_equipo', 'tipo_enlace', 'ancho_banda', 'simetria_enlace', 'ip_publica', 'ip', 'tipo_conexion', 'numero_contrato', 'numero_cuenta', 'circuit_id', 'soporte_contacto', 'estado', 'costo', 'folio_factura', 'contrasena'],
            'Fortinet': ['tipo_registro', 'modelo', 'serie', 'nombre_equipo', 'firmware_version', 'ip', 'mac_ethernet', 'ip_publica', 'ip_local', 'costo', 'folio_factura', 'contrasena'],
            'Gateway UniFi': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'unifi_os_ver', 'controller_ver', 'ip_publica', 'tipo_conexion', 'velocidad_enlace', 'costo', 'folio_factura', 'contrasena'],
            'Access Point': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'mac_wifi', 'ip', 'firmware_version', 'ssids', 'vlans', 'poe', 'puerto_sw', 'numero_nodo', 'ubicacion', 'estado', 'controlador_ap', 'costo', 'folio_factura', 'contrasena'],
            'UPS': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'capacidad_va', 'capacidad_w', 'tipo_ups', 'voltaje_entrada', 'voltaje_salida', 'cant_baterias', 'specs_baterias', 'fecha_compra', 'fecha_bateria', 'autonomia', 'posicion_rack', 'ubicacion', 'ip', 'numero_nodo', 'estado', 'costo', 'folio_factura', 'contrasena']
        };

        const allowed = siteAllowedByDevice[val] || siteAllowedByDevice['Switch'];
        const allFieldInputs = document.querySelectorAll('#contenedorCamposForm [id^="field_"]');
        allFieldInputs.forEach(inp => {
            const colName = inp.id.replace('field_', '');
            if (colName === 'foto_camara_file' || colName === 'factura_file') return;
            const colWrapper = inp.closest('.col-md-4');
            if (!colWrapper) return;

            if (allowed.includes(colName)) {
                colWrapper.style.display = 'block';
            } else {
                colWrapper.style.display = 'none';
            }
        });

        // Hide tabs with 0 visible fields
        const tabPanes = document.querySelectorAll('#contenedorCamposForm .tab-pane');
        tabPanes.forEach(pane => {
            const visibleCols = pane.querySelectorAll('.col-md-4:not([style*="display: none"]), .col-md-6:not([style*="display: none"])');
            const tabBtn = document.querySelector(`[data-bs-target="#${pane.id}"]`);
            if (tabBtn && tabBtn.parentElement) {
                tabBtn.parentElement.style.display = (visibleCols.length > 0) ? 'block' : 'none';
            }
        });

        // Auto-select first visible tab
        const firstVisibleTabBtn = document.querySelector('#tabCapturaEquipos li:not([style*="display: none"]) .nav-link');
        if (firstVisibleTabBtn) {
            const bsTab = new bootstrap.Tab(firstVisibleTabBtn);
            bsTab.show();
        }
    }

    function onTipoRegistroMovilChange(val) {
        const movilAllowedByDevice = {
            'Celular': [
                'tipo_registro', 'marca', 'modelo', 'serie', 'imei_1', 'imei_2', 'numero_telefonico',
                'almacenamiento', 'ram', 'color', 'sistema_op', 'accesorios', 'nombre', 'departamento',
                'fecha_asignacion', 'fecha_compra', 'proveedor', 'folio_factura', 'garantia', 'mac',
                'costo', 'tiene_plan_celular', 'vencimiento_plan', 'proveedor_plan', 'numero_contrato_plan'
            ],
            'Tableta': [
                'tipo_registro', 'marca', 'modelo', 'serie', 'almacenamiento', 'ram', 'sistema_op',
                'color', 'estado_fisico', 'estatus', 'accesorios', 'nombre', 'departamento', 'ubicacion',
                'fecha_asignacion', 'fecha_compra', 'proveedor', 'garantia', 'costo', 'folio_factura'
            ],
            'Pantalla': [
                'tipo_registro', 'marca', 'modelo', 'serie', 'tamano_pantalla', 'resolucion', 'estado_fisico',
                'estatus', 'accesorios', 'departamento', 'ubicacion', 'fecha_compra', 'proveedor',
                'garantia', 'costo', 'folio_factura', 'observaciones'
            ],
            'Otro dispositivo': [
                'tipo_registro', 'subtipo_dispositivo', 'marca', 'modelo', 'serie', 'especificaciones',
                'accesorios', 'nombre', 'departamento', 'ubicacion', 'fecha_compra', 'proveedor',
                'garantia', 'fecha_asignacion', 'costo', 'folio_factura', 'observaciones'
            ]
        };

        const allowed = movilAllowedByDevice[val] || movilAllowedByDevice['Celular'];
        const allFieldInputs = document.querySelectorAll('#contenedorCamposForm [id^="field_"]');
        allFieldInputs.forEach(inp => {
            const colName = inp.id.replace('field_', '');
            if (colName === 'foto_camara_file' || colName === 'factura_file') return;
            const colWrapper = inp.closest('.col-md-4');
            if (!colWrapper) return;

            if (allowed.includes(colName)) {
                colWrapper.style.display = 'block';
            } else {
                colWrapper.style.display = 'none';
            }
        });

        // Hide tabs with 0 visible fields
        const tabPanes = document.querySelectorAll('#contenedorCamposForm .tab-pane');
        tabPanes.forEach(pane => {
            const visibleCols = pane.querySelectorAll('.col-md-4:not([style*="display: none"]), .col-md-6:not([style*="display: none"])');
            const tabBtn = document.querySelector(`[data-bs-target="#${pane.id}"]`);
            if (tabBtn && tabBtn.parentElement) {
                tabBtn.parentElement.style.display = (visibleCols.length > 0) ? 'block' : 'none';
            }
        });

        // Auto-select first visible tab
        const firstVisibleTabBtn = document.querySelector('#tabCapturaEquipos li:not([style*="display: none"]) .nav-link');
        if (firstVisibleTabBtn) {
            const bsTab = new bootstrap.Tab(firstVisibleTabBtn);
            bsTab.show();
        }
    }

    function abrirModalNuevo() {
        document.getElementById('modalRegistroLabel').innerHTML = '<i class="bi bi-plus-circle text-primary me-2"></i> Captura de Registro - <?php echo htmlspecialchars($infoSeccion['nombre']); ?>';
        document.getElementById('form_registro_id').value = '0';
        
        const fields = document.querySelectorAll('#contenedorCamposForm input, #contenedorCamposForm select');
        fields.forEach(inp => {
            if (inp.tagName === 'SELECT') {
                inp.selectedIndex = 0;
            } else {
                inp.value = '';
            }
        });

        limpiarPuertoSwitchEnFormularioEquipos();
        limpiarNodoEnFormularioEquipos();

        const selTipo = document.getElementById('field_tipo_registro');
        if (selTipo) {
            if (currentSeccion === 'site_vw') {
                onTipoRegistroSiteChange(selTipo.value || 'Switch');
            } else if (currentSeccion === 'moviles') {
                onTipoRegistroMovilChange(selTipo.value || 'Celular');
            } else {
                onTipoRegistroChange(selTipo.value || 'DVR/NVR');
            }
        }

        const modal = new bootstrap.Modal(document.getElementById('modalRegistro'));
        modal.show();
    }

    function abrirModalNuevoDVR() {
        abrirModalNuevo();
        document.getElementById('modalRegistroLabel').innerHTML = '<i class="bi bi-camera-video-fill text-primary me-2"></i> Registrar Grabador DVR/NVR';
        const selTipo = document.getElementById('field_tipo_registro');
        if (selTipo) {
            selTipo.value = 'DVR/NVR';
            onTipoRegistroChange('DVR/NVR');
        }
    }

    function abrirModalNuevaCamara() {
        if (!listadoDvrs || listadoDvrs.length === 0) {
            const modalWarn = new bootstrap.Modal(document.getElementById('modalSinDvrWarning'));
            modalWarn.show();
            return;
        }

        abrirModalNuevo();
        document.getElementById('modalRegistroLabel').innerHTML = '<i class="bi bi-camera-fill text-info me-2"></i> Registrar Cámara IP / Análoga';
        const selTipo = document.getElementById('field_tipo_registro');
        if (selTipo) {
            selTipo.value = 'Cámara';
            onTipoRegistroChange('Cámara');
        }
    }

    function onSubtipoCamaraChange(subtipoVal) {
        const selTipo = document.getElementById('field_tipo_registro');
        if (selTipo && selTipo.value !== 'Cámara') return;

        const allFieldInputs = document.querySelectorAll('#contenedorCamposForm [id^="field_"]');
        allFieldInputs.forEach(inp => {
            const colName = inp.id.replace('field_', '');
            const colWrapper = inp.closest('.col-md-4');
            if (!colWrapper) return;

            if (subtipoVal === 'Análoga') {
                if (colName === 'ip' || colName === 'canal_ip') {
                    colWrapper.style.display = 'none';
                } else if (colName === 'canal_analogico') {
                    colWrapper.style.display = 'block';
                }
            } else {
                // IP
                if (colName === 'ip' || colName === 'canal_ip') {
                    colWrapper.style.display = 'block';
                } else if (colName === 'canal_analogico') {
                    colWrapper.style.display = 'none';
                }
            }
        });
    }

    function onTipoRegistroChange(val) {
        if (val === 'Cámara' && (!listadoDvrs || listadoDvrs.length === 0)) {
            const modalWarn = new bootstrap.Modal(document.getElementById('modalSinDvrWarning'));
            modalWarn.show();
            const selTipo = document.getElementById('field_tipo_registro');
            if (selTipo) selTipo.value = 'DVR/NVR';
            val = 'DVR/NVR';
        }

        const boxFotosCam = document.getElementById('box_fotos_camara_form');
        const selSubtipo = document.getElementById('field_subtipo_camara');

        if (val === 'Cámara') {
            if (boxFotosCam) boxFotosCam.style.display = 'flex';

            const subtipoVal = selSubtipo ? (selSubtipo.value || 'IP') : 'IP';
            const cameraBaseAllowed = ['tipo_registro', 'subtipo_camara', 'dvr_vinculado', 'nombre', 'modelo', 'costo', 'contrasena_camara', 'ubicacion'];

            const allFieldInputs = document.querySelectorAll('#contenedorCamposForm [id^="field_"]');
            allFieldInputs.forEach(inp => {
                const colName = inp.id.replace('field_', '');
                if (colName === 'foto_camara_file' || colName === 'foto_vista_file') return;
                const colWrapper = inp.closest('.col-md-4');
                if (!colWrapper) return;

                if (cameraBaseAllowed.includes(colName)) {
                    colWrapper.style.display = 'block';
                } else if (subtipoVal === 'Análoga' && colName === 'canal_analogico') {
                    colWrapper.style.display = 'block';
                } else if (subtipoVal !== 'Análoga' && (colName === 'ip' || colName === 'canal_ip')) {
                    colWrapper.style.display = 'block';
                } else {
                    colWrapper.style.display = 'none';
                }
            });
        } else {
            // DVR/NVR
            if (boxFotosCam) boxFotosCam.style.display = 'none';

            const cameraOnlyFields = ['subtipo_camara', 'dvr_vinculado', 'canal_analogico', 'canal_ip', 'ubicacion'];
            const allFieldInputs = document.querySelectorAll('#contenedorCamposForm [id^="field_"]');
            allFieldInputs.forEach(inp => {
                const colName = inp.id.replace('field_', '');
                const colWrapper = inp.closest('.col-md-4');
                if (!colWrapper) return;

                if (cameraOnlyFields.includes(colName)) {
                    colWrapper.style.display = 'none';
                } else {
                    colWrapper.style.display = 'block';
                }
            });
        }

        // Hide tabs with 0 visible fields
        const tabPanes = document.querySelectorAll('#contenedorCamposForm .tab-pane');
        tabPanes.forEach(pane => {
            const visibleCols = pane.querySelectorAll('.col-md-4:not([style*="display: none"]), .col-md-6:not([style*="display: none"])');
            const tabBtn = document.querySelector(`[data-bs-target="#${pane.id}"]`);
            if (tabBtn && tabBtn.parentElement) {
                tabBtn.parentElement.style.display = (visibleCols.length > 0) ? 'block' : 'none';
            }
        });

        // Auto-select first visible tab
        const firstVisibleTabBtn = document.querySelector('#tabCapturaEquipos li:not([style*="display: none"]) .nav-link');
        if (firstVisibleTabBtn) {
            const bsTab = new bootstrap.Tab(firstVisibleTabBtn);
            bsTab.show();
        }
    }

    function abrirModalNuevoConArea(nomArea) {
        abrirModalNuevo();
        const inputDept = document.getElementById('field_departamento') || document.getElementById('field_area');
        if (inputDept && nomArea) {
            inputDept.value = nomArea;
        }
    }

    function abrirModalEditar(reg) {
        document.getElementById('modalRegistroLabel').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Registro #' + reg.id;
        document.getElementById('form_registro_id').value = reg.id;

        for (const col in reg) {
            const el = document.getElementById('field_' + col);
            if (el) {
                if (el.tagName === 'SELECT') {
                    const val = (reg[col] !== null && reg[col] !== undefined) ? String(reg[col]).trim() : '';
                    let found = false;
                    for (let i = 0; i < el.options.length; i++) {
                        if (el.options[i].value === val) {
                            el.selectedIndex = i;
                            found = true;
                            break;
                        }
                    }
                    if (!found && val !== '') {
                        const opt = new Option(val + ' (Personalizado)', val, true, true);
                        el.add(opt);
                    } else if (val === '') {
                        el.selectedIndex = 0;
                    }
                } else {
                    el.value = (reg[col] !== null && reg[col] !== undefined) ? reg[col] : '';
                }
            }
        }

        sincronizarCombosSwitchFormulario(reg.puerto_sw, reg.switch_nombre);
        sincronizarComboNodoFormulario(reg.numero_nodo, reg.puerto_sw, reg.puerto_patch_panel);

        if (reg.tipo_registro) {
            if (currentSeccion === 'site_vw') {
                onTipoRegistroSiteChange(reg.tipo_registro);
            } else if (currentSeccion === 'moviles') {
                onTipoRegistroMovilChange(reg.tipo_registro);
            } else {
                onTipoRegistroChange(reg.tipo_registro);
                if (reg.subtipo_camara) {
                    onSubtipoCamaraChange(reg.subtipo_camara);
                }
            }
        } else if (currentSeccion === 'site_vw') {
            onTipoRegistroSiteChange('Switch');
        } else if (currentSeccion === 'moviles') {
            onTipoRegistroMovilChange('Celular');
        }

        const modal = new bootstrap.Modal(document.getElementById('modalRegistro'));
        modal.show();
    }

    function getAllowedFieldsForRecord(reg, seccion) {
        let tipo = (reg.tipo_registro || '').trim();
        if (seccion === 'site_vw') {
            if (!tipo) tipo = 'Switch';
            const siteMap = {
                'Switch': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'cantidad_puertos', 'velocidad_puertos', 'tipo_switch', 'firmware_version', 'posicion_rack', 'ubicacion', 'numero_nodo', 'estado', 'costo', 'folio_factura', 'garantia', 'contrasena'],
                'Servidor': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'sistema_op', 'procesador', 'ram', 'almacenamiento', 'cantidad_discos', 'tipo_servidor', 'posicion_rack', 'ubicacion', 'funcion_servicio', 'ambiente', 'criticidad', 'estado', 'fecha_compra', 'garantia', 'costo', 'folio_factura', 'puerto_sw', 'numero_nodo', 'contrasena'],
                'NAS': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'firmware_version', 'bahias_nas', 'cantidad_discos', 'capacidad_disco_ind', 'almacenamiento', 'capacidad_disponible', 'config_raid', 'tipo_discos', 'protocolos_nas', 'funcion_servicio', 'posicion_rack', 'ubicacion', 'estado', 'fecha_compra', 'garantia', 'costo', 'folio_factura', 'puerto_sw', 'numero_nodo', 'contrasena'],
                'Router / ISP': ['tipo_registro', 'proveedor', 'nombre_equipo', 'tipo_enlace', 'ancho_banda', 'simetria_enlace', 'ip_publica', 'ip', 'tipo_conexion', 'numero_contrato', 'numero_cuenta', 'circuit_id', 'soporte_contacto', 'estado', 'costo', 'folio_factura', 'contrasena'],
                'Fortinet': ['tipo_registro', 'modelo', 'serie', 'nombre_equipo', 'firmware_version', 'ip', 'mac_ethernet', 'ip_publica', 'ip_local', 'costo', 'folio_factura', 'contrasena'],
                'Gateway UniFi': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'ip', 'mac_ethernet', 'unifi_os_ver', 'controller_ver', 'ip_publica', 'tipo_conexion', 'velocidad_enlace', 'costo', 'folio_factura', 'contrasena'],
                'Access Point': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'nombre_equipo', 'mac_wifi', 'ip', 'firmware_version', 'ssids', 'vlans', 'poe', 'puerto_sw', 'numero_nodo', 'ubicacion', 'estado', 'controlador_ap', 'costo', 'folio_factura', 'contrasena'],
                'UPS': ['tipo_registro', 'fabricante', 'modelo', 'serie', 'capacidad_va', 'capacidad_w', 'tipo_ups', 'voltaje_entrada', 'voltaje_salida', 'cant_baterias', 'specs_baterias', 'fecha_compra', 'fecha_bateria', 'autonomia', 'posicion_rack', 'ubicacion', 'ip', 'numero_nodo', 'estado', 'costo', 'folio_factura', 'contrasena']
            };
            return siteMap[tipo] || siteMap['Switch'];
        }

        if (seccion === 'moviles') {
            if (!tipo) tipo = 'Celular';
            const movilMap = {
                'Celular': [
                    'tipo_registro', 'marca', 'modelo', 'serie', 'imei_1', 'imei_2', 'numero_telefonico',
                    'almacenamiento', 'ram', 'color', 'sistema_op', 'accesorios', 'nombre', 'departamento',
                    'fecha_asignacion', 'fecha_compra', 'proveedor', 'folio_factura', 'garantia', 'mac',
                    'costo', 'tiene_plan_celular', 'vencimiento_plan', 'proveedor_plan', 'numero_contrato_plan'
                ],
                'Tableta': [
                    'tipo_registro', 'marca', 'modelo', 'serie', 'almacenamiento', 'ram', 'sistema_op',
                    'color', 'estado_fisico', 'estatus', 'accesorios', 'nombre', 'departamento', 'ubicacion',
                    'fecha_asignacion', 'fecha_compra', 'proveedor', 'garantia', 'costo', 'folio_factura'
                ],
                'Pantalla': [
                    'tipo_registro', 'marca', 'modelo', 'serie', 'tamano_pantalla', 'resolucion', 'estado_fisico',
                    'estatus', 'accesorios', 'departamento', 'ubicacion', 'fecha_compra', 'proveedor',
                    'garantia', 'costo', 'folio_factura', 'observaciones'
                ],
                'Otro dispositivo': [
                    'tipo_registro', 'subtipo_dispositivo', 'marca', 'modelo', 'serie', 'especificaciones',
                    'accesorios', 'nombre', 'departamento', 'ubicacion', 'fecha_compra', 'proveedor',
                    'garantia', 'fecha_asignacion', 'costo', 'folio_factura', 'observaciones'
                ]
            };
            return movilMap[tipo] || movilMap['Celular'];
        }

        if (seccion === 'dvr') {
            if (tipo === 'Cámara') {
                return ['tipo_registro', 'subtipo_camara', 'dvr_vinculado', 'nombre', 'modelo', 'ip', 'costo', 'contrasena_camara', 'canal_analogico', 'canal_ip', 'ubicacion'];
            } else {
                return ['tipo_registro', 'nombre', 'dvr', 'ip', 'numero_serie', 'almacenamiento', 'numero_discos', 'usuario_dvr', 'contrasena_dvr', 'dias_grabacion', 'costo', 'folio_factura', 'ubicacion', 'puerto_sw'];
            }
        }

        if (seccion === 'telefonos_poe') {
            return [
                'usuario', 'area', 'modelo', 'serie', 'mac', 'ip',
                'numero_telefonico', 'extension', 'tipo_licencia', 'correo', 'portabilidad',
                'folio_factura', 'numero_nodo', 'puerto_sw', 'switch_nombre'
            ];
        }

        if (seccion === 'monitores') {
            return [
                'marca', 'modelo_exacto', 'serie', 'departamento', 'ubicacion',
                'fecha_adquisicion', 'proveedor', 'contrato',
                'ip', 'numero_nodo', 'puerto_sw',
                'usuario_impresora', 'contrasena_impresora',
                'usuario_impresora_web', 'contrasena_impresora_web'
            ];
        }

        if (seccion === 'equipos_vw' || seccion === 'equipos_corp' || seccion === 'equipos_baja') {
            return [
                'departamento', 'puesto', 'usuario', 'nombre_equipo', 'estado', 'tipo_equipo',
                'dominio', 'dd', 'procesador', 'ghz', 'ram', 'ip', 'mac_wifi', 'mac_ethernet', 'sistema_op', 'serie',
                'logmein', 'contrasena', 'office', 'serie_office', 'clave_candado', 'gds', 'remoto', 'usuario_gds',
                'correo', 'extension', 'puerto_patch_panel', 'puerto_sw', 'numero_nodo',
                'costo', 'fecha_compra', 'folio_factura', 'inicio_garantia', 'fin_garantia', 'renovacion_equipo', 'compra', 'proveedor', 'antivirus', 'dia_respaldo', 'hora_respaldo', 'no_break', 'modelo_nobreak', 'serie_nobreak'
            ];
        }

        return null;
    }

    function verExpedienteCompleto(reg) {
        const isCam = (reg.tipo_registro === 'Cámara');
        const isTel = (currentSeccion === 'telefonos_poe');
        if (isTel) {
            document.getElementById('expediente_subtitulo').textContent = 'Teléfono IP PoE | Ext: ' + (reg.extension || 'S/E') + ' | Asignado: ' + (reg.usuario || 'Sin Asignar');
        } else {
            document.getElementById('expediente_subtitulo').textContent = (isCam ? 'Cámara: ' : 'Equipo: ') + (reg.nombre_equipo || reg.equipo || reg.nombre || 'N/A') + (isCam ? ' | DVR/NVR: ' + (reg.dvr_vinculado || 'N/A') : ' | Usuario: ' + (reg.usuario || reg.nombre || 'N/A'));
        }
        document.getElementById('busquedaExpediente').value = '';
        
        const container = document.getElementById('contenedorExpedienteDetalles');
        container.innerHTML = '';

        // 1. Tarjeta Hero / Banner de Fotografía del Equipo / Cámara
        const photoHeroBlock = document.createElement('div');
        photoHeroBlock.className = 'col-12 mb-3 cat-header-block';
        photoHeroBlock.setAttribute('data-cat-id', 'cat_foto');
        photoHeroBlock.setAttribute('data-search', 'fotografia foto imagen equipo camara vista');

        if (isCam) {
            let fotoCamHtml = reg.foto_equipo ? `
                <div class="card p-3 rounded-4 h-100" style="background: linear-gradient(135deg, #0d213a 0%, #071527 100%); border: 1.5px solid rgba(56, 189, 248, 0.3);">
                    <div class="d-flex flex-column align-items-center text-center">
                        <div style="width: 100%; height: 130px; border-radius: 10px; background: #050d1a; border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 10px;">
                            <img src="${escapeHtml(reg.foto_equipo)}" style="max-width: 100%; max-height: 100%; object-fit: contain; cursor: pointer;" onclick="window.open('${escapeHtml(reg.foto_equipo)}', '_blank')" title="Ver a tamaño completo">
                        </div>
                        <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 rounded-pill px-3 py-1 mb-2 fw-bold" style="font-size: 0.75rem;">
                            <i class="bi bi-camera-fill me-1"></i> Fotografía Física de la Cámara
                        </span>
                        <div class="d-flex gap-2">
                            <a href="${escapeHtml(reg.foto_equipo)}" target="_blank" class="btn btn-sm btn-outline-info rounded-3 font-semibold"><i class="bi bi-arrows-fullscreen"></i> Ver</a>
                            <button type="button" class="btn btn-sm btn-outline-warning rounded-3 font-semibold" onclick="abrirModalSubirDoc('foto', ${reg.id})"><i class="bi bi-arrow-repeat"></i> Cambiar</button>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-3 font-semibold" onclick="eliminarDocEquipo('foto', ${reg.id})"><i class="bi bi-trash-fill"></i></button>
                        </div>
                    </div>
                </div>
            ` : `
                <div class="card p-3 rounded-4 h-100 text-center" style="background: linear-gradient(135deg, #0d213a 0%, #071527 100%); border: 1.5px dashed rgba(255, 255, 255, 0.15);">
                    <div style="height: 90px; display: flex; align-items: center; justify-content: center;" class="text-secondary fs-1"><i class="bi bi-camera opacity-50"></i></div>
                    <h6 class="fw-bold text-white mb-2 small"><i class="bi bi-image text-info me-1"></i> Foto Cámara Física</h6>
                    <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 fw-bold" onclick="abrirModalSubirDoc('foto', ${reg.id})"><i class="bi bi-camera-fill me-1"></i> Subir Foto Cámara</button>
                </div>
            `;

            let fotoVistaHtml = reg.foto_vista_camara ? `
                <div class="card p-3 rounded-4 h-100" style="background: linear-gradient(135deg, #0d213a 0%, #071527 100%); border: 1.5px solid rgba(56, 189, 248, 0.3);">
                    <div class="d-flex flex-column align-items-center text-center">
                        <div style="width: 100%; height: 130px; border-radius: 10px; background: #050d1a; border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 10px;">
                            <img src="${escapeHtml(reg.foto_vista_camara)}" style="max-width: 100%; max-height: 100%; object-fit: contain; cursor: pointer;" onclick="window.open('${escapeHtml(reg.foto_vista_camara)}', '_blank')" title="Ver a tamaño completo">
                        </div>
                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 mb-2 fw-bold" style="font-size: 0.75rem;">
                            <i class="bi bi-camera-reels-fill me-1"></i> Fotografía de la Vista / Transmisión
                        </span>
                        <div class="d-flex gap-2">
                            <a href="${escapeHtml(reg.foto_vista_camara)}" target="_blank" class="btn btn-sm btn-outline-info rounded-3 font-semibold"><i class="bi bi-arrows-fullscreen"></i> Ver</a>
                            <button type="button" class="btn btn-sm btn-outline-warning rounded-3 font-semibold" onclick="abrirModalSubirDoc('foto_vista', ${reg.id})"><i class="bi bi-arrow-repeat"></i> Cambiar</button>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-3 font-semibold" onclick="eliminarDocEquipo('foto_vista', ${reg.id})"><i class="bi bi-trash-fill"></i></button>
                        </div>
                    </div>
                </div>
            ` : `
                <div class="card p-3 rounded-4 h-100 text-center" style="background: linear-gradient(135deg, #0d213a 0%, #071527 100%); border: 1.5px dashed rgba(255, 255, 255, 0.15);">
                    <div style="height: 90px; display: flex; align-items: center; justify-content: center;" class="text-secondary fs-1"><i class="bi bi-camera-reels opacity-50"></i></div>
                    <h6 class="fw-bold text-white mb-2 small"><i class="bi bi-display text-primary me-1"></i> Foto Vista / Transmisión</h6>
                    <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 fw-bold" onclick="abrirModalSubirDoc('foto_vista', ${reg.id})"><i class="bi bi-camera-reels-fill me-1"></i> Subir Foto Vista</button>
                </div>
            `;

            photoHeroBlock.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">${fotoCamHtml}</div>
                    <div class="col-md-6">${fotoVistaHtml}</div>
                </div>
            `;
        } else {
            let fotoHtml = '';
            if (reg.foto_equipo && reg.foto_equipo.trim() !== '') {
                fotoHtml = `
                    <div class="card p-3 rounded-4" style="background: linear-gradient(135deg, #0d213a 0%, #071527 100%); border: 1.5px solid rgba(56, 189, 248, 0.3);">
                        <div class="d-flex flex-column flex-md-row align-items-center gap-4">
                            <div style="width: 190px; height: 145px; border-radius: 12px; background: #050d1a; border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; box-shadow: 0 4px 15px rgba(0,0,0,0.5);">
                                <img src="${escapeHtml(reg.foto_equipo)}" alt="Foto del Equipo" style="max-width: 100%; max-height: 100%; object-fit: contain; cursor: pointer;" onclick="window.open('${escapeHtml(reg.foto_equipo)}', '_blank')" title="Clic para ver foto a tamaño completo">
                            </div>
                            <div class="flex-grow-1 text-center text-md-start">
                                <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 rounded-pill px-3 py-1 mb-2 fw-bold" style="font-size: 0.78rem;">
                                    <i class="bi bi-camera-fill me-1"></i> Fotografía Oficial del Equipo
                                </span>
                                <h5 class="fw-bold text-white mb-1">${escapeHtml(reg.nombre_equipo || reg.equipo || reg.nombre || (currentSeccion === 'telefonos_poe' ? ('Teléfono ' + (reg.modelo || '') + (reg.extension ? ' (Ext. ' + reg.extension + ')' : '')) : 'Equipo'))}</h5>
                                <p class="text-secondary small mb-3">Fotografía registrada para identificación física de este activo en la sucursal.</p>
                                <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-md-start">
                                    <a href="${escapeHtml(reg.foto_equipo)}" target="_blank" class="btn btn-sm btn-outline-info rounded-3 font-semibold">
                                        <i class="bi bi-arrows-fullscreen me-1"></i> Ver Imagen Completa
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-warning rounded-3 font-semibold" onclick="abrirModalSubirDoc('foto', ${reg.id})">
                                        <i class="bi bi-arrow-repeat me-1"></i> Cambiar Fotografía
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-3 font-semibold" onclick="eliminarDocEquipo('foto', ${reg.id})">
                                        <i class="bi bi-trash-fill me-1"></i> Eliminar Foto
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                fotoHtml = `
                    <div class="card p-3 rounded-4" style="background: linear-gradient(135deg, #0d213a 0%, #071527 100%); border: 1.5px dashed rgba(255, 255, 255, 0.15);">
                        <div class="d-flex flex-column flex-md-row align-items-center gap-4">
                            <div style="width: 140px; height: 105px; border-radius: 12px; background: rgba(15, 34, 61, 0.6); border: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: center; flex-shrink: 0;" class="text-secondary fs-1">
                                <i class="bi bi-camera-fill opacity-50"></i>
                            </div>
                            <div class="flex-grow-1 text-center text-md-start">
                                <h6 class="fw-bold text-white mb-1"><i class="bi bi-image me-1 text-info"></i> Fotografía del Equipo</h6>
                                <p class="text-secondary small mb-3">Aún no se ha subido una fotografía física de este equipo al expediente.</p>
                                <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 fw-bold shadow-sm" onclick="abrirModalSubirDoc('foto', ${reg.id})">
                                    <i class="bi bi-camera-fill me-1"></i> Subir Fotografía del Equipo
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }
            photoHeroBlock.innerHTML = fotoHtml;
        }
        container.appendChild(photoHeroBlock);

        // Tarjeta de Diagnóstico SNMP en Vivo para Impresoras
        if (currentSeccion === 'monitores' && reg.ip && reg.ip.trim() !== '') {
            const snmpCard = document.createElement('div');
            snmpCard.className = 'col-12 mb-3 cat-header-block';
            snmpCard.setAttribute('data-cat-id', 'cat_snmp_live');
            snmpCard.setAttribute('data-search', 'snmp toner impresiones estado error impresora ip consola web');
            snmpCard.innerHTML = `
                <div class="card p-3 rounded-4 shadow-lg" style="background: linear-gradient(135deg, #091a30 0%, #0d2747 100%); border: 1.5px solid rgba(56, 189, 248, 0.35);">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                        <div class="d-flex align-items-center gap-2">
                            <div class="w-10 h-10 rounded-3 bg-primary bg-opacity-25 text-info d-flex align-items-center justify-content-center fs-5" style="width: 40px; height: 40px;">
                                <i class="bi bi-printer-fill"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-white mb-0">Diagnóstico SNMP en Tiempo Real (Tóner & Estado)</h6>
                                <span class="text-secondary small">IP: <strong class="text-info font-monospace">${escapeHtml(reg.ip)}</strong></span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="http://${escapeHtml(reg.ip)}/" target="_blank" class="btn btn-sm btn-outline-info rounded-3 font-semibold">
                                <i class="bi bi-globe me-1"></i> Abrir Web Image Monitor
                            </a>
                            <button type="button" class="btn btn-sm btn-primary rounded-3 font-semibold" id="btn_refresh_snmp_exp" onclick="cargarSNMPExpediente('${escapeHtml(reg.ip)}')">
                                <i class="bi bi-arrow-clockwise me-1"></i> Actualizar SNMP
                            </button>
                        </div>
                    </div>
                    <div id="snmp_expediente_content" class="row g-3">
                        <div class="col-12 text-center py-3 text-info">
                            <span class="spinner-border spinner-border-sm me-2"></span> Consultando nivel de tóner y pantalla de la impresora vía SNMP...
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(snmpCard);
            const ipTarget = reg.ip.trim();
            setTimeout(function() { cargarSNMPExpediente(ipTarget); }, 150);
        }

        const allowedDeviceFields = getAllowedFieldsForRecord(reg, currentSeccion);
        const dvrExcludedFields = ['correo_oficial_planta', 'puerto_patch_panel', 'numero_nodo', 'contrasena_remoto', 'contrasena_gds', 'garantia2', 'subtipo_camara', 'canal_analogico', 'canal_ip'];
        const systemInternalFields = ['id', 'foto_equipo', 'foto_vista_camara', 'factura_url', 'responsiva_url', 'actualizado_en'];

        CATEGORIAS_EXPEDIENTE.forEach(cat => {
            let camposDeCat = [];
            cat.campos.forEach(kLower => {
                for (const key in reg) {
                    if (key.toLowerCase() === kLower) {
                        const kL = key.toLowerCase();
                        const valRaw = reg[key];

                        if (systemInternalFields.includes(kL)) {
                            continue;
                        }
                        if (currentSeccion === 'dvr' && dvrExcludedFields.includes(kL)) {
                            continue;
                        }
                        if (allowedDeviceFields && Array.isArray(allowedDeviceFields)) {
                            if (!allowedDeviceFields.includes(kL)) {
                                continue;
                            }
                        }
                        camposDeCat.push(key);
                        break;
                    }
                }
            });

            if (camposDeCat.length > 0) {
                let catTitle = cat.titulo;
                if (cat.id === 'cat_garantia' && currentSeccion === 'dvr') {
                    catTitle = '💾 Respaldo';
                }
                const secHeader = document.createElement('div');
                secHeader.className = 'col-12 mt-3 mb-1 cat-header-block';
                secHeader.setAttribute('data-cat-id', cat.id);
                secHeader.innerHTML = `<h6 class="fw-bold text-info border-bottom border-secondary pb-2 mb-0"><i class="bi ${cat.icono} me-2"></i> ${catTitle}</h6>`;
                container.appendChild(secHeader);

                camposDeCat.forEach(key => {
                    const valRaw = reg[key];
                    const isPass = key.toLowerCase().includes('contrasena') || key.toLowerCase().includes('password') || key.toLowerCase().includes('clave');
                    const valFormatted = formatUnitValue(key, valRaw);
                    const keyTitle = getFieldLabel(key);

                    const card = document.createElement('div');
                    card.className = 'col-md-4 col-lg-3 card-item-block';
                    card.setAttribute('data-cat-id', cat.id);
                    card.setAttribute('data-search', (keyTitle + ' ' + (valRaw || '')).toLowerCase());

                    let valHtml = '';
                    if (isPass && valRaw) {
                        const passId = 'pass_exp_' + Math.random().toString(36).substr(2, 9);
                        valHtml = `
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="pass-cell font-monospace" id="${passId}" data-pass="${escapeHtml(valRaw)}">••••••••</span>
                                <button type="button" class="btn btn-sm text-info p-0 ms-1" onclick="togglePassSpan('${passId}', this)" title="Mostrar / Ocultar Contraseña">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                            </div>
                        `;
                    } else {
                        valHtml = `<div class="fw-semibold text-white">${valFormatted}</div>`;
                    }

                    card.innerHTML = `
                        <div class="card h-100 p-3 rounded-3" style="background: #0f223d; border: 1px solid rgba(255, 255, 255, 0.08);">
                            <div class="small fw-bold mb-1 text-uppercase" style="font-size: 0.7rem; color: #94a3b8;">${keyTitle}</div>
                            ${valHtml}
                        </div>
                    `;
                    container.appendChild(card);
                });
            }
        });

        // Renderizar botones de Documentos (Foto, Factura y Responsiva) en el Footer del Expediente
        const footerDocs = document.getElementById('expediente_footer_docs');
        if (footerDocs) {
            let htmlDocs = '';

            // BOTÓN FOTO DEL EQUIPO / CÁMARA / DISPOSITIVO
            const labelFotoBtn = isCam ? 'Foto Cámara' : (currentSeccion === 'site_vw' ? 'Foto Dispositivo SITE' : 'Foto Equipo');
            if (reg.foto_equipo && reg.foto_equipo.trim() !== '') {
                htmlDocs += `
                    <button type="button" class="btn btn-outline-info btn-sm fw-bold px-3 rounded-3" onclick="abrirModalSubirDoc('foto', ${reg.id})">
                        <i class="bi bi-camera-fill me-1"></i> Cambiar ${labelFotoBtn}
                    </button>
                `;
            } else {
                htmlDocs += `
                    <button type="button" class="btn btn-outline-info btn-sm fw-bold px-3 rounded-3" onclick="abrirModalSubirDoc('foto', ${reg.id})">
                        <i class="bi bi-camera-fill me-1"></i> Cargar ${labelFotoBtn}
                    </button>
                `;
            }

            // BOTÓN FOTO VISTA DE CÁMARA (SI ES CÁMARA)
            if (isCam) {
                if (reg.foto_vista_camara && reg.foto_vista_camara.trim() !== '') {
                    htmlDocs += `
                        <button type="button" class="btn btn-outline-primary btn-sm fw-bold px-3 rounded-3 ms-1" onclick="abrirModalSubirDoc('foto_vista', ${reg.id})">
                            <i class="bi bi-camera-reels-fill me-1"></i> Cambiar Foto Vista
                        </button>
                    `;
                } else {
                    htmlDocs += `
                        <button type="button" class="btn btn-outline-primary btn-sm fw-bold px-3 rounded-3 ms-1" onclick="abrirModalSubirDoc('foto_vista', ${reg.id})">
                            <i class="bi bi-camera-reels me-1"></i> Cargar Foto Vista
                        </button>
                    `;
                }
            }

            // BOTÓN FACTURA
            if (reg.factura_url && reg.factura_url.trim() !== '') {
                htmlDocs += `
                    <div class="btn-group ms-1">
                        <a href="${reg.factura_url}" target="_blank" class="btn btn-success btn-sm fw-bold rounded-start px-3">
                            <i class="bi bi-file-earmark-pdf-fill me-1"></i> Visualizar Factura
                        </a>
                        <button type="button" class="btn btn-outline-success btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Opciones Factura</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            <li><a class="dropdown-item" href="${reg.factura_url}" target="_blank"><i class="bi bi-eye me-2 text-info"></i> Abrir / Visualizar Factura</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="abrirModalSubirDoc('factura', ${reg.id})"><i class="bi bi-arrow-repeat me-2 text-warning"></i> Reemplazar Factura</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="eliminarDocEquipo('factura', ${reg.id})"><i class="bi bi-trash me-2"></i> Eliminar Factura</a></li>
                        </ul>
                    </div>
                `;
            } else {
                htmlDocs += `
                    <button type="button" class="btn btn-outline-info btn-sm fw-bold px-3 rounded-3 ms-1" onclick="abrirModalSubirDoc('factura', ${reg.id})">
                        <i class="bi bi-file-earmark-plus me-1"></i> Cargar Factura
                    </button>
                `;
            }

            // BOTÓN RESPONSIVA (SOLO PARA SECCIONES PERMITIDAS: equipos_vw, equipos_corp, moviles)
            if (['equipos_vw', 'equipos_corp', 'moviles'].includes(currentSeccion)) {
                if (reg.responsiva_url && reg.responsiva_url.trim() !== '') {
                    htmlDocs += `
                        <div class="btn-group ms-1">
                            <a href="${reg.responsiva_url}" target="_blank" class="btn btn-primary btn-sm fw-bold rounded-start px-3">
                                <i class="bi bi-file-earmark-check-fill me-1"></i> Visualizar Responsiva
                            </a>
                            <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="visually-hidden">Opciones Responsiva</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-dark shadow">
                                <li><a class="dropdown-item" href="${reg.responsiva_url}" target="_blank"><i class="bi bi-eye me-2 text-info"></i> Abrir / Visualizar Responsiva</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="abrirModalSubirDoc('responsiva', ${reg.id})"><i class="bi bi-arrow-repeat me-2 text-warning"></i> Reemplazar Responsiva</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="eliminarDocEquipo('responsiva', ${reg.id})"><i class="bi bi-trash me-2"></i> Eliminar Responsiva</a></li>
                            </ul>
                        </div>
                    `;
                } else {
                    htmlDocs += `
                        <button type="button" class="btn btn-outline-primary btn-sm fw-bold px-3 rounded-3 ms-1" onclick="abrirModalSubirDoc('responsiva', ${reg.id})">
                            <i class="bi bi-file-earmark-check me-1"></i> Cargar Responsiva
                        </button>
                    `;
                }

                // BOTÓN GENERAR RESPONSIVA PDF
                htmlDocs += `
                    <a href="generar_responsiva.php?id=${reg.id}&sec=${currentSeccion}" target="_blank" class="btn btn-warning btn-sm fw-bold px-3 rounded-3 ms-1" title="Generar e imprimir Carta Responsiva en PDF">
                        <i class="bi bi-printer-fill me-1"></i> Generar Responsiva PDF
                    </a>
                `;
            }

            footerDocs.innerHTML = htmlDocs;
        }

        const modal = new bootstrap.Modal(document.getElementById('modalExpediente'));
        modal.show();
    }

    function abrirModalSubirDoc(tipo, regId) {
        document.getElementById('doc_registro_id').value = regId;
        document.getElementById('doc_tipo_documento').value = tipo;
        
        const labelEl = document.getElementById('modalSubirDocLabel');
        const instEl = document.getElementById('doc_instrucciones');
        const fileInput = document.querySelector('#customDocUploadOverlay input[name="documento_file"]');

        if (tipo === 'foto' || tipo === 'foto_equipo') {
            labelEl.innerHTML = '<i class="bi bi-camera-fill text-warning me-2"></i> Cargar Fotografía del Equipo / Cámara';
            instEl.textContent = 'Selecciona la fotografía o imagen física de la cámara/equipo (JPG, PNG, WEBP) para adjuntarla al expediente.';
            if (fileInput) fileInput.setAttribute('accept', 'image/*,.jpg,.jpeg,.png,.webp');
        } else if (tipo === 'foto_vista' || tipo === 'foto_vista_camara') {
            labelEl.innerHTML = '<i class="bi bi-camera-reels-fill text-info me-2"></i> Cargar Foto de la Vista de la Cámara';
            instEl.textContent = 'Selecciona la captura de pantalla o foto de la transmisión/vista que genera esta cámara (JPG, PNG, WEBP).';
            if (fileInput) fileInput.setAttribute('accept', 'image/*,.jpg,.jpeg,.png,.webp');
        } else if (tipo === 'responsiva') {
            labelEl.innerHTML = '<i class="bi bi-file-earmark-check-fill text-primary me-2"></i> Cargar Responsiva de Equipo';
            instEl.textContent = 'Selecciona la carta responsiva firmada (PDF o Imagen) para adjuntarla al expediente de este equipo.';
            if (fileInput) fileInput.setAttribute('accept', '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx');
        } else {
            labelEl.innerHTML = '<i class="bi bi-receipt-cutoff text-info me-2"></i> Cargar Factura de Equipo';
            instEl.textContent = 'Selecciona la factura o comprobante digital de compra (PDF o Imagen) para adjuntarla al expediente de este equipo.';
            if (fileInput) fileInput.setAttribute('accept', '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx');
        }

        const overlay = document.getElementById('customDocUploadOverlay');
        if (overlay) overlay.style.display = 'flex';
    }

    function cerrarModalSubirDoc() {
        const overlay = document.getElementById('customDocUploadOverlay');
        if (overlay) overlay.style.display = 'none';
    }

    function cargarSNMPExpediente(ip) {
        const el = document.getElementById('snmp_expediente_content');
        const btn = document.getElementById('btn_refresh_snmp_exp');
        if (btn) btn.disabled = true;

        fetch('equipos.php?action=consultar_impresora_snmp&ip=' + encodeURIComponent(ip))
            .then(res => res.json())
            .then(data => {
                if (btn) btn.disabled = false;
                if (!el) return;

                if (data.error || !data.online) {
                    el.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-danger mb-0 rounded-3 d-flex align-items-center gap-2 small">
                                <i class="bi bi-wifi-off fs-5"></i>
                                <div>
                                    <strong>Impresora Fuera de Línea / Sin Respuesta</strong><br>
                                    No se pudo establecer comunicación SNMP con la IP <code>${escapeHtml(ip)}</code>. Verifica que la impresora esté encendida y conectada a la red local.
                                </div>
                            </div>
                        </div>
                    `;
                    return;
                }

                let tonerBar = '';
                if (data.toner_percent !== null) {
                    let colorClass = 'bg-success';
                    if (data.toner_percent <= 15) colorClass = 'bg-danger';
                    else if (data.toner_percent <= 35) colorClass = 'bg-warning text-dark';

                    tonerBar = `
                        <div class="card p-3 rounded-3 h-100" style="background: rgba(15, 34, 61, 0.7); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="small fw-bold text-uppercase mb-1 text-secondary">Nivel de Tóner Cartucho (${escapeHtml(data.toner_cartridge)})</div>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="fs-4 fw-black ${data.toner_percent <= 15 ? 'text-danger' : 'text-success'}">${data.toner_percent}%</span>
                                <span class="badge ${data.toner_percent <= 15 ? 'bg-danger' : 'bg-success'} rounded-pill px-2.5 py-1 small">${data.toner_percent <= 15 ? 'CRÍTICO' : 'ÓPTIMO'}</span>
                            </div>
                            <div class="progress" style="height: 10px; background: rgba(255,255,255,0.1);">
                                <div class="progress-bar ${colorClass} progress-bar-striped progress-bar-animated" style="width: ${data.toner_percent}%"></div>
                            </div>
                        </div>
                    `;
                } else {
                    tonerBar = `
                        <div class="card p-3 rounded-3 h-100" style="background: rgba(15, 34, 61, 0.7); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="small fw-bold text-uppercase mb-1 text-secondary">Cartucho de Tóner</div>
                            <div class="fw-bold text-white fs-6">${escapeHtml(data.toner_cartridge)}</div>
                            <div class="text-secondary small mt-1">Estado reportado: ${escapeHtml(data.status)}</div>
                        </div>
                    `;
                }

                const alertBadgeClass = data.estado_badge === 'danger' ? 'alert-danger' : (data.estado_badge === 'warning' ? 'alert-warning' : 'alert-success');

                el.innerHTML = `
                    <div class="col-md-5">
                        ${tonerBar}
                    </div>
                    <div class="col-md-4">
                        <div class="card p-3 rounded-3 h-100" style="background: rgba(15, 34, 61, 0.7); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="small fw-bold text-uppercase mb-1 text-secondary">Mensaje Consola / Pantalla</div>
                            <div class="alert ${alertBadgeClass} py-2 px-3 mb-0 rounded-3 font-monospace small fw-bold d-flex align-items-center gap-2">
                                <i class="bi ${data.es_error ? 'bi-exclamation-octagon-fill fs-5' : 'bi-check-circle-fill fs-5'}"></i>
                                <div>${escapeHtml(data.status)}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card p-3 rounded-3 h-100" style="background: rgba(15, 34, 61, 0.7); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="small fw-bold text-uppercase mb-1 text-secondary">Total Páginas Impresas</div>
                            <div class="fs-4 fw-black text-info font-monospace">${data.counter}</div>
                            <div class="text-secondary small" style="font-size: 0.7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(data.model)}">${escapeHtml(data.model)}</div>
                        </div>
                    </div>
                `;
            })
            .catch(err => {
                if (btn) btn.disabled = false;
                if (el) {
                    el.innerHTML = `<div class="col-12 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> Error al consultar datos SNMP de la impresora.</div>`;
                }
            });
    }

    function consultarSNMPImpresora(ip, regId, btn) {
        const cell = document.getElementById('snmp_status_cell_' + regId);
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Leyendo...';
        }

        fetch('equipos.php?action=consultar_impresora_snmp&ip=' + encodeURIComponent(ip))
            .then(res => res.json())
            .then(data => {
                if (!cell) return;
                if (data.error || !data.online) {
                    cell.innerHTML = `<span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1 small fw-bold" title="${data.error || 'Fuera de línea'}"><i class="bi bi-wifi-off me-1"></i> Offline</span>`;
                    return;
                }

                let pctBadge = '';
                if (data.toner_percent !== null) {
                    let colorClass = 'bg-success';
                    if (data.toner_percent <= 15) colorClass = 'bg-danger';
                    else if (data.toner_percent <= 35) colorClass = 'bg-warning text-dark';

                    pctBadge = `
                        <div class="d-flex align-items-center gap-1.5 mb-1" style="min-width: 90px;">
                            <div class="progress flex-grow-1" style="height: 7px; background: rgba(255,255,255,0.15);">
                                <div class="progress-bar ${colorClass}" style="width: ${data.toner_percent}%"></div>
                            </div>
                            <span class="fw-bold small ${data.toner_percent <= 15 ? 'text-danger' : 'text-success'}" style="font-size: 0.78rem;">${data.toner_percent}%</span>
                        </div>
                    `;
                }

                const badgeBg = data.estado_badge === 'danger' ? 'bg-danger text-white' : (data.estado_badge === 'warning' ? 'bg-warning text-dark' : 'bg-success text-white');

                cell.innerHTML = `
                    <div class="d-flex flex-column gap-1" title="Contador: ${data.counter} | Modelo: ${escapeHtml(data.model)}">
                        ${pctBadge}
                        <span class="badge ${badgeBg} bg-opacity-25 border border-${data.estado_badge} rounded-pill px-2 py-0.5 small" style="font-size: 0.72rem;">
                            <i class="bi ${data.es_error ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'} me-1"></i> ${escapeHtml(data.status)}
                        </span>
                    </div>
                `;
            })
            .catch(err => {
                if (cell) {
                    cell.innerHTML = `<span class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> Error</span>`;
                }
            });
    }

    function eliminarDocEquipo(tipo, regId) {
        if (confirm('¿Estás seguro de que deseas eliminar el documento de ' + tipo.toUpperCase() + ' adjunto a este equipo?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="accion" value="eliminar_documento_equipo">
                <input type="hidden" name="registro_id" value="${regId}">
                <input type="hidden" name="tipo_documento" value="${tipo}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function filtrarDatoExpediente() {
        const q = document.getElementById('busquedaExpediente').value.toLowerCase().trim();
        const cards = document.querySelectorAll('#contenedorExpedienteDetalles .card-item-block');
        const headers = document.querySelectorAll('#contenedorExpedienteDetalles .cat-header-block');

        const visibleCats = new Set();

        cards.forEach(card => {
            const text = card.getAttribute('data-search') || '';
            const matches = text.includes(q);
            card.style.display = matches ? '' : 'none';
            if (matches) {
                visibleCats.add(card.getAttribute('data-cat-id'));
            }
        });

        headers.forEach(h => {
            const catId = h.getAttribute('data-cat-id');
            h.style.display = visibleCats.has(catId) ? '' : 'none';
        });
    }

    function abrirExpedienteSiNoEsBoton(event, reg) {
        if (event.target.closest('button, a, select, input, form, .btn, .form-check-input')) {
            return;
        }
        verExpedienteCompleto(reg);
    }

    let chartInstanceAreas = null;
    let chartInstanceEstatus = null;
    let chartInstanceCosto = null;
    let chartInstanceRam = null;
    let chartInstanceSecciones = null;

    function cambiarVistaEquipos(tipo) {
        const tablaView = document.getElementById('vistaTablaEquipos');
        const areasView = document.getElementById('vistaAreasEquipos');
        const graficasView = document.getElementById('vistaGraficasEquipos');

        const btnTabla = document.getElementById('btnVistaTablaEquipos');
        const btnAreas = document.getElementById('btnVistaAreasEquipos');
        const btnGraficas = document.getElementById('btnVistaGraficasEquipos');

        if (tipo === 'graficas') {
            if (tablaView) tablaView.classList.add('d-none');
            if (areasView) areasView.classList.add('d-none');
            if (graficasView) graficasView.classList.remove('d-none');

            if (btnTabla) btnTabla.className = 'btn-seg';
            if (btnAreas) btnAreas.className = 'btn-seg';
            if (btnGraficas) btnGraficas.className = 'btn-seg active';

            localStorage.setItem('vista_equipos_pref', 'graficas');
            inicializarGraficasEquipos();
        } else if (tipo === 'areas') {
            if (tablaView) tablaView.classList.add('d-none');
            if (graficasView) graficasView.classList.add('d-none');
            if (areasView) areasView.classList.remove('d-none');

            if (btnTabla) btnTabla.className = 'btn-seg';
            if (btnGraficas) btnGraficas.className = 'btn-seg';
            if (btnAreas) btnAreas.className = 'btn-seg active';

            localStorage.setItem('vista_equipos_pref', 'areas');
        } else {
            if (areasView) areasView.classList.add('d-none');
            if (graficasView) graficasView.classList.add('d-none');
            if (tablaView) tablaView.classList.remove('d-none');

            if (btnAreas) btnAreas.className = 'btn-seg';
            if (btnGraficas) btnGraficas.className = 'btn-seg';
            if (btnTabla) btnTabla.className = 'btn-seg active';

            localStorage.setItem('vista_equipos_pref', 'tabla');
        }
    }

    function inicializarGraficasEquipos() {
        if (typeof Chart === 'undefined') return;

        const labelSecciones = <?php echo $jsonSeccionesLabels; ?>;
        const countSecciones = <?php echo $jsonSeccionesCounts; ?>;

        const labelAreas = <?php echo $jsonAreasLabels; ?>;
        const countAreas = <?php echo $jsonAreasCounts; ?>;
        const costAreas  = <?php echo $jsonAreasCosts; ?>;

        const labelEstatus = <?php echo $jsonEstatusRenovLabels; ?>;
        const countEstatus = <?php echo $jsonEstatusRenovCounts; ?>;

        const labelRam = <?php echo $jsonRamLabels; ?>;
        const countRam = <?php echo $jsonRamCounts; ?>;

        // Estilos y Opciones Comunes para Tema Oscuro
        const darkGridOptions = {
            color: 'rgba(255, 255, 255, 0.06)',
            borderColor: 'rgba(255, 255, 255, 0.1)'
        };
        const darkTicksOptions = {
            color: '#94a3b8',
            font: { family: "'Segoe UI', sans-serif", size: 11 }
        };

        // 1. Gráfica de Equipos por Área
        const ctxAreas = document.getElementById('chartEquiposPorArea');
        if (ctxAreas) {
            if (chartInstanceAreas) chartInstanceAreas.destroy();
            chartInstanceAreas = new Chart(ctxAreas, {
                type: 'bar',
                data: {
                    labels: labelAreas,
                    datasets: [{
                        label: 'Equipos Registrados',
                        data: countAreas,
                        backgroundColor: 'rgba(37, 99, 235, 0.65)',
                        borderColor: '#3b82f6',
                        borderWidth: 1.5,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { backgroundColor: '#0f223d', titleColor: '#fff', bodyColor: '#38bdf8' }
                    },
                    scales: {
                        x: { grid: darkGridOptions, ticks: darkTicksOptions },
                        y: { grid: darkGridOptions, ticks: darkTicksOptions, beginAtZero: true }
                    }
                }
            });
        }

        // 2. Gráfica de Estatus de Renovación
        const ctxEstatus = document.getElementById('chartEstatusRenovacion');
        if (ctxEstatus) {
            if (chartInstanceEstatus) chartInstanceEstatus.destroy();
            chartInstanceEstatus = new Chart(ctxEstatus, {
                type: 'doughnut',
                data: {
                    labels: labelEstatus,
                    datasets: [{
                        data: countEstatus,
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.8)', // Vigentes - Verde
                            'rgba(245, 158, 11, 0.8)', // Próximos - Amarillo
                            'rgba(239, 68, 68, 0.8)',  // Urgentes - Rojo
                            'rgba(148, 163, 184, 0.4)' // Sin fecha - Gris
                        ],
                        borderColor: '#0a192e',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#cbd5e1', font: { size: 11 }, padding: 12, usePointStyle: true }
                        }
                    },
                    cutout: '65%'
                }
            });
        }

        // 3. Gráfica de Inversión / Costo por Área
        const ctxCosto = document.getElementById('chartCostoPorArea');
        if (ctxCosto) {
            if (chartInstanceCosto) chartInstanceCosto.destroy();
            chartInstanceCosto = new Chart(ctxCosto, {
                type: 'bar',
                data: {
                    labels: labelAreas,
                    datasets: [{
                        label: 'Inversión en Equipos ($)',
                        data: costAreas,
                        backgroundColor: 'rgba(16, 185, 129, 0.65)',
                        borderColor: '#10b981',
                        borderWidth: 1.5,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f223d',
                            callbacks: {
                                label: function(context) {
                                    let val = context.raw || 0;
                                    return 'Inversión: $' + val.toLocaleString('es-MX', {minimumFractionDigits: 2});
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: darkGridOptions, ticks: darkTicksOptions },
                        y: {
                            grid: darkGridOptions,
                            ticks: {
                                ...darkTicksOptions,
                                callback: function(value) { return '$' + value.toLocaleString('es-MX'); }
                            },
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // 4. Gráfica de Distribución por RAM
        const ctxRam = document.getElementById('chartRamDistribucion');
        if (ctxRam) {
            if (chartInstanceRam) chartInstanceRam.destroy();
            chartInstanceRam = new Chart(ctxRam, {
                type: 'pie',
                data: {
                    labels: labelRam.length > 0 ? labelRam : ['Sin Datos RAM'],
                    datasets: [{
                        data: countRam.length > 0 ? countRam : [1],
                        backgroundColor: [
                            'rgba(14, 165, 233, 0.8)',
                            'rgba(168, 85, 247, 0.8)',
                            'rgba(236, 72, 153, 0.8)',
                            'rgba(249, 115, 22, 0.8)',
                            'rgba(20, 184, 166, 0.8)'
                        ],
                        borderColor: '#0a192e',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#cbd5e1', font: { size: 11 }, padding: 10, usePointStyle: true }
                        }
                    }
                }
            });
        }
        // 5. Gráfica de Equipos por Sección de Inventario
        const ctxSecciones = document.getElementById('chartEquiposPorSeccion');
        if (ctxSecciones && labelSecciones && labelSecciones.length > 0) {
            if (chartInstanceSecciones) chartInstanceSecciones.destroy();
            chartInstanceSecciones = new Chart(ctxSecciones, {
                type: 'bar',
                data: {
                    labels: labelSecciones,
                    datasets: [{
                        label: 'Equipos Registrados',
                        data: countSecciones,
                        backgroundColor: 'rgba(14, 165, 233, 0.65)',
                        borderColor: '#0ea5e9',
                        borderWidth: 1.5,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { backgroundColor: '#0f223d', titleColor: '#fff', bodyColor: '#38bdf8' }
                    },
                    scales: {
                        x: { grid: darkGridOptions, ticks: darkTicksOptions },
                        y: { grid: darkGridOptions, ticks: darkTicksOptions, beginAtZero: true }
                    }
                }
            });
        }
    }

    // Restaurar preferencia de vista al cargar la página
    document.addEventListener('DOMContentLoaded', function() {
        const currentSec = "<?php echo htmlspecialchars($seccion_activa); ?>";
        if (currentSec === 'graficas') {
            cambiarVistaEquipos('graficas');
        } else {
            const prefVista = localStorage.getItem('vista_equipos_pref');
            if (prefVista === 'areas') {
                cambiarVistaEquipos('areas');
            } else {
                cambiarVistaEquipos('tabla');
            }
        }
    });

    function exportarTablaCSV() {
        const table = document.getElementById('tablaEquipos');
        let csv = [];
        for (let i = 0; i < table.rows.length; i++) {
            let row = [], cols = table.rows[i].querySelectorAll('td, th');
            for (let j = 0; j < cols.length - 1; j++) {
                row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
            }
            csv.push(row.join(','));
        }
        const csvFile = new Blob([csv.join('\n')], {type: 'text/csv;charset=utf-8;'});
        const downloadLink = document.createElement('a');
        downloadLink.download = '<?php echo $seccion_activa; ?>_inventario.csv';
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = 'none';
        document.body.appendChild(downloadLink);
        downloadLink.click();
    }
</script>
</body>
</html>
