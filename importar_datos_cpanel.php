<?php
session_start();
require_once __DIR__ . '/conexion.php';

// Verificación de seguridad básica (Sesión activa de Admin o Clave de Seguridad)
$clave_maestra = 'restaurar2026';
$autorizado = false;

if (isset($_SESSION['usuario_rol']) && in_array(strtolower($_SESSION['usuario_rol']), ['admin', 'superadmin', 'administrador'])) {
    $autorizado = true;
}
if (isset($_REQUEST['key']) && $_REQUEST['key'] === $clave_maestra) {
    $autorizado = true;
}

$archivo_sql = __DIR__ . '/respaldo_completo_para_cpanel.sql';
$mensaje = '';
$tipo_mensaje = '';
$detalles_ejecucion = [];

if ($autorizado && isset($_POST['ejecutar_restauracion'])) {
    if (!file_exists($archivo_sql)) {
        $mensaje = "El archivo de respaldo '$archivo_sql' no fue encontrado en el servidor.";
        $tipo_mensaje = "danger";
    } elseif (!$pdo) {
        $mensaje = "No hay conexión PDO activa con la base de datos MySQL.";
        $tipo_mensaje = "danger";
    } else {
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $pdo->exec("SET NAMES utf8mb4;");

            // Leer archivo SQL línea por línea para ejecutar sentencias limpiamente
            $handle = fopen($archivo_sql, "r");
            if ($handle) {
                $query_actual = '';
                $total_ejecutadas = 0;
                $tablas_procesadas = [];

                while (($line = fgets($handle)) !== false) {
                    $line_trim = trim($line);
                    // Omitir comentarios de línea completa y líneas vacías
                    if ($line_trim === '' || strpos($line_trim, '--') === 0 || strpos($line_trim, '/*') === 0) {
                        continue;
                    }

                    $query_actual .= $line;

                    // Si termina en punto y coma, ejecutar
                    if (substr(rtrim($line_trim), -1) === ';') {
                        try {
                            $pdo->exec($query_actual);
                            $total_ejecutadas++;

                            if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?([a-zA-Z0-9_]+)`?/i', $query_actual, $m)) {
                                $tablas_procesadas[$m[1]] = ($tablas_procesadas[$m[1]] ?? 0);
                            } elseif (preg_match('/INSERT INTO `?([a-zA-Z0-9_]+)`?/i', $query_actual, $m)) {
                                $tablas_procesadas[$m[1]] = ($tablas_procesadas[$m[1]] ?? 0) + 1;
                            }
                        } catch (PDOException $ex) {
                            $detalles_ejecucion[] = [
                                'estado' => 'warning',
                                'detalle' => "Aviso en sentencia: " . substr($query_actual, 0, 80) . "... -> " . $ex->getMessage()
                            ];
                        }
                        $query_actual = '';
                    }
                }
                fclose($handle);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                $mensaje = "¡Base de datos restaurada con éxito! Se ejecutaron $total_ejecutadas sentencias SQL.";
                $tipo_mensaje = "success";
                
                foreach ($tablas_procesadas as $tabla => $filas) {
                    $detalles_ejecucion[] = [
                        'estado' => 'success',
                        'detalle' => "Tabla `$tabla` procesada correctamente (" . ($filas > 0 ? "$filas registros insertados/actualizados" : "estructura creada") . ")."
                    ];
                }
            } else {
                $mensaje = "No se pudo abrir el archivo SQL para lectura.";
                $tipo_mensaje = "danger";
            }
        } catch (Throwable $e) {
            $mensaje = "Error crítico durante la restauración: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurador de Base de Datos - Portal Divol</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #0f172a; color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card-custom { background: #1e293b; border: 1px solid #334155; border-radius: 12px; }
        .log-box { background: #090d16; border: 1px solid #1e293b; border-radius: 8px; max-height: 380px; overflow-y: auto; font-family: monospace; font-size: 0.85rem; }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 820px;">
    
    <div class="text-center mb-4">
        <h2 class="fw-bold text-primary"><i class="bi bi-database-fill-gear me-2"></i>Restaurador de Base de Datos</h2>
        <p class="text-secondary">Sincroniza y restaura todas las tablas y datos locales hacia MySQL cPanel (divolavilla_sistemas)</p>
    </div>

    <?php if (!$autorizado): ?>
        <div class="card card-custom p-4 shadow">
            <div class="text-center mb-3">
                <i class="bi bi-shield-lock text-warning" style="font-size: 3rem;"></i>
                <h4 class="mt-2">Acceso Protegido</h4>
                <p class="text-muted small">Inicia sesión en el portal como administrador o ingresa la clave de confirmación para proceder.</p>
            </div>
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Clave de Seguridad:</label>
                    <input type="password" name="key" class="form-control bg-dark text-white border-secondary" placeholder="Ingresa la clave..." required>
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-bold">
                    <i class="bi bi-unlock-fill me-1"></i> Continuar
                </button>
            </form>
        </div>
    <?php else: ?>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show shadow" role="alert">
                <i class="bi bi-<?= $tipo_mensaje === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
                <strong><?= $mensaje ?></strong>
            </div>
        <?php endif; ?>

        <div class="card card-custom p-4 shadow mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold"><i class="bi bi-file-earmark-code me-2 text-info"></i>Archivo de Respaldo Local</h5>
                <span class="badge bg-success">Listo para Restaurar</span>
            </div>
            
            <p class="text-light mb-2">
                <strong>Archivo:</strong> <code>respaldo_completo_para_cpanel.sql</code><br>
                <strong>Tamaño:</strong> <?= file_exists($archivo_sql) ? round(filesize($archivo_sql)/1024, 2) . ' KB' : 'No encontrado' ?><br>
                <strong>Contenido:</strong> Usuarios, Permisos, Inventario (Equipos, Impresoras, Celulares/Tablets, DVR/Cámaras, Teléfonos PoE, Switches, Nodos de Red, VLANs, etc.)
            </p>

            <hr class="border-secondary my-3">

            <div class="d-flex flex-wrap gap-2">
                <form method="POST" action="" onsubmit="return confirm('¿Seguro que deseas restaurar la base de datos completa con todos los datos registrados?');">
                    <input type="hidden" name="key" value="<?= htmlspecialchars($_REQUEST['key'] ?? $clave_maestra) ?>">
                    <input type="hidden" name="ejecutar_restauracion" value="1">
                    <button type="submit" class="btn btn-success fw-bold px-4 py-2">
                        <i class="bi bi-arrow-repeat me-1"></i> Restaurar / Actualizar Todo en MySQL
                    </button>
                </form>

                <a href="respaldo_completo_para_cpanel.sql" download class="btn btn-outline-info px-3 py-2">
                    <i class="bi bi-download me-1"></i> Descargar SQL
                </a>

                <a href="equipos.php" class="btn btn-outline-light px-3 py-2 ms-auto">
                    <i class="bi bi-arrow-left me-1"></i> Ir al Portal
                </a>
            </div>
        </div>

        <?php if (!empty($detalles_ejecucion)): ?>
            <div class="card card-custom p-3 shadow">
                <h6 class="fw-bold mb-2 text-light"><i class="bi bi-terminal me-2"></i>Registro de Ejecución:</h6>
                <div class="log-box p-3">
                    <?php foreach ($detalles_ejecucion as $det): ?>
                        <div class="text-<?= $det['estado'] === 'success' ? 'success' : 'warning' ?> py-1">
                            <i class="bi bi-<?= $det['estado'] === 'success' ? 'check2' : 'exclamation-circle' ?> me-1"></i>
                            <?= htmlspecialchars($det['detalle']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>
</body>
</html>
