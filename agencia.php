<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión y Permisos
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
if (!in_array($rolActual, ['superadmin', 'admin'])) {
    requerirPermiso('agencia', 'puede_ver');
}

// Auto-asegurar existencia de las tablas de permisos y agencias
if ($pdo) {
    asegurarTablasPermisos($pdo);
    if (function_exists('asegurarTablasReportesAgencia')) {
        asegurarTablasReportesAgencia($pdo);
    }
}

$mensaje = '';
$error = '';

// Cargar Ficha de la Única Agencia Registrada en el Sistema
$agenciaData = null;
if ($pdo) {
    try {
        // Cargar siempre la agencia registrada (Fila 1)
        $stmtFirst = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
        $agenciaData = $stmtFirst ? $stmtFirst->fetch(PDO::FETCH_ASSOC) : null;

        // Si la tabla agencias estuviera vacía, crear el registro inicial
        if (!$agenciaData) {
            $stmtInsert = $pdo->prepare("
                INSERT INTO agencias (nombre, razon_social, rfc, direccion, encargado_sistemas, telefono_sistemas, correo_sistemas)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                'Agencia Grupo Huerta',
                'Grupo Huerta Automotriz S.A. de C.V.',
                'GHA850101XXX',
                'Av. Insurgentes Sur #1234, Col. Del Valle, Benito Juárez, CDMX, C.P. 03100',
                'Ing. Juan Pérez',
                '55 1234 5678 Ext. 101',
                'sistemas@grupohuerta.mx'
            ]);

            $stmtFirst = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
            $agenciaData = $stmtFirst->fetch(PDO::FETCH_ASSOC);
        }

        // Sincronizar variable de sesión con el nombre de la agencia registrada
        if ($agenciaData && !empty($agenciaData['nombre'])) {
            $_SESSION['agencia'] = $agenciaData['nombre'];
            $agenciaNombreActual = $agenciaData['nombre'];
        }
    } catch (PDOException $e) {
        $error = "Error al consultar los datos de la agencia: " . $e->getMessage();
    }
}

