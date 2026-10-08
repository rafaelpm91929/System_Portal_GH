<?php
// API Central y Gestor de Políticas Corporativas Protegidas
date_default_timezone_set('America/Mexico_City');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once 'conexion.php';
require_once 'permisos_helper.php';

if ($pdo) {
    asegurarTablaPoliticas($pdo);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'listar');

// 1. STREAM SEGURO DE ARCHIVO PDF (PARA PDF.JS)
if ($action === 'stream_pdf') {
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_GH_TOKEN'] ?? '');
    $esPeticionAgenciaAutorizada = ($token === 'GH_POLITICAS_SEGURA_2026_CORP');

    if (!isset($_SESSION['usuario_id']) && !$esPeticionAgenciaAutorizada) {
        http_response_code(403);
        die("Acceso no autorizado.");
    }

    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        die("Documento no encontrado.");
    }

    $filePath = null;
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT archivo_pdf, titulo FROM politicas_corporativas WHERE id = ? AND estatus = 1 LIMIT 1");
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $possiblePath = __DIR__ . '/' . $doc['archivo_pdf'];
                if (file_exists($possiblePath)) {
                    $filePath = $possiblePath;
                } else {
                    $altPath = __DIR__ . '/uploads/politicas/' . basename($doc['archivo_pdf']);
                    if (file_exists($altPath)) {
                        $filePath = $altPath;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    // A. Si el archivo físico se encuentra en el servidor local
    if ($filePath && file_exists($filePath)) {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="politica_protegida_' . $id . '.pdf"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($filePath);
        exit();
    }

    // B. Si NO está en el servidor local (portal de agencia), solicitar stream seguro desde Portal Central GH
    $urlCentralPdf = 'https://portal.grupohuerta.mx/api_politicas.php?action=stream_pdf&id=' . $id . '&token=GH_POLITICAS_SEGURA_2026_CORP';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header'  => "User-Agent: PortalAgenciaStream/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $pdfBytes = @file_get_contents($urlCentralPdf, false, $ctx);
    if ($pdfBytes !== false && strlen($pdfBytes) > 100) {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdfBytes));
        header('Content-Disposition: inline; filename="politica_protegida_' . $id . '.pdf"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $pdfBytes;
        exit();
    }

    http_response_code(404);
    die("Archivo de política no disponible en el servidor ni en Central GH.");
}

// 2. LISTADO JSON DE POLÍTICAS ACTIVAS
if ($action === 'listar') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$pdo) {
        echo json_encode(['exito' => false, 'error' => 'No hay conexión a la base de datos', 'politicas' => []]);
        exit();
    }

    try {
        $stmt = $pdo->query("
            SELECT * 
            FROM politicas_corporativas 
            WHERE estatus = 1 
            ORDER BY COALESCE(area, categoria) ASC, COALESCE(subarea, '') ASC, titulo ASC
        ");
        $politicas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'exito' => true,
            'total' => count($politicas),
            'politicas' => $politicas
        ], JSON_UNESCAPED_UNICODE);
        exit();

    } catch (Throwable $e) {
        echo json_encode(['exito' => false, 'error' => $e->getMessage(), 'politicas' => []]);
        exit();
    }
}

// 3. SINCRONIZAR DESDE EL PORTAL CENTRAL GH (PARA PORTALES DE AGENCIA)
if ($action === 'sincronizar_central') {
    header('Content-Type: application/json; charset=utf-8');
    
    // URL del endpoint central de Grupo Huerta
    $urlCentral = 'https://portal.grupohuerta.mx/api_politicas.php?action=listar';

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header'  => "User-Agent: PortalAgenciaClient/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $response = @file_get_contents($urlCentral, false, $ctx);
    if ($response === false) {
        echo json_encode([
            'exito' => false, 
            'error' => 'No se pudo contactar al Portal Central GH. Mostrando políticas locales.'
        ]);
        exit();
    }

    $json = json_decode($response, true);
    if (!$json || empty($json['exito'])) {
        echo json_encode([
            'exito' => false, 
            'error' => 'Respuesta no válida del Portal Central GH.'
        ]);
        exit();
    }

    echo json_encode([
        'exito' => true,
        'mensaje' => 'Sincronización con Central GH exitosa',
        'total' => count($json['politicas'] ?? []),
        'politicas' => $json['politicas'] ?? []
    ]);
    exit();
}

