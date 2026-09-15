<?php
// Endpoint Seguro en cPanel (portal.grupohuerta.mx) para recibir reportes de Agencias
header('Content-Type: application/json');

include_once 'conexion.php';

// Tokens de Seguridad por Agencia
$tokens_validos = [
    'Divolavilla' => 'GedasDivolavilla2026!',
    'SeatLaVilla' => 'GedasSeat2026!',
    'CupraGarage' => 'GedasCupra2026!',
    'Central'     => 'GedasCentral2026!'
];

$archivoCache = __DIR__ . '/reporte_cache.json';

// Leer payload JSON recibido
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "mensaje" => "Payload JSON inválido o vacío."]);
    exit;
}

$agencia = $data['agencia'] ?? 'Divolavilla';
$agencia_slug = strtolower(str_replace(' ', '', $agencia));
$token_recibido = $data['token'] ?? '';

// Validar Token de Seguridad
$token_esperado = $tokens_validos[$agencia] ?? $tokens_validos['Divolavilla'];
if ($token_recibido !== $token_esperado) {
    http_response_code(401);
    echo json_encode(["status" => "error", "mensaje" => "Acceso no autorizado: Token inválido para la agencia $agencia."]);
    exit;
}

unset($data['token']); // Eliminar token por seguridad antes de almacenar

$archivoCache = __DIR__ . "/reporte_cache_{$agencia_slug}.json";

// Guardar en Base de Datos MySQL (grupohue_sistemas) si la conexión está activa
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO reportes_agencias (agencia, datos_json, total_equipos, total_servicios, fecha_reporte)
            VALUES (:agencia, :datos_json, :total_equipos, :total_servicios, NOW())
            ON DUPLICATE KEY UPDATE 
                datos_json = VALUES(datos_json),
                total_equipos = VALUES(total_equipos),
                total_servicios = VALUES(total_servicios),
                fecha_reporte = NOW()
        ");

        $total_equipos = count($data['inventario'] ?? []);
        $total_servicios = count($data['ordenes_servicio'] ?? []);

        $stmt->execute([
            'agencia' => $agencia,
            'datos_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'total_equipos' => $total_equipos,
            'total_servicios' => $total_servicios
        ]);
        $db_guardado = true;
    } catch (Exception $e) {
        // Log interno de error de BD si es necesario
    }
}

// Guardar siempre una copia en caché local en disco
file_put_contents($archivoCache, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    "status" => "ok",
    "mensaje" => "Reporte de $agencia consolidado con éxito en Portal Maestro Grupo Huerta.",
    "db_guardado" => $db_guardado,
    "timestamp" => date('Y-m-d H:i:s')
]);
?>
