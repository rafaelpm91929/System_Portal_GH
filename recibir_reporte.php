<?php
// Endpoint en cPanel (divolavilla.com) para recibir los reportes desde la red local
header('Content-Type: application/json');

define('TOKEN_SECRETO', 'GedasDivolavilla2026!');
$archivoCache = __DIR__ . '/reporte_cache.json';

// Leer payload JSON recibido
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "mensaje" => "Payload JSON inválido o vacío."]);
    exit;
}

// Validar Token de Seguridad
if (!isset($data['token']) || $data['token'] !== TOKEN_SECRETO) {
    http_response_code(401);
    echo json_encode(["status" => "error", "mensaje" => "Acceso no autorizado: Token inválido."]);
    exit;
}

// Guardar los datos en el archivo de caché local en cPanel
unset($data['token']); // Eliminar el token antes de guardar en disco
if (file_put_contents($archivoCache, json_encode($data, JSON_PRETTY_PRINT))) {
    echo json_encode([
        "status" => "ok",
        "mensaje" => "Reporte actualizado con éxito en Divolavilla.",
        "timestamp" => date('Y-m-d H:i:s')
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "mensaje" => "No se pudo escribir el archivo de caché en cPanel."]);
}
?>