// PROCESAMIENTO DE ACCIONES POST (EDICIÓN, SUBIDA DE ARCHIVOS Y GESTIÓN DE ÁREAS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // ACCIÓN: ACTUALIZAR FICHA DE AGENCIA Y SUBIR LOGO / FOTO
    if ($accion === 'actualizar_agencia') {
        $agencia_id = intval($_POST['agencia_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $razon_social = trim($_POST['razon_social'] ?? '');
        $rfc = trim($_POST['rfc'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $encargado = trim($_POST['encargado_sistemas'] ?? '');
        $telefono = trim($_POST['telefono_sistemas'] ?? '');
        $correo = trim($_POST['correo_sistemas'] ?? '');
        $mapsUrl = trim($_POST['maps_url'] ?? '');
        $colorTema = trim($_POST['color_tema'] ?? ($agenciaData['color_tema'] ?? 'azul'));

        if (empty($nombre) || empty($razon_social) || empty($rfc)) {
            $error = "Por favor completa los campos obligatorios: Nombre, Razón Social y RFC.";
        } else {
            try {
                $logoUrl = $agenciaData['logo_url'] ?? '';
                $fotoUrl = $agenciaData['foto_url'] ?? '';

                // Directorio para guardar uploads
                $uploadDir = 'uploads/agencias/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                // 1. Procesar Subida de LOGOTIPO
                if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                    $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
                    if (in_array($ext, $permitidas)) {
                        $nuevoNombreLogo = 'logo_' . ($agencia_id > 0 ? $agencia_id : time()) . '_' . time() . '.' . $ext;
                        $destLogo = $uploadDir . $nuevoNombreLogo;
                        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $destLogo)) {
                            $logoUrl = $destLogo;
                        }
                    } else {
                        $error = "El formato de imagen del logotipo no es válido. (Permitidos: JPG, PNG, WEBP, SVG)";
                    }
                }

                // 2. Procesar Subida de FOTOGRAFÍA DE LA AGENCIA
                if (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto_file']['name'], PATHINFO_EXTENSION));
                    $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    if (in_array($ext, $permitidas)) {
                        $nuevoNombreFoto = 'foto_' . ($agencia_id > 0 ? $agencia_id : time()) . '_' . time() . '.' . $ext;
                        $destFoto = $uploadDir . $nuevoNombreFoto;
                        if (move_uploaded_file($_FILES['foto_file']['tmp_name'], $destFoto)) {
                            $fotoUrl = $destFoto;
                        }
                    } else {
                        $error = "El formato de la fotografía de la agencia no es válido. (Permitidos: JPG, PNG, WEBP)";
                    }
                }

                if (empty($error)) {
                    $nombreAnterior = $agenciaData['nombre'] ?? '';
                    if ($agencia_id > 0) {
                        try { $pdo->exec("ALTER TABLE agencias ADD COLUMN color_tema VARCHAR(50) DEFAULT 'azul'"); } catch (Throwable $t) {}
                        $stmtUpd = $pdo->prepare("
                            UPDATE agencias 
                            SET nombre=?, razon_social=?, rfc=?, direccion=?, encargado_sistemas=?, telefono_sistemas=?, correo_sistemas=?, logo_url=?, foto_url=?, maps_url=?, color_tema=? 
                            WHERE id=?
                        ");
                        $stmtUpd->execute([$nombre, $razon_social, $rfc, $direccion, $encargado, $telefono, $correo, $logoUrl, $fotoUrl, $mapsUrl, $colorTema, $agencia_id]);
                        $_SESSION['color_tema'] = $colorTema;
                    }

                    // Sincronizar automáticamente A TODOS LOS USUARIOS con el nombre de la agencia registrada
                    try {
                        $stmtUpdUsers = $pdo->prepare("UPDATE usuarios SET agencia = ?");
                        $stmtUpdUsers->execute([$nombre]);
                    } catch (Throwable $tU) {}

                    $_SESSION['agencia'] = $nombre;
                    $agenciaNombreActual = $nombre;

                    // Recargar datos actualizados de la agencia
                    $stmt = $pdo->prepare("SELECT * FROM agencias WHERE id=? LIMIT 1");
                    $stmt->execute([$agencia_id]);
                    $agenciaData = $stmt->fetch(PDO::FETCH_ASSOC);

                    $mensaje = "¡Ficha corporativa de <strong>" . htmlspecialchars($nombre) . "</strong> actualizada con éxito!";
                }

            } catch (PDOException $e) {
                $error = "Error al actualizar la agencia: " . $e->getMessage();
            }
        }
    }

    // ACCIÓN: CAMBIAR COLOR INSTITUCIONAL DEL TEMA OBSCURO
    elseif ($accion === 'cambiar_color_tema') {
        $agencia_id = intval($_POST['agencia_id'] ?? 0);
        $colorTema = trim($_POST['color_tema'] ?? 'azul');
        if ($agencia_id > 0) {
            try {
                try { $pdo->exec("ALTER TABLE agencias ADD COLUMN color_tema VARCHAR(50) DEFAULT 'azul'"); } catch (Throwable $t) {}
                $stmtUpd = $pdo->prepare("UPDATE agencias SET color_tema = ? WHERE id = ?");
                $stmtUpd->execute([$colorTema, $agencia_id]);
                $_SESSION['color_tema'] = $colorTema;

                $catalogo = function_exists('obtenerCatalogoTemasOscuros') ? obtenerCatalogoTemasOscuros() : [];
                $nombreTema = $catalogo[$colorTema]['nombre'] ?? $colorTema;
                $mensaje = "Paleta de color obscuro cambiada a <strong>" . htmlspecialchars($nombreTema) . "</strong> con éxito para el login y todos los módulos.";

                // Recargar datos actualizados de la agencia
                $stmt = $pdo->prepare("SELECT * FROM agencias WHERE id=? LIMIT 1");
                $stmt->execute([$agencia_id]);
                $agenciaData = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error = "Error al actualizar el color: " . $e->getMessage();
            }
        }
    }

    // ACCIÓN: AGREGAR ÁREA A LA AGENCIA
    elseif ($accion === 'agregar_area') {
        $agencia_id = intval($_POST['agencia_id'] ?? 0);
        $nombre_area = trim($_POST['nombre_area'] ?? '');

        if ($agencia_id > 0 && !empty($nombre_area)) {
            try {
                $stmtIns = $pdo->prepare("INSERT INTO agencia_areas (agencia_id, nombre, estatus) VALUES (?, ?, 1)");
                $stmtIns->execute([$agencia_id, $nombre_area]);
                $mensaje = "Área <strong>" . htmlspecialchars($nombre_area) . "</strong> registrada exitosamente.";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'UNIQUE') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                    $error = "El área <strong>" . htmlspecialchars($nombre_area) . "</strong> ya se encuentra registrada en esta agencia.";
                } else {
                    $error = "Error al agregar el área: " . $e->getMessage();
                }
            }
        }
    }

    // ACCIÓN: EDITAR NOMBRE DE ÁREA
    elseif ($accion === 'editar_area') {
        $area_id = intval($_POST['area_id'] ?? 0);
        $nuevo_nombre = trim($_POST['nuevo_nombre'] ?? '');

        if ($area_id > 0 && !empty($nuevo_nombre)) {
            try {
                $stmtUpd = $pdo->prepare("UPDATE agencia_areas SET nombre = ? WHERE id = ?");
                $stmtUpd->execute([$nuevo_nombre, $area_id]);
                $mensaje = "Área actualizada correctamente a <strong>" . htmlspecialchars($nuevo_nombre) . "</strong>.";
            } catch (PDOException $e) {
                $error = "Error al editar el área: " . $e->getMessage();
            }
        }
    }

    // ACCIÓN: TOGGLE ESTATUS DE ÁREA
    elseif ($accion === 'toggle_area_status') {
        $area_id = intval($_POST['area_id'] ?? 0);
        $nuevo_estatus = intval($_POST['nuevo_estatus'] ?? 1);

        if ($area_id > 0) {
            try {
                $stmtUpd = $pdo->prepare("UPDATE agencia_areas SET estatus = ? WHERE id = ?");
                $stmtUpd->execute([$nuevo_estatus, $area_id]);
                $mensaje = "Estatus de área modificado correctamente.";
            } catch (PDOException $e) {
                $error = "Error al cambiar estatus del área: " . $e->getMessage();
            }
        }
    }

    // ACCIÓN: ELIMINAR ÁREA
    elseif ($accion === 'eliminar_area') {
        $area_id = intval($_POST['area_id'] ?? 0);

        if ($area_id > 0) {
            try {
                $stmtDel = $pdo->prepare("DELETE FROM agencia_areas WHERE id = ?");
                $stmtDel->execute([$area_id]);
                $mensaje = "Área eliminada correctamente de la agencia.";
            } catch (PDOException $e) {
                $error = "Error al eliminar el área: " . $e->getMessage();
            }
        }
    }

    // ACCIÓN: GUARDAR CONFIGURACIÓN DE CONEXIÓN AL SERVIDOR LOCAL / DMS
    elseif ($accion === 'guardar_servidor_local') {
        $agencia_id   = intval($_POST['agencia_id'] ?? ($agenciaData['id'] ?? 1));
        $local_host   = trim($_POST['local_db_host'] ?? '127.0.0.1');
        $local_port   = intval($_POST['local_db_port'] ?? 3306);
        $local_name   = trim($_POST['local_db_name'] ?? '');
        $local_user   = trim($_POST['local_db_user'] ?? '');
        $local_pass   = trim($_POST['local_db_pass'] ?? '');
        $local_tipo   = strtolower(trim($_POST['local_tipo_db'] ?? 'mysql'));
        $local_token  = trim($_POST['local_api_token'] ?? '');
        $local_srv_url= trim($_POST['local_servidor_url'] ?? '');

        if ($local_port <= 0) {
            $local_port = ($local_tipo === 'sqlserver') ? 1433 : 3306;
        }

        if (empty($local_token)) {
            $local_token = 'TK_LOCAL_' . strtoupper(bin2hex(random_bytes(8)));
        }

        try {
            $stmtUpdLoc = $pdo->prepare("
                UPDATE agencias 
                SET local_db_host = ?, local_db_port = ?, local_db_name = ?, local_db_user = ?, local_db_pass = ?, local_tipo_db = ?, local_api_token = ?, local_servidor_url = ?
                WHERE id = ?
            ");
            $stmtUpdLoc->execute([$local_host, $local_port, $local_name, $local_user, $local_pass, $local_tipo, $local_token, $local_srv_url, $agencia_id]);

            // Recargar datos actualizados
            $stmt = $pdo->prepare("SELECT * FROM agencias WHERE id=? LIMIT 1");
            $stmt->execute([$agencia_id]);
            $agenciaData = $stmt->fetch(PDO::FETCH_ASSOC);

            $mensaje = "¡Configuración de conexión al Servidor Local guardada exitosamente!";
        } catch (PDOException $e) {
            $error = "Error al guardar configuración de servidor local: " . $e->getMessage();
        }
    }
}

