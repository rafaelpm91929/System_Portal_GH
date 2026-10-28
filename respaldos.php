<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/excel_helper.php';

// Asegurar existencia de tablas del módulo de respaldos
if ($pdo) {
    asegurarTablasPermisos($pdo);
    asegurarTablasRespaldos($pdo);
}

requerirPermiso('respaldos', 'puede_ver');

// Permisos del usuario actual
$puedeEditar = tienePermiso('respaldos', 'puede_editar');
$puedeCrear = tienePermiso('respaldos', 'puede_crear');
$puedeEliminar = tienePermiso('respaldos', 'puede_eliminar');
$puedeExportar = tienePermiso('respaldos', 'puede_exportar');
$esAdmin = in_array(strtolower($_SESSION['usuario_rol'] ?? ''), ['admin', 'superadmin']);

// Datos de sesión
$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$agenciaInfo = null;
if ($pdo) {
    try {
        $stmtAg = $pdo->query("SELECT nombre, logo_url FROM agencias ORDER BY id ASC LIMIT 1");
        $agenciaInfo = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {}
}
$agenciaNombre = !empty($agenciaInfo['nombre']) ? trim($agenciaInfo['nombre']) : ($_SESSION['agencia'] ?? 'Agencia');
$logoAgencia = (!empty($agenciaInfo['logo_url']) && file_exists(__DIR__ . '/' . $agenciaInfo['logo_url'])) ? $agenciaInfo['logo_url'] : '';

// 2. Cargar configuraciones y Token Bearer
$configMap = [];
if ($pdo) {
    try {
        $stmtConf = $pdo->query("SELECT clave, valor FROM respaldos_config");
        $configMap = $stmtConf->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {}
}
$apiToken = $configMap['token_api_colector'] ?? 'generar_token_primera_vez';
$umbralAlerta = (int)($configMap['umbral_alerta_horas'] ?? 24);
$umbralCritico = (int)($configMap['umbral_critico_horas'] ?? 48);

// Construir URL base del API
$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$apiUrlCompleta = $protocolo . $host . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/api_respaldos.php';

if (!function_exists('calcularSemanasCalendarioMes')) {
    function calcularSemanasCalendarioMes($mes) {
        $diasEnMes = (int)date('t', strtotime("$mes-01"));
        $semanas = [];
        $semanaNum = 1;
        $diaActual = 1;
        $nombresDias = [0 => 'Dom', 1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb'];

        while ($diaActual <= $diasEnMes) {
            $fechaInicioStr = sprintf('%s-%02d', $mes, $diaActual);
            $wInicio = (int)date('w', strtotime($fechaInicioStr));
            
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
                'dias_count' => ($diaFin - $diaActual + 1),
                'respaldo' => null,
                'es_futuro' => false,
                'en_curso' => false,
                'es_exento' => false,
                'programado_proximo' => false,
                'prog_label' => ''
            ];

            $diaActual = $diaFin + 1;
            $semanaNum++;
        }

        return $semanas;
    }
}

if (!function_exists('obtenerSemanaActualMes')) {
    function obtenerSemanaActualMes($mes = null) {
        if (!$mes) $mes = date('Y-m');
        $diaHoy = (int)date('d');
        $semanas = calcularSemanasCalendarioMes($mes);
        foreach ($semanas as $sId => $sConf) {
            if ($diaHoy >= $sConf['inicio'] && $diaHoy <= $sConf['fin']) {
                return $sId;
            }
        }
        return 'S1';
    }
}

// 3. Consultar estadísticas generales y conteo de NAS
$totalNas = 0;
$nasCatalogo = [];
$areasDisponibles = [];
$totalEquipos = 0;
$exitosos = 0;
$vencidos = 0;
$criticos = 0;
$incompletos = 0;
$equiposList = [];
$top5Criticos = [];

if ($pdo) {
    try {
        // Conteo de NAS
        $stmtNas = $pdo->query("SELECT * FROM respaldos_nas ORDER BY id ASC");
        $nasCatalogo = $stmtNas ? $stmtNas->fetchAll(PDO::FETCH_ASSOC) : [];
        $totalNas = count($nasCatalogo);

        // Listado de áreas distintas
        $stmtAreas = $pdo->query("SELECT DISTINCT area_nombre FROM respaldos_carpetas WHERE area_nombre IS NOT NULL AND area_nombre != '' UNION SELECT DISTINCT area_nombre FROM respaldos_equipos WHERE area_nombre IS NOT NULL AND area_nombre != '' ORDER BY area_nombre ASC");
        $areasDisponibles = $stmtAreas ? $stmtAreas->fetchAll(PDO::FETCH_COLUMN) : [];
        if (empty($areasDisponibles)) {
            $areasDisponibles = ['Contabilidad', 'Ventas', 'Sistemas', 'Administración', 'Servicio', 'Refacciones', 'Gerencia', 'Caja', 'General'];
        }

        // Auto-sincronizar estados y días según las reglas semanales SLA:
        // <= 6 días: Verde (exitoso)
        // 7 a 12 días: Naranja (vencido)
        // >= 13 días: Rojo (critico)
        try {
            $pdo->exec("
                UPDATE respaldos_equipos 
                SET nas_id = (SELECT id FROM respaldos_nas WHERE estatus = 1 ORDER BY id ASC LIMIT 1) 
                WHERE nas_id IS NULL OR nas_id = 0
            ");
            $pdo->exec("
                UPDATE respaldos_equipos 
                SET dias_sin_respaldo = CASE 
                        WHEN ultimo_respaldo IS NOT NULL THEN GREATEST(0, DATEDIFF(CURDATE(), DATE(ultimo_respaldo)))
                        ELSE 99 
                    END,
                    estado = CASE 
                        WHEN tiene_temporales = 1 THEN 'incompleto'
                        WHEN ultimo_respaldo IS NULL THEN 'critico'
                        WHEN DATEDIFF(CURDATE(), DATE(ultimo_respaldo)) <= 6 THEN 'exitoso'
                        WHEN DATEDIFF(CURDATE(), DATE(ultimo_respaldo)) <= 12 THEN 'vencido'
                        ELSE 'critico'
                    END
            ");

            // Si hay registros históricos con fecha posterior al último respaldo real, corregir fecha
            $pdo->exec("
                UPDATE respaldos_historial h
                INNER JOIN respaldos_equipos e ON (h.equipo_id = e.id OR h.nombre_equipo = e.nombre_equipo)
                SET h.fecha = DATE(e.ultimo_respaldo),
                    h.fecha_hora = e.ultimo_respaldo
                WHERE e.ultimo_respaldo IS NOT NULL 
                  AND h.fecha > DATE(e.ultimo_respaldo)
            ");
        } catch (Throwable $syncE) {}

        // Políticas SLA de respaldo por Área
        $areasPoliticas = [];
        try {
            $stmtAP = $pdo->query("SELECT * FROM respaldos_areas_politicas ORDER BY area_nombre ASC");
            if ($stmtAP) {
                while ($apRow = $stmtAP->fetch(PDO::FETCH_ASSOC)) {
                    $areasPoliticas[$apRow['area_nombre']] = $apRow;
                }
            }
        } catch (Throwable $e) {}

        $semanaActualId = obtenerSemanaActualMes();
        $patternSem = '%' . $semanaActualId . '%';

        // Métricas de equipos (solo equipos monitoreados que pertenezcan a una NAS existente y sin '@')
        $stmtMetricas = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN e.estado = 'exitoso' THEN 1 ELSE 0 END) as exitosos,
                SUM(CASE WHEN e.estado = 'vencido' AND (COALESCE(p.aplica_esta_semana, 1) = 1 AND (p.semanas_mes_aplica IS NULL OR p.semanas_mes_aplica = '' OR p.semanas_mes_aplica LIKE ?)) THEN 1 ELSE 0 END) as vencidos,
                SUM(CASE WHEN e.estado = 'critico' AND (COALESCE(p.aplica_esta_semana, 1) = 1 AND (p.semanas_mes_aplica IS NULL OR p.semanas_mes_aplica = '' OR p.semanas_mes_aplica LIKE ?)) THEN 1 ELSE 0 END) as criticos,
                SUM(CASE WHEN e.estado = 'incompleto' THEN 1 ELSE 0 END) as incompletos,
                SUM(CASE WHEN (COALESCE(p.aplica_esta_semana, 1) = 0 OR (p.semanas_mes_aplica IS NOT NULL AND p.semanas_mes_aplica != '' AND p.semanas_mes_aplica NOT LIKE ?)) THEN 1 ELSE 0 END) as exentos
            FROM respaldos_equipos e
            LEFT JOIN respaldos_nas n ON e.nas_id = n.id
            LEFT JOIN respaldos_areas_politicas p ON e.area_nombre = p.area_nombre
            WHERE COALESCE(e.monitorear, 1) = 1 
              AND e.nombre_equipo NOT LIKE '%@%'
              AND (e.nas_id IS NULL OR n.id IS NOT NULL)
        ");
        $stmtMetricas->execute([$patternSem, $patternSem, $patternSem]);
        $metricas = $stmtMetricas ? $stmtMetricas->fetch(PDO::FETCH_ASSOC) : [];
        $totalEquipos = (int)($metricas['total'] ?? 0);
        $exitosos = (int)($metricas['exitosos'] ?? 0);
        $vencidos = (int)($metricas['vencidos'] ?? 0);
        $criticos = (int)($metricas['criticos'] ?? 0);
        $incompletos = (int)($metricas['incompletos'] ?? 0);
        $exentos = (int)($metricas['exentos'] ?? 0);

        // Top 5 Equipos con más días sin respaldar (excluyendo exentos)
        $stmtTop5 = $pdo->prepare("
            SELECT e.*, n.nombre as nas_nombre 
            FROM respaldos_equipos e 
            LEFT JOIN respaldos_nas n ON e.nas_id = n.id 
            LEFT JOIN respaldos_areas_politicas p ON e.area_nombre = p.area_nombre
            WHERE e.estado IN ('critico', 'vencido', 'incompleto') 
              AND COALESCE(e.monitorear, 1) = 1 
              AND (COALESCE(p.aplica_esta_semana, 1) = 1 AND (p.semanas_mes_aplica IS NULL OR p.semanas_mes_aplica = '' OR p.semanas_mes_aplica LIKE ?))
              AND e.nombre_equipo NOT LIKE '%@%'
              AND (e.nas_id IS NULL OR n.id IS NOT NULL)
            ORDER BY e.dias_sin_respaldo DESC, e.ultimo_respaldo ASC 
            LIMIT 5
        ");
        $stmtTop5->execute([$patternSem]);
        $top5Criticos = $stmtTop5 ? $stmtTop5->fetchAll(PDO::FETCH_ASSOC) : [];

        // Listado completo de equipos para la tabla
        $stmtEq = $pdo->query("
            SELECT e.*, n.nombre as nas_nombre, n.marca as nas_marca,
                   COALESCE(p.dias_programados, 'Vie') as dias_programados,
                   COALESCE(p.aplica_esta_semana, 1) as aplica_esta_semana,
                   COALESCE(p.semanas_mes_aplica, 'S1,S2,S3,S4,S5') as semanas_mes_aplica,
                   p.motivo_exencion
            FROM respaldos_equipos e 
            LEFT JOIN respaldos_nas n ON e.nas_id = n.id 
            LEFT JOIN respaldos_areas_politicas p ON e.area_nombre = p.area_nombre
            WHERE COALESCE(e.monitorear, 1) = 1 
              AND e.nombre_equipo NOT LIKE '%@%'
              AND (e.nas_id IS NULL OR n.id IS NOT NULL)
            ORDER BY 
              CASE WHEN (COALESCE(p.aplica_esta_semana, 1) = 0 OR (p.semanas_mes_aplica IS NOT NULL AND p.semanas_mes_aplica != '' AND p.semanas_mes_aplica NOT LIKE '$patternSem')) THEN 2 ELSE 1 END ASC,
              e.estado DESC, e.dias_sin_respaldo DESC, e.nombre_equipo ASC
        ");
        $equiposList = $stmtEq ? $stmtEq->fetchAll(PDO::FETCH_ASSOC) : [];

        // Historial del mes actual para mostrar las 4 semanas de cada equipo
        $stmtHistMes = $pdo->query("
            SELECT nombre_equipo, fecha, fecha_hora, estado 
            FROM respaldos_historial 
            WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 35 DAY) 
            ORDER BY fecha DESC
        ");
        $historialPorEquipo = [];
        if ($stmtHistMes) {
            while ($r = $stmtHistMes->fetch(PDO::FETCH_ASSOC)) {
                $eqKey = strtoupper($r['nombre_equipo']);
                if (!isset($historialPorEquipo[$eqKey])) {
                    $historialPorEquipo[$eqKey] = [];
                }
                $historialPorEquipo[$eqKey][] = $r;
            }
        }

    } catch (Throwable $e) {}
}

$totalAplicables = max(0, $totalEquipos - $exentos);
$porcentajeCumplimiento = ($totalAplicables > 0) ? round(($exitosos / $totalAplicables) * 100, 1) : (($totalEquipos > 0 && $exentos == $totalEquipos) ? 100 : 0);
$mostrarAsistenteInicial = ($totalNas === 0);

function calcular4SemanasDelMes($historialEquipo, $diasSinRespaldo = 99, $ultimoRespaldo = null, $mes = null, $politicaArea = null) {
    if (!$mes) {
        $mes = date('Y-m');
    }
    $diaHoy = (int)date('d');
    $esMesActual = ($mes === date('Y-m'));

    $semanas = calcularSemanasCalendarioMes($mes);

    $aplicaEstaSemana = isset($politicaArea['aplica_esta_semana']) ? (int)$politicaArea['aplica_esta_semana'] : 1;
    $diasProgramados = $politicaArea['dias_programados'] ?? 'Vie';
    $semanasMesAplica = trim($politicaArea['semanas_mes_aplica'] ?? 'S1,S2,S3,S4,S5');
    $semanasAplicaArr = ($semanasMesAplica !== '')
        ? array_values(array_filter(array_map('trim', explode(',', strtoupper($semanasMesAplica)))))
        : ['S1', 'S2', 'S3', 'S4', 'S5'];

    foreach ($semanas as $sId => &$sConf) {
        if ($esMesActual) {
            if ($diaHoy < $sConf['inicio']) {
                $sConf['es_futuro'] = true;
            } elseif ($diaHoy >= $sConf['inicio'] && $diaHoy <= $sConf['fin']) {
                $sConf['en_curso'] = true;
            }
        } elseif ($mes > date('Y-m')) {
            $sConf['es_futuro'] = true;
        }
    }
    unset($sConf);

    if (!empty($historialEquipo)) {
        foreach ($historialEquipo as $h) {
            $fechaH = $h['fecha'];
            if (strpos($fechaH, $mes) === 0) {
                $diaNum = (int)date('d', strtotime($fechaH));
                foreach ($semanas as $sId => &$sConf) {
                    if ($diaNum >= $sConf['inicio'] && $diaNum <= $sConf['fin'] && empty($sConf['respaldo'])) {
                        $sConf['respaldo'] = $h;
                        break;
                    }
                }
                unset($sConf);
            }
        }
    }

    if (!empty($ultimoRespaldo)) {
        $fUlt = date('Y-m-d', strtotime($ultimoRespaldo));
        if (strpos($fUlt, $mes) === 0) {
            $diaNum = (int)date('d', strtotime($fUlt));
            $hItem = ['fecha' => $fUlt, 'fecha_hora' => $ultimoRespaldo, 'estado' => 'exitoso'];
            foreach ($semanas as $sId => &$sConf) {
                if ($diaNum >= $sConf['inicio'] && $diaNum <= $sConf['fin'] && empty($sConf['respaldo'])) {
                    $sConf['respaldo'] = $hItem;
                    break;
                }
            }
            unset($sConf);
        }
    }

    // Evaluar cada semana según la política de semanas activas del área
    foreach ($semanas as $sId => &$sConf) {
        $estaSemanaAplica = in_array(strtoupper($sId), $semanasAplicaArr) && ($aplicaEstaSemana === 1);

        if (!$estaSemanaAplica) {
            $sConf['es_exento'] = true;
        } elseif ($sConf['en_curso'] && empty($sConf['respaldo'])) {
            $mapDias = ['DOM' => 0, 'LUN' => 1, 'MAR' => 2, 'MIÉ' => 3, 'MIE' => 3, 'JUE' => 4, 'VIE' => 5, 'SÁB' => 6, 'SAB' => 6];
            $diasProgArr = array_map('trim', explode(',', strtoupper($diasProgramados)));
            $diasProgNums = [];
            foreach ($diasProgArr as $dp) {
                if (isset($mapDias[$dp])) $diasProgNums[] = $mapDias[$dp];
            }
            $maxDiaProg = !empty($diasProgNums) ? max($diasProgNums) : 5;
            $diaSemanaHoy = (int)date('w');

            if ($diaSemanaHoy < $maxDiaProg && (int)$diasSinRespaldo > 6) {
                $sConf['programado_proximo'] = true;
                $sConf['prog_label'] = $diasProgramados;
            }
        }
    }
    unset($sConf);

    return $semanas;
}

function formatearTamanoBytes($bytes) {
    if (!$bytes || $bytes <= 0) return '0 B';
    $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $unidades[$i];
}

function tiempoRelativo($fechaStr) {
    if (empty($fechaStr)) return 'Nunca';
    $timestamp = strtotime($fechaStr);
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Hace unos momentos';
    if ($diff < 3600) return 'Hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'Hace ' . floor($diff / 3600) . ' hrs';
    $dias = floor($diff / 86400);
    if ($dias == 1) return 'Ayer';
    if ($dias < 30) return "Hace $dias días";
    return date('d/m/Y', $timestamp);
}

// Conteo para las gráficas ejecutivas
$conteoPorArea = [];
$conteoPorSemana = [];

$mesActualGrafica = date('Y-m');
$semanasMesConf = calcularSemanasCalendarioMes($mesActualGrafica);
foreach ($semanasMesConf as $sId => $sConf) {
    $conteoPorSemana[$sId] = 0;
}

foreach ($equiposList as $eqItem) {
    $ar = !empty($eqItem['area_nombre']) ? trim($eqItem['area_nombre']) : 'General';
    if (!isset($conteoPorArea[$ar])) $conteoPorArea[$ar] = 0;
    $conteoPorArea[$ar]++;

    $sem = calcular4SemanasDelMes(
        $historialPorEquipo[strtoupper($eqItem['nombre_equipo'])] ?? [],
        $eqItem['dias_sin_respaldo'] ?? 99,
        $eqItem['ultimo_respaldo'] ?? null,
        $mesActualGrafica
    );
    foreach ($sem as $k => $v) {
        if (!empty($v['respaldo'])) {
            if (!isset($conteoPorSemana[$k])) $conteoPorSemana[$k] = 0;
            $conteoPorSemana[$k]++;
        }
    }
}
if (empty($conteoPorArea)) {
    $conteoPorArea = ['General' => 0];
}

// Limpiar codificación de áreas para json_encode seguro
$conteoPorAreaLimpio = [];
foreach ($conteoPorArea as $areaK => $areaV) {
    $areaKClean = mb_convert_encoding($areaK, 'UTF-8', 'UTF-8');
    if ($areaKClean === false || $areaKClean === '') {
        $areaKClean = utf8_encode($areaK);
    }
    $conteoPorAreaLimpio[$areaKClean] = (int)$areaV;
}

$labelsSemanasChart = [];
$dataSemanasChart = [];
foreach ($semanasMesConf as $sId => $sConf) {
    $labelsSemanasChart[] = $sConf['label'];
    $dataSemanasChart[] = (int)($conteoPorSemana[$sId] ?? 0);
}

$datosSemaforoDona = [
    (int)$exitosos,
    (int)$vencidos,
    (int)$criticos,
    (int)$incompletos
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoreo de Respaldos (NAS) | <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <?php include_once 'pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <script>
    window.DATOS_AGENCIA_EXCEL = <?php 
        $agenciaExcelData = obtenerDatosAgenciaExcel($pdo ?? null, $agenciaNombre);
        echo json_encode($agenciaExcelData, JSON_UNESCAPED_UNICODE); 
    ?>;
    </script>
    <script>
// =============================================================================
// GRÁFICAS EJECUTIVAS CHART.JS (SEMÁFORO SEMANAL, ÁREAS Y SEMANAS DEL MES)
// =============================================================================
var chartInstanciaDona = null;
var chartInstanciaAreas = null;
var chartInstanciaSemanas = null;

function inicializarGraficas() {
    if (typeof Chart === 'undefined') {
        setTimeout(inicializarGraficas, 200);
        return;
    }

    // 1. Gráfica de Dona: Semáforo Semanal (≤6d verde, 7-12d naranja, ≥13d rojo)
    const ctxDona = document.getElementById('chartSemaforoDona');
    if (ctxDona) {
        if (window.chartInstanciaDona) {
            try { window.chartInstanciaDona.destroy(); } catch(e){}
        }
        window.chartInstanciaDona = new Chart(ctxDona, {
            type: 'doughnut',
            data: {
                labels: ['Al Día (≤6d)', 'En Alerta (7-12d)', 'Críticos (≥13d)', 'Incompletos'],
                datasets: [{
                    data: <?php echo json_encode($datosSemaforoDona); ?>,
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#ec4899'],
                    borderWidth: 2,
                    borderColor: '#09172c'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: '#ffffff', boxWidth: 12, font: { size: 10 } }
                    }
                },
                cutout: '70%'
            }
        });
    }

    // 2. Gráfica de Barras: Equipos por Área
    const ctxAreas = document.getElementById('chartAreasBarras');
    if (ctxAreas) {
        if (window.chartInstanciaAreas) {
            try { window.chartInstanciaAreas.destroy(); } catch(e){}
        }
        window.chartInstanciaAreas = new Chart(ctxAreas, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($conteoPorAreaLimpio), JSON_UNESCAPED_UNICODE) ?: '[]'; ?>,
                datasets: [{
                    label: 'Equipos',
                    data: <?php echo json_encode(array_values($conteoPorAreaLimpio)) ?: '[]'; ?>,
                    backgroundColor: 'rgba(56, 189, 248, 0.6)',
                    borderColor: '#38bdf8',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { display: false } },
                    y: { ticks: { color: '#94a3b8', precision: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            }
        });
    }

    // 3. Gráfica de Semanas del Mes
    const ctxSemanas = document.getElementById('chartSemanasMes');
    if (ctxSemanas) {
        if (window.chartInstanciaSemanas) {
            try { window.chartInstanciaSemanas.destroy(); } catch(e){}
        }
        window.chartInstanciaSemanas = new Chart(ctxSemanas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labelsSemanasChart, JSON_UNESCAPED_UNICODE) ?: '[]'; ?>,
                datasets: [{
                    label: 'Respaldos cumplidos',
                    data: <?php echo json_encode($dataSemanasChart) ?: '[]'; ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.2)',
                    borderColor: '#10b981',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#10b981',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { display: false } },
                    y: { ticks: { color: '#94a3b8', precision: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } }
                }
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inicializarGraficas);
} else {
    inicializarGraficas();
}
window.addEventListener('load', function() {
    if (!window.chartInstanciaDona || !window.chartInstanciaAreas || !window.chartInstanciaSemanas) {
        inicializarGraficas();
    }
});

function abrirModalEditarEquipo(eq) {
    if (typeof eq === 'string') {
        try { eq = JSON.parse(eq); } catch (e) { eq = {}; }
    }
    document.getElementById('editEquipoId').value = eq.id || 0;
    document.getElementById('editNombreEquipo').value = eq.nombre_equipo || '';
    document.getElementById('editUsuarioResponsable').value = eq.usuario_responsable || '';
    if (eq.area_nombre) {
        document.getElementById('editAreaNombre').value = eq.area_nombre;
    }
    const modal = new bootstrap.Modal(document.getElementById('modalEditarEquipo'));
    modal.show();
}

function guardarDatosEquipo(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarEquipo');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando...';

    const form = document.getElementById('formEditarEquipo');
    const formData = new FormData(form);

    fetch('api_respaldos.php?action=guardar_equipo', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Guardar Cambios';
        if (res.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Guardado!',
                text: 'Los datos del equipo fueron actualizados con éxito.',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error', res.error || 'No se pudo guardar', 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Guardar Cambios';
        Swal.fire('Error', 'Falla de conexión al guardar: ' + err.message, 'error');
    });
}
</script>
    <?php renderizarEstilosTemaGlobal($pdo); ?>
    <style>
        :root {
            --bg-dark: #040d1a;
            --bg-card: #09172c;
            --bg-card-hover: #0e2440;
            --gold-accent: #d4af37;
            --gold-border: rgba(212, 175, 55, 0.35);
            --gold-glow: rgba(212, 175, 55, 0.15);
            --color-exitoso: #10b981;
            --color-vencido: #f59e0b;
            --color-critico: #ef4444;
            --color-incompleto: #ec4899;
        }

        body {
            background-color: var(--bg-dark);
            background-image: radial-gradient(#0b223e 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 60px;
        }

        .top-navbar {
            background: rgba(4, 13, 26, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .card-executive {
            background: #09172c;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 22px;
            position: relative;
            overflow: hidden;
            transition: all 0.25s ease;
        }

        .card-executive:hover {
            transform: translateY(-3px);
            border-color: rgba(59, 130, 246, 0.4);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.5);
        }

        .kpi-number {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .kpi-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #94a3b8;
            font-weight: 600;
        }

        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        /* Semáforo badges */
        .badge-semaforo {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .badge-exitoso {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.28) 0%, rgba(5, 150, 105, 0.12) 100%);
            color: #34d399;
            border: 1px solid rgba(52, 211, 153, 0.50);
            box-shadow: 0 2px 10px rgba(16, 185, 129, 0.20);
        }

        .badge-vencido {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.28) 0%, rgba(217, 119, 6, 0.12) 100%);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.50);
            box-shadow: 0 2px 10px rgba(245, 158, 11, 0.20);
        }

        .badge-critico {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.28) 0%, rgba(185, 28, 28, 0.12) 100%);
            color: #f87171;
            border: 1px solid rgba(248, 113, 113, 0.50);
            box-shadow: 0 2px 10px rgba(239, 68, 68, 0.20);
        }

        .badge-incompleto {
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.28) 0%, rgba(190, 24, 93, 0.12) 100%);
            color: #f472b6;
            border: 1px solid rgba(244, 114, 182, 0.50);
            box-shadow: 0 2px 10px rgba(236, 72, 153, 0.20);
        }

        .badge-exento {
            background: linear-gradient(135deg, rgba(148, 163, 184, 0.25) 0%, rgba(100, 116, 139, 0.12) 100%);
            color: #cbd5e1;
            border: 1px solid rgba(148, 163, 184, 0.45);
            box-shadow: 0 2px 8px rgba(148, 163, 184, 0.15);
        }

        /* Píldoras con degradado moderno para semanas */
        .badge-semana-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 8px;
            font-size: 0.72rem;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600;
            letter-spacing: 0.3px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
        }
        .badge-semana-pill:hover {
            transform: translateY(-1px);
        }
        .badge-semana-exitoso {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.32) 0%, rgba(5, 150, 105, 0.15) 100%);
            border: 1px solid rgba(52, 211, 153, 0.55);
            color: #6ee7b7;
            box-shadow: 0 2px 10px rgba(16, 185, 129, 0.22);
            text-shadow: 0 0 10px rgba(16, 185, 129, 0.35);
        }
        .badge-semana-en_curso {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.30) 0%, rgba(217, 119, 6, 0.14) 100%);
            border: 1px solid rgba(251, 191, 36, 0.55);
            color: #fde68a;
            box-shadow: 0 2px 10px rgba(245, 158, 11, 0.22);
            text-shadow: 0 0 10px rgba(245, 158, 11, 0.35);
        }
        .badge-semana-sin_respaldo {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.28) 0%, rgba(153, 27, 27, 0.14) 100%);
            border: 1px solid rgba(248, 113, 113, 0.50);
            color: #fca5a5;
            box-shadow: 0 2px 10px rgba(239, 68, 68, 0.22);
        }
        .badge-semana-futuro {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.02) 100%);
            border: 1px dashed rgba(255, 255, 255, 0.18);
            color: #94a3b8;
        }
        .badge-semana-exento {
            background: linear-gradient(135deg, rgba(148, 163, 184, 0.22) 0%, rgba(100, 116, 139, 0.10) 100%);
            border: 1px dashed rgba(148, 163, 184, 0.45);
            color: #94a3b8;
        }
        .badge-semana-programado {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.25) 0%, rgba(14, 165, 233, 0.10) 100%);
            border: 1px solid rgba(56, 189, 248, 0.50);
            color: #7dd3fc;
            box-shadow: 0 2px 10px rgba(56, 189, 248, 0.18);
        }

        .dot-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-exitoso { background: #10b981; box-shadow: 0 0 8px #10b981; }
        .dot-vencido { background: #f59e0b; box-shadow: 0 0 8px #f59e0b; }
        .dot-critico { background: #ef4444; box-shadow: 0 0 8px #ef4444; }
        .dot-incompleto { background: #ec4899; box-shadow: 0 0 8px #ec4899; }
        .dot-exento { background: #94a3b8; box-shadow: 0 0 8px #94a3b8; }

        /* Scrollbar superior sincronizado y mejoras de tabla */
        .table-scroll-sync-top {
            overflow-x: auto;
            overflow-y: hidden;
            height: 12px;
            margin-bottom: 4px;
            border-radius: 6px;
        }
        .table-scroll-sync-top::-webkit-scrollbar,
        .table-scroll-sync-bottom::-webkit-scrollbar {
            height: 8px;
        }
        .table-scroll-sync-top::-webkit-scrollbar-track,
        .table-scroll-sync-bottom::-webkit-scrollbar-track {
            background: rgba(4, 13, 26, 0.75);
            border-radius: 6px;
        }
        .table-scroll-sync-top::-webkit-scrollbar-thumb,
        .table-scroll-sync-bottom::-webkit-scrollbar-thumb {
            background: rgba(212, 175, 55, 0.45);
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        .table-scroll-sync-top::-webkit-scrollbar-thumb:hover,
        .table-scroll-sync-bottom::-webkit-scrollbar-thumb:hover {
            background: rgba(212, 175, 55, 0.85);
            box-shadow: 0 0 10px rgba(212, 175, 55, 0.4);
        }
        .table-scroll-sync-inner {
            height: 1px;
        }

        /* Columna fija (Sticky First Column) para scrolling horizontal suave */
        .table-sticky-col th.th-sticky-first,
        .table-sticky-col td.td-sticky-first {
            position: sticky;
            left: 0;
            z-index: 4;
            background: #09172c;
            box-shadow: 4px 0 12px rgba(0, 0, 0, 0.45);
        }
        .table-sticky-col tbody tr:hover td.td-sticky-first {
            background: #0e2440;
        }

        /* Estilo elegante para el rango de fechas debajo del nombre de semana */
        .badge-semana-rango {
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 500;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.09);
            padding: 2px 7px;
            border-radius: 10px;
            letter-spacing: 0;
            text-transform: none;
            white-space: nowrap;
        }

        /* Tabla de Equipos */
        .table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 6px;
        }

        .table-custom th {
            background: #09172c;
            color: #94a3b8;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 12px 16px;
            border: none;
        }

        .table-custom tbody tr {
            background: #09172c;
            transition: all 0.2s ease;
        }

        .table-custom tbody tr:hover {
            background: #0e2440;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .table-custom td {
            padding: 14px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            vertical-align: middle;
            font-size: 0.88rem;
        }

        .table-custom td:first-child {
            border-left: 1px solid rgba(255, 255, 255, 0.05);
            border-top-left-radius: 10px;
            border-bottom-left-radius: 10px;
        }

        .table-custom td:last-child {
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
        }

        /* Nav Tabs estilo ejecutivo */
        .nav-tabs-custom {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            gap: 10px;
        }

        .nav-tabs-custom .nav-link {
            color: #94a3b8;
            border: none;
            border-radius: 10px;
            padding: 10px 18px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .nav-tabs-custom .nav-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-tabs-custom .nav-link.active {
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        /* Heatmap Grid */
        .heatmap-container {
            overflow-x: auto;
            padding-bottom: 10px;
        }

        .heatmap-cell {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            display: inline-block;
            margin: 1px;
            cursor: pointer;
            transition: transform 0.15s ease;
        }

        .heatmap-cell:hover {
            transform: scale(1.3);
            z-index: 10;
        }

        .hm-exitoso { background-color: #10b981; }
        .hm-vencido { background-color: #f59e0b; }
        .hm-critico { background-color: #ef4444; }
        .hm-incompleto { background-color: #ec4899; }
        .hm-sin_datos { background-color: #1e293b; border: 1px dashed rgba(255, 255, 255, 0.1); }

        /* Asistente Inicial Wizard */
        .wizard-step {
            display: none;
        }
        .wizard-step.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-indicator {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 30px;
        }

        .step-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 10%;
            right: 10%;
            height: 2px;
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-50%);
            z-index: 1;
        }

        .step-item {
            position: relative;
            z-index: 2;
            background: #09172c;
            padding: 0 15px;
            text-align: center;
        }

        .step-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #1e293b;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin: 0 auto 6px auto;
            border: 2px solid rgba(255, 255, 255, 0.15);
            transition: all 0.3s;
        }

        .step-item.active .step-circle {
            background: #2563eb;
            color: #ffffff;
            border-color: #38bdf8;
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.4);
        }

        .step-item.completed .step-circle {
            background: #10b981;
            color: #ffffff;
            border-color: #34d399;
        }

        .code-block {
            background: #02070e;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 8px;
            padding: 12px 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: #38bdf8;
            word-break: break-all;
        }

        .radar-pulse {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: rgba(56, 189, 248, 0.15);
            border: 2px solid #38bdf8;
            animation: pulseRadar 1.8s infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px auto;
        }

        @keyframes pulseRadar {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(56, 189, 248, 0.6); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 16px rgba(56, 189, 248, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(56, 189, 248, 0); }
        }
    </style>
</head>
<body>

<!-- Navbar Superior -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm rounded-3 text-white border-opacity-25" title="Regresar al Menú Principal">
            <i class="bi bi-arrow-left me-1"></i> Menú
        </a>
        <div class="vr bg-secondary opacity-25" style="height: 24px;"></div>
        <div class="d-flex align-items-center gap-2">
            <div class="kpi-icon" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6; width: 36px; height: 36px; font-size: 1.1rem;">
                <i class="bi bi-cloud-arrow-up-fill"></i>
            </div>
            <div>
                <span class="fw-bold tracking-wide">MONITOREO DE RESPALDOS</span>
                <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-50 ms-2">NAS SMB</span>
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if ($puedeExportar): ?>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-success rounded-3 fw-semibold shadow-sm" onclick="exportarAuditoriaExcelAvanzado()" title="Exportar Libro Excel Ejecutivo (2 Hojas: Resumen + Gráficas y Matriz Semanal por Área)">
                <i class="bi bi-file-earmark-spreadsheet-fill me-1 text-success"></i> Exportar Auditoría (Excel)
            </button>
            <button type="button" class="btn btn-outline-success dropdown-toggle dropdown-toggle-split rounded-end-3" data-bs-toggle="dropdown" aria-expanded="false" title="Más opciones de exportación">
                <span class="visually-hidden">Opciones de exportación</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow border-secondary border-opacity-50">
                <li>
                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="exportarAuditoriaExcelAvanzado()">
                        <i class="bi bi-file-earmark-excel-fill text-success me-2"></i>
                        <div>
                            <div class="fw-semibold">Libro Excel Oficial (.xlsx)</div>
                            <small class="text-secondary">2 Hojas: KPIs + Gráficas y Matriz por Área</small>
                        </div>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item py-2" href="api_respaldos.php?action=exportar_excel">
                        <i class="bi bi-file-earmark-spreadsheet text-warning me-2"></i>
                        <div>
                            <div class="fw-semibold">Reporte Rápido Membretado (.xls)</div>
                            <small class="text-secondary">Descarga directa desde servidor</small>
                        </div>
                    </a>
                </li>
                <li><hr class="dropdown-divider border-secondary border-opacity-25"></li>
                <li>
                    <a class="dropdown-item py-2" href="api_respaldos.php?action=exportar_csv">
                        <i class="bi bi-filetype-csv text-info me-2"></i>
                        <div>
                            <div class="fw-semibold">Reporte Plano (.csv)</div>
                            <small class="text-secondary">Datos tabulares para importar en otros sistemas</small>
                        </div>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-outline-primary btn-sm rounded-3" data-bs-toggle="modal" data-bs-target="#modalColectorLan">
            <i class="bi bi-terminal-fill me-1"></i> Agente Colector LAN
        </button>

        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3" title="Cerrar Sesión">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="container-fluid px-4 py-4" style="max-width: 1560px;">

    <!-- Encabezado con título e información -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">Semáforo de Respaldos en Red Local</h2>
            <p class="text-secondary small mb-0">
                Supervisión centralizada de copias de seguridad en NAS locales (QNAP, Buffalo, Synology). SLA y auditoría continua.
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-light btn-sm rounded-3" onclick="abrirAsistenteWizard()">
                <i class="bi bi-magic me-1"></i> Asistente de Configuración
            </button>
            <?php if ($puedeCrear): ?>
            <button class="btn btn-primary btn-sm rounded-3 px-3 shadow" onclick="abrirModalNuevoNas()">
                <i class="bi bi-plus-circle me-1"></i> Agregar NAS
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- TARJETAS RESUMEN / KPIS EJECUTIVOS -->
    <div class="row g-3 mb-4">
        <!-- Total Equipos -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Total Equipos</span>
                    <div class="kpi-icon" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">
                        <i class="bi bi-display"></i>
                    </div>
                </div>
                <div class="kpi-number text-white" id="kpiTotalEquipos"><?php echo $totalEquipos; ?></div>
                <div class="text-secondary small">Monitoreados en red</div>
            </div>
        </div>

        <!-- Cumplimiento Hoy -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive" style="border-color: rgba(16, 185, 129, 0.3);">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">SLA Semanal</span>
                    <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                        <i class="bi bi-shield-check"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <div class="kpi-number text-success" id="kpiPorcentajeCumplimiento"><?php echo $porcentajeCumplimiento; ?>%</div>
                    <?php if (!empty($exentos) && $exentos > 0): ?>
                    <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50 small" id="kpiExentosBadge" title="<?php echo $exentos; ?> equipos exentos esta semana">
                        <?php echo $exentos; ?> exentos
                    </span>
                    <?php else: ?>
                    <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50 small d-none" id="kpiExentosBadge">0 exentos</span>
                    <?php endif; ?>
                </div>
                <div class="progress mt-2" style="height: 5px; background: rgba(255,255,255,0.08);">
                    <div class="progress-bar bg-success" id="kpiBarraCumplimiento" role="progressbar" style="width: <?php echo $porcentajeCumplimiento; ?>%;"></div>
                </div>
            </div>
        </div>

        <!-- Respaldados con Éxito (Verde) -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive" style="border-color: rgba(16, 185, 129, 0.2);">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Al Día (&le; 6 días)</span>
                    <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                </div>
                <div class="kpi-number text-success" id="kpiExitosos"><?php echo $exitosos; ?></div>
                <div class="text-secondary small">Respaldados esta semana</div>
            </div>
        </div>

        <!-- Vencidos (Amarillo) -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive" style="border-color: rgba(245, 158, 11, 0.2);">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">En Alerta (7-12 días)</span>
                    <div class="kpi-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                </div>
                <div class="kpi-number text-warning" id="kpiVencidos"><?php echo $vencidos; ?></div>
                <div class="text-secondary small">Próximos a vencer</div>
            </div>
        </div>

        <!-- Críticos / Sin Respaldo (Rojo) -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive" style="border-color: rgba(239, 68, 68, 0.2);">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Críticos (&ge; 13 días)</span>
                    <div class="kpi-icon" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                </div>
                <div class="kpi-number text-danger" id="kpiCriticos"><?php echo $criticos; ?></div>
                <div class="text-secondary small">Sin copia reciente</div>
            </div>
        </div>

        <!-- Incompletos / Temporales (.tmp) -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-executive" style="border-color: rgba(236, 72, 153, 0.2);">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-label">Con Temporales</span>
                    <div class="kpi-icon" style="background: rgba(236, 72, 153, 0.15); color: #ec4899;">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
                <div class="kpi-number text-pink" id="kpiIncompletos" style="color: #f472b6;"><?php echo $incompletos; ?></div>
                <div class="text-secondary small">Archivos .tmp / incompletos</div>
            </div>
        </div>
    </div>

    <!-- ALERTA INICIAL SI NO HAY NAS REGISTRADAS -->
    <?php if ($mostrarAsistenteInicial): ?>
    <div class="card-executive p-4 mb-4 text-center border-warning border-opacity-50" style="background: rgba(245, 158, 11, 0.05);">
        <i class="bi bi-hdd-network display-4 text-warning mb-2 d-block"></i>
        <h4 class="fw-bold text-white mb-2">Aún no hay almacenamiento NAS configurado</h4>
        <p class="text-secondary small mx-auto mb-3" style="max-width: 650px;">
            Para comenzar a monitorear los respaldos automáticos de las más de 150 máquinas, inicia el Asistente Inicial para registrar tus dispositivos NAS (QNAP, Buffalo, Synology) y mapear las carpetas por área.
        </p>
        <button class="btn btn-warning rounded-3 px-4 fw-semibold shadow" onclick="abrirAsistenteWizard()">
            <i class="bi bi-magic me-1"></i> Iniciar Asistente Paso a Paso
        </button>
    </div>
    <?php endif; ?>

    <!-- SECCIÓN DE WIDGETS Y TOP 5 CRÍTICOS -->
    <?php if (!empty($top5Criticos)): ?>
    <div class="card-executive mb-4 p-3 border-danger border-opacity-25" style="background: rgba(239, 68, 68, 0.04);">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50"><i class="bi bi-fire me-1"></i> ACCIÓN INMEDIATA</span>
                <h6 class="fw-bold text-white mb-0">Top Equipos Críticos con Mayor Tiempo Sin Respaldo</h6>
            </div>
            <span class="text-secondary small">Prioridad de soporte de Sistemas</span>
        </div>
        <div class="row g-2">
            <?php foreach ($top5Criticos as $crit): ?>
            <div class="col-12 col-md-6 col-lg">
                <div class="p-2 rounded-3 border border-secondary border-opacity-20 d-flex justify-content-between align-items-center" style="background: rgba(13, 30, 54, 0.7);">
                    <div class="overflow-hidden me-2">
                        <div class="fw-bold text-white text-truncate small" title="<?php echo htmlspecialchars($crit['nombre_equipo']); ?>">
                            <i class="bi bi-pc-display text-danger me-1"></i> <?php echo htmlspecialchars($crit['nombre_equipo']); ?>
                        </div>
                        <div class="text-secondary" style="font-size: 0.75rem;">
                            <?php echo htmlspecialchars($crit['area_nombre']); ?> • <?php echo htmlspecialchars($crit['usuario_responsable'] ?: 'Sin asignar'); ?>
                        </div>
                    </div>
                    <div class="text-end text-nowrap">
                        <span class="badge bg-danger text-white fw-bold"><?php echo $crit['dias_sin_respaldo']; ?> días</span>
                        <div class="text-secondary" style="font-size: 0.7rem;"><?php echo tiempoRelativo($crit['ultimo_respaldo']); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

        <!-- PANEL DE GRÁFICAS EJECUTIVAS (CHART.JS) -->
    <div class="row g-3 mb-4">
        <!-- Gráfica 1: Semáforo Semanal (Dona) -->
        <div class="col-12 col-md-4">
            <div class="card-executive p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-pie-chart-fill text-warning me-2"></i>Cumplimiento Semanal</h6>
                    <span class="badge bg-dark border border-secondary text-secondary small">Semana Actual</span>
                </div>
                <div style="position: relative; height: 185px;">
                    <canvas id="chartSemaforoDona"></canvas>
                </div>
            </div>
        </div>

        <!-- Gráfica 2: Cumplimiento por Departamento (Barras) -->
        <div class="col-12 col-md-4">
            <div class="card-executive p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-bar-chart-fill text-info me-2"></i>Equipos por Área</h6>
                    <span class="badge bg-dark border border-secondary text-secondary small">Distribución</span>
                </div>
                <div style="position: relative; height: 185px;">
                    <canvas id="chartAreasBarras"></canvas>
                </div>
            </div>
        </div>

        <!-- Gráfica 3: Efectividad de las 4 Semanas del Mes -->
        <div class="col-12 col-md-4">
            <div class="card-executive p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-white mb-0"><i class="bi bi-calendar-check-fill text-success me-2"></i>4 Semanas del Mes</h6>
                    <span class="badge bg-dark border border-secondary text-secondary small">Ciclos Semanales</span>
                </div>
                <div style="position: relative; height: 185px;">
                    <canvas id="chartSemanasMes"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- PESTAÑAS PRINCIPALES DEL MÓDULO -->
    <ul class="nav nav-tabs-custom mb-3" id="tabModuloRespaldos" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="tab-dashboard-link" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button">
                <i class="bi bi-speedometer2"></i> Dashboard Semáforo
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-heatmap-link" data-bs-toggle="tab" data-bs-target="#tab-heatmap" type="button" onclick="cargarMatrizSemanasMes()">
                <i class="bi bi-calendar3-week"></i> Matriz Semanal por Mes
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-areas-link" data-bs-toggle="tab" data-bs-target="#tab-areas" type="button" onclick="cargarPoliticasAreas()">
                <i class="bi bi-calendar2-check"></i> Días de Respaldo por Área
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-nas-link" data-bs-toggle="tab" data-bs-target="#tab-nas" type="button" onclick="cargarCatalogoNas()">
                <i class="bi bi-hdd-network"></i> Catálogo Multi-NAS y Carpetas
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-config-link" data-bs-toggle="tab" data-bs-target="#tab-config" type="button">
                <i class="bi bi-sliders"></i> Configuración y Umbrales
            </button>
        </li>
    </ul>

    <!-- CONTENIDO DE LAS PESTAÑAS -->
    <div class="tab-content" id="tabContentRespaldos">

        <!-- =================================================================== -->
        <!-- PESTAÑA 1: DASHBOARD PRINCIPAL (SEMÁFORO DE 150+ MÁQUINAS)          -->
        <!-- =================================================================== -->
        <div class="tab-pane fade show active" id="tab-dashboard" role="tabpanel">
            
            <!-- Barra de Filtros Rápidos y Buscador en Tiempo Real -->
            <div class="card-executive p-3 mb-3">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" id="buscadorEquipos" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Buscar por equipo, usuario o archivo..." onkeyup="filtrarTablaEquipos()">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroArea" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="filtrarTablaEquipos()">
                            <option value="">Todas las Áreas (Departamentos)</option>
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select id="filtroNas" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="filtrarTablaEquipos()">
                            <option value="">Todas las NAS</option>
                            <?php foreach ($nasCatalogo as $n): ?>
                            <option value="<?php echo htmlspecialchars($n['nombre']); ?>"><?php echo htmlspecialchars($n['nombre']); ?> (<?php echo htmlspecialchars($n['marca']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <select id="filtroEstado" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="filtrarTablaEquipos()">
                            <option value="">Todos los Estados</option>
                            <option value="exitoso">🟢 Al Día (&le; 6 días)</option>
                            <option value="vencido">🟡 En Alerta (7-12 días)</option>
                            <option value="critico">🔴 Crítico (&ge; 13 o 14 días)</option>
                            <option value="incompleto">⚠️ Con Temporales</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Contador de Registros -->
            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <span class="text-secondary small" id="contadorFiltrados">
                    Mostrando <strong class="text-white"><?php echo count($equiposList); ?></strong> de <strong class="text-white"><?php echo $totalEquipos; ?></strong> equipos
                </span>
                <span class="text-secondary small">
                    <i class="bi bi-clock-history me-1"></i> Monitoreo continuo vía colector local
                </span>
            </div>

            <!-- Scroll superior sincronizado -->
            <div class="table-scroll-sync-top" id="scrollDashboardTop">
                <div class="table-scroll-sync-inner" id="scrollDashboardInnerTop"></div>
            </div>
            <!-- Tabla de Equipos -->
            <div class="table-responsive table-scroll-sync-bottom" id="dashboardContenido">
                <table class="table-custom table-sticky-col" id="tablaEquipos">
                    <thead>
                        <tr>
                            <th class="th-sticky-first">Nombre del Equipo</th>
                            <th>Área / Departamento</th>
                            <th>NAS Destino</th>
                            <th>Último Respaldo</th>
                            <th>4 Semanas del Mes</th>
                            <th>Archivo Más Reciente</th>
                            <th>Tamaño</th>
                            <th>Estado Semáforo</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyEquipos">
                        <?php if (empty($equiposList)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                No se han registrado equipos aún. Ejecuta el script colector en la red local o añade tus carpetas NAS en el Asistente.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($equiposList as $eq): 
                            $diasCal = 99;
                            if (!empty($eq['ultimo_respaldo'])) {
                                $fUlt = date('Y-m-d', strtotime($eq['ultimo_respaldo']));
                                $dtHoy = new DateTime(date('Y-m-d'));
                                $dtUlt = new DateTime($fUlt);
                                if ($dtHoy <= $dtUlt) {
                                    $diasCal = 0;
                                } else {
                                    $diasCal = (int)$dtHoy->diff($dtUlt)->days;
                                }
                            }
                            $estadoFinal = 'critico';
                            if (!empty($eq['tiene_temporales'])) { $estadoFinal = 'incompleto'; }
                            elseif ($diasCal <= 6) { $estadoFinal = 'exitoso'; }
                            elseif ($diasCal <= 12) { $estadoFinal = 'vencido'; } 
                            $badgeClass = 'badge-critico';
                            $dotClass = 'dot-critico';
                            $estadoLabel = 'Crítico (≥13d)';

                            if ($estadoFinal === 'exitoso') {
                                $badgeClass = 'badge-exitoso';
                                $dotClass = 'dot-exitoso';
                                $estadoLabel = 'Al Día (≤6d)';
                            } elseif ($estadoFinal === 'vencido') {
                                $badgeClass = 'badge-vencido';
                                $dotClass = 'dot-vencido';
                                $estadoLabel = 'En Alerta (7-12d)';
                            } elseif ($estadoFinal === 'incompleto') {
                                $badgeClass = 'badge-incompleto';
                                $dotClass = 'dot-incompleto';
                                $estadoLabel = 'Incompleto (.tmp)';
                            }
                        ?>
                        <tr class="fila-equipo" 
                            data-equipo="<?php echo strtolower(htmlspecialchars($eq['nombre_equipo'])); ?>" 
                            data-usuario="<?php echo strtolower(htmlspecialchars($eq['usuario_responsable'] ?? '')); ?>"
                            data-area="<?php echo htmlspecialchars($eq['area_nombre']); ?>" 
                            data-nas="<?php echo htmlspecialchars($eq['nas_nombre'] ?? ''); ?>" 
                            data-estado="<?php echo htmlspecialchars($estadoFinal); ?>"
                            data-archivo="<?php echo strtolower(htmlspecialchars($eq['archivo_reciente'] ?? '')); ?>">
                            <td class="td-sticky-first">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(255,255,255,0.06);">
                                        <i class="bi bi-pc-display text-info"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-white"><?php echo htmlspecialchars($eq['nombre_equipo']); ?></div>
                                        <div class="text-secondary" style="font-size: 0.75rem;">
                                            <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($eq['usuario_responsable'] ?: 'Sin asignar'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">
                                    <?php echo htmlspecialchars($eq['area_nombre']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="badge bg-dark border border-secondary border-opacity-50 text-white small">
                                        <?php echo htmlspecialchars($eq['nas_marca'] ?: 'NAS'); ?>
                                    </span>
                                    <span class="text-secondary small"><?php echo htmlspecialchars($eq['nas_nombre'] ?: 'No asignado'); ?></span>
                                </div>
                            </td>
                            <td>
                                <div><?php echo tiempoRelativo($eq['ultimo_respaldo']); ?></div>
                                <div class="text-secondary" style="font-size: 0.72rem;">
                                    <?php echo !empty($eq['ultimo_respaldo']) ? date('d/m/Y H:i', strtotime($eq['ultimo_respaldo'])) : 'Sin registro'; ?>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <?php 
                                    $semMap = calcular4SemanasDelMes($historialPorEquipo[strtoupper($eq['nombre_equipo'])] ?? [], (int)$diasCal, $eq['ultimo_respaldo'] ?? null, null, $eq);
                                    foreach ($semMap as $sKey => $sVal):
                                        if (!empty($sVal['respaldo'])):
                                            $fDisplay = date('d/m', strtotime($sVal['respaldo']['fecha']));
                                    ?>
                                        <span class="badge-semana-pill badge-semana-exitoso" title="<?php echo $sKey; ?> (<?php echo $sVal['dias_label'] ?? $sVal['dias']; ?>): <?php echo $sVal['respaldo']['fecha_hora']; ?>">
                                            <i class="bi bi-check me-0"></i><?php echo $sKey; ?>: <?php echo $fDisplay; ?>
                                        </span>
                                    <?php elseif (!empty($sVal['es_exento'])): ?>
                                        <span class="badge-semana-pill badge-semana-exento" title="<?php echo $sKey; ?>: Área exenta esta semana (no aplica)">
                                            <i class="bi bi-pause me-0"></i><?php echo $sKey; ?>: No aplica
                                        </span>
                                    <?php elseif (!empty($sVal['programado_proximo'])): ?>
                                        <span class="badge-semana-pill badge-semana-programado" title="<?php echo $sKey; ?>: Respaldo programado para <?php echo htmlspecialchars($sVal['prog_label'] ?? 'Vie'); ?>">
                                            <i class="bi bi-calendar-event me-0"></i><?php echo $sKey; ?>: Prog (<?php echo htmlspecialchars($sVal['prog_label'] ?? 'Vie'); ?>)
                                        </span>
                                    <?php elseif ($sVal['es_futuro']): ?>
                                        <span class="badge-semana-pill badge-semana-futuro" title="<?php echo $sKey; ?> (<?php echo $sVal['dias_label'] ?? $sVal['dias']; ?>): Pendiente en el mes">
                                            <?php echo $sKey; ?>: -
                                        </span>
                                    <?php elseif ($sVal['en_curso'] && (int)$diasCal <= 6): ?>
                                        <span class="badge-semana-pill badge-semana-en_curso" title="<?php echo $sKey; ?> (<?php echo $sVal['dias_label'] ?? $sVal['dias']; ?>): En curso (Al día por respaldo reciente)">
                                            <i class="bi bi-clock me-0"></i><?php echo $sKey; ?>: Vigente
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-semana-pill badge-semana-sin_respaldo" title="<?php echo $sKey; ?> (<?php echo $sVal['dias_label'] ?? $sVal['dias']; ?>): Sin respaldo registrado">
                                            <i class="bi bi-x"></i><?php echo $sKey; ?>: Sin copia
                                        </span>
                                    <?php endif; endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($eq['archivo_reciente'] ?? 'Ninguno'); ?>">
                                    <code><?php echo htmlspecialchars($eq['archivo_reciente'] ?: 'Ninguno'); ?></code>
                                </div>
                                <?php if (!empty($eq['tiene_temporales'])): ?>
                                <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50" style="font-size: 0.65rem;">
                                    <i class="bi bi-exclamation-circle me-1"></i> Archivo .tmp activo
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-white fw-semibold"><?php echo formatearTamanoBytes($eq['tamano_bytes']); ?></span>
                            </td>
                            <td>
                                <?php 
                                $semanasMesAplica = trim($eq['semanas_mes_aplica'] ?? 'S1,S2,S3,S4,S5');
                                $semanasAplicaArr = ($semanasMesAplica !== '') ? array_values(array_filter(array_map('trim', explode(',', strtoupper($semanasMesAplica))))) : ['S1','S2','S3','S4','S5'];
                                $esSemanaActualAplicable = in_array(strtoupper($semanaActualId), $semanasAplicaArr) && ((int)($eq['aplica_esta_semana'] ?? 1) === 1);
                                if (!$esSemanaActualAplicable): 
                                ?>
                                <span class="badge-semaforo badge-exento" title="<?php echo htmlspecialchars($eq['motivo_exencion'] ?: "Esta semana ($semanaActualId) no aplica según la programación del área"); ?>">
                                    <span class="dot-indicator dot-exento"></span>
                                    No Aplica (<?php echo $semanaActualId; ?>)
                                </span>
                                <?php else: ?>
                                <span class="badge-semaforo <?php echo $badgeClass; ?>">
                                    <span class="dot-indicator <?php echo $dotClass; ?>"></span>
                                    <?php echo $estadoLabel; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-outline-warning btn-sm rounded-3 px-2 py-1 me-1" onclick='abrirModalEditarEquipo(<?php echo json_encode($eq, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Editar datos del equipo y usuario asignado"><i class="bi bi-pencil-square"></i></button><button class="btn btn-outline-info btn-sm rounded-3 px-2 py-1" onclick="abrirExpedienteEquipo('<?php echo htmlspecialchars($eq['nombre_equipo']); ?>', <?php echo $eq['id']; ?>)" title="Ver expediente e historial de 90 días">
                                    <i class="bi bi-journal-text me-1"></i> Historial
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- =================================================================== -->
        <!-- PESTAÑA 2: MATRIZ SEMANAL DE CONSTANCIA (FILTRADA POR MES)          -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-heatmap" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-white mb-1"><i class="bi bi-calendar3-week text-warning me-2"></i>Matriz Semanal de Cumplimiento por Mes</h5>
                        <p class="text-secondary small mb-0">Monitoreo de 4 a 5 semanas calendario. Filtra por mes para auditar la regularidad de cada equipo.</p>
                    </div>
                    <!-- Controles: Selector de Mes, Buscador y Leyenda Degradada -->
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <label for="selectMesMatriz" class="text-secondary small fw-semibold text-nowrap"><i class="bi bi-calendar-event text-warning me-1"></i>Mes:</label>
                            <select id="selectMesMatriz" class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-50" style="min-width: 175px;" onchange="cargarMatrizSemanasMes(this.value)">
                                <option value="">Cargando meses...</option>
                            </select>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <input type="text" id="filtroMatrizRapido" class="form-control form-control-sm bg-dark text-white border-secondary border-opacity-50" placeholder="Buscar máquina..." style="width: 160px;" onkeyup="filtrarTablaMatriz()">
                        </div>
                        <?php if ($puedeExportar): ?>
                        <button type="button" class="btn btn-outline-success btn-sm rounded-3 fw-semibold shadow-sm text-nowrap" onclick="exportarAuditoriaExcelAvanzado()" title="Descargar libro Excel oficial de 2 hojas">
                            <i class="bi bi-file-earmark-excel-fill me-1"></i> Exportar a Excel
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Leyenda de Píldoras con Degradado -->
                <div class="p-2 mb-3 rounded-3 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05);">
                    <div class="d-flex flex-wrap align-items-center gap-2 small">
                        <span class="text-secondary me-1 font-monospace" style="font-size: 0.72rem;">ESTADOS:</span>
                        <span class="badge-semana-pill badge-semana-exitoso"><i class="bi bi-check-circle-fill"></i> Cumplido (En fecha)</span>
                        <span class="badge-semana-pill badge-semana-en_curso"><i class="bi bi-clock-history"></i> En curso (≤6 días)</span>
                        <span class="badge-semana-pill badge-semana-sin_respaldo"><i class="bi bi-x-circle-fill"></i> Sin respaldo (Omitido)</span>
                        <span class="badge-semana-pill badge-semana-futuro"><i class="bi bi-dash"></i> Programado (Futuro)</span>
                    </div>
                    <div class="text-secondary small" id="contadorEquiposMatriz">
                        Mostrando todos los equipos
                    </div>
                </div>

                <div id="matrizCargando" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="text-secondary small mt-2">Cargando matriz semanal del mes seleccionado...</div>
                </div>

                <!-- Scroll superior sincronizado -->
                <div class="table-scroll-sync-top" id="scrollMatrizTop" style="display: none;">
                    <div class="table-scroll-sync-inner" id="scrollMatrizInnerTop"></div>
                </div>
                <div id="matrizContenido" class="table-responsive table-scroll-sync-bottom" style="display: none;">
                    <table class="table-custom table-sticky-col" style="min-width: 960px;" id="tablaMatrizSemanal">
                        <thead>
                            <tr id="matrizHeaderSemanas">
                                <th style="width: 250px;">Equipo / Área / Usuario</th>
                                <th class="text-center" style="width: 120px;">Semana 1 (1-7)</th>
                                <th class="text-center" style="width: 120px;">Semana 2 (8-14)</th>
                                <th class="text-center" style="width: 120px;">Semana 3 (15-21)</th>
                                <th class="text-center" style="width: 120px;">Semana 4 (22-28)</th>
                                <th class="text-center" style="width: 120px;">Semana 5 (29+)</th>
                                <th class="text-center" style="width: 130px;">Cumplimiento</th>
                                <th class="text-end" style="width: 100px;">Expediente</th>
                            </tr>
                        </thead>
                        <tbody id="matrizBody">
                            <!-- Filas dinámicas generadas vía JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- PESTAÑA 3: PROGRAMACIÓN DE DÍAS Y POLÍTICAS POR ÁREA                 -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-areas" role="tabpanel">
            <div class="card-executive p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                    <div>
                        <h5 class="fw-bold text-white mb-1">
                            <i class="bi bi-calendar2-check text-primary me-2"></i>Programación de Respaldos y Exención Semanal por Área
                        </h5>
                        <p class="text-secondary small mb-0">
                            Configura qué días de la semana le corresponde respaldo a cada departamento. Si un área no respaldará esta semana (por mantenimiento, inventario o descanso), desactívala aquí para evitar penalizaciones injustas en el SLA semanal.
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-outline-info btn-sm rounded-3 px-3" onclick="cargarPoliticasAreas()">
                            <i class="bi bi-arrow-repeat me-1"></i> Actualizar Áreas
                        </button>
                    </div>
                </div>

                <!-- Mini KPIs de Políticas de Áreas -->
                <div class="row g-3 mb-4" id="resumenKpisAreas">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 border border-secondary border-opacity-25" style="background: rgba(13, 30, 54, 0.4);">
                            <div class="text-secondary small text-uppercase fw-semibold mb-1">Total Áreas</div>
                            <div class="fs-4 fw-bold text-white" id="kpiAreasTotal">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 border border-success border-opacity-25" style="background: rgba(16, 185, 129, 0.08);">
                            <div class="text-success small text-uppercase fw-semibold mb-1">Áreas Activas</div>
                            <div class="fs-4 fw-bold text-success" id="kpiAreasActivas">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 border border-warning border-opacity-25" style="background: rgba(245, 158, 11, 0.08);">
                            <div class="text-warning small text-uppercase fw-semibold mb-1">Áreas Exentas esta Sem.</div>
                            <div class="fs-4 fw-bold text-warning" id="kpiAreasExentas">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 border border-info border-opacity-25" style="background: rgba(56, 189, 248, 0.08);">
                            <div class="text-info small text-uppercase fw-semibold mb-1">Equipos en Áreas</div>
                            <div class="fs-4 fw-bold text-info" id="kpiAreasEquipos">0</div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Áreas -->
                <div class="table-responsive">
                    <table class="table-custom" id="tablaPoliticasAreas">
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">Área / Departamento</th>
                                <th style="min-width: 140px;">Equipos</th>
                                <th style="min-width: 240px;">Días Programados</th>
                                <th style="min-width: 220px;">Semanas que Aplica (Mes)</th>
                                <th style="min-width: 120px;">Frecuencia</th>
                                <th style="min-width: 170px;" class="text-center">¿Aplica esta semana?</th>
                                <th style="min-width: 200px;">Motivo Exención</th>
                                <th class="text-end" style="min-width: 120px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyPoliticasAreas">
                            <tr>
                                <td colspan="8" class="text-center py-4 text-secondary">
                                    <div class="spinner-border spinner-border-sm text-primary me-2"></div> Cargando áreas y programación SLA...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- PESTAÑA 4: CATÁLOGO MULTI-NAS Y GESTIÓN DE CARPETAS                -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-nas" role="tabpanel">
            <div class="row g-4">
                <!-- Columna Izquierda: Listado de NAS -->
                <div class="col-12 col-lg-5">
                    <div class="card-executive p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-white mb-0"><i class="bi bi-hdd-network text-primary me-2"></i>Dispositivos NAS</h6>
                            <?php if ($puedeCrear): ?>
                            <button class="btn btn-primary btn-sm rounded-3" onclick="abrirModalNuevoNas()">
                                <i class="bi bi-plus-lg me-1"></i> Nuevo NAS
                            </button>
                            <?php endif; ?>
                        </div>

                        <div id="listaNasCards" class="d-flex flex-column gap-2">
                            <!-- Generado dinámicamente o precargado -->
                            <?php if (empty($nasCatalogo)): ?>
                            <div class="text-center py-4 text-secondary small">
                                No hay NAS registradas aún. Haz clic en "Nuevo NAS" o usa el Asistente.
                            </div>
                            <?php else: ?>
                            <?php foreach ($nasCatalogo as $n): ?>
                            <div id="cardNas_<?php echo $n['id']; ?>" class="p-3 rounded-3 border border-secondary border-opacity-25 card-nas-item" style="background: rgba(13, 30, 54, 0.5); cursor: pointer; transition: all 0.2s;" onclick="seleccionarNasCarpetas(<?php echo $n['id']; ?>, '<?php echo htmlspecialchars($n['nombre'], ENT_QUOTES); ?>')">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <div class="fw-bold text-white"><?php echo htmlspecialchars($n['nombre']); ?></div>
                                        <div class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-50 small"><?php echo htmlspecialchars($n['marca']); ?></div>
                                    </div>
                                    <div class="d-flex gap-1" onclick="event.stopPropagation()">
                                        <?php if ($puedeEditar): ?>
                                        <button class="btn btn-outline-light btn-sm p-1 rounded-2" onclick="editarNas(<?php echo htmlspecialchars(json_encode($n)); ?>)" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($puedeEliminar): ?>
                                        <button class="btn btn-outline-danger btn-sm p-1 rounded-2" onclick="eliminarNas(<?php echo $n['id']; ?>, '<?php echo htmlspecialchars($n['nombre']); ?>')" title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-secondary small font-monospace mb-2">
                                    <i class="bi bi-folder2-open me-1"></i><?php echo htmlspecialchars($n['ip_ruta_red']); ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center pt-2 border-top border-secondary border-opacity-10 small">
                                    <span class="text-secondary">Usuario SMB: <strong class="text-white"><?php echo htmlspecialchars($n['usuario_smb'] ?: 'Sin usuario'); ?></strong></span>
                                    <div class="d-flex gap-1" onclick="event.stopPropagation()">
                                        <button class="btn btn-outline-warning btn-sm py-0 px-2 rounded-2" onclick="abrirDeteccionNasExistente(<?php echo $n['id']; ?>, '<?php echo htmlspecialchars($n['nombre']); ?>')" title="Detectar carpetas reales desde la máquina local">
                                            <i class="bi bi-broadcast me-1"></i> Detectar
                                        </button>
                                        <button class="btn btn-outline-info btn-sm py-0 px-2 rounded-2" onclick="seleccionarNasCarpetas(<?php echo $n['id']; ?>, '<?php echo htmlspecialchars($n['nombre'], ENT_QUOTES); ?>')">
                                            Ver <i class="bi bi-chevron-right ms-1"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha: Carpetas y Mapeo por Áreas -->
                <div class="col-12 col-lg-7">
                    <div class="card-executive p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="fw-bold text-white mb-0" id="tituloCarpetasNas">Carpetas Monitoreadas</h6>
                                <span class="text-secondary" style="font-size: 0.75rem;" id="subtituloCarpetasNas">Selecciona un NAS para gestionar sus carpetas y departamentos</span>
                            </div>
                            <?php if ($puedeCrear): ?>
                            <button class="btn btn-outline-light btn-sm rounded-3" onclick="abrirModalNuevaCarpeta()">
                                <i class="bi bi-folder-plus me-1"></i> Añadir Carpeta
                            </button>
                            <?php endif; ?>
                        </div>

                        <div id="contenedorCarpetas">
                            <div class="text-center py-5 text-secondary small">
                                <i class="bi bi-folder2 display-5 text-secondary opacity-50 d-block mb-2"></i>
                                Selecciona un dispositivo NAS a la izquierda para inspeccionar y configurar sus carpetas compartidas y áreas asociadas.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- PESTAÑA 4: CONFIGURACIÓN Y UMBRALES                                 -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-config" role="tabpanel">
            <div class="card-executive p-4" style="max-width: 900px; margin: 0 auto;">
                <h5 class="fw-bold text-white mb-3"><i class="bi bi-sliders text-primary me-2"></i>Reglas de Evaluación y Alertas de Respaldos</h5>
                
                <form id="formConfiguracion" onsubmit="guardarConfiguracion(event)">
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary small fw-bold">Umbral de Alerta / Vencido (Horas)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-warning"><i class="bi bi-exclamation-triangle"></i></span>
                                <input type="number" class="form-control bg-dark text-white border-secondary border-opacity-25" id="cfg_umbral_alerta_horas" name="umbral_alerta_horas" value="<?php echo $umbralAlerta; ?>" min="1" max="168" required>
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">horas</span>
                            </div>
                            <div class="form-text text-secondary" style="font-size: 0.75rem;">Tiempo para marcar un respaldo como Amarillo (Vencido). Por defecto 24 hrs.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary small fw-bold">Umbral Crítico / Sin Respaldo (Horas)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-danger"><i class="bi bi-x-circle"></i></span>
                                <input type="number" class="form-control bg-dark text-white border-secondary border-opacity-25" id="cfg_umbral_critico_horas" name="umbral_critico_horas" value="<?php echo $umbralCritico; ?>" min="2" max="720" required>
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">horas</span>
                            </div>
                            <div class="form-text text-secondary" style="font-size: 0.75rem;">Tiempo para marcar como Rojo (Crítico). Por defecto 48 hrs.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary small fw-bold">Tamaño Mínimo Válido (MB)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-info"><i class="bi bi-file-earmark-check"></i></span>
                                <input type="number" step="0.1" class="form-control bg-dark text-white border-secondary border-opacity-25" id="cfg_tamano_minimo_valido_mb" name="tamano_minimo_valido_mb" value="<?php echo htmlspecialchars($configMap['tamano_minimo_valido_mb'] ?? '1'); ?>" min="0.1" required>
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary">MB</span>
                            </div>
                            <div class="form-text text-secondary" style="font-size: 0.75rem;">Archivos con tamaño menor son ignorados o tratados como corruptos.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary small fw-bold">Filtros de Exclusión (Basura del Sistema)</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25" id="cfg_patrones_descarte" name="patrones_descarte" value="<?php echo htmlspecialchars($configMap['patrones_descarte'] ?? '@Recycle,#snapshot,@Recently-Snapshot,.dropbox.cache,Thumbs.db,desktop.ini'); ?>">
                            <div class="form-text text-secondary" style="font-size: 0.75rem;">Separados por coma. Se descartan automáticamente en cada escaneo.</div>
                        </div>
                    </div>

                    <?php if ($puedeEditar): ?>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">
                        <i class="bi bi-save me-1"></i> Guardar Parámetros
                    </button>
                    <?php endif; ?>
                </form>

                <hr class="my-4 border-secondary opacity-25">

                <!-- Token Bearer y Acceso de Colectores -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-key-fill text-warning me-2"></i>Token Secreto Bearer (Colector LAN)</h6>
                        <span class="text-secondary small">Este token autoriza al script local para sincronizar con cPanel sin usuario ni contraseña interactiva.</span>
                    </div>
                    <?php if ($esAdmin): ?>
                    <button class="btn btn-outline-warning btn-sm rounded-3" onclick="regenerarTokenApi()">
                        <i class="bi bi-arrow-repeat me-1"></i> Regenerar Token
                    </button>
                    <?php endif; ?>
                </div>

                <div class="p-3 rounded-3 border border-secondary border-opacity-20 d-flex justify-content-between align-items-center" style="background: rgba(13, 30, 54, 0.6);">
                    <code id="displayTokenApi" class="text-warning font-monospace" style="font-size: 0.95rem;"><?php echo htmlspecialchars($apiToken); ?></code>
                    <button class="btn btn-outline-light btn-sm rounded-2" onclick="copiarAlPortapapeles('displayTokenApi')">
                        <i class="bi bi-clipboard me-1"></i> Copiar
                    </button>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- ======================================================================= -->
<!-- MODAL: ASISTENTE INICIAL (WIZARD PASO A PASO)                          -->
<!-- ======================================================================= -->
<div class="modal fade" id="modalWizard" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-secondary border-opacity-50" style="background: #09172c; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary p-2 rounded-circle">
                        <i class="bi bi-magic fs-5"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0">Asistente de Configuración Inicial de Respaldos</h5>
                        <small class="text-secondary">Configura tus NAS locales y mapea las áreas de la agencia en 3 sencillos pasos</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                
                <!-- Indicador de pasos -->
                <div class="step-indicator">
                    <div class="step-item active" id="step-ind-1">
                        <div class="step-circle">1</div>
                        <div class="small fw-semibold text-white">Catálogo NAS</div>
                    </div>
                    <div class="step-item" id="step-ind-2">
                        <div class="step-circle">2</div>
                        <div class="small fw-semibold text-secondary">Carpetas Compartidas</div>
                    </div>
                    <div class="step-item" id="step-ind-3">
                        <div class="step-circle">3</div>
                        <div class="small fw-semibold text-secondary">Mapeo por Áreas</div>
                    </div>
                </div>

                <!-- PASO 1: Formulario para Agregar NAS y Conexión API -->
                <div class="wizard-step active" id="wizard-step-1">
                    <div id="wizPaso1Formulario">
                        <h6 class="fw-bold text-white mb-2">Paso 1: Registra tu dispositivo NAS de almacenamiento</h6>
                        <p class="text-secondary small mb-3">Introduce los datos del NAS. Al guardar, se generará la conexión API para que el colector en tu máquina local se conecte y detecte las carpetas reales de inmediato.</p>
                        <form id="formWizardNas" onsubmit="wizardGuardarNas(event)">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label small text-secondary fw-bold">Nombre Identificador</label>
                                    <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25" id="wiz_nas_nombre" placeholder="ej. QNAP Principal Sistemas" required>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small text-secondary fw-bold">Marca o Tipo de Servidor</label>
                                    <select class="form-select bg-dark text-white border-secondary border-opacity-25" id="wiz_nas_marca" required>
                                        <option value="QNAP" selected>QNAP</option>
                                        <option value="Buffalo">Buffalo</option>
                                        <option value="Synology">Synology</option>
                                        <option value="Windows Server">Windows Server (SMB)</option>
                                        <option value="Linux Samba">Linux Samba</option>
                                        <option value="Otro">Otro Servidor</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-secondary fw-bold">Ruta de Red Local (UNC) o Dirección IP</label>
                                    <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25 font-monospace" id="wiz_nas_ip" placeholder="ej. \\192.168.1.100\Backups  o  192.168.1.100" required>
                                    <div class="form-text text-secondary" style="font-size: 0.75rem;">Ruta accesible desde la red local (LAN) de la oficina.</div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small text-secondary fw-bold">Usuario SMB (Lectura)</label>
                                    <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25" id="wiz_nas_user" placeholder="ej. backup_reader o admin">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small text-secondary fw-bold">Contraseña SMB</label>
                                    <input type="password" class="form-control bg-dark text-white border-secondary border-opacity-25" id="wiz_nas_pass" placeholder="••••••••">
                                </div>
                            </div>
                            <div class="text-end mt-4">
                                <button type="submit" class="btn btn-primary rounded-3 px-4 shadow">
                                    Guardar y Generar Conexión API <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Subpantalla de Conexión Local y Detección Automática -->
                    <div id="wizPaso1ConexionLocal" style="display: none;">
                        <div class="alert alert-success bg-success bg-opacity-10 border-success border-opacity-25 text-white small mb-3">
                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                            <strong>¡NAS guardada en cPanel con éxito!</strong> Ahora ejecuta el colector en tu máquina local de la oficina para conectar y descubrir las carpetas compartidas.
                        </div>

                        <div class="radar-pulse">
                            <i class="bi bi-broadcast text-info fs-4"></i>
                        </div>
                        <div class="text-center mb-3">
                            <div class="fw-bold text-white" id="wizStatusDeteccion">Esperando conexión de la máquina local...</div>
                            <div class="text-secondary small">Ejecuta el script en una PC de la oficina conectada a la misma red local que la NAS.</div>
                        </div>

                        <!-- Descarga Directa del API y Scripts Listos con Token -->
                        <div class="p-3 rounded-3 border border-info border-opacity-40 mb-3" style="background: rgba(14, 165, 233, 0.08);">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <div>
                                    <div class="fw-bold text-white small"><i class="bi bi-cloud-arrow-down-fill text-info me-1"></i> Descarga el Agente Colector listo con tu Token configurado</div>
                                    <div class="text-secondary" style="font-size: 0.75rem;">Guárdalo en tu máquina local de la oficina y dale doble clic para detectar las carpetas del NAS al instante.</div>
                                </div>
                                <a href="api_respaldos.php?action=descargar_paquete_zip" class="btn btn-info btn-sm rounded-3 fw-bold text-dark shadow">
                                    <i class="bi bi-file-earmark-zip-fill me-1"></i> Descargar Paquete ZIP Completo
                                </a>
                            </div>
                            <div class="d-flex flex-wrap gap-2 pt-2 border-top border-secondary border-opacity-20">
                                <a href="api_respaldos.php?action=descargar_script&tipo=bat" class="btn btn-outline-light btn-sm rounded-2 py-1 px-2" style="font-size: 0.78rem;" title="Lanzador directo: doble clic y detecta las carpetas">
                                    <i class="bi bi-play-circle-fill text-success me-1"></i> Lanzador Doble-Clic (.bat)
                                </a>
                                <a href="api_respaldos.php?action=descargar_script&tipo=ps1" class="btn btn-outline-info btn-sm rounded-2 py-1 px-2" style="font-size: 0.78rem;" title="Script nativo de PowerShell">
                                    <i class="bi bi-windows me-1"></i> Script PowerShell (.ps1)
                                </a>
                                <a href="api_respaldos.php?action=descargar_script&tipo=py" class="btn btn-outline-warning btn-sm rounded-2 py-1 px-2" style="font-size: 0.78rem;" title="Script multiplataforma Python">
                                    <i class="bi bi-filetype-py me-1"></i> Script Python (.py)
                                </a>
                                <a href="api_respaldos.php?action=descargar_script&tipo=json" class="btn btn-outline-secondary btn-sm rounded-2 py-1 px-2 text-white" style="font-size: 0.78rem;" title="Archivo de configuración con Token y API URL">
                                    <i class="bi bi-file-earmark-code me-1"></i> Configuración (.json)
                                </a>
                            </div>
                        </div>

                        <div class="card-executive p-3 mb-3 border-secondary border-opacity-25" style="background: rgba(13, 30, 54, 0.7);">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small fw-bold text-white"><i class="bi bi-terminal-fill text-info me-1"></i> Comando para Máquina Local (PowerShell):</span>
                                <button type="button" class="btn btn-outline-light btn-sm py-0 px-2 rounded-2" onclick="copiarAlPortapapeles('wizComandoPs')">
                                    <i class="bi bi-clipboard me-1"></i> Copiar
                                </button>
                            </div>
                            <div class="code-block" id="wizComandoPs">powershell.exe -ExecutionPolicy Bypass -File .\colector_respaldos.ps1 -ApiToken "<?php echo htmlspecialchars($apiToken); ?>" -Descubrir</div>
                            <div class="text-secondary mt-2" style="font-size: 0.75rem;">
                                O en Python: <code>python colector_respaldos.py --token "<?php echo htmlspecialchars($apiToken); ?>" --descubrir</code>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary btn-sm rounded-3" onclick="wizardReabrirFormularioNas()">
                                <i class="bi bi-arrow-left me-1"></i> Modificar Datos NAS
                            </button>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-info btn-sm rounded-3" onclick="wizardComprobarCarpetasDetectadas(true)">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Comprobar Ahora
                                </button>
                                <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 shadow" onclick="wizardIrPaso(2)">
                                    Continuar a Selección de Carpetas <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PASO 2: Detección y Selección de Carpetas -->
                <div class="wizard-step" id="wizard-step-2">
                    <h6 class="fw-bold text-white mb-2">Paso 2: Selección de Carpetas a Monitorear</h6>
                    <p class="text-secondary small mb-3">Marca las carpetas compartidas que contienen los respaldos de las computadoras. Las carpetas del sistema (@Recycle, #snapshot) se descartan automáticamente.</p>

                    <div class="p-3 rounded-3 border border-secondary border-opacity-20 mb-3" style="background: rgba(13, 30, 54, 0.4); max-height: 280px; overflow-y: auto;">
                        <div id="wizListaCarpetasCheckboxes">
                            <!-- Inyectadas por JS -->
                        </div>
                    </div>

                    <div class="input-group input-group-sm mb-3">
                        <input type="text" id="wizNuevaCarpetaNombre" class="form-control bg-dark text-white border-secondary border-opacity-25" placeholder="Nombre de carpeta (ej. Contabilidad, Ventas, Gerencia)">
                        <button class="btn btn-outline-light" type="button" onclick="wizardAgregarCarpetaManual()">
                            <i class="bi bi-plus-lg me-1"></i> Agregar a la Lista
                        </button>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary rounded-3" onclick="wizardIrPaso(1)">
                            <i class="bi bi-arrow-left me-1"></i> Anterior
                        </button>
                        <button type="button" class="btn btn-primary rounded-3 px-4 shadow" onclick="wizardIrPaso(3)">
                            Siguiente: Mapear Áreas <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>

                <!-- PASO 3: Mapeo por Áreas / Departamentos -->
                <div class="wizard-step" id="wizard-step-3">
                    <h6 class="fw-bold text-white mb-2">Paso 3: Asignación de Cada Carpeta a su Departamento</h6>
                    <p class="text-secondary small mb-3">Asocia cada carpeta seleccionada con el área correspondiente para clasificar los reportes y las 150+ computadoras.</p>

                    <div class="p-3 rounded-3 border border-secondary border-opacity-20 mb-4" style="background: rgba(13, 30, 54, 0.4); max-height: 300px; overflow-y: auto;" id="wizMapeoAreasLista">
                        <!-- Generado por JS -->
                    </div>

                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary rounded-3" onclick="wizardIrPaso(2)">
                            <i class="bi bi-arrow-left me-1"></i> Anterior
                        </button>
                        <button type="button" class="btn btn-success rounded-3 px-4 shadow fw-bold" onclick="wizardFinalizarConfiguracion()">
                            <i class="bi bi-check2-circle me-1"></i> Finalizar y Guardar Configuración
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL: EXPEDIENTE HISTÓRICO DEL EQUIPO (90 DÍAS & SLA)                  -->
<!-- ======================================================================= -->
<div class="modal fade" id="modalExpediente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-secondary border-opacity-50" style="background: #09172c; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-info bg-opacity-25 text-info border border-info p-2 rounded-circle">
                        <i class="bi bi-journal-text fs-5"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="modalExpedienteTitulo">Expediente de Equipo</h5>
                        <small class="text-secondary" id="modalExpedienteSubtitulo">Auditoría cronológica de los últimos 90 días</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                
                <div id="expedienteCargando" class="text-center py-4">
                    <div class="spinner-border text-info" role="status"></div>
                    <div class="text-secondary small mt-2">Cargando expediente e historial...</div>
                </div>

                <div id="expedienteContenido" style="display: none;">
                    <!-- Tarjeta de Métricas y Cumplimiento SLA -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-4">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-25 text-center" style="background: rgba(13, 30, 54, 0.6);">
                                <div class="text-secondary small uppercase fw-bold">Efectividad SLA (90 Días)</div>
                                <div class="display-6 fw-bold text-success my-1" id="expSlaPorcentaje">0%</div>
                                <div class="text-secondary" style="font-size: 0.75rem;">Consistencia de respaldo</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-25 text-center" style="background: rgba(13, 30, 54, 0.6);">
                                <div class="text-secondary small uppercase fw-bold">Días Exitosos</div>
                                <div class="display-6 fw-bold text-white my-1" id="expDiasExitosos">0</div>
                                <div class="text-secondary" style="font-size: 0.75rem;">Respaldos al día</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-25 text-center" style="background: rgba(13, 30, 54, 0.6);">
                                <div class="text-secondary small uppercase fw-bold">Días Con Falla</div>
                                <div class="display-6 fw-bold text-danger my-1" id="expDiasFallidos">0</div>
                                <div class="text-secondary" style="font-size: 0.75rem;">Vencidos o críticos</div>
                            </div>
                        </div>
                    </div>

                    <!-- Ficha Detallada del Equipo -->
                    <div class="p-3 rounded-3 border border-secondary border-opacity-25 mb-4" style="background: rgba(13, 30, 54, 0.4);">
                        <div class="row g-2 small">
                            <div class="col-6 col-md-3 text-secondary">Usuario Responsable:</div>
                            <div class="col-6 col-md-3 text-white fw-semibold" id="expUsuario">-</div>
                            <div class="col-6 col-md-3 text-secondary">Área / Depto:</div>
                            <div class="col-6 col-md-3 text-white fw-semibold" id="expArea">-</div>
                            <div class="col-6 col-md-3 text-secondary">NAS Asignado:</div>
                            <div class="col-6 col-md-3 text-white fw-semibold" id="expNas">-</div>
                            <div class="col-6 col-md-3 text-secondary">Último Respaldo:</div>
                            <div class="col-6 col-md-3 text-white fw-semibold" id="expUltimo">-</div>
                        </div>
                    </div>

                    <!-- Tabla de Historial Cronológico de 90 días -->
                    <h6 class="fw-bold text-white mb-2"><i class="bi bi-clock-history text-info me-2"></i>Registros Cronológicos (Últimos 90 Días)</h6>
                    <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Estado</th>
                                    <th>Archivo Generado</th>
                                    <th>Tamaño</th>
                                </tr>
                            </thead>
                            <tbody id="expTablaHistorial">
                                <!-- Filas inyectadas por JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL: AGENTE COLECTOR LAN (DESCARGA Y GUÍA DE CONFIGURACIÓN)           -->
<!-- ======================================================================= -->
<!-- MODAL: EDITAR EQUIPO Y ASIGNACIÓN -->
<div class="modal fade" id="modalEditarEquipo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-50" style="background: #09172c; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning p-2 rounded-circle">
                        <i class="bi bi-pencil-square fs-5"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0">Editar Datos del Equipo</h5>
                        <small class="text-secondary">Asigna el nombre amigable, usuario real y departamento</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formEditarEquipo" onsubmit="guardarDatosEquipo(event)">
                <div class="modal-body p-4">
                    <input type="hidden" id="editEquipoId" name="id">
                    
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Nombre / Identificador del Equipo *</label>
                        <input type="text" id="editNombreEquipo" name="nombre_equipo" class="form-control bg-dark text-white border-secondary border-opacity-50" required placeholder="Ej. MX38080VL0032 o PC-CONTABILIDAD">
                        <div class="form-text text-secondary" style="font-size: 0.75rem;">Nombre de la máquina o carpeta en la NAS.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Usuario Responsable / Asignado</label>
                        <input type="text" id="editUsuarioResponsable" name="usuario_responsable" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Ej. Brenda Morales o Juan Pérez">
                        <div class="form-text text-secondary" style="font-size: 0.75rem;">Persona o puesto que utiliza este equipo.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Área / Departamento</label>
                        <select id="editAreaNombre" name="area_nombre" class="form-select bg-dark text-white border-secondary border-opacity-50">
                            <?php foreach ($areasDisponibles as $arItem): ?>
                            <option value="<?php echo htmlspecialchars($arItem); ?>"><?php echo htmlspecialchars($arItem); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning rounded-3 px-4 fw-semibold shadow" id="btnGuardarEquipo">
                        <i class="bi bi-check2 me-1"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL: AGENTE COLECTOR LAN (INSTRUCCIONES Y SCRIPTS)                   -->
<!-- ======================================================================= -->
<div class="modal fade" id="modalColectorLan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-secondary border-opacity-50" style="background: #09172c; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary p-2 rounded-circle">
                        <i class="bi bi-terminal-fill fs-5"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0">Agente Colector LAN para Red Local</h5>
                        <small class="text-secondary">Script colector para escanear las NAS y reportar a cPanel en 1 solo envío</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                
                <div class="alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 text-light small mb-3">
                    <i class="bi bi-info-circle-fill text-primary me-2"></i>
                    El colector corre en <strong>una sola computadora o servidor de la oficina</strong> conectado a la red local (LAN). Lee las rutas desde cPanel, examina los respaldos en las NAS por SMB y envía un JSON consolidado por HTTPS.
                </div>

                <h6 class="fw-bold text-white mb-2">1. Datos de Conexión del Portal</h6>
                <div class="p-3 rounded-3 border border-secondary border-opacity-20 mb-3" style="background: rgba(13, 30, 54, 0.6);">
                    <div class="row g-2 small">
                        <div class="col-12 col-md-4 text-secondary">Endpoint API cPanel:</div>
                        <div class="col-12 col-md-8 font-monospace text-info"><?php echo htmlspecialchars($apiUrlCompleta); ?></div>
                        <div class="col-12 col-md-4 text-secondary">Bearer Token Activo:</div>
                        <div class="col-12 col-md-8 font-monospace text-warning"><?php echo htmlspecialchars($apiToken); ?></div>
                    </div>
                </div>

                <h6 class="fw-bold text-white mb-2">2. Descargar Script Colector</h6>
                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded-3 border border-secondary border-opacity-20 h-100 d-flex flex-column justify-content-between" style="background: rgba(13, 30, 54, 0.4);">
                            <div>
                                <div class="fw-bold text-white mb-1"><i class="bi bi-filetype-py text-warning me-1"></i> Versión Python</div>
                                <div class="text-secondary" style="font-size: 0.75rem;">Multiplataforma (Windows, Linux, macOS). Requiere Python 3.7+.</div>
                            </div>
                            <a href="colector_respaldos.py" download="colector_respaldos.py" class="btn btn-outline-warning btn-sm rounded-3 mt-3">
                                <i class="bi bi-download me-1"></i> Descargar colector_respaldos.py
                            </a>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded-3 border border-secondary border-opacity-20 h-100 d-flex flex-column justify-content-between" style="background: rgba(13, 30, 54, 0.4);">
                            <div>
                                <div class="fw-bold text-white mb-1"><i class="bi bi-windows text-info me-1"></i> Versión Nativa PowerShell</div>
                                <div class="text-secondary" style="font-size: 0.75rem;">Sin instalar nada. Corre nativo en Windows 10/11 y Windows Server.</div>
                            </div>
                            <a href="colector_respaldos.ps1" download="colector_respaldos.ps1" class="btn btn-outline-info btn-sm rounded-3 mt-3">
                                <i class="bi bi-download me-1"></i> Descargar colector_respaldos.ps1
                            </a>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-white mb-2">3. Configurar en Administrador de Tareas de Windows (Task Scheduler)</h6>
                <p class="text-secondary small mb-2">Para programar la ejecución automática diaria a las 8:00 AM y 8:00 PM, crea una tarea con la siguiente acción:</p>
                <div class="code-block mb-3">
                    powershell.exe -ExecutionPolicy Bypass -File "C:\Scripts\colector_respaldos.ps1"
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL: AGREGAR / EDITAR NAS                                             -->
<!-- ======================================================================= -->
<div class="modal fade" id="modalNas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-50" style="background: #09172c; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold text-white" id="modalNasTitulo">Registrar Dispositivo NAS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formNas" onsubmit="guardarNasModal(event)">
                <input type="hidden" id="nas_id" name="id" value="0">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Nombre del NAS</label>
                        <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25" id="nas_nombre" name="nombre" required placeholder="ej. QNAP Principal Sistemas">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Marca</label>
                        <select class="form-select bg-dark text-white border-secondary border-opacity-25" id="nas_marca" name="marca">
                            <option value="QNAP">QNAP</option>
                            <option value="Buffalo">Buffalo</option>
                            <option value="Synology">Synology</option>
                            <option value="Windows Server">Windows Server</option>
                            <option value="Linux Samba">Linux Samba</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">IP o Ruta de Red (UNC)</label>
                        <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25 font-monospace" id="nas_ip_ruta_red" name="ip_ruta_red" required placeholder="\\192.168.1.100\Backups">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small text-secondary fw-bold">Usuario SMB (Opcional)</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25" id="nas_usuario_smb" name="usuario_smb">
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary fw-bold">Contraseña SMB</label>
                            <input type="password" class="form-control bg-dark text-white border-secondary border-opacity-25" id="nas_password_smb" name="password_smb">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Notas u Observaciones</label>
                        <textarea class="form-control bg-dark text-white border-secondary border-opacity-25" id="nas_notas" name="notas" rows="2" placeholder="Ubicación física en SITE, capacidad, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">Guardar NAS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL: AGREGAR CARPETA INDIVIDUAL                                       -->
<!-- ======================================================================= -->
<div class="modal fade" id="modalCarpeta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-50" style="background: #09172c; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold text-white">Añadir Carpeta a Monitorear</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formCarpeta" onsubmit="guardarCarpetaModal(event)">
                <input type="hidden" id="carp_nas_id" name="nas_id" value="0">
                <input type="hidden" id="carp_id" name="id" value="0">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Dispositivo NAS</label>
                        <select class="form-select bg-dark text-white border-secondary border-opacity-25" id="carp_nas_select" onchange="document.getElementById('carp_nas_id').value = this.value">
                            <?php foreach ($nasCatalogo as $n): ?>
                            <option value="<?php echo $n['id']; ?>"><?php echo htmlspecialchars($n['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Nombre de la Carpeta</label>
                        <input type="text" class="form-control bg-dark text-white border-secondary border-opacity-25" id="carp_nombre" name="nombre_carpeta" required placeholder="ej. Contabilidad, Ventas, Sistemas">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-bold">Área / Departamento Asignado</label>
                        <select class="form-select bg-dark text-white border-secondary border-opacity-25" id="carp_area" name="area_nombre">
                            <?php foreach ($areasDisponibles as $ar): ?>
                            <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="carp_monitorear" name="monitorear" value="1" checked>
                        <label class="form-check-label text-white small" for="carp_monitorear">Activar monitoreo continuo de esta carpeta</label>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">Guardar Carpeta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL: PROGRAMAR DÍAS Y POLÍTICA DE EXENCIÓN POR ÁREA                  -->
<!-- ======================================================================= -->
<div class="modal fade" id="modalEditarAreaPolitica" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary border-opacity-50" style="background: #09172c; color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary bg-opacity-25 text-primary border border-primary p-2 rounded-circle">
                        <i class="bi bi-calendar2-week fs-5"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="tituloModalAreaPolitica">Programar Respaldo de Área</h5>
                        <small class="text-secondary">Días de ejecución y política semanal de SLA</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formAreaPolitica" onsubmit="guardarAreaPolitica(event)">
                <input type="hidden" id="areaPol_id" name="id" value="">
                <input type="hidden" id="areaPol_nombre" name="area_nombre" value="">
                
                <div class="modal-body p-4">
                    <!-- Nombre de Área -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Departamento / Área</label>
                        <div class="p-2 rounded-3 border border-secondary border-opacity-25 bg-dark d-flex align-items-center gap-2">
                            <i class="bi bi-building text-primary fs-5"></i>
                            <span class="fw-bold text-white" id="displayAreaPolNombre">--</span>
                        </div>
                    </div>

                    <!-- Días de la Semana -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold d-block">
                            Días de Respaldo Programados
                            <span class="text-muted fw-normal float-end" style="font-size: 0.75rem;">(Selecciona uno o varios)</span>
                        </label>
                        <div class="d-flex flex-wrap gap-2 pt-1" id="contenedorDiasSemanaCheckboxes">
                            <?php 
                            $diasSem = ['Lun' => 'Lunes', 'Mar' => 'Martes', 'Mié' => 'Miércoles', 'Jue' => 'Jueves', 'Vie' => 'Viernes', 'Sáb' => 'Sábado', 'Dom' => 'Domingo'];
                            foreach ($diasSem as $cod => $nom):
                            ?>
                            <div class="form-check form-check-inline m-0">
                                <input class="btn-check check-dia-prog" type="checkbox" id="checkDia_<?php echo $cod; ?>" value="<?php echo $cod; ?>" autocomplete="off">
                                <label class="btn btn-outline-info btn-sm px-3 rounded-3" for="checkDia_<?php echo $cod; ?>"><?php echo $cod; ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text text-secondary" style="font-size: 0.75rem;">
                            Los días antes del programado no se marcarán como "sin copia" en la semana en curso.
                        </div>
                    </div>

                    <!-- Semanas del Mes que Aplica -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold d-block">
                            Semanas del Mes en que Aplica Respaldo
                            <span class="text-muted fw-normal float-end" style="font-size: 0.75rem;">(Selecciona las semanas activas)</span>
                        </label>
                        <div class="d-flex flex-wrap gap-2 pt-1" id="contenedorSemanasMesCheckboxes">
                            <?php 
                            $semanasNombres = [
                                'S1' => 'Semana 1 (1-7)',
                                'S2' => 'Semana 2 (8-14)',
                                'S3' => 'Semana 3 (15-21)',
                                'S4' => 'Semana 4 (22-28)',
                                'S5' => 'Semana 5 (29-31)'
                            ];
                            foreach ($semanasNombres as $sKey => $sNom):
                            ?>
                            <div class="form-check form-check-inline m-0">
                                <input class="btn-check check-semana-mes-prog" type="checkbox" id="checkSemanaMes_<?php echo $sKey; ?>" value="<?php echo $sKey; ?>" autocomplete="off" checked>
                                <label class="btn btn-outline-primary btn-sm px-2 rounded-3" for="checkSemanaMes_<?php echo $sKey; ?>">
                                    <i class="bi bi-calendar-check me-1"></i><?php echo $sNom; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text text-secondary" style="font-size: 0.75rem;">
                            Las semanas no seleccionadas se mostrarán en <strong class="text-white">GRIS (No aplica)</strong> y no contarán como omisiones ni afectarán las gráficas o semáforos.
                        </div>
                    </div>

                    <!-- Frecuencia -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Frecuencia de Evaluación</label>
                        <select class="form-select bg-dark text-white border-secondary border-opacity-25" id="areaPol_frecuencia" name="frecuencia">
                            <option value="semanal" selected>Semanal (Predeterminado)</option>
                            <option value="quincenal">Quincenal</option>
                            <option value="mensual">Mensual</option>
                            <option value="diario">Diario</option>
                        </select>
                    </div>

                    <!-- Switch: ¿Aplica esta semana? -->
                    <div class="p-3 rounded-3 border border-secondary border-opacity-25 mb-3" style="background: rgba(13, 30, 54, 0.4);">
                        <div class="form-check form-switch d-flex justify-content-between align-items-center ps-0 mb-2">
                            <label class="form-check-label fw-bold text-white mb-0" for="areaPol_aplica_semana">
                                <i class="bi bi-check2-circle text-success me-1"></i> ¿Aplica respaldo esta semana?
                            </label>
                            <input class="form-check-input ms-0" type="checkbox" role="switch" id="areaPol_aplica_semana" name="aplica_esta_semana" value="1" checked onchange="toggleMotivoExencionInput()">
                        </div>
                        <div class="text-secondary small" style="font-size: 0.78rem;">
                            Si se desmarca, ningún equipo de esta área penalizará el porcentaje de SLA de la semana actual.
                        </div>
                    </div>

                    <!-- Motivo de Exención -->
                    <div class="mb-3" id="grupoMotivoExencion" style="display: none;">
                        <label class="form-label text-warning small fw-bold">
                            <i class="bi bi-info-circle me-1"></i> Motivo de Exención (opcional)
                        </label>
                        <input type="text" class="form-control bg-dark text-white border-warning border-opacity-50" id="areaPol_motivo" name="motivo_exencion" placeholder="Ej: Semana de inventario, vacaciones, mantenimiento">
                        <div class="form-text text-secondary" style="font-size: 0.75rem;">Quedará visible como justificación en el semáforo y auditoría.</div>
                    </div>

                    <!-- Notas -->
                    <div class="mb-2">
                        <label class="form-label text-secondary small fw-bold">Notas Internas</label>
                        <textarea class="form-control bg-dark text-white border-secondary border-opacity-25" id="areaPol_notas" name="notas" rows="2" placeholder="Observaciones adicionales sobre esta área..."></textarea>
                    </div>
                </div>

                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-3 px-4">
                        <i class="bi bi-save me-1"></i> Guardar Programación
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include_once 'pwa_body.php'; ?>

<script>
// =============================================================================
// JAVASCRIPT: GESTIÓN DE TABLA, FILTROS EN TIEMPO REAL Y ASISTENTE
// =============================================================================

let nasSeleccionadoActual = <?php echo !empty($nasCatalogo[0]['id']) ? $nasCatalogo[0]['id'] : 0; ?>;
let wizardNasIdCreado = 0;
let wizardCarpetasTemp = [];

// 1. FILTRADO EN TIEMPO REAL DE LA TABLA DE EQUIPOS
function filtrarTablaEquipos() {
    const q = (document.getElementById('buscadorEquipos').value || '').toLowerCase().trim();
    const area = document.getElementById('filtroArea').value;
    const nas = document.getElementById('filtroNas').value;
    const estado = document.getElementById('filtroEstado').value;

    const filas = document.querySelectorAll('.fila-equipo');
    let visibles = 0;

    filas.forEach(f => {
        const fEq = f.dataset.equipo || '';
        const fUsr = f.dataset.usuario || '';
        const fArea = f.dataset.area || '';
        const fNas = f.dataset.nas || '';
        const fEstado = f.dataset.estado || '';
        const fArch = f.dataset.archivo || '';

        const coincideTexto = !q || fEq.includes(q) || fUsr.includes(q) || fArch.includes(q);
        const coincideArea = !area || fArea === area;
        const coincideNas = !nas || fNas === nas;
        const coincideEstado = !estado || fEstado === estado;

        if (coincideTexto && coincideArea && coincideNas && coincideEstado) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });

    const cont = document.getElementById('contadorFiltrados');
    if (cont) {
        cont.innerHTML = `Mostrando <strong class="text-white">${visibles}</strong> de <strong class="text-white">${filas.length}</strong> equipos`;
    }
}

// 2. EXPEDIENTE POR MÁQUINA (MODAL 90 DÍAS & SLA)
function abrirExpedienteEquipo(nombreEquipo, equipoId) {
    const modalEl = document.getElementById('modalExpediente');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    document.getElementById('modalExpedienteTitulo').innerText = `Expediente: ${nombreEquipo}`;
    document.getElementById('expedienteCargando').style.display = 'block';
    document.getElementById('expedienteContenido').style.display = 'none';

    fetch(`api_respaldos.php?action=obtener_historial_equipo&equipo_id=${equipoId}`)
        .then(r => r.json())
        .then(res => {
            document.getElementById('expedienteCargando').style.display = 'none';
            if (!res.success) {
                Swal.fire('Error', res.error || 'No se pudo cargar el expediente', 'error');
                return;
            }

            document.getElementById('expedienteContenido').style.display = 'block';

            // Datos generales
            const eq = res.equipo;
            document.getElementById('expUsuario').innerText = eq.usuario_responsable || 'Sin asignar';
            document.getElementById('expArea').innerText = eq.area_nombre || 'General';
            document.getElementById('expNas').innerText = eq.nas_nombre || 'No asignado';
            document.getElementById('expUltimo').innerText = eq.ultimo_respaldo || 'Nunca';

            // Métricas
            const met = res.metricas;
            document.getElementById('expSlaPorcentaje').innerText = `${met.porcentaje_sla}%`;
            document.getElementById('expDiasExitosos').innerText = met.dias_exitosos;
            document.getElementById('expDiasFallidos').innerText = met.dias_vencidos + met.dias_criticos;

            // Historial
            const tbody = document.getElementById('expTablaHistorial');
            tbody.innerHTML = '';

            if (!res.historial || res.historial.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary py-3">Sin registros históricos previos en los últimos 90 días.</td></tr>';
            } else {
                res.historial.forEach(h => {
                    let badge = '<span class="badge badge-critico">Crítico</span>';
                    if (h.estado === 'exitoso') badge = '<span class="badge badge-exitoso">Al Día</span>';
                    else if (h.estado === 'vencido') badge = '<span class="badge badge-vencido">Vencido</span>';
                    else if (h.estado === 'incompleto') badge = '<span class="badge badge-incompleto">Incompleto</span>';

                    const bytes = parseFloat(h.tamano_bytes || 0);
                    const mb = (bytes / (1024 * 1024)).toFixed(2) + ' MB';

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="text-white fw-semibold">${h.fecha}</td>
                        <td>${badge}</td>
                        <td class="small font-monospace">${h.archivo_nombre || 'Sin archivo'}</td>
                        <td class="text-secondary small">${mb}</td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        })
        .catch(err => {
            document.getElementById('expedienteCargando').style.display = 'none';
            Swal.fire('Error de red', err.message, 'error');
        });
}

// 3. VISTA MATRIZ SEMANAL CON FILTRO POR MES (S1..S5)
let cacheMatrizDatos = [];

function cargarMatrizSemanasMes(mes) {
    const cargandoEl = document.getElementById('matrizCargando');
    const contenidoEl = document.getElementById('matrizContenido');
    if (cargandoEl) cargandoEl.style.display = 'block';
    if (contenidoEl) contenidoEl.style.display = 'none';

    let url = 'api_respaldos.php?action=obtener_matriz_semanal_mes';
    if (mes) {
        url += '&mes=' + encodeURIComponent(mes);
    }

    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (cargandoEl) cargandoEl.style.display = 'none';
            if (!res.success) {
                Swal.fire('Error', res.error || 'No se pudo cargar la matriz semanal', 'error');
                return;
            }

            if (contenidoEl) contenidoEl.style.display = 'block';

            // Actualizar selector de meses si no tiene opciones o cambió
            const selMes = document.getElementById('selectMesMatriz');
            if (selMes && res.meses_disponibles && (selMes.options.length <= 1 || selMes.value !== res.mes_seleccionado)) {
                selMes.innerHTML = '';
                res.meses_disponibles.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.valor;
                    opt.innerText = m.etiqueta;
                    if (m.activo) opt.selected = true;
                    selMes.appendChild(opt);
                });
            }

            // Construir cabeceras de columnas según semanas calendario del mes (Domingo a Sábado)
            const trHead = document.getElementById('matrizHeaderSemanas');
            if (trHead) {
                let thHtml = '<th style="min-width: 250px;" class="th-sticky-first">Equipo / Área / Usuario</th>';
                const semanasKeys = Object.keys(res.semanas_def);
                semanasKeys.forEach(sId => {
                    const sConf = res.semanas_def[sId];
                    thHtml += `
                        <th class="text-center" style="min-width: 140px;">
                            <div class="fw-bold text-white text-uppercase" style="letter-spacing: 0.5px; font-size: 0.78rem;">${sConf.label}</div>
                            <div class="badge-semana-rango mt-1">${sConf.dias_label}</div>
                        </th>`;
                });
                thHtml += '<th class="text-center" style="min-width: 130px;">Cumplimiento</th>';
                thHtml += '<th class="text-end" style="min-width: 90px;">Expediente</th>';
                trHead.innerHTML = thHtml;
            }

            cacheMatrizDatos = res.matriz || [];
            renderizarFilasMatriz(cacheMatrizDatos, res.semanas_def);
            
            // Sincronizar scroll superior
            const topScrollEl = document.getElementById('scrollMatrizTop');
            if (topScrollEl) topScrollEl.style.display = 'block';
            setTimeout(() => {
                sincronizarScrollDoble('scrollMatrizTop', 'matrizContenido', 'scrollMatrizInnerTop', 'tablaMatrizSemanal');
            }, 100);
        })
        .catch(err => {
            if (cargandoEl) cargandoEl.style.display = 'none';
            Swal.fire('Error de conexión', err.message, 'error');
        });
}

function renderizarFilasMatriz(matriz, semanasDef) {
    const tbody = document.getElementById('matrizBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    const contadorEl = document.getElementById('contadorEquiposMatriz');
    if (contadorEl) {
        contadorEl.innerText = `Mostrando ${matriz.length} equipos`;
    }

    if (!matriz || matriz.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-4">No hay equipos registrados para mostrar en esta matriz.</td></tr>';
        return;
    }

    const semanasKeys = Object.keys(semanasDef);

    matriz.forEach(m => {
        const tr = document.createElement('tr');
        tr.className = 'fila-matriz';
        tr.setAttribute('data-texto', (m.nombre_equipo + ' ' + (m.area_nombre || '') + ' ' + (m.usuario_responsable || '')).toLowerCase());

        let celdasSemanas = '';
        semanasKeys.forEach(sId => {
            const sem = m.semanas[sId] || { estado: 'sin_respaldo' };
            let pillHtml = '';

            if (sem.estado === 'exitoso') {
                const fShow = sem.fecha_corta || 'Cumplido';
                const tit = `Respaldo exitoso: ${sem.archivo || ''} (${sem.fecha_respaldo || ''})`;
                pillHtml = `<span class="badge-semana-pill badge-semana-exitoso" title="${tit}"><i class="bi bi-check-circle-fill"></i> ${fShow}</span>`;
            } else if (sem.estado === 'exento') {
                pillHtml = `<span class="badge-semana-pill badge-semana-exento" title="Área exenta esta semana (no aplica)"><i class="bi bi-pause-circle"></i> No aplica</span>`;
            } else if (sem.estado === 'programado') {
                pillHtml = `<span class="badge-semana-pill badge-semana-programado" title="Programado para ${sem.label_extra || 'esta semana'}"><i class="bi bi-calendar-event"></i> ${sem.label_extra || 'Programado'}</span>`;
            } else if (sem.estado === 'en_curso') {
                pillHtml = `<span class="badge-semana-pill badge-semana-en_curso" title="Semana en curso (al día)"><i class="bi bi-clock-history"></i> En curso</span>`;
            } else if (sem.estado === 'futuro') {
                pillHtml = `<span class="badge-semana-pill badge-semana-futuro" title="Semana programada"><i class="bi bi-dash"></i> Futuro</span>`;
            } else {
                pillHtml = `<span class="badge-semana-pill badge-semana-sin_respaldo" title="Sin respaldo en esta semana"><i class="bi bi-x-circle-fill"></i> Sin resp.</span>`;
            }

            celdasSemanas += `<td class="text-center align-middle">${pillHtml}</td>`;
        });

        // Color de barra de cumplimiento según %
        const pct = (m.cumplimiento_porcentaje !== undefined ? m.cumplimiento_porcentaje : (m.porcentaje || 0));
        let barClass = 'bg-danger';
        let textClass = 'text-danger';
        if (pct >= 80) {
            barClass = 'bg-success';
            textClass = 'text-success';
        } else if (pct >= 50) {
            barClass = 'bg-warning';
            textClass = 'text-warning';
        }

        tr.innerHTML = `
            <td class="align-middle td-sticky-first">
                <div class="fw-bold text-white small">${m.nombre_equipo}</div>
                <div class="text-secondary" style="font-size: 0.72rem;">
                    <span class="badge bg-secondary bg-opacity-25 text-light px-1 py-0 me-1">${m.area_nombre || 'General'}</span>
                    ${m.usuario_responsable ? '• ' + m.usuario_responsable : ''}
                </div>
            </td>
            ${celdasSemanas}
            <td class="text-center align-middle">
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <div class="progress flex-grow-1" style="height: 6px; background: rgba(255,255,255,0.08); max-width: 60px;">
                        <div class="progress-bar ${barClass}" style="width: ${pct}%;"></div>
                    </div>
                    <span class="small fw-bold ${textClass}">${pct}%</span>
                </div>
            </td>
            <td class="text-end align-middle">
                <button class="btn btn-outline-info btn-sm rounded-3 px-2 py-1" onclick="abrirExpedienteEquipo('${m.nombre_equipo}', ${m.equipo_id})" title="Ver expediente">
                    <i class="bi bi-journal-text"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function filtrarTablaMatriz() {
    const q = (document.getElementById('filtroMatrizRapido')?.value || '').trim().toLowerCase();
    const filas = document.querySelectorAll('.fila-matriz');
    let visibles = 0;
    filas.forEach(f => {
        const txt = f.getAttribute('data-texto') || '';
        if (!q || txt.includes(q)) {
            f.style.display = '';
            visibles++;
        } else {
            f.style.display = 'none';
        }
    });
    const contadorEl = document.getElementById('contadorEquiposMatriz');
    if (contadorEl) {
        contadorEl.innerText = `Mostrando ${visibles} de ${filas.length} equipos`;
    }
}

// =============================================================================
// FUNCIÓN DE DOBLE SCROLLBAR SINCRONIZADO (SUPERIOR E INFERIOR)
// =============================================================================
function sincronizarScrollDoble(topId, bottomId, innerId, tablaId) {
    const topEl = document.getElementById(topId);
    const bottomEl = document.getElementById(bottomId);
    const innerEl = document.getElementById(innerId);
    const tablaEl = document.getElementById(tablaId);

    if (!topEl || !bottomEl || !innerEl || !tablaEl) return;

    const actualizarAncho = () => {
        const anchoTotal = tablaEl.scrollWidth;
        innerEl.style.width = anchoTotal + 'px';
        if (anchoTotal <= bottomEl.clientWidth + 10) {
            topEl.style.display = 'none';
        } else {
            topEl.style.display = 'block';
        }
    };

    actualizarAncho();
    window.removeEventListener('resize', actualizarAncho);
    window.addEventListener('resize', actualizarAncho);

    let syncingTop = false;
    let syncingBottom = false;

    topEl.onscroll = () => {
        if (!syncingTop) {
            syncingBottom = true;
            bottomEl.scrollLeft = topEl.scrollLeft;
        }
        syncingTop = false;
    };

    bottomEl.onscroll = () => {
        if (!syncingBottom) {
            syncingTop = true;
            topEl.scrollLeft = bottomEl.scrollLeft;
        }
        syncingBottom = false;
    };
}

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        sincronizarScrollDoble('scrollDashboardTop', 'dashboardContenido', 'scrollDashboardInnerTop', 'tablaEquipos');
    }, 250);

    // Eventos al cambiar entre pestañas
    document.getElementById('tab-dashboard-link')?.addEventListener('shown.bs.tab', () => {
        sincronizarScrollDoble('scrollDashboardTop', 'dashboardContenido', 'scrollDashboardInnerTop', 'tablaEquipos');
    });
    document.getElementById('tab-heatmap-link')?.addEventListener('shown.bs.tab', () => {
        sincronizarScrollDoble('scrollMatrizTop', 'matrizContenido', 'scrollMatrizInnerTop', 'tablaMatrizSemanal');
    });
    document.getElementById('tab-areas-link')?.addEventListener('shown.bs.tab', () => {
        cargarPoliticasAreas();
    });
    document.getElementById('tab-nas-link')?.addEventListener('shown.bs.tab', () => {
        cargarCatalogoNas();
    });
});

// 4. GESTIÓN DE NAS Y CARPETAS
function cargarCatalogoNas() {
    if (nasSeleccionadoActual > 0) {
        cargarCarpetasPorNas(nasSeleccionadoActual);
    } else {
        const primerNasCard = document.querySelector('.card-nas-item');
        if (primerNasCard) {
            primerNasCard.click();
        }
    }
}

function seleccionarNasCarpetas(nasId, nasNombre) {
    nasSeleccionadoActual = nasId;
    const tit = document.getElementById('tituloCarpetasNas');
    if (tit) tit.innerText = `Carpetas de: ${nasNombre}`;
    const sub = document.getElementById('subtituloCarpetasNas');
    if (sub) sub.innerText = `Mapeo por área y exclusión de carpetas del sistema`;

    document.querySelectorAll('.card-nas-item').forEach(el => {
        el.style.borderColor = 'rgba(255,255,255,0.15)';
        el.style.background = 'rgba(13, 30, 54, 0.5)';
    });
    const cardActiva = document.getElementById(`cardNas_${nasId}`);
    if (cardActiva) {
        cardActiva.style.borderColor = '#38bdf8';
        cardActiva.style.background = 'rgba(56, 189, 248, 0.1)';
    }

    cargarCarpetasPorNas(nasId);
}

function cargarCarpetasPorNas(nasId) {
    const cont = document.getElementById('contenedorCarpetas');
    cont.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-info"></div> Cargando carpetas...</div>';

    fetch(`api_respaldos.php?action=listar_carpetas&nas_id=${nasId}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                cont.innerHTML = `<div class="text-danger small py-3">${res.error}</div>`;
                return;
            }

            if (!res.carpetas || res.carpetas.length === 0) {
                cont.innerHTML = `
                    <div class="text-center py-4 text-secondary small">
                        No hay carpetas asociadas a este NAS aún.
                        <button class="btn btn-outline-primary btn-sm d-block mx-auto mt-2" onclick="abrirModalNuevaCarpeta(${nasId})">
                            <i class="bi bi-folder-plus me-1"></i> Añadir Primera Carpeta
                        </button>
                    </div>
                `;
                return;
            }

            let html = `
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th style="min-width: 280px;">Carpeta / Subcarpetas NetBak</th>
                                <th>Área Asignada</th>
                                <th>Monitorear</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            res.carpetas.forEach(c => {
                const esSys = parseInt(c.es_sistema) === 1;
                const esNetbak = c.nombre_carpeta.toLowerCase().includes('netbak');
                const checkMon = parseInt(c.monitorear) === 1 ? 'checked' : '';
                const tagSys = esSys ? '<span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50 ms-2" style="font-size:0.65rem;">Sistema / Descarte</span>' : '';
                const tagNetbak = esNetbak ? '<span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-50 ms-2" style="font-size:0.65rem;"><i class="bi bi-hdd-network me-1"></i>NetBak</span>' : '';

                const subEquipos = c.subcarpetas_netbak || [];
                const tieneSubs = subEquipos.length > 0;
                const totalMon = c.total_monitoreadas !== undefined ? c.total_monitoreadas : subEquipos.filter(s => parseInt(s.monitorear) === 1).length;

                // Generar filas o cards para cada máquina dentro de NetBakData
                let subHtml = '';
                if (tieneSubs) {
                    subEquipos.forEach(eq => {
                        const eqCheck = parseInt(eq.monitorear) === 1 ? 'checked' : '';
                        const stBadge = eq.estado === 'exitoso' ? 'text-success' : (eq.estado === 'vencido' ? 'text-warning' : 'text-danger');
                        const ultStr = eq.ultimo_respaldo ? eq.ultimo_respaldo.substring(0, 16) : 'Sin respaldo';
                        const eqNombreEsc = (eq.nombre_equipo || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");

                        subHtml += `
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="p-2 rounded-2 border border-secondary border-opacity-15 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.03);">
                                    <div class="overflow-hidden me-2">
                                        <div class="fw-bold text-white text-truncate small" title="${eq.nombre_equipo}">
                                            <i class="bi bi-pc-display ${stBadge} me-1"></i> ${eq.nombre_equipo}
                                        </div>
                                        <div class="text-secondary" style="font-size: 0.68rem;">
                                            <i class="bi bi-clock me-1"></i> ${ultStr}
                                        </div>
                                    </div>
                                    <div class="form-check form-switch m-0" title="Activar/Desactivar monitoreo de esta máquina">
                                        <input class="form-check-input" type="checkbox" ${eqCheck} onchange="toggleMonitorearEquipo(${eq.id}, this.checked, '${eqNombreEsc}', ${c.id})">
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    subHtml = `
                        <div class="col-12">
                            <div class="text-secondary small py-2 px-3 rounded-2" style="background: rgba(255,255,255,0.02); font-size: 0.75rem;">
                                <i class="bi bi-info-circle me-1 text-info"></i> No se han registrado máquinas dentro de esta carpeta aún. Se listarán automáticamente al correr <code>colector_respaldos.ps1 -Descubrir</code>.
                            </div>
                        </div>
                    `;
                }

                const carpNombreEsc = (c.nombre_carpeta || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");

                html += `
                    <tr class="${esNetbak ? 'fila-netbak' : ''}" style="border-top: 1px solid rgba(255,255,255,0.08);">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <button class="btn btn-sm btn-dark border border-secondary border-opacity-25 px-2 py-0 text-white rounded-2" onclick="toggleDesgloseSubcarpetas(${c.id})" title="Desglosar máquinas de esta carpeta">
                                    <i class="bi bi-chevron-down" id="chevronCarp_${c.id}"></i>
                                </button>
                                <div>
                                    <div class="fw-bold text-white">
                                        <i class="bi bi-folder2-open text-warning me-1"></i> ${c.nombre_carpeta} ${tagNetbak} ${tagSys}
                                    </div>
                                    <div class="text-secondary" style="font-size: 0.72rem;">
                                        <i class="bi bi-pc-display text-info me-1"></i> <span id="contadorSub_${c.id}">${totalMon} de ${subEquipos.length} máquinas monitoreadas</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-secondary bg-opacity-25 text-light">${c.area_nombre || 'General'}</span>
                        </td>
                        <td>
                            <div class="form-check form-switch" title="Monitorear toda la carpeta">
                                <input class="form-check-input" type="checkbox" ${checkMon} onchange="toggleMonitorearCarpeta(${c.id}, this.checked)">
                            </div>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-outline-danger btn-sm p-1 rounded-2" onclick="eliminarCarpeta(${c.id}, '${carpNombreEsc}')" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <!-- Fila Expandible con el desglose de subcarpetas dentro de NetBakData -->
                    <tr id="desgloseSub_${c.id}" class="fila-desglose" style="display: table-row;">
                        <td colspan="4" class="p-2 border-0" style="background: rgba(2, 9, 20, 0.45);">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-20" style="background: rgba(9, 23, 44, 0.6); margin-left: 28px;">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary border-opacity-10 gap-2">
                                    <span class="text-warning small fw-bold">
                                        <i class="bi bi-diagram-3-fill me-1"></i> Subcarpetas de NetBakData (Selecciona cuáles sí y cuáles no vigilar):
                                    </span>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-outline-success btn-sm py-0 px-2 rounded-2" style="font-size: 0.72rem;" onclick="toggleTodasSubcarpetas(${c.id}, 1)">
                                            <i class="bi bi-check-all me-1"></i> Activar Todas
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm py-0 px-2 rounded-2" style="font-size: 0.72rem;" onclick="toggleTodasSubcarpetas(${c.id}, 0)">
                                            <i class="bi bi-x me-1"></i> Desactivar Todas
                                        </button>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    ${subHtml}
                                </div>
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += '</tbody></table></div>';
            cont.innerHTML = html;
        });
}

function toggleDesgloseSubcarpetas(carpetaId) {
    const fila = document.getElementById(`desgloseSub_${carpetaId}`);
    const chev = document.getElementById(`chevronCarp_${carpetaId}`);
    if (!fila) return;

    if (fila.style.display === 'none') {
        fila.style.display = 'table-row';
        if (chev) {
            chev.className = 'bi bi-chevron-down';
        }
    } else {
        fila.style.display = 'none';
        if (chev) {
            chev.className = 'bi bi-chevron-right';
        }
    }
}

function toggleMonitorearCarpeta(carpetaId, estado) {
    const fd = new FormData();
    fd.append('id', carpetaId);
    fd.append('monitorear', estado ? 1 : 0);

    // Sincronizar visualmente los switches de subcarpetas
    const contenedor = document.getElementById(`desgloseSub_${carpetaId}`);
    if (contenedor) {
        const switches = contenedor.querySelectorAll('input[type="checkbox"]');
        switches.forEach(sw => sw.checked = estado);
        actualizarContadorCarpetaLocal(carpetaId);
    }

    fetch('api_respaldos.php?action=toggle_monitorear_carpeta', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            Swal.fire('Error', res.error || 'No se pudo actualizar la carpeta', 'error');
            return;
        }
        if (res.metricas) {
            actualizarVistasConMetricas(res.metricas);
        }
        if (typeof cargarMatrizSemanasMes === 'function') {
            const mesSel = document.getElementById('filtroMesMatriz')?.value;
            cargarMatrizSemanasMes(mesSel);
        }
    })
    .catch(err => {
        console.error('Error al toggle carpeta:', err);
    });
}

function toggleMonitorearEquipo(equipoId, activo, equipoNombre, carpetaId) {
    const fd = new FormData();
    fd.append('id', equipoId);
    fd.append('monitorear', activo ? 1 : 0);

    fetch('api_respaldos.php?action=toggle_monitorear_equipo', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            Swal.fire('Error', res.error || 'No se pudo guardar la configuración', 'error');
            const sw = document.querySelector(`input[onchange*="toggleMonitorearEquipo(${equipoId}"]`);
            if (sw) sw.checked = !activo;
            return;
        }

        actualizarContadorCarpetaLocal(carpetaId);

        // Ocultar o mostrar fila en la tabla del Dashboard principal
        const filaEq = document.querySelector(`#tablaEquipos tr[data-equipo-id="${equipoId}"]`);
        if (filaEq) {
            filaEq.style.display = activo ? '' : 'none';
        }

        if (res.metricas) {
            actualizarVistasConMetricas(res.metricas);
        }

        if (typeof cargarMatrizSemanasMes === 'function') {
            const mesSel = document.getElementById('filtroMesMatriz')?.value;
            cargarMatrizSemanasMes(mesSel);
        }
    })
    .catch(err => {
        console.error('Error al actualizar monitoreo del equipo:', err);
        const sw = document.querySelector(`input[onchange*="toggleMonitorearEquipo(${equipoId}"]`);
        if (sw) sw.checked = !activo;
    });
}

function toggleTodasSubcarpetas(carpetaId, activo) {
    const val = activo ? 1 : 0;
    const fd = new FormData();
    fd.append('carpeta_id', carpetaId);
    fd.append('monitorear', val);

    // Actualizar switches visualmente de inmediato
    const contenedor = document.getElementById(`desgloseSub_${carpetaId}`);
    if (contenedor) {
        const switches = contenedor.querySelectorAll('input[type="checkbox"]');
        switches.forEach(sw => sw.checked = (val === 1));
        actualizarContadorCarpetaLocal(carpetaId);
    }

    fetch('api_respaldos.php?action=toggle_todas_subcarpetas', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            Swal.fire('Error', res.error || 'No se pudo actualizar el grupo de máquinas', 'error');
            return;
        }

        if (res.metricas) {
            actualizarVistasConMetricas(res.metricas);
        }

        if (typeof cargarMatrizSemanasMes === 'function') {
            const mesSel = document.getElementById('filtroMesMatriz')?.value;
            cargarMatrizSemanasMes(mesSel);
        }
    })
    .catch(err => {
        console.error('Error al actualizar todas las subcarpetas:', err);
    });
}

function actualizarContadorCarpetaLocal(carpetaId) {
    const contenedor = document.getElementById(`desgloseSub_${carpetaId}`);
    const badge = document.getElementById(`contadorSub_${carpetaId}`);
    if (contenedor && badge) {
        const switches = contenedor.querySelectorAll('input[type="checkbox"]');
        let activas = 0;
        switches.forEach(sw => { if (sw.checked) activas++; });
        badge.innerText = `${activas} de ${switches.length} máquinas monitoreadas`;
    }
}

function actualizarVistasConMetricas(m) {
    if (!m) return;

    const totalVal = (m.total !== undefined) ? m.total : m.total_equipos;
    if (totalVal !== undefined) {
        const elTot = document.getElementById('kpiTotalEquipos');
        if (elTot) elTot.innerText = totalVal;
    }

    const pctVal = (m.porcentaje !== undefined) ? m.porcentaje : m.cumplimiento_porcentaje;
    if (pctVal !== undefined) {
        const elSla = document.getElementById('kpiPorcentajeCumplimiento');
        if (elSla) elSla.innerText = pctVal + '%';
        const elBar = document.getElementById('kpiBarraCumplimiento');
        if (elBar) elBar.style.width = pctVal + '%';
    }

    if (m.exitosos !== undefined) {
        const elExit = document.getElementById('kpiExitosos');
        if (elExit) elExit.innerText = m.exitosos;
    }
    if (m.vencidos !== undefined) {
        const elVenc = document.getElementById('kpiVencidos');
        if (elVenc) elVenc.innerText = m.vencidos;
    }
    if (m.criticos !== undefined) {
        const elCrit = document.getElementById('kpiCriticos');
        if (elCrit) elCrit.innerText = m.criticos;
    }
    if (m.incompletos !== undefined) {
        const elIncomp = document.getElementById('kpiIncompletos');
        if (elIncomp) elIncomp.innerText = m.incompletos;
    }
    if (m.exentos !== undefined) {
        const elEx = document.getElementById('kpiExentosBadge');
        if (elEx) {
            if (m.exentos > 0) {
                elEx.innerText = `${m.exentos} exentos`;
                elEx.classList.remove('d-none');
            } else {
                elEx.classList.add('d-none');
            }
        }
    }

    // 1. Gráfica Dona
    if (window.chartInstanciaDona && m.exitosos !== undefined) {
        window.chartInstanciaDona.data.datasets[0].data = [
            m.exitosos || 0,
            m.vencidos || 0,
            m.criticos || 0,
            m.incompletos || 0
        ];
        window.chartInstanciaDona.update();
    }

    // 2. Gráfica Áreas
    if (window.chartInstanciaAreas) {
        let labels = m.areas_labels || (m.conteo_por_area ? Object.keys(m.conteo_por_area) : null);
        let data = m.areas_data || (m.conteo_por_area ? Object.values(m.conteo_por_area) : null);
        if (labels && data) {
            window.chartInstanciaAreas.data.labels = labels;
            window.chartInstanciaAreas.data.datasets[0].data = data;
            window.chartInstanciaAreas.update();
        }
    }

    // 3. Gráfica Semanas
    if (window.chartInstanciaSemanas) {
        let semData = m.semanas_data;
        let semLabels = m.semanas_labels;
        if (!semData && m.conteo_por_semana) {
            semLabels = Object.keys(m.conteo_por_semana);
            semData = Object.values(m.conteo_por_semana);
        }
        if (semData) {
            if (semLabels) window.chartInstanciaSemanas.data.labels = semLabels;
            window.chartInstanciaSemanas.data.datasets[0].data = semData;
            window.chartInstanciaSemanas.update();
        }
    }
}

function eliminarCarpeta(carpetaId, nombre) {
    Swal.fire({
        title: '¿Eliminar carpeta?',
        text: `Se dejará de monitorear la carpeta '${nombre}'`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then(r => {
        if (r.isConfirmed) {
            const fd = new FormData();
            fd.append('id', carpetaId);
            fetch('api_respaldos.php?action=eliminar_carpeta', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(d => {
                    if (d.success) {
                        cargarCarpetasPorNas(nasSeleccionadoActual);
                    } else {
                        Swal.fire('Error', d.error, 'error');
                    }
                });
        }
    });
}

// 5. MODALES NAS Y CARPETAS
function abrirModalNuevoNas() {
    document.getElementById('formNas').reset();
    document.getElementById('nas_id').value = '0';
    document.getElementById('modalNasTitulo').innerText = 'Registrar Dispositivo NAS';
    new bootstrap.Modal(document.getElementById('modalNas')).show();
}

function editarNas(nasObj) {
    document.getElementById('nas_id').value = nasObj.id;
    document.getElementById('nas_nombre').value = nasObj.nombre;
    document.getElementById('nas_marca').value = nasObj.marca || 'QNAP';
    document.getElementById('nas_ip_ruta_red').value = nasObj.ip_ruta_red;
    document.getElementById('nas_usuario_smb').value = nasObj.usuario_smb || '';
    document.getElementById('nas_password_smb').value = '';
    document.getElementById('nas_notas').value = nasObj.notas || '';
    document.getElementById('modalNasTitulo').innerText = `Editar NAS: ${nasObj.nombre}`;
    new bootstrap.Modal(document.getElementById('modalNas')).show();
}

function guardarNasModal(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('formNas'));

    fetch('api_respaldos.php?action=guardar_nas', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('modalNas')).hide();
                Swal.fire({
                    icon: 'success',
                    title: '¡Guardado!',
                    text: res.mensaje,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', res.error, 'error');
            }
        });
}

function eliminarNas(nasId, nombre) {
    Swal.fire({
        title: `¿Eliminar ${nombre}?`,
        text: 'Se eliminarán también las carpetas asociadas a este dispositivo.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, eliminar NAS',
        cancelButtonText: 'Cancelar'
    }).then(r => {
        if (r.isConfirmed) {
            const fd = new FormData();
            fd.append('id', nasId);
            fetch('api_respaldos.php?action=eliminar_nas', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(d => {
                    if (d.success) {
                        Swal.fire('Eliminado', d.mensaje, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.error, 'error');
                    }
                });
        }
    });
}

function abrirModalNuevaCarpeta(nasIdDefecto) {
    document.getElementById('formCarpeta').reset();
    document.getElementById('carp_id').value = '0';
    const sel = document.getElementById('carp_nas_select');
    if (nasIdDefecto || nasSeleccionadoActual) {
        sel.value = nasIdDefecto || nasSeleccionadoActual;
    }
    document.getElementById('carp_nas_id').value = sel.value;
    new bootstrap.Modal(document.getElementById('modalCarpeta')).show();
}

function guardarCarpetaModal(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('formCarpeta'));

    fetch('api_respaldos.php?action=guardar_carpeta', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('modalCarpeta')).hide();
                cargarCarpetasPorNas(document.getElementById('carp_nas_id').value);
                Swal.fire({ icon: 'success', title: 'Carpeta agregada', timer: 1200, showConfirmButton: false });
            } else {
                Swal.fire('Error', res.error, 'error');
            }
        });
}

// 6. ASISTENTE WIZARD PASO A PASO
let wizardPollingInterval = null;

function abrirAsistenteWizard() {
    document.getElementById('formWizardNas').reset();
    if (wizardPollingInterval) clearInterval(wizardPollingInterval);
    document.getElementById('wizPaso1Formulario').style.display = 'block';
    document.getElementById('wizPaso1ConexionLocal').style.display = 'none';
    wizardNasIdCreado = 0;
    wizardCarpetasTemp = [];
    wizardIrPaso(1);
    new bootstrap.Modal(document.getElementById('modalWizard')).show();
}

function wizardIrPaso(paso) {
    document.querySelectorAll('.wizard-step').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.step-item').forEach(s => s.classList.remove('active'));

    const stepEl = document.getElementById(`wizard-step-${paso}`);
    const indEl = document.getElementById(`step-ind-${paso}`);
    if (stepEl) stepEl.classList.add('active');
    if (indEl) indEl.classList.add('active');

    for (let i = 1; i < paso; i++) {
        const p = document.getElementById(`step-ind-${i}`);
        if (p) p.classList.add('completed');
    }

    if (paso === 2) {
        if (wizardPollingInterval) clearInterval(wizardPollingInterval);
        renderizarWizardCarpetasCheckboxes();
    } else if (paso === 3) {
        renderizarWizardMapeoAreas();
    }
}

function wizardGuardarNas(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('nombre', document.getElementById('wiz_nas_nombre').value);
    fd.append('marca', document.getElementById('wiz_nas_marca').value);
    fd.append('ip_ruta_red', document.getElementById('wiz_nas_ip').value);
    fd.append('usuario_smb', document.getElementById('wiz_nas_user').value);
    fd.append('password_smb', document.getElementById('wiz_nas_pass').value);

    fetch('api_respaldos.php?action=guardar_nas', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                wizardNasIdCreado = res.id;

                // Mostrar pantalla de espera y conexión local
                document.getElementById('wizPaso1Formulario').style.display = 'none';
                document.getElementById('wizPaso1ConexionLocal').style.display = 'block';

                // Iniciar sondeo automático para detectar cuando el colector reporte
                iniciarPollingDeteccionNas(wizardNasIdCreado);
            } else {
                Swal.fire('Error', res.error, 'error');
            }
        })
        .catch(err => {
            Swal.fire('Error', err.message, 'error');
        });
}

function wizardReabrirFormularioNas() {
    if (wizardPollingInterval) clearInterval(wizardPollingInterval);
    document.getElementById('wizPaso1Formulario').style.display = 'block';
    document.getElementById('wizPaso1ConexionLocal').style.display = 'none';
}

function iniciarPollingDeteccionNas(nasId) {
    if (wizardPollingInterval) clearInterval(wizardPollingInterval);

    wizardPollingInterval = setInterval(() => {
        wizardComprobarCarpetasDetectadas(false);
    }, 2500);
}

function wizardComprobarCarpetasDetectadas(manual = false) {
    if (!wizardNasIdCreado) return;

    fetch(`api_respaldos.php?action=obtener_estado_nas&nas_id=${wizardNasIdCreado}`)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.total_carpetas > 0) {
                if (wizardPollingInterval) clearInterval(wizardPollingInterval);

                wizardCarpetasTemp = res.carpetas.map(c => ({
                    nombre_carpeta: c.nombre_carpeta,
                    area_nombre: c.area_nombre || 'General',
                    monitorear: parseInt(c.monitorear) === 1 ? 1 : 0,
                    es_sistema: parseInt(c.es_sistema) === 1 ? 1 : 0
                }));

                Swal.fire({
                    icon: 'success',
                    title: '¡Carpetas Detectadas en la NAS!',
                    text: `Se recibieron ${res.total_carpetas} carpetas compartidas desde la máquina local.`,
                    timer: 1600,
                    showConfirmButton: false
                }).then(() => {
                    wizardIrPaso(2);
                });
            } else if (manual) {
                Swal.fire({
                    icon: 'info',
                    title: 'Aún no se detectan carpetas',
                    text: 'Asegúrate de haber ejecutado el script colector en tu máquina local de la oficina.',
                    confirmButtonText: 'Entendido'
                });
            }
        });
}

function abrirDeteccionNasExistente(nasId, nombreNas) {
    wizardNasIdCreado = nasId;
    abrirAsistenteWizard();
    document.getElementById('wizPaso1Formulario').style.display = 'none';
    document.getElementById('wizPaso1ConexionLocal').style.display = 'block';
    iniciarPollingDeteccionNas(nasId);
}

function renderizarWizardCarpetasCheckboxes() {
    const cont = document.getElementById('wizListaCarpetasCheckboxes');
    cont.innerHTML = '';

    if (!wizardCarpetasTemp || wizardCarpetasTemp.length === 0) {
        wizardCarpetasTemp = [
            { nombre_carpeta: 'Contabilidad', area_nombre: 'Contabilidad', monitorear: 1, es_sistema: 0 },
            { nombre_carpeta: 'Ventas', area_nombre: 'Ventas', monitorear: 1, es_sistema: 0 },
            { nombre_carpeta: 'Sistemas', area_nombre: 'Sistemas', monitorear: 1, es_sistema: 0 },
            { nombre_carpeta: 'Administracion', area_nombre: 'Administración', monitorear: 1, es_sistema: 0 },
            { nombre_carpeta: 'Servicio', area_nombre: 'Servicio', monitorear: 1, es_sistema: 0 },
            { nombre_carpeta: 'Refacciones', area_nombre: 'Refacciones', monitorear: 1, es_sistema: 0 },
            { nombre_carpeta: '@Recycle', area_nombre: 'General', monitorear: 0, es_sistema: 1 },
            { nombre_carpeta: '#snapshot', area_nombre: 'General', monitorear: 0, es_sistema: 1 }
        ];
    }

    wizardCarpetasTemp.forEach((c, idx) => {
        const esBasura = c.nombre_carpeta.startsWith('@') || c.nombre_carpeta.startsWith('#') || c.es_sistema;
        const check = (c.monitorear && !esBasura) ? 'checked' : '';
        const badgeBasura = esBasura ? '<span class="badge bg-danger bg-opacity-25 text-danger border border-danger ms-2" style="font-size:0.65rem;">Descarte Automático</span>' : '<span class="badge bg-success bg-opacity-25 text-success border border-success ms-2" style="font-size:0.65rem;">Detectada en NAS</span>';

        const div = document.createElement('div');
        div.className = 'form-check mb-2 d-flex justify-content-between align-items-center p-2 rounded-2';
        div.style.background = esBasura ? 'rgba(239, 68, 68, 0.05)' : 'rgba(255, 255, 255, 0.02)';
        div.innerHTML = `
            <div>
                <input class="form-check-input" type="checkbox" id="wiz_chk_${idx}" ${check} onchange="wizardCarpetasTemp[${idx}].monitorear = this.checked ? 1 : 0">
                <label class="form-check-label text-white small" for="wiz_chk_${idx}">
                    <i class="bi bi-folder2 text-warning me-1"></i> ${c.nombre_carpeta} ${badgeBasura}
                </label>
            </div>
            <button type="button" class="btn btn-link text-danger p-0" onclick="wizardEliminarCarpetaTemp(${idx})" title="Quitar">
                <i class="bi bi-x-circle"></i>
            </button>
        `;
        cont.appendChild(div);
    });
}

function wizardAgregarCarpetaManual() {
    const inp = document.getElementById('wizNuevaCarpetaNombre');
    const nom = inp.value.trim();
    if (!nom) return;

    wizardCarpetasTemp.push({
        nombre_carpeta: nom,
        area_nombre: nom,
        monitorear: 1
    });
    inp.value = '';
    renderizarWizardCarpetasCheckboxes();
}

function wizardEliminarCarpetaTemp(idx) {
    wizardCarpetasTemp.splice(idx, 1);
    renderizarWizardCarpetasCheckboxes();
}

function renderizarWizardMapeoAreas() {
    const cont = document.getElementById('wizMapeoAreasLista');
    cont.innerHTML = '';

    const activas = wizardCarpetasTemp.filter(c => c.monitorear === 1);
    if (activas.length === 0) {
        cont.innerHTML = '<div class="text-warning small text-center py-3">No marcaste ninguna carpeta para monitorear en el paso anterior.</div>';
        return;
    }

    const areasOpciones = ['Contabilidad', 'Ventas', 'Sistemas', 'Administración', 'Servicio', 'Refacciones', 'Gerencia', 'Caja', 'Auditoría', 'General'];

    activas.forEach((c, idx) => {
        let opts = '';
        areasOpciones.forEach(a => {
            const sel = (c.area_nombre.toLowerCase() === a.toLowerCase()) ? 'selected' : '';
            opts += `<option value="${a}" ${sel}>${a}</option>`;
        });

        const row = document.createElement('div');
        row.className = 'row g-2 align-items-center mb-2 p-2 rounded-2 border border-secondary border-opacity-10';
        row.style.background = 'rgba(255,255,255,0.02)';
        row.innerHTML = `
            <div class="col-12 col-md-5">
                <span class="text-white small fw-bold"><i class="bi bi-folder2 text-warning me-1"></i> ${c.nombre_carpeta}</span>
            </div>
            <div class="col-12 col-md-7">
                <select class="form-select form-select-sm bg-dark text-white border-secondary border-opacity-25" onchange="actualizarAreaTemp('${c.nombre_carpeta}', this.value)">
                    ${opts}
                </select>
            </div>
        `;
        cont.appendChild(row);
    });
}

function actualizarAreaTemp(nombreCarpeta, nuevaArea) {
    const item = wizardCarpetasTemp.find(c => c.nombre_carpeta === nombreCarpeta);
    if (item) {
        item.area_nombre = nuevaArea;
    }
}

function wizardFinalizarConfiguracion() {
    if (!wizardNasIdCreado && nasSeleccionadoActual) {
        wizardNasIdCreado = nasSeleccionadoActual;
    }

    const payload = {
        nas_id: wizardNasIdCreado,
        carpetas: wizardCarpetasTemp
    };

    fetch('api_respaldos.php?action=guardar_carpetas_lote', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalWizard')).hide();
            Swal.fire({
                icon: 'success',
                title: '¡Configuración completada!',
                text: 'Las carpetas y áreas han quedado mapeadas exitosamente.',
                timer: 1800,
                showConfirmButton: false
            }).then(() => location.reload());
        } else {
            Swal.fire('Error', res.error, 'error');
        }
    });
}

// 7. CONFIGURACIÓN Y TOKEN BEARER
function guardarConfiguracion(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('formConfiguracion'));

    fetch('api_respaldos.php?action=guardar_config', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                Swal.fire('Guardado', res.mensaje, 'success');
            } else {
                Swal.fire('Error', res.error, 'error');
            }
        });
}

