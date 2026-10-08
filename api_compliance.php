<?php
// API Central de Compliance (Portal Central GH)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-GH-TOKEN');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'conexion.php';
require_once 'permisos_helper.php';

if ($pdo) {
    asegurarTablaCompliance($pdo);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'listar');

// 1. STREAM SEGURO DE ARCHIVO / IMAGEN
if ($action === 'stream_archivo') {
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_GH_TOKEN'] ?? ($_POST['token'] ?? ''));
    $esPeticionAgenciaAutorizada = ($token === 'GH_COMPLIANCE_SEGURA_2026');

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
    $mimeType = 'application/octet-stream';
    $fileName = 'documento_' . $id;

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT archivo_url, imagen_url, archivo_tipo, titulo FROM compliance_documentos WHERE id = ? AND estatus = 1 LIMIT 1");
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $campoRuta = !empty($doc['archivo_url']) ? $doc['archivo_url'] : ($doc['imagen_url'] ?? '');
                if (!empty($_GET['campo']) && $_GET['campo'] === 'imagen' && !empty($doc['imagen_url'])) {
                    $campoRuta = $doc['imagen_url'];
                }

                if (!empty($campoRuta)) {
                    $possiblePath = __DIR__ . '/' . $campoRuta;
                    if (file_exists($possiblePath)) {
                        $filePath = $possiblePath;
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
                    'doc'  => 'application/msword',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'xls'  => 'application/vnd.ms-excel'
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

    http_response_code(404);
    die("Archivo no disponible.");
}

// 2. LISTADO JSON COMPLETO (FORMATOS, MANUALES, AVISOS)
if ($action === 'listar') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$pdo) {
        echo json_encode(['exito' => false, 'error' => 'No hay conexión', 'formatos' => [], 'manuales' => [], 'avisos' => []]);
        exit();
    }

    try {
        $stmtF = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'formato' AND estatus = 1 ORDER BY codigo ASC, id DESC");
        $formatos = $stmtF ? $stmtF->fetchAll(PDO::FETCH_ASSOC) : [];

        $stmtM = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'manual' AND estatus = 1 ORDER BY codigo ASC, id DESC");
        $manuales = $stmtM ? $stmtM->fetchAll(PDO::FETCH_ASSOC) : [];

        $stmtA = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'aviso' AND estatus = 1 ORDER BY id DESC");
        $avisos = $stmtA ? $stmtA->fetchAll(PDO::FETCH_ASSOC) : [];

        $baseUrl = 'https://portal.grupohuerta.mx/';

        foreach ($avisos as &$av) {
            $av['fondo_cls'] = obtenerClaseFondoAviso($av);
            $imgExt = strtolower(pathinfo($av['imagen_url'] ?? '', PATHINFO_EXTENSION));
            $av['es_fisica'] = !empty($av['imagen_url']) && in_array($imgExt, ['jpg', 'jpeg', 'png', 'webp', 'gif']) && (strpos($av['imagen_url'], 'aviso_card_') === false);

            if (!empty($av['imagen_url'])) {
                if (strpos($av['imagen_url'], 'http') !== 0) {
                    $av['imagen_url_completa'] = $baseUrl . ltrim($av['imagen_url'], '/');
                } else {
                    $av['imagen_url_completa'] = $av['imagen_url'];
                }
            } elseif (!empty($av['archivo_url'])) {
                $ext = strtolower(pathinfo($av['archivo_url'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'])) {
                    $av['imagen_url_completa'] = $baseUrl . ltrim($av['archivo_url'], '/');
                }
            }
            if (!empty($av['archivo_url']) && strpos($av['archivo_url'], 'http') !== 0) {
                $av['archivo_url_completo'] = $baseUrl . ltrim($av['archivo_url'], '/');
            }
        }
        unset($av);

        foreach ($formatos as &$fmt) {
            if (!empty($fmt['archivo_url']) && strpos($fmt['archivo_url'], 'http') !== 0) {
                $fmt['archivo_url_completo'] = $baseUrl . ltrim($fmt['archivo_url'], '/');
            }
        }
        unset($fmt);

        foreach ($manuales as &$man) {
            if (!empty($man['archivo_url']) && strpos($man['archivo_url'], 'http') !== 0) {
                $man['archivo_url_completo'] = $baseUrl . ltrim($man['archivo_url'], '/');
            }
        }
        unset($man);

        echo json_encode([
            'exito' => true,
            'total_formatos' => count($formatos),
            'total_manuales' => count($manuales),
            'total_avisos' => count($avisos),
            'formatos' => $formatos,
            'manuales' => $manuales,
            'avisos' => $avisos,
            'servidor' => 'portal.grupohuerta.mx',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit();

    } catch (Throwable $e) {
        echo json_encode(['exito' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

echo json_encode(['exito' => false, 'error' => 'Acción no válida']);
