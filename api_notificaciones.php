<?php
// =============================================================================
// API DE NOTIFICACIONES MULTIMÓDULO - GRUPO HUERTA
// Maneja la entrega, sincronización y estado de lectura respetando permisos RBAC.
// =============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'listar';
$token  = $_GET['token']  ?? $_POST['token']  ?? '';

// 1. ENDPOINT PÚBLICO PROTEGIDO POR TOKEN: CONSUMO REMOTO ENTRE PORTALES
if ($action === 'listar_remoto') {
    if ($token !== 'GH_NOTIFICACIONES_SEGURA_2026') {
        http_response_code(403);
        echo json_encode(['exito' => false, 'error' => 'Token de seguridad inválido.']);
        exit();
    }

    if (!$pdo) {
        echo json_encode(['exito' => false, 'error' => 'Sin conexión a base de datos.', 'notificaciones' => []]);
        exit();
    }

    asegurarTablaNotificaciones($pdo);
    try {
        $stmt = $pdo->query("SELECT * FROM notificaciones WHERE estatus = 1 ORDER BY id DESC LIMIT 50");
        $notifs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        echo json_encode(['exito' => true, 'total' => count($notifs), 'notificaciones' => $notifs]);
    } catch (Throwable $e) {
        echo json_encode(['exito' => false, 'error' => $e->getMessage(), 'notificaciones' => []]);
    }
    exit();
}

// 2. ENDPOINTS DE USUARIO (REQUIEREN SESIÓN INICIADA)
$usuarioId = $_SESSION['usuario_id'] ?? null;
if (!$usuarioId) {
    http_response_code(401);
    echo json_encode(['exito' => false, 'error' => 'Sesión no iniciada.', 'notificaciones' => [], 'total_no_leidas' => 0]);
    exit();
}

// Refrescar permisos del usuario
if ($pdo) {
    cargarPermisosSesion($pdo, $usuarioId);
    asegurarTablaNotificaciones($pdo);
}

// SINCRONIZACIÓN AUTOMÁTICA EN SEGUNDO PLANO (SI ES PORTAL DE AGENCIA Y CENTRAL RESPONDE)
if ($action === 'listar') {
    // Intentar sincronizar desde Portal Central GH si no estamos en el central
    $esPortalCentral = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'portal.grupohuerta.mx') !== false);
    if (!$esPortalCentral && $pdo) {
        try {
            $urlCentral = 'https://portal.grupohuerta.mx/api_notificaciones.php?action=listar_remoto&token=GH_NOTIFICACIONES_SEGURA_2026';
            $ctx = stream_context_create([
                'http' => ['timeout' => 2, 'header' => "User-Agent: PortalAgenciaNotif/1.0\r\n"],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);
            $raw = @file_get_contents($urlCentral, false, $ctx);
            if ($raw) {
                $remotoJson = json_decode($raw, true);
                if (!empty($remotoJson['exito']) && !empty($remotoJson['notificaciones'])) {
                    foreach ($remotoJson['notificaciones'] as $rn) {
                        $stmtCheck = $pdo->prepare("SELECT id FROM notificaciones WHERE modulo = ? AND tipo = ? AND referencia_id = ?");
                        $stmtCheck->execute([$rn['modulo'], $rn['tipo'], $rn['referencia_id']]);
                        if (!$stmtCheck->fetch()) {
                            $stmtIns = $pdo->prepare("
                                INSERT INTO notificaciones (modulo, tipo, titulo, mensaje, enlace, icono, color, referencia_id, creado_en, estatus)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                            ");
                            $stmtIns->execute([
                                $rn['modulo'],
                                $rn['tipo'],
                                $rn['titulo'],
                                $rn['mensaje'],
                                $rn['enlace'] ?? '',
                                $rn['icono'] ?? 'bi-bell-fill',
                                $rn['color'] ?? 'primary',
                                $rn['referencia_id'] ?? null,
                                $rn['creado_en'] ?? date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        } catch (Throwable $eSync) {}
    }

    $resultado = obtenerNotificacionesUsuario($usuarioId, 30, $pdo);
    echo json_encode([
        'exito' => true,
        'notificaciones' => $resultado['notificaciones'],
        'total_no_leidas' => $resultado['total_no_leidas']
    ]);
    exit();
}

if ($action === 'marcar_leida') {
    $notifId = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    $ok = marcarNotificacionLeida($notifId, $usuarioId, $pdo);
    echo json_encode(['exito' => $ok]);
    exit();
}

if ($action === 'marcar_todas') {
    $ok = marcarTodasNotificacionesLeidas($usuarioId, $pdo);
    echo json_encode(['exito' => $ok]);
    exit();
}

echo json_encode(['exito' => false, 'error' => 'Acción no soportada.']);
exit();
