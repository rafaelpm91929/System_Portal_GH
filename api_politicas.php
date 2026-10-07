<?php
// API Central y Gestor de Políticas Corporativas Protegidas (Portal Central GH)
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
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_GH_TOKEN'] ?? ($_POST['token'] ?? ''));
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

    http_response_code(404);
    die("Archivo de política no disponible en el servidor.");
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

// 3. SINCRONIZAR DESDE EL PORTAL CENTRAL GH
if ($action === 'sincronizar_central') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$pdo) {
        echo json_encode(['exito' => false, 'error' => 'No hay conexión a la base de datos local']);
        exit();
    }

    try {
        $stmt = $pdo->query("SELECT * FROM politicas_corporativas WHERE estatus = 1 ORDER BY COALESCE(area, categoria) ASC, COALESCE(subarea, '') ASC, titulo ASC");
        $politicas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'exito' => true,
            'mensaje' => 'Políticas sincronizadas desde servidor central',
            'total' => count($politicas),
            'politicas' => $politicas
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Throwable $e) {
        echo json_encode(['exito' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// 4. REGISTRAR LECTURA DE POLÍTICA EN BITÁCORA AUDITABLE
if ($action === 'registrar_lectura') {
    header('Content-Type: application/json; charset=utf-8');

    $token = $_POST['token'] ?? ($_GET['token'] ?? ($_SERVER['HTTP_X_GH_TOKEN'] ?? ''));
    $esPeticionAgenciaAutorizada = ($token === 'GH_POLITICAS_SEGURA_2026_CORP');

    if (!isset($_SESSION['usuario_id']) && !$esPeticionAgenciaAutorizada) {
        echo json_encode(['exito' => false, 'error' => 'Acceso no autorizado']);
        exit();
    }

    $politicaId = intval($_POST['politica_id'] ?? 0);
    $politicaTitulo = trim($_POST['politica_titulo'] ?? '');
    $usuarioId = isset($_SESSION['usuario_id']) ? intval($_SESSION['usuario_id']) : intval($_POST['usuario_id'] ?? 0);
    $usuarioNombre = trim($_POST['usuario_nombre'] ?? ($_SESSION['usuario_nombre'] ?? 'Colaborador'));
    $usuarioLogin = trim($_POST['usuario_login'] ?? ($_SESSION['usuario_login'] ?? 'usuario'));
    $agencia = trim($_POST['agencia'] ?? ($_SESSION['agencia'] ?? 'Grupo Huerta'));
    $ip = trim($_POST['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $origen = trim($_POST['origen'] ?? ($esPeticionAgenciaAutorizada ? 'Portal ' . $agencia : 'Portal Central GH'));

    if ($politicaId <= 0) {
        echo json_encode(['exito' => false, 'error' => 'ID de política requerido']);
        exit();
    }

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO politicas_lecturas (politica_id, politica_titulo, usuario_id, usuario_nombre, usuario_login, agencia, ip, origen, fecha_lectura)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$politicaId, $politicaTitulo, $usuarioId ?: null, $usuarioNombre, $usuarioLogin, $agencia, $ip, $origen]);

            echo json_encode(['exito' => true, 'mensaje' => 'Lectura registrada correctamente en Central GH']);
            exit();
        } catch (Throwable $e) {
            echo json_encode(['exito' => false, 'error' => $e->getMessage()]);
            exit();
        }
    }

    echo json_encode(['exito' => false, 'error' => 'Sin conexión a base de datos']);
    exit();
}

// 5. OBTENER BITÁCORA AUDITABLE DE LECTURAS (ADMINISTRADORES)
if ($action === 'obtener_lecturas') {
    header('Content-Type: application/json; charset=utf-8');

    $rol = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? '');
    if (!in_array($rol, ['superadmin', 'admin'])) {
        echo json_encode(['exito' => false, 'error' => 'Acceso denegado. Se requieren permisos de administrador.']);
        exit();
    }

    if (!$pdo) {
        echo json_encode(['exito' => false, 'error' => 'Sin conexión a base de datos', 'lecturas' => []]);
        exit();
    }

    try {
        $filtroPolitica = intval($_GET['politica_id'] ?? 0);
        $sql = "SELECT id, politica_id, politica_titulo, usuario_id, usuario_nombre, usuario_login, agencia, ip, origen, fecha_lectura 
                FROM politicas_lecturas ";
        $params = [];
        if ($filtroPolitica > 0) {
            $sql .= " WHERE politica_id = ? ";
            $params[] = $filtroPolitica;
        }
        $sql .= " ORDER BY fecha_lectura DESC LIMIT 300";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lecturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Resumen y KPIs
        $stmtStats = $pdo->query("
            SELECT 
                COUNT(*) as total_lecturas, 
                COUNT(DISTINCT usuario_login) as usuarios_unicos, 
                COUNT(DISTINCT agencia) as agencias_activas 
            FROM politicas_lecturas
        ");
        $stats = $stmtStats ? $stmtStats->fetch(PDO::FETCH_ASSOC) : ['total_lecturas' => 0, 'usuarios_unicos' => 0, 'agencias_activas' => 0];

        echo json_encode([
            'exito' => true,
            'stats' => $stats,
            'total' => count($lecturas),
            'lecturas' => $lecturas
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Throwable $e) {
        echo json_encode(['exito' => false, 'error' => $e->getMessage(), 'lecturas' => []]);
        exit();
    }
}
