<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'conexion.php';
require_once 'permisos_helper.php';

// Asegurar existencia de tablas de permisos y compliance
if ($pdo) {
    asegurarTablasPermisos($pdo);
    asegurarTablaCompliance($pdo);
}

requerirPermiso('compliance', 'puede_ver');

// Datos de sesión del usuario
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Colaborador');
$loginUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? 'usuario');
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
// En el Portal Central Grupo Huerta (rama gh), la administración y borrado de documentos/avisos está habilitada para gestores
$esAdmin = true;
$puedeEliminar = true;
$agenciaUsuario = $_SESSION['agencia'] ?? 'Dirección General Grupo Huerta';

// Obtener IP real del usuario para auditoría / marcas de agua
$ipUsuario = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (strpos($ipUsuario, ',') !== false) {
    $ipUsuario = trim(explode(',', $ipUsuario)[0]);
}

$mensaje = '';
$error = '';

// =============================================================================
// 2. ACCIONES ADMINISTRATIVAS: SUBIR DOCUMENTO / AVISO / ELIMINAR (PORTAL CENTRAL GH)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // ACCIÓN: SUBIR NUEVO DOCUMENTO / AVISO
    if ($accion === 'subir_documento') {
        $tipo = trim($_POST['tipo'] ?? 'formato');
        if (!in_array($tipo, ['formato', 'manual', 'aviso'])) {
            $tipo = 'formato';
        }
        $codigo = trim($_POST['codigo'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $categoria = trim($_POST['categoria'] ?? 'General');
        $version = trim($_POST['version'] ?? '1.0');
        $prioridad = trim($_POST['prioridad'] ?? 'Normal');
        $fechaVigencia = !empty($_POST['fecha_vigencia']) ? $_POST['fecha_vigencia'] : null;
        $obligatorio = isset($_POST['obligatorio_lectura']) ? 1 : 0;

        if (empty($titulo)) {
            $error = "El título del documento o aviso es obligatorio.";
        } elseif (empty($codigo)) {
            $error = "El código de identificación institucional es obligatorio (ej. FR-TI-01 o AV-05).";
        } else {
            $dirDestino = __DIR__ . '/uploads/compliance/';
            if (!file_exists($dirDestino)) {
                @mkdir($dirDestino, 0755, true);
            }

            $rutaRelativa = '';
            $rutaImagen = '';
            $archivoTipo = 'pdf';
            $archivoTamano = '0 KB';

            // A. Procesar Imagen de Aviso si se subió
            if ($tipo === 'aviso' && isset($_FILES['imagen_aviso']) && $_FILES['imagen_aviso']['error'] === UPLOAD_ERR_OK) {
                $nombreImgOrig = $_FILES['imagen_aviso']['name'];
                $extImg = strtolower(pathinfo($nombreImgOrig, PATHINFO_EXTENSION));
                $permitidosImg = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
                if (in_array($extImg, $permitidosImg)) {
                    $nombreLimpioImg = 'aviso_img_' . time() . '_' . rand(100, 999) . '.' . $extImg;
                    $rutaFisicaImg = $dirDestino . $nombreLimpioImg;
                    if (move_uploaded_file($_FILES['imagen_aviso']['tmp_name'], $rutaFisicaImg)) {
                        $rutaImagen = 'uploads/compliance/' . $nombreLimpioImg;
                    }
                }
            }

            // B. Si es aviso y NO subieron imagen física (o pusieron solo texto), convertir el texto en imagen institucional oficial SVG
            $estiloFondo = trim($_POST['estilo_fondo'] ?? 'negro_oro');
            if ($tipo === 'aviso' && empty($rutaImagen)) {
                $svgContenido = generarImagenAvisoSVG($titulo, $descripcion, $prioridad, $categoria, $codigo, date('d/m/Y'), $nombreUsuario, $estiloFondo);
                $codLimpio = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $codigo);
                $nombreLimpioSvg = 'aviso_card_' . $codLimpio . '_' . time() . '_' . rand(100, 999) . '.svg';
                $rutaFisicaSvg = $dirDestino . $nombreLimpioSvg;
                if (@file_put_contents($rutaFisicaSvg, $svgContenido)) {
                    $rutaImagen = 'uploads/compliance/' . $nombreLimpioSvg;
                }
            }

            // C. Procesar Archivo / Anexo Opcional (PDF, Word, Excel)
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                $nombreOriginal = $_FILES['archivo']['name'];
                $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
                $permitidos = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp', 'svg'];

                if (!in_array($ext, $permitidos)) {
                    $error = "Formato no permitido. Se permiten archivos PDF, Word, Excel o Imágenes.";
                } else {
                    $nombreLimpio = 'comp_' . $tipo . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    $rutaFisica = $dirDestino . $nombreLimpio;
                    $rutaRelativa = 'uploads/compliance/' . $nombreLimpio;

                    if (move_uploaded_file($_FILES['archivo']['tmp_name'], $rutaFisica)) {
                        $archivoTipo = $ext;
                        $tamBytes = @filesize($rutaFisica);
                        if ($tamBytes >= 1048576) {
                            $archivoTamano = round($tamBytes / 1048576, 1) . ' MB';
                        } else {
                            $archivoTamano = round($tamBytes / 1024, 0) . ' KB';
                        }
                    } else {
                        $error = "No fue posible guardar el archivo adjunto en el servidor.";
                    }
                }
            }

            if (empty($error)) {
                try {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO compliance_documentos 
                        (tipo, codigo, titulo, descripcion, categoria, archivo_url, imagen_url, archivo_tipo, archivo_tamano, version, fecha_publicacion, fecha_vigencia, prioridad, obligatorio_lectura, estatus, creado_por, estilo_fondo)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                    ");
                    $stmtIns->execute([
                        $tipo,
                        $codigo,
                        $titulo,
                        $descripcion,
                        $categoria,
                        $rutaRelativa,
                        $rutaImagen,
                        $archivoTipo,
                        $archivoTamano,
                        $version,
                        date('Y-m-d'),
                        $fechaVigencia,
                        $prioridad,
                        $obligatorio,
                        $nombreUsuario,
                        $estiloFondo
                    ]);
                    $mensaje = "El registro " . htmlspecialchars($codigo) . " ha sido publicado exitosamente en Compliance.";
                    // Cambiar a la sección respectiva para visualizar el nuevo registro
                    $seccionActiva = ($tipo === 'aviso') ? 'avisos' : (($tipo === 'manual') ? 'manuales' : 'formatos');
                } catch (Throwable $eIns) {
                    $error = "Error al registrar en la base de datos: " . $eIns->getMessage();
                }
            }
        }
    }

    // ACCIÓN: ELIMINAR DOCUMENTO / AVISO (PORTAL CENTRAL GH)
    if ($accion === 'eliminar_documento') {
        $docId = (int)($_POST['documento_id'] ?? 0);
        $seccionDestino = $_POST['seccion'] ?? '';
        if ($docId > 0) {
            try {
                $stmtDoc = $pdo->prepare("SELECT id, codigo, tipo, titulo, archivo_url, imagen_url FROM compliance_documentos WHERE id = ?");
                $stmtDoc->execute([$docId]);
                $docFila = $stmtDoc->fetch(PDO::FETCH_ASSOC);
                if ($docFila) {
                    if (!empty($docFila['archivo_url']) && file_exists(__DIR__ . '/' . $docFila['archivo_url'])) {
                        @unlink(__DIR__ . '/' . $docFila['archivo_url']);
                    }
                    if (!empty($docFila['imagen_url']) && file_exists(__DIR__ . '/' . $docFila['imagen_url'])) {
                        @unlink(__DIR__ . '/' . $docFila['imagen_url']);
                    }

                    $stmtDel = $pdo->prepare("DELETE FROM compliance_documentos WHERE id = ?");
                    $stmtDel->execute([$docId]);
                    $tipoTxt = ($docFila['tipo'] === 'aviso') ? 'Aviso' : (($docFila['tipo'] === 'manual') ? 'Manual' : 'Formato');
                    $mensaje = "{$tipoTxt} [" . htmlspecialchars($docFila['codigo'] ?? '') . "] eliminado exitosamente del catálogo.";
                    if (in_array($seccionDestino, ['principal', 'formatos', 'manuales', 'avisos'])) {
                        $seccionActiva = $seccionDestino;
                    }
                } else {
                    $error = "El registro solicitado no fue encontrado o ya fue eliminado.";
                }
            } catch (Throwable $eDel) {
                $error = "Error al eliminar el registro: " . $eDel->getMessage();
            }
        }
    }
}

// =============================================================================
// 3. CONSULTA DE DOCUMENTOS Y ESTADÍSTICAS
// =============================================================================
$formatos = [];
$manuales = [];
$avisos = [];
$categoriasFormatos = [];
$categoriasManuales = [];

if ($pdo) {
    try {
        // Formatos
        $stmtF = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'formato' AND estatus = 1 ORDER BY codigo ASC, id DESC");
        $formatos = $stmtF ? $stmtF->fetchAll(PDO::FETCH_ASSOC) : [];

        // Manuales
        $stmtM = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'manual' AND estatus = 1 ORDER BY codigo ASC, id DESC");
        $manuales = $stmtM ? $stmtM->fetchAll(PDO::FETCH_ASSOC) : [];

        // Avisos
        $stmtA = $pdo->query("SELECT * FROM compliance_documentos WHERE tipo = 'aviso' AND estatus = 1 ORDER BY id DESC");
        $avisos = $stmtA ? $stmtA->fetchAll(PDO::FETCH_ASSOC) : [];

        // Asegurar que cada aviso tenga su imagen generada si está vacía o es basada en texto
        foreach ($avisos as &$avItem) {
            $necesitaGenerar = false;
            if (empty($avItem['imagen_url'])) {
                $necesitaGenerar = true;
            } elseif (!file_exists(__DIR__ . '/' . $avItem['imagen_url']) && strpos($avItem['imagen_url'], '.svg') !== false) {
                $necesitaGenerar = true;
            }
            if ($necesitaGenerar) {
                $codLimpio = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $avItem['codigo']);
                $svgFile = 'uploads/compliance/aviso_' . $codLimpio . '_' . $avItem['id'] . '.svg';
                $svgBody = generarImagenAvisoSVG($avItem['titulo'], $avItem['descripcion'], $avItem['prioridad'], $avItem['categoria'], $avItem['codigo'], $avItem['fecha_publicacion'], $avItem['creado_por'] ?? 'Dirección General');
                @file_put_contents(__DIR__ . '/' . $svgFile, $svgBody);
                $avItem['imagen_url'] = $svgFile;
                try {
                    $updImg = $pdo->prepare("UPDATE compliance_documentos SET imagen_url = ? WHERE id = ?");
                    $updImg->execute([$svgFile, $avItem['id']]);
                } catch (Throwable $eU) {}
            }
            $avItem['fondo_cls'] = obtenerClaseFondoAviso($avItem);
            $imgExt = strtolower(pathinfo($avItem['imagen_url'] ?? '', PATHINFO_EXTENSION));
            $avItem['es_fisica'] = !empty($avItem['imagen_url']) && in_array($imgExt, ['jpg', 'jpeg', 'png', 'webp', 'gif']) && (strpos($avItem['imagen_url'], 'aviso_card_') === false);
        }
        unset($avItem);

        foreach ($formatos as $f) {
            $cat = !empty($f['categoria']) ? trim($f['categoria']) : 'General';
            $categoriasFormatos[$cat] = ($categoriasFormatos[$cat] ?? 0) + 1;
        }
        foreach ($manuales as $m) {
            $cat = !empty($m['categoria']) ? trim($m['categoria']) : 'General';
            $categoriasManuales[$cat] = ($categoriasManuales[$cat] ?? 0) + 1;
        }
    } catch (Throwable $eQ) {}
}

$totalFormatos = count($formatos);
$totalManuales = count($manuales);
$totalAvisos = count($avisos);
$totalDocumentos = $totalFormatos + $totalManuales + $totalAvisos;