// 4. REGISTRAR LECTURA DE POLÍTICA (LOCAL Y REPORTAR A CENTRAL GH)
if ($action === 'registrar_lectura') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['usuario_id'])) {
        echo json_encode(['exito' => false, 'error' => 'Acceso no autorizado']);
        exit();
    }

    $politicaId = intval($_POST['politica_id'] ?? 0);
    $politicaTitulo = trim($_POST['politica_titulo'] ?? '');
    $usuarioId = intval($_SESSION['usuario_id'] ?? 0);
    $usuarioNombre = trim($_POST['usuario_nombre'] ?? ($_SESSION['usuario_nombre'] ?? 'Colaborador'));
    $usuarioLogin = trim($_POST['usuario_login'] ?? ($_SESSION['usuario_login'] ?? 'usuario'));
    $agencia = trim($_POST['agencia'] ?? ($_SESSION['agencia'] ?? 'Agencia'));
    $ip = trim($_POST['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $origen = 'Portal ' . $agencia;

    if ($politicaId < 0 || empty($politicaTitulo)) {
        echo json_encode(['exito' => false, 'error' => 'Información de política requerida']);
        exit();
    }

    $localLecturaId = 0;
    date_default_timezone_set('America/Mexico_City');
    $fechaLecturaMexico = date('Y-m-d H:i:s');

    // A. Guardar en base de datos local de la agencia si está disponible
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO politicas_lecturas (politica_id, politica_titulo, usuario_id, usuario_nombre, usuario_login, agencia, ip, origen, fecha_lectura, fecha_fin, duracion_segundos)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)
            ");
            $stmt->execute([$politicaId, $politicaTitulo, $usuarioId ?: null, $usuarioNombre, $usuarioLogin, $agencia, $ip, $origen, $fechaLecturaMexico]);
            $localLecturaId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {}
    }

    // B. Reportar de inmediato al Portal Central GH
    $urlCentralLog = 'https://portal.grupohuerta.mx/api_politicas.php?action=registrar_lectura';
    $postData = http_build_query([
        'token' => 'GH_POLITICAS_SEGURA_2026_CORP',
        'politica_id' => $politicaId,
        'politica_titulo' => $politicaTitulo,
        'usuario_id' => $usuarioId,
        'usuario_nombre' => $usuarioNombre,
        'usuario_login' => $usuarioLogin,
        'agencia' => $agencia,
        'ip' => $ip,
        'origen' => $origen,
        'fecha_lectura' => $fechaLecturaMexico
    ]);

    $ctxLog = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\nUser-Agent: PortalAgenciaLog/1.0\r\n",
            'content' => $postData,
            'timeout' => 4
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    @file_get_contents($urlCentralLog, false, $ctxLog);

    echo json_encode([
        'exito' => true, 
        'mensaje' => 'Lectura registrada y notificada al corporativo',
        'lectura_id' => $localLecturaId,
        'fecha_lectura' => $fechaLecturaMexico
    ]);
    exit();
}

// 4.1 FINALIZAR LECTURA Y REGISTRAR HORA DE SALIDA Y DURACIÓN
if ($action === 'finalizar_lectura') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['usuario_id'])) {
        echo json_encode(['exito' => false, 'error' => 'Acceso no autorizado']);
        exit();
    }

    $lecturaId = intval($_POST['lectura_id'] ?? ($_GET['lectura_id'] ?? 0));
    if ($lecturaId > 0 && $pdo) {
        try {
            date_default_timezone_set('America/Mexico_City');
            $stmt = $pdo->prepare("SELECT fecha_lectura FROM politicas_lecturas WHERE id = ?");
            $stmt->execute([$lecturaId]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fila && !empty($fila['fecha_lectura'])) {
                $inicio = strtotime($fila['fecha_lectura']);
                $fin = time();
                $duracion = max(1, $fin - $inicio);
                $fechaFinStr = date('Y-m-d H:i:s', $fin);

                $updateStmt = $pdo->prepare("
                    UPDATE politicas_lecturas 
                    SET fecha_fin = ?, duracion_segundos = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$fechaFinStr, $duracion, $lecturaId]);

                echo json_encode([
                    'exito' => true, 
                    'mensaje' => 'Lectura finalizada',
                    'fecha_fin' => $fechaFinStr,
                    'duracion_segundos' => $duracion
                ]);
                exit();
            }
        } catch (Throwable $e) {
            echo json_encode(['exito' => false, 'error' => $e->getMessage()]);
            exit();
        }
    }

    echo json_encode(['exito' => false, 'error' => 'ID inválido']);
    exit();
}

// 5. OBTENER BITÁCORA AUDITABLE DE LECTURAS (NO DISPONIBLE EN RAMA MAIN / SUCURSALES)
if ($action === 'obtener_lecturas') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito' => false, 'error' => 'Acceso no permitido: La bitácora de auditoría forense únicamente está disponible en el Portal Central GH.', 'lecturas' => []]);
    exit();
}
