<?php
/**
 * API REST para el Módulo de Monitoreo de Respaldos (NAS)
 * Permite la comunicación segura con scripts colectores LAN (Bearer Token)
 * y operaciones del portal (Sesión de Usuario).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

if ($pdo) {
    asegurarTablasRespaldos($pdo);
} else {
    echo json_encode(['success' => false, 'error' => 'No hay conexión a la base de datos']);
    exit();
}

/**
 * Valida el Token Bearer para autenticación de scripts colectores LAN
 */
function validarTokenColector($pdo) {
    $tokenEnviado = null;

    // 1. Obtener desde Header Authorization
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
    }

    if (!empty($authHeader) && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $tokenEnviado = trim($matches[1]);
    }

    // 2. Respaldo desde GET / POST / JSON Body
    if (empty($tokenEnviado)) {
        $tokenEnviado = $_GET['token'] ?? ($_POST['token'] ?? null);
    }
    if (empty($tokenEnviado)) {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $jsonData = json_decode($raw, true);
            if (is_array($jsonData) && !empty($jsonData['token'])) {
                $tokenEnviado = trim($jsonData['token']);
            }
        }
    }

    if (empty($tokenEnviado)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT valor FROM respaldos_config WHERE clave = 'token_api_colector' LIMIT 1");
        $stmt->execute();
        $tokenReal = $stmt->fetchColumn();

        if (!empty($tokenReal) && hash_equals($tokenReal, $tokenEnviado)) {
            return true;
        }
    } catch (Throwable $e) {}

    return false;
}

/**
 * Valida sesión de usuario para llamadas internas del portal
 */
function validarSesionPortal() {
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión no válida o expirada']);
        exit();
    }
}

/**
 * Calcula las semanas calendario de un mes (de Domingo a Sábado).
 * La Semana 1 inicia el día 1 del mes y concluye el primer sábado.
 * Las siguientes semanas van de domingo a sábado.
 * La última semana concluye el último día del mes.
 */