function regenerarTokenApi() {
    Swal.fire({
        title: '¿Regenerar Token Bearer?',
        text: 'Los scripts colectores LAN que usen el token anterior dejarán de reportar hasta que se actualice su archivo de configuración.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        confirmButtonText: 'Sí, regenerar token',
        cancelButtonText: 'Cancelar'
    }).then(r => {
        if (r.isConfirmed) {
            fetch('api_respaldos.php?action=regenerar_token', { method: 'POST' })
                .then(res => res.json())
                .then(d => {
                    if (d.success) {
                        document.getElementById('displayTokenApi').innerText = d.token;
                        Swal.fire('Token Regenerado', 'Recuerda actualizar tu colector_config.json en el servidor local.', 'success');
                    } else {
                        Swal.fire('Error', d.error, 'error');
                    }
                });
        }
    });
}

function copiarAlPortapapeles(idElemento) {
    const el = document.getElementById(idElemento);
    if (!el) return;
    navigator.clipboard.writeText(el.innerText).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'Copiado al portapapeles',
            toast: true,
            position: 'top-end',
            timer: 1500,
            showConfirmButton: false
        });
    });
}

// =============================================================================
// GESTIÓN DE POLÍTICAS Y PROGRAMACIÓN DE RESPALDOS POR ÁREA
// =============================================================================
let politicasAreasCache = [];

