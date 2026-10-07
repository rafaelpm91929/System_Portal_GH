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
            if ($tipo === 'aviso' && empty($rutaImagen)) {
                $svgContenido = generarImagenAvisoSVG($titulo, $descripcion, $prioridad, $categoria, $codigo, date('d/m/Y'), $nombreUsuario);
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
                        (tipo, codigo, titulo, descripcion, categoria, archivo_url, imagen_url, archivo_tipo, archivo_tamano, version, fecha_publicacion, fecha_vigencia, prioridad, obligatorio_lectura, estatus, creado_por)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
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
                        $nombreUsuario
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
            background: #030812;
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.6);
        }

        .gallery-slide-item {
            display: none;
            position: relative;
            width: 100%;
            animation: slideFade 0.45s ease forwards;
        }
        .gallery-slide-item.active {
            display: block;
        }

        @keyframes slideFade {
            from { opacity: 0; transform: scale(0.985); }
            to { opacity: 1; transform: scale(1); }
        }

        .gallery-slide-img {
            width: 100%;
            height: auto;
            max-height: 520px;
            object-fit: contain;
            background: #030812;
            display: block;
            margin: 0 auto;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        .gallery-slide-img:hover {
            transform: scale(1.01);
        }

        .gallery-slide-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(4, 13, 26, 0.85) 40%, rgba(4, 13, 26, 0.98) 100%);
            padding: 24px 28px 18px 28px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
        }

        .gallery-controls-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(4, 13, 26, 0.75);
            backdrop-filter: blur(8px);
            border: 1px solid var(--gold-border);
            color: var(--gold-accent-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
        }
        .gallery-controls-btn:hover {
            background: rgba(212, 175, 55, 0.25);
            border-color: var(--gold-border-bright);
            color: #ffffff;
            box-shadow: 0 0 15px var(--gold-glow);
            transform: translateY(-50%) scale(1.1);
        }
        .gallery-btn-prev { left: 16px; }
        .gallery-btn-next { right: 16px; }

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
                    <button class="btn-gold-primary" onclick="cambiarSeccion('formatos')">
                        <i class="bi bi-file-earmark-text-fill"></i> Explorar Formatos
                    </button>
                    <button class="btn-gold-outline" onclick="cambiarSeccion('manuales')">
                        <i class="bi bi-book-half"></i> Consultar Manuales
                    </button>
                    <button class="btn-gold-outline" onclick="cambiarSeccion('avisos')">
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
                            <button class="btn btn-sm btn-link text-warning text-decoration-none p-0" onclick="cambiarSeccion('formatos')">
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
                                    <button class="btn btn-sm btn-outline-warning rounded-pill px-3" onclick="verDocumento(<?php echo htmlspecialchars(json_encode($mf)); ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-5">
                    <div class="p-4 rounded-4" style="background: var(--bg-card); border: 1px solid var(--gold-border); height: 100%;">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h3 class="h5 fw-bold text-white m-0 d-flex align-items-center gap-2">
                                <i class="bi bi-megaphone-fill text-warning"></i> Avisos
                            </h3>
                            <button class="btn btn-sm btn-link text-warning text-decoration-none p-0" onclick="cambiarSeccion('avisos')">
                                Ver avisos <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>

                        <?php if (!empty($avisos)): 
                            $primerAviso = $avisos[0];
                        ?>
                            <div class="position-relative rounded-3 overflow-hidden border border-secondary border-opacity-25" style="cursor: pointer;" onclick="cambiarSeccion('avisos')">
                                <img src="<?php echo htmlspecialchars($primerAviso['imagen_url'] ?: 'uploads/compliance/aviso_AV-01.svg'); ?>" alt="Aviso Destacado" class="w-100" style="max-height: 190px; object-fit: cover;">
                                <div class="position-absolute bottom-0 start-0 end-0 p-2 text-white" style="background: rgba(4, 13, 26, 0.88); backdrop-filter: blur(4px);">
                                    <div class="small fw-bold text-truncate"><?php echo htmlspecialchars($primerAviso['titulo']); ?></div>
                                    <div class="text-secondary" style="font-size: 0.7rem;"><?php echo htmlspecialchars($primerAviso['codigo']); ?> &bull; Prioridad <?php echo htmlspecialchars($primerAviso['prioridad']); ?></div>
                                </div>
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
                                        <button class="btn btn-sm btn-outline-warning w-100 rounded-3" onclick="verDocumento(<?php echo htmlspecialchars(json_encode($fmt)); ?>)">
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
                                        <button class="btn btn-sm btn-outline-warning w-100 rounded-3" onclick="verDocumento(<?php echo htmlspecialchars(json_encode($man)); ?>)">
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
                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btnToggleVistaAvisos" onclick="toggleVistaAvisos()">
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
                            <button class="btn btn-sm btn-outline-secondary rounded-circle" id="btnPausaGaleria" onclick="togglePlayGaleria()" title="Pausar / Reanudar Rotación" style="width: 34px; height: 34px; padding: 0;">
                                <i class="bi bi-pause-fill" id="iconoPausa"></i>
                            </button>
                        </div>
                    </div>

                    <div class="gallery-main-stage" id="galleryMainStage" onmouseenter="pausarAutoPlay()" onmouseleave="reanudarAutoPlay()">
                        <div class="gallery-autoplay-bar" id="galleryProgressBar"></div>

                        <!-- Botones de Navegación -->
                        <button class="gallery-controls-btn gallery-btn-prev" onclick="cambiarSlideGaleria(-1)" aria-label="Aviso Anterior">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button class="gallery-controls-btn gallery-btn-next" onclick="cambiarSlideGaleria(1)" aria-label="Aviso Siguiente">
                            <i class="bi bi-chevron-right"></i>
                        </button>

                        <!-- Diapositivas de Avisos -->
                        <?php foreach ($avisos as $index => $av): 
                            $imgSrc = !empty($av['imagen_url']) ? $av['imagen_url'] : 'uploads/compliance/aviso_AV-01.svg';
                            $pClass = 'priority-normal';
                            if ($av['prioridad'] === 'Alta') $pClass = 'priority-alta';
                            elseif ($av['prioridad'] === 'Media') $pClass = 'priority-media';
                        ?>
                            <div class="gallery-slide-item <?php echo $index === 0 ? 'active' : ''; ?>" data-slide-index="<?php echo $index; ?>">
                                <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($av['titulo']); ?>" class="gallery-slide-img" onclick="abrirLightbox('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars(addslashes($av['titulo'])); ?>')">
                                
                                <div class="gallery-slide-overlay">
                                    <div style="max-width: 750px;">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="doc-code-badge"><?php echo htmlspecialchars($av['codigo']); ?></span>
                                            <span class="doc-priority-badge <?php echo $pClass; ?>">Prioridad <?php echo htmlspecialchars($av['prioridad']); ?></span>
                                            <span class="category-tag"><?php echo htmlspecialchars($av['categoria']); ?></span>
                                            <span class="text-secondary small ms-2"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($av['fecha_publicacion']); ?></span>
                                        </div>
                                        <h3 class="h4 fw-bold text-white mb-1"><?php echo htmlspecialchars($av['titulo']); ?></h3>
                                        <p class="text-light text-opacity-75 mb-0 text-truncate" style="font-size: 0.88rem; max-width: 680px;">
                                            <?php echo htmlspecialchars($av['descripcion']); ?>
                                        </p>
                                    </div>

                                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <button class="btn btn-sm btn-gold-primary rounded-pill px-3" onclick="abrirLightbox('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars(addslashes($av['titulo'])); ?>')">
                                            <i class="bi bi-arrows-fullscreen me-1"></i> Pantalla Completa
                                        </button>
                                        <?php if (!empty($av['archivo_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($av['archivo_url']); ?>" download class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3">
                                                <i class="bi bi-download me-1"></i> Anexo
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('¿Confirmas que deseas eliminar este aviso?');" class="m-0">
                                            <input type="hidden" name="accion" value="eliminar_documento">
                                            <input type="hidden" name="documento_id" value="<?php echo $av['id']; ?>">
                                            <input type="hidden" name="seccion" value="avisos">
                                            <button type="submit" class="btn btn-sm btn-danger text-white fw-bold rounded-pill px-3 d-inline-flex align-items-center gap-1" title="Eliminar Aviso">
                                                <i class="bi bi-trash3-fill"></i> Borrar Aviso
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Tira de Miniaturas Inferior -->
                    <div class="gallery-thumbs-strip" id="galleryThumbsStrip">
                        <?php foreach ($avisos as $idx => $av): 
                            $thumbImg = !empty($av['imagen_url']) ? $av['imagen_url'] : 'uploads/compliance/aviso_AV-01.svg';
                        ?>
                            <div class="gallery-thumb-card <?php echo $idx === 0 ? 'active' : ''; ?>" data-thumb-index="<?php echo $idx; ?>" onclick="irASlideGaleria(<?php echo $idx; ?>)" title="<?php echo htmlspecialchars($av['titulo']); ?>">
                                <img src="<?php echo htmlspecialchars($thumbImg); ?>" alt="Miniatura <?php echo htmlspecialchars($av['codigo']); ?>">
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
                        $imgSrc = !empty($av['imagen_url']) ? $av['imagen_url'] : 'uploads/compliance/aviso_AV-01.svg';
                        $pClass = 'priority-normal';
                        if ($av['prioridad'] === 'Alta') $pClass = 'priority-alta';
                        elseif ($av['prioridad'] === 'Media') $pClass = 'priority-media';
                    ?>
                        <div class="col-12 col-md-6 item-tarjeta-aviso" data-search="<?php echo htmlspecialchars(strtolower($av['codigo'] . ' ' . $av['titulo'] . ' ' . $av['descripcion'] . ' ' . $av['prioridad'])); ?>">
                            <div class="doc-item-card h-100 d-flex flex-column justify-content-between p-3">
                                <div>
                                    <div class="rounded-3 overflow-hidden mb-3 border border-secondary border-opacity-25" style="background: #030812; cursor: pointer;" onclick="abrirLightbox('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars(addslashes($av['titulo'])); ?>')">
                                        <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($av['titulo']); ?>" class="w-100" style="max-height: 220px; object-fit: contain;">
                                    </div>
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
                                        <button class="btn btn-sm btn-outline-warning rounded-pill px-3" onclick="abrirLightbox('<?php echo htmlspecialchars($imgSrc); ?>', '<?php echo htmlspecialchars(addslashes($av['titulo'])); ?>')">
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
                            <input type="text" name="codigo" id="inpCodigoDoc" class="form-control form-control-gold" placeholder="Ej. AV-05 o FR-TI-03" required value="AV-0<?php echo $totalAvisos + 1; ?>">
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
                            <label class="form-label-gold">Título Oficial *</label>
                            <input type="text" name="titulo" class="form-control form-control-gold" placeholder="Título formal del comunicado o documento" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Versión</label>
                            <input type="text" name="version" class="form-control form-control-gold" value="1.0" placeholder="1.0">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label-gold">Prioridad</label>
                            <select name="prioridad" class="form-select form-select-gold">
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

                        <div class="col-12">
                            <label class="form-label-gold">Texto / Contenido del Aviso *</label>
                            <textarea name="descripcion" class="form-control form-control-gold" rows="4" placeholder="Escribe aquí el texto del aviso (si no subes imagen, este texto se convertirá automáticamente en una imagen ejecutiva oficial con sellos corporativos)..."></textarea>
                        </div>

                        <!-- Campo Imagen para Avisos -->
                        <div class="col-12" id="boxImagenAviso">
                            <label class="form-label-gold">
                                <i class="bi bi-image text-warning"></i> Imagen del Aviso (Opcional)
                            </label>
                            <input type="file" name="imagen_aviso" class="form-control form-control-gold" accept=".jpg,.jpeg,.png,.webp,.svg,.gif">
                            <div class="form-text text-secondary" style="font-size: 0.74rem;">
                                <i class="bi bi-magic text-warning"></i> <strong>Conversión Automática:</strong> Si no adjuntas una imagen física, el sistema convertirá automáticamente el texto en una imagen gráfica oficial de alta resolución.
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
<!-- MODAL: LIGHTBOX / ZOOM DE IMAGEN DE AVISO                                 -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalLightboxAviso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content modal-content-gold">
            <div class="modal-header modal-header-gold">
                <h5 class="modal-title fw-bold text-white" id="lightboxTitulo"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2 p-md-3 text-center bg-black position-relative">
                <img id="lightboxImg" src="" alt="Aviso Ampliado" class="img-fluid rounded-3" style="max-height: 80vh; width: auto; object-fit: contain;">
                <div class="small text-secondary mt-2">
                    <i class="bi bi-shield-lock-fill text-warning"></i> Portal Oficial Grupo Huerta &bull; Usuario: <?php echo htmlspecialchars($nombreUsuario); ?> &bull; IP: <?php echo htmlspecialchars($ipUsuario); ?>
                </div>
            </div>
            <div class="modal-footer modal-footer-gold justify-content-between">
                <a id="lightboxBtnDescargar" href="#" download class="btn btn-warning text-dark fw-bold rounded-pill px-4">
                    <i class="bi bi-download me-1"></i> Descargar Imagen
                </a>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
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
                        Identificación Forense: <?php echo htmlspecialchars($nombreUsuario); ?> &bull; IP: <?php echo htmlspecialchars($ipUsuario); ?>
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

    reiniciarProgreso();
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

// Lightbox Modal para Imagen Completa
function abrirLightbox(src, titulo) {
    const img = document.getElementById('lightboxImg');
    const tit = document.getElementById('lightboxTitulo');
    const btn = document.getElementById('lightboxBtnDescargar');
    if (img) img.src = src;
    if (tit) tit.innerText = titulo || 'Aviso Institucional Grupo Huerta';
    if (btn) btn.href = src;
    const modal = new bootstrap.Modal(document.getElementById('modalLightboxAviso'));
    modal.show();
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

// Dinamismo formulario Modal de Nuevo Documento
function onTipoDocChange() {
    const sel = document.getElementById('selTipoDoc');
    const boxImg = document.getElementById('boxImagenAviso');
    const inpCod = document.getElementById('inpCodigoDoc');
    if (!sel) return;
    if (sel.value === 'aviso') {
        if (boxImg) boxImg.style.display = 'block';
        if (inpCod && !inpCod.value.startsWith('AV-')) inpCod.value = 'AV-0<?php echo $totalAvisos + 1; ?>';
    } else {
        if (boxImg) boxImg.style.display = 'none';
        if (inpCod && inpCod.value.startsWith('AV-')) {
            inpCod.value = (sel.value === 'formato') ? 'FR-TI-0<?php echo $totalFormatos + 1; ?>' : 'MN-TI-0<?php echo $totalManuales + 1; ?>';
        }
    }
}
</script>
</body>
</html>