// ====================================================================
// DESCARGA AUTOMÁTICA DEL CONECTOR PHP PARA SERVIDOR LOCAL
// ====================================================================
if (isset($_GET['descargar_conector_local'])) {
    $hostLocal  = !empty($agenciaData['local_db_host']) ? $agenciaData['local_db_host'] : '127.0.0.1';
    $portLocal  = !empty($agenciaData['local_db_port']) ? intval($agenciaData['local_db_port']) : (($tipoLocal === 'sqlserver') ? 1433 : 3306);
    $dbLocal    = !empty($agenciaData['local_db_name']) ? $agenciaData['local_db_name'] : 'dms_agencia';
    $userLocal  = !empty($agenciaData['local_db_user']) ? $agenciaData['local_db_user'] : 'root';
    $passLocal  = !empty($agenciaData['local_db_pass']) ? $agenciaData['local_db_pass'] : '';
    $tipoLocal  = !empty($agenciaData['local_tipo_db']) ? $agenciaData['local_tipo_db'] : 'mysql';
    $tokenLocal = !empty($agenciaData['local_api_token']) ? $agenciaData['local_api_token'] : 'TK_LOCAL_DEFAULT_2026';

    $protocolo    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
    $hostServidor = $_SERVER['HTTP_HOST'] ?? 'portal.divolavilla.com';
    $directorio   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $urlReceptor  = $protocolo . $hostServidor . ($directorio ? $directorio : '') . '/api_receptor_reportes_locales.php';

    $template = file_get_contents(__DIR__ . '/conector_servidor_local.php');
    if ($template) {
        $template = preg_replace("/\\\$LOCAL_DB_HOST\s*=\s*'.*?';/", "\$LOCAL_DB_HOST = '" . addslashes($hostLocal) . "';", $template);
        $template = preg_replace("/\\\$LOCAL_DB_PORT\s*=\s*\d+;/", "\$LOCAL_DB_PORT = " . intval($portLocal) . ";", $template);
        $template = preg_replace("/\\\$LOCAL_DB_NAME\s*=\s*'.*?';/", "\$LOCAL_DB_NAME = '" . addslashes($dbLocal) . "';", $template);
        $template = preg_replace("/\\\$LOCAL_DB_USER\s*=\s*'.*?';/", "\$LOCAL_DB_USER = '" . addslashes($userLocal) . "';", $template);
        $template = preg_replace("/\\\$LOCAL_DB_PASS\s*=\s*'.*?';/", "\$LOCAL_DB_PASS = '" . addslashes($passLocal) . "';", $template);
        $template = preg_replace("/\\\$LOCAL_DB_TYPE\s*=\s*'.*?';/", "\$LOCAL_DB_TYPE = '" . addslashes($tipoLocal) . "';", $template);
        $template = preg_replace("/\\\$LOCAL_API_TOKEN\s*=\s*'.*?';/", "\$LOCAL_API_TOKEN = '" . addslashes($tokenLocal) . "';", $template);
        $template = preg_replace("/\\\$CPANEL_RECEPTOR_URL\s*=\s*'.*?';/", "\$CPANEL_RECEPTOR_URL = '" . addslashes($urlReceptor) . "';", $template);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="conector_servidor_local.php"');
    header('Content-Length: ' . strlen($template));
    echo $template;
    exit();
}

// Auto-sembrado de Áreas Predeterminadas (Administración, Servicio, Refacciones, Ventas, HyP, CRM)
$areasAgencia = [];
if ($agenciaData && isset($agenciaData['id'])) {
    try {
        $stmtCountArea = $pdo->prepare("SELECT COUNT(*) FROM agencia_areas WHERE agencia_id = ?");
        $stmtCountArea->execute([$agenciaData['id']]);
        $totalAreas = intval($stmtCountArea->fetchColumn());

        if ($totalAreas === 0) {
            $areasPredeterminadas = ['Administración', 'Servicio', 'Refacciones', 'Ventas', 'HyP', 'CRM', 'Sistemas'];
            $stmtInsArea = $pdo->prepare("INSERT INTO agencia_areas (agencia_id, nombre, estatus) VALUES (?, ?, 1)");
            foreach ($areasPredeterminadas as $nomArea) {
                try {
                    $stmtInsArea->execute([$agenciaData['id'], $nomArea]);
                } catch (Throwable $tArea) {}
            }
        }

        // Consultar catálogo de áreas de esta agencia
        $stmtAreas = $pdo->prepare("SELECT * FROM agencia_areas WHERE agencia_id = ? ORDER BY id ASC");
        $stmtAreas->execute([$agenciaData['id']]);
        $areasAgencia = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignorar error secundario
    }
}

// Cargar catálogo de temas oscuros y tema activo
$catalogoTemas = function_exists('obtenerCatalogoTemasOscuros') ? obtenerCatalogoTemasOscuros() : [];
$temaActivo = function_exists('obtenerTemaColorActivo') ? obtenerTemaColorActivo($pdo) : ($catalogoTemas['azul'] ?? []);
$colorClaveActual = $agenciaData['color_tema'] ?? ($temaActivo['clave'] ?? 'azul');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datos de la Agencia - Portal de Sistemas Grupo Huerta</title>
    <?php include_once 'pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #040d1a;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 60px;
        }
        .top-navbar {
            background: rgba(10, 25, 46, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 15px 35px;
        }
        .hero-banner {
            position: relative;
            min-height: 240px;
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(135deg, #0f2b48 0%, #07152b 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            margin-bottom: 30px;
        }
        .hero-bg-img {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-size: cover;
            background-position: center;
            opacity: 0.35;
            filter: blur(1px);
        }
        .hero-overlay {
            position: relative;
            z-index: 2;
            padding: 35px;
            display: flex;
            align-items: center;
            gap: 25px;
            background: linear-gradient(90deg, rgba(4, 13, 26, 0.92) 0%, rgba(4, 13, 26, 0.6) 100%);
            min-height: 240px;
        }
        .logo-container {
            width: 120px;
            height: 120px;
            border-radius: 18px;
            background: #0a192e;
            border: 2px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
            flex-shrink: 0;
        }
        .logo-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            padding: 8px;
        }
        .card-custom {
            background: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 24px;
            height: 100%;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .card-custom:hover {
            border-color: rgba(37, 99, 235, 0.4);
            transform: translateY(-3px);
        }
        .label-custom {
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .val-custom {
            color: #ffffff;
            font-size: 1.05rem;
            font-weight: 600;
        }
        .modal-content {
            background-color: #0a192e;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 18px;
        }
        .modal-header, .modal-footer {
            border-color: rgba(255, 255, 255, 0.08);
        }
        .form-control, .form-select {
            background-color: #0f223d;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }
        .form-control:focus, .form-select:focus {
            background-color: #132a4b;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.25);
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Volver al Menú Principal
        </a>
        <span class="fw-bold fs-5">PORTAL DE SISTEMAS <span class="text-primary">| Datos de la Agencia</span></span>
    </div>
    <div>
        <span class="badge bg-primary p-2 fs-6"><i class="bi bi-building-fill me-1"></i> <?php echo htmlspecialchars($agenciaNombreActual); ?></span>
    </div>
</div>

<div class="container-fluid px-4" style="max-width: 1400px;">

    <!-- Mensajes de Notificación -->
    <?php if ($mensaje): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- HERO BANNER FICHA DE AGENCIA -->
    <div class="hero-banner">
        <?php if (!empty($agenciaData['foto_url']) && file_exists($agenciaData['foto_url'])): ?>
            <div class="hero-bg-img" style="background-image: url('<?php echo htmlspecialchars($agenciaData['foto_url']); ?>');"></div>
        <?php endif; ?>

        <div class="hero-overlay d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between">
            <div class="d-flex align-items-center gap-4">
                <div class="logo-container">
                    <?php if (!empty($agenciaData['logo_url']) && file_exists($agenciaData['logo_url'])): ?>
                        <img src="<?php echo htmlspecialchars($agenciaData['logo_url']); ?>" alt="Logotipo <?php echo htmlspecialchars($agenciaData['nombre']); ?>">
                    <?php else: ?>
                        <i class="bi bi-building-fill text-primary display-4"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="badge bg-info bg-opacity-25 text-info border border-info px-3 py-1 rounded-pill mb-2">
                        <i class="bi bi-shield-check me-1"></i> Sucursal Oficial
                    </span>
                    <h2 class="fw-bold mb-1 text-white display-6"><?php echo htmlspecialchars($agenciaData['nombre'] ?? 'Agencia Grupo Huerta'); ?></h2>
                    <p class="text-light opacity-75 fs-6 mb-0">
                        <i class="bi bi-file-text me-1 text-primary"></i> <?php echo htmlspecialchars($agenciaData['razon_social'] ?? 'Grupo Huerta Automotriz S.A. de C.V.'); ?>
                    </p>
                </div>
            </div>

            <div class="mt-3 mt-md-0">
                <button type="button" class="btn btn-primary rounded-3 px-4 py-2 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalEditarAgencia">
                    <i class="bi bi-pencil-square me-2"></i> Editar Datos de la Agencia
                </button>
            </div>
        </div>
    </div>

    <!-- TARJETAS CON INFORMACIÓN DETALLADA -->
    <div class="row g-4">
        
        <!-- CARD 1: DATOS FISCALES -->
        <div class="col-md-4">
            <div class="card-custom">
                <div class="d-flex align-items-center gap-3 mb-4 border-bottom border-secondary border-opacity-25 pb-3">
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-3 fs-4">
                        <i class="bi bi-card-heading"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-white">Datos Fiscales</h5>
                        <small class="text-secondary">Identificación y registro legal</small>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="label-custom">Nombre Comercial de la Agencia</div>
                    <div class="val-custom"><?php echo htmlspecialchars($agenciaData['nombre'] ?? 'N/A'); ?></div>
                </div>

                <div class="mb-3">
                    <div class="label-custom">Razón Social</div>
                    <div class="val-custom"><?php echo htmlspecialchars($agenciaData['razon_social'] ?? 'N/A'); ?></div>
                </div>

                <div>
                    <div class="label-custom">RFC de la Agencia</div>
                    <div class="val-custom font-monospace text-info"><?php echo htmlspecialchars($agenciaData['rfc'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- CARD 2: UBICACIÓN Y DIRECCIÓN (CON GOOGLE MAPS INTERACTIVO) -->
        <div class="col-md-4">
            <div class="card-custom d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom border-secondary border-opacity-25 pb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-success bg-opacity-10 text-success p-3 rounded-3 fs-4">
                                <i class="bi bi-geo-alt-fill"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0 text-white">Ubicación Física</h5>
                                <small class="text-secondary">Dirección completa y mapa</small>
                            </div>
                        </div>
                        <span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-2 py-1 small">
                            <i class="bi bi-circle-fill me-1" style="font-size: 0.4rem;"></i> Activa
                        </span>
                    </div>

                    <div class="mb-3">
                        <div class="label-custom">Dirección de la Agencia</div>
                        <div class="val-custom fs-6 text-light" style="line-height: 1.5;">
                            <?php echo nl2br(htmlspecialchars($agenciaData['direccion'] ?? 'No especificada')); ?>
                        </div>
                    </div>

                    <!-- MAPA INTERACTIVO GOOGLE MAPS -->
                    <?php
                        $customMaps = trim($agenciaData['maps_url'] ?? '');
                        $direccionBusqueda = trim($agenciaData['direccion'] ?? 'Agencia Grupo Huerta');
                        
                        if (!empty($customMaps) && (strpos($customMaps, 'http') === 0 || strpos($customMaps, 'https') === 0)) {
                            if (strpos($customMaps, 'output=embed') !== false || strpos($customMaps, 'google.com/maps/embed') !== false) {
                                $mapEmbedSrc = $customMaps;
                            } else {
                                $mapEmbedSrc = 'https://maps.google.com/maps?q=' . urlencode($customMaps) . '&t=&z=15&ie=UTF8&iwloc=&output=embed';
                            }
                        } else {
                            $mapEmbedSrc = 'https://maps.google.com/maps?q=' . urlencode($direccionBusqueda) . '&t=&z=15&ie=UTF8&iwloc=&output=embed';
                        }
                        $mapExternalUrl = !empty($customMaps) ? $customMaps : 'https://www.google.com/maps/search/?api=1&query=' . urlencode($direccionBusqueda);
                    ?>

                    <div class="my-3 rounded-3 overflow-hidden border border-secondary border-opacity-25 shadow-sm">
                        <iframe 
                            width="100%" 
                            height="180" 
                            style="border:0; display:block;" 
                            src="<?php echo htmlspecialchars($mapEmbedSrc); ?>" 
                            allowfullscreen 
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade">
                        </iframe>
                    </div>
                </div>

                <div class="pt-2 text-end">
                    <a href="<?php echo htmlspecialchars($mapExternalUrl); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success rounded-3 w-100">
                        <i class="bi bi-map-fill me-1"></i> Abrir mapa interactivo completo en Google Maps
                    </a>
                </div>
            </div>
        </div>

        <!-- CARD 3: ENCARGADO DE SISTEMAS -->
        <div class="col-md-4">
            <div class="card-custom">
                <div class="d-flex align-items-center gap-3 mb-4 border-bottom border-secondary border-opacity-25 pb-3">
                    <div class="bg-warning bg-opacity-10 text-warning p-3 rounded-3 fs-4">
                        <i class="bi bi-person-workspace"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-white">Encargado de Sistemas</h5>
                        <small class="text-secondary">Responsable de TI y soporte en la sucursal</small>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="label-custom">Nombre del Encargado de Sistemas</div>
                    <div class="val-custom text-warning"><?php echo htmlspecialchars($agenciaData['encargado_sistemas'] ?? 'No asignado'); ?></div>
                </div>

                <div class="mb-3">
                    <div class="label-custom">Correo Electrónico de Contacto</div>
                    <div class="val-custom text-light fs-6">
                        <i class="bi bi-envelope me-1 text-secondary"></i> <?php echo htmlspecialchars($agenciaData['correo_sistemas'] ?? 'sistemas@grupohuerta.mx'); ?>
                    </div>
                </div>

                <div>
                    <div class="label-custom">Teléfono / Conmutador / Extensión</div>
                    <div class="val-custom text-light fs-6">
                        <i class="bi bi-telephone me-1 text-secondary"></i> <?php echo htmlspecialchars($agenciaData['telefono_sistemas'] ?? 'No registrado'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 4: COLOR DEL PORTAL / TEMA OBSCURO -->
        <div class="col-12">
            <div class="card-custom">
                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 border-bottom border-secondary border-opacity-25 pb-3 mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 rounded-3 fs-4" style="background: rgba(255, 255, 255, 0.05); color: var(--portal-theme-accent, #38bdf8);">
                            <i class="bi bi-palette-fill"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0 text-white">Color Institucional del Portal</h5>
                            <small class="text-secondary">Paleta oscura aplicada automáticamente al fondo 3D del login, barras y módulos</small>
                        </div>
                    </div>
                    <div>
                        <span class="badge px-3 py-2 fs-6 rounded-pill d-inline-flex align-items-center gap-2" style="background: <?php echo $temaActivo['bg_deep'] ?? '#02070e'; ?>; border: 1px solid <?php echo $temaActivo['accent'] ?? '#38bdf8'; ?>; color: <?php echo $temaActivo['accent'] ?? '#38bdf8'; ?>;">
                            <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background: <?php echo $temaActivo['hex_swatch'] ?? '#0284c7'; ?>; box-shadow: 0 0 10px <?php echo $temaActivo['hex_swatch'] ?? '#0284c7'; ?>;"></span>
                            <?php echo htmlspecialchars($temaActivo['nombre'] ?? 'Azul Obscuro'); ?>
                        </span>
                    </div>
                </div>

                <div class="row g-3 align-items-center">
                    <div class="col-lg-4">
                        <p class="text-secondary small mb-0">
                            Haz clic en cualquiera de las tonalidades oscuras para cambiar el color del sistema al instante. Se sincronizará en tiempo real con el fondo del login interactivo y todas las secciones.
                        </p>
                    </div>
                    <div class="col-lg-8">
                        <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                            <?php foreach ($catalogoTemas as $claveT => $infoT): ?>
                                <?php $esSeleccionado = ($colorClaveActual === $claveT); ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="accion" value="cambiar_color_tema">
                                    <input type="hidden" name="agencia_id" value="<?php echo $agenciaData['id'] ?? 0; ?>">
                                    <input type="hidden" name="color_tema" value="<?php echo $claveT; ?>">
                                    <button type="submit" class="btn btn-sm rounded-pill px-3 py-2 d-inline-flex align-items-center gap-2 <?php echo $esSeleccionado ? 'fw-bold shadow' : 'btn-outline-secondary text-white'; ?>" style="<?php echo $esSeleccionado ? ('border: 2px solid ' . $infoT['accent'] . '; background: ' . $infoT['bg_deep'] . '; color: ' . $infoT['accent'] . ' !important;') : 'background: rgba(255,255,255,0.03);'; ?>">
                                        <span style="display:inline-block; width:14px; height:14px; border-radius:50%; background: <?php echo $infoT['hex_swatch']; ?>; box-shadow: 0 0 8px <?php echo $infoT['hex_swatch']; ?>;"></span>
                                        <span><?php echo htmlspecialchars($infoT['nombre']); ?></span>
                                        <?php if ($esSeleccionado): ?>
                                            <i class="bi bi-check-circle-fill ms-1" style="color: <?php echo $infoT['accent']; ?>;"></i>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- SECCIÓN: ÁREAS Y DEPARTAMENTOS DE LA AGENCIA -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card-custom">
                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between mb-4 border-bottom border-secondary border-opacity-25 pb-3 gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-info bg-opacity-10 text-info p-3 rounded-3 fs-4">
                            <i class="bi bi-diagram-3-fill"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0 text-white">Áreas y Departamentos de la Agencia</h5>
                            <small class="text-secondary">Catálogo oficial de áreas para asignación de equipos y personal de la sucursal</small>
                        </div>
                    </div>
                    <button type="button" class="btn btn-info text-dark fw-bold rounded-3 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAgregarArea">
                        <i class="bi bi-plus-circle me-1"></i> Agregar Nueva Área
                    </button>
                </div>

                <!-- LISTA DE ÁREAS REGISTRADAS -->
                <div class="row g-3">
                    <?php if (empty($areasAgencia)): ?>
                        <div class="col-12 text-center py-4 text-secondary">
                            <i class="bi bi-info-circle fs-4 d-block mb-2"></i> No hay áreas registradas aún. Haz clic en 'Agregar Nueva Área' para registrar la primera.
                        </div>
                    <?php else: ?>
                        <?php foreach ($areasAgencia as $area): ?>
                            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                <div class="p-3 rounded-3 text-center h-100 position-relative d-flex flex-column justify-content-between" style="background: #0f223d; border: 1px solid rgba(255, 255, 255, 0.1);">
                                    <div>
                                        <?php if ($area['estatus']): ?>
                                            <span class="position-absolute top-0 end-0 p-1 me-2 mt-2 bg-success rounded-circle" title="Área Activa" style="width: 8px; height: 8px;"></span>
                                        <?php else: ?>
                                            <span class="position-absolute top-0 end-0 p-1 me-2 mt-2 bg-secondary rounded-circle" title="Área Inactiva" style="width: 8px; height: 8px;"></span>
                                        <?php endif; ?>

                                        <div class="fs-4 text-primary mb-1">
                                            <i class="bi bi-building-gear"></i>
                                        </div>
                                        <div class="fw-bold text-white small mb-2 text-truncate" title="<?php echo htmlspecialchars($area['nombre']); ?>">
                                            <?php echo htmlspecialchars($area['nombre']); ?>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-center gap-1 pt-2 border-top border-secondary border-opacity-25">
                                        <!-- Botón Editar Nombre -->
                                        <button type="button" class="btn btn-sm btn-outline-warning p-1 lh-1" onclick='abrirModalEditarArea(<?php echo json_encode($area); ?>)' title="Editar nombre de área">
                                            <i class="bi bi-pencil-square" style="font-size: 0.75rem;"></i>
                                        </button>

                                        <!-- Botón Toggle Activo / Inactivo -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas cambiar el estatus de esta área?');">
                                            <input type="hidden" name="accion" value="toggle_area_status">
                                            <input type="hidden" name="area_id" value="<?php echo $area['id']; ?>">
                                            <input type="hidden" name="nuevo_estatus" value="<?php echo $area['estatus'] ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $area['estatus'] ? 'btn-outline-success' : 'btn-outline-secondary'; ?> p-1 lh-1" title="<?php echo $area['estatus'] ? 'Desactivar área' : 'Activar área'; ?>">
                                                <i class="bi <?php echo $area['estatus'] ? 'bi-check-circle-fill' : 'bi-dash-circle'; ?>" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </form>

                                        <!-- Botón Eliminar Área -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta área de la agencia?');">
                                            <input type="hidden" name="accion" value="eliminar_area">
                                            <input type="hidden" name="area_id" value="<?php echo $area['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger p-1 lh-1" title="Eliminar área">
                                                <i class="bi bi-trash-fill" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- SECCIÓN: CONEXIÓN A SERVIDOR LOCAL / DMS (REPORTES DE AGENCIA)            -->
    <!-- ========================================================================= -->
    <div class="row mt-4" id="servidor_local">
        <div class="col-12">
            <div class="card-custom" style="border-color: rgba(16, 185, 129, 0.35); box-shadow: 0 10px 30px rgba(0,0,0,0.4);">
                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between mb-4 border-bottom border-secondary border-opacity-25 pb-3 gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 rounded-3 fs-3" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">
                            <i class="bi bi-hdd-network-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h5 class="fw-bold mb-0 text-white">Conexión a Servidor Local / DMS (Reportes de Agencia)</h5>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-2 py-1 small">
                                    <i class="bi bi-shield-check me-1"></i> API Local
                                </span>
                            </div>
                            <small class="text-secondary">Configura los datos del servidor local (IP, usuario, contraseña y token) para extraer y transmitir reportes operativos hacia tu cPanel.</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="agencia.php?descargar_conector_local=1" class="btn btn-outline-success btn-sm rounded-3 px-3 py-2 text-white d-flex align-items-center gap-1.5 shadow-sm">
                            <i class="bi bi-cloud-arrow-down-fill text-success"></i> <span>Descargar API Automática (.php)</span>
                        </a>
                        <button type="button" class="btn btn-success text-dark fw-bold btn-sm rounded-3 px-3 py-2 d-flex align-items-center gap-1.5 shadow-sm" onclick="ejecutarPruebaConexionLocal()">
                            <i class="bi bi-activity"></i> <span>Hacer prueba de conexión a servidor local</span>
                        </button>
                    </div>
                </div>

                <!-- Resumen de Estado de Conexión -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08);">
                            <div class="label-custom">HOST / IP LOCAL</div>
                            <div class="val-custom text-info font-monospace fs-6">
                                <i class="bi bi-hdd-fill me-1"></i> <?php echo htmlspecialchars(($agenciaData['local_db_host'] ?? '127.0.0.1') . ':' . ($agenciaData['local_db_port'] ?? 3306)); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08);">
                            <div class="label-custom">BASE DE DATOS</div>
                            <div class="val-custom text-white font-monospace fs-6">
                                <i class="bi bi-database-fill me-1 text-primary"></i> <?php echo htmlspecialchars($agenciaData['local_db_name'] ?? 'dms_agencia'); ?> (<?php echo strtoupper(htmlspecialchars($agenciaData['local_tipo_db'] ?? 'mysql')); ?>)
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08);">
                            <div class="label-custom">TOKEN API LOCAL</div>
                            <div class="val-custom text-warning font-monospace small">
                                <i class="bi bi-key-fill me-1"></i> <?php echo htmlspecialchars(substr($agenciaData['local_api_token'] ?? 'TK_LOCAL_PENDIENTE', 0, 10) . '••••••••'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08);">
                            <div class="label-custom">ÚLTIMA SINCRONIZACIÓN</div>
                            <div class="val-custom text-light small">
                                <i class="bi bi-clock-history me-1 text-success"></i> <?php echo !empty($agenciaData['local_ultima_sincronizacion']) ? htmlspecialchars($agenciaData['local_ultima_sincronizacion']) : '<span class="text-secondary">Sin sincronizar aún</span>'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formulario de Configuración -->
                <form method="POST" id="formServidorLocal">
                    <input type="hidden" name="accion" value="guardar_servidor_local">
                    <input type="hidden" name="agencia_id" value="<?php echo $agenciaData['id'] ?? 1; ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">IP o Host del Servidor Local</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-hdd-network"></i></span>
                                <input type="text" name="local_db_host" id="cfg_local_host" class="form-control" placeholder="ej. 192.168.1.100 o 127.0.0.1" value="<?php echo htmlspecialchars($agenciaData['local_db_host'] ?? '127.0.0.1'); ?>" required>
                            </div>
                            <small class="text-secondary" style="font-size: 0.73rem;">IP donde está alojado el DMS o base de datos en la agencia.</small>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary">Puerto</label>
                            <input type="number" name="local_db_port" id="cfg_local_port" class="form-control" placeholder="3306" value="<?php echo htmlspecialchars($agenciaData['local_db_port'] ?? 3306); ?>" required>
                            <small class="text-secondary" style="font-size: 0.73rem;">3306 (MySQL) o 1433 (SQL Server).</small>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary">Motor de Base de Datos</label>
                            <select name="local_tipo_db" id="cfg_local_tipo" class="form-select">
                                <option value="mysql" <?php echo (($agenciaData['local_tipo_db'] ?? 'mysql') === 'mysql') ? 'selected' : ''; ?>>MySQL / MariaDB</option>
                                <option value="sqlserver" <?php echo (($agenciaData['local_tipo_db'] ?? '') === 'sqlserver') ? 'selected' : ''; ?>>Microsoft SQL Server</option>
                            </select>
                            <small class="text-secondary" style="font-size: 0.73rem;">Tipo de gestor de base de datos.</small>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary">Nombre de la Base de Datos</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-database"></i></span>
                                <input type="text" name="local_db_name" id="cfg_local_dbname" class="form-control" placeholder="ej. dms_agencia, intelisis" value="<?php echo htmlspecialchars($agenciaData['local_db_name'] ?? ''); ?>" required>
                            </div>
                            <small class="text-secondary" style="font-size: 0.73rem;">Nombre de la BD local a consultar.</small>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary">Usuario de la BD Local</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-person"></i></span>
                                <input type="text" name="local_db_user" id="cfg_local_user" class="form-control" placeholder="ej. root o user_dms" value="<?php echo htmlspecialchars($agenciaData['local_db_user'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary">Contraseña de la BD Local</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-lock"></i></span>
                                <input type="password" name="local_db_pass" id="cfg_local_pass" class="form-control" placeholder="••••••••" value="<?php echo htmlspecialchars($agenciaData['local_db_pass'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordLocal('cfg_local_pass', this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Token de Seguridad para la API</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary border-opacity-25 text-secondary"><i class="bi bi-shield-lock"></i></span>
                                <input type="text" name="local_api_token" id="cfg_local_token" class="form-control font-monospace text-warning" placeholder="Token Secreto" value="<?php echo htmlspecialchars($agenciaData['local_api_token'] ?? ''); ?>" required>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="generarNuevoTokenLocal()"><i class="bi bi-shuffle me-1"></i> Generar</button>
                            </div>
                            <small class="text-secondary" style="font-size: 0.73rem;">Clave secreta compartida entre el servidor local y este cPanel para validar cada reporte.</small>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3 mt-4 pt-3 border-top border-secondary border-opacity-25">
                        <div class="text-secondary small">
                            <i class="bi bi-info-circle-fill text-info me-1"></i> Al guardar, se actualizan los parámetros en la base de datos de la agencia y en el conector descargable.
                        </div>
                        <div class="d-flex gap-2 w-100 w-md-auto justify-content-end">
                            <a href="reportes_agencia.php" class="btn btn-outline-secondary text-white rounded-3 px-3">
                                <i class="bi bi-bar-chart-line-fill me-1"></i> Ir a Reportes Agencia
                            </a>
                            <button type="submit" class="btn btn-success text-dark fw-bold rounded-3 px-4 shadow">
                                <i class="bi bi-check-lg me-1"></i> Guardar Parámetros de Conexión
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<!-- =================================================== -->
<!-- MODAL: EDITAR DATOS Y SUBIR ARCHIVOS (LOGO Y FOTO)  -->
<!-- =================================================== -->
<div class="modal fade" id="modalEditarAgencia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="actualizar_agencia">
                <input type="hidden" name="agencia_id" value="<?php echo $agenciaData['id'] ?? 0; ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-pencil-square me-2"></i> Editar Ficha de la Agencia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Nombre de la Agencia</label>
                            <input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($agenciaData['nombre'] ?? ''); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">RFC de la Agencia</label>
                            <input type="text" name="rfc" class="form-control font-monospace" value="<?php echo htmlspecialchars($agenciaData['rfc'] ?? ''); ?>" placeholder="ej. GHA850101XXX" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-secondary">Razón Social</label>
                            <input type="text" name="razon_social" class="form-control" value="<?php echo htmlspecialchars($agenciaData['razon_social'] ?? ''); ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-secondary">Dirección Completa de la Sucursal</label>
                            <textarea name="direccion" class="form-control" rows="3" required><?php echo htmlspecialchars($agenciaData['direccion'] ?? ''); ?></textarea>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-secondary"><i class="bi bi-geo-alt me-1 text-success"></i> Enlace o Ubicación de Google Maps (Opcional)</label>
                            <input type="text" name="maps_url" class="form-control" value="<?php echo htmlspecialchars($agenciaData['maps_url'] ?? ''); ?>" placeholder="ej. https://maps.app.goo.gl/... o la dirección exacta para el mapa">
                            <small class="text-muted fs-7">Si se deja en blanco, el mapa interactivo se buscará automáticamente usando la dirección ingresada arriba.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Encargado de Sistemas</label>
                            <input type="text" name="encargado_sistemas" class="form-control" value="<?php echo htmlspecialchars($agenciaData['encargado_sistemas'] ?? ''); ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Teléfono / Extensión de Sistemas</label>
                            <input type="text" name="telefono_sistemas" class="form-control" value="<?php echo htmlspecialchars($agenciaData['telefono_sistemas'] ?? ''); ?>">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-secondary">Correo Electrónico de Sistemas</label>
                            <input type="email" name="correo_sistemas" class="form-control" value="<?php echo htmlspecialchars($agenciaData['correo_sistemas'] ?? ''); ?>">
                        </div>

                        <hr class="my-3 border-secondary border-opacity-50">

                        <!-- COLOR DEL PORTAL / TEMA OBSCURO -->
                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-info"><i class="bi bi-palette-fill me-1"></i> Color del Portal / Tema Obscuro (Login y Módulos)</label>
                            <select name="color_tema" class="form-select" id="selectorColorTemaModal">
                                <?php foreach ($catalogoTemas as $claveT => $infoT): ?>
                                    <option value="<?php echo $claveT; ?>" <?php echo ($colorClaveActual === $claveT) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($infoT['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted fs-7 d-block mt-1">Elige la paleta oscura oficial que teñirá el fondo 3D del login interactivo y las secciones del sistema.</small>
                        </div>

                        <hr class="my-3 border-secondary border-opacity-50">

                        <!-- SUBIDA DE LOGOTIPO -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-info"><i class="bi bi-image me-1"></i> Logotipo Oficial (Imagen PNG/JPG)</label>
                            <input type="file" name="logo_file" class="form-control" accept="image/*">
                            <small class="text-muted fs-7 d-block mt-1">Formato ideal: PNG transparente o JPG cuadrado.</small>
                        </div>

                        <!-- SUBIDA DE FOTO DE FACHADA -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-info"><i class="bi bi-camera me-1"></i> Fotografía de la Agencia / Fachada</label>
                            <input type="file" name="foto_file" class="form-control" accept="image/*">
                            <small class="text-muted fs-7 d-block mt-1">Formato ideal: Foto horizontal JPG/PNG de alta calidad.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4"><i class="bi bi-save me-1"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: AGREGAR NUEVA ÁREA                           -->
<!-- =================================================== -->
<div class="modal fade" id="modalAgregarArea" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="agregar_area">
                <input type="hidden" name="agencia_id" value="<?php echo $agenciaData['id'] ?? 0; ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-info"><i class="bi bi-plus-circle me-2"></i> Registrar Nueva Área</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Nombre del Área / Departamento</label>
                        <input type="text" name="nombre_area" class="form-control" placeholder="ej. Contabilidad, Mercadotecnia, Taller" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info text-dark fw-bold rounded-3 px-4"><i class="bi bi-save me-1"></i> Guardar Área</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: EDITAR NOMBRE DE ÁREA                        -->
<!-- =================================================== -->
<div class="modal fade" id="modalEditarArea" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="editar_area">
                <input type="hidden" name="area_id" id="edit_area_id" value="0">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-warning"><i class="bi bi-pencil-square me-2"></i> Editar Área</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Nombre del Área / Departamento</label>
                        <input type="text" name="nuevo_nombre" id="edit_area_nombre" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning rounded-3 px-4 fw-bold"><i class="bi bi-save me-1"></i> Actualizar Nombre</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- =================================================== -->
<!-- MODAL: PRUEBA DE CONEXIÓN A SERVIDOR LOCAL          -->
<!-- =================================================== -->
<div class="modal fade" id="modalPruebaConexionLocal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: #081528; border: 1px solid rgba(16, 185, 129, 0.4); color: #fff;">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                    <span class="p-2 rounded-2 bg-success bg-opacity-25 text-success"><i class="bi bi-activity"></i></span>
                    Diagnóstico de Conexión a Servidor Local
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="contenedorResultadoTestLocal">
                <div class="text-center py-4">
                    <div class="spinner-border text-success mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                    <h5 class="fw-bold text-white">Ejecutando prueba de conexión...</h5>
                    <p class="text-secondary small mb-0">Probando sockets TCP con el servidor local y receptor de cPanel...</p>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
                <a href="agencia.php?descargar_conector_local=1" class="btn btn-success text-dark fw-bold rounded-3">
                    <i class="bi bi-cloud-arrow-down-fill me-1"></i> Descargar Conector Local
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    function abrirModalEditarArea(area) {
        document.getElementById('edit_area_id').value = area.id;
        document.getElementById('edit_area_nombre').value = area.nombre;
        const modal = new bootstrap.Modal(document.getElementById('modalEditarArea'));
        modal.show();
    }

    function togglePasswordLocal(id, btn) {
        const input = document.getElementById(id);
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
        } else {
            input.type = 'password';
            btn.innerHTML = '<i class="bi bi-eye"></i>';
        }
    }

    function generarNuevoTokenLocal() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let token = 'TK_LOCAL_';
        for (let i = 0; i < 16; i++) {
            token += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('cfg_local_token').value = token;
    }

    function ejecutarPruebaConexionLocal() {
        const modalEl = document.getElementById('modalPruebaConexionLocal');
        const modal = new bootstrap.Modal(modalEl);
        const contenedor = document.getElementById('contenedorResultadoTestLocal');

        contenedor.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-success mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                <h5 class="fw-bold text-white">Ejecutando prueba de conexión...</h5>
                <p class="text-secondary small mb-0">Probando sockets TCP con el servidor local y receptor de cPanel...</p>
            </div>
        `;
        modal.show();

        const payload = {
            host: document.getElementById('cfg_local_host')?.value || '',
            port: document.getElementById('cfg_local_port')?.value || '3306',
            dbname: document.getElementById('cfg_local_dbname')?.value || '',
            user: document.getElementById('cfg_local_user')?.value || '',
            pass: document.getElementById('cfg_local_pass')?.value || '',
            tipo_db: document.getElementById('cfg_local_tipo')?.value || 'mysql',
            token: document.getElementById('cfg_local_token')?.value || ''
        };

        fetch('api_test_conexion_local.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            const d = data.detalles || {};
            let htmlBadge = '';
            let htmlAlert = '';

            if (d.pdo_conectado) {
                htmlBadge = `<span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i> Conexión Directa Exitosa</span>`;
                htmlAlert = `<div class="alert alert-success border-0 bg-success bg-opacity-10 text-success p-3 rounded-3 mb-3">
                    <strong>¡Excelente!</strong> Se estableció conexión directa con el motor de base de datos local (${escapeHtml(d.version_motor || payload.tipo_db)}).
                </div>`;
            } else if (d.es_ip_privada) {
                htmlBadge = `<span class="badge bg-info text-dark fs-6 px-3 py-2"><i class="bi bi-hdd-network-fill me-1"></i> Red Privada LAN Detectada</span>`;
                htmlAlert = `<div class="alert alert-info border-0 bg-info bg-opacity-10 text-info p-3 rounded-3 mb-3">
                    <strong><i class="bi bi-info-circle-fill me-1"></i> Servidor Local en Intranet / Red Privada:</strong><br>
                    La IP <code>${escapeHtml(payload.host)}</code> pertenece a una red local privada (RFC 1918). Debido a que tu cPanel está en la nube, la comunicación se realiza mediante el <strong>Conector Local Automático</strong>. Este script corre dentro de la agencia y transmite los reportes por HTTPS hacia cPanel sin abrir puertos en el router.
                </div>`;
            } else if (d.socket_abierto) {
                htmlBadge = `<span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> Socket Abierto (Revisar Credencial)</span>`;
                htmlAlert = `<div class="alert alert-warning border-0 bg-warning bg-opacity-10 text-warning p-3 rounded-3 mb-3">
                    <strong>Host alcanzable por red (${d.socket_tiempo_ms} ms), pero la BD respondió:</strong><br>
                    <code>${escapeHtml(d.pdo_mensaje || 'Acceso denegado con las credenciales ingresadas')}</code>
                </div>`;
            } else {
                htmlBadge = `<span class="badge bg-danger fs-6 px-3 py-2"><i class="bi bi-x-circle-fill me-1"></i> Host No Alcanzable</span>`;
                htmlAlert = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger p-3 rounded-3 mb-3">
                    <strong>No se pudo abrir socket TCP hacia ${escapeHtml(payload.host)}:${escapeHtml(payload.port)}:</strong><br>
                    <code>${escapeHtml(d.socket_error || 'Tiempo de espera agotado')}</code><br>
                    <small class="text-light mt-1 d-block">Si el servidor está dentro de la red interna de la sucursal, descarga el conector local y ejecútalo directamente en ese equipo.</small>
                </div>`;
            }

            contenedor.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-white mb-0">Resultado del Diagnóstico:</h6>
                    ${htmlBadge}
                </div>

                ${htmlAlert}

                <div class="row g-2 p-3 rounded-3 mb-3" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); font-size: 0.85rem;">
                    <div class="col-sm-6 text-secondary"><strong>IP / Host Evaluado:</strong> <span class="text-light font-monospace">${escapeHtml(payload.host)}:${escapeHtml(payload.port)}</span></div>
                    <div class="col-sm-6 text-secondary"><strong>IP Resuelta:</strong> <span class="text-light font-monospace">${escapeHtml(d.ip_resuelta || payload.host)}</span></div>
                    <div class="col-sm-6 text-secondary"><strong>Tipo de Red:</strong> <span class="text-light">${d.es_ip_privada ? 'Privada / LAN' : 'Pública / WAN'}</span></div>
                    <div class="col-sm-6 text-secondary"><strong>Socket TCP:</strong> <span class="${d.socket_abierto ? 'text-success' : 'text-danger'}">${d.socket_abierto ? 'Abierto (' + d.socket_tiempo_ms + ' ms)' : 'Cerrado / No alcanzable'}</span></div>
                    <div class="col-sm-6 text-secondary"><strong>Receptor cPanel:</strong> <span class="text-success"><i class="bi bi-check-circle-fill"></i> Activo y Listo</span></div>
                    <div class="col-sm-6 text-secondary"><strong>Última Sincronización:</strong> <span class="text-light">${escapeHtml(d.ultima_sincronizacion || 'Ninguna')}</span></div>
                </div>

                <div class="p-3 rounded-3" style="background: rgba(16, 185, 129, 0.08); border: 1px dashed rgba(16, 185, 129, 0.3);">
                    <div class="fw-bold text-success mb-1 small"><i class="bi bi-lightbulb-fill me-1"></i> Pasos para poner la API en tu Servidor Local:</div>
                    <ol class="small text-secondary mb-0 ps-3">
                        <li>Descarga el archivo <strong>conector_servidor_local.php</strong>.</li>
                        <li>Cópialo al servidor de la agencia (ej. <code>C:\\xampp\\htdocs\\</code> o en una carpeta de tu servidor).</li>
                        <li>Ábrelo en tu navegador local (<code>http://localhost/conector_servidor_local.php</code>) para comprobar la conexión a tu base de datos.</li>
                        <li>Programa una Tarea en Windows (Programador de Tareas) que ejecute <code>php conector_servidor_local.php</code> cada X minutos para enviar los datos automáticamente a tu cPanel.</li>
                    </ol>
                </div>
            `;
        })
        .catch(err => {
            contenedor.innerHTML = `
                <div class="alert alert-danger border-0">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> Error al ejecutar la prueba: ${escapeHtml(err.message)}
                </div>
            `;
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.toString().replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' })[m]);
    }
</script>
</body>
</html>