function calcularSemanasCalendarioMes($mes) {
    $diasEnMes = (int)date('t', strtotime("$mes-01"));
    $semanas = [];
    $semanaNum = 1;
    $diaActual = 1;
    $nombresDias = [0 => 'Dom', 1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb'];

    while ($diaActual <= $diasEnMes) {
        $fechaInicioStr = sprintf('%s-%02d', $mes, $diaActual);
        $wInicio = (int)date('w', strtotime($fechaInicioStr)); // 0=Dom, 6=Sáb
        
        // La semana concluye el sábado (w = 6) o el último día del mes
        $diasHastaSabado = 6 - $wInicio;
        $diaFin = min($diaActual + $diasHastaSabado, $diasEnMes);
        $fechaFinStr = sprintf('%s-%02d', $mes, $diaFin);
        $wFin = (int)date('w', strtotime($fechaFinStr));

        $sId = "S$semanaNum";
        $diasLabel = ($diaActual === $diaFin) 
            ? "{$nombresDias[$wInicio]} $diaActual" 
            : "{$nombresDias[$wInicio]} $diaActual al {$nombresDias[$wFin]} $diaFin";

        $semanas[$sId] = [
            'id' => $sId,
            'label' => "Semana $semanaNum",
            'dias_label' => $diasLabel,
            'inicio' => $diaActual,
            'fin' => $diaFin,
            'dias_count' => ($diaFin - $diaActual + 1)
        ];

        $diaActual = $diaFin + 1;
        $semanaNum++;
    }

    return $semanas;
}

/**
 * Calcula en tiempo real las métricas ejecutivas para actualizar
 * instantáneamente los KPIs y las gráficas del portal (Dona, Barras, Semanas)
 */
function obtenerMetricasGlobalesRespaldos($pdo) {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'exitoso' THEN 1 ELSE 0 END) as exitosos,
            SUM(CASE WHEN estado = 'vencido' THEN 1 ELSE 0 END) as vencidos,
            SUM(CASE WHEN estado = 'critico' THEN 1 ELSE 0 END) as criticos,
            SUM(CASE WHEN estado = 'incompleto' THEN 1 ELSE 0 END) as incompletos
        FROM respaldos_equipos
        WHERE COALESCE(monitorear, 1) = 1
    ");
    $m = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
    $total = (int)($m['total'] ?? 0);
    $exitosos = (int)($m['exitosos'] ?? 0);
    $vencidos = (int)($m['vencidos'] ?? 0);
    $criticos = (int)($m['criticos'] ?? 0);
    $incompletos = (int)($m['incompletos'] ?? 0);
    $pct = ($total > 0) ? round(($exitosos / $total) * 100, 1) : 0;

    // Equipos por área
    $stmtAreas = $pdo->query("
        SELECT area_nombre, COUNT(*) as c 
        FROM respaldos_equipos 
        WHERE COALESCE(monitorear, 1) = 1
        GROUP BY area_nombre 
        ORDER BY c DESC 
        LIMIT 8
    ");
    $conteoPorArea = [];
    if ($stmtAreas) {
        while ($r = $stmtAreas->fetch(PDO::FETCH_ASSOC)) {
            $conteoPorArea[$r['area_nombre']] = (int)$r['c'];
        }
    }

    // Cumplimiento de semanas calendario del mes actual
    $mesActual = date('Y-m');
    $semanasDef = calcularSemanasCalendarioMes($mesActual);
    $stmtHist = $pdo->prepare("
        SELECT h.fecha 
        FROM respaldos_historial h
        INNER JOIN respaldos_equipos e ON (h.nombre_equipo = e.nombre_equipo)
        WHERE h.fecha LIKE ? AND h.estado = 'exitoso' AND COALESCE(e.monitorear, 1) = 1
    ");
    $stmtHist->execute(["$mesActual%"]);
    $histFechas = $stmtHist ? $stmtHist->fetchAll(PDO::FETCH_COLUMN) : [];

    $semanasChartLabels = [];
    $semanasChartData = [];
    foreach ($semanasDef as $sId => $sConf) {
        $semanasChartLabels[] = $sConf['label'];
        $c = 0;
        foreach ($histFechas as $f) {
            $dNum = (int)date('d', strtotime($f));
            if ($dNum >= $sConf['inicio'] && $dNum <= $sConf['fin']) {
                $c++;
            }
        }
        $semanasChartData[] = $c;
    }

    return [
        'total' => $total,
        'exitosos' => $exitosos,
        'vencidos' => $vencidos,
        'criticos' => $criticos,
        'incompletos' => $incompletos,
        'porcentaje' => $pct,
        'areas_labels' => array_keys($conteoPorArea),
        'areas_data' => array_values($conteoPorArea),
        'semanas_labels' => $semanasChartLabels,
        'semanas_data' => $semanasChartData
    ];
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {

    // =========================================================================
    // 1. ENDPOINTS PARA EL AGENTE COLECTOR LAN (SMB / NAS)
    // =========================================================================

    /**
     * Consulta las rutas NAS y carpetas que el colector local debe vigilar
     */
    case 'obtener_rutas_vigilar':
        if (!validarTokenColector($pdo)) {
            // También permitir si es usuario autenticado en el portal probando
            if (!isset($_SESSION['usuario_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Token Bearer no válido o no proporcionado']);
                exit();
            }
        }

        try {
            // Auto-reparar nombres de carpetas con tildes dañadas en la base de datos (ej. Administración)
            try {
                $pdo->exec("UPDATE respaldos_carpetas SET nombre_carpeta = 'AdministraciónVw' WHERE nombre_carpeta LIKE 'Administraci%nVw' OR nombre_carpeta LIKE '%Administraci%'");
                $pdo->exec("UPDATE respaldos_carpetas SET nombre_carpeta = REPLACE(nombre_carpeta, 'Ã³', 'ó') WHERE nombre_carpeta LIKE '%Ã³%'");
                $pdo->exec("UPDATE respaldos_carpetas SET nombre_carpeta = REPLACE(nombre_carpeta, 'Ã¡', 'á') WHERE nombre_carpeta LIKE '%Ã¡%'");
                $pdo->exec("UPDATE respaldos_carpetas SET nombre_carpeta = REPLACE(nombre_carpeta, 'Ã©', 'é') WHERE nombre_carpeta LIKE '%Ã©%'");
                $pdo->exec("UPDATE respaldos_carpetas SET nombre_carpeta = REPLACE(nombre_carpeta, 'Ã­', 'í') WHERE nombre_carpeta LIKE '%Ã­%'");
                $pdo->exec("UPDATE respaldos_carpetas SET nombre_carpeta = REPLACE(nombre_carpeta, 'Ãº', 'ú') WHERE nombre_carpeta LIKE '%Ãº%'");
                $pdo->exec("UPDATE respaldos_carpetas SET nombre_carpeta = REPLACE(nombre_carpeta, 'Ã±', 'ñ') WHERE nombre_carpeta LIKE '%Ã±%'");
            } catch (Throwable $eRep) {}

            // Cargar configuración
            $stmtConf = $pdo->query("SELECT clave, valor FROM respaldos_config");
            $config = [];
            while ($row = $stmtConf->fetch(PDO::FETCH_ASSOC)) {
                $config[$row['clave']] = $row['valor'];
            }

            // Cargar NAS activos
            $stmtNas = $pdo->query("SELECT id, nombre, ip_ruta_red, usuario_smb, password_smb, marca, notas FROM respaldos_nas WHERE estatus = 1 ORDER BY id ASC");
            $nasList = $stmtNas->fetchAll(PDO::FETCH_ASSOC);

            // Auto-filtrar para vigilar exclusivamente carpetas NetBak
            try {
                // Si existen carpetas con NetBakData, desmarcar las carpetas padre genéricas
                $pdo->exec("
                    UPDATE respaldos_carpetas 
                    SET monitorear = 0 
                    WHERE nombre_carpeta NOT LIKE '%NetBak%' 
                      AND nas_id IN (
                          SELECT DISTINCT nas_id FROM (
                              SELECT nas_id FROM respaldos_carpetas WHERE nombre_carpeta LIKE '%NetBak%'
                          ) as t
                      )
                ");
                // Asegurar que las carpetas NetBakData estén activas para monitorear
                $pdo->exec("
                    UPDATE respaldos_carpetas 
                    SET monitorear = 1 
                    WHERE (nombre_carpeta LIKE '%NetBak%' OR nombre_carpeta LIKE '%netbak%') 
                      AND es_sistema = 0
                ");
                // Limpiar equipos huérfanos que no pertenezcan a NetBak
                $pdo->exec("
                    DELETE FROM respaldos_equipos 
                    WHERE ruta_carpeta IS NOT NULL 
                      AND ruta_carpeta != '' 
                      AND ruta_carpeta NOT LIKE '%NetBak%' 
                      AND ruta_carpeta NOT LIKE '%netbak%'
                ");
            } catch (Throwable $eNet) {}

            // Cargar carpetas activas de cada NAS (priorizando NetBak)
            foreach ($nasList as &$nas) {
                $stmtCarp = $pdo->prepare("
                    SELECT id, nombre_carpeta, ruta_relativa, area_nombre, monitorear 
                    FROM respaldos_carpetas 
                    WHERE nas_id = ? AND monitorear = 1 AND es_sistema = 0 
                      AND (nombre_carpeta LIKE '%NetBak%' OR nombre_carpeta LIKE '%netbak%')
                    ORDER BY nombre_carpeta ASC
                ");
                $stmtCarp->execute([$nas['id']]);
                $nas['carpetas'] = $stmtCarp->fetchAll(PDO::FETCH_ASSOC);

                // Si aún no hay carpetas específicamente NetBak, traer las activas para descubrimiento inicial
                if (empty($nas['carpetas'])) {
                    $stmtCarpAll = $pdo->prepare("
                        SELECT id, nombre_carpeta, ruta_relativa, area_nombre, monitorear 
                        FROM respaldos_carpetas 
                        WHERE nas_id = ? AND monitorear = 1 AND es_sistema = 0 
                        ORDER BY nombre_carpeta ASC
                    ");
                    $stmtCarpAll->execute([$nas['id']]);
                    $nas['carpetas'] = $stmtCarpAll->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            unset($nas);

            $patronesDescarte = array_map('trim', explode(',', $config['patrones_descarte'] ?? '@Recycle,#snapshot,@Recently-Snapshot,.dropbox.cache,aquota.user,aquota.group,Thumbs.db,desktop.ini,.DS_Store'));

            // Lista de nombres de equipos excluidos por el usuario
            $stmtExcl = $pdo->query("SELECT nombre_equipo FROM respaldos_equipos WHERE COALESCE(monitorear, 1) = 0");
            $equiposExcluidos = $stmtExcl ? $stmtExcl->fetchAll(PDO::FETCH_COLUMN) : [];

            echo json_encode([
                'success' => true,
                'fecha_servidor' => date('Y-m-d H:i:s'),
                'nas_list' => $nasList,
                'config' => [
                    'umbral_alerta_horas' => (int)($config['umbral_alerta_horas'] ?? 24),
                    'umbral_critico_horas' => (int)($config['umbral_critico_horas'] ?? 48),
                    'tamano_minimo_valido_mb' => (float)($config['tamano_minimo_valido_mb'] ?? 1),
                    'patrones_descarte' => $patronesDescarte,
                    'equipos_excluidos' => $equiposExcluidos
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Error al consultar rutas: ' . $e->getMessage()]);
        }
        break;

    /**
     * Recibe la lista de carpetas compartidas detectadas en la NAS por el colector local
     */
    case 'reportar_carpetas_detectadas':
        if (!validarTokenColector($pdo)) {
            if (!isset($_SESSION['usuario_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Token Bearer no válido']);
                exit();
            }
        }

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data || !is_array($data) || empty($data['nas_id'])) {
            echo json_encode(['success' => false, 'error' => 'nas_id requerido']);
            exit();
        }

        $nasId = (int)$data['nas_id'];
        $carpetas = $data['carpetas'] ?? [];

        $basura = ['@recycle', '#snapshot', '@recently-snapshot', '.dropbox.cache', 'aquota.user', 'aquota.group', 'thumbs.db', 'desktop.ini', '.ds_store'];
        $nuevas = 0;

        try {
            $pdo->beginTransaction();

            $stmtCheck = $pdo->prepare("SELECT id, area_nombre, monitorear, es_sistema FROM respaldos_carpetas WHERE nas_id = ? AND nombre_carpeta = ? LIMIT 1");
            $stmtIns = $pdo->prepare("INSERT INTO respaldos_carpetas (nas_id, nombre_carpeta, ruta_relativa, area_nombre, monitorear, es_sistema) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($carpetas as $c) {
                $nombre = is_array($c) ? trim($c['nombre_carpeta'] ?? '') : trim($c);
                if (empty($nombre)) continue;

                $esSistema = 0;
                foreach ($basura as $b) {
                    if (stripos($nombre, $b) !== false) {
                        $esSistema = 1;
                        break;
                    }
                }

                // Inferencia automática de área según nombre
                $areaInferencia = 'General';
                $nomLow = strtolower($nombre);
                if (strpos($nomLow, 'conta') !== false) $areaInferencia = 'Contabilidad';
                elseif (strpos($nomLow, 'vent') !== false) $areaInferencia = 'Ventas';
                elseif (strpos($nomLow, 'sist') !== false || strpos($nomLow, 'ti') !== false) $areaInferencia = 'Sistemas';
                elseif (strpos($nomLow, 'admin') !== false) $areaInferencia = 'Administración';
                elseif (strpos($nomLow, 'serv') !== false) $areaInferencia = 'Servicio';
                elseif (strpos($nomLow, 'refac') !== false) $areaInferencia = 'Refacciones';
                elseif (strpos($nomLow, 'geren') !== false) $areaInferencia = 'Gerencia';
                elseif (strpos($nomLow, 'caja') !== false) $areaInferencia = 'Caja';
                elseif (!$esSistema) $areaInferencia = ucfirst($nombre);

                $esNetbak = (stripos($nombre, 'netbak') !== false);
                $monitorear = ($esSistema || !$esNetbak) ? 0 : 1;

                $stmtCheck->execute([$nasId, $nombre]);
                $exist = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if (!$exist) {
                    $stmtIns->execute([$nasId, $nombre, $nombre, $areaInferencia, $monitorear, $esSistema]);
                    $nuevas++;
                }
            }

            // Registrar también las subcarpetas / máquinas dentro de NetBakData si fueron enviadas
            if (!empty($data['subcarpetas_netbak']) && is_array($data['subcarpetas_netbak'])) {
                $stmtCheckEq = $pdo->prepare("SELECT id, area_nombre FROM respaldos_equipos WHERE nombre_equipo = ? LIMIT 1");
                $stmtInsEq = $pdo->prepare("INSERT INTO respaldos_equipos (nombre_equipo, usuario_responsable, area_nombre, nas_id, carpeta_id, ruta_origen, estado, monitorear) VALUES (?, ?, ?, ?, ?, ?, 'critico', 1)");
                $stmtGetCarpId = $pdo->prepare("SELECT id, area_nombre FROM respaldos_carpetas WHERE nas_id = ? AND nombre_carpeta = ? LIMIT 1");

                foreach ($data['subcarpetas_netbak'] as $subEq) {
                    $nomEq = trim($subEq['nombre_equipo'] ?? '');
                    if (empty($nomEq)) continue;
                    $cPadre = trim($subEq['carpeta_padre'] ?? '');
                    $rCarp = trim($subEq['ruta_carpeta'] ?? '');

                    $stmtGetCarpId->execute([$nasId, $cPadre]);
                    $carpInfo = $stmtGetCarpId->fetch(PDO::FETCH_ASSOC);
                    $cId = $carpInfo ? $carpInfo['id'] : null;
                    $areaEq = $carpInfo ? $carpInfo['area_nombre'] : 'General';

                    $stmtCheckEq->execute([$nomEq]);
                    $eqExist = $stmtCheckEq->fetch(PDO::FETCH_ASSOC);
                    if (!$eqExist) {
                        $stmtInsEq->execute([$nomEq, $nomEq, $areaEq, $nasId, $cId, $rCarp]);
                    } else {
                        if ($cId) {
                            $pdo->prepare("UPDATE respaldos_equipos SET carpeta_id = COALESCE(carpeta_id, ?), nas_id = COALESCE(nas_id, ?) WHERE id = ?")
                                ->execute([$cId, $nasId, $eqExist['id']]);
                        }
                    }
                }
            }

            $pdo->commit();

            // Devolver las carpetas actuales del NAS para el frontend
            $stmtAll = $pdo->prepare("SELECT * FROM respaldos_carpetas WHERE nas_id = ? ORDER BY es_sistema ASC, nombre_carpeta ASC");
            $stmtAll->execute([$nasId]);
            $todasCarpetas = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'mensaje' => "Detección completada: $nuevas carpetas nuevas registradas",
                'nuevas' => $nuevas,
                'total' => count($todasCarpetas),
                'carpetas' => $todasCarpetas
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Consulta el estado de detección de carpetas de una NAS (para polling del Wizard)
     */
    case 'obtener_estado_nas':
        validarSesionPortal();
        $nasId = (int)($_GET['nas_id'] ?? ($_POST['nas_id'] ?? 0));
        if ($nasId <= 0) {
            echo json_encode(['success' => false, 'error' => 'nas_id inválido']);
            exit();
        }

        try {
            $stmtNas = $pdo->prepare("SELECT id, nombre, ip_ruta_red, marca FROM respaldos_nas WHERE id = ?");
            $stmtNas->execute([$nasId]);
            $nas = $stmtNas->fetch(PDO::FETCH_ASSOC);

            $stmtCarp = $pdo->prepare("SELECT * FROM respaldos_carpetas WHERE nas_id = ? ORDER BY es_sistema ASC, nombre_carpeta ASC");
            $stmtCarp->execute([$nasId]);
            $carpetas = $stmtCarp->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'nas' => $nas,
                'total_carpetas' => count($carpetas),
                'carpetas' => $carpetas
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Recibe el reporte consolidado de todas las máquinas en un solo envío HTTPS
     */
    case 'reportar_respaldo_consolidado':
        if (!validarTokenColector($pdo)) {
            if (!isset($_SESSION['usuario_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Token Bearer no válido']);
                exit();
            }
        }

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data || !is_array($data)) {
            echo json_encode(['success' => false, 'error' => 'Formato JSON inválido o cuerpo vacío']);
            exit();
        }

        $equipos = $data['equipos'] ?? [];
        $agenteIp = $data['agente_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $duracionSeg = (int)($data['duracion_segundos'] ?? 0);
        $fechaReporte = $data['fecha_escaneo'] ?? date('Y-m-d H:i:s');
        $fechaDia = date('Y-m-d', strtotime($fechaReporte));

        // Obtener umbrales de SLA Semanal (168h = 7 días, 240h = 10 días)
        $stmtConf = $pdo->query("SELECT clave, valor FROM respaldos_config WHERE clave IN ('umbral_alerta_horas', 'umbral_critico_horas')");
        $confMap = $stmtConf->fetchAll(PDO::FETCH_KEY_PAIR);
        $umbralAlerta = (int)($confMap['umbral_alerta_horas'] ?? 168);
        $umbralCritico = (int)($confMap['umbral_critico_horas'] ?? 240);

        $procesados = 0;
        $ahoraTime = time();

        try {
            $pdo->beginTransaction();

            $stmtCheckEq = $pdo->prepare("SELECT id FROM respaldos_equipos WHERE nombre_equipo = ? LIMIT 1");
            $stmtUpdEq = $pdo->prepare("
                UPDATE respaldos_equipos 
                SET usuario_responsable = CASE 
                        WHEN usuario_responsable IS NULL OR usuario_responsable = '' OR usuario_responsable = nombre_equipo 
                        THEN COALESCE(?, usuario_responsable) 
                        ELSE usuario_responsable 
                    END,
                    area_nombre = COALESCE(?, area_nombre),
                    nas_id = ?,
                    carpeta_id = ?,
                    ruta_origen = ?,
                    ultimo_respaldo = ?,
                    archivo_reciente = ?,
                    tamano_bytes = ?,
                    estado = ?,
                    dias_sin_respaldo = ?,
                    tiene_temporales = ?,
                    detalles_temporales = ?,
                    actualizado_en = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmtInsEq = $pdo->prepare("
                INSERT INTO respaldos_equipos (
                    nombre_equipo, usuario_responsable, area_nombre, nas_id, carpeta_id,
                    ruta_origen, ultimo_respaldo, archivo_reciente, tamano_bytes,
                    estado, dias_sin_respaldo, tiene_temporales, detalles_temporales, actualizado_en
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");

            $stmtCheckHist = $pdo->prepare("SELECT id FROM respaldos_historial WHERE nombre_equipo = ? AND fecha = ? LIMIT 1");
            $stmtUpdHist = $pdo->prepare("
                UPDATE respaldos_historial 
                SET fecha_hora = ?, estado = ?, archivo_nombre = ?, tamano_bytes = ?, duracion_seg = ?, nas_id = ?
                WHERE id = ?
            ");
            $stmtInsHist = $pdo->prepare("
                INSERT INTO respaldos_historial (equipo_id, nombre_equipo, nas_id, fecha, fecha_hora, estado, archivo_nombre, tamano_bytes, duracion_seg)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($equipos as $eq) {
                $nombreEquipo = strtoupper(trim($eq['nombre_equipo'] ?? ''));
                if (empty($nombreEquipo)) continue;

                $usuarioResp = !empty($eq['usuario_responsable']) ? trim($eq['usuario_responsable']) : null;
                $areaNombre = !empty($eq['area_nombre']) ? trim($eq['area_nombre']) : 'General';
                $nasId = !empty($eq['nas_id']) ? (int)$eq['nas_id'] : null;
                $carpetaId = !empty($eq['carpeta_id']) ? (int)$eq['carpeta_id'] : null;
                $rutaOrigen = $eq['ruta_carpeta'] ?? ($eq['ruta_origen'] ?? '');
                $ultimoArchivo = $eq['ultimo_archivo_nombre'] ?? ($eq['archivo_reciente'] ?? null);
                $ultimoFecha = !empty($eq['ultimo_archivo_fecha']) ? $eq['ultimo_archivo_fecha'] : null;
                $tamanoBytes = (float)($eq['tamano_bytes'] ?? 0);
                $tieneTemporales = !empty($eq['tiene_temporales']) ? 1 : 0;
                $detallesTemp = !empty($eq['archivos_temporales']) ? json_encode($eq['archivos_temporales'], JSON_UNESCAPED_SLASHES) : null;

                // Calcular estado y días sin respaldo según la regla solicitada:
                // ≤ 6 días: Verde (Al Día / exitoso)
                // 7 a 12 días: Naranja (En Alerta / vencido)
                // ≥ 13 o 14 días: Rojo (Crítico / critico)
                $estado = 'critico';
                $diasSinRespaldo = 99;

                if (!empty($ultimoFecha)) {
                    $mtime = strtotime($ultimoFecha);
                    $fechaUltimoDia = date('Y-m-d', $mtime);
                    $fechaHoyDia = date('Y-m-d');

                    $dtUltimo = new DateTime($fechaUltimoDia);
                    $dtHoy = new DateTime($fechaHoyDia);

                    if ($dtHoy < $dtUltimo) {
                        $diasSinRespaldo = 0;
                    } else {
                        $diasSinRespaldo = (int)$dtHoy->diff($dtUltimo)->days;
                    }

                    if ($tieneTemporales) {
                        $estado = 'incompleto';
                    } elseif ($diasSinRespaldo <= 6) {
                        $estado = 'exitoso';
                    } elseif ($diasSinRespaldo <= 12) {
                        $estado = 'vencido';
                    } else {
                        $estado = 'critico';
                    }
                } else {
                    $estado = $tieneTemporales ? 'incompleto' : 'critico';
                }

                // Upsert Equipo
                $stmtCheckEq->execute([$nombreEquipo]);
                $existingId = $stmtCheckEq->fetchColumn();

                $equipoId = null;
                if ($existingId) {
                    $equipoId = (int)$existingId;
                    $stmtUpdEq->execute([
                        $usuarioResp, $areaNombre, $nasId, $carpetaId, $rutaOrigen,
                        $ultimoFecha, $ultimoArchivo, $tamanoBytes, $estado,
                        $diasSinRespaldo, $tieneTemporales, $detallesTemp, $equipoId
                    ]);
                } else {
                    $stmtInsEq->execute([
                        $nombreEquipo, $usuarioResp, $areaNombre, $nasId, $carpetaId,
                        $rutaOrigen, $ultimoFecha, $ultimoArchivo, $tamanoBytes,
                        $estado, $diasSinRespaldo, $tieneTemporales, $detallesTemp
                    ]);
                    $equipoId = (int)$pdo->lastInsertId();
                }

                // Guardar en el historial el último respaldo detectado si tiene fecha válida
                if (!empty($ultimoFecha)) {
                    $fechaUltimo = date('Y-m-d', strtotime($ultimoFecha));
                    $stmtCheckHist->execute([$nombreEquipo, $fechaUltimo]);
                    $histId = $stmtCheckHist->fetchColumn();

                    if ($histId) {
                        $stmtUpdHist->execute([
                            $ultimoFecha, 'exitoso', $ultimoArchivo, $tamanoBytes, $duracionSeg, $nasId, $histId
                        ]);
                    } else {
                        $stmtInsHist->execute([
                            $equipoId, $nombreEquipo, $nasId, $fechaUltimo, $ultimoFecha, 'exitoso', $ultimoArchivo, $tamanoBytes, $duracionSeg
                        ]);
                    }
                }

                // Guardar historial completo de respaldos reportado por el agente (hasta 12 semanas)
                if (!empty($eq['historial_reciente']) && is_array($eq['historial_reciente'])) {
                    foreach ($eq['historial_reciente'] as $h) {
                        $hFechaHora = $h['fecha_hora'] ?? null;
                        if (empty($hFechaHora)) continue;
                        $hFecha = date('Y-m-d', strtotime($hFechaHora));
                        $hNombre = $h['archivo_nombre'] ?? $hFecha;
                        $hBytes = (float)($h['tamano_bytes'] ?? 0);

                        $stmtCheckHist->execute([$nombreEquipo, $hFecha]);
                        $hId = $stmtCheckHist->fetchColumn();
                        if ($hId) {
                            $stmtUpdHist->execute([
                                $hFechaHora, 'exitoso', $hNombre, $hBytes, 0, $nasId, $hId
                            ]);
                        } else {
                            $stmtInsHist->execute([
                                $equipoId, $nombreEquipo, $nasId, $hFecha, $hFechaHora, 'exitoso', $hNombre, $hBytes, 0
                            ]);
                        }
                    }
                }

                $procesados++;
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'mensaje' => "Reporte consolidado procesado correctamente",
                'procesados' => $procesados,
                'fecha_servidor' => date('Y-m-d H:i:s')
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'error' => 'Error al procesar reporte consolidado: ' . $e->getMessage()]);
        }
        break;

    // =========================================================================
    // 2. ENDPOINTS DE GESTIÓN Y PANEL WEB (ADMINISTRACIÓN DEL PORTAL)
    // =========================================================================

    /**
     * Listado de NAS registrados
     */
    case 'listar_nas':
        validarSesionPortal();
        try {
            $stmt = $pdo->query("
                SELECT n.*, 
                    (SELECT COUNT(*) FROM respaldos_carpetas WHERE nas_id = n.id AND monitorear = 1 AND es_sistema = 0) as total_carpetas,
                    (SELECT COUNT(*) FROM respaldos_equipos WHERE nas_id = n.id) as total_equipos
                FROM respaldos_nas n 
                ORDER BY n.id ASC
            ");
            $nasList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($nasList as &$n) {
                // Ocultar contraseña en listado público
                if (!empty($n['password_smb'])) {
                    $n['tiene_password'] = true;
                    $n['password_smb'] = '••••••••';
                } else {
                    $n['tiene_password'] = false;
                }
            }
            unset($n);

            echo json_encode(['success' => true, 'nas' => $nasList]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Guarda o edita un dispositivo NAS
     */
    case 'guardar_nas':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_editar');

        $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
        $nombre = trim($_POST['nombre'] ?? '');
        $ipRuta = trim($_POST['ip_ruta_red'] ?? '');
        $usuarioSmb = trim($_POST['usuario_smb'] ?? '');
        $passwordSmb = trim($_POST['password_smb'] ?? '');
        $marca = trim($_POST['marca'] ?? 'QNAP');
        $notas = trim($_POST['notas'] ?? '');
        $estatus = isset($_POST['estatus']) ? (int)$_POST['estatus'] : 1;

        if (empty($nombre) || empty($ipRuta)) {
            echo json_encode(['success' => false, 'error' => 'El nombre y la IP o ruta de red son obligatorios']);
            exit();
        }

        try {
            if ($id > 0) {
                if ($passwordSmb === '' || $passwordSmb === '••••••••') {
                    // Mantener la contraseña existente si no se modificó
                    $stmt = $pdo->prepare("UPDATE respaldos_nas SET nombre=?, ip_ruta_red=?, usuario_smb=?, marca=?, notas=?, estatus=?, actualizado_en=CURRENT_TIMESTAMP WHERE id=?");
                    $stmt->execute([$nombre, $ipRuta, $usuarioSmb, $marca, $notas, $estatus, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE respaldos_nas SET nombre=?, ip_ruta_red=?, usuario_smb=?, password_smb=?, marca=?, notas=?, estatus=?, actualizado_en=CURRENT_TIMESTAMP WHERE id=?");
                    $stmt->execute([$nombre, $ipRuta, $usuarioSmb, $passwordSmb, $marca, $notas, $estatus, $id]);
                }
                echo json_encode(['success' => true, 'id' => $id, 'mensaje' => 'Dispositivo NAS actualizado correctamente']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO respaldos_nas (nombre, ip_ruta_red, usuario_smb, password_smb, marca, notas, estatus) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $ipRuta, $usuarioSmb, $passwordSmb, $marca, $notas, $estatus]);
                $nuevoId = (int)$pdo->lastInsertId();
                echo json_encode(['success' => true, 'id' => $nuevoId, 'mensaje' => 'Dispositivo NAS registrado con éxito']);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Error al guardar NAS: ' . $e->getMessage()]);
        }
        break;

    /**
     * Elimina un dispositivo NAS y sus carpetas asociadas
     */
    case 'eliminar_nas':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_eliminar');

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido']);
            exit();
        }

        try {
            $pdo->beginTransaction();
            $stmtC = $pdo->prepare("DELETE FROM respaldos_carpetas WHERE nas_id = ?");
            $stmtC->execute([$id]);

            $stmtN = $pdo->prepare("DELETE FROM respaldos_nas WHERE id = ?");
            $stmtN->execute([$id]);
            $pdo->commit();

            echo json_encode(['success' => true, 'mensaje' => 'Dispositivo NAS eliminado con éxito']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Listado de carpetas por NAS
     */
    case 'listar_carpetas':
        validarSesionPortal();
        $nasId = (int)($_GET['nas_id'] ?? ($_POST['nas_id'] ?? 0));

        try {
            $sql = "SELECT c.*, n.nombre as nas_nombre FROM respaldos_carpetas c JOIN respaldos_nas n ON c.nas_id = n.id";
            $params = [];
            if ($nasId > 0) {
                $sql .= " WHERE c.nas_id = ?";
                $params[] = $nasId;
            }
            $sql .= " ORDER BY c.nas_id ASC, c.es_sistema ASC, c.nombre_carpeta ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $carpetas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Obtener subcarpetas / máquinas pertenecientes a cada carpeta
            $stmtEq = $pdo->prepare("
                SELECT id, nombre_equipo, usuario_responsable, area_nombre, nas_id, carpeta_id, ruta_origen, ultimo_respaldo, estado, dias_sin_respaldo, COALESCE(monitorear, 1) as monitorear
                FROM respaldos_equipos
                WHERE carpeta_id = ? 
                   OR (nas_id = ? AND (ruta_origen LIKE ? OR area_nombre = ?))
                ORDER BY nombre_equipo ASC
            ");

            foreach ($carpetas as &$carp) {
                $stmtEq->execute([$carp['id'], $carp['nas_id'], '%' . $carp['nombre_carpeta'] . '%', $carp['area_nombre']]);
                $subs = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
                $carp['subcarpetas_netbak'] = $subs;
                $carp['total_subcarpetas'] = count($subs);
                $carp['total_monitoreadas'] = count(array_filter($subs, function($s) { return (int)($s['monitorear'] ?? 1) === 1; }));
            }
            unset($carp);

            echo json_encode(['success' => true, 'carpetas' => $carpetas]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Activa o desactiva el monitoreo de una subcarpeta / máquina individual dentro de NetBakData
     */
    case 'toggle_monitorear_equipo':
        validarSesionPortal();
        $equipoId = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
        $monitorear = (int)($_POST['monitorear'] ?? ($_GET['monitorear'] ?? 1));

        if ($equipoId <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de equipo inválido']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE respaldos_equipos SET monitorear = ? WHERE id = ?");
            $stmt->execute([$monitorear ? 1 : 0, $equipoId]);

            $metricasActuales = obtenerMetricasGlobalesRespaldos($pdo);

            echo json_encode([
                'success' => true,
                'mensaje' => $monitorear ? 'Equipo activado para monitoreo' : 'Equipo excluido del monitoreo',
                'equipo_id' => $equipoId,
                'monitorear' => $monitorear ? 1 : 0,
                'metricas' => $metricasActuales
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Activa o desactiva todas las subcarpetas / máquinas de una carpeta NetBakData
     */
    case 'toggle_todas_subcarpetas':
        validarSesionPortal();
        $carpetaId = (int)($_POST['carpeta_id'] ?? ($_GET['carpeta_id'] ?? 0));
        $monitorear = (int)($_POST['monitorear'] ?? ($_GET['monitorear'] ?? 1));

        if ($carpetaId <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de carpeta inválido']);
            exit();
        }

        try {
            $stmtC = $pdo->prepare("SELECT nas_id, nombre_carpeta, area_nombre FROM respaldos_carpetas WHERE id = ?");
            $stmtC->execute([$carpetaId]);
            $carp = $stmtC->fetch(PDO::FETCH_ASSOC);

            if ($carp) {
                $stmt = $pdo->prepare("
                    UPDATE respaldos_equipos 
                    SET monitorear = ? 
                    WHERE carpeta_id = ? OR (nas_id = ? AND (ruta_origen LIKE ? OR area_nombre = ?))
                ");
                $stmt->execute([$monitorear ? 1 : 0, $carpetaId, $carp['nas_id'], '%' . $carp['nombre_carpeta'] . '%', $carp['area_nombre']]);
            }

            $metricasActuales = obtenerMetricasGlobalesRespaldos($pdo);

            echo json_encode([
                'success' => true,
                'mensaje' => $monitorear ? 'Todas las subcarpetas activadas' : 'Todas las subcarpetas excluidas',
                'carpeta_id' => $carpetaId,
                'monitorear' => $monitorear ? 1 : 0,
                'metricas' => $metricasActuales
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Guarda o edita un equipo (Nombre/Alias, Usuario Responsable, Área)
     */
    case 'guardar_equipo':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_editar');

        $id = (int)($_POST['id'] ?? 0);
        $nombre = strtoupper(trim($_POST['nombre_equipo'] ?? ''));
        $usuario = trim($_POST['usuario_responsable'] ?? '');
        $area = trim($_POST['area_nombre'] ?? 'General');

        if ($id <= 0 || empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'ID y Nombre de Equipo son obligatorios']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE respaldos_equipos SET nombre_equipo = ?, usuario_responsable = ?, area_nombre = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$nombre, $usuario, $area, $id]);
            echo json_encode(['success' => true, 'mensaje' => 'Equipo actualizado con éxito']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Activa o desactiva el monitoreo de una carpeta completa (y sus subcarpetas asociadas)
     */
    case 'toggle_monitorear_carpeta':
        validarSesionPortal();
        $carpetaId = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
        $monitorear = (int)($_POST['monitorear'] ?? ($_GET['monitorear'] ?? 1));

        if ($carpetaId <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de carpeta inválido']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE respaldos_carpetas SET monitorear = ? WHERE id = ?");
            $stmt->execute([$monitorear ? 1 : 0, $carpetaId]);

            // Sincronizar las subcarpetas / máquinas de esta carpeta
            $stmtC = $pdo->prepare("SELECT nas_id, nombre_carpeta, area_nombre FROM respaldos_carpetas WHERE id = ?");
            $stmtC->execute([$carpetaId]);
            $carp = $stmtC->fetch(PDO::FETCH_ASSOC);
            if ($carp) {
                $stmtEq = $pdo->prepare("
                    UPDATE respaldos_equipos 
                    SET monitorear = ? 
                    WHERE carpeta_id = ? OR (nas_id = ? AND (ruta_origen LIKE ? OR area_nombre = ?))
                ");
                $stmtEq->execute([$monitorear ? 1 : 0, $carpetaId, $carp['nas_id'], '%' . $carp['nombre_carpeta'] . '%', $carp['area_nombre']]);
            }

            $metricasActuales = obtenerMetricasGlobalesRespaldos($pdo);

            echo json_encode([
                'success' => true,
                'carpeta_id' => $carpetaId,
                'monitorear' => $monitorear ? 1 : 0,
                'metricas' => $metricasActuales
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Guarda o edita una carpeta individual
     */
    case 'guardar_carpeta':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_editar');

        $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
        $nasId = (int)($_POST['nas_id'] ?? 0);
        $nombre = trim($_POST['nombre_carpeta'] ?? '');
        $area = trim($_POST['area_nombre'] ?? 'General');
        $monitorear = isset($_POST['monitorear']) ? (int)$_POST['monitorear'] : 1;

        if ($nasId <= 0 || empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'Selecciona un NAS y nombre de carpeta']);
            exit();
        }

        // Evaluar si es carpeta basura del sistema
        $esSistema = 0;
        $basura = ['@recycle', '#snapshot', '@recently-snapshot', '.dropbox.cache', 'aquota.user', 'aquota.group', 'thumbs.db', 'desktop.ini', '.ds_store'];
        foreach ($basura as $b) {
            if (stripos($nombre, $b) !== false) {
                $esSistema = 1;
                $monitorear = 0;
                break;
            }
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE respaldos_carpetas SET nombre_carpeta=?, area_nombre=?, monitorear=?, es_sistema=?, actualizado_en=CURRENT_TIMESTAMP WHERE id=?");
                $stmt->execute([$nombre, $area, $monitorear, $esSistema, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO respaldos_carpetas (nas_id, nombre_carpeta, ruta_relativa, area_nombre, monitorear, es_sistema) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nasId, $nombre, $nombre, $area, $monitorear, $esSistema]);
            }
            echo json_encode(['success' => true, 'mensaje' => 'Carpeta guardada correctamente']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Actualiza o crea carpetas en lote (utilizado por el Asistente Inicial)
     */
    case 'guardar_carpetas_lote':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_editar');

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data || !isset($data['nas_id']) || !isset($data['carpetas'])) {
            echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
            exit();
        }

        $nasId = (int)$data['nas_id'];
        $carpetas = $data['carpetas'];

        try {
            $pdo->beginTransaction();

            $stmtCheck = $pdo->prepare("SELECT id FROM respaldos_carpetas WHERE nas_id = ? AND nombre_carpeta = ? LIMIT 1");
            $stmtUpd = $pdo->prepare("UPDATE respaldos_carpetas SET area_nombre = ?, monitorear = ?, es_sistema = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?");
            $stmtIns = $pdo->prepare("INSERT INTO respaldos_carpetas (nas_id, nombre_carpeta, ruta_relativa, area_nombre, monitorear, es_sistema) VALUES (?, ?, ?, ?, ?, ?)");

            $basura = ['@recycle', '#snapshot', '@recently-snapshot', '.dropbox.cache', 'aquota.user', 'aquota.group', 'thumbs.db', 'desktop.ini', '.ds_store'];

            foreach ($carpetas as $c) {
                $nombre = trim($c['nombre_carpeta'] ?? '');
                if (empty($nombre)) continue;

                $area = trim($c['area_nombre'] ?? 'General');
                $monitorear = !empty($c['monitorear']) ? 1 : 0;

                $esSistema = 0;
                foreach ($basura as $b) {
                    if (stripos($nombre, $b) !== false) {
                        $esSistema = 1;
                        $monitorear = 0;
                        break;
                    }
                }

                $stmtCheck->execute([$nasId, $nombre]);
                $existId = $stmtCheck->fetchColumn();

                if ($existId) {
                    $stmtUpd->execute([$area, $monitorear, $esSistema, $existId]);
                } else {
                    $stmtIns->execute([$nasId, $nombre, $nombre, $area, $monitorear, $esSistema]);
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'mensaje' => 'Carpetas configuradas correctamente']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Elimina una carpeta del catálogo
     */
    case 'eliminar_carpeta':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_eliminar');

        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM respaldos_carpetas WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'mensaje' => 'Carpeta eliminada']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Obtiene el expediente de una máquina y su historial de los últimos 90 días
     */
    case 'obtener_historial_equipo':
        validarSesionPortal();
        $nombreEquipo = strtoupper(trim($_GET['nombre_equipo'] ?? ($_POST['nombre_equipo'] ?? '')));
        $equipoId = (int)($_GET['equipo_id'] ?? ($_POST['equipo_id'] ?? 0));

        if (empty($nombreEquipo) && $equipoId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Se requiere nombre o ID de equipo']);
            exit();
        }

        try {
            // Datos del equipo
            if ($equipoId > 0) {
                $stmtEq = $pdo->prepare("SELECT e.*, n.nombre as nas_nombre, n.marca as nas_marca FROM respaldos_equipos e LEFT JOIN respaldos_nas n ON e.nas_id = n.id WHERE e.id = ? LIMIT 1");
                $stmtEq->execute([$equipoId]);
            } else {
                $stmtEq = $pdo->prepare("SELECT e.*, n.nombre as nas_nombre, n.marca as nas_marca FROM respaldos_equipos e LEFT JOIN respaldos_nas n ON e.nas_id = n.id WHERE e.nombre_equipo = ? LIMIT 1");
                $stmtEq->execute([$nombreEquipo]);
            }
            $equipo = $stmtEq->fetch(PDO::FETCH_ASSOC);

            if (!$equipo) {
                echo json_encode(['success' => false, 'error' => 'Equipo no encontrado']);
                exit();
            }

            $nombreReal = $equipo['nombre_equipo'];

            // Historial de los últimos 90 días
            $hace90Dias = date('Y-m-d', strtotime('-90 days'));
            $stmtHist = $pdo->prepare("
                SELECT h.*, n.nombre as nas_nombre 
                FROM respaldos_historial h 
                LEFT JOIN respaldos_nas n ON h.nas_id = n.id
                WHERE h.nombre_equipo = ? AND h.fecha >= ?
                ORDER BY h.fecha DESC
            ");
            $stmtHist->execute([$nombreReal, $hace90Dias]);
            $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

            // Calcular efectividad y métricas de SLA
            $diasEvaluados = 90;
            $diasExitosos = 0;
            $diasVencidos = 0;
            $diasCriticos = 0;
            $diasIncompletos = 0;
            $sumaBytes = 0;

            foreach ($historial as $h) {
                if ($h['estado'] === 'exitoso') $diasExitosos++;
                elseif ($h['estado'] === 'vencido') $diasVencidos++;
                elseif ($h['estado'] === 'incompleto') $diasIncompletos++;
                else $diasCriticos++;

                $sumaBytes += (float)($h['tamano_bytes'] ?? 0);
            }

            $porcentajeSla = ($diasEvaluados > 0) ? round(($diasExitosos / $diasEvaluados) * 100, 1) : 0;
            $promedioBytes = (count($historial) > 0) ? round($sumaBytes / count($historial)) : 0;

            echo json_encode([
                'success' => true,
                'equipo' => $equipo,
                'metricas' => [
                    'dias_evaluados' => $diasEvaluados,
                    'dias_exitosos' => $diasExitosos,
                    'dias_vencidos' => $diasVencidos,
                    'dias_criticos' => $diasCriticos,
                    'dias_incompletos' => $diasIncompletos,
                    'porcentaje_sla' => $porcentajeSla,
                    'promedio_tamano_bytes' => $promedioBytes
                ],
                'historial' => $historial
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Matriz de calor (Heatmap) de los últimos 30 días para todos los equipos
     */
    case 'obtener_heatmap_30dias':
        validarSesionPortal();
        try {
            $dias = [];
            for ($i = 29; $i >= 0; $i--) {
                $dias[] = date('Y-m-d', strtotime("-$i days"));
            }

            $hace30Dias = $dias[0];

            $stmtEq = $pdo->query("SELECT id, nombre_equipo, area_nombre, estado, ultimo_respaldo FROM respaldos_equipos ORDER BY area_nombre ASC, nombre_equipo ASC");
            $equipos = $stmtEq->fetchAll(PDO::FETCH_ASSOC);

            $stmtHist = $pdo->prepare("
                SELECT nombre_equipo, fecha, estado 
                FROM respaldos_historial 
                WHERE fecha >= ?
            ");
            $stmtHist->execute([$hace30Dias]);
            $histRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

            // Mapear historial [equipo][fecha] = estado
            $histMap = [];
            foreach ($histRows as $r) {
                $histMap[$r['nombre_equipo']][$r['fecha']] = $r['estado'];
            }

            // Construir matriz
            $matriz = [];
            foreach ($equipos as $eq) {
                $nom = $eq['nombre_equipo'];
                $calendario = [];
                foreach ($dias as $d) {
                    $calendario[$d] = $histMap[$nom][$d] ?? 'sin_datos';
                }
                $matriz[] = [
                    'equipo_id' => $eq['id'],
                    'nombre_equipo' => $nom,
                    'area_nombre' => $eq['area_nombre'],
                    'estado_actual' => $eq['estado'],
                    'dias' => $calendario
                ];
            }

            echo json_encode([
                'success' => true,
                'rango_fechas' => $dias,
                'matriz' => $matriz
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Top 5 de equipos con más días sin respaldar
     */
    /**
     * Matriz Semanal de Cumplimiento con Filtro por Mes (S1, S2, S3, S4, S5)
     */
    case 'obtener_matriz_semanal_mes':
        validarSesionPortal();
        try {
            $mesSeleccionado = trim($_GET['mes'] ?? date('Y-m'));
            if (!preg_match('/^\d{4}-\d{2}$/', $mesSeleccionado)) {
                $mesSeleccionado = date('Y-m');
            }

            $timestampMes = strtotime("$mesSeleccionado-01");
            $totalDiasMes = (int)date('t', $timestampMes);
            $esMesActual = ($mesSeleccionado === date('Y-m'));
            $diaHoy = (int)date('d');

            // Definición dinámica de semanas calendario (Domingo a Sábado)
            $semanasDef = calcularSemanasCalendarioMes($mesSeleccionado);

            $stmtEq = $pdo->query("SELECT id, nombre_equipo, usuario_responsable, area_nombre, estado, ultimo_respaldo, dias_sin_respaldo, tiene_temporales FROM respaldos_equipos WHERE COALESCE(monitorear, 1) = 1 ORDER BY area_nombre ASC, nombre_equipo ASC");
            $equipos = $stmtEq->fetchAll(PDO::FETCH_ASSOC);

            // Obtener respaldos históricos del mes seleccionado
            $inicioMesStr = "$mesSeleccionado-01";
            $finMesStr = "$mesSeleccionado-$totalDiasMes";
            $stmtHist = $pdo->prepare("
                SELECT nombre_equipo, fecha, fecha_hora, estado, archivo_nombre, tamano_bytes
                FROM respaldos_historial 
                WHERE fecha BETWEEN ? AND ?
                ORDER BY fecha DESC, fecha_hora DESC
            ");
            $stmtHist->execute([$inicioMesStr, $finMesStr]);
            $histRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

            $histPorEquipo = [];
            foreach ($histRows as $r) {
                $k = strtoupper($r['nombre_equipo']);
                $histPorEquipo[$k][] = $r;
            }

            // Lista de meses disponibles para el selector (últimos 12 meses)
            $mesesDisponibles = [];
            for ($m = 0; $m < 12; $m++) {
                $ym = date('Y-m', strtotime("-$m months"));
                $mesesNombres = [
                    '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
                    '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
                    '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
                ];
                $partesM = explode('-', $ym);
                $nomL = ($mesesNombres[$partesM[1]] ?? $partesM[1]) . ' ' . $partesM[0];
                $mesesDisponibles[] = [
                    'valor' => $ym,
                    'etiqueta' => $nomL,
                    'activo' => ($ym === $mesSeleccionado)
                ];
            }

            $matriz = [];
            foreach ($equipos as $eq) {
                $nomKey = strtoupper($eq['nombre_equipo']);
                $histEq = $histPorEquipo[$nomKey] ?? [];
                
                // Recalcular días de forma confiable
                $diasSinRespaldo = 99;
                if (!empty($eq['ultimo_respaldo'])) {
                    $diasSinRespaldo = (int)(new DateTime(date('Y-m-d')))->diff(new DateTime(date('Y-m-d', strtotime($eq['ultimo_respaldo']))))->days;
                }

                $semanasRes = [];
                $cumplidas = 0;
                $evaluables = 0;

                foreach ($semanasDef as $sId => $sConf) {
                    $item = [
                        'id' => $sId,
                        'label' => $sConf['label'],
                        'dias' => $sConf['dias_label'],
                        'estado' => 'sin_respaldo',
                        'fecha_respaldo' => null,
                        'fecha_corta' => null,
                        'archivo' => null
                    ];

                    $esFuturo = false;
                    $enCurso = false;

                    if ($esMesActual) {
                        if ($diaHoy < $sConf['inicio']) {
                            $esFuturo = true;
                        } elseif ($diaHoy >= $sConf['inicio'] && $diaHoy <= $sConf['fin']) {
                            $enCurso = true;
                        }
                    } elseif ($mesSeleccionado > date('Y-m')) {
                        $esFuturo = true;
                    }

                    // Buscar respaldo que caiga en esta semana
                    $respaldoEncontrado = null;
                    foreach ($histEq as $h) {
                        $dNum = (int)date('d', strtotime($h['fecha']));
                        if ($dNum >= $sConf['inicio'] && $dNum <= $sConf['fin']) {
                            $respaldoEncontrado = $h;
                            break;
                        }
                    }

                    // También comprobar si el ultimo_respaldo del equipo cae en esta semana
                    if (!$respaldoEncontrado && !empty($eq['ultimo_respaldo'])) {
                        $fUlt = date('Y-m-d', strtotime($eq['ultimo_respaldo']));
                        if (strpos($fUlt, $mesSeleccionado) === 0) {
                            $dNum = (int)date('d', strtotime($fUlt));
                            if ($dNum >= $sConf['inicio'] && $dNum <= $sConf['fin']) {
                                $respaldoEncontrado = [
                                    'fecha' => $fUlt,
                                    'fecha_hora' => $eq['ultimo_respaldo'],
                                    'archivo_nombre' => $eq['archivo_reciente'] ?? 'Respaldo'
                                ];
                            }
                        }
                    }

                    if ($respaldoEncontrado) {
                        $item['estado'] = 'exitoso';
                        $item['fecha_respaldo'] = $respaldoEncontrado['fecha_hora'];
                        $item['fecha_corta'] = date('d/m', strtotime($respaldoEncontrado['fecha']));
                        $item['archivo'] = $respaldoEncontrado['archivo_nombre'] ?? '';
                        $cumplidas++;
                        $evaluables++;
                    } elseif ($esFuturo) {
                        $item['estado'] = 'futuro';
                    } elseif ($enCurso && $diasSinRespaldo <= 6) {
                        $item['estado'] = 'en_curso';
                        $cumplidas++;
                        $evaluables++;
                    } else {
                        $item['estado'] = 'sin_respaldo';
                        $evaluables++;
                    }

                    $semanasRes[$sId] = $item;
                }

                $porcentaje = ($evaluables > 0) ? round(($cumplidas / $evaluables) * 100) : 0;

                // Estado semáforo en tiempo real
                $estadoReal = 'critico';
                if (!empty($eq['tiene_temporales'])) {
                    $estadoReal = 'incompleto';
                } elseif ($diasSinRespaldo <= 6) {
                    $estadoReal = 'exitoso';
                } elseif ($diasSinRespaldo <= 12) {
                    $estadoReal = 'vencido';
                }

                $matriz[] = [
                    'equipo_id' => $eq['id'],
                    'nombre_equipo' => $eq['nombre_equipo'],
                    'usuario_responsable' => $eq['usuario_responsable'],
                    'area_nombre' => $eq['area_nombre'],
                    'estado_actual' => $estadoReal,
                    'dias_sin_respaldo' => $diasSinRespaldo,
                    'ultimo_respaldo' => $eq['ultimo_respaldo'],
                    'cumplidas' => $cumplidas,
                    'total_evaluables' => $evaluables,
                    'porcentaje' => $porcentaje,
                    'cumplimiento_porcentaje' => $porcentaje,
                    'semanas' => $semanasRes
                ];
            }

            echo json_encode([
                'success' => true,
                'mes_seleccionado' => $mesSeleccionado,
                'meses_disponibles' => $mesesDisponibles,
                'semanas_def' => $semanasDef,
                'semanas_columnas' => array_values($semanasDef),
                'matriz' => $matriz
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'obtener_top5_criticos':
        validarSesionPortal();
        try {
            $stmt = $pdo->query("
                SELECT e.*, n.nombre as nas_nombre 
                FROM respaldos_equipos e
                LEFT JOIN respaldos_nas n ON e.nas_id = n.id
                WHERE e.estado IN ('critico', 'vencido', 'incompleto')
                ORDER BY e.dias_sin_respaldo DESC, e.ultimo_respaldo ASC 
                LIMIT 5
            ");
            $top5 = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'top5' => $top5]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Regenera el Bearer Token para el agente colector LAN
     */
    case 'regenerar_token':
        validarSesionPortal();
        $rol = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
        if (!in_array($rol, ['admin', 'superadmin'])) {
            echo json_encode(['success' => false, 'error' => 'Solo administradores pueden regenerar el token']);
            exit();
        }

        try {
            $nuevoToken = bin2hex(random_bytes(24));
            $stmt = $pdo->prepare("UPDATE respaldos_config SET valor = ? WHERE clave = 'token_api_colector'");
            $stmt->execute([$nuevoToken]);
            echo json_encode(['success' => true, 'token' => $nuevoToken, 'mensaje' => 'Token API regenerado con éxito']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Guarda la configuración de umbrales
     */
    case 'guardar_config':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_editar');

        $alerta = (int)($_POST['umbral_alerta_horas'] ?? 24);
        $critico = (int)($_POST['umbral_critico_horas'] ?? 48);
        $tamano = (float)($_POST['tamano_minimo_valido_mb'] ?? 1);
        $descarte = trim($_POST['patrones_descarte'] ?? '');

        try {
            $configs = [
                'umbral_alerta_horas' => $alerta,
                'umbral_critico_horas' => $critico,
                'tamano_minimo_valido_mb' => $tamano,
                'patrones_descarte' => $descarte
            ];

            $stmt = $pdo->prepare("UPDATE respaldos_config SET valor = ? WHERE clave = ?");
            foreach ($configs as $k => $v) {
                $stmt->execute([(string)$v, $k]);
            }

            echo json_encode(['success' => true, 'mensaje' => 'Configuración actualizada']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Exportación de reporte a archivo CSV compatible con Excel
     */
    case 'exportar_csv':
        validarSesionPortal();
        requerirPermiso('respaldos', 'puede_exportar');

        $filtroArea = trim($_GET['area'] ?? '');
        $filtroNas = (int)($_GET['nas_id'] ?? 0);
        $filtroEstado = trim($_GET['estado'] ?? '');

        try {
            $sql = "
                SELECT e.nombre_equipo, e.usuario_responsable, e.area_nombre, 
                       n.nombre as nas_nombre, e.ultimo_respaldo, e.archivo_reciente, 
                       e.tamano_bytes, e.estado, e.dias_sin_respaldo, e.tiene_temporales
                FROM respaldos_equipos e
                LEFT JOIN respaldos_nas n ON e.nas_id = n.id
                WHERE 1=1
            ";
            $params = [];

            if (!empty($filtroArea)) {
                $sql .= " AND e.area_nombre = ?";
                $params[] = $filtroArea;
            }
            if ($filtroNas > 0) {
                $sql .= " AND e.nas_id = ?";
                $params[] = $filtroNas;
            }
            if (!empty($filtroEstado)) {
                $sql .= " AND e.estado = ?";
                $params[] = $filtroEstado;
            }

            $sql .= " ORDER BY e.area_nombre ASC, e.nombre_equipo ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generar archivo CSV en memoria con UTF-8 BOM
            $filename = "Reporte_Auditoria_Respaldos_" . date('Y-m-d_His') . ".csv";
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');
            // BOM UTF-8 para apertura correcta en Microsoft Excel
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            // Encabezados
            fputcsv($output, [
                'Equipo',
                'Usuario Responsable',
                'Área / Departamento',
                'NAS Destino',
                'Último Respaldo',
                'Archivo Más Reciente',
                'Tamaño (MB)',
                'Estado Actual',
                'Días Sin Respaldo',
                'Tiene Temporales / Incompletos'
            ]);

            foreach ($rows as $r) {
                $mb = !empty($r['tamano_bytes']) ? round($r['tamano_bytes'] / (1024 * 1024), 2) : 0;
                $estadoTexto = strtoupper($r['estado']);
                if ($r['estado'] === 'exitoso') $estadoTexto = 'EXITOSO (AL DIA)';
                elseif ($r['estado'] === 'vencido') $estadoTexto = 'VENCIDO (>24H)';
                elseif ($r['estado'] === 'critico') $estadoTexto = 'CRITICO (>48H)';
                elseif ($r['estado'] === 'incompleto') $estadoTexto = 'INCOMPLETO (.TMP)';

                fputcsv($output, [
                    $r['nombre_equipo'],
                    $r['usuario_responsable'] ?: 'No asignado',
                    $r['area_nombre'],
                    $r['nas_nombre'] ?: 'No asignado',
                    $r['ultimo_respaldo'] ?: 'Nunca',
                    $r['archivo_reciente'] ?: 'Ninguno',
                    $mb,
                    $estadoTexto,
                    $r['dias_sin_respaldo'],
                    $r['tiene_temporales'] ? 'SI' : 'NO'
                ]);
            }

            fclose($output);
            exit();
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /**
     * Descarga el paquete ZIP completo con scripts y configuración listos
     */
    case 'descargar_paquete_zip':
        validarSesionPortal();

        $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $apiUrl = $protocolo . $host . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/api_respaldos.php';

        $stTok = $pdo->query("SELECT valor FROM respaldos_config WHERE clave = 'token_api_colector' LIMIT 1");
        $token = $stTok ? $stTok->fetchColumn() : 'token_error';

        $jsonConf = json_encode([
            'api_url' => $apiUrl,
            'api_token' => $token,
            'timeout_segundos' => 45,
            'verificar_ssl' => true,
            'extensiones_temporales' => [".tmp", ".temp", ".part", ".partial", ".crdownload", ".aria2", "~$"],
            'patrones_descarte' => ["@recycle", "#snapshot", "@recently-snapshot", ".dropbox.cache", "aquota.user", "aquota.group", "thumbs.db", "desktop.ini", ".ds_store"]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $batDescubrir = "@echo off\r\ntitle Detectar Carpetas NAS\r\necho Conectando con la NAS local y enviando carpetas al portal cPanel...\r\npowershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%~dp0colector_respaldos.ps1\" -ApiUrl \"$apiUrl\" -ApiToken \"$token\" -Descubrir\r\necho.\r\necho Proceso terminado. Presiona cualquier tecla para salir.\r\npause >nul\r\n";
        $batRespaldo = "@echo off\r\ntitle Escaneo Diario de Respaldos\r\necho Ejecutando escaneo de respaldos y enviando semaforo a cPanel...\r\npowershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%~dp0colector_respaldos.ps1\" -ApiUrl \"$apiUrl\" -ApiToken \"$token\"\r\necho.\r\npause >nul\r\n";

        $leeme = "=====================================================================\r\nAGENTE COLECTOR DE RESPALDOS SMB/NAS (RED LOCAL LAN)\r\n=====================================================================\r\n\r\n1. PASO 1 (DETECCION DE CARPETAS NAS):\r\n   Haz doble clic sobre 'ejecutar_deteccion.bat'.\r\n   El script se conectara por SMB a tu NAS local, listara las carpetas\r\n   compartidas reales y las enviara a tu portal en cPanel.\r\n\r\n2. PASO 2 (ESCANEO DIARIO AUTOMATICO):\r\n   Programa en el Administrador de Tareas de Windows (Task Scheduler)\r\n   la ejecucion diaria de 'ejecutar_respaldo_diario.bat' o 'colector_respaldos.ps1'.\r\n\r\nAPI URL configurada: $apiUrl\r\nToken configurado: $token\r\n";

        $ps1Base = file_exists(__DIR__ . '/colector_respaldos.ps1') ? file_get_contents(__DIR__ . '/colector_respaldos.ps1') : '';
        $pyBase = file_exists(__DIR__ . '/colector_respaldos.py') ? file_get_contents(__DIR__ . '/colector_respaldos.py') : '';

        $ps1Personalizado = $ps1Base;
        $pyPersonalizado = $pyBase;

        $tmpZip = tempnam(sys_get_temp_dir(), 'colector_zip_');
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('colector_config.json', $jsonConf);
            $zip->addFromString('ejecutar_deteccion.bat', $batDescubrir);
            $zip->addFromString('ejecutar_respaldo_diario.bat', $batRespaldo);
            $zip->addFromString('colector_respaldos.ps1', $ps1Personalizado);
            $zip->addFromString('colector_respaldos.py', $pyPersonalizado);
            $zip->addFromString('LEEME_INSTRUCCIONES.txt', $leeme);
            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="Agente_Colector_Respaldos_LAN.zip"');
            header('Content-Length: ' . filesize($tmpZip));
            readfile($tmpZip);
            @unlink($tmpZip);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => 'No se pudo generar el archivo ZIP']);
            exit();
        }
        break;

    /**
     * Descarga un archivo individual preconfigurado
     */
    case 'descargar_script':
        validarSesionPortal();
        $tipo = strtolower(trim($_GET['tipo'] ?? 'ps1'));

        $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $apiUrl = $protocolo . $host . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/api_respaldos.php';

        $stTok = $pdo->query("SELECT valor FROM respaldos_config WHERE clave = 'token_api_colector' LIMIT 1");
        $token = $stTok ? $stTok->fetchColumn() : 'token_error';

        if ($tipo === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="colector_config.json"');
            echo json_encode([
                'api_url' => $apiUrl,
                'api_token' => $token,
                'timeout_segundos' => 45,
                'verificar_ssl' => true,
                'extensiones_temporales' => [".tmp", ".temp", ".part", ".partial", ".crdownload", ".aria2", "~$"],
                'patrones_descarte' => ["@recycle", "#snapshot", "@recently-snapshot", ".dropbox.cache", "aquota.user", "aquota.group", "thumbs.db", "desktop.ini", ".ds_store"]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit();
        } elseif ($tipo === 'bat') {
            header('Content-Type: application/x-bat');
            header('Content-Disposition: attachment; filename="ejecutar_deteccion.bat"');
            echo "@echo off\r\ntitle Detectar Carpetas NAS\r\necho Conectando con la NAS local y enviando carpetas al portal cPanel...\r\npowershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%~dp0colector_respaldos.ps1\" -ApiUrl \"$apiUrl\" -ApiToken \"$token\" -Descubrir\r\necho.\r\necho Proceso terminado. Presiona cualquier tecla para salir.\r\npause >nul\r\n";
            exit();
        } elseif ($tipo === 'py') {
            header('Content-Type: text/x-python; charset=utf-8');
            header('Content-Disposition: attachment; filename="colector_respaldos.py"');
            $pyBase = file_exists(__DIR__ . '/colector_respaldos.py') ? file_get_contents(__DIR__ . '/colector_respaldos.py') : '';
            echo $pyBase;
            exit();
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="colector_respaldos.ps1"');
            $ps1Base = file_exists(__DIR__ . '/colector_respaldos.ps1') ? file_get_contents(__DIR__ . '/colector_respaldos.ps1') : '';
            echo $ps1Base;
            exit();
        }
        break;

    default:
        echo json_encode([
            'success' => false,
            'error' => 'Acción no válida o no especificada',
            'acciones_disponibles' => [
                'obtener_rutas_vigilar',
                'reportar_respaldo_consolidado',
                'listar_nas',
                'guardar_nas',
                'eliminar_nas',
                'listar_carpetas',
                'guardar_carpeta',
                'guardar_carpetas_lote',
                'eliminar_carpeta',
                'obtener_historial_equipo',
                'obtener_heatmap_30dias',
                'obtener_top5_criticos',
                'regenerar_token',
                'guardar_config',
                'exportar_csv'
            ]
        ]);
        break;
}
