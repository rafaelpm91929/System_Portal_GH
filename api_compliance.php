<?php
// API de Compliance y Proxy con Portal Central GH (Portal Agencia)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once 'conexion.php';
require_once 'permisos_helper.php';

if ($pdo) {
    asegurarTablaCompliance($pdo);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'listar');

// 1. STREAM SEGURO DE ARCHIVO O IMAGEN
if ($action === 'stream_archivo') {
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(403);
        die("Acceso no autorizado.");
    }

    $id = intval($_GET['id'] ?? 0);
    $campo = $_GET['campo'] ?? 'archivo';
    if ($id <= 0) {
        http_response_code(404);
        die("Documento no encontrado.");
    }

    $filePath = null;
    $mimeType = 'application/octet-stream';
    $fileName = 'documento_' . $id;

    // A. Verificar existencia local en primer orden
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT archivo_url, imagen_url, archivo_tipo, titulo FROM compliance_documentos WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $campoRuta = ($campo === 'imagen' && !empty($doc['imagen_url'])) ? $doc['imagen_url'] : $doc['archivo_url'];
                if (!empty($campoRuta)) {
                    $posPath = __DIR__ . '/' . $campoRuta;
                    if (file_exists($posPath)) {
                        $filePath = $posPath;
                    } else {
                        $altPath = __DIR__ . '/uploads/compliance/' . basename($campoRuta);
                        if (file_exists($altPath)) {
                            $filePath = $altPath;
                        }
                    }
                }
                $ext = strtolower(pathinfo($filePath ?: $campoRuta, PATHINFO_EXTENSION));
                $mimes = [
                    'pdf'  => 'application/pdf',
                    'svg'  => 'image/svg+xml',
                    'png'  => 'image/png',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    'gif'  => 'image/gif',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ];
                $mimeType = $mimes[$ext] ?? 'application/octet-stream';
                $fileName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $doc['titulo']) . '.' . ($ext ?: 'pdf');
            }
        } catch (Throwable $e) {}
    }

    if ($filePath && file_exists($filePath)) {
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        $extF = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $disposition = in_array($extF, ['pdf', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif']) ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit();
    }

    // B. Proxy a Portal Central GH si no existe en local
    $urlCentral = 'https://portal.grupohuerta.mx/api_compliance.php?action=stream_archivo&id=' . $id . '&campo=' . urlencode($campo) . '&token=GH_COMPLIANCE_SEGURA_2026';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header'  => "User-Agent: PortalAgenciaComplianceStream/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $fileBytes = @file_get_contents($urlCentral, false, $ctx);
    if ($fileBytes !== false && strlen($fileBytes) > 10) {
        $ext = ($campo === 'imagen') ? 'svg' : 'pdf';
        $mimes = [
            'pdf' => 'application/pdf',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg'
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . strlen($fileBytes));
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Cache-Control: private, max-age=3600');
        echo $fileBytes;
        exit();
    }

    http_response_code(404);
    die("Archivo no disponible en el servidor ni en Portal Central GH.");
}

// 2. LISTADO JSON CENTRAL
if ($action === 'listar') {
    header('Content-Type: application/json; charset=utf-8');

    // Intentar consultar Portal Central GH
    $urlCentral = 'https://portal.grupohuerta.mx/api_compliance.php?action=listar';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 6,
            'header'  => "User-Agent: PortalAgenciaCompliance/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $resp = @file_get_contents($urlCentral, false, $ctx);
    if ($resp) {
        $data = json_decode($resp, true);
        if (!empty($data['exito'])) {
            echo $resp;
            exit();
        }
    }

    // Fallback local
    if ($pdo) {
        try {
            $stmtF = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'formato' AND estatus = 1 ORDER BY codigo ASC, id DESC");
            $formatos = $stmtF ? $stmtF->fetchAll(PDO::FETCH_ASSOC) : [];

            $stmtM = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'manual' AND estatus = 1 ORDER BY codigo ASC, id DESC");
            $manuales = $stmtM ? $stmtM->fetchAll(PDO::FETCH_ASSOC) : [];

            $stmtA = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'aviso' AND estatus = 1 ORDER BY id DESC");
            $avisos = $stmtA ? $stmtA->fetchAll(PDO::FETCH_ASSOC) : [];

            echo json_encode([
                'exito' => true,
                'total_formatos' => count($formatos),
                'total_manuales' => count($manuales),
                'total_avisos' => count($avisos),
                'formatos' => $formatos,
                'manuales' => $manuales,
                'avisos' => $avisos,
                'origen' => 'local_fallback'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        } catch (Throwable $e) {}
    }

    echo json_encode(['exito' => false, 'error' => 'No disponible']);
    exit();
}