// Sección activa por parámetro GET (default: principal)
$seccionActiva = $_GET['seccion'] ?? 'principal';
if (!in_array($seccionActiva, ['principal', 'formatos', 'manuales', 'avisos'])) {
    $seccionActiva = 'principal';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance & Gobernanza | Portal Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #040d1a;
            --bg-card: #09172c;
            --bg-sidebar: #061122;
            --gold-accent: #d4af37;
            --gold-accent-light: #f5df9e;
            --gold-border: rgba(212, 175, 55, 0.35);
            --gold-border-bright: rgba(212, 175, 55, 0.7);
            --gold-glow: rgba(212, 175, 55, 0.18);
            --sidebar-width: 280px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 12% 15%, rgba(212, 175, 55, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 88% 20%, rgba(30, 58, 138, 0.22) 0%, transparent 45%),
                radial-gradient(circle at 50% 90%, rgba(9, 23, 44, 0.7) 0%, transparent 60%);
            background-attachment: fixed;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
        }

        /* NAVBAR SUPERIOR EJECUTIVA */
        .executive-navbar {
            background: rgba(4, 13, 26, 0.96);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--gold-border);
            padding: 12px 28px;
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }

        .btn-gold-outline {
            color: var(--gold-accent-light);
            border: 1px solid var(--gold-border);
            background: rgba(212, 175, 55, 0.08);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 8px 16px;
            border-radius: 10px;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-gold-outline:hover {
            color: #ffffff;
            background: rgba(212, 175, 55, 0.22);
            border-color: var(--gold-border-bright);
            box-shadow: 0 0 14px var(--gold-glow);
        }

        .btn-gold-primary {
            background: linear-gradient(135deg, #d4af37 0%, #a6841e 100%);
            color: #040d1a;
            border: 1px solid var(--gold-border-bright);
            font-weight: 700;
            font-size: 0.88rem;
            padding: 8px 18px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }
        .btn-gold-primary:hover {
            background: linear-gradient(135deg, #f5df9e 0%, #c49d29 100%);
            color: #02070e;
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.45);
            transform: translateY(-1px);
        }

        /* LAYOUT PRINCIPAL CON SIDEBAR */
        .compliance-wrapper {
            display: flex;
            min-height: calc(100vh - 65px);
        }

        /* SIDEBAR LATERAL */
        .compliance-sidebar {
            width: var(--sidebar-width);
            background: var(--bg-sidebar);
            border-right: 1px solid var(--gold-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            transition: all 0.3s ease;
            position: sticky;
            top: 65px;
            height: calc(100vh - 65px);
            overflow-y: auto;
            z-index: 1010;
        }

        .sidebar-header {
            padding: 24px 20px 16px 20px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
        }

        .sidebar-brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 1.2px;
            color: var(--gold-accent-light);
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid var(--gold-border);
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .sidebar-title {
            font-family: 'Cinzel', serif;
            font-size: 1.18rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 1.5px;
            margin: 0;
        }
        .sidebar-subtitle {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 2px;
        }

        .sidebar-nav {
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex-grow: 1;
        }

        .nav-section-title {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            padding: 10px 12px 4px 12px;
        }

        .compliance-nav-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-radius: 12px;
            color: #cbd5e1;
            font-weight: 600;
            font-size: 0.92rem;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            cursor: pointer;
        }

        .compliance-nav-item:hover {
            color: var(--gold-accent-light);
            background: rgba(212, 175, 55, 0.08);
            border-color: rgba(212, 175, 55, 0.2);
            transform: translateX(3px);
        }

        .compliance-nav-item.active {
            color: var(--gold-accent-light);
            background: linear-gradient(90deg, rgba(212, 175, 55, 0.18) 0%, rgba(212, 175, 55, 0.05) 100%);
            border: 1px solid var(--gold-border);
            box-shadow: 0 4px 14px var(--gold-glow);
            font-weight: 700;
        }

        .compliance-nav-item .nav-icon-label {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .compliance-nav-item i {
            font-size: 1.15rem;
            color: var(--gold-accent);
            width: 20px;
            text-align: center;
        }

        .badge-nav-count {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
        }
        .compliance-nav-item.active .badge-nav-count {
            background: rgba(212, 175, 55, 0.25);
            color: var(--gold-accent-light);
            border: 1px solid var(--gold-border);
        }

        .sidebar-footer {
            padding: 16px 14px;
            border-top: 1px solid rgba(212, 175, 55, 0.15);
            background: rgba(4, 13, 26, 0.6);
        }

        .sidebar-kpi-pill {
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid var(--gold-border);
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        /* ÁREA DE CONTENIDO */
        .compliance-content {
            flex-grow: 1;
            padding: 32px 36px 60px 36px;
            max-width: calc(100vw - var(--sidebar-width));
            overflow-x: hidden;
        }

        /* SECCIONES OCULTAS / VISIBLES */
        .compliance-section {
            display: none;
            animation: fadeIn 0.25s ease forwards;
        }
        .compliance-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* BANNER HERO EJECUTIVO */
        .hero-compliance-card {
            background: linear-gradient(135deg, rgba(9, 23, 44, 0.95) 0%, rgba(6, 17, 34, 0.98) 100%);
            border: 1px solid var(--gold-border);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4), 0 0 20px var(--gold-glow);
            border-radius: 20px;
            padding: 32px 36px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .hero-gold-title {
            font-family: 'Cinzel', serif;
            font-size: 1.85rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        /* TARJETAS KPI DE COMPLIANCE */
        .kpi-metric-card {
            background: var(--bg-card);
            border: 1px solid var(--gold-border);
            border-radius: 16px;
            padding: 22px 24px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.3);
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-metric-card:hover {
            border-color: var(--gold-border-bright);
            box-shadow: 0 8px 24px var(--gold-glow);
            transform: translateY(-2px);
        }

        .kpi-metric-number {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--gold-accent-light);
            line-height: 1.1;
        }
        .kpi-metric-label {
            font-size: 0.85rem;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .kpi-icon-box {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid var(--gold-border);
            color: var(--gold-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            flex-shrink: 0;
        }

        /* TARJETAS DE DOCUMENTOS Y FORMATOS */
        .doc-item-card {
            background: var(--bg-card);
            border: 1px solid var(--gold-border);
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 18px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
            position: relative;
        }
        .doc-item-card:hover {
            border-color: var(--gold-border-bright);
            box-shadow: 0 8px 22px var(--gold-glow);
            background: #0b1a32;
        }

        .doc-code-badge {
            font-family: 'Cinzel', serif;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--gold-accent-light);
            background: rgba(212, 175, 55, 0.15);
            border: 1px solid var(--gold-border);
            padding: 4px 10px;
            border-radius: 8px;
            letter-spacing: 0.8px;
            display: inline-block;
        }

        .doc-priority-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .priority-alta {
            background: rgba(239, 68, 68, 0.18);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }
        .priority-media {
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }
        .priority-normal {
            background: rgba(59, 130, 246, 0.18);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.4);
        }

        .category-tag {
            font-size: 0.75rem;
            font-weight: 600;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 3px 9px;
            border-radius: 6px;
        }

        .search-control-box {
            background: #061122;
            border: 1px solid var(--gold-border);
            color: #ffffff;
            border-radius: 12px;
            padding: 10px 16px;
            font-size: 0.92rem;
        }
        .search-control-box:focus {
            background: #08162c;
            border-color: var(--gold-accent);
            color: #ffffff;
            box-shadow: 0 0 10px var(--gold-glow);
            outline: none;
        }

        /* ===================================================================== */
        /* GALERÍA / CARRUSEL DE AVISOS                                          */
        /* ===================================================================== */
        .avisos-gallery-wrapper {
            background: #061224;
            border: 1px solid var(--gold-border);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.5), 0 0 20px var(--gold-glow);
            margin-bottom: 30px;
        }

        .gallery-main-stage {
            position: relative;
            width: 100%;
            height: 560px;
            background: #030812;
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.6);
            display: flex;
            flex-direction: column;
        }

        .gallery-slides-viewport {
            position: relative;
            width: 100%;
            height: 440px;
            overflow: hidden;
            background: #020711;
        }

        .gallery-slide-item {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            align-items: center;
            justify-content: center;
            padding: 16px;
            animation: slideFade 0.35s ease forwards;
        }
        .gallery-slide-item.active {
            display: flex;
        }

        @keyframes slideFade {
            from { opacity: 0; transform: scale(0.985); }
            to { opacity: 1; transform: scale(1); }
        }

        .gallery-slide-img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            background: transparent;
            display: block;
            margin: 0 auto;
            cursor: pointer;
            transition: transform 0.25s ease;
        }
        .gallery-slide-img:hover {
            transform: scale(1.015);
        }

        /* Tarjeta de Aviso en HTML (tipo Facebook) cuando no hay imagen física */
        .gallery-fb-slide-card {
            width: 100%;
            max-width: 860px;
            height: 100%;
            border-radius: 14px;
            padding: 24px 32px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: transform 0.25s ease;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), inset 0 0 0 1px rgba(212, 175, 55, 0.3);
        }
        .gallery-fb-slide-card:hover {
            transform: scale(1.01);
        }
        .fb-card-logo {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #d4af37;
            color: #040d1a;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        .gallery-fb-titulo {
            font-family: 'Cinzel', serif;
            color: #ffffff;
            font-size: 1.85rem;
            font-weight: 800;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.8);
        }
        .gallery-fb-desc {
            font-family: 'Montserrat', sans-serif;
            color: #f1f5f9;
            font-size: 1.15rem;
            line-height: 1.5;
            max-width: 780px;
            margin: 0 auto;
            text-shadow: 0 2px 6px rgba(0,0,0,0.7);
        }

        /* PIE FIJO DEL ANUNCIO (100% ESTÁTICO, NO SE MUEVE NI PARPADEA) */
        .gallery-static-footer {
            height: 120px;
            min-height: 120px;
            width: 100%;
            background: rgba(4, 13, 26, 0.98);
            border-top: 1px solid var(--gold-border);
            padding: 16px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            z-index: 20;
            position: relative;
        }

        .gallery-controls-btn {
            position: absolute;
            top: 220px;
            transform: translateY(-50%);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(4, 13, 26, 0.85);
            backdrop-filter: blur(8px);
            border: 2px solid var(--gold-border-bright);
            color: var(--gold-accent-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 25;
        }
        .gallery-controls-btn:hover {
            background: rgba(212, 175, 55, 0.35);
            border-color: #ffd700;
            color: #ffffff;
            box-shadow: 0 0 20px var(--gold-glow);
            transform: translateY(-50%) scale(1.1);
        }
        .gallery-btn-prev { left: 20px; }
        .gallery-btn-next { right: 20px; }

        /* Estilos Facebook Text Card Creator */
        .fb-theme-picker {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 6px;
            margin-bottom: 10px;
        }
        .fb-swatch {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            padding: 0;
        }
        .fb-swatch:hover {
            transform: scale(1.12);
        }
        .fb-swatch.active {
            border-color: #ffd700;
            box-shadow: 0 0 12px rgba(255, 215, 0, 0.7);
            transform: scale(1.15);
        }
        .fb-swatch.active::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #ffffff;
            font-size: 14px;
            font-weight: 900;
            text-shadow: 0 1px 3px rgba(0,0,0,0.8);
        }
        .swatch-negro { background: linear-gradient(135deg, #050b14 0%, #152238 100%) !important; border: 1px solid rgba(212, 175, 55, 0.5) !important; }
        .swatch-azul { background: linear-gradient(135deg, #08214d 0%, #104887 50%, #0c356a 100%) !important; border: 1px solid rgba(96, 165, 250, 0.6) !important; }
        .swatch-rojo { background: linear-gradient(135deg, #4a0810 0%, #8c1626 50%, #590d18 100%) !important; border: 1px solid rgba(248, 113, 113, 0.6) !important; }
        .swatch-verde { background: linear-gradient(135deg, #062b18 0%, #0e6338 50%, #093f24 100%) !important; border: 1px solid rgba(52, 211, 153, 0.6) !important; }
        .swatch-purpura { background: linear-gradient(135deg, #2a0d3d 0%, #601a70 50%, #3a0f47 100%) !important; border: 1px solid rgba(192, 132, 252, 0.6) !important; }

        .btn-emoji-quick {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 6px;
            padding: 2px 7px;
            font-size: 0.95rem;
            cursor: pointer;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
        .btn-emoji-quick:hover {
            background: rgba(212, 175, 55, 0.25);
            border-color: var(--gold-accent);
            transform: scale(1.15);
        }

        .fb-live-card {
            width: 100%;
            min-height: 220px;
            border-radius: 16px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            border: 1px solid rgba(212, 175, 55, 0.4);
            box-shadow: 0 12px 30px rgba(0,0,0,0.5);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        .fb-live-card-body {
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 12px 10px;
            word-break: break-word;
        }
        .fb-live-card-text {
            color: #ffffff;
            font-weight: 700;
            line-height: 1.35;
            text-shadow: 0 2px 8px rgba(0,0,0,0.7);
            font-family: 'Montserrat', sans-serif;
            transition: font-size 0.2s ease;
            white-space: pre-wrap;
        }

        /* Dashboard Principal Avisos Carousel */
        .dash-carousel-container {
            position: relative;
            width: 100%;
            height: 270px;
            background: #030812;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(212, 175, 55, 0.3);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }
        .dash-carousel-slide {
            display: none;
            width: 100%;
            height: 100%;
            position: relative;
            cursor: pointer;
            animation: slideFade 0.4s ease;
        }
        .dash-carousel-slide.active {
            display: block;
        }
        .dash-carousel-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        .dash-carousel-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(4, 13, 26, 0.8) 35%, rgba(4, 13, 26, 0.98) 100%);
            padding: 14px 18px 10px 18px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .dash-fb-card {
            width: 100%;
            height: 100%;
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: stretch;
            cursor: pointer;
            box-shadow: inset 0 0 0 1px rgba(212, 175, 55, 0.35);
            position: relative;
            overflow: hidden;
        }
        .dash-fb-body {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            overflow: hidden;
            padding: 8px 10px;
        }
        .dash-fb-title {
            color: #ffffff;
            font-weight: 800;
            font-size: 1.15rem;
            line-height: 1.35;
            margin-bottom: 8px;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.85);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .dash-fb-text {
            color: rgba(255, 255, 255, 0.95);
            font-size: 0.88rem;
            line-height: 1.45;
            margin-bottom: 0;
            text-shadow: 0 1px 5px rgba(0, 0, 0, 0.85);
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .dash-carousel-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(4, 13, 26, 0.85);
            border: 1px solid var(--gold-border);
            color: var(--gold-accent-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            cursor: pointer;
            z-index: 10;
            transition: all 0.2s ease;
        }
        .dash-carousel-nav:hover {
            background: var(--gold-accent);
            color: #030812;
        }
        .dash-carousel-prev { left: 8px; }
        .dash-carousel-next { right: 8px; }
        .dash-carousel-indicator {
            position: absolute;
            top: 10px;
            right: 12px;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(212,175,55,0.4);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 0.7rem;
            color: var(--gold-accent-light);
            font-weight: 700;
            z-index: 10;
        }

        .gallery-thumbs-strip {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding: 16px 4px 6px 4px;
            scrollbar-width: thin;
            scrollbar-color: var(--gold-accent) transparent;
        }
        .gallery-thumb-card {
            flex: 0 0 160px;
            height: 90px;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid transparent;
            cursor: pointer;
            position: relative;
            background: #030812;
            transition: all 0.2s ease;
            opacity: 0.6;
        }
        .gallery-thumb-card:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        .gallery-thumb-card.active {
            opacity: 1;
            border-color: var(--gold-accent);
            box-shadow: 0 0 14px var(--gold-glow);
        }
        .gallery-thumb-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-autoplay-bar {
            height: 3px;
            width: 0%;
            background: linear-gradient(90deg, #d4af37, #f5df9e);
            transition: width 0.1s linear;
        }

        /* MODAL STYLING */
        .modal-content-gold {
            background: #09172c;
            border: 1px solid var(--gold-border);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), 0 0 25px var(--gold-glow);
            border-radius: 18px;
            color: #ffffff;
        }
        .modal-header-gold {
            border-bottom: 1px solid var(--gold-border);
            padding: 20px 24px;
        }
        .modal-footer-gold {
            border-top: 1px solid var(--gold-border);
            padding: 16px 24px;
        }

        .form-label-gold {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--gold-accent-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .form-control-gold, .form-select-gold {
            background: #040d1a;
            border: 1px solid rgba(212, 175, 55, 0.3);
            color: #ffffff;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.9rem;
        }
        .form-control-gold:focus, .form-select-gold:focus {
            background: #061224;
            border-color: var(--gold-accent);
            color: #ffffff;
            box-shadow: 0 0 12px var(--gold-glow);
            outline: none;
        }

        /* RESPONSIVE TOGGLE SIDEBAR */
        .sidebar-toggle-btn {
            display: none;
            background: rgba(212, 175, 55, 0.15);
            border: 1px solid var(--gold-border);
            color: var(--gold-accent-light);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 1.1rem;
        }

        @media (max-width: 991px) {
            .sidebar-toggle-btn {
                display: inline-block;
            }
            .compliance-sidebar {
                position: fixed;
                left: -290px;
                top: 65px;
                height: calc(100vh - 65px);
                z-index: 1050;
            }
            .compliance-sidebar.open {
                left: 0;
                box-shadow: 10px 0 30px rgba(0, 0, 0, 0.7);
            }
            .compliance-content {
                max-width: 100vw;
                padding: 20px 16px;
            }
            .gallery-slide-overlay {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

<!-- NAVBAR SUPERIOR EJECUTIVA -->
<header class="executive-navbar d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
        <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Abrir Menú">
            <i class="bi bi-list"></i>
        </button>
        <a href="menu.php" class="btn-gold-outline">
            <i class="bi bi-arrow-left"></i> Menú Principal
        </a>
        <div class="vr bg-secondary opacity-50 d-none d-sm-block"></div>
        <div class="d-none d-md-flex align-items-center gap-2">
            <i class="bi bi-shield-check text-warning fs-5"></i>
            <span class="fw-bold tracking-wide" style="font-size: 0.92rem;">
                PORTAL GRUPO HUERTA
            </span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-3">
        <?php if ($esAdmin): ?>
            <button class="btn-gold-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoDocumento">
                <i class="bi bi-cloud-arrow-up-fill"></i> <span class="d-none d-sm-inline">Nuevo Documento / Aviso</span>
            </button>
        <?php endif; ?>

        <div class="text-end d-none d-lg-block">
            <div class="small fw-bold text-white"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.73rem;">
                <?php echo htmlspecialchars($agenciaUsuario); ?> &bull; 
                <span class="badge bg-warning text-dark text-uppercase fw-bold" style="font-size: 0.65rem;">
                    <?php echo htmlspecialchars($rolActual); ?>
                </span>
            </div>
        </div>
    </div>
</header>

<div class="compliance-wrapper">

    <!-- ========================================================================= -->
    <!-- MENÚ LATERAL INTERACTIVO (FORMATOS, MANUALES, AVISOS, PRINCIPAL)          -->
    <!-- ========================================================================= -->
    <aside class="compliance-sidebar" id="complianceSidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand-badge">
                <i class="bi bi-shield-lock-fill"></i> GOBIERNO & CONTROL
            </div>
            <h2 class="sidebar-title">COMPLIANCE</h2>
            <div class="sidebar-subtitle">Gestión Normativa e Institucional</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-title">Navegación del Módulo</div>

            <!-- 1. PRINCIPAL -->
            <a href="?seccion=principal" class="compliance-nav-item <?php echo $seccionActiva === 'principal' ? 'active' : ''; ?>" data-tab-target="principal">
                <span class="nav-icon-label">
                    <i class="bi bi-house-door-fill"></i>
                    <span>Principal</span>
                </span>
                <span class="badge-nav-count"><i class="bi bi-stars text-warning"></i></span>
            </a>

            <!-- 2. FORMATOS -->
            <a href="?seccion=formatos" class="compliance-nav-item <?php echo $seccionActiva === 'formatos' ? 'active' : ''; ?>" data-tab-target="formatos">
                <span class="nav-icon-label">
                    <i class="bi bi-file-earmark-text-fill"></i>
                    <span>Formatos</span>
                </span>
                <span class="badge-nav-count"><?php echo $totalFormatos; ?></span>
            </a>

            <!-- 3. MANUALES -->
            <a href="?seccion=manuales" class="compliance-nav-item <?php echo $seccionActiva === 'manuales' ? 'active' : ''; ?>" data-tab-target="manuales">
                <span class="nav-icon-label">
                    <i class="bi bi-book-half"></i>
                    <span>Manuales</span>
                </span>
                <span class="badge-nav-count"><?php echo $totalManuales; ?></span>
            </a>

            <!-- 4. AVISOS -->
            <a href="?seccion=avisos" class="compliance-nav-item <?php echo $seccionActiva === 'avisos' ? 'active' : ''; ?>" data-tab-target="avisos">
                <span class="nav-icon-label">
                    <i class="bi bi-megaphone-fill"></i>
                    <span>Avisos</span>
                </span>
                <span class="badge-nav-count"><?php echo $totalAvisos; ?></span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-kpi-pill">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="small text-secondary fw-semibold">Auditoría Normativa</span>
                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50" style="font-size: 0.68rem;">100% VIGENTE</span>
                </div>
                <div class="text-white small fw-bold">
                    <i class="bi bi-check2-circle text-warning me-1"></i> <?php echo $totalDocumentos; ?> Documentos Activos
                </div>
            </div>

            <a href="menu.php" class="btn btn-outline-secondary btn-sm w-100 rounded-3" style="font-size: 0.8rem;">
                <i class="bi bi-box-arrow-left me-1"></i> Volver a Módulos
            </a>
        </div>
    </aside>

    <!-- ========================================================================= -->
    <!-- CONTENIDO PRINCIPAL                                                       -->
    <!-- ========================================================================= -->
    <main class="compliance-content">

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success alert-dismissible fade show border-success border-opacity-50 bg-success bg-opacity-10 text-white rounded-4 mb-4" role="alert">
                <i class="bi bi-check-circle-fill text-success me-2 fs-5 align-middle"></i>
                <?php echo htmlspecialchars($mensaje); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show border-danger border-opacity-50 bg-danger bg-opacity-10 text-white rounded-4 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill text-danger me-2 fs-5 align-middle"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- ===================================================================== -->
        <!-- SECCIÓN 1: PRINCIPAL                                                  -->
        <!-- ===================================================================== -->
        <section class="compliance-section <?php echo $seccionActiva === 'principal' ? 'active' : ''; ?>" id="sec-principal">
            
            <!-- Hero Card -->
            <div class="hero-compliance-card">
                <div class="d-inline-flex align-items-center gap-2 mb-2" style="color: var(--gold-accent-light); font-size: 0.78rem; font-weight: 700; letter-spacing: 1px;">
                    <i class="bi bi-award-fill"></i> ALTA DIRECCIÓN & CUMPLIMIENTO CORPORATIVO
                </div>
                <h1 class="hero-gold-title">Módulo de Compliance</h1>
                <p class="text-secondary mb-4" style="max-width: 820px; font-size: 0.98rem; line-height: 1.6;">
                    Bienvenido al centro institucional de gobernanza de <strong>Grupo Huerta</strong>. Aquí encontrarás formatos oficiales, manuales de operación estandarizados y los avisos y circulares vigentes emitidos por la Dirección General y la Dirección de Sistemas.
                </p>

                <div class="d-flex flex-wrap gap-3">
                    <button type="button" class="btn-gold-primary" onclick="cambiarSeccion('formatos')">
                        <i class="bi bi-file-earmark-text-fill"></i> Explorar Formatos
                    </button>
                    <button type="button" class="btn-gold-outline" onclick="cambiarSeccion('manuales')">
                        <i class="bi bi-book-half"></i> Consultar Manuales
                    </button>
                    <button type="button" class="btn-gold-outline" onclick="cambiarSeccion('avisos')">
                        <i class="bi bi-megaphone-fill"></i> Ver Avisos
                    </button>
                </div>
            </div>

            <!-- Métricas KPI -->
            <div class="row g-4 mb-5">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="kpi-metric-card" onclick="cambiarSeccion('formatos')" style="cursor: pointer;">
                        <div>
                            <div class="kpi-metric-number"><?php echo $totalFormatos; ?></div>
                            <div class="kpi-metric-label">Formatos Oficiales</div>
                            <div class="text-secondary" style="font-size: 0.75rem;">Responsivas y formatos activos</div>
                        </div>
                        <div class="kpi-icon-box">
                            <i class="bi bi-file-earmark-check"></i>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="kpi-metric-card" onclick="cambiarSeccion('manuales')" style="cursor: pointer;">
                        <div>
                            <div class="kpi-metric-number"><?php echo $totalManuales; ?></div>
                            <div class="kpi-metric-label">Manuales Operativos</div>
                            <div class="text-secondary" style="font-size: 0.75rem;">Guías y protocolos estandarizados</div>
                        </div>
                        <div class="kpi-icon-box">
                            <i class="bi bi-journal-bookmark-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="kpi-metric-card" onclick="cambiarSeccion('avisos')" style="cursor: pointer;">
                        <div>
                            <div class="kpi-metric-number"><?php echo $totalAvisos; ?></div>
                            <div class="kpi-metric-label">Avisos y Circulares</div>
                            <div class="text-secondary" style="font-size: 0.75rem;">Comunicados y circulares</div>
                        </div>
                        <div class="kpi-icon-box">
                            <i class="bi bi-megaphone-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="kpi-metric-card">
                        <div>
                            <div class="kpi-metric-number" style="color: #34d399;">100%</div>
                            <div class="kpi-metric-label">Estatus Regulatorio</div>
                            <div class="text-secondary" style="font-size: 0.75rem;">Conforme a normatividad 2026</div>
                        </div>
                        <div class="kpi-icon-box" style="background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.3); color: #34d399;">
                            <i class="bi bi-patch-check-fill"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resumen de Destacados -->
            <div class="row g-4">
                <div class="col-12 col-lg-7">
                    <div class="p-4 rounded-4" style="background: var(--bg-card); border: 1px solid var(--gold-border);">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h3 class="h5 fw-bold text-white m-0 d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-text text-warning"></i> Formatos Más Solicitados
                            </h3>
                            <button type="button" class="btn btn-sm btn-link text-warning text-decoration-none p-0" onclick="cambiarSeccion('formatos')">
                                Ver todos <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>

                        <div class="d-flex flex-column gap-3">
                            <?php 
                            $muestraFormatos = array_slice($formatos, 0, 3);
                            foreach ($muestraFormatos as $mf): 
                            ?>
                                <div class="p-3 rounded-3 d-flex align-items-center justify-content-between" style="background: rgba(4, 13, 26, 0.6); border: 1px solid rgba(255, 255, 255, 0.06);">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="doc-code-badge"><?php echo htmlspecialchars($mf['codigo']); ?></span>
                                        <div>
                                            <div class="fw-bold text-white small"><?php echo htmlspecialchars($mf['titulo']); ?></div>
                                            <div class="text-secondary" style="font-size: 0.74rem;">
                                                <?php echo htmlspecialchars($mf['categoria']); ?> &bull; Versión <?php echo htmlspecialchars($mf['version']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3" onclick="verDocumento(<?php echo htmlspecialchars(json_encode($mf)); ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-5">
                    <div class="p-4 rounded-4 d-flex flex-column justify-content-between" style="background: var(--bg-card); border: 1px solid var(--gold-border); height: 100%;">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h3 class="h5 fw-bold text-white m-0 d-flex align-items-center gap-2">
                                <i class="bi bi-megaphone-fill text-warning"></i> Avisos
                                <span class="badge bg-warning text-dark fw-bold px-2 py-0.5 rounded-pill" style="font-size: 0.7rem;"><?php echo count($avisos); ?></span>
                            </h3>
                            <button type="button" class="btn btn-sm btn-link text-warning text-decoration-none p-0 fw-semibold" onclick="cambiarSeccion('avisos')">
                                Ver todos <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>

                        <?php if (!empty($avisos)): ?>
                            <div class="dash-carousel-container" id="dashAvisosCarousel" onmouseenter="pausarDashCarousel()" onmouseleave="reanudarDashCarousel()">
                                <div class="dash-carousel-indicator" id="dashCarouselCounter">1 / <?php echo count($avisos); ?></div>
                                <button type="button" class="dash-carousel-nav dash-carousel-prev" onclick="cambiarDashSlide(-1); event.stopPropagation();" aria-label="Anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <button type="button" class="dash-carousel-nav dash-carousel-next" onclick="cambiarDashSlide(1); event.stopPropagation();" aria-label="Siguiente">
                                    <i class="bi bi-chevron-right"></i>
                                </button>

                                <?php foreach ($avisos as $dIdx => $dAv): 
                                    $dImg = !empty($dAv['imagen_url_completa']) ? $dAv['imagen_url_completa'] : (!empty($dAv['imagen_url']) ? $dAv['imagen_url'] : '');
                                    $dExt = strtolower(pathinfo($dImg, PATHINFO_EXTENSION));
                                    $dEsFisica = !empty($dImg) && in_array($dExt, ['jpg', 'jpeg', 'png', 'webp', 'gif']) && (strpos($dImg, 'aviso_card_') === false);
                                    
                                    $dPrio = $dAv['prioridad'] ?? 'Normal';
                                    $dBadge = ($dPrio === 'Alta' || $dPrio === 'Urgente') ? 'bg-danger text-white' : (($dPrio === 'Media') ? 'bg-warning text-dark' : 'bg-primary text-white');
                                    
                                    $dFondoCls = 'swatch-negro';
                                    if ($dPrio === 'Alta' || $dPrio === 'Urgente') {
                                        $dFondoCls = 'swatch-rojo';
                                    } elseif ($dPrio === 'Media') {
                                        $dFondoCls = 'swatch-azul';
                                    } elseif (stripos($dAv['categoria'] ?? '', 'ciber') !== false) {
                                        $dFondoCls = 'swatch-azul';
                                    } elseif (stripos($dAv['categoria'] ?? '', 'legal') !== false || stripos($dAv['categoria'] ?? '', 'alta') !== false) {
                                        $dFondoCls = 'swatch-rojo';
                                    }

                                    if (!empty($dImg) && strpos($dImg, '.svg') !== false && file_exists(__DIR__ . '/' . $dImg)) {
                                        $svgRaw = @file_get_contents(__DIR__ . '/' . $dImg);
                                        if ($svgRaw) {
                                            if (strpos($svgRaw, '#400b11') !== false || strpos($svgRaw, '#70131e') !== false) $dFondoCls = 'swatch-rojo';
                                            elseif (strpos($svgRaw, '#0a1e3f') !== false || strpos($svgRaw, '#0d3b66') !== false) $dFondoCls = 'swatch-azul';
                                            elseif (strpos($svgRaw, '#092b1a') !== false || strpos($svgRaw, '#0f5132') !== false) $dFondoCls = 'swatch-verde';
                                            elseif (strpos($svgRaw, '#230c33') !== false || strpos($svgRaw, '#4a154b') !== false) $dFondoCls = 'swatch-purpura';
                                            elseif (strpos($svgRaw, '#050b14') !== false || strpos($svgRaw, '#101c30') !== false) $dFondoCls = 'swatch-negro';
                                        }
                                    }
                                ?>
                                    <div class="dash-carousel-slide <?php echo $dIdx === 0 ? 'active' : ''; ?>" data-dash-index="<?php echo $dIdx; ?>" onclick="cambiarSeccion('avisos')">
                                        <?php if ($dEsFisica): ?>
                                            <img src="<?php echo htmlspecialchars($dImg); ?>" alt="<?php echo htmlspecialchars($dAv['titulo']); ?>" class="dash-carousel-img" onerror="this.style.display='none'; this.nextElementSibling.classList.remove('d-none');">
                                            <div class="dash-fb-card <?php echo $dFondoCls; ?> d-none">
                                                <div class="d-flex align-items-center justify-content-between w-100 mb-2 pe-5">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="fb-card-logo" style="width: 28px; height: 28px; font-size: 0.7rem;">GH</div>
                                                        <div class="text-start">
                                                            <div class="text-white fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">GRUPO HUERTA &bull; COMUNICADO</div>
                                                            <div class="text-white text-opacity-75" style="font-size: 0.65rem;"><i class="bi bi-calendar3 me-1"></i><?php echo htmlspecialchars($dAv['fecha_publicacion']); ?></div>
                                                        </div>
                                                    </div>
                                                    <span class="badge <?php echo $dBadge; ?> fw-bold px-2 py-0.5 rounded-pill" style="font-size: 0.68rem;"><?php echo htmlspecialchars(strtoupper($dPrio)); ?></span>
                                                </div>
                                                <div class="dash-fb-body text-center my-auto py-2 px-2">
                                                    <h4 class="dash-fb-title"><?php echo htmlspecialchars($dAv['titulo']); ?></h4>
                                                    <p class="dash-fb-text"><?php echo nl2br(htmlspecialchars($dAv['descripcion'])); ?></p>
                                                </div>
                                                <div class="d-flex align-items-center justify-content-between w-100 pt-2 border-top border-white border-opacity-15" style="font-size: 0.7rem;">
                                                    <span class="text-white text-opacity-80 fw-semibold"><i class="bi bi-shield-check text-warning me-1"></i> <?php echo htmlspecialchars($dAv['codigo']); ?> &bull; <?php echo htmlspecialchars($dAv['categoria']); ?></span>
                                                    <span class="text-warning fw-bold">Ver aviso <i class="bi bi-arrow-right"></i></span>
                                                </div>
                                            </div>
                                            <div class="dash-carousel-overlay">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge <?php echo $dBadge; ?> px-2 py-0.5" style="font-size: 0.68rem; font-weight: 700;"><?php echo htmlspecialchars($dAv['codigo']); ?></span>
                                                    <span class="text-secondary" style="font-size: 0.7rem;"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($dAv['fecha_publicacion']); ?></span>
                                                </div>
                                                <div class="fw-bold text-white text-truncate mt-1" style="font-size: 0.95rem;"><?php echo htmlspecialchars($dAv['titulo']); ?></div>
                                                <div class="text-light text-opacity-75 text-truncate" style="font-size: 0.78rem;"><?php echo htmlspecialchars($dAv['descripcion']); ?></div>
                                            </div>
                                        <?php else: ?>
                                            <div class="dash-fb-card <?php echo $dFondoCls; ?>">
                                                <div class="d-flex align-items-center justify-content-between w-100 mb-2 pe-5">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="fb-card-logo" style="width: 28px; height: 28px; font-size: 0.7rem;">GH</div>
                                                        <div class="text-start">
                                                            <div class="text-white fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">GRUPO HUERTA &bull; COMUNICADO</div>
                                                            <div class="text-white text-opacity-75" style="font-size: 0.65rem;"><i class="bi bi-calendar3 me-1"></i><?php echo htmlspecialchars($dAv['fecha_publicacion']); ?></div>
                                                        </div>
                                                    </div>
                                                    <span class="badge <?php echo $dBadge; ?> fw-bold px-2 py-0.5 rounded-pill" style="font-size: 0.68rem;"><?php echo htmlspecialchars(strtoupper($dPrio)); ?></span>
                                                </div>
                                                <div class="dash-fb-body text-center my-auto py-2 px-2">
                                                    <h4 class="dash-fb-title"><?php echo htmlspecialchars($dAv['titulo']); ?></h4>
                                                    <p class="dash-fb-text"><?php echo nl2br(htmlspecialchars($dAv['descripcion'])); ?></p>
                                                </div>
                                                <div class="d-flex align-items-center justify-content-between w-100 pt-2 border-top border-white border-opacity-15" style="font-size: 0.7rem;">
                                                    <span class="text-white text-opacity-80 fw-semibold"><i class="bi bi-shield-check text-warning me-1"></i> <?php echo htmlspecialchars($dAv['codigo']); ?> &bull; <?php echo htmlspecialchars($dAv['categoria']); ?></span>
                                                    <span class="text-warning fw-bold">Ver aviso <i class="bi bi-arrow-right"></i></span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center text-secondary rounded-3" style="background: rgba(0,0,0,0.2);">
                                <i class="bi bi-megaphone opacity-50 display-6 d-block mb-2"></i>
                                No hay avisos activos registrados.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </section>

        <!-- ===================================================================== -->
        <!-- SECCIÓN 2: FORMATOS                                                   -->
        <!-- ===================================================================== -->
        <section class="compliance-section <?php echo $seccionActiva === 'formatos' ? 'active' : ''; ?>" id="sec-formatos">
            
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h2 class="h3 fw-bold text-white m-0 d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-text-fill text-warning"></i> Formatos Oficiales
                    </h2>
                    <p class="text-secondary small mb-0">Cartas responsivas, solicitudes de cuentas y bitácoras oficiales de Grupo Huerta.</p>
                </div>
                <div class="d-flex gap-2">
                    <input type="text" id="filtroFormatos" class="search-control-box" placeholder="Buscar formato por título o código..." style="min-width: 260px;">
                </div>
            </div>

            <!-- Grid de Formatos -->
            <div class="row g-3" id="contenedorFormatos">
                <?php if (empty($formatos)): ?>
                    <div class="col-12 py-5 text-center text-secondary">
                        <i class="bi bi-folder2-open display-4 opacity-50 d-block mb-3"></i>
                        No hay formatos registrados actualmente.
                    </div>
                <?php else: ?>
                    <?php foreach ($formatos as $fmt): ?>
                        <div class="col-12 col-md-6 col-xl-4 item-tarjeta-formato" data-search="<?php echo htmlspecialchars(strtolower($fmt['codigo'] . ' ' . $fmt['titulo'] . ' ' . $fmt['categoria'])); ?>">
                            <div class="doc-item-card h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="doc-code-badge"><?php echo htmlspecialchars($fmt['codigo']); ?></span>
                                        <span class="category-tag"><?php echo htmlspecialchars($fmt['categoria']); ?></span>
                                    </div>

                                    <h3 class="h6 fw-bold text-white mb-2" style="line-height: 1.4;">
                                        <?php echo htmlspecialchars($fmt['titulo']); ?>
                                    </h3>
                                    <p class="text-secondary mb-3" style="font-size: 0.8rem; line-height: 1.5; min-height: 48px;">
                                        <?php echo htmlspecialchars($fmt['descripcion']); ?>
                                    </p>
                                </div>

                                <div>
                                    <div class="d-flex align-items-center justify-content-between text-secondary mb-3 pt-2 border-top border-secondary border-opacity-10" style="font-size: 0.72rem;">
                                        <span><i class="bi bi-hash"></i> Versión <?php echo htmlspecialchars($fmt['version']); ?></span>
                                        <span><i class="bi bi-file-earmark"></i> <?php echo htmlspecialchars(strtoupper($fmt['archivo_tipo'] ?? 'PDF')); ?> (<?php echo htmlspecialchars($fmt['archivo_tamano'] ?? '400 KB'); ?>)</span>
                                    </div>

                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-warning w-100 rounded-3" onclick="verDocumento(<?php echo htmlspecialchars(json_encode($fmt)); ?>)">
                                            <i class="bi bi-eye"></i> Visualizar
                                        </button>
                                        <?php if (!empty($fmt['archivo_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($fmt['archivo_url']); ?>" download class="btn btn-sm btn-warning text-dark fw-bold rounded-3 px-3" title="Descargar Formato">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('¿Confirmas que deseas eliminar este formato oficial?');" class="m-0">
                                            <input type="hidden" name="accion" value="eliminar_documento">
                                            <input type="hidden" name="documento_id" value="<?php echo $fmt['id']; ?>">
                                            <input type="hidden" name="seccion" value="formatos">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 d-inline-flex align-items-center gap-1" title="Eliminar Formato">
                                                <i class="bi bi-trash3-fill"></i> Borrar
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </section>

        <!-- ===================================================================== -->
        <!-- SECCIÓN 3: MANUALES                                                   -->
        <!-- ===================================================================== -->
        <section class="compliance-section <?php echo $seccionActiva === 'manuales' ? 'active' : ''; ?>" id="sec-manuales">
            
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h2 class="h3 fw-bold text-white m-0 d-flex align-items-center gap-2">
                        <i class="bi bi-book-half text-warning"></i> Manuales de Procedimientos
                    </h2>
                    <p class="text-secondary small mb-0">Manuales operativos, protocolos de seguridad de la información y guías de usuario.</p>
                </div>
                <div>
                    <input type="text" id="filtroManuales" class="search-control-box" placeholder="Buscar manual por título..." style="min-width: 260px;">
                </div>
            </div>

            <!-- Grid de Manuales -->
            <div class="row g-3" id="contenedorManuales">
                <?php if (empty($manuales)): ?>
                    <div class="col-12 py-5 text-center text-secondary">
                        <i class="bi bi-book display-4 opacity-50 d-block mb-3"></i>
                        No hay manuales registrados actualmente.
                    </div>
                <?php else: ?>
                    <?php foreach ($manuales as $man): ?>
                        <div class="col-12 col-md-6 col-xl-4 item-tarjeta-manual" data-search="<?php echo htmlspecialchars(strtolower($man['codigo'] . ' ' . $man['titulo'] . ' ' . $man['categoria'])); ?>">
                            <div class="doc-item-card h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="doc-code-badge"><?php echo htmlspecialchars($man['codigo']); ?></span>
                                        <span class="category-tag"><?php echo htmlspecialchars($man['categoria']); ?></span>
                                    </div>

                                    <h3 class="h6 fw-bold text-white mb-2" style="line-height: 1.4;">
                                        <?php echo htmlspecialchars($man['titulo']); ?>
                                    </h3>
                                    <p class="text-secondary mb-3" style="font-size: 0.8rem; line-height: 1.5; min-height: 48px;">
                                        <?php echo htmlspecialchars($man['descripcion']); ?>
                                    </p>
                                </div>

                                <div>
                                    <div class="d-flex align-items-center justify-content-between text-secondary mb-3 pt-2 border-top border-secondary border-opacity-10" style="font-size: 0.72rem;">
                                        <span><i class="bi bi-hash"></i> Versión <?php echo htmlspecialchars($man['version']); ?></span>
                                        <span><i class="bi bi-file-earmark-pdf"></i> <?php echo htmlspecialchars($man['archivo_tamano'] ?? '1.2 MB'); ?></span>
                                    </div>

                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-warning w-100 rounded-3" onclick="verDocumento(<?php echo htmlspecialchars(json_encode($man)); ?>)">
                                            <i class="bi bi-book"></i> Consultar Manual
                                        </button>
                                        <?php if (!empty($man['archivo_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($man['archivo_url']); ?>" download class="btn btn-sm btn-warning text-dark fw-bold rounded-3 px-3" title="Descargar Manual">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('¿Confirmas que deseas eliminar este manual?');" class="m-0">
                                            <input type="hidden" name="accion" value="eliminar_documento">
                                            <input type="hidden" name="documento_id" value="<?php echo $man['id']; ?>">
                                            <input type="hidden" name="seccion" value="manuales">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 d-inline-flex align-items-center gap-1" title="Eliminar Manual">
                                                <i class="bi bi-trash3-fill"></i> Borrar
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </section>

        <!-- ===================================================================== -->
        <!-- SECCIÓN 4: AVISOS (GALERÍA INTERACTIVA DE IMÁGENES / CARROUSEL)       -->
        <!-- ===================================================================== -->
        <section class="compliance-section <?php echo $seccionActiva === 'avisos' ? 'active' : ''; ?>" id="sec-avisos">
            
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                    <h2 class="h3 fw-bold text-white m-0 d-flex align-items-center gap-2">
                        <i class="bi bi-megaphone-fill text-warning"></i> Avisos
                    </h2>
                    <p class="text-secondary small mb-0">Comunicados oficiales, avisos de privacidad y alertas institucionales de Grupo Huerta.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btnToggleVistaAvisos" onclick="toggleVistaAvisos()">
                        <i class="bi bi-grid-fill me-1"></i> <span id="txtToggleVista">Ver Cuadrícula</span>
                    </button>
                    <input type="text" id="filtroAvisos" class="search-control-box" placeholder="Buscar aviso..." style="min-width: 220px;">
                </div>
            </div>

            <?php if (empty($avisos)): ?>
                <div class="py-5 text-center text-secondary">
                    <i class="bi bi-bell-slash display-4 opacity-50 d-block mb-3"></i>
                    No hay avisos registrados actualmente.
                </div>
            <?php else: ?>

                <!-- 1. VISTA CARRUSEL CONTINUO DE AVISOS -->
                <div class="avisos-gallery-wrapper" id="vistaGaleriaAvisos">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-warning text-dark fw-bold px-3 py-1.5 rounded-pill">
                                <i class="bi bi-megaphone-fill me-1"></i> AVISOS
                            </span>
                            <span class="text-secondary small" id="indicadorGaleriaTexto">Aviso 1 de <?php echo count($avisos); ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle" id="btnPausaGaleria" onclick="togglePlayGaleria()" title="Pausar / Reanudar Rotación" style="width: 34px; height: 34px; padding: 0;">
                                <i class="bi bi-pause-fill" id="iconoPausa"></i>
                            </button>
                        </div>
                    </div>

                    <div class="gallery-main-stage" id="galleryMainStage" onmouseenter="pausarAutoPlay()" onmouseleave="reanudarAutoPlay()">
                        <div class="gallery-autoplay-bar" id="galleryProgressBar"></div>

                        <!-- Botones de Navegación -->
                        <button type="button" class="gallery-controls-btn gallery-btn-prev" onclick="cambiarSlideGaleria(-1)" aria-label="Aviso Anterior">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button type="button" class="gallery-controls-btn gallery-btn-next" onclick="cambiarSlideGaleria(1)" aria-label="Aviso Siguiente">
                            <i class="bi bi-chevron-right"></i>
                        </button>

                        <!-- 1. ÁREA VISUAL DE DIAPOSITIVAS (IMAGEN O TARJETA DE TEXTO) -->
                        <div class="gallery-slides-viewport">
                            <?php foreach ($avisos as $index => $av): 
                                $imgSrc = !empty($av['imagen_url_completa']) ? $av['imagen_url_completa'] : (!empty($av['imagen_url']) ? $av['imagen_url'] : '');
                                $esFisica = $av['es_fisica'] ?? false;
                                
                                $prio = strtoupper($av['prioridad'] ?? 'Normal');
                                $badgeCls = ($prio === 'ALTA' || $prio === 'URGENTE') ? 'bg-danger text-white' : (($prio === 'MEDIA') ? 'bg-warning text-dark' : 'bg-primary text-white');
                                $fondoCls = $av['fondo_cls'] ?? 'swatch-negro';
                            ?>
                                <div class="gallery-slide-item <?php echo $index === 0 ? 'active' : ''; ?>" data-slide-index="<?php echo $index; ?>">
                                    <?php if ($esFisica): ?>
                                        <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($av['titulo']); ?>" class="gallery-slide-img" onclick="abrirLightbox(<?php echo $index; ?>)">
                                    <?php else: ?>
                                        <div class="gallery-fb-slide-card <?php echo $fondoCls; ?>" onclick="abrirLightbox(<?php echo $index; ?>)">
                                            <div class="d-flex align-items-center justify-content-between w-100 mb-2">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="fb-card-logo">GH</div>
                                                    <div class="text-start">
                                                        <div class="text-white fw-bold small">GRUPO HUERTA &bull; COMPLIANCE INSTITUCIONAL</div>
                                                        <div class="text-secondary" style="font-size: 0.68rem;">Comunicado Oficial &bull; <?php echo htmlspecialchars($av['fecha_publicacion']); ?></div>
                                                    </div>
                                                </div>
                                                <span class="badge <?php echo $badgeCls; ?> fw-bold px-3 py-1.5 rounded-pill" style="font-size: 0.72rem;"><?php echo htmlspecialchars(strtoupper($av['prioridad'])); ?></span>
                                            </div>
                                            
                                            <div class="gallery-fb-content text-center my-auto py-2">
                                                <h2 class="gallery-fb-titulo"><?php echo htmlspecialchars($av['titulo']); ?></h2>
                                                <p class="gallery-fb-desc"><?php echo nl2br(htmlspecialchars($av['descripcion'])); ?></p>
                                            </div>

                                            <div class="d-flex align-items-center justify-content-between w-100 pt-2 border-top border-secondary border-opacity-25" style="font-size: 0.72rem;">
                                                <span class="text-secondary fw-semibold"><i class="bi bi-shield-check text-warning me-1"></i> CÓDIGO: <?php echo htmlspecialchars($av['codigo']); ?> &bull; <?php echo htmlspecialchars($av['categoria']); ?></span>
                                                <span class="text-warning text-opacity-75"><i class="bi bi-patch-check-fill me-1"></i> Emisión Oficial Certificada</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- 2. PIE FIJO DEL ANUNCIO (NUNCA SE MUEVE, SOLO CAMBIA SU TEXTO) -->
                        <div class="gallery-static-footer" id="galleryStaticFooter">
                            <div style="max-width: 750px;">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="doc-code-badge" id="ftAvisoCodigo"></span>
                                    <span class="doc-priority-badge" id="ftAvisoPrioridad"></span>
                                    <span class="category-tag" id="ftAvisoCategoria"></span>
                                    <span class="text-secondary small ms-2"><i class="bi bi-calendar3"></i> <span id="ftAvisoFecha"></span></span>
                                </div>
                                <h3 class="h5 fw-bold text-white mb-1 text-truncate" id="ftAvisoTitulo"></h3>
                                <p class="text-light text-opacity-75 mb-0 text-truncate" style="font-size: 0.85rem; max-width: 680px;" id="ftAvisoDescripcion"></p>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                <button type="button" class="btn btn-sm btn-gold-primary rounded-pill px-3" id="ftBtnZoom" onclick="abrirLightboxAvisoActual()">
                                    <i class="bi bi-arrows-fullscreen me-1"></i> Pantalla Completa
                                </button>
                                <a href="#" download class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 d-none" id="ftBtnAnexo">
                                    <i class="bi bi-download me-1"></i> Anexo
                                </a>
                                <?php if ($esAdmin): ?>
                                    <form method="POST" onsubmit="return confirm('¿Confirmas que deseas eliminar este aviso?');" class="m-0" id="ftFormEliminar">
                                        <input type="hidden" name="accion" value="eliminar_documento">
                                        <input type="hidden" name="documento_id" id="ftAvisoIdEliminar" value="">
                                        <input type="hidden" name="seccion" value="avisos">
                                        <button type="submit" class="btn btn-sm btn-danger text-white fw-bold rounded-pill px-3 d-inline-flex align-items-center gap-1" title="Eliminar Aviso">
                                            <i class="bi bi-trash3-fill"></i> Borrar Aviso
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tira de Miniaturas Inferior -->
                    <div class="gallery-thumbs-strip" id="galleryThumbsStrip">
                        <?php foreach ($avisos as $idx => $av): 
                            $thumbImg = !empty($av['imagen_url_completa']) ? $av['imagen_url_completa'] : (!empty($av['imagen_url']) ? $av['imagen_url'] : '');
                            $tExt = strtolower(pathinfo($thumbImg, PATHINFO_EXTENSION));
                            $tEsFisica = !empty($thumbImg) && in_array($tExt, ['jpg', 'jpeg', 'png', 'webp', 'gif']) && (strpos($thumbImg, 'aviso_card_') === false);
                            $tFondoCls = ($av['prioridad'] === 'Alta' || $av['prioridad'] === 'Urgente') ? 'swatch-rojo' : (($av['prioridad'] === 'Media') ? 'swatch-azul' : 'swatch-negro');
                        ?>
                            <div class="gallery-thumb-card <?php echo $idx === 0 ? 'active' : ''; ?>" data-thumb-index="<?php echo $idx; ?>" onclick="irASlideGaleria(<?php echo $idx; ?>)" title="<?php echo htmlspecialchars($av['titulo']); ?>">
                                <?php if ($tEsFisica): ?>
                                    <img src="<?php echo htmlspecialchars($thumbImg); ?>" alt="Miniatura <?php echo htmlspecialchars($av['codigo']); ?>" onerror="this.style.display='none'; this.nextElementSibling.classList.remove('d-none');">
                                    <div class="w-100 h-100 d-none d-flex flex-column align-items-center justify-content-center <?php echo $tFondoCls; ?>" style="padding: 4px;">
                                        <span class="fw-bold text-white" style="font-size: 0.7rem;">GH</span>
                                    </div>
                                <?php else: ?>
                                    <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center <?php echo $tFondoCls; ?>" style="padding: 4px;">
                                        <div class="fb-card-logo" style="width: 20px; height: 20px; font-size: 0.55rem; margin-bottom: 2px;">GH</div>
                                        <div class="text-white text-truncate w-100 text-center" style="font-size: 0.55rem; font-weight: 700;"><?php echo htmlspecialchars($av['codigo']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="position-absolute bottom-0 start-0 end-0 p-1 text-center small text-white fw-bold" style="background: rgba(0,0,0,0.7); font-size: 0.65rem;">
                                    <?php echo htmlspecialchars($av['codigo']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 2. VISTA CUADRÍCULA ALTERNA (OCULTA POR DEFECTO) -->
                <div class="row g-3 d-none" id="vistaCuadriculaAvisos">
                    <?php foreach ($avisos as $av): 
                        $imgSrc = !empty($av['imagen_url_completa']) ? $av['imagen_url_completa'] : (!empty($av['imagen_url']) ? $av['imagen_url'] : '');
                        $avExt = strtolower(pathinfo($imgSrc, PATHINFO_EXTENSION));
                        $avEsFisica = !empty($imgSrc) && in_array($avExt, ['jpg', 'jpeg', 'png', 'webp', 'gif']) && (strpos($imgSrc, 'aviso_card_') === false);
                        $avFondoCls = ($av['prioridad'] === 'Alta' || $av['prioridad'] === 'Urgente') ? 'swatch-rojo' : (($av['prioridad'] === 'Media') ? 'swatch-azul' : 'swatch-negro');
                        if (stripos($av['categoria'], 'ciber') !== false) $avFondoCls = 'swatch-azul';
                        elseif (stripos($av['categoria'], 'legal') !== false) $avFondoCls = 'swatch-rojo';
                        $pClass = 'priority-normal';
                        if ($av['prioridad'] === 'Alta') $pClass = 'priority-alta';
                        elseif ($av['prioridad'] === 'Media') $pClass = 'priority-media';
                    ?>
                        <div class="col-12 col-md-6 item-tarjeta-aviso" data-search="<?php echo htmlspecialchars(strtolower($av['codigo'] . ' ' . $av['titulo'] . ' ' . $av['descripcion'] . ' ' . $av['prioridad'])); ?>">
                            <div class="doc-item-card h-100 d-flex flex-column justify-content-between p-3">
                                <div>
                                    <?php if ($avEsFisica): ?>
                                        <div class="rounded-3 overflow-hidden mb-3 border border-secondary border-opacity-25" style="background: #030812; cursor: pointer;" onclick="abrirLightbox('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars(addslashes($av['titulo'])); ?>')">
                                            <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($av['titulo']); ?>" class="w-100" style="max-height: 220px; object-fit: contain;">
                                        </div>
                                    <?php else: ?>
                                        <div class="rounded-3 overflow-hidden mb-3 p-3 <?php echo $avFondoCls; ?> border border-secondary border-opacity-25 d-flex flex-column justify-content-center text-center" style="min-height: 140px; cursor: pointer;" onclick="abrirLightbox('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars(addslashes($av['titulo'])); ?>')">
                                            <div class="fb-card-logo mx-auto mb-2" style="width: 32px; height: 32px; font-size: 0.75rem;">GH</div>
                                            <div class="fw-bold text-white small mb-1"><?php echo htmlspecialchars($av['titulo']); ?></div>
                                            <div class="text-white text-opacity-75" style="font-size: 0.72rem;"><?php echo htmlspecialchars($av['codigo']); ?> &bull; <?php echo htmlspecialchars($av['fecha_publicacion']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <span class="doc-code-badge"><?php echo htmlspecialchars($av['codigo']); ?></span>
                                        <span class="doc-priority-badge <?php echo $pClass; ?>">Prioridad <?php echo htmlspecialchars($av['prioridad']); ?></span>
                                    </div>
                                    <h4 class="h6 fw-bold text-white mb-2"><?php echo htmlspecialchars($av['titulo']); ?></h4>
                                    <p class="text-secondary small mb-3"><?php echo htmlspecialchars($av['descripcion']); ?></p>
                                </div>

                                <div class="d-flex align-items-center justify-content-between pt-2 border-top border-secondary border-opacity-10">
                                    <span class="text-secondary" style="font-size: 0.72rem;"><?php echo htmlspecialchars($av['fecha_publicacion']); ?></span>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3" onclick="abrirLightbox('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars(addslashes($av['titulo'])); ?>')">
                                            <i class="bi bi-arrows-fullscreen"></i>
                                        </button>
                                        <?php if (!empty($av['archivo_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($av['archivo_url']); ?>" download class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('¿Confirmas que deseas eliminar este aviso?');" class="m-0">
                                            <input type="hidden" name="accion" value="eliminar_documento">
                                            <input type="hidden" name="documento_id" value="<?php echo $av['id']; ?>">
                                            <input type="hidden" name="seccion" value="avisos">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 d-inline-flex align-items-center gap-1" title="Eliminar Aviso">
                                                <i class="bi bi-trash3-fill"></i> Borrar
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </section>

    </main>
</div>

<!-- ========================================================================= -->
<!-- MODAL: NUEVO DOCUMENTO / AVISO (ADMINISTRADOR)                            -->
<!-- ========================================================================= -->
<?php if ($esAdmin): ?>
<div class="modal fade" id="modalNuevoDocumento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-content-gold">
            <div class="modal-header modal-header-gold">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                    <i class="bi bi-cloud-arrow-up-fill text-warning"></i> Nuevo Registro de Compliance
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="subir_documento">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Sección / Tipo *</label>
                            <select name="tipo" id="selTipoDoc" class="form-select form-select-gold" required onchange="onTipoDocChange()">
                                <option value="aviso" selected>Avisos</option>
                                <option value="formato">Formatos Oficiales</option>
                                <option value="manual">Manuales de Procedimiento</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Código Oficial *</label>
                            <input type="text" name="codigo" id="inpCodigoDoc" class="form-control form-control-gold" placeholder="Ej. AV-05 o FR-TI-03" required value="AV-0<?php echo $totalAvisos + 1; ?>" oninput="actualizarPreviewFacebook()">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Categoría *</label>
                            <select name="categoria" class="form-select form-select-gold">
                                <option value="Legal & Cumplimiento">Legal & Cumplimiento</option>
                                <option value="Ciberseguridad">Ciberseguridad</option>
                                <option value="Dirección General">Dirección General</option>
                                <option value="Recursos Humanos">Recursos Humanos</option>
                                <option value="Tecnologías de la Información">Tecnologías de la Información</option>
                                <option value="Operaciones & Soporte">Operaciones & Soporte</option>
                                <option value="Seguridad Patrimonial">Seguridad Patrimonial</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <label class="form-label-gold m-0">Título Oficial *</label>
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <span class="text-secondary small me-1" style="font-size: 0.72rem;">Emojis:</span>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '📢')">📢</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '⚠️')">⚠️</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '🚨')">🚨</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '🔒')">🔒</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '🛡️')">🛡️</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '📋')">📋</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '📌')">📌</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '💡')">💡</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '✅')">✅</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpTituloDoc', '🚀')">🚀</button>
                                </div>
                            </div>
                            <input type="text" name="titulo" id="inpTituloDoc" class="form-control form-control-gold" placeholder="Título formal del comunicado o documento" required oninput="actualizarPreviewFacebook()">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Versión</label>
                            <input type="text" name="version" class="form-control form-control-gold" value="1.0" placeholder="1.0">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Prioridad</label>
                            <select name="prioridad" id="selPrioridadDoc" class="form-select form-select-gold" onchange="actualizarPreviewFacebook()">
                                <option value="Alta">Alta</option>
                                <option value="Media">Media</option>
                                <option value="Normal">Normal</option>
                                <option value="Urgente">Urgente</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Fecha Vigencia</label>
                            <input type="date" name="fecha_vigencia" class="form-control form-control-gold" value="<?php echo date('Y') + 1; ?>-12-31">
                        </div>

                        <!-- CREADOR DE TARJETA ESTILO FACEBOOK (SOLO AVISOS) -->
                        <div class="col-12" id="boxCreadorFacebookAviso">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label-gold m-0">
                                    <i class="bi bi-palette-fill text-warning me-1"></i> Diseño de Tarjeta Tipo Facebook (Generación de Imagen)
                                </label>
                                <span class="badge bg-dark text-warning border border-warning border-opacity-25 small px-2 py-1">
                                    <i class="bi bi-sparkles me-1"></i> En vivo como Facebook
                                </span>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-3 mb-2 p-2 rounded-3" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(212,175,55,0.2);">
                                <span class="text-secondary small fw-bold">Elegir Fondo:</span>
                                <div class="fb-theme-picker m-0">
                                    <button type="button" class="fb-swatch swatch-negro active" data-tema="negro_oro" title="Negro Carbón & Oro Imperial" onclick="seleccionarTemaFondo('negro_oro')"></button>
                                    <button type="button" class="fb-swatch swatch-azul" data-tema="azul_zafiro" title="Azul Zafiro Corporativo" onclick="seleccionarTemaFondo('azul_zafiro')"></button>
                                    <button type="button" class="fb-swatch swatch-rojo" data-tema="rojo_rubi" title="Rojo Rubí Alta Dirección" onclick="seleccionarTemaFondo('rojo_rubi')"></button>
                                    <button type="button" class="fb-swatch swatch-verde" data-tema="esmeralda" title="Verde Esmeralda Normativo" onclick="seleccionarTemaFondo('esmeralda')"></button>
                                    <button type="button" class="fb-swatch swatch-purpura" data-tema="purpura" title="Púrpura Presidencial" onclick="seleccionarTemaFondo('purpura')"></button>
                                </div>
                                <span class="text-secondary small ms-auto fst-italic" id="fbNombreTema">Tema: Negro Carbón & Oro</span>
                            </div>
                            <input type="hidden" name="estilo_fondo" id="inpEstiloFondo" value="negro_oro">

                            <!-- Vista Previa de la Tarjeta en Vivo -->
                            <div class="fb-live-card swatch-negro" id="fbLivePreviewCard">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="width: 28px; height: 28px; border-radius: 50%; background: #d4af37; color: #040d1a; font-weight: 900; display: flex; align-items: center; justify-content: center; font-size: 0.72rem;">GH</div>
                                        <div>
                                            <div class="text-white fw-bold" style="font-size: 0.78rem;">GRUPO HUERTA &bull; COMPLIANCE</div>
                                            <div class="text-secondary" style="font-size: 0.65rem;" id="fbPreviewFecha">Oficial &bull; <?php echo date('d/m/Y'); ?></div>
                                        </div>
                                    </div>
                                    <span class="badge bg-warning text-dark fw-bold px-2 py-0.5" id="fbPreviewBadge" style="font-size: 0.68rem;">ALTA DIRECCIÓN</span>
                                </div>
                                <div class="fb-live-card-body">
                                    <div class="fb-live-card-text" id="fbPreviewTexto" style="font-size: 1.25rem;">
                                        Escribe aquí tu aviso institucional...
                                    </div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between pt-2 border-top border-secondary border-opacity-25">
                                    <span class="text-secondary" style="font-size: 0.68rem;" id="fbPreviewCodigo">CÓDIGO: AV-0<?php echo $totalAvisos + 1; ?></span>
                                    <span class="text-warning text-opacity-75" style="font-size: 0.68rem;"><i class="bi bi-shield-check"></i> Documento Oficial Certificado</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <label class="form-label-gold m-0">Texto / Contenido del Aviso *</label>
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <span class="text-secondary small me-1" style="font-size: 0.72rem;">Emojis:</span>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '📢')">📢</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '⚠️')">⚠️</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '🚨')">🚨</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '🔒')">🔒</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '🛡️')">🛡️</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '📄')">📄</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '📌')">📌</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '💡')">💡</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '🔔')">🔔</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '✅')">✅</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '❌')">❌</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '🚀')">🚀</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '💼')">💼</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '🏢')">🏢</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', '👍')">👍</button>
                                    <button type="button" class="btn-emoji-quick" onclick="insertarEmoji('inpDescripcionAviso', 'ℹ️')">ℹ️</button>
                                </div>
                            </div>
                            <textarea name="descripcion" id="inpDescripcionAviso" class="form-control form-control-gold" rows="3" placeholder="Escribe aquí el texto del aviso (se reflejará en tiempo real en la tarjeta estilo Facebook arriba)..." oninput="actualizarPreviewFacebook()"></textarea>
                        </div>

                        <!-- Campo Imagen para Avisos -->
                        <div class="col-12" id="boxImagenAviso">
                            <label class="form-label-gold">
                                <i class="bi bi-image text-warning"></i> O bien, adjunta una Imagen Física (Opcional)
                            </label>
                            <input type="file" name="imagen_aviso" id="inpImagenAviso" class="form-control form-control-gold" accept=".jpg,.jpeg,.png,.webp,.svg,.gif" onchange="onImagenAvisoSeleccionada(this)">
                            <div class="form-text text-secondary" style="font-size: 0.74rem;" id="boxImagenAvisoHelp">
                                <i class="bi bi-info-circle text-warning"></i> Si no seleccionas una imagen física, el sistema guardará automáticamente la tarjeta visual que estás previsualizando.
                            </div>
                        </div>

                        <!-- Campo Archivo / Anexo -->
                        <div class="col-12">
                            <label class="form-label-gold">Archivo Adjunto / Anexo Oficial (PDF, DOCX, XLSX)</label>
                            <input type="file" name="archivo" class="form-control form-control-gold" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
                            <div class="form-text text-secondary" style="font-size: 0.72rem;">Máximo 25 MB. Formatos permitidos: PDF, DOCX, XLSX.</div>
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="obligatorio_lectura" id="chkObligatorio" value="1">
                                <label class="form-check-label small text-secondary" for="chkObligatorio">
                                    Marcar como lectura institucional obligatoria para colaboradores
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer modal-footer-gold">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-gold-primary">
                        <i class="bi bi-cloud-arrow-up-fill"></i> Publicar en Compliance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL: LIGHTBOX / ZOOM DE IMAGEN O TARJETA DE AVISO                        -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalLightboxAviso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content modal-content-gold">
            <div class="modal-header modal-header-gold">
                <div class="d-flex align-items-center gap-2">
                    <span class="doc-code-badge" id="lightboxCodigo">AV</span>
                    <h5 class="modal-title fw-bold text-white m-0" id="lightboxTitulo"></h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3 p-md-4 text-center bg-black position-relative d-flex flex-column align-items-center justify-content-center">
                <!-- CASO 1: IMAGEN FÍSICA -->
                <img id="lightboxImg" src="" alt="Aviso Ampliado" class="img-fluid rounded-3 d-none" style="max-height: 75vh; width: auto; object-fit: contain;">
                
                <!-- CASO 2: TARJETA DE TEXTO CON COLOR COMPLETO (SI NO TIENE IMAGEN) -->
                <div id="lightboxCardWrapper" class="w-100 d-none" style="max-width: 900px;">
                    <div id="lightboxFbCard" class="gallery-fb-slide-card w-100 p-4 p-md-5" style="min-height: 400px; cursor: default;">
                        <div class="d-flex align-items-center justify-content-between w-100 mb-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="fb-card-logo" style="width: 38px; height: 38px; font-size: 0.9rem;">GH</div>
                                <div class="text-start">
                                    <div class="text-white fw-bold" style="font-size: 0.85rem; letter-spacing: 0.6px;">GRUPO HUERTA &bull; COMPLIANCE INSTITUCIONAL</div>
                                    <div class="text-white text-opacity-75" style="font-size: 0.72rem;" id="lightboxCardFecha">Comunicado Oficial</div>
                                </div>
                            </div>
                            <span class="badge fw-bold px-3 py-1.5 rounded-pill" style="font-size: 0.75rem;" id="lightboxCardPrioridad">ALTA</span>
                        </div>
                        
                        <div class="gallery-fb-content text-center my-auto py-3 px-2">
                            <h2 class="gallery-fb-titulo mb-3" id="lightboxCardTitulo" style="font-size: 1.9rem; font-weight: 800; line-height: 1.35; text-shadow: 0 2px 10px rgba(0,0,0,0.85);"></h2>
                            <p class="gallery-fb-desc" id="lightboxCardDesc" style="font-size: 1.15rem; line-height: 1.6; max-width: 780px; margin: 0 auto; text-shadow: 0 1px 5px rgba(0,0,0,0.8);"></p>
                        </div>

                        <div class="d-flex align-items-center justify-content-between w-100 pt-3 border-top border-white border-opacity-15" style="font-size: 0.75rem;">
                            <span class="text-white text-opacity-80 fw-semibold" id="lightboxCardMeta">
                                <i class="bi bi-shield-check text-warning me-1"></i> Emisión Oficial Certificada
                            </span>
                            <span class="text-warning text-opacity-90">
                                <i class="bi bi-patch-check-fill me-1"></i> Portal Grupo Huerta
                            </span>
                        </div>
                    </div>
                </div>

                <!-- LEYENDA INFERIOR: SOLO PORTAL GRUPO HUERTA (SIN IP Y SIN USUARIO) -->
                <div class="small text-secondary mt-3">
                    <i class="bi bi-shield-lock-fill text-warning me-1"></i> Portal Grupo Huerta
                </div>
            </div>
            <div class="modal-footer modal-footer-gold justify-content-between">
                <a id="lightboxBtnDescargar" href="#" download class="btn btn-warning text-dark fw-bold rounded-pill px-4">
                    <i class="bi bi-download me-1"></i> Descargar Imagen
                </a>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 ms-auto" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: VISOR / FICHA DETALLE DEL DOCUMENTO                                -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalVisorDocumento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-content-gold">
            <div class="modal-header modal-header-gold">
                <div>
                    <span id="vDocCodigo" class="doc-code-badge mb-1"></span>
                    <h5 id="vDocTitulo" class="modal-title fw-bold text-white m-0"></h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 rounded-3 mb-3" style="background: rgba(4, 13, 26, 0.7); border: 1px solid var(--gold-border);">
                    <div class="row g-2 text-secondary small">
                        <div class="col-6 col-sm-3"><strong>Categoría:</strong> <span id="vDocCategoria" class="text-white"></span></div>
                        <div class="col-6 col-sm-3"><strong>Versión:</strong> <span id="vDocVersion" class="text-white"></span></div>
                        <div class="col-6 col-sm-3"><strong>Prioridad:</strong> <span id="vDocPrioridad" class="text-white"></span></div>
                        <div class="col-6 col-sm-3"><strong>Publicación:</strong> <span id="vDocFecha" class="text-white"></span></div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label-gold">Descripción y Alcance Institucional</label>
                    <p id="vDocDescripcion" class="text-light" style="line-height: 1.6; font-size: 0.92rem;"></p>
                </div>

                <div id="vDocArchivoBox" class="p-4 rounded-3 text-center" style="background: rgba(212, 175, 55, 0.05); border: 1px dashed var(--gold-border);">
                    <i class="bi bi-file-earmark-pdf-fill text-warning display-4 d-block mb-2"></i>
                    <h6 class="text-white fw-bold mb-1">Documento Oficial Protegido</h6>
                    <p class="text-secondary small mb-3" style="font-size: 0.78rem;">
                        <i class="bi bi-shield-check text-warning me-1"></i> Portal Grupo Huerta &bull; Repositorio Institucional
                    </p>
                    <div id="vDocBtnContainer"></div>
                </div>
            </div>
            <div class="modal-footer modal-footer-gold">
                <button type="button" class="btn btn-outline-secondary rounded-3 px-4" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =============================================================================
// NAVEGACIÓN Y MENÚ LATERAL
// =============================================================================
function cambiarSeccion(seccionId) {
    document.querySelectorAll('.compliance-section').forEach(sec => sec.classList.remove('active'));
    document.querySelectorAll('.compliance-nav-item').forEach(item => item.classList.remove('active'));

    const secTarget = document.getElementById('sec-' + seccionId);
    if (secTarget) {
        secTarget.classList.add('active');
    }

    const navTarget = document.querySelector(`.compliance-nav-item[data-tab-target="${seccionId}"]`);
    if (navTarget) {
        navTarget.classList.add('active');
    }

    if (history.pushState) {
        const nuevaUrl = window.location.pathname + '?seccion=' + seccionId;
        window.history.pushState({path: nuevaUrl}, '', nuevaUrl);
    }

    const sidebar = document.getElementById('complianceSidebar');
    if (sidebar && window.innerWidth < 992) {
        sidebar.classList.remove('open');
    }
}

document.querySelectorAll('.compliance-nav-item').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const tab = this.getAttribute('data-tab-target');
        if (tab) cambiarSeccion(tab);
    });
});

const btnToggle = document.getElementById('sidebarToggle');
if (btnToggle) {
    btnToggle.addEventListener('click', () => {
        const sidebar = document.getElementById('complianceSidebar');
        if (sidebar) sidebar.classList.toggle('open');
    });
}

// =============================================================================
// MOTOR DE LA GALERÍA / CARRUSEL CONTINUO DE AVISOS
// =============================================================================
const avisosGaleriaData = <?php echo json_encode(array_values($avisos)); ?>;
let currentSlideIndex = 0;
const slides = document.querySelectorAll('.gallery-slide-item');
const thumbs = document.querySelectorAll('.gallery-thumb-card');
const totalSlides = slides.length;
let autoPlayTimer = null;
let autoPlayPausado = false;
let progresoTimer = null;
let progresoPct = 0;
const TIEMPO_SLIDE_MS = 5000;

function mostrarSlide(index) {
    if (totalSlides === 0) return;
    if (index >= totalSlides) currentSlideIndex = 0;
    else if (index < 0) currentSlideIndex = totalSlides - 1;
    else currentSlideIndex = index;

    slides.forEach((sl, idx) => {
        sl.classList.toggle('active', idx === currentSlideIndex);
    });
    thumbs.forEach((th, idx) => {
        th.classList.toggle('active', idx === currentSlideIndex);
    });

    const indTexto = document.getElementById('indicadorGaleriaTexto');
    if (indTexto) {
        indTexto.innerText = `Aviso ${currentSlideIndex + 1} de ${totalSlides}`;
    }

    // Centrar miniatura activa en el scroll
    const activeThumb = document.querySelector(`.gallery-thumb-card[data-thumb-index="${currentSlideIndex}"]`);
    if (activeThumb) {
        activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }

    // ACTUALIZACIÓN DEL PIE FIJO (INMÓVIL, SIN ANIMACIONES DE MOVIMIENTO)
    const av = avisosGaleriaData[currentSlideIndex];
    if (av) {
        const ftCod = document.getElementById('ftAvisoCodigo');
        const ftPrio = document.getElementById('ftAvisoPrioridad');
        const ftCat = document.getElementById('ftAvisoCategoria');
        const ftFecha = document.getElementById('ftAvisoFecha');
        const ftTit = document.getElementById('ftAvisoTitulo');
        const ftDesc = document.getElementById('ftAvisoDescripcion');
        const ftBtnAnexo = document.getElementById('ftBtnAnexo');
        const ftIdEliminar = document.getElementById('ftAvisoIdEliminar');

        if (ftCod) ftCod.innerText = av.codigo || 'AV';
        if (ftPrio) {
            ftPrio.innerText = 'Prioridad ' + (av.prioridad || 'Normal');
            let pClass = 'priority-normal';
            if (av.prioridad === 'Alta') pClass = 'priority-alta';
            else if (av.prioridad === 'Media') pClass = 'priority-media';
            ftPrio.className = 'doc-priority-badge ' + pClass;
        }
        if (ftCat) ftCat.innerText = av.categoria || 'General';
        if (ftFecha) ftFecha.innerText = av.fecha_publicacion || '';
        if (ftTit) ftTit.innerText = av.titulo || '';
        if (ftDesc) ftDesc.innerText = av.descripcion || '';

        const anexoUrl = av.archivo_url_completo || av.archivo_url;
        if (ftBtnAnexo) {
            if (anexoUrl && anexoUrl.length > 0) {
                ftBtnAnexo.href = anexoUrl;
                ftBtnAnexo.classList.remove('d-none');
            } else {
                ftBtnAnexo.classList.add('d-none');
            }
        }
        if (ftIdEliminar) {
            ftIdEliminar.value = av.id || '';
        }
    }

    reiniciarProgreso();
}

function abrirLightboxAvisoActual() {
    abrirLightbox(currentSlideIndex);
}

function cambiarSlideGaleria(delta) {
    mostrarSlide(currentSlideIndex + delta);
}

function irASlideGaleria(index) {
    mostrarSlide(index);
}

function reiniciarProgreso() {
    progresoPct = 0;
    const bar = document.getElementById('galleryProgressBar');
    if (bar) bar.style.width = '0%';
}

function iniciarAutoPlay() {
    if (totalSlides <= 1) return;
    detenerAutoPlay();
    reiniciarProgreso();

    progresoTimer = setInterval(() => {
        if (!autoPlayPausado) {
            progresoPct += (100 / (TIEMPO_SLIDE_MS / 100));
            const bar = document.getElementById('galleryProgressBar');
            if (bar) bar.style.width = Math.min(progresoPct, 100) + '%';
            if (progresoPct >= 100) {
                cambiarSlideGaleria(1);
            }
        }
    }, 100);
}

function detenerAutoPlay() {
    if (progresoTimer) clearInterval(progresoTimer);
}

function pausarAutoPlay() {
    autoPlayPausado = true;
}

function reanudarAutoPlay() {
    autoPlayPausado = false;
}

function togglePlayGaleria() {
    autoPlayPausado = !autoPlayPausado;
    const icon = document.getElementById('iconoPausa');
    if (icon) {
        icon.className = autoPlayPausado ? 'bi bi-play-fill' : 'bi bi-pause-fill';
    }
}

if (totalSlides > 0) {
    mostrarSlide(0);
    iniciarAutoPlay();
}

// Toggle entre Vista Galería y Vista Cuadrícula
function toggleVistaAvisos() {
    const gal = document.getElementById('vistaGaleriaAvisos');
    const cuad = document.getElementById('vistaCuadriculaAvisos');
    const txt = document.getElementById('txtToggleVista');
    if (gal && cuad) {
        const esGal = !gal.classList.contains('d-none');
        if (esGal) {
            gal.classList.add('d-none');
            cuad.classList.remove('d-none');
            if (txt) txt.innerText = 'Ver Carrusel de Avisos';
            pausarAutoPlay();
        } else {
            gal.classList.remove('d-none');
            cuad.classList.add('d-none');
            if (txt) txt.innerText = 'Ver Cuadrícula';
            reanudarAutoPlay();
        }
    }
}

// Lightbox Modal para Imagen Completa o Tarjeta de Color con Texto
function abrirLightbox(srcOrIndex, titulo) {
    let av = null;
    if (typeof srcOrIndex === 'number') {
        av = avisosGaleriaData[srcOrIndex];
    } else if (typeof srcOrIndex === 'object' && srcOrIndex !== null) {
        av = srcOrIndex;
    } else if (typeof srcOrIndex === 'string') {
        av = avisosGaleriaData.find(a => (a.imagen_url === srcOrIndex || a.imagen_url_completa === srcOrIndex || a.titulo === titulo));
    }

    const imgEl = document.getElementById('lightboxImg');
    const cardWrap = document.getElementById('lightboxCardWrapper');
    const fbCard = document.getElementById('lightboxFbCard');
    const titEl = document.getElementById('lightboxTitulo');
    const codEl = document.getElementById('lightboxCodigo');
    const btnDescargar = document.getElementById('lightboxBtnDescargar');

    if (av) {
        if (titEl) titEl.innerText = av.titulo || 'Aviso Institucional Grupo Huerta';
        if (codEl) codEl.innerText = av.codigo || 'AV';

        const imgSrc = av.imagen_url_completa || av.imagen_url || '';
        const ext = imgSrc.split('.').pop().toLowerCase().split('?')[0];
        const esFisica = Boolean(av.es_fisica !== undefined ? av.es_fisica : (imgSrc && ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext) && !imgSrc.includes('aviso_card_')));

        if (esFisica) {
            if (imgEl) {
                imgEl.src = imgSrc;
                imgEl.classList.remove('d-none');
            }
            if (cardWrap) cardWrap.classList.add('d-none');
            if (btnDescargar) {
                btnDescargar.href = imgSrc;
                btnDescargar.classList.remove('d-none');
            }
        } else {
            if (imgEl) imgEl.classList.add('d-none');
            if (cardWrap) cardWrap.classList.remove('d-none');
            if (btnDescargar) btnDescargar.classList.add('d-none');

            if (fbCard) {
                const colorCls = av.fondo_cls || 'swatch-negro';
                fbCard.className = 'gallery-fb-slide-card w-100 p-4 p-md-5 ' + colorCls;
            }
            const lbCardTit = document.getElementById('lightboxCardTitulo');
            const lbCardDesc = document.getElementById('lightboxCardDesc');
            const lbCardFecha = document.getElementById('lightboxCardFecha');
            const lbCardPrio = document.getElementById('lightboxCardPrioridad');
            const lbCardMeta = document.getElementById('lightboxCardMeta');

            if (lbCardTit) lbCardTit.innerText = av.titulo || '';
            if (lbCardDesc) lbCardDesc.innerHTML = (av.descripcion || '').replace(/\n/g, '<br>');
            if (lbCardFecha) lbCardFecha.innerText = 'Comunicado Oficial • ' + (av.fecha_publicacion || '');
            if (lbCardPrio) {
                const prio = (av.prioridad || 'Normal').toUpperCase();
                lbCardPrio.innerText = prio;
                let bClass = 'bg-primary text-white';
                if (prio === 'ALTA' || prio === 'URGENTE') bClass = 'bg-danger text-white';
                else if (prio === 'MEDIA') bClass = 'bg-warning text-dark';
                lbCardPrio.className = 'badge fw-bold px-3 py-1.5 rounded-pill ' + bClass;
            }
            if (lbCardMeta) {
                lbCardMeta.innerHTML = `<i class="bi bi-shield-check text-warning me-1"></i> CÓDIGO: ${av.codigo || 'AV'} &bull; ${av.categoria || 'General'}`;
            }
        }
    } else {
        // Fallback si sólo se pasó una URL directa
        if (imgEl) {
            imgEl.src = srcOrIndex;
            imgEl.classList.remove('d-none');
        }
        if (cardWrap) cardWrap.classList.add('d-none');
        if (titEl) titEl.innerText = titulo || 'Aviso Institucional Grupo Huerta';
        if (btnDescargar) {
            btnDescargar.href = srcOrIndex;
            btnDescargar.classList.remove('d-none');
        }
    }

    const modal = new bootstrap.Modal(document.getElementById('modalLightboxAviso'));
    modal.show();
}

function insertarEmoji(targetInputId, emoji) {
    const el = document.getElementById(targetInputId);
    if (!el) return;
    const start = el.selectionStart || el.value.length;
    const end = el.selectionEnd || el.value.length;
    const text = el.value;
    el.value = text.substring(0, start) + emoji + text.substring(end);
    el.focus();
    el.selectionStart = el.selectionEnd = start + emoji.length;
    actualizarPreviewFacebook();
}

// Filtros en vivo
function setupFiltro(inputId, itemsClass) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    inp.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        document.querySelectorAll('.' + itemsClass).forEach(el => {
            const data = (el.getAttribute('data-search') || '').toLowerCase();
            el.style.display = data.includes(term) ? '' : 'none';
        });
    });
}
setupFiltro('filtroFormatos', 'item-tarjeta-formato');
setupFiltro('filtroManuales', 'item-tarjeta-manual');
setupFiltro('filtroAvisos', 'item-tarjeta-aviso');

// Modal visor de documento (Formatos y Manuales)
function verDocumento(doc) {
    document.getElementById('vDocCodigo').innerText = doc.codigo || 'DOC';
    document.getElementById('vDocTitulo').innerText = doc.titulo || '';
    document.getElementById('vDocCategoria').innerText = doc.categoria || 'General';
    document.getElementById('vDocVersion').innerText = doc.version || '1.0';
    document.getElementById('vDocPrioridad').innerText = doc.prioridad || 'Normal';
    document.getElementById('vDocFecha').innerText = doc.fecha_publicacion || '-';
    document.getElementById('vDocDescripcion').innerText = doc.descripcion || 'Sin descripción disponible.';

    const btnCont = document.getElementById('vDocBtnContainer');
    if (doc.archivo_url && doc.archivo_url.length > 0) {
        btnCont.innerHTML = `<a href="${doc.archivo_url}" download class="btn btn-warning text-dark fw-bold px-4 rounded-3"><i class="bi bi-download me-1"></i> Descargar Archivo Oficial (${doc.archivo_tamano || 'PDF'})</a>`;
    } else {
        btnCont.innerHTML = `<button class="btn btn-outline-warning rounded-3 px-4" disabled><i class="bi bi-check-circle me-1"></i> Documento Oficial Resguardado en Archivo Central</button>`;
    }

    const modal = new bootstrap.Modal(document.getElementById('modalVisorDocumento'));
    modal.show();
}

// --- Control de Carrusel en Dashboard Principal ---
let dashCurrentSlide = 0;
const dashSlides = document.querySelectorAll('.dash-carousel-slide');
const totalDashSlides = dashSlides.length;
let dashInterval = null;
let dashPausado = false;

function mostrarDashSlide(idx) {
    if (totalDashSlides === 0) return;
    if (idx >= totalDashSlides) dashCurrentSlide = 0;
    else if (idx < 0) dashCurrentSlide = totalDashSlides - 1;
    else dashCurrentSlide = idx;

    dashSlides.forEach((sl, i) => {
        sl.classList.toggle('active', i === dashCurrentSlide);
    });

    const counter = document.getElementById('dashCarouselCounter');
    if (counter) {
        counter.innerText = `${dashCurrentSlide + 1} / ${totalDashSlides}`;
    }
}

function cambiarDashSlide(delta) {
    mostrarDashSlide(dashCurrentSlide + delta);
}

function pausarDashCarousel() {
    dashPausado = true;
}

function reanudarDashCarousel() {
    dashPausado = false;
}

function iniciarDashCarousel() {
    if (totalDashSlides <= 1) return;
    if (dashInterval) clearInterval(dashInterval);
    dashInterval = setInterval(() => {
        if (!dashPausado) {
            cambiarDashSlide(1);
        }
    }, 4500);
}

if (totalDashSlides > 0) {
    iniciarDashCarousel();
}

// --- Control Tarjeta Facebook y Live Preview ---
const temasFondo = {
    'negro_oro': { bg: 'linear-gradient(135deg, #050b14 0%, #152238 100%)', border: '#d4af37', label: 'Negro Carbón & Oro Imperial' },
    'azul_zafiro': { bg: 'linear-gradient(135deg, #08214d 0%, #104887 50%, #0c356a 100%)', border: '#60a5fa', label: 'Azul Zafiro Corporativo' },
    'rojo_rubi': { bg: 'linear-gradient(135deg, #4a0810 0%, #8c1626 50%, #590d18 100%)', border: '#f87171', label: 'Rojo Rubí Alta Dirección' },
    'esmeralda': { bg: 'linear-gradient(135deg, #062b18 0%, #0e6338 50%, #093f24 100%)', border: '#34d399', label: 'Verde Esmeralda Normativo' },
    'purpura': { bg: 'linear-gradient(135deg, #2a0d3d 0%, #601a70 50%, #3a0f47 100%)', border: '#c084fc', label: 'Púrpura Presidencial' }
};

function seleccionarTemaFondo(tema) {
    const inp = document.getElementById('inpEstiloFondo');
    if (inp) inp.value = tema;
    document.querySelectorAll('.fb-swatch').forEach(sw => {
        sw.classList.toggle('active', sw.getAttribute('data-tema') === tema);
    });
    const card = document.getElementById('fbLivePreviewCard');
    const lbl = document.getElementById('fbNombreTema');
    if (temasFondo[tema]) {
        if (card) {
            card.style.background = temasFondo[tema].bg;
            card.style.borderColor = temasFondo[tema].border;
        }
        if (lbl) lbl.innerText = 'Tema: ' + temasFondo[tema].label;
    }
}

function actualizarPreviewFacebook() {
    const txtArea = document.getElementById('inpDescripcionAviso');
    const txtPreview = document.getElementById('fbPreviewTexto');
    const inpTitulo = document.getElementById('inpTituloDoc');
    const inpCodigo = document.getElementById('inpCodigoDoc');
    const selPrio = document.getElementById('selPrioridadDoc');
    const cardBadge = document.getElementById('fbPreviewBadge');
    const cardCod = document.getElementById('fbPreviewCodigo');

    if (inpCodigo && cardCod) {
        cardCod.innerText = 'CÓDIGO: ' + (inpCodigo.value || 'AV-01');
    }
    if (selPrio && cardBadge) {
        cardBadge.innerText = 'PRIORIDAD ' + (selPrio.value || 'NORMAL').toUpperCase();
    }

    if (!txtPreview) return;
    const desc = txtArea ? txtArea.value.trim() : '';
    const titulo = inpTitulo ? inpTitulo.value.trim() : '';

    if (!desc && !titulo) {
        txtPreview.innerText = 'Escribe aquí tu aviso institucional...';
        txtPreview.style.fontSize = '1.25rem';
        return;
    }

    let contenido = desc;
    if (titulo && desc) {
        contenido = `"${titulo}"\n\n${desc}`;
    } else if (titulo) {
        contenido = `"${titulo}"`;
    }

    txtPreview.innerText = contenido;
    
    // Auto-escalado tipográfico interactivo estilo Facebook
    const len = contenido.length;
    if (len <= 70) {
        txtPreview.style.fontSize = '1.45rem';
    } else if (len <= 150) {
        txtPreview.style.fontSize = '1.15rem';
    } else if (len <= 300) {
        txtPreview.style.fontSize = '0.96rem';
    } else {
        txtPreview.style.fontSize = '0.84rem';
    }
}

function onImagenAvisoSeleccionada(input) {
    const card = document.getElementById('fbLivePreviewCard');
    const help = document.getElementById('boxImagenAvisoHelp');
    if (input.files && input.files[0]) {
        if (help) {
            help.innerHTML = `<span class="text-warning"><i class="bi bi-file-earmark-image"></i> Archivo seleccionado: <strong>${input.files[0].name}</strong>. Se usará este archivo en lugar de la tarjeta de texto generada.</span>`;
        }
        if (card) {
            card.style.opacity = '0.45';
        }
    } else {
        if (help) {
            help.innerHTML = `<i class="bi bi-info-circle text-warning"></i> Si no seleccionas una imagen física, el sistema guardará automáticamente la tarjeta visual que estás previsualizando.`;
        }
        if (card) {
            card.style.opacity = '1';
        }
    }
}

// Dinamismo formulario Modal de Nuevo Documento
function onTipoDocChange() {
    const sel = document.getElementById('selTipoDoc');
    const boxImg = document.getElementById('boxImagenAviso');
    const boxFb = document.getElementById('boxCreadorFacebookAviso');
    const inpCod = document.getElementById('inpCodigoDoc');
    if (!sel) return;
    if (sel.value === 'aviso') {
        if (boxImg) boxImg.style.display = 'block';
        if (boxFb) boxFb.style.display = 'block';
        if (inpCod && !inpCod.value.startsWith('AV-')) inpCod.value = 'AV-0<?php echo $totalAvisos + 1; ?>';
        actualizarPreviewFacebook();
    } else {
        if (boxImg) boxImg.style.display = 'none';
        if (boxFb) boxFb.style.display = 'none';
        if (inpCod && inpCod.value.startsWith('AV-')) {
            inpCod.value = (sel.value === 'formato') ? 'FR-TI-0<?php echo $totalFormatos + 1; ?>' : 'MN-TI-0<?php echo $totalManuales + 1; ?>';
        }
    }
}
</script>
</body>
</html>
