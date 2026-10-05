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