function cargarPoliticasAreas() {
    const tbody = document.getElementById('tbodyPoliticasAreas');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-secondary"><div class="spinner-border spinner-border-sm text-primary me-2"></div> Cargando áreas y programación SLA...</td></tr>';

    fetch('api_respaldos.php?action=listar_areas_politicas')
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="7" class="text-danger py-4 text-center">${res.error || 'Error al cargar áreas'}</td></tr>`;
                return;
            }

            politicasAreasCache = res.areas || [];
            actualizarResumenKpisAreas(politicasAreasCache);
            renderizarTablaPoliticasAreas(politicasAreasCache);
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="7" class="text-danger py-4 text-center">Error de conexión: ${err.message}</td></tr>`;
        });
}

function actualizarResumenKpisAreas(areas) {
    const elTot = document.getElementById('kpiAreasTotal');
    const elAct = document.getElementById('kpiAreasActivas');
    const elExe = document.getElementById('kpiAreasExentas');
    const elEq = document.getElementById('kpiAreasEquipos');

    let total = areas.length;
    let activas = 0;
    let exentas = 0;
    let totalEq = 0;

    areas.forEach(a => {
        if (parseInt(a.aplica_esta_semana) === 1) activas++;
        else exentas++;
        totalEq += parseInt(a.total_equipos || 0);
    });

    if (elTot) elTot.innerText = total;
    if (elAct) elAct.innerText = activas;
    if (elExe) elExe.innerText = exentas;
    if (elEq) elEq.innerText = totalEq;
}

