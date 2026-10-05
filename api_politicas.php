<?php
// API Central y Gestor de Políticas Corporativas Protegidas
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
    if ($id <= 0 || !$pdo) {
        http_response_code(404);
        die("Documento no encontrado.");
    }

    try {
        $stmt = $pdo->prepare("SELECT archivo_pdf, titulo FROM politicas_corporativas WHERE id = ? AND estatus = 1 LIMIT 1");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            http_response_code(404);
            die("Política no disponible o inactiva.");
        }

        $filePath = __DIR__ . '/' . $doc['archivo_pdf'];
        if (!file_exists($filePath)) {
            // Intentar buscar relativo en uploads/politicas
            $filePath = __DIR__ . '/uploads/politicas/' . basename($doc['archivo_pdf']);
        }

        if (!file_exists($filePath)) {
            http_response_code(404);
            die("Archivo físico no encontrado en el servidor.");
        }

        // Enviar encabezados anti-descarga y anti-caché
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="politica_protegida_' . $id . '.pdf"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($filePath);
        exit();

    } catch (Throwable $e) {
        http_response_code(500);
        die("Error al procesar el documento: " . $e->getMessage());
    }
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
            SELECT id, titulo, descripcion, categoria, version, fecha_vigencia, obligatorio_lectura, estatus, creado_en 
            FROM politicas_corporativas 
            WHERE estatus = 1 
            ORDER BY categoria ASC, titulo ASC
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

// 4. REGISTRAR LECTURA DE POLÍTICA EN BITÁCORA
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
    $usuarioId = isset($_SESSION['usuario_id']) ? intval($_SESSION['usuario_id']) : (intval($_POST['usuario_id'] ?? 0));
    $usuarioNombre = trim($_POST['usuario_nombre'] ?? ($_SESSION['usuario_nombre'] ?? 'Colaborador'));
    $usuarioLogin = trim($_POST['usuario_login'] ?? ($_SESSION['usuario_login'] ?? 'usuario'));
    $agencia = trim($_POST['agencia'] ?? ($_SESSION['agencia'] ?? 'Grupo Huerta'));
    $ip = trim($_POST['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $origen = trim($_POST['origen'] ?? ($esPeticionAgenciaAutorizada ? 'Portal Agencia' : 'Portal GH'));

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

            echo json_encode(['exito' => true, 'mensaje' => 'Lectura registrada correctamente']);
            exit();
        } catch (Throwable $e) {
            echo json_encode(['exito' => false, 'error' => $e->getMessage()]);
            exit();
        }
    }

    echo json_encode(['exito' => false, 'error' => 'Sin conexión a base de datos']);
    exit();
}

// 5. OBTENER BITÁCORA AUDITABLE DE LECTURAS (SOLO ADMINISTRADORES)
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
