<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Despliegue - Portal de Sistemas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container { max-width: 600px; margin-top: 80px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
        .status-icon { font-size: 3rem; color: #198754; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card text-center p-4">
            <div class="card-body">
                <div class="status-icon mb-3">✅</div>
                <h3 class="card-title text-dark fw-bold">¡Servidor Conectado y Funcionando!</h3>
                <p class="card-text text-muted">
                    El directorio <code>public_html/sistemas</code> en tu cPanel está respondiendo correctamente.
                </p>
                <div class="alert alert-success d-inline-block px-4 py-2 mt-2" role="alert">
                    <strong>Estado del Servidor:</strong> HTTP 200 OK
                </div>
                <hr class="my-4">
                <div class="small text-secondary">
                    <div><strong>Servidor:</strong> <?php echo $_SERVER['SERVER_NAME'] ?? 'cPanel'; ?></div>
                    <div><strong>Fecha y hora del servidor:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
                    <div><strong>PHP Version:</strong> <?php echo phpversion(); ?></div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