function renderizarTablaPoliticasAreas(areas) {
    const tbody = document.getElementById('tbodyPoliticasAreas');
    if (!tbody) return;

    if (!areas || areas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-secondary">No se detectaron áreas configuradas aún.</td></tr>';
        return;
    }

    let html = '';
    const todosDias = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    const todasSemanas = ['S1', 'S2', 'S3', 'S4', 'S5'];

    areas.forEach(a => {
        const id = a.id;
        const nombre = a.area_nombre || 'General';
        const aplica = parseInt(a.aplica_esta_semana) === 1;
        const diasStr = a.dias_programados || 'Vie';
        const diasArr = diasStr.split(',').map(d => d.trim());
        const frec = a.frecuencia || 'semanal';
        const motivo = a.motivo_exencion || '';
        const eqTotal = parseInt(a.total_equipos || 0);
        const eqAlDia = parseInt(a.equipos_al_dia || 0);
        const eqAlerta = parseInt(a.equipos_alerta || 0);
        const eqCrit = parseInt(a.equipos_criticos || 0);

        // Semanas del mes que aplican para esta área (S1 a S5)
        const semanasMesAplicaStr = a.semanas_mes_aplica || 'S1,S2,S3,S4,S5';
        const semActivasArr = semanasMesAplicaStr.split(',').map(s => s.trim().toUpperCase());

        // Generar badges visuales para los días de la semana
        let diasBadgesHtml = '<div class="d-flex flex-wrap gap-1">';
        todosDias.forEach(d => {
            const activo = diasArr.includes(d);
            if (activo) {
                diasBadgesHtml += `<span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-50 py-1 px-2 fw-bold" style="font-size: 0.72rem;">${d}</span>`;
            } else {
                diasBadgesHtml += `<span class="badge text-secondary border border-secondary border-opacity-10 py-1 px-2" style="font-size: 0.72rem; opacity: 0.4;">${d}</span>`;
            }
        });
        diasBadgesHtml += '</div>';

        // Generar botones interactivos para Semanas del Mes (S1 a S5)
        let semanasMesBadgesHtml = '<div class="d-flex flex-wrap gap-1">';
        todasSemanas.forEach(sCod => {
            const isActiva = semActivasArr.includes(sCod);
            if (isActiva) {
                semanasMesBadgesHtml += `
                    <button type="button" class="btn btn-sm py-0 px-2 rounded-2 fw-bold shadow-sm" 
                            style="font-size: 0.72rem; background: rgba(56, 189, 248, 0.22); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.55);" 
                            onclick="toggleSemanaMesArea(${id}, '${sCod}')" 
                            title="${sCod} Aplica respaldo (Clic para pausar / poner en gris esta semana)">
                        ${sCod} <i class="bi bi-check2"></i>
                    </button>`;
            } else {
                semanasMesBadgesHtml += `
                    <button type="button" class="btn btn-sm py-0 px-2 rounded-2" 
                            style="font-size: 0.72rem; background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px dashed rgba(148, 163, 184, 0.45);" 
                            onclick="toggleSemanaMesArea(${id}, '${sCod}')" 
                            title="${sCod} No Aplica / Exento (Gris) - Clic para activar">
                        ${sCod} <i class="bi bi-x"></i>
                    </button>`;
            }
        });
        semanasMesBadgesHtml += '</div>';

        // Badge de equipos en área
        const badgeEquipos = `<span class="fw-bold text-white">${eqTotal}</span> <span class="text-secondary small">(${eqAlDia} <i class="bi bi-check-circle text-success"></i>, ${eqAlerta + eqCrit} <i class="bi bi-exclamation-circle text-danger"></i>)</span>`;

        // Switch de "Aplica esta semana"
        const switchId = `switchAplicaArea_${id}`;
        const switchHtml = `
            <div class="form-check form-switch d-inline-flex align-items-center gap-2 justify-content-center">
                <input class="form-check-input" type="checkbox" role="switch" id="${switchId}" ${aplica ? 'checked' : ''} onchange="toggleAplicaAreaSemana(${id}, this.checked)">
                <label class="form-check-label small ${aplica ? 'text-success fw-bold' : 'text-secondary'}" for="${switchId}" id="lblSwitch_${id}">
                    ${aplica ? '<i class="bi bi-check-circle me-1"></i>Aplica' : '<i class="bi bi-pause-circle me-1"></i>Exento'}
                </label>
            </div>
        `;

        const jsonArea = JSON.stringify(a).replace(/'/g, '&apos;').replace(/"/g, '&quot;');

        html += `
            <tr id="filaAreaPol_${id}" style="${!aplica ? 'opacity: 0.75; background: rgba(148, 163, 184, 0.03);' : ''}">
                <td class="align-middle">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary border-opacity-50 p-2 rounded-3">
                            <i class="bi bi-folder2"></i>
                        </span>
                        <div>
                            <div class="fw-bold text-white">${nombre}</div>
                            <div class="text-secondary" style="font-size: 0.72rem;">${a.notas ? a.notas : 'Área corporativa'}</div>
                        </div>
                    </div>
                </td>
                <td class="align-middle">${badgeEquipos}</td>
                <td class="align-middle">${diasBadgesHtml}</td>
                <td class="align-middle">${semanasMesBadgesHtml}</td>
                <td class="align-middle">
                    <span class="badge bg-dark border border-secondary border-opacity-25 text-capitalize text-light">${frec}</span>
                </td>
                <td class="align-middle text-center">${switchHtml}</td>
                <td class="align-middle">
                    ${motivo ? `<span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25 text-truncate d-inline-block" style="max-width: 200px;" title="${motivo}"><i class="bi bi-info-circle me-1"></i>${motivo}</span>` : '<span class="text-secondary small fst-italic">--</span>'}
                </td>
                <td class="align-middle text-end">
                    <button class="btn btn-outline-primary btn-sm rounded-3 px-2 py-1" onclick="abrirModalEditarAreaPolitica(${jsonArea})" title="Modificar días programados y regla de SLA">
                        <i class="bi bi-pencil-square me-1"></i> Programar
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

function toggleSemanaMesArea(areaId, semana) {
    const formData = new FormData();
    formData.append('id', areaId);
    formData.append('semana', semana);

    fetch('api_respaldos.php?action=toggle_semana_mes_area', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            Swal.fire('Error', res.error || 'No se pudo actualizar la semana', 'error');
            return;
        }

        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-dark border border-info border-opacity-50 position-fixed bottom-0 end-0 m-3';
        toastEl.style.zIndex = '99999';
        toastEl.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-calendar-check text-info me-2"></i>${res.mensaje}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        document.body.appendChild(toastEl);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 2500 });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());

        if (res.metricas) {
            actualizarVistasConMetricas(res.metricas);
        }

        // Actualizar caché y re-renderizar tabla de áreas
        if (politicasAreasCache && politicasAreasCache.length) {
            const idx = politicasAreasCache.findIndex(p => parseInt(p.id) === parseInt(areaId));
            if (idx !== -1) {
                politicasAreasCache[idx].semanas_mes_aplica = res.semanas_mes_aplica;
                renderizarTablaPoliticasAreas(politicasAreasCache);
            }
        }
    })
    .catch(err => {
        console.error('Error al alternar semana del área:', err);
    });
}

function toggleAplicaAreaSemana(areaId, nuevoEstado) {
    let motivo = '';
    const label = document.getElementById(`lblSwitch_${areaId}`);
    if (label) {
        label.innerHTML = nuevoEstado ? '<i class="bi bi-check-circle me-1"></i>Aplica' : '<i class="bi bi-pause-circle me-1"></i>Exento';
        label.className = `form-check-label small ${nuevoEstado ? 'text-success fw-bold' : 'text-secondary'}`;
    }

    const fila = document.getElementById(`filaAreaPol_${areaId}`);
    if (fila) {
        fila.style.opacity = nuevoEstado ? '1' : '0.75';
    }

    const formData = new FormData();
    formData.append('id', areaId);
    formData.append('aplica', nuevoEstado ? 1 : 0);
    formData.append('motivo', motivo);

    fetch('api_respaldos.php?action=toggle_aplica_area_semana', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            Swal.fire('Error', res.error || 'No se pudo actualizar el estado', 'error');
            cargarPoliticasAreas();
            return;
        }

        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-dark border border-primary border-opacity-50 position-fixed bottom-0 end-0 m-3';
        toastEl.style.zIndex = '99999';
        toastEl.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-check-circle text-success me-2"></i>${res.mensaje}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        document.body.appendChild(toastEl);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 2500 });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());

        if (res.metricas) {
            actualizarVistasConMetricas(res.metricas);
        }

        // Actualizar mini KPIs de la pestaña
        if (politicasAreasCache && politicasAreasCache.length) {
            const idx = politicasAreasCache.findIndex(p => parseInt(p.id) === parseInt(areaId));
            if (idx !== -1) {
                politicasAreasCache[idx].aplica_esta_semana = nuevoEstado ? 1 : 0;
                actualizarResumenKpisAreas(politicasAreasCache);
            }
        }
    })
    .catch(err => {
        console.error('Error al conmutar área:', err);
    });
}

