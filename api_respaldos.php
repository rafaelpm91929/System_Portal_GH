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
            // Cargar configuración
            $stmtConf = $pdo->query("SELECT clave, valor FROM respaldos_config");
            $config = [];
            while ($row = $stmtConf->fetch(PDO::FETCH_ASSOC)) {
                $config[$row['clave']] = $row['valor'];
            }

            // Cargar NAS activos
            $stmtNas = $pdo->query("SELECT id, nombre, ip_ruta_red, usuario_smb, password_smb, marca, notas FROM respaldos_nas WHERE estatus = 1 ORDER BY id ASC");
            $nasList = $stmtNas->fetchAll(PDO::FETCH_ASSOC);

            // Cargar carpetas activas de cada NAS
            foreach ($nasList as &$nas) {
                $stmtCarp = $pdo->prepare("
                    SELECT id, nombre_carpeta, ruta_relativa, area_nombre, monitorear 
                    FROM respaldos_carpetas 
                    WHERE nas_id = ? AND monitorear = 1 AND es_sistema = 0 
                    ORDER BY nombre_carpeta ASC
                ");
                $stmtCarp->execute([$nas['id']]);
                $nas['carpetas'] = $stmtCarp->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($nas);

            $patronesDescarte = array_map('trim', explode(',', $config['patrones_descarte'] ?? '@Recycle,#snapshot,@Recently-Snapshot,.dropbox.cache,aquota.user,aquota.group,Thumbs.db,desktop.ini,.DS_Store'));

            echo json_encode([
                'success' => true,
                'fecha_servidor' => date('Y-m-d H:i:s'),
                'nas_list' => $nasList,
                'config' => [
                    'umbral_alerta_horas' => (int)($config['umbral_alerta_horas'] ?? 24),
                    'umbral_critico_horas' => (int)($config['umbral_critico_horas'] ?? 48),
                    'tamano_minimo_valido_mb' => (float)($config['tamano_minimo_valido_mb'] ?? 1),
                    'patrones_descarte' => $patronesDescarte
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Error al consultar rutas: ' . $e->getMessage()]);
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

        // Obtener umbrales
        $stmtConf = $pdo->query("SELECT clave, valor FROM respaldos_config WHERE clave IN ('umbral_alerta_horas', 'umbral_critico_horas')");
        $confMap = $stmtConf->fetchAll(PDO::FETCH_KEY_PAIR);
        $umbralAlerta = (int)($confMap['umbral_alerta_horas'] ?? 24);
        $umbralCritico = (int)($confMap['umbral_critico_horas'] ?? 48);

        $procesados = 0;
        $ahoraTime = time();

        try {
            $pdo->beginTransaction();

            $stmtCheckEq = $pdo->prepare("SELECT id FROM respaldos_equipos WHERE nombre_equipo = ? LIMIT 1");
            $stmtUpdEq = $pdo->prepare("
                UPDATE respaldos_equipos 
                SET usuario_responsable = COALESCE(?, usuario_responsable),
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

                // Calcular estado y días sin respaldo
                $estado = 'critico';
                $diasSinRespaldo = 99;

                if (!empty($ultimoFecha)) {
                    $mtime = strtotime($ultimoFecha);
                    $horasTranscurridas = max(0, ($ahoraTime - $mtime) / 3600);
                    $diasSinRespaldo = (int)floor($horasTranscurridas / 24);

                    if ($tieneTemporales) {
                        $estado = 'incompleto';
                    } elseif ($horasTranscurridas <= $umbralAlerta) {
                        $estado = 'exitoso';
                    } elseif ($horasTranscurridas <= $umbralCritico) {
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

                // Guardar / Actualizar Historial del Día
                $stmtCheckHist->execute([$nombreEquipo, $fechaDia]);
                $histId = $stmtCheckHist->fetchColumn();

                if ($histId) {
                    $stmtUpdHist->execute([
                        $fechaReporte, $estado, $ultimoArchivo, $tamanoBytes, $duracionSeg, $nasId, $histId
                    ]);
                } else {
                    $stmtInsHist->execute([
                        $equipoId, $nombreEquipo, $nasId, $fechaDia, $fechaReporte, $estado, $ultimoArchivo, $tamanoBytes, $duracionSeg
                    ]);
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

            echo json_encode(['success' => true, 'carpetas' => $carpetas]);
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