function abrirModalEditarAreaPolitica(area) {
    if (!area) return;
    document.getElementById('areaPol_id').value = area.id || '';
    document.getElementById('areaPol_nombre').value = area.area_nombre || '';
    document.getElementById('displayAreaPolNombre').innerText = area.area_nombre || 'General';
    document.getElementById('tituloModalAreaPolitica').innerText = `Programar: ${area.area_nombre}`;

    // Desmarcar todos los checks de días
    document.querySelectorAll('.check-dia-prog').forEach(ch => { ch.checked = false; });
    const dias = (area.dias_programados || 'Vie').split(',').map(d => d.trim());
    dias.forEach(d => {
        const ch = document.getElementById(`checkDia_${d}`);
        if (ch) ch.checked = true;
    });

    // Marcar/desmarcar semanas del mes que aplican
    document.querySelectorAll('.check-semana-mes-prog').forEach(ch => { ch.checked = false; });
    const semMes = (area.semanas_mes_aplica || 'S1,S2,S3,S4,S5').split(',').map(s => s.trim().toUpperCase());
    semMes.forEach(s => {
        const ch = document.getElementById(`checkSemanaMes_${s}`);
        if (ch) ch.checked = true;
    });

    document.getElementById('areaPol_frecuencia').value = area.frecuencia || 'semanal';
    const aplicaSw = document.getElementById('areaPol_aplica_semana');
    if (aplicaSw) {
        aplicaSw.checked = (parseInt(area.aplica_esta_semana) === 1);
    }
    document.getElementById('areaPol_motivo').value = area.motivo_exencion || '';
    document.getElementById('areaPol_notas').value = area.notas || '';

    toggleMotivoExencionInput();

    const m = new bootstrap.Modal(document.getElementById('modalEditarAreaPolitica'));
    m.show();
}

function toggleMotivoExencionInput() {
    const sw = document.getElementById('areaPol_aplica_semana');
    const grp = document.getElementById('grupoMotivoExencion');
    if (sw && grp) {
        grp.style.display = sw.checked ? 'none' : 'block';
    }
}

function guardarAreaPolitica(e) {
    e.preventDefault();
    const id = document.getElementById('areaPol_id').value;
    const nombre = document.getElementById('areaPol_nombre').value;
    const frecuencia = document.getElementById('areaPol_frecuencia').value;
    const aplica = document.getElementById('areaPol_aplica_semana').checked ? 1 : 0;
    const motivo = document.getElementById('areaPol_motivo').value;
    const notas = document.getElementById('areaPol_notas').value;

    const diasSeleccionados = [];
    document.querySelectorAll('.check-dia-prog:checked').forEach(ch => {
        diasSeleccionados.push(ch.value);
    });

    if (diasSeleccionados.length === 0) {
        Swal.fire('Atención', 'Selecciona al menos un día de la semana para el respaldo de esta área.', 'warning');
        return;
    }

    const semanasMesSeleccionadas = [];
    document.querySelectorAll('.check-semana-mes-prog:checked').forEach(ch => {
        semanasMesSeleccionadas.push(ch.value);
    });

    const formData = new FormData();
    formData.append('id', id);
    formData.append('area_nombre', nombre);
    formData.append('dias_programados', diasSeleccionados.join(','));
    formData.append('semanas_mes_aplica', semanasMesSeleccionadas.join(','));
    formData.append('frecuencia', frecuencia);
    formData.append('aplica_esta_semana', aplica);
    formData.append('motivo_exencion', motivo);
    formData.append('notas', notas);

    fetch('api_respaldos.php?action=guardar_area_politica', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            Swal.fire('Error', res.error || 'No se pudo guardar la programación', 'error');
            return;
        }

        const modalEl = document.getElementById('modalEditarAreaPolitica');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        Swal.fire({
            title: '¡Programación Guardada!',
            text: res.mensaje || 'Política y días actualizados correctamente',
            icon: 'success',
            timer: 1800,
            showConfirmButton: false
        });

        cargarPoliticasAreas();
        if (res.metricas) {
            actualizarVistasConMetricas(res.metricas);
        }
    })
    .catch(err => {
        Swal.fire('Error', err.message, 'error');
    });
}

// Inicializar carpetas del primer NAS si existe
<?php if (!empty($nasCatalogo[0]['id'])): ?>
document.addEventListener('DOMContentLoaded', () => {
    seleccionarNasCarpetas(<?php echo (int)$nasCatalogo[0]['id']; ?>, '<?php echo addslashes($nasCatalogo[0]['nombre']); ?>');
});
<?php endif; ?>

// =============================================================================
// EXPORTADOR AVANZADO A MICROSOFT EXCEL (.XLSX) MULTI-HOJA CON GRÁFICAS Y MATRIZ
// =============================================================================
async function exportarAuditoriaExcelAvanzado() {
    if (typeof ExcelJS === 'undefined') {
        Swal.fire({
            title: 'Librería Excel no lista',
            text: 'Descargando motor de hojas de cálculo de alta precisión, espere un momento...',
            icon: 'info',
            timer: 2000,
            showConfirmButton: false
        });
        return;
    }

    // 1. Obtener filtros activos en la pantalla
    const mesSel = document.getElementById('selectMesMatriz')?.value || '<?php echo date('Y-m'); ?>';
    const areaSel = document.getElementById('filtroArea')?.value || '';
    const nasSel = document.getElementById('filtroNas')?.value || '';
    const estadoSel = document.getElementById('filtroEstado')?.value || '';
    const busqueda = (document.getElementById('buscadorEquipos')?.value || document.getElementById('filtroMatrizRapido')?.value || '').toLowerCase().trim();

    // 2. Mostrar indicador de progreso
    Swal.fire({
        title: 'Generando Reporte de Auditoría...',
        html: `
            <div class="py-2 text-start small">
                <div class="mb-1"><i class="bi bi-gear-wide-connected text-primary me-2"></i> Procesando datos del mes <strong>${mesSel}</strong>...</div>
                <div class="mb-1"><i class="bi bi-funnel-fill text-warning me-2"></i> Aplicando filtros de Área, NAS y Semáforo...</div>
                <div class="mb-1"><i class="bi bi-bar-chart-fill text-info me-2"></i> Renderizando gráficas ejecutivas en alta resolución...</div>
                <div><i class="bi bi-file-earmark-spreadsheet-fill text-success me-2"></i> Estructurando Libro de 2 Hojas (Resumen + Matriz Semanal)...</div>
            </div>
        `,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    try {
        // 3. Consultar la matriz semanal completa para el mes seleccionado
        let url = 'api_respaldos.php?action=obtener_matriz_semanal_mes&mes=' + encodeURIComponent(mesSel);
        const resp = await fetch(url);
        const res = await resp.json();

        if (!res.success) {
            throw new Error(res.error || 'No se pudieron recuperar los datos de la matriz mensual');
        }

        // 4. Filtrar equipos según los filtros activos de la interfaz
        let equiposFiltrados = res.matriz || [];
        if (areaSel) {
            equiposFiltrados = equiposFiltrados.filter(e => e.area_nombre === areaSel);
        }
        if (nasSel) {
            equiposFiltrados = equiposFiltrados.filter(e => {
                const nasNom = e.nas_nombre || '';
                const nasMrc = e.nas_marca || '';
                return nasNom === nasSel || nasSel.includes(nasNom) || (nasMrc && nasSel.includes(nasMrc));
            });
        }
        if (estadoSel) {
            equiposFiltrados = equiposFiltrados.filter(e => e.estado_actual === estadoSel);
        }
        if (busqueda) {
            equiposFiltrados = equiposFiltrados.filter(e => {
                const str = ((e.nombre_equipo || '') + ' ' + (e.usuario_responsable || '') + ' ' + (e.area_nombre || '') + ' ' + (e.archivo_reciente || '')).toLowerCase();
                return str.includes(busqueda);
            });
        }

        if (equiposFiltrados.length === 0) {
            Swal.fire({
                title: 'Sin Registros',
                text: 'No hay equipos que coincidan con los filtros seleccionados actualmente. Ajusta los filtros para exportar.',
                icon: 'warning'
            });
            return;
        }

        // 5. Calcular KPIs sobre los equipos filtrados
        const totalEquipos = equiposFiltrados.length;
        const exitosos = equiposFiltrados.filter(e => e.estado_actual === 'exitoso').length;
        const vencidos = equiposFiltrados.filter(e => e.estado_actual === 'vencido').length;
        const criticos = equiposFiltrados.filter(e => e.estado_actual === 'critico').length;
        const incompletos = equiposFiltrados.filter(e => e.tiene_temporales || e.estado_actual === 'incompleto').length;
        const exentos = equiposFiltrados.filter(e => e.estado_actual === 'exento').length;
        const aplicables = Math.max(0, totalEquipos - exentos);
        const cumplimientoSLA = aplicables > 0 
            ? ((exitosos / aplicables) * 100).toFixed(1) 
            : (totalEquipos > 0 && exentos === totalEquipos ? '100.0' : '0.0');

        // 6. Agrupar equipos por Área
        const areasMap = {};
        equiposFiltrados.forEach(eq => {
            const ar = (eq.area_nombre || 'General').trim();
            if (!areasMap[ar]) areasMap[ar] = [];
            areasMap[ar].push(eq);
        });

        // 7. Extraer imágenes de las gráficas de Chart.js
        let imgDona = null;
        let imgAreas = null;
        try {
            if (window.chartInstanciaDona && typeof window.chartInstanciaDona.toBase64Image === 'function') {
                imgDona = window.chartInstanciaDona.toBase64Image();
            }
            if (window.chartInstanciaAreas && typeof window.chartInstanciaAreas.toBase64Image === 'function') {
                imgAreas = window.chartInstanciaAreas.toBase64Image();
            }
        } catch (e) {
            console.warn('No se pudieron extraer imágenes de Chart.js:', e);
        }

        // 8. Datos de Agencia
        const agencia = window.DATOS_AGENCIA_EXCEL || {
            nombre: 'GRUPO HUERTA',
            razon_social: 'Distribuidora de Vehículos de Oriente SAPI de CV',
            rfc: 'XAXX010101000',
            direccion: 'Av. Ferrocarril Hidalgo 883, CDMX',
            encargado_sistemas: 'Departamento de Sistemas'
        };

        const now = new Date();
        const fechaHoy = now.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
        const horaHoy = now.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });

        // Helper para formatear bytes a texto legible
        const formatBytes = (bytes) => {
            if (!bytes || isNaN(bytes) || bytes <= 0) return '0.00 MB';
            const unids = ['B', 'KB', 'MB', 'GB', 'TB'];
            const idx = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, idx)).toFixed(2) + ' ' + (unids[idx] || 'MB');
        };

        // =====================================================================
        // CREACIÓN DEL LIBRO DE TRABAJO (WORKBOOK)
        // =====================================================================
        const wb = new ExcelJS.Workbook();
        wb.creator = 'Portal de Sistemas - ' + (agencia.nombre || 'Grupo Huerta');
        wb.lastModifiedBy = agencia.encargado_sistemas || 'Sistemas';
        wb.created = now;
        wb.modified = now;

        // Estilos reutilizables
        const borderThin = {
            top: { style: 'thin', color: { argb: 'FFCBD5E1' } },
            left: { style: 'thin', color: { argb: 'FFCBD5E1' } },
            bottom: { style: 'thin', color: { argb: 'FFCBD5E1' } },
            right: { style: 'thin', color: { argb: 'FFCBD5E1' } }
        };

        const fontHeader = { name: 'Segoe UI', size: 10, bold: true, color: { argb: 'FFFFFFFF' } };
        const fillHeaderDark = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F172A' } };
        const fillHeaderBlue = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1E293B' } };

        // =====================================================================
        // HOJA 1: RESUMEN EJECUTIVO Y EQUIPOS
        // =====================================================================
        const ws1 = wb.addWorksheet('Resumen y Auditoría', {
            views: [{ showGridLines: true }]
        });

        ws1.columns = [
            { key: 'margen', width: 3 },
            { key: 'equipo', width: 22 },
            { key: 'usuario', width: 25 },
            { key: 'area', width: 22 },
            { key: 'nas', width: 18 },
            { key: 'ultimo', width: 20 },
            { key: 'dias', width: 16 },
            { key: 'tamano', width: 16 },
            { key: 'estado', width: 22 },
            { key: 'archivo', width: 34 },
            { key: 'tmp', width: 16 }
        ];

        // 1. Membrete oficial
        ws1.mergeCells('B2:K2');
        const cTitle1 = ws1.getCell('B2');
        cTitle1.value = (agencia.nombre || 'SISTEMAS GRUPO HUERTA').toUpperCase();
        cTitle1.font = { name: 'Segoe UI', size: 16, bold: true, color: { argb: 'FF0F172A' } };
        cTitle1.alignment = { vertical: 'middle' };

        ws1.mergeCells('B3:K3');
        const cSub1 = ws1.getCell('B3');
        cSub1.value = 'AUDITORÍA DE RESPALDOS Y MONITOREO DE CONTINUIDAD (NAS)';
        cSub1.font = { name: 'Segoe UI', size: 12, bold: true, color: { argb: 'FF0284C7' } };
        cSub1.alignment = { vertical: 'middle' };

        ws1.mergeCells('B4:K4');
        const cMeta1 = ws1.getCell('B4');
        cMeta1.value = `Razón Social: ${agencia.razon_social || 'N/A'}  |  RFC: ${agencia.rfc || 'N/A'}  |  Dirección: ${agencia.direccion || 'N/A'}`;
        cMeta1.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF475569' } };
        cMeta1.alignment = { vertical: 'middle' };

        ws1.mergeCells('B5:K5');
        const cMeta2 = ws1.getCell('B5');
        const filtroAreaTxt = areaSel ? `Área [${areaSel}]` : 'Áreas [Todas]';
        const filtroNasTxt = nasSel ? `NAS [${nasSel}]` : 'NAS [Todas]';
        const filtroEstadoTxt = estadoSel ? `Estado [${estadoSel}]` : 'Estados [Todos]';
        const filtroBusqTxt = busqueda ? `Búsqueda ["${busqueda}"]` : '';
        const filtrosAplicadosStr = [filtroAreaTxt, filtroNasTxt, filtroEstadoTxt, filtroBusqTxt].filter(Boolean).join(' | ');
        cMeta2.value = `Fecha de Emisión: ${fechaHoy} ${horaHoy} hrs  |  Control: ${agencia.encargado_sistemas || 'Sistemas'}  |  Filtros Activos: ${filtrosAplicadosStr}`;
        cMeta2.font = { name: 'Segoe UI', size: 9, bold: true, color: { argb: 'FF0369A1' } };
        cMeta2.alignment = { vertical: 'middle' };

        // 2. Tarjetas de Indicadores Clave (KPIs)
        const kpis = [
            { col: 'B', label: 'CUMPLIMIENTO SLA', val: `${cumplimientoSLA}%`, bg: 'FF0F172A', fg: 'FF10B981' },
            { col: 'C', label: 'TOTAL EQUIPOS', val: totalEquipos, bg: 'FF1E293B', fg: 'FFFFFFFF' },
            { col: 'D', label: 'AL DÍA (≤6D)', val: exitosos, bg: 'FF064E3B', fg: 'FF34D399' },
            { col: 'E', label: 'EN ALERTA (7-12D)', val: vencidos, bg: 'FF78350F', fg: 'FFFBBF24' },
            { col: 'F', label: 'CRÍTICOS (≥13D)', val: criticos, bg: 'FF7F1D1D', fg: 'FFF87171' },
            { col: 'G', label: 'CON TEMPORALES', val: incompletos, bg: 'FF701A75', fg: 'FFF472B6' },
            { col: 'H', label: 'EXENTOS', val: exentos, bg: 'FF334155', fg: 'FFE2E8F0' }
        ];

        ws1.getRow(7).height = 18;
        ws1.getRow(8).height = 32;

        kpis.forEach(k => {
            const cellLbl = ws1.getCell(`${k.col}7`);
            cellLbl.value = k.label;
            cellLbl.font = { name: 'Segoe UI', size: 8.5, bold: true, color: { argb: 'FFFFFFFF' } };
            cellLbl.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: k.bg } };
            cellLbl.alignment = { horizontal: 'center', vertical: 'middle' };
            cellLbl.border = borderThin;

            const cellVal = ws1.getCell(`${k.col}8`);
            cellVal.value = k.val;
            cellVal.font = { name: 'Segoe UI', size: 16, bold: true, color: { argb: k.fg } };
            cellVal.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: k.bg } };
            cellVal.alignment = { horizontal: 'center', vertical: 'middle' };
            cellVal.border = borderThin;
        });

        // 3. Incrustar Gráficas de Chart.js si están disponibles
        let filaInicioTabla = 10;
        if (imgDona || imgAreas) {
            // Título de la sección de gráficas
            ws1.mergeCells('B10:K10');
            const cGTitle = ws1.getCell('B10');
            cGTitle.value = 'VISUALIZACIÓN GRÁFICA DE SEMÁFORO Y DISTRIBUCIÓN POR ÁREA';
            cGTitle.font = { name: 'Segoe UI', size: 10, bold: true, color: { argb: 'FF0284C7' } };
            cGTitle.alignment = { vertical: 'middle' };

            if (imgDona) {
                const donaId = wb.addImage({
                    base64: imgDona,
                    extension: 'png'
                });
                ws1.addImage(donaId, {
                    tl: { col: 1, row: 11 },
                    ext: { width: 360, height: 185 }
                });
            }

            if (imgAreas) {
                const areasId = wb.addImage({
                    base64: imgAreas,
                    extension: 'png'
                });
                ws1.addImage(areasId, {
                    tl: { col: 5, row: 11 },
                    ext: { width: 440, height: 185 }
                });
            }

            filaInicioTabla = 23;
        }

        // 4. Encabezado de la Tabla de Equipos
        ws1.mergeCells(`B${filaInicioTabla}:K${filaInicioTabla}`);
        const cTblTitle = ws1.getCell(`B${filaInicioTabla}`);
        cTblTitle.value = `DETALLE DE EQUIPOS AUDITADOS (${totalEquipos} máquinas)`;
        cTblTitle.font = { name: 'Segoe UI', size: 11, bold: true, color: { argb: 'FF0F172A' } };
        cTblTitle.alignment = { vertical: 'middle' };

        const headerRowIdx = filaInicioTabla + 1;
        const hRow = ws1.getRow(headerRowIdx);
        hRow.height = 26;

        const headersTbl1 = [
            { col: 'B', text: 'NOMBRE DEL EQUIPO' },
            { col: 'C', text: 'USUARIO RESPONSABLE' },
            { col: 'D', text: 'ÁREA / DEPARTAMENTO' },
            { col: 'E', text: 'NAS DESTINO' },
            { col: 'F', text: 'ÚLTIMO RESPALDO' },
            { col: 'G', text: 'DÍAS SIN COPIA' },
            { col: 'H', text: 'TAMAÑO' },
            { col: 'I', text: 'ESTADO SEMÁFORO' },
            { col: 'J', text: 'ARCHIVO MÁS RECIENTE' },
            { col: 'K', text: 'TEMPORALES' }
        ];

        headersTbl1.forEach(h => {
            const cell = ws1.getCell(`${h.col}${headerRowIdx}`);
            cell.value = h.text;
            cell.font = fontHeader;
            cell.fill = fillHeaderDark;
            cell.alignment = { horizontal: 'center', vertical: 'middle' };
            cell.border = borderThin;
        });

        // 5. Filas de Equipos
        let currRowIdx = headerRowIdx + 1;
        equiposFiltrados.forEach((eq, idx) => {
            const r = ws1.getRow(currRowIdx);
            r.height = 20;
            const bgZebra = (idx % 2 === 0) ? 'FFFFFFFF' : 'FFF8FAFC';

            // Celda B: Equipo
            const cEq = ws1.getCell(`B${currRowIdx}`);
            cEq.value = eq.nombre_equipo;
            cEq.font = { name: 'Segoe UI', size: 9.5, bold: true, color: { argb: 'FF0F172A' } };
            cEq.alignment = { vertical: 'middle' };

            // Celda C: Usuario
            const cUsr = ws1.getCell(`C${currRowIdx}`);
            cUsr.value = eq.usuario_responsable || 'Sin asignar';
            cUsr.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF334155' } };
            cUsr.alignment = { vertical: 'middle' };

            // Celda D: Área
            const cArea = ws1.getCell(`D${currRowIdx}`);
            cArea.value = eq.area_nombre || 'General';
            cArea.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF0369A1' } };
            cArea.alignment = { vertical: 'middle' };

            // Celda E: NAS
            const cNas = ws1.getCell(`E${currRowIdx}`);
            cNas.value = eq.nas_nombre || 'No asignado';
            cNas.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF475569' } };
            cNas.alignment = { vertical: 'middle' };

            // Celda F: Último Respaldo
            const cUlt = ws1.getCell(`F${currRowIdx}`);
            cUlt.value = eq.ultimo_respaldo ? eq.ultimo_respaldo.replace('T', ' ') : 'Sin registro';
            cUlt.font = { name: 'Segoe UI', size: 8.5, color: { argb: 'FF334155' } };
            cUlt.alignment = { horizontal: 'center', vertical: 'middle' };

            // Celda G: Días Sin Respaldo
            const cDias = ws1.getCell(`G${currRowIdx}`);
            cDias.value = Number(eq.dias_sin_respaldo || 0);
            cDias.font = { name: 'Segoe UI', size: 9.5, bold: true };
            cDias.alignment = { horizontal: 'center', vertical: 'middle' };

            // Celda H: Tamaño
            const cTam = ws1.getCell(`H${currRowIdx}`);
            cTam.value = formatBytes(eq.tamano_bytes);
            cTam.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF1E293B' } };
            cTam.alignment = { horizontal: 'right', vertical: 'middle' };

            // Celda I: Estado Semáforo con Color Oficial
            const cEst = ws1.getCell(`I${currRowIdx}`);
            let estadoTexto = 'Al Día (≤6d)';
            let estBg = 'FFDCFCE7';
            let estFg = 'FF15803D';

            if (eq.estado_actual === 'vencido') {
                estadoTexto = 'En Alerta (7-12d)';
                estBg = 'FFFEF3C7';
                estFg = 'FFB45309';
            } else if (eq.estado_actual === 'critico') {
                estadoTexto = 'Crítico (≥13d)';
                estBg = 'FFFEE2E2';
                estFg = 'FFB91C1C';
            } else if (eq.estado_actual === 'incompleto') {
                estadoTexto = 'Incompleto (.tmp)';
                estBg = 'FFFCE7F3';
                estFg = 'FFBE185D';
            } else if (eq.estado_actual === 'exento') {
                estadoTexto = 'No Aplica (Exento)';
                estBg = 'FFF1F5F9';
                estFg = 'FF475569';
            }

            cEst.value = estadoTexto;
            cEst.font = { name: 'Segoe UI', size: 8.5, bold: true, color: { argb: estFg } };
            cEst.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: estBg } };
            cEst.alignment = { horizontal: 'center', vertical: 'middle' };

            // Celda J: Archivo Reciente
            const cArch = ws1.getCell(`J${currRowIdx}`);
            cArch.value = eq.archivo_reciente || 'Ninguno';
            cArch.font = { name: 'Consolas', size: 8.5, color: { argb: 'FF475569' } };
            cArch.alignment = { vertical: 'middle' };

            // Celda K: Temporales
            const cTmp = ws1.getCell(`K${currRowIdx}`);
            if (eq.tiene_temporales) {
                cTmp.value = 'SÍ (.TMP)';
                cTmp.font = { name: 'Segoe UI', size: 8.5, bold: true, color: { argb: 'FF991B1B' } };
                cTmp.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFEE2E2' } };
            } else {
                cTmp.value = 'NO';
                cTmp.font = { name: 'Segoe UI', size: 8.5, color: { argb: 'FF64748B' } };
                cTmp.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgZebra } };
            }
            cTmp.alignment = { horizontal: 'center', vertical: 'middle' };

            // Aplicar fondos zebra y bordes a las columnas sin fondo propio
            ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'J'].forEach(colK => {
                const cell = ws1.getCell(`${colK}${currRowIdx}`);
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgZebra } };
                cell.border = borderThin;
            });
            cEst.border = borderThin;
            cTmp.border = borderThin;

            currRowIdx++;
        });

        // Pie de página Hoja 1
        ws1.mergeCells(`B${currRowIdx}:K${currRowIdx}`);
        const cFoot1 = ws1.getCell(`B${currRowIdx}`);
        cFoot1.value = `Reporte Oficial emitido por el Sistema de Auditoría de Sistemas | ${agencia.nombre} | ${totalEquipos} equipos monitoreados`;
        cFoot1.font = { name: 'Segoe UI', size: 9, bold: true, color: { argb: 'FF475569' } };
        cFoot1.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF1F5F9' } };
        cFoot1.alignment = { vertical: 'middle' };
        cFoot1.border = borderThin;

        // =====================================================================
        // HOJA 2: MATRIZ SEMANAL DEL MES (SECCIONADA POR ÁREA)
        // =====================================================================
        const ws2 = wb.addWorksheet('Matriz Semanal por Área', {
            views: [{ showGridLines: true }]
        });

        const semanasDef = res.semanas_def || {};
        const semanasKeys = Object.keys(semanasDef);

        // Definir columnas de la Hoja 2
        const colsWs2 = [
            { key: 'margen', width: 3 },
            { key: 'equipo', width: 22 },
            { key: 'usuario', width: 25 },
            { key: 'area', width: 20 }
        ];

        semanasKeys.forEach(sId => {
            colsWs2.push({ key: sId, width: 17 });
        });
        colsWs2.push({ key: 'pct', width: 18 });
        ws2.columns = colsWs2;

        const ultimaColLetra = String.fromCharCode(65 + colsWs2.length - 1); // e.g. 'I' o 'J'

        // 1. Membrete Hoja 2
        ws2.mergeCells(`B2:${ultimaColLetra}2`);
        const cTitle2 = ws2.getCell('B2');
        cTitle2.value = `MATRIZ SEMANAL DE CONSTANCIA Y RESPALDOS - ${res.mes_seleccionado || mesSel}`;
        cTitle2.font = { name: 'Segoe UI', size: 15, bold: true, color: { argb: 'FF0F172A' } };
        cTitle2.alignment = { vertical: 'middle' };

        ws2.mergeCells(`B3:${ultimaColLetra}3`);
        const cSub2 = ws2.getCell('B3');
        cSub2.value = `Auditoría mensual de 4 a 5 semanas calendario organizada por Departamentos / Áreas (${agencia.nombre})`;
        cSub2.font = { name: 'Segoe UI', size: 11, bold: true, color: { argb: 'FF0284C7' } };
        cSub2.alignment = { vertical: 'middle' };

        ws2.mergeCells(`B4:${ultimaColLetra}4`);
        const cMeta2_2 = ws2.getCell('B4');
        cMeta2_2.value = `Emitido: ${fechaHoy} ${horaHoy} hrs  |  Filtros: ${filtrosAplicadosStr}  |  Total Equipos: ${totalEquipos}`;
        cMeta2_2.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF475569' } };
        cMeta2_2.alignment = { vertical: 'middle' };

        // 2. Leyenda explicativa de colores
        ws2.mergeCells(`B5:${ultimaColLetra}5`);
        const cLeyenda = ws2.getCell('B5');
        cLeyenda.value = 'GUÍA VISUAL: [Verde] Cumplido en fecha  |  [Verde Agua] En curso (≤6 días)  |  [Azul] Programado próx.  |  [Gris] No aplica (Exento)  |  [Rojo] Sin respaldo  |  [Gris Claro] Futuro';
        cLeyenda.font = { name: 'Segoe UI', size: 8.5, bold: true, color: { argb: 'FF334155' } };
        cLeyenda.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF8FAFC' } };
        cLeyenda.alignment = { vertical: 'middle' };
        cLeyenda.border = borderThin;

        // 3. Encabezados de Columnas de la Matriz (Fila 7)
        ws2.getRow(7).height = 28;
        const colHeadersWs2 = [
            { col: 'B', text: 'NOMBRE DEL EQUIPO' },
            { col: 'C', text: 'USUARIO RESPONSABLE' },
            { col: 'D', text: 'ÁREA' }
        ];

        let colCode = 69; // 'E'
        semanasKeys.forEach(sId => {
            const sConf = semanasDef[sId] || {};
            const colLetter = String.fromCharCode(colCode);
            colHeadersWs2.push({
                col: colLetter,
                text: `${sConf.label || sId}\n(${sConf.dias_label || ''})`
            });
            colCode++;
        });

        const pctColLetter = String.fromCharCode(colCode);
        colHeadersWs2.push({ col: pctColLetter, text: '% CUMPLIMIENTO' });

        colHeadersWs2.forEach(ch => {
            const cell = ws2.getCell(`${ch.col}7`);
            cell.value = ch.text;
            cell.font = fontHeader;
            cell.fill = fillHeaderDark;
            cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
            cell.border = borderThin;
        });

        // 4. Filas de la Matriz Agrupadas por ÁREA
        let rowMatrizIdx = 8;
        const areasNombresOrdenadas = Object.keys(areasMap).sort();

        areasNombresOrdenadas.forEach(arNombre => {
            const eqListArea = areasMap[arNombre];

            // Fila de encabezado de área (Separador distintivo de área)
            ws2.mergeCells(`B${rowMatrizIdx}:${ultimaColLetra}${rowMatrizIdx}`);
            const cAreaHeader = ws2.getCell(`B${rowMatrizIdx}`);
            cAreaHeader.value = `📂 ÁREA: ${arNombre.toUpperCase()} (${eqListArea.length} ${eqListArea.length === 1 ? 'equipo' : 'equipos'})`;
            cAreaHeader.font = { name: 'Segoe UI', size: 10.5, bold: true, color: { argb: 'FFFFFFFF' } };
            cAreaHeader.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1E293B' } };
            cAreaHeader.alignment = { vertical: 'middle' };
            cAreaHeader.border = borderThin;
            ws2.getRow(rowMatrizIdx).height = 24;
            rowMatrizIdx++;

            // Filas de cada equipo de esta área
            eqListArea.forEach((eq, eqAreaIdx) => {
                const r = ws2.getRow(rowMatrizIdx);
                r.height = 20;
                const bgZebra = (eqAreaIdx % 2 === 0) ? 'FFFFFFFF' : 'FFF8FAFC';

                // B: Equipo
                const cEq = ws2.getCell(`B${rowMatrizIdx}`);
                cEq.value = eq.nombre_equipo;
                cEq.font = { name: 'Segoe UI', size: 9.5, bold: true, color: { argb: 'FF0F172A' } };
                cEq.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgZebra } };
                cEq.alignment = { vertical: 'middle' };
                cEq.border = borderThin;

                // C: Usuario
                const cUsr = ws2.getCell(`C${rowMatrizIdx}`);
                cUsr.value = eq.usuario_responsable || 'Sin asignar';
                cUsr.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF334155' } };
                cUsr.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgZebra } };
                cUsr.alignment = { vertical: 'middle' };
                cUsr.border = borderThin;

                // D: Área
                const cAr = ws2.getCell(`D${rowMatrizIdx}`);
                cAr.value = eq.area_nombre || 'General';
                cAr.font = { name: 'Segoe UI', size: 9, color: { argb: 'FF0369A1' } };
                cAr.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgZebra } };
                cAr.alignment = { vertical: 'middle' };
                cAr.border = borderThin;

                // Columnas de Semanas S1..S5
                let sColCode = 69; // 'E'
                semanasKeys.forEach(sId => {
                    const colLetter = String.fromCharCode(sColCode);
                    const cSem = ws2.getCell(`${colLetter}${rowMatrizIdx}`);
                    const semData = (eq.semanas && eq.semanas[sId]) ? eq.semanas[sId] : { estado: 'sin_respaldo' };

                    let valTexto = 'Sin resp.';
                    let semBg = 'FFFEE2E2'; // rojo suave
                    let semFg = 'FF991B1B';

                    if (semData.estado === 'exitoso') {
                        valTexto = semData.fecha_corta || 'Cumplido';
                        semBg = 'FFDCFCE7'; // verde
                        semFg = 'FF166534';
                    } else if (semData.estado === 'exento') {
                        valTexto = 'No aplica';
                        semBg = 'FFF1F5F9'; // gris
                        semFg = 'FF475569';
                    } else if (semData.estado === 'programado') {
                        valTexto = semData.label_extra || 'Programado';
                        semBg = 'FFE0F2FE'; // azul suave
                        semFg = 'FF0369A1';
                    } else if (semData.estado === 'en_curso') {
                        valTexto = 'En curso';
                        semBg = 'FFCCFBF1'; // menta
                        semFg = 'FF115E59';
                    } else if (semData.estado === 'futuro') {
                        valTexto = '-';
                        semBg = 'FFF8FAFC'; // gris muy tenue
                        semFg = 'FF94A3B8';
                    }

                    cSem.value = valTexto;
                    cSem.font = { name: 'Segoe UI', size: 8.5, bold: true, color: { argb: semFg } };
                    cSem.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: semBg } };
                    cSem.alignment = { horizontal: 'center', vertical: 'middle' };
                    cSem.border = borderThin;

                    sColCode++;
                });

                // Columna % Cumplimiento del mes
                const cPct = ws2.getCell(`${pctColLetter}${rowMatrizIdx}`);
                const pctNum = Number(eq.cumplimiento_porcentaje !== undefined ? eq.cumplimiento_porcentaje : (eq.porcentaje || 0));
                cPct.value = `${pctNum}%`;

                let pctBg = 'FFFEE2E2';
                let pctFg = 'FFB91C1C';
                if (pctNum >= 80) {
                    pctBg = 'FFDCFCE7';
                    pctFg = 'FF15803D';
                } else if (pctNum >= 50) {
                    pctBg = 'FFFEF3C7';
                    pctFg = 'FFB45309';
                }

                cPct.font = { name: 'Segoe UI', size: 9, bold: true, color: { argb: pctFg } };
                cPct.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: pctBg } };
                cPct.alignment = { horizontal: 'center', vertical: 'middle' };
                cPct.border = borderThin;

                rowMatrizIdx++;
            });
        });

        // 5. Pie de la Hoja 2
        ws2.mergeCells(`B${rowMatrizIdx}:${ultimaColLetra}${rowMatrizIdx}`);
        const cFoot2 = ws2.getCell(`B${rowMatrizIdx}`);
        cFoot2.value = `Fin de Matriz Semanal • Total de Áreas: ${areasNombresOrdenadas.length} • Total Equipos: ${totalEquipos} • SLA Promedio: ${cumplimientoSLA}%`;
        cFoot2.font = { name: 'Segoe UI', size: 9, bold: true, color: { argb: 'FF334155' } };
        cFoot2.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF1F5F9' } };
        cFoot2.alignment = { vertical: 'middle' };
        cFoot2.border = borderThin;

        // =====================================================================
        // GENERACIÓN Y DESCARGA DEL ARCHIVO BINARIO (.XLSX)
        // =====================================================================
        const buffer = await wb.xlsx.writeBuffer();
        const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const cleanAgencia = (agencia.nombre || 'Grupo_Huerta').replace(/[^a-zA-Z0-9_-]/g, '_');
        const fileName = `Auditoria_Respaldos_${cleanAgencia}_${mesSel}_${now.toISOString().slice(0, 10)}.xlsx`;

        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);

        // Notificación de éxito
        Swal.fire({
            title: '¡Reporte Excel Generado!',
            html: `
                <div class="text-start small py-1">
                    <p class="mb-2 text-white">El libro de auditoría se ha generado y descargado correctamente con el formato ejecutivo oficial:</p>
                    <ul class="text-secondary ps-3 mb-2">
                        <li><strong>Hoja 1 ("Resumen y Auditoría"):</strong> Membrete corporativo, KPIs (SLA, Críticos, Al Día, Temporales), Gráficas ejecutivas y tabla completa filtrada.</li>
                        <li><strong>Hoja 2 ("Matriz Semanal por Área"):</strong> Desglose de semanas de ${mesSel} agrupadas y seccionadas por Departamentos con colores del semáforo.</li>
                    </ul>
                    <div class="p-2 rounded bg-dark border border-secondary border-opacity-25 text-success font-monospace" style="font-size: 0.75rem;">
                        <i class="bi bi-file-earmark-excel me-1"></i> ${fileName}
                    </div>
                </div>
            `,
            icon: 'success',
            confirmButtonText: 'Aceptar',
            confirmButtonColor: '#10b981'
        });

    } catch (err) {
        console.error('Error al exportar libro Excel:', err);
        Swal.fire({
            title: 'Error al Generar Excel',
            text: err.message || 'Ocurrió un problema inesperado al construir el reporte.',
            icon: 'error'
        });
    }
}

</script>
</body>
</html>
