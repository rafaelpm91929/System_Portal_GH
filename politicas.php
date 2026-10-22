<?php
date_default_timezone_set('America/Mexico_City');
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

// Asegurar existencia de tablas
if ($pdo) {
    asegurarTablaPoliticas($pdo);
    asegurarTablasPermisos($pdo);
}

requerirPermiso('politicas', 'puede_ver');

// Datos de sesión del usuario
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Colaborador');
$loginUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? 'usuario');
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

// 1.1 Verificación y estructura para Aviso de Privacidad y Términos en cada acceso
if ($pdo && function_exists('asegurarTablaPoliticas')) {
    try { asegurarTablaPoliticas($pdo); } catch (Throwable $eTerm) {}
}


// Obtener IP real del usuario para la marca de agua forense
$ipUsuario = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (strpos($ipUsuario, ',') !== false) {
    $ipUsuario = trim(explode(',', $ipUsuario)[0]);
}

// Cargar información de la agencia registrada
$agenciaInfo = null;
if ($pdo) {
    try {
        $stmtAg = $pdo->query("SELECT nombre, razon_social, logo_url FROM agencias ORDER BY id ASC LIMIT 1");
        $agenciaInfo = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {}
}
$agenciaNombre = !empty($agenciaInfo['nombre']) ? trim($agenciaInfo['nombre']) : ($_SESSION['agencia'] ?? 'Grupo Huerta');
$logoAgencia = (!empty($agenciaInfo['logo_url']) && file_exists(__DIR__ . '/' . $agenciaInfo['logo_url'])) ? $agenciaInfo['logo_url'] : '';

$mensaje = '';
$error = '';

// =============================================================================
// 2. ACCIONES ADMINISTRATIVAS: SUBIR POLÍTICA / ALTA DE ÁREAS Y ELIMINAR
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // ACCIÓN: SUBIR NUEVA POLÍTICA CORPORATIVA (ADMIN)
    if ($accion === 'subir_politica' && $esAdmin) {
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $area = trim($_POST['area'] ?? ($_POST['categoria'] ?? 'General'));
        $subarea = trim($_POST['subarea'] ?? 'Políticas Generales');
        $version = trim($_POST['version'] ?? '1.0');
        $fechaVigencia = !empty($_POST['fecha_vigencia']) ? $_POST['fecha_vigencia'] : null;
        $obligatorio = isset($_POST['obligatorio_lectura']) ? 1 : 0;

        if (empty($titulo)) {
            $error = "El título de la política o normativa es obligatorio.";
        } elseif (empty($area)) {
            $error = "Debes indicar el Área Institucional para clasificar la política.";
        } elseif (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
            $error = "Debes seleccionar un archivo PDF válido.";
        } else {
            $ext = strtolower(pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $error = "Únicamente se permiten archivos en formato PDF oficial.";
            } else {
                $dirDestino = __DIR__ . '/uploads/politicas/';
                if (!file_exists($dirDestino)) {
                    @mkdir($dirDestino, 0755, true);
                }

                $nombreLimpio = 'politica_' . time() . '_' . rand(100, 999) . '.pdf';
                $rutaFisica = $dirDestino . $nombreLimpio;
                $rutaRelativa = 'uploads/politicas/' . $nombreLimpio;

                if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $rutaFisica)) {
                    $insertExitoso = false;
                    try {
                        $stmtIns = $pdo->prepare("
                            INSERT INTO politicas_corporativas 
                            (titulo, descripcion, categoria, area, subarea, archivo_pdf, version, fecha_vigencia, obligatorio_lectura, estatus, creado_por) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                        ");
                        $stmtIns->execute([
                            $titulo,
                            $descripcion,
                            $area, // categoria como fallback
                            $area,
                            $subarea,
                            $rutaRelativa,
                            $version,
                            $fechaVigencia,
                            $obligatorio,
                            $loginUsuario
                        ]);
                        $insertExitoso = true;
                        $mensaje = "La política se ha registrado y publicado con éxito en el Área: " . htmlspecialchars($area) . ".";
                    } catch (Throwable $e) {
                        // Respaldo de auto-reparación: si falta la columna en SQLite/MySQL, migrar dinámicamente y reintentar
                        try {
                            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: '');
                            if ($driver === 'sqlite') {
                                @$pdo->exec("ALTER TABLE politicas_corporativas ADD COLUMN area TEXT");
                                @$pdo->exec("ALTER TABLE politicas_corporativas ADD COLUMN subarea TEXT");
                                @$pdo->exec("ALTER TABLE politicas_corporativas ADD COLUMN categoria TEXT DEFAULT 'General'");
                            } else {
                                @$pdo->exec("ALTER TABLE `politicas_corporativas` ADD COLUMN `area` VARCHAR(100) NULL");
                                @$pdo->exec("ALTER TABLE `politicas_corporativas` ADD COLUMN `subarea` VARCHAR(100) NULL");
                            }
                            $stmtRetry = $pdo->prepare("
                                INSERT INTO politicas_corporativas 
                                (titulo, descripcion, categoria, area, subarea, archivo_pdf, version, fecha_vigencia, obligatorio_lectura, estatus, creado_por) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                            ");
                            $stmtRetry->execute([
                                $titulo,
                                $descripcion,
                                $area,
                                $area,
                                $subarea,
                                $rutaRelativa,
                                $version,
                                $fechaVigencia,
                                $obligatorio,
                                $loginUsuario
                            ]);
                            $insertExitoso = true;
                            $mensaje = "La política se ha registrado y publicado con éxito en el Área: " . htmlspecialchars($area) . ".";
                        } catch (Throwable $eRetry) {
                            $error = "Error al guardar en la base de datos: " . $eRetry->getMessage();
                        }
                    }

                    if ($insertExitoso) {
                        $nuevoPolId = (int)$pdo->lastInsertId();
                        $tipoNotif = (floatval($version) > 1.0) ? 'actualizacion_politica' : 'nueva_politica';
                        $tituloNotif = ($tipoNotif === 'actualizacion_politica') ? 'Actualización de Política' : 'Nueva Política Publicada';
                        crearNotificacion(
                            'politicas',
                            $tipoNotif,
                            $tituloNotif,
                            'Se subió la política oficial: ' . $titulo . ' (' . $area . ' - v' . $version . ')',
                            'politicas.php',
                            'bi-shield-shaded',
                            '#9333ea',
                            $nuevoPolId,
                            $pdo
                        );
                    }
                } else {
                    $error = "No se pudo guardar el archivo en el servidor. Verifica permisos de la carpeta uploads/politicas.";
                }
            }
        }
    }

    // ACCIÓN: ELIMINAR POLÍTICA (ADMIN)
    if ($accion === 'eliminar_politica' && $esAdmin) {
        $politicaId = intval($_POST['politica_id'] ?? 0);
        if ($politicaId > 0) {
            try {
                $stmtGet = $pdo->prepare("SELECT archivo_pdf FROM politicas_corporativas WHERE id = ?");
                $stmtGet->execute([$politicaId]);
                $polData = $stmtGet->fetch(PDO::FETCH_ASSOC);

                if ($polData && !empty($polData['archivo_pdf'])) {
                    $archivoFisico = __DIR__ . '/' . $polData['archivo_pdf'];
                    if (file_exists($archivoFisico)) {
                        @unlink($archivoFisico);
                    }
                }

                $stmtDel = $pdo->prepare("DELETE FROM politicas_corporativas WHERE id = ?");
                $stmtDel->execute([$politicaId]);
                $mensaje = "La política ha sido eliminada del repositorio corporativo.";
            } catch (Throwable $e) {
                $error = "Error al eliminar política: " . $e->getMessage();
            }
        }
    }
}

// =============================================================================
// 3. CONSULTA DE POLÍTICAS VIGENTES
// =============================================================================
$politicasLista = [];

// En Portal Central GH consultamos directamente la base de datos local
if ($pdo) {
    try {
        $stmtList = $pdo->query("
            SELECT * FROM politicas_corporativas 
            WHERE estatus = 1 
            ORDER BY COALESCE(area, categoria) ASC, COALESCE(subarea, '') ASC, titulo ASC
        ");
        $politicasLista = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}

// Fallback adicional vía API en caso de ser necesario
if (empty($politicasLista)) {
    $urlCentral = 'https://portal.grupohuerta.mx/api_politicas.php?action=listar';
    $ctx = stream_context_create([
        'http' => ['timeout' => 5],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $resp = @file_get_contents($urlCentral, false, $ctx);
    if ($resp) {
        $dataJson = json_decode($resp, true);
        if (!empty($dataJson['exito']) && !empty($dataJson['politicas'])) {
            $politicasLista = $dataJson['politicas'];
        }
    }
}

// =============================================================================
// 4. ESTRUCTURACIÓN JERÁRQUICA: ÁREAS -> SUBÁREAS -> POLÍTICAS
// =============================================================================
$areasEstructuradas = [];
$todasSubareasExistentes = [];

foreach ($politicasLista as $pol) {
    // Resolver Área
    $area = !empty($pol['area']) ? trim($pol['area']) : (!empty($pol['categoria']) ? trim($pol['categoria']) : 'General');
    
    // Resolver Subárea
    $subarea = !empty($pol['subarea']) ? trim($pol['subarea']) : (!empty($pol['sub_area']) ? trim($pol['sub_area']) : '');

    // Formato compuesto "Área / Subárea" o "Área - Subárea"
    if (empty($subarea) && strpos($area, '/') !== false) {
        $partes = explode('/', $area, 2);
        $area = trim($partes[0]);
        $subarea = trim($partes[1]);
    } elseif (empty($subarea) && strpos($area, ' - ') !== false) {
        $partes = explode(' - ', $area, 2);
        $area = trim($partes[0]);
        $subarea = trim($partes[1]);
    }

    if (empty($subarea)) {
        $subarea = 'Políticas Generales';
    }

    if (!isset($areasEstructuradas[$area])) {
        $areasEstructuradas[$area] = [
            'nombre' => $area,
            'subareas' => [],
            'total_politicas' => 0,
            'total_obligatorias' => 0
        ];
    }

    if (!isset($areasEstructuradas[$area]['subareas'][$subarea])) {
        $areasEstructuradas[$area]['subareas'][$subarea] = [];
    }

    $pol['area_resuelto'] = $area;
    $pol['subarea_resuelto'] = $subarea;
    $areasEstructuradas[$area]['subareas'][$subarea][] = $pol;
    $areasEstructuradas[$area]['total_politicas']++;
    if (!empty($pol['obligatorio_lectura'])) {
        $areasEstructuradas[$area]['total_obligatorias']++;
    }

    $todasSubareasExistentes[$subarea] = true;
}
ksort($areasEstructuradas);

// Función auxiliar para iconos representativos por área
function obtenerIconoArea($nombreArea) {
    $n = strtolower($nombreArea);
    if (strpos($n, 'sistema') !== false || strpos($n, 'desarrollo') !== false) return 'bi-cpu-fill';
    if (strpos($n, 'equipo') !== false || strpos($n, 'ti') !== false || strpos($n, 'red') !== false) return 'bi-hdd-network-fill';
    if (strpos($n, 'seguridad') !== false || strpos($n, 'ciber') !== false) return 'bi-shield-check';
    if (strpos($n, 'direccion') !== false || strpos($n, 'consejo') !== false || strpos($n, 'gerencia') !== false) return 'bi-building-fill-check';
    if (strpos($n, 'humano') !== false || strpos($n, 'personal') !== false || strpos($n, 'rh') !== false) return 'bi-people-fill';
    if (strpos($n, 'finanza') !== false || strpos($n, 'contab') !== false || strpos($n, 'administra') !== false) return 'bi-cash-coin';
    if (strpos($n, 'operacion') !== false || strpos($n, 'taller') !== false || strpos($n, 'servicio') !== false) return 'bi-gear-wide-connected';
    if (strpos($n, 'etica') !== false || strpos($n, 'conducta') !== false || strpos($n, 'legal') !== false) return 'bi-award-fill';
    return 'bi-folder2-open';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Políticas y Normativas Corporativas - <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <?php include_once 'pwa_head.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- PDF.js para renderizado en Canvas (Protección sin descarga directa) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
    </script>

    <style>
        :root {
            --bg-dark: #050d1a;
            --surface-card: #09172c;
            --surface-card-hover: #0d2242;
            --gold-accent: #d4af37;
            --gold-accent-light: #f5df9e;
            --gold-border: rgba(212, 175, 55, 0.35);
            --gold-border-bright: rgba(212, 175, 55, 0.7);
            --gold-glow: rgba(212, 175, 55, 0.18);
        }

        body {
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 15% 15%, rgba(212, 175, 55, 0.08) 0%, transparent 45%),
                radial-gradient(circle at 85% 12%, rgba(30, 58, 138, 0.25) 0%, transparent 40%),
                radial-gradient(circle at 50% 90%, rgba(9, 23, 44, 0.7) 0%, transparent 60%);
            background-attachment: fixed;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            user-select: none;
            -webkit-user-select: none;
        }

        .executive-navbar {
            background: rgba(5, 13, 26, 0.95);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--gold-border);
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 1020;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.45);
        }

        .agency-logo-thumb {
            max-height: 32px;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        /* PESTAÑAS DENTRO DEL MÓDULO CON CONTORNOS DORADOS */
        .module-tab-nav {
            border-bottom: 1px solid var(--gold-border);
            gap: 10px;
        }

        .module-tab-nav .nav-link {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 11px 22px;
            border-radius: 12px 12px 0 0;
            border: 1px solid transparent;
            background: transparent;
            transition: all 0.22s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .module-tab-nav .nav-link:hover {
            color: var(--gold-accent-light);
            background: rgba(212, 175, 55, 0.05);
            border-color: var(--gold-border) var(--gold-border) transparent;
        }

        .module-tab-nav .nav-link.active {
            color: var(--gold-accent-light);
            background: #09172c;
            border-color: var(--gold-border) var(--gold-border) #09172c;
            border-bottom-color: #09172c;
            font-weight: 700;
            box-shadow: 0 -4px 15px var(--gold-glow);
        }

        /* TARJETAS DE ÁREAS CON CONTORNOS DORADOS */
        .area-folder-card {
            background: linear-gradient(145deg, #09172c 0%, #061224 100%);
            border: 1px solid var(--gold-border);
            border-radius: 18px;
            padding: 26px;
            transition: all 0.26s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4), 0 0 15px var(--gold-glow);
        }

        .area-folder-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--gold-accent), transparent);
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }

        .area-folder-card:hover {
            transform: translateY(-5px);
            border-color: var(--gold-border-bright);
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.55), 0 0 25px rgba(212, 175, 55, 0.35);
            background: linear-gradient(145deg, #0d2242 0%, #081932 100%);
        }

        .area-folder-card:hover::before {
            opacity: 1;
        }

        .area-icon-wrap {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid var(--gold-border);
            color: var(--gold-accent-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.65rem;
            margin-bottom: 16px;
            box-shadow: 0 0 15px var(--gold-glow);
        }

        .area-title-text {
            font-size: 1.25rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 8px;
            line-height: 1.3;
            letter-spacing: -0.2px;
        }

        /* SECCIONES DE SUBÁREA CON CONTORNO DORADO */
        .subarea-group-card {
            background: rgba(9, 23, 44, 0.75);
            border: 1px solid var(--gold-border);
            border-radius: 18px;
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35), 0 0 15px var(--gold-glow);
        }

        .subarea-header {
            background: rgba(14, 34, 64, 0.65);
            border-bottom: 1px solid var(--gold-border);
            padding: 15px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .subarea-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subarea-badge-count {
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid var(--gold-border);
            color: var(--gold-accent-light);
            font-family: monospace;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }

        /* FILAS DE POLÍTICAS */
        .policy-item-row {
            padding: 18px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            transition: background 0.15s ease;
        }

        .policy-item-row:last-child {
            border-bottom: none;
        }

        .policy-item-row:hover {
            background: rgba(212, 175, 55, 0.035);
        }

        .folio-pill {
            background: rgba(212, 175, 55, 0.12);
            color: var(--gold-accent-light);
            border: 1px solid var(--gold-border);
            font-family: monospace;
            font-size: 0.74rem;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 8px;
            letter-spacing: 0.5px;
        }

        .badge-mandatory-pill {
            background: rgba(239, 68, 68, 0.14);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
            font-size: 0.68rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
        }

        .btn-view-doc {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.15) 0%, rgba(14, 38, 70, 0.9) 100%);
            border: 1px solid var(--gold-border);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.84rem;
            padding: 8px 18px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.22s ease;
            white-space: nowrap;
        }

        .btn-view-doc:hover {
            background: linear-gradient(135deg, var(--gold-accent) 0%, #b8972e 100%);
            border-color: var(--gold-accent-light);
            color: #06101e;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4);
        }

        /* BUSCADOR CON CONTORNO DORADO */
        .gold-search-input {
            background: rgba(7, 18, 36, 0.85) !important;
            border: 1px solid var(--gold-border) !important;
            color: #ffffff !important;
            font-size: 0.88rem;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .gold-search-input:focus {
            border-color: var(--gold-accent) !important;
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.2) !important;
        }

        /* BOTONES DE ADMINISTRACIÓN */
        .btn-gold-action {
            background: linear-gradient(135deg, var(--gold-accent) 0%, #b8972e 100%);
            color: #050d1a !important;
            border: 1px solid var(--gold-accent-light);
            font-weight: 700;
            font-size: 0.84rem;
            padding: 8px 18px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }

        .btn-gold-action:hover {
            filter: brightness(1.15);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.45);
        }

        /* ESTILOS DEL VISOR BLINDADO FULLSCREEN */
        #visorModalOverlay {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: #020610;
            z-index: 999990;
            display: none;
            flex-direction: column;
        }

        .visor-header {
            background: #050d1a;
            border-bottom: 1px solid var(--gold-border);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999995;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
        }

        #visorCanvasContainer {
            flex-grow: 1;
            height: calc(100vh - 65px);
            overflow-y: auto;
            overflow-x: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px 20px;
            gap: 30px;
            position: relative;
            background: #030814;
        }

        .pdf-page-wrapper {
            position: relative;
            flex-shrink: 0 !important;
            display: block;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.9);
            border-radius: 6px;
            overflow: hidden;
            background: #ffffff;
            margin: 0 auto;
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: filter 0.18s ease, opacity 0.18s ease;
        }

        .pdf-page-canvas {
            display: block;
            width: 100%;
            height: 100%;
        }

        .forensic-watermark-overlay {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            z-index: 5;
            display: none;
        }

        .banknote-security-pattern {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            z-index: 3;
            opacity: 0.035;
            background-image: 
                radial-gradient(#1e293b 0.8px, transparent 0.8px),
                repeating-linear-gradient(45deg, rgba(30,41,59,0.3) 0, rgba(30,41,59,0.3) 0.5px, transparent 0, transparent 4px);
            background-size: 8px 8px, 6px 6px;
        }

        .watermark-stamp {
            display: none;
        }

        .watermark-gh-logo {
            display: none;
        }

        .central-gh-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 45%;
            max-width: 320px;
            opacity: 0.12;
            pointer-events: none;
            z-index: 3;
            text-align: center;
            user-select: none;
        }

        .central-gh-watermark img {
            width: 100%;
            height: auto;
            display: block;
        }

        /* OVERLAY DE BLOQUEO POR CURSOR */
        #pdfLockOverlay {
            position: absolute;
            top: 56px;
            left: 0;
            width: 100%;
            height: calc(100% - 56px);
            background: rgba(3, 8, 18, 0.95);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            z-index: 999998;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            opacity: 0;
            visibility: hidden;
        }

        #pdfLockOverlay.activo {
            opacity: 1;
            visibility: visible;
        }

        .pdf-lock-box-gold {
            background: rgba(9, 23, 44, 0.9);
            border: 2px solid var(--gold-border);
            border-radius: 20px;
            padding: 36px 32px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8), 0 0 35px var(--gold-glow);
            max-width: 540px;
            text-align: center;
        }

        .visor-bloqueado .pdf-page-wrapper {
            filter: blur(28px) grayscale(90%) !important;
            opacity: 0.12 !important;
        }

        .pulsing-dot {
            width: 8px;
            height: 8px;
            background-color: var(--gold-accent);
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px var(--gold-accent);
        }

        @media print {
            * {
                display: none !important;
                visibility: hidden !important;
            }
            html, body {
                background: #000000 !important;
                display: block !important;
            }
        }
    </style>
</head>
<body oncontextmenu="return false;">

<?php include_once 'pwa_body.php'; ?>

<!-- TOP NAVBAR -->
<nav class="executive-navbar d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="d-flex align-items-center gap-2 gap-md-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3 py-1 px-2 px-md-3" title="Regresar al Menú Principal" style="border-color: var(--gold-border);">
            <i class="bi bi-arrow-left"></i> <span class="d-none d-sm-inline ms-1">Menú Principal</span>
        </a>

        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($logoAgencia)): ?>
                <img src="<?php echo htmlspecialchars($logoAgencia); ?>" alt="Logo Agencia" class="agency-logo-thumb">
            <?php endif; ?>
            <span class="fw-bold fs-6 text-white text-uppercase">
                PORTAL <span style="color: var(--gold-accent-light);"><?php echo htmlspecialchars($agenciaNombre); ?></span>
            </span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($esAdmin): ?>
            <!-- BOTÓN ALTA DE POLÍTICA Y ÁREAS (SOLO ADMINISTRADOR CENTRAL GH) -->
            <button type="button" class="btn-gold-action" data-bs-toggle="modal" data-bs-target="#modalSubirPolitica">
                <i class="bi bi-cloud-arrow-up-fill"></i> <span>Publicar Normativa / Área</span>
            </button>

            <!-- BOTÓN BITÁCORA DE AUDITORÍA -->
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-3 py-1.5 px-3 text-light d-flex align-items-center gap-1.5" style="border-color: var(--gold-border);" onclick="abrirModalAuditoriaLecturas()">
                <i class="bi bi-journal-check" style="color: var(--gold-accent);"></i> <span class="d-none d-md-inline">Bitácora</span>
            </button>
        <?php endif; ?>

        <span class="badge bg-dark border text-light px-3 py-1.5 rounded-pill small d-none d-lg-inline-flex align-items-center gap-2 font-monospace" style="border-color: var(--gold-border) !important;">
            <span class="pulsing-dot"></span> Central GH Maestro
        </span>
        <a href="politicas.php" class="btn btn-outline-secondary btn-sm rounded-3 py-1.5 px-2.5 text-light" style="border-color: var(--gold-border);" title="Actualizar políticas">
            <i class="bi bi-arrow-clockwise"></i>
        </a>
    </div>
</nav>

<div class="container-fluid py-4 px-3 px-md-4" style="max-width: 1400px;">

    <!-- MENSAJES -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4 shadow" role="alert" style="background: rgba(6, 78, 59, 0.85); color: #a7f3d0; border: 1px solid rgba(16, 185, 129, 0.4) !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4 shadow" role="alert" style="background: rgba(127, 29, 29, 0.85); color: #fecaca; border: 1px solid rgba(239, 68, 68, 0.4) !important;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo $error; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- TÍTULO LIMPIO (SIN HERO NI TARJETAS INNECESARIAS) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-2 border-bottom" style="border-color: var(--gold-border) !important;">
        <div>
            <h2 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-lock2-fill" style="color: var(--gold-accent);"></i> Políticas y Normativas Institucionales
            </h2>
            <p class="text-secondary small mb-0">
                Directrices corporativas y normativas oficiales organizadas por áreas de Grupo Huerta.
            </p>
        </div>

        <!-- BUSCADOR CON CONTORNO DORADO (BÚSQUEDA PROFUNDA EN TÍTULO Y CONTENIDO DE PDFS) -->
        <div class="w-100 w-md-auto" style="min-width: 300px; max-width: 420px;">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-dark text-secondary" style="border: 1px solid var(--gold-border); border-right: none; border-radius: 10px 0 0 10px;"><i class="bi bi-search" style="color: var(--gold-accent);"></i></span>
                <input type="text" id="filtroTextoPolitica" class="form-control gold-search-input" style="border-radius: 0 10px 10px 0;" placeholder="Buscar por título, palabra o frase dentro del PDF..." oninput="filtrarPoliticasGlobal()">
            </div>
            <div id="indicadorStatusIndexacion" class="d-flex align-items-center justify-content-end mt-1 text-secondary font-monospace" style="font-size: 0.68rem;">
                <span id="txtStatusIndexacion"><i class="bi bi-cpu text-warning me-1"></i>Búsqueda profunda en PDFs activa</span>
            </div>
        </div>
    </div>

    <!-- PESTAÑAS DENTRO DEL MÓDULO (ÁREAS Y POLÍTICAS) -->
    <ul class="nav module-tab-nav mb-4" id="politicasPillsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pill-areas-tab" data-bs-toggle="tab" data-bs-target="#vista-areas" type="button" role="tab" onclick="volverACatalogoAreas()">
                <i class="bi bi-folder-fill" style="color: var(--gold-accent);"></i> Áreas Institucionales
                <span class="subarea-badge-count ms-1"><?php echo count($areasEstructuradas); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="pill-detalle-tab" data-bs-toggle="tab" data-bs-target="#vista-detalle-politicas" type="button" role="tab">
                <i class="bi bi-list-task" style="color: var(--gold-accent);"></i> <span id="lblTituloPestanaDetalle">Listado de Políticas</span>
                <span class="subarea-badge-count ms-1" id="badgeTotalPoliticasPestana"><?php echo count($politicasLista); ?></span>
            </button>
        </li>
    </ul>

    <!-- CONTENIDO DE LAS PESTAÑAS -->
    <div class="tab-content" id="politicasPillsContent">

        <!-- PESTAÑA 1: CATÁLOGO DE ÁREAS -->
        <div class="tab-pane fade show active" id="vista-areas" role="tabpanel">
            <?php if (empty($areasEstructuradas)): ?>
                <div class="text-center py-5 rounded-4 p-5" style="background: rgba(10, 27, 50, 0.4); border: 1px solid var(--gold-border);">
                    <i class="bi bi-folder-plus display-2 opacity-50 d-block mb-3" style="color: var(--gold-accent);"></i>
                    <h4 class="fw-bold text-white mb-2">Aún no hay áreas de políticas dadas de alta</h4>
                    <p class="text-secondary small mb-4" style="max-width: 520px; margin: 0 auto;">
                        Como administrador de Grupo Huerta, puedes dar de alta la primera área y subir el archivo PDF oficial.
                    </p>
                    <?php if ($esAdmin): ?>
                        <button type="button" class="btn-gold-action" data-bs-toggle="modal" data-bs-target="#modalSubirPolitica">
                            <i class="bi bi-plus-lg"></i> Dar de Alta Nueva Área y Subir Política
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($areasEstructuradas as $nombreArea => $infoArea): 
                        $iconoArea = obtenerIconoArea($nombreArea);
                        $cantSubareas = count($infoArea['subareas']);
                        $cantPoliticas = $infoArea['total_politicas'];
                    ?>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="area-folder-card" onclick="seleccionarArea('<?php echo addslashes(htmlspecialchars($nombreArea)); ?>')">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="area-icon-wrap">
                                            <i class="bi <?php echo $iconoArea; ?>"></i>
                                        </div>
                                        <?php if ($infoArea['total_obligatorias'] > 0): ?>
                                            <span class="badge-mandatory-pill">
                                                <i class="bi bi-exclamation-circle-fill me-1"></i> <?php echo $infoArea['total_obligatorias']; ?> Obligatoria<?php echo $infoArea['total_obligatorias'] > 1 ? 's' : ''; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <h4 class="area-title-text">
                                        <?php echo htmlspecialchars($nombreArea); ?>
                                    </h4>

                                    <p class="text-secondary small mb-3">
                                        <?php echo $cantSubareas; ?> subárea<?php echo $cantSubareas !== 1 ? 's' : ''; ?> registrada<?php echo $cantSubareas !== 1 ? 's' : ''; ?>
                                    </p>
                                </div>

                                <div>
                                    <div class="d-flex justify-content-between align-items-center pt-3 border-top text-secondary small" style="border-color: rgba(212, 175, 55, 0.18) !important;">
                                        <span><i class="bi bi-file-earmark-text me-1" style="color: var(--gold-accent);"></i> <b><?php echo $cantPoliticas; ?></b> política<?php echo $cantPoliticas !== 1 ? 's' : ''; ?></span>
                                        <span class="fw-semibold" style="color: var(--gold-accent-light);">Entrar <i class="bi bi-arrow-right ms-1"></i></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- PESTAÑA 2: LISTADO DE POLÍTICAS POR ÁREA Y SUBÁREAS -->
        <div class="tab-pane fade" id="vista-detalle-politicas" role="tabpanel">
            
            <!-- BARRA DE NAVEGACIÓN Y ACCIÓN RÁPIDA -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 p-3 rounded-3" style="background: rgba(14, 38, 70, 0.4); border: 1px solid var(--gold-border);">
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm text-light rounded-3 px-3" style="border-color: var(--gold-border);" onclick="volverACatalogoAreas()">
                        <i class="bi bi-arrow-left me-1"></i> Volver a Áreas
                    </button>
                    <span class="text-secondary opacity-50">&bull;</span>
                    <h5 class="fw-bold text-white mb-0 d-inline-flex align-items-center gap-2">
                        <i class="bi bi-folder2-open" style="color: var(--gold-accent);"></i> <span id="lblAreaNombreActiva">Todas las Áreas</span>
                    </h5>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <span class="subarea-badge-count" id="lblBadgeConteoDetalle">
                        <?php echo count($politicasLista); ?> Políticas
                    </span>
                    <?php if ($esAdmin): ?>
                        <button type="button" class="btn btn-outline-warning btn-sm rounded-3 px-3 fw-bold" style="border-color: var(--gold-border); color: var(--gold-accent-light);" data-bs-toggle="modal" data-bs-target="#modalSubirPolitica">
                            <i class="bi bi-plus-lg me-1"></i> Agregar a esta Área
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CONTENEDOR DE SUBÁREAS Y POLÍTICAS -->
            <div id="contenedorSubareasYPoliticas">
                <?php if (empty($areasEstructuradas)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No hay políticas registradas actualmente.
                    </div>
                <?php else: ?>
                    <?php foreach ($areasEstructuradas as $nombreArea => $infoArea): ?>
                        <div class="bloque-area-contenedor" data-area-nombre="<?php echo htmlspecialchars($nombreArea); ?>">
                            
                            <?php foreach ($infoArea['subareas'] as $nombreSubarea => $politicasSub): ?>
                                <div class="subarea-group-card bloque-subarea-item" data-area="<?php echo htmlspecialchars($nombreArea); ?>" data-subarea="<?php echo htmlspecialchars($nombreSubarea); ?>">
                                    <!-- ENCABEZADO DE SUBÁREA -->
                                    <div class="subarea-header">
                                        <h5 class="subarea-title">
                                            <i class="bi bi-folder-fill" style="color: var(--gold-accent);"></i>
                                            <span><?php echo htmlspecialchars($nombreSubarea); ?></span>
                                            <span class="text-secondary small fw-normal">(<?php echo htmlspecialchars($nombreArea); ?>)</span>
                                        </h5>
                                        <span class="subarea-badge-count">
                                            <?php echo count($politicasSub); ?> documento<?php echo count($politicasSub) !== 1 ? 's' : ''; ?>
                                        </span>
                                    </div>

                                    <!-- LISTADO DE POLÍTICAS EN ESTA SUBÁREA -->
                                    <div class="subarea-body">
                                        <?php foreach ($politicasSub as $pol): 
                                            $folioCode = 'GH-POL-' . str_pad($pol['id'], 3, '0', STR_PAD_LEFT);
                                            $textoBusqueda = strtolower($pol['titulo'] . ' ' . $pol['descripcion'] . ' ' . $folioCode . ' ' . $nombreArea . ' ' . $nombreSubarea);
                                        ?>
                                            <div class="policy-item-row fila-politica-item" id="filaPolitica_<?php echo $pol['id']; ?>" data-doc-id="<?php echo $pol['id']; ?>" data-doc-titulo="<?php echo htmlspecialchars($pol['titulo']); ?>" data-doc-folio="<?php echo $folioCode; ?>" data-doc-version="<?php echo htmlspecialchars($pol['version'] ?? '1.0'); ?>" data-search="<?php echo htmlspecialchars($textoBusqueda); ?>" data-area="<?php echo htmlspecialchars($nombreArea); ?>">">
                                                <div class="d-flex align-items-center gap-3" style="max-width: 65%;">
                                                    <span class="folio-pill flex-shrink-0"><?php echo $folioCode; ?></span>
                                                    
                                                    <div>
                                                        <div class="d-flex align-items-center gap-2 mb-1">
                                                            <h6 class="fw-bold text-white mb-0">
                                                                <?php echo htmlspecialchars($pol['titulo']); ?>
                                                            </h6>
                                                            <span class="badge bg-dark border font-monospace" style="font-size: 0.65rem; border-color: var(--gold-border) !important; color: var(--gold-accent-light);">
                                                                v<?php echo htmlspecialchars($pol['version'] ?? '1.0'); ?>
                                                            </span>
                                                            <?php if (!empty($pol['obligatorio_lectura'])): ?>
                                                                <span class="badge-mandatory-pill">
                                                                    <i class="bi bi-shield-fill-exclamation"></i> Obligatoria
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>

                                                        <?php if (!empty($pol['descripcion'])): ?>
                                                            <p class="text-secondary small mb-0" style="font-size: 0.8rem;">
                                                                <?php echo htmlspecialchars($pol['descripcion']); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <!-- CONTENEDOR DE COINCIDENCIA DENTRO DEL CONTENIDO DEL PDF -->
                                                        <div class="snippet-busqueda-pdf mt-2 d-none" id="snippetMatch_<?php echo $pol['id']; ?>"></div>
                                                    </div>
                                                </div>

                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="text-end text-secondary small d-none d-md-block me-2" style="font-size: 0.72rem;">
                                                        <span class="d-block"><i class="bi bi-calendar3 me-1" style="color: var(--gold-accent);"></i> Vigencia: <?php echo !empty($pol['fecha_vigencia']) ? date('d/m/Y', strtotime($pol['fecha_vigencia'])) : 'Permanente'; ?></span>
                                                        <span style="color: var(--gold-accent-light);"><i class="bi bi-shield-check me-1"></i> Protegido</span>
                                                    </div>

                                                    <button type="button" class="btn-view-doc" id="btnLeerPol_<?php echo $pol['id']; ?>" onclick="abrirVisorBlindado(<?php echo $pol['id']; ?>, '<?php echo addslashes(htmlspecialchars($pol['titulo'])); ?>', '<?php echo $folioCode; ?>', this.getAttribute('data-target-page') || null)">
                                                        <i class="bi bi-eye-fill"></i> Leer
                                                    </button>

                                                    <?php if ($esAdmin): ?>
                                                        <!-- BOTÓN ELIMINAR (ADMIN) -->
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que deseas eliminar la política \'<?php echo addslashes(htmlspecialchars($pol['titulo'])); ?>\' de forma permanente?');">
                                                            <input type="hidden" name="accion" value="eliminar_politica">
                                                            <input type="hidden" name="politica_id" value="<?php echo $pol['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-3 py-1.5 px-2" title="Eliminar política">
                                                                <i class="bi bi-trash3-fill"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

    </div>

</div>

<!-- ========================================================================= -->
<!-- MODAL: DAR DE ALTA ÁREA, SUBÁREA Y SUBIR POLÍTICA PDF (ADMINISTRADOR) -->
<!-- ========================================================================= -->
<?php if ($esAdmin): ?>
<div class="modal fade" id="modalSubirPolitica" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content text-white" style="background: #09172c; border: 2px solid var(--gold-border); border-radius: 18px; box-shadow: 0 25px 60px rgba(0,0,0,0.8);">
            <div class="modal-header border-secondary border-opacity-25" style="border-bottom: 1px solid var(--gold-border);">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" style="color: var(--gold-accent-light);">
                    <i class="bi bi-cloud-arrow-up-fill" style="color: var(--gold-accent);"></i> Dar de Alta Política Corporativa (PDF)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="subir_politica">
                <div class="modal-body p-4">
                    
                    <!-- TÍTULO -->
                    <div class="mb-3">
                        <label class="form-label text-light small fw-bold">Título Oficial de la Política o Normativa *</label>
                        <input type="text" name="titulo" class="form-control gold-search-input" placeholder="Ej. Política de Uso de Equipo de Cómputo y TI" required>
                    </div>

                    <!-- ÁREA Y SUBÁREA -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-light small fw-bold">Área Institucional *</label>
                            <input list="listaAreasDatalist" name="area" id="inputModalArea" class="form-control gold-search-input" placeholder="Ej. Dirección Sistemas, Finanzas, etc." required>
                            <datalist id="listaAreasDatalist">
                                <?php foreach (array_keys($areasEstructuradas) as $aNom): ?>
                                    <option value="<?php echo htmlspecialchars($aNom); ?>">
                                <?php endforeach; ?>
                                <option value="Dirección Sistemas">
                                <option value="Seguridad de la Información">
                                <option value="Uso de Equipos y TI">
                                <option value="Código de Ética y Conducta">
                                <option value="Recursos Humanos">
                                <option value="Operaciones y Servicios">
                                <option value="Finanzas y Administración">
                            </datalist>
                            <div class="form-text text-secondary" style="font-size: 0.72rem;">
                                Puedes elegir una existente o escribir una <b>nueva área</b> para darla de alta.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-light small fw-bold">Subárea *</label>
                            <input list="listaSubareasDatalist" name="subarea" id="inputModalSubarea" class="form-control gold-search-input" placeholder="Ej. Infraestructura, Ciberseguridad, etc." required>
                            <datalist id="listaSubareasDatalist">
                                <?php foreach (array_keys($todasSubareasExistentes) as $sNom): ?>
                                    <option value="<?php echo htmlspecialchars($sNom); ?>">
                                <?php endforeach; ?>
                                <option value="Políticas Generales">
                                <option value="Infraestructura">
                                <option value="Ciberseguridad">
                                <option value="Soporte y Telecomunicaciones">
                                <option value="Desarrollo e Innovación">
                                <option value="Control Interno">
                            </datalist>
                            <div class="form-text text-secondary" style="font-size: 0.72rem;">
                                Subárea para clasificar y agrupar el documento.
                            </div>
                        </div>
                    </div>

                    <!-- VERSIÓN Y VIGENCIA -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-light small fw-bold">Versión del Documento</label>
                            <input type="text" name="version" class="form-control gold-search-input font-monospace" value="1.0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light small fw-bold">Fecha de Vigencia (Opcional)</label>
                            <input type="date" name="fecha_vigencia" class="form-control gold-search-input">
                        </div>
                    </div>

                    <!-- DESCRIPCIÓN -->
                    <div class="mb-3">
                        <label class="form-label text-light small fw-bold">Resumen / Descripción Ejecutiva</label>
                        <textarea name="descripcion" rows="2" class="form-control gold-search-input" placeholder="Describe brevemente el alcance de esta normativa institucional..."></textarea>
                    </div>

                    <!-- ARCHIVO PDF OFICIAL -->
                    <div class="mb-3">
                        <label class="form-label text-light small fw-bold">Archivo PDF Oficial *</label>
                        <input type="file" name="archivo_pdf" accept="application/pdf,.pdf" class="form-control gold-search-input" required>
                        <div class="form-text text-secondary" style="font-size: 0.72rem;">
                            El PDF será renderizado en modo blindado (Canvas sin descarga) y protegido contra capturas.
                        </div>
                    </div>

                    <!-- OBLIGATORIO -->
                    <div class="form-check form-switch p-3 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08);">
                        <input class="form-check-input" type="checkbox" name="obligatorio_lectura" id="checkObligatorioModal" value="1">
                        <label class="form-check-label text-light fw-bold small ms-2" for="checkObligatorioModal">
                            Marcar como lectura obligatoria para el personal
                        </label>
                        <div class="text-secondary small ms-2" style="font-size: 0.75rem;">
                            Se mostrará un distintivo de cumplimiento institucional en las agencias.
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-secondary border-opacity-25" style="border-top: 1px solid var(--gold-border);">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-gold-action px-4">
                        <i class="bi bi-check2-circle"></i> Publicar y Sincronizar en Portal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: BITÁCORA AUDITABLE DE LECTURAS (SOLO ADMINISTRADORES) -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalAuditoriaLecturas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content text-white" style="background: #09172c; border: 2px solid var(--gold-border); border-radius: 18px;">
            <div class="modal-header border-secondary border-opacity-25" style="border-bottom: 1px solid var(--gold-border);">
                <div class="d-flex align-items-center justify-content-between w-100 me-3">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2 mb-0" style="color: var(--gold-accent-light);">
                        <i class="bi bi-shield-check" style="color: var(--gold-accent);"></i> Auditoría y Trazabilidad Forense Anti-IA
                    </h5>
                    <!-- PESTAÑAS DENTRO DEL MODAL -->
                    <ul class="nav nav-pills" id="auditoriaPills" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active btn-sm py-1 px-3 fw-bold" id="tab-bitacora-btn" data-bs-toggle="pill" data-bs-target="#tab-bitacora-pane" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i> Bitácora
                            </button>
                        </li>
                        <li class="nav-item ms-1">
                            <button class="nav-link btn-sm py-1 px-3 fw-bold" id="tab-terminos-btn" data-bs-toggle="pill" data-bs-target="#tab-terminos-pane" type="button" role="tab" style="color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4);">
                                <i class="bi bi-patch-check-fill text-success me-1"></i> Avisos Firmados <span class="badge bg-success text-dark ms-1" id="badgeTotalTerminosNav">0</span>
                            </button>
                        </li>
                        <li class="nav-item ms-1">
                            <button class="nav-link btn-sm py-1 px-3 fw-bold" id="tab-detector-btn" data-bs-toggle="pill" data-bs-target="#tab-detector-pane" type="button" role="tab" style="color: var(--gold-accent-light); border: 1px solid var(--gold-border);">
                                <i class="bi bi-fingerprint text-warning me-1"></i> Detector Anti-IA
                            </button>
                        </li>
                    </ul>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="tab-content" id="auditoriaPillsContent">
                    <!-- PANEL 1: BITÁCORA DE LECTURAS -->
                    <div class="tab-pane fade show active" id="tab-bitacora-pane" role="tabpanel">
                
                <!-- STATS DE AUDITORÍA -->
                <div class="row g-3 mb-3" id="statsAuditoriaContainer">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(14, 38, 70, 0.5); border: 1px solid var(--gold-border);">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.72rem;">Total Registros</div>
                            <div class="fs-4 fw-bold text-white mt-1" id="statTotalLecturas">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(14, 38, 70, 0.5); border: 1px solid var(--gold-border);">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.72rem;">Colaboradores</div>
                            <div class="fs-4 fw-bold" style="color: var(--gold-accent-light);" id="statUsuariosUnicos">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(14, 38, 70, 0.5); border: 1px solid var(--gold-border);">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.72rem;">Agencias Activas</div>
                            <div class="fs-4 fw-bold text-info mt-1" id="statAgenciasActivas">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3" style="background: rgba(14, 38, 70, 0.5); border: 1px solid var(--gold-border);">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.72rem;">Permanencia Promedio</div>
                            <div class="fs-4 fw-bold text-warning mt-1" id="statTiempoPromedio">--</div>
                        </div>
                    </div>
                </div>

                <!-- BARRA DE BÚSQUEDA Y FILTROS -->
                <div class="p-3 mb-3 rounded-3" style="background: rgba(14, 38, 70, 0.45); border: 1px solid var(--gold-border);">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-4">
                            <label class="form-label text-secondary small fw-bold mb-1">
                                <i class="bi bi-search text-warning me-1"></i> Búsqueda Rápida
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" id="filtroBitacoraTexto" class="form-control bg-dark text-white border-secondary" placeholder="Colaborador, política, IP, usuario..." oninput="filtrarBitacora()">
                                <button class="btn btn-outline-secondary border-secondary text-secondary" type="button" onclick="limpiarFiltroBitacora()" title="Limpiar"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label class="form-label text-secondary small fw-bold mb-1">
                                <i class="bi bi-building text-info me-1"></i> Agencia
                            </label>
                            <select id="filtroBitacoraAgencia" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="filtrarBitacora()">
                                <option value="">Todas las agencias</option>
                            </select>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label class="form-label text-secondary small fw-bold mb-1">
                                <i class="bi bi-file-earmark-text text-success me-1"></i> Política / Actividad
                            </label>
                            <select id="filtroBitacoraPolitica" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="filtrarBitacora()">
                                <option value="">Todas las políticas</option>
                            </select>
                        </div>

                        <div class="col-12 col-lg-2">
                            <label class="form-label text-secondary small fw-bold mb-1">
                                <i class="bi bi-clock-history text-warning me-1"></i> Periodo / Estado
                            </label>
                            <select id="filtroBitacoraPeriodo" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="filtrarBitacora()">
                                <option value="todos">Todo el historial</option>
                                <option value="hoy">Hoy</option>
                                <option value="ayer">Ayer</option>
                                <option value="7dias">Últimos 7 días</option>
                                <option value="activas">En visualización activa</option>
                                <option value="concluidas">Concluidas</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-2 border-top border-secondary border-opacity-25 gap-2">
                        <div class="text-secondary small d-flex align-items-center gap-2">
                            <span id="lblContadorFiltrados" class="badge bg-dark border border-secondary text-light font-monospace">Cargando...</span>
                            <span id="lblFiltroActivoBadge" class="d-none badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25">Filtros activos</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm text-light rounded-3 px-2.5 py-1" onclick="exportarBitacoraCSV()" title="Exportar registros a CSV / Excel">
                                <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Exportar CSV
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm text-light rounded-3 px-2.5 py-1" onclick="cargarDatosBitacora()" title="Actualizar bitácora en vivo">
                                <i class="bi bi-arrow-clockwise text-warning me-1"></i> Actualizar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TABLA DE LECTURAS -->
                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-dark table-hover align-middle mb-0" style="font-size: 0.82rem; background: transparent;">
                        <thead class="sticky-top" style="background: #09172c; z-index: 2;">
                            <tr class="text-secondary border-secondary">
                                <th style="min-width: 130px;">Hora Ingreso</th>
                                <th style="min-width: 130px;">Hora Salida</th>
                                <th style="min-width: 110px;">Permanencia</th>
                                <th style="min-width: 180px;">Política / Documento</th>
                                <th style="min-width: 200px;">Colaborador</th>
                                <th style="min-width: 140px;">Agencia</th>
                                <th style="min-width: 110px;">Terminal / IP</th>
                                <th style="min-width: 120px;">Origen</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyAuditoriaLecturas">
                            <tr>
                                <td colspan="8" class="text-center py-4 text-secondary">
                                    <div class="spinner-border spinner-border-sm me-2" style="color: var(--gold-accent);"></div>
                                    Cargando bitácora de auditoría...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                    </div> <!-- FIN PANEL 1 (BITÁCORA) -->

                    <!-- PANEL 2: DETECTOR FORENSE DE IMÁGENES FILTRADAS (ANTI-IA) -->
                    <div class="tab-pane fade" id="tab-detector-pane" role="tabpanel">
                        <div class="p-4 rounded-3 text-center mb-4" style="background: rgba(14, 38, 70, 0.45); border: 2px dashed var(--gold-border);">
                            <div class="fs-1 text-warning mb-2"><i class="bi bi-shield-lock-fill"></i></div>
                            <h5 class="fw-bold text-white mb-2">Inspección Forense de Documentos Filtrados</h5>
                            <p class="text-secondary small mb-3" style="max-width: 650px; margin: 0 auto;">
                                Sube o arrastra aquí cualquier foto o captura de una política (incluso si intentaron limpiarla con Photoshop o herramientas de IA). El decodificador buscará la constelación esteganográfica de micro-puntos y extraerá la firma del usuario responsable.
                            </p>
                            <input type="file" id="inputForenseFile" class="d-none" accept="image/*" onchange="if(this.files && this.files[0]) procesarArchivoForense(this.files[0])">
                            <button type="button" class="btn btn-warning fw-bold px-4 py-2 rounded-3 shadow" onclick="document.getElementById('inputForenseFile').click()">
                                <i class="bi bi-upload me-2"></i> Seleccionar Imagen / Captura Filtrada
                            </button>
                        </div>
                        <div id="resultadoForenseBox" class="d-none mb-3"></div>
                    </div> <!-- FIN PANEL 2 -->

                    <!-- PANEL 3: AVISOS DE PRIVACIDAD Y TÉRMINOS ACEPTADOS -->
                    <div class="tab-pane fade" id="tab-terminos-pane" role="tabpanel">
                        <!-- STATS DE AVISOS -->
                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-4">
                                <div class="p-3 rounded-3" style="background: rgba(14, 38, 70, 0.5); border: 1px solid rgba(16, 185, 129, 0.4);">
                                    <div class="text-secondary small text-uppercase" style="font-size: 0.72rem;">Total Firmas / Aceptaciones</div>
                                    <div class="fs-4 fw-bold text-success mt-1" id="statTerminosTotal">0</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4">
                                <div class="p-3 rounded-3" style="background: rgba(14, 38, 70, 0.5); border: 1px solid var(--gold-border);">
                                    <div class="text-secondary small text-uppercase" style="font-size: 0.72rem;">Agencias Participantes</div>
                                    <div class="fs-4 fw-bold text-info mt-1" id="statTerminosAgencias">0</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="p-3 rounded-3" style="background: rgba(14, 38, 70, 0.5); border: 1px solid var(--gold-border);">
                                    <div class="text-secondary small text-uppercase" style="font-size: 0.72rem;">Última Firma Registrada</div>
                                    <div class="fs-6 fw-bold text-warning mt-2 font-monospace" id="statTerminosUltima">--</div>
                                </div>
                            </div>
                        </div>

                        <!-- BARRA DE BÚSQUEDA Y FILTROS DE AVISOS -->
                        <div class="p-3 mb-3 rounded-3" style="background: rgba(14, 38, 70, 0.45); border: 1px solid var(--gold-border);">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-secondary small fw-bold mb-1">
                                        <i class="bi bi-search text-success me-1"></i> Buscar Colaborador / Usuario / IP
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                                        <input type="text" id="filtroTerminosTexto" class="form-control bg-dark text-white border-secondary" placeholder="Nombre, login, terminal..." oninput="filtrarTablaTerminos()">
                                        <button class="btn btn-outline-secondary border-secondary text-secondary" type="button" onclick="document.getElementById('filtroTerminosTexto').value=''; filtrarTablaTerminos();" title="Limpiar"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label text-secondary small fw-bold mb-1">
                                        <i class="bi bi-building text-info me-1"></i> Agencia
                                    </label>
                                    <select id="filtroTerminosAgencia" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="filtrarTablaTerminos()">
                                        <option value="">Todas las agencias</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3 d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm text-light rounded-3 px-2.5 py-1" onclick="exportarTerminosCSV()" title="Descargar reporte en CSV">
                                        <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Exportar CSV
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm text-light rounded-3 px-2.5 py-1" onclick="cargarTerminosAceptadosCentral()" title="Recargar lista">
                                        <i class="bi bi-arrow-clockwise text-warning me-1"></i> Actualizar
                                    </button>
                                </div>
                            </div>
                            <div class="mt-2 text-secondary small font-monospace" id="lblContadorTerminos">
                                Mostrando 0 registros
                            </div>
                        </div>

                        <!-- TABLA DE AVISOS ACEPTADOS -->
                        <div class="table-responsive" style="max-height: 440px; overflow-y: auto;">
                            <table class="table table-dark table-hover align-middle mb-0" style="font-size: 0.82rem; background: transparent;">
                                <thead class="sticky-top" style="background: #09172c; z-index: 2;">
                                    <tr class="text-secondary border-secondary">
                                        <th style="min-width: 140px;">Fecha y Hora Firma</th>
                                        <th style="min-width: 220px;">Colaborador</th>
                                        <th style="min-width: 140px;">Agencia</th>
                                        <th style="min-width: 110px;">Terminal / IP</th>
                                        <th style="min-width: 100px;">Origen</th>
                                        <th style="min-width: 90px;">Versión</th>
                                        <th style="min-width: 130px;">Estado</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyTerminosAceptados">
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-secondary">
                                            <div class="spinner-border spinner-border-sm me-2 text-success"></div>
                                            Cargando registros de avisos firmados...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div> <!-- FIN PANEL 3 -->
                </div> <!-- FIN TAB-CONTENT -->
            </div> <!-- FIN MODAL-BODY -->
            <div class="modal-footer border-secondary border-opacity-25" style="border-top: 1px solid var(--gold-border);">
                <button type="button" class="btn btn-outline-secondary rounded-3 px-4" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- ========================================================================= -->
<!-- MODAL OBLIGATORIO: AVISO DE PRIVACIDAD Y TÉRMINOS Y CONDICIONES (PRIMERA VEZ) -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalAvisoPrivacidadTerminos" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="modalAvisoPrivacidadLabel" aria-hidden="true" style="z-index: 1000000;">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content text-white" style="background: #061122; border: 2px solid var(--gold-border); border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,0.95);">
            
            <div class="modal-header border-secondary border-opacity-25 p-4" style="border-bottom: 1px solid var(--gold-border); background: linear-gradient(135deg, rgba(212, 175, 55, 0.12) 0%, rgba(5, 13, 26, 0.95) 100%);">
                <div class="d-flex align-items-center gap-3">
                    <img src="uploads/logo_gh.png" alt="Grupo Huerta" style="height: 48px; width: auto; object-fit: contain;">
                    <div>
                        <span class="badge bg-warning text-dark font-monospace small px-2.5 py-1 fw-bold mb-1">
                            <i class="bi bi-shield-lock-fill me-1"></i> PROTOCOLO INSTITUCIONAL OBLIGATORIO
                        </span>
                        <h5 class="modal-title fw-bold text-white mb-0" id="modalAvisoPrivacidadLabel" style="letter-spacing: 0.5px;">
                            Aviso de Privacidad y Términos de Confidencialidad
                        </h5>
                        <p class="text-secondary small mb-0 font-monospace" style="font-size: 0.75rem;">
                            Grupo Huerta • Repositorio Central de Políticas y Normativas
                        </p>
                    </div>
                </div>
            </div>

            <div class="modal-body p-4" style="background: rgba(3, 8, 18, 0.85); font-size: 0.88rem; line-height: 1.65; color: #cbd5e1;">
                
                <div class="alert alert-dark border border-warning border-opacity-40 rounded-3 p-3 mb-3 d-flex align-items-start gap-2.5" style="background: rgba(212, 175, 55, 0.08);">
                    <i class="bi bi-info-circle-fill text-warning fs-5 flex-shrink-0 mt-0.5"></i>
                    <div class="small text-light">
                        Bienvenido al portal institucional, <strong><?php echo htmlspecialchars($nombreUsuario); ?></strong>. Para acceder y consultar las normativas de <strong>Grupo Huerta</strong> y tu agencia (<strong><?php echo htmlspecialchars($agenciaNombre); ?></strong>), es indispensable leer y confirmar tu conformidad con el presente Aviso de Privacidad y Términos de Uso.
                    </div>
                </div>

                <div class="p-3.5 rounded-3 border border-secondary border-opacity-25" style="background: #040a16; max-height: 320px; overflow-y: auto;">
                    
                    <h6 class="fw-bold text-warning mb-2 d-flex align-items-center gap-1.5" style="font-size: 0.92rem;">
                        <i class="bi bi-shield-shaded text-warning"></i> I. AVISO DE PRIVACIDAD SIMPLIFICADO
                    </h6>
                    <p class="mb-3 small">
                        <strong>Grupo Huerta</strong>, con domicilio en sus oficinas corporativas y agencias autorizadas, es responsable del tratamiento de los datos personales y de acceso generados durante tu interacción con este sistema. Tus datos de identificación (nombre, usuario, agencia y dirección IP) se emplean estrictamente con fines de autenticación, control de accesos, auditoría de cumplimiento normativo y seguridad de la información, conforme a la legislación aplicable en materia de protección de datos personales.
                    </p>

                    <h6 class="fw-bold text-warning mb-2 d-flex align-items-center gap-1.5" style="font-size: 0.92rem;">
                        <i class="bi bi-file-earmark-lock2-fill text-warning"></i> II. PROPIEDAD INTELECTUAL Y CONFIDENCIALIDAD
                    </h6>
                    <p class="mb-3 small">
                        Toda la documentación, políticas, códigos de conducta, manuales operativos, formatos y anexos contenidos en este repositorio son <strong>PROPIEDAD EXCLUSIVA DE GRUPO HUERTA</strong> y constituyen <strong>SECRETOS INDUSTRIALES Y COMERCIALES</strong>. Su acceso se concede con carácter estrictamente confidencial, personal e intransferible únicamente para el ejercicio de tus funciones laborales dentro de la organización.
                    </p>

                    <h6 class="fw-bold text-warning mb-2 d-flex align-items-center gap-1.5" style="font-size: 0.92rem;">
                        <i class="bi bi-camera-video-off-fill text-warning"></i> III. PROHIBICIONES Y RESTRICCIONES DE SEGURIDAD
                    </h6>
                    <p class="mb-2 small">Queda estrictamente prohibido a cualquier colaborador o usuario:</p>
                    <ul class="small mb-3 ps-3" style="list-style-type: square;">
                        <li>Reproducir, fotografiar con teléfonos móviles, escanear, imprimir o realizar capturas de pantalla de los documentos.</li>
                        <li>Descargar, transferir o enviar archivos a correos personales, dispositivos externos (USB), servicios de almacenamiento en la nube o chats de mensajería (WhatsApp, Telegram, etc.).</li>
                        <li>Suministrar o filtrar la documentación a exempleados, competidores, clientes, proveedores o cualquier tercero no autorizado.</li>
                        <li>Utilizar herramientas de Inteligencia Artificial (IA), software de OCR o programas de edición para intentar eliminar las marcas de agua institucionales o alterar su contenido.</li>
                    </ul>

                    <h6 class="fw-bold text-warning mb-2 d-flex align-items-center gap-1.5" style="font-size: 0.92rem;">
                        <i class="bi bi-fingerprint text-warning"></i> IV. TRAZABILIDAD FORENSE DIGITAL ACTIVA
                    </h6>
                    <p class="mb-3 small">
                        El colaborador reconoce que cada lectura es monitoreada en tiempo real. Los visores inyectan de forma dinámica en cada página <strong>marcas de agua con tu nombre, agencia, fecha y hora exacta</strong>, así como <strong>micro-códigos esteganográficos invisibles</strong> integrados en los píxeles del documento. Cualquier divulgación no autorizada permitirá la identificación pericial inmediata del origen de la filtración.
                    </p>

                    <h6 class="fw-bold text-warning mb-2 d-flex align-items-center gap-1.5" style="font-size: 0.92rem;">
                        <i class="bi bi-exclamation-octagon-fill text-danger"></i> V. RESPONSABILIDAD Y SANCIONES LEGALES
                    </h6>
                    <p class="mb-0 small">
                        El incumplimiento de estas disposiciones constituye una violación grave a las obligaciones laborales y de confidencialidad, dando lugar a la <strong>rescisión del contrato individual de trabajo sin responsabilidad para el patrón</strong> conforme al artículo 47 de la <em>Ley Federal del Trabajo</em>, con independencia de las denuncias civiles y penales correspondientes por revelación de secretos industriales y daño patrimonial conforme al <em>Código Penal Federal</em>.
                    </p>
                </div>

                <!-- CHECKBOX OBLIGATORIO DE VISTO Y CONFORMIDAD -->
                <div class="form-check p-3 rounded-3 mt-3" style="background: rgba(212, 175, 55, 0.08); border: 1.5px solid var(--gold-border);">
                    <input class="form-check-input ms-0 me-2.5" type="checkbox" id="chkAceptoTerminosPoliticas" style="cursor: pointer; transform: scale(1.2); margin-top: 3px;" onchange="toggleBotonAceptarTerminos(this.checked)">
                    <label class="form-check-label fw-bold text-white small" for="chkAceptoTerminosPoliticas" style="cursor: pointer; line-height: 1.45;">
                        <span class="text-warning">CONFIRMACIÓN Y ACEPTACIÓN EXPRESA:</span> He leído cuidadosamente, comprendo y acepto en su totalidad el Aviso de Privacidad, los Términos y Condiciones y las Políticas de Confidencialidad Institucional de Grupo Huerta.
                    </label>
                </div>

                <div id="msgErrorAceptacion" class="alert alert-danger p-2 small mt-2 d-none"></div>

                <!-- BOTÓN DE CONFIRMACIÓN -->
                <div class="mt-3">
                    <button type="button" id="btnAceptarTerminosPoliticas" class="btn btn-warning w-100 fw-bold py-2.5 rounded-3 shadow d-flex align-items-center justify-content-center gap-2" style="font-size: 0.92rem;" disabled onclick="confirmarAceptacionTerminos()">
                        <i class="bi bi-patch-check-fill fs-5"></i> Aceptar y Continuar al Módulo de Políticas
                    </button>
                    <div class="text-center text-secondary small font-monospace mt-2" style="font-size: 0.72rem;">
                        Colaborador: <?php echo htmlspecialchars($nombreUsuario); ?> (<?php echo htmlspecialchars($loginUsuario); ?>) • <?php echo htmlspecialchars($agenciaNombre); ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>


<!-- VISOR BLINDADO FULLSCREEN (MODAL CONTAINER) -->
<!-- ========================================================================= -->
<div id="visorModalOverlay">
    <!-- BARRA SUPERIOR DEL VISOR -->
    <div class="visor-header">
        <div class="d-flex align-items-center gap-2">
            <img src="uploads/logo_gh.png" alt="Grupo Huerta" style="height: 28px; width: auto; object-fit: contain; margin-right: 2px;">
            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger px-2.5 py-1 rounded-pill small font-monospace">
                <i class="bi bi-shield-fill-x me-1"></i> LECTURA PROTEGIDA
            </span>
            <span class="folio-pill d-none d-sm-inline" id="visorFolioDoc">
                GH-POL-000
            </span>
            <h6 class="fw-bold text-white mb-0 text-truncate" id="visorTituloDoc" style="max-width: 45vw;">
                Cargando Documento...
            </h6>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- CONTROLES DE ZOOM -->
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-dark text-white" style="border: 1px solid var(--gold-border);" onclick="cambiarZoomVisor(-0.15)" title="Alejar Zoom">
                    <i class="bi bi-dash-lg"></i>
                </button>
                <span id="visorZoomBadge" class="badge bg-dark border-top border-bottom text-info d-flex align-items-center px-2 font-monospace" style="border-color: var(--gold-border) !important; color: var(--gold-accent-light) !important;">
                    100%
                </span>
                <button type="button" class="btn btn-dark text-white" style="border: 1px solid var(--gold-border);" onclick="cambiarZoomVisor(0.15)" title="Acercar Zoom">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>

            <!-- CONTROLES DE PÁGINA -->
            <div class="d-flex align-items-center gap-1.5 text-secondary small font-monospace">
                <span>Página <b id="lblPaginaActual" class="text-white">1</b> de <b id="lblTotalPaginas" class="text-white">1</b></span>
            </div>

            <!-- BUSCADOR INTERNO DENTRO DEL PDF -->
            <div class="d-flex align-items-center gap-1.5 ms-1">
                <div class="input-group input-group-sm" style="max-width: 220px;">
                    <input type="text" id="visorBuscadorTexto" class="form-control bg-dark text-white border-secondary small" style="font-size: 0.76rem; border-color: var(--gold-border) !important;" placeholder="Buscar palabra en PDF..." onkeyup="if(event.key==='Enter') buscarTextoEnVisorActual()">
                    <button type="button" class="btn btn-dark text-warning border-secondary btn-sm" style="border-color: var(--gold-border) !important;" onclick="buscarTextoEnVisorActual()" title="Buscar en este documento">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <span id="lblVisorMatchCount" class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50 font-monospace small d-none">0 coincidencias</span>
            </div>

            <!-- BADGES DE SEGURIDAD -->
            <span class="badge bg-dark border rounded-pill px-2.5 py-1.5 small font-monospace d-none d-xl-inline-flex align-items-center" style="border-color: var(--gold-border) !important; color: var(--gold-accent-light);">
                <i class="bi bi-shield-lock-fill me-1" style="color: var(--gold-accent);"></i> Anti-Cámara Moiré
            </span>

            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger rounded-pill px-2.5 py-1.5 small font-monospace d-none d-lg-inline-flex align-items-center">
                <i class="bi bi-cursor-fill text-danger me-1"></i> Bloqueo Cursor Activo
            </span>
            
            <!-- BOTÓN CERRAR VISOR -->
            <button type="button" class="btn btn-outline-danger btn-sm rounded-3 fw-bold px-3 ms-2" onclick="cerrarVisorBlindado()">
                <i class="bi bi-x-lg me-1"></i> Cerrar
            </button>
        </div>
    </div>

    <!-- OVERLAY DE BLOQUEO POR CURSOR FUERA DEL PDF -->
    <div id="pdfLockOverlay">
        <div class="pdf-lock-box-gold px-4">
            <div class="mb-3" style="width: 76px; height: 76px; margin: 0 auto; border-radius: 50%; background: rgba(212, 175, 55, 0.15); border: 2px solid var(--gold-accent); display: flex; align-items: center; justify-content: center; font-size: 2.3rem; color: var(--gold-accent-light); box-shadow: 0 0 25px var(--gold-glow);">
                <i class="bi bi-shield-slash-fill"></i>
            </div>
            <h4 class="fw-bold text-white mb-2" style="letter-spacing: 0.5px;">LECTURA BLOQUEADA POR SEGURIDAD</h4>
            <p class="text-secondary mb-3 small" style="line-height: 1.5;">
                El cursor ha salido del área del documento. Por normativas de confidencialidad institucional de Grupo Huerta, el contenido se protege automáticamente.
            </p>
            <div class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-40 px-3.5 py-2 rounded-pill font-monospace small d-inline-flex align-items-center gap-2 shadow-lg mb-2">
                <span class="spinner-grow spinner-grow-sm text-danger" role="status"></span>
                Coloca el cursor sobre el documento PDF para continuar la lectura
            </div>
            <div class="text-secondary small font-monospace opacity-75" style="font-size: 0.72rem;">
                Lector: <?php echo htmlspecialchars($nombreUsuario); ?> | Agencia: <?php echo htmlspecialchars($agenciaNombre); ?>
            </div>
        </div>
    </div>

    <!-- ÁREA DE RENDERIZADO DE PÁGINAS PDF CON MARCA DE AGUA Y MALLA MOIRÉ -->
    <div id="visorCanvasContainer">
        <div id="visorLoader" class="text-center py-5">
            <div class="spinner-border mb-3" role="status" style="width: 3rem; height: 3rem; color: var(--gold-accent);"></div>
            <h5 class="fw-bold text-white">Desencriptando y renderizando documento protegido...</h5>
            <p class="text-secondary small">Aplicando marcas de agua forenses y trama óptica anti-captura.</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- CONTROL DE PESTAÑAS, VISOR BLINDADO Y AUDITORÍA -->
<script>
    // Datos forenses del usuario actual para la marca de agua
    const FORENSIC_USER_NAME = "<?php echo addslashes($nombreUsuario); ?>";
    const FORENSIC_USER_LOGIN = "<?php echo addslashes($loginUsuario); ?>";
    const FORENSIC_AGENCIA = "<?php echo addslashes($agenciaNombre); ?>";
    const FORENSIC_IP = "<?php echo addslashes($ipUsuario); ?>";

    let currentFolioDoc = 'GH-POL';
    const logoGhPreloaded = new Image();
    logoGhPreloaded.src = 'uploads/logo_gh.png';

        // =========================================================================
    // CONTROL DEL AVISO DE PRIVACIDAD Y TÉRMINOS Y CONDICIONES (EN CADA ACCESO)
    // =========================================================================
    document.addEventListener('DOMContentLoaded', function() {
        const modalTerminosEl = document.getElementById('modalAvisoPrivacidadTerminos');
        if (modalTerminosEl) {
            const modalTerminos = new bootstrap.Modal(modalTerminosEl, {
                backdrop: 'static',
                keyboard: false
            });
            modalTerminos.show();
        }
    });

    function toggleBotonAceptarTerminos(isChecked) {
        const btn = document.getElementById('btnAceptarTerminosPoliticas');
        if (btn) btn.disabled = !isChecked;
    }

    function confirmarAceptacionTerminos() {
        const chk = document.getElementById('chkAceptoTerminosPoliticas');
        const btn = document.getElementById('btnAceptarTerminosPoliticas');
        const msgErr = document.getElementById('msgErrorAceptacion');

        if (!chk || !chk.checked) {
            if (msgErr) {
                msgErr.classList.remove('d-none');
                msgErr.textContent = 'Es obligatorio marcar la casilla de aceptación para continuar.';
            }
            return;
        }

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Registrando aceptación y firma digital...';
        }
        if (msgErr) msgErr.classList.add('d-none');

        const formData = new FormData();
        formData.append('action', 'aceptar_terminos');
        formData.append('usuario_id', '<?php echo $usuarioId; ?>');
        formData.append('usuario_nombre', FORENSIC_USER_NAME);
        formData.append('usuario_login', FORENSIC_USER_LOGIN);
        formData.append('agencia', FORENSIC_AGENCIA);
        formData.append('ip', FORENSIC_IP);
        formData.append('version_terminos', '1.0');

        fetch('api_politicas.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                const modalEl = document.getElementById('modalAvisoPrivacidadTerminos');
                if (modalEl) {
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                    modalEl.remove();
                }
                const toastBienvenida = document.createElement('div');
                toastBienvenida.className = 'alert alert-success position-fixed top-0 end-0 m-3 shadow-lg rounded-3 text-white border-success d-flex align-items-center gap-2';
                toastBienvenida.style.zIndex = '9999999';
                toastBienvenida.style.background = '#0d2818';
                toastBienvenida.innerHTML = '<i class="bi bi-shield-check fs-5 text-success"></i> <div><strong>Aviso de Privacidad Confirmado:</strong> Acceso autorizado al repositorio institucional.</div>';
                document.body.appendChild(toastBienvenida);
                setTimeout(() => toastBienvenida.remove(), 4000);
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-patch-check-fill fs-5"></i> Aceptar y Continuar al Módulo de Políticas';
                }
                if (msgErr) {
                    msgErr.classList.remove('d-none');
                    msgErr.textContent = data.error || 'Error al registrar la aceptación. Intenta nuevamente.';
                }
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-patch-check-fill fs-5"></i> Aceptar y Continuar al Módulo de Políticas';
            }
            if (msgErr) {
                msgErr.classList.remove('d-none');
                msgErr.textContent = 'Error de conexión con el servidor: ' + err.message;
            }
        });
    }


    // =========================================================================
    // BLINDAJE DUAL: ENTRELAZADO DESTRUCTIVO + TRAMPA ESTEGANOGRÁFICA INVISIBLE
    // =========================================================================
    function aplicarMarcasAntiIADirectoEnCanvas(ctx, width, height, user, login, agencia, folio) {
        if (!ctx) return;
        try {
            const d = new Date();
            const pad = n => String(n).padStart(2, '0');
            const dia = pad(d.getDate());
            const mes = pad(d.getMonth() + 1);
            const anio = d.getFullYear();
            let h = d.getHours();
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            h = h ? h : 12;
            const hora = `${pad(h)}:${pad(d.getMinutes())}:${pad(d.getSeconds())} ${ampm}`;
            const fechaHoraFmt = `${dia}/${mes}/${anio} ${hora}`;

            const usuarioTxt = (user || 'COLABORADOR').toUpperCase();
            const loginTxt = (login || '').toLowerCase();
            const agenciaTxt = (agencia || 'AGENCIA').toUpperCase();
            const folioTxt = folio || 'GH-POL';

            // 1. Franjas perimetrales sutiles en bordes superior e inferior
            ctx.save();
            ctx.font = 'bold 10px "Inter", "Segoe UI", Arial, sans-serif';
            ctx.fillStyle = 'rgba(15, 23, 42, 0.40)';
            const barraSuperior = `USUARIO: ${usuarioTxt} (${loginTxt})   |   AGENCIA: ${agenciaTxt}   |   FECHA: ${fechaHoraFmt}`;
            ctx.fillText(barraSuperior, 24, 20);

            const barraInferior = `TRAZABILIDAD INSTITUCIONAL: ${usuarioTxt}   |   ${agenciaTxt}   |   ${fechaHoraFmt}   |   FOLIO: ${folioTxt}`;
            ctx.fillText(barraInferior, 24, height - 14);
            ctx.restore();

            // 2. MATRIZ DIAGONAL ARMONIZADA (CON LOGO GH Y TRANSPARENCIA CALIBRADA)
            ctx.save();
            ctx.translate(width / 2, height / 2);
            ctx.rotate(-28 * Math.PI / 180);
            ctx.translate(-width / 2, -height / 2);

            const line1 = `GRUPO HUERTA  •  CONFIDENCIAL INSTITUCIONAL`;
            const line2 = `USUARIO: ${usuarioTxt} (${loginTxt})`;
            const line3 = `AGENCIA: ${agenciaTxt}  •  USO INTERNO EXCLUSIVO`;
            const line4 = `FECHA: ${fechaHoraFmt}  •  FOLIO: ${folioTxt}`;

            const stepY = 145;
            const stepX = 410;
            const startX = -width * 0.7;
            const endX   = width * 1.7;
            const startY = -height * 0.7;
            const endY   = height * 1.7;

            // Fusión de tinta suave con el documento
            ctx.globalCompositeOperation = 'multiply';

            let row = 0;
            for (let y = startY; y < endY; y += stepY) {
                row++;
                const offX = (row % 2 === 0) ? (stepX / 2) : 0;
                for (let x = startX + offX; x < endX; x += stepX) {
                    // Logo oficial de Grupo Huerta en cada bloque
                    if (logoGhPreloaded && logoGhPreloaded.complete && logoGhPreloaded.naturalWidth > 0) {
                        ctx.save();
                        ctx.globalAlpha = 0.28;
                        ctx.drawImage(logoGhPreloaded, x, y - 14, 20, 20);
                        ctx.restore();
                    }

                    // Línea 1: Encabezado institucional
                    ctx.font = 'bold 9.5px "Inter", "Segoe UI", Arial, sans-serif';
                    ctx.fillStyle = 'rgba(71, 85, 105, 0.26)';
                    ctx.fillText(line1, x + 24, y);

                    // Línea 2: USUARIO nítido con transparencia suave y legible
                    ctx.font = 'bold 12.5px "Inter", "Segoe UI", Arial, sans-serif';
                    ctx.fillStyle = 'rgba(15, 23, 42, 0.29)';
                    ctx.fillText(line2, x, y + 17);

                    // Línea 3: AGENCIA con transparencia suave
                    ctx.font = 'bold 11.5px "Inter", "Segoe UI", Arial, sans-serif';
                    ctx.fillStyle = 'rgba(15, 23, 42, 0.27)';
                    ctx.fillText(line3, x, y + 33);

                    // Línea 4: FECHA Y HORA con tinte de seguridad sutil
                    ctx.font = 'bold 11px "Inter", "Segoe UI", Arial, monospace';
                    ctx.fillStyle = 'rgba(185, 28, 28, 0.28)';
                    ctx.fillText(line4, x, y + 49);
                }
            }
            ctx.restore();

            // 3. TRAMPA FORENSE ESTEGANOGRÁFICA INVISIBLE (YELLOW-DOTS / MIC)
            inyectarTrampaEsteganograficaAntiIA(ctx, width, height, loginTxt, agenciaTxt, `${dia}/${mes}/${anio}`, folioTxt);

        } catch (eCanvasW) {}
    }

        // Inyección de micro-puntos forenses (Estándar EURion / Machine Identification Codes)
    function inyectarTrampaEsteganograficaAntiIA(ctx, width, height, login, agencia, fechaStr, folio) {
        if (!ctx) return;
        try {
            const imgData = ctx.getImageData(0, 0, width, height);
            const d = imgData.data;

            // Cadena de trazabilidad compacta
            const payload = `GHSTG:${login}:${agencia}:${fechaStr}:${folio}`;
            const bits = [];
            for (let c = 0; c < payload.length; c++) {
                const code = payload.charCodeAt(c);
                for (let b = 7; b >= 0; b--) {
                    bits.push((code >> b) & 1);
                }
            }
            // Secuencia de sincronización de 16 bits (0xAA55)
            const syncHeader = [1,0,1,0,1,0,1,0, 0,1,0,1,0,1,0,1];
            const fullStream = syncHeader.concat(bits);

            // Inyectar en cuadrícula repetitiva de 64x64 píxeles por toda la hoja
            const blockSize = 64;
            const cols = Math.floor(width / blockSize);
            const rows = Math.floor(height / blockSize);

            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) {
                    const startX = c * blockSize;
                    const startY = r * blockSize;

                    for (let bitIdx = 0; bitIdx < fullStream.length; bitIdx++) {
                        const bit = fullStream[bitIdx];
                        const px = startX + ((bitIdx * 7 + 11) % (blockSize - 4)) + 2;
                        const py = startY + ((bitIdx * 13 + 5) % (blockSize - 4)) + 2;

                        if (px < width && py < height) {
                            const i = (py * width + px) * 4;
                            // En zonas claras de papel (R > 180, G > 180)
                            if (d[i] > 180 && d[i+1] > 180) {
                                if (bit === 1) {
                                    // Bit 1: Micro-punto en canal azul (imperceptible al ojo, invisible a la IA)
                                    d[i+2] = Math.max(0, d[i+2] - 22);
                                } else {
                                    // Bit 0: Modulación LSB en canal verde
                                    d[i+1] = (d[i+1] & ~1) | 1;
                                }
                            }
                        }
                    }
                }
            }

            ctx.putImageData(imgData, 0, 0);
        } catch (eStg) {}
    }

    // Ruido Adversarial Multi-Frecuencia
    function aplicarMicroRuidoAdversarialAntiIA(ctx, width, height) {
        if (!ctx) return;
        try {
            const imgData = ctx.getImageData(0, 0, width, height);
            const d = imgData.data;

            for (let y = 0; y < height; y += 3) {
                const yFactor = Math.cos(y * 0.42);
                const rowOffset = y * width;
                for (let x = 0; x < width; x += 3) {
                    const i = (rowOffset + x) * 4;
                    const r = d[i], g = d[i+1], b = d[i+2];

                    if (r > 40 || g > 40 || b > 40) {
                        const s1 = Math.sin(x * 0.42) * yFactor;
                        const s2 = Math.sin((x + y) * 0.85);
                        const advVal = Math.round((s1 * 3.8) + (s2 * 2.2));

                        d[i]   = Math.min(255, Math.max(0, r + advVal));
                        d[i+1] = Math.min(255, Math.max(0, g + advVal));
                        d[i+2] = Math.min(255, Math.max(0, b - advVal));
                    }
                }
            }
            ctx.putImageData(imgData, 0, 0);
        } catch (eNoise) {}
    }

    // =========================================================================
    // DECODIFICADOR FORENSE ANTI-IA PARA AUDITORÍA DE DOCUMENTOS FILTRADOS
    // =========================================================================
    function procesarArchivoForense(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                analizarImagenForense(img);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    function analizarImagenForense(img) {
        const resultadoBox = document.getElementById('resultadoForenseBox');
        if (!resultadoBox) return;

        resultadoBox.classList.remove('d-none');
        resultadoBox.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-warning mb-2" role="status"></div>
                <div class="text-light fw-bold">Escaneando matriz de micro-puntos y constelación esteganográfica...</div>
                <div class="text-secondary small">Decodificando firmas invisibles que sobreviven a la limpieza por IA...</div>
            </div>
        `;

        setTimeout(function() {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = img.width;
                canvas.height = img.height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);

                const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const d = imgData.data;

                // Extraer bitstream en bloques
                const blockSize = 64;
                const cols = Math.floor(canvas.width / blockSize);
                const rows = Math.floor(canvas.height / blockSize);

                const syncHeader = [1,0,1,0,1,0,1,0, 0,1,0,1,0,1,0,1];
                let payloadDetectado = null;

                for (let r = 0; r < rows && !payloadDetectado; r++) {
                    for (let c = 0; c < cols && !payloadDetectado; c++) {
                        const startX = c * blockSize;
                        const startY = r * blockSize;

                        // Intentar leer hasta 350 bits
                        const bitsLeidos = [];
                        for (let bitIdx = 0; bitIdx < 350; bitIdx++) {
                            const px = startX + ((bitIdx * 7 + 11) % (blockSize - 4)) + 2;
                            const py = startY + ((bitIdx * 13 + 5) % (blockSize - 4)) + 2;

                            if (px < canvas.width && py < canvas.height) {
                                const i = (py * canvas.width + px) * 4;
                                const diffAzul = (d[i] + d[i+1]) / 2 - d[i+2];
                                bitsLeidos.push(diffAzul > 10 ? 1 : 0);
                            } else {
                                bitsLeidos.push(0);
                            }
                        }

                        // Verificar si coincide con cabecera de sincronía
                        let matchSync = true;
                        for (let s = 0; s < syncHeader.length; s++) {
                            if (bitsLeidos[s] !== syncHeader[s]) {
                                matchSync = false;
                                break;
                            }
                        }

                        if (matchSync) {
                            // Decodificar caracteres ASCII de 8 bits
                            let str = '';
                            for (let b = syncHeader.length; b + 7 < bitsLeidos.length; b += 8) {
                                let charCode = 0;
                                for (let k = 0; k < 8; k++) {
                                    charCode = (charCode << 1) | bitsLeidos[b + k];
                                }
                                if (charCode >= 32 && charCode <= 126) {
                                    str += String.fromCharCode(charCode);
                                } else {
                                    break;
                                }
                            }
                            if (str.startsWith('GHSTG:')) {
                                payloadDetectado = str;
                            }
                        }
                    }
                }

                if (payloadDetectado) {
                    const partes = payloadDetectado.split(':');
                    const loginDetectado = partes[1] || 'Desconocido';
                    const agenciaDetectada = partes[2] || 'Agencia General';
                    const fechaDetectada = partes[3] || 'Reciente';
                    const folioDetectado = partes[4] || 'GH-POL';

                    resultadoBox.innerHTML = `
                        <div class="alert alert-success border-success bg-dark bg-opacity-75 text-white p-4 rounded-3 shadow-lg">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="fs-1 text-success"><i class="bi bi-shield-fill-check"></i></div>
                                <div>
                                    <h5 class="fw-bold text-success mb-1">¡TRAMPA FORENSE POSITIVA DETECTADA!</h5>
                                    <p class="mb-0 text-light small">Se identificó la constelación esteganográfica invisible inyectada en los píxeles originales, demostrando autoría incluso tras la alteración por IA.</p>
                                </div>
                            </div>
                            <div class="row g-2 font-monospace pt-2 border-top border-secondary border-opacity-50">
                                <div class="col-md-6">
                                    <div class="p-2 rounded bg-dark border border-secondary border-opacity-25">
                                        <span class="text-secondary small d-block">USUARIO / LOGIN:</span>
                                        <b class="text-warning fs-6">${loginDetectado}</b>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 rounded bg-dark border border-secondary border-opacity-25">
                                        <span class="text-secondary small d-block">AGENCIA DE ORIGEN:</span>
                                        <b class="text-info fs-6">${agenciaDetectada}</b>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 rounded bg-dark border border-secondary border-opacity-25">
                                        <span class="text-secondary small d-block">FECHA DE CONSULTA:</span>
                                        <b class="text-white fs-6">${fechaDetectada}</b>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 rounded bg-dark border border-secondary border-opacity-25">
                                        <span class="text-secondary small d-block">DOCUMENTO / FOLIO:</span>
                                        <b class="text-danger fs-6">${folioDetectado}</b>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    resultadoBox.innerHTML = `
                        <div class="alert alert-warning border-warning bg-dark text-white p-3 rounded-3">
                            <h6 class="fw-bold text-warning mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Firma esteganográfica no detectada en este recorte</h6>
                            <p class="mb-0 small text-secondary">La imagen subida no contiene suficientes bloques blancos originales de 64x64px, o la compresión fue excesiva. Prueba subiendo una captura con mayor resolución o área visible del documento.</p>
                        </div>
                    `;
                }

            } catch (errDec) {
                resultadoBox.innerHTML = `
                    <div class="alert alert-danger bg-dark text-white p-3 rounded-3">
                        <i class="bi bi-x-circle me-2"></i> Error procesando la imagen forense: ${errDec.message}
                    </div>
                `;
            }
        }, 600);
    }

        let currentAreaSeleccionada = 'todas';

    // 1. GESTIÓN DE NAVEGACIÓN ENTRE PESTAÑAS INTERNAS
    function seleccionarArea(nombreArea) {
        currentAreaSeleccionada = nombreArea;
        
        // Actualizar etiqueta del encabezado
        document.getElementById('lblAreaNombreActiva').textContent = 'Área: ' + nombreArea;
        document.getElementById('lblTituloPestanaDetalle').textContent = 'Políticas: ' + nombreArea;

        // Si existe el campo del modal de subida, preseleccionar este área
        const inputAreaModal = document.getElementById('inputModalArea');
        if (inputAreaModal) {
            inputAreaModal.value = nombreArea;
        }

        // Filtrar bloques de áreas visibles
        const bloquesArea = document.querySelectorAll('.bloque-area-contenedor');
        let contadorPoliticasArea = 0;

        bloquesArea.forEach(b => {
            if (b.getAttribute('data-area-nombre') === nombreArea) {
                b.style.display = 'block';
                const filas = b.querySelectorAll('.fila-politica-item');
                contadorPoliticasArea += filas.length;
                filas.forEach(f => f.style.display = 'flex');
            } else {
                b.style.display = 'none';
            }
        });

        document.getElementById('lblBadgeConteoDetalle').textContent = contadorPoliticasArea + (contadorPoliticasArea === 1 ? ' Política' : ' Políticas');
        document.getElementById('badgeTotalPoliticasPestana').textContent = contadorPoliticasArea;

        // Cambiar a la pestaña de detalle
        const tabDetalleBtn = document.getElementById('pill-detalle-tab');
        const tab = bootstrap.Tab.getOrCreateInstance(tabDetalleBtn);
        tab.show();

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function volverACatalogoAreas() {
        currentAreaSeleccionada = 'todas';
        document.getElementById('lblTituloPestanaDetalle').textContent = 'Listado de Políticas';
        document.getElementById('lblAreaNombreActiva').textContent = 'Todas las Áreas';
        
        // Restaurar visibilidad de todos los bloques
        document.querySelectorAll('.bloque-area-contenedor').forEach(b => b.style.display = 'block');
        document.querySelectorAll('.fila-politica-item').forEach(f => f.style.display = 'flex');

        const totalTodas = document.querySelectorAll('.fila-politica-item').length;
        document.getElementById('lblBadgeConteoDetalle').textContent = totalTodas + ' Políticas';
        document.getElementById('badgeTotalPoliticasPestana').textContent = totalTodas;

        const tabAreasBtn = document.getElementById('pill-areas-tab');
        const tab = bootstrap.Tab.getOrCreateInstance(tabAreasBtn);
        tab.show();
    }

    // =========================================================================
    // 2. BUSCADOR PROFUNDO: TÍTULO, SUBÁREA Y CONTENIDO COMPLETO DE CADA PDF
    // =========================================================================
    const indiceTextualPdfs = {}; // { [docId]: [ { page: 1, text: "..." }, ... ] }
    let isIndexacionPdfsIniciada = false;

    // Indexación en segundo plano no bloqueante usando PDF.js + Caché local
    async function indexarTodosLosPdfsEnSegundoPlano() {
        if (isIndexacionPdfsIniciada) return;
        isIndexacionPdfsIniciada = true;

        const filas = document.querySelectorAll('.fila-politica-item');
        if (!filas.length) return;

        const indBadge = document.getElementById('txtStatusIndexacion');
        if (indBadge) {
            indBadge.innerHTML = '<span class="spinner-border spinner-border-sm text-warning me-1" style="width:10px;height:10px;"></span>Indexando contenido de PDFs...';
        }

        for (const fila of filas) {
            const docId = fila.getAttribute('data-doc-id');
            const docVer = fila.getAttribute('data-doc-version') || '1.0';
            if (!docId) continue;

            const cacheKey = `gh_pdf_idx_v2_${docId}_${docVer}`;
            let paginasIndexadas = null;

            try {
                const cachedRaw = localStorage.getItem(cacheKey);
                if (cachedRaw) {
                    paginasIndexadas = JSON.parse(cachedRaw);
                }
            } catch (e) {}

            if (paginasIndexadas && Array.isArray(paginasIndexadas)) {
                indiceTextualPdfs[docId] = paginasIndexadas;
                aplicarIndiceAFila(fila, paginasIndexadas);
            } else {
                try {
                    const streamUrl = 'api_politicas.php?action=stream_pdf&id=' + encodeURIComponent(docId);
                    const pdf = await pdfjsLib.getDocument({ url: streamUrl, withCredentials: true }).promise;
                    const paginas = [];

                    for (let p = 1; p <= pdf.numPages; p++) {
                        const page = await pdf.getPage(p);
                        const textContent = await page.getTextContent();
                        const pageText = textContent.items.map(item => item.str).join(' ');
                        paginas.push({ page: p, text: pageText });
                    }

                    indiceTextualPdfs[docId] = paginas;
                    try {
                        localStorage.setItem(cacheKey, JSON.stringify(paginas));
                    } catch (eS) {}

                    aplicarIndiceAFila(fila, paginas);
                } catch (errPdf) {}
            }
        }

        if (indBadge) {
            indBadge.innerHTML = '<i class="bi bi-check2-all text-success me-1"></i>Búsqueda profunda en PDFs activa';
        }

        // Si el usuario ya tenía texto escrito, refrescar inmediatamente
        const queryActual = (document.getElementById('filtroTextoPolitica')?.value || '').trim();
        if (queryActual.length > 0) {
            filtrarPoliticasGlobal();
        }
    }

    function aplicarIndiceAFila(fila, paginas) {
        const textoCompletoPdf = paginas.map(p => p.text).join(' ').toLowerCase();
        const searchActual = fila.getAttribute('data-search') || '';
        if (!searchActual.includes(textoCompletoPdf.slice(0, 40))) {
            fila.setAttribute('data-search', searchActual + ' ' + textoCompletoPdf);
        }
    }

    // Iniciar indexación en segundo plano tras renderizado
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(indexarTodosLosPdfsEnSegundoPlano, 300));
    } else {
        setTimeout(indexarTodosLosPdfsEnSegundoPlano, 300);
    }

    // Filtrar en tiempo real por título, metadatos y contenido interno de PDF
    function filtrarPoliticasGlobal() {
        const input = document.getElementById('filtroTextoPolitica');
        const query = (input?.value || '').toLowerCase().trim();

        if (query.length > 0) {
            const tabDetalleBtn = document.getElementById('pill-detalle-tab');
            const tab = bootstrap.Tab.getOrCreateInstance(tabDetalleBtn);
            tab.show();

            document.querySelectorAll('.bloque-area-contenedor').forEach(b => b.style.display = 'block');

            let encontrados = 0;
            document.querySelectorAll('.bloque-subarea-item').forEach(subCard => {
                let subcardTieneMatches = false;
                const filas = subCard.querySelectorAll('.fila-politica-item');

                filas.forEach(fila => {
                    const docId = fila.getAttribute('data-doc-id');
                    const searchBase = fila.getAttribute('data-search') || '';
                    const snippetBox = document.getElementById(`snippetMatch_${docId}`);
                    const btnLeer = fila.querySelector('.btn-view-doc');

                    if (searchBase.includes(query)) {
                        fila.style.display = 'flex';
                        subcardTieneMatches = true;
                        encontrados++;

                        // Buscar coincidencia exacta dentro de las páginas del PDF
                        let matchInfo = null;
                        if (indiceTextualPdfs[docId]) {
                            for (const item of indiceTextualPdfs[docId]) {
                                const idx = item.text.toLowerCase().indexOf(query);
                                if (idx !== -1) {
                                    const start = Math.max(0, idx - 45);
                                    const end = Math.min(item.text.length, idx + query.length + 45);
                                    const snippetTxt = item.text.slice(start, end).replace(/\s+/g, ' ');
                                    const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                                    const highlighted = snippetTxt.replace(regex, '<mark class="bg-warning text-dark px-1 fw-bold rounded">$1</mark>');
                                    matchInfo = {
                                        page: item.page,
                                        highlighted: highlighted
                                    };
                                    break;
                                }
                            }
                        }

                        if (matchInfo && snippetBox) {
                            snippetBox.classList.remove('d-none');
                            snippetBox.innerHTML = `
                                <div class="p-2 rounded mt-1" style="background: rgba(212, 175, 55, 0.12); border-left: 3px solid var(--gold-accent); font-size: 0.76rem;">
                                    <span class="fw-bold" style="color: var(--gold-accent-light);"><i class="bi bi-file-earmark-text-fill me-1"></i>Coincidencia en Página ${matchInfo.page}:</span>
                                    <span class="text-white">...${matchInfo.highlighted}...</span>
                                </div>
                            `;
                            if (btnLeer) {
                                btnLeer.setAttribute('data-target-page', matchInfo.page);
                            }
                        } else if (snippetBox) {
                            snippetBox.classList.add('d-none');
                            snippetBox.innerHTML = '';
                            if (btnLeer) {
                                btnLeer.removeAttribute('data-target-page');
                            }
                        }

                    } else {
                        fila.style.display = 'none';
                        if (snippetBox) {
                            snippetBox.classList.add('d-none');
                            snippetBox.innerHTML = '';
                        }
                    }
                });

                subCard.style.display = subcardTieneMatches ? 'block' : 'none';
            });

            document.getElementById('lblBadgeConteoDetalle').textContent = encontrados + ' Encontradas';
        } else {
            // Limpiar snippets
            document.querySelectorAll('.snippet-busqueda-pdf').forEach(s => {
                s.classList.add('d-none');
                s.innerHTML = '';
            });

            if (currentAreaSeleccionada === 'todas') {
                volverACatalogoAreas();
            } else {
                seleccionarArea(currentAreaSeleccionada);
            }
        }
    }

    // Buscar palabra dentro del visor abierto
    let visorMatchPages = [];
    let visorCurrentMatchIdx = -1;

    async function buscarTextoEnVisorActual() {
        const query = (document.getElementById('visorBuscadorTexto')?.value || '').toLowerCase().trim();
        const badge = document.getElementById('lblVisorMatchCount');
        if (!currentPdfDoc || !query) {
            if (badge) badge.classList.add('d-none');
            return;
        }

        visorMatchPages = [];
        visorCurrentMatchIdx = -1;

        for (let p = 1; p <= currentPdfDoc.numPages; p++) {
            const page = await currentPdfDoc.getPage(p);
            const content = await page.getTextContent();
            const text = content.items.map(i => i.str).join(' ').toLowerCase();
            if (text.includes(query)) {
                visorMatchPages.push(p);
            }
        }

        if (badge) {
            badge.classList.remove('d-none');
            if (visorMatchPages.length > 0) {
                visorCurrentMatchIdx = 0;
                badge.className = 'badge bg-warning text-dark font-monospace small';
                badge.textContent = `${visorMatchPages.length} coincidencia(s)`;
                irAPaginaVisor(visorMatchPages[0]);
            } else {
                badge.className = 'badge bg-secondary text-white font-monospace small';
                badge.textContent = 'Sin coincidencias';
            }
        }
    }

    function irAPaginaVisor(pageNum) {
        const targetWrapper = document.getElementById(`page-slot-${pageNum}`) || document.getElementById(`pdfPageWrapper_${pageNum}`);
        const container = document.getElementById('visorCanvasContainer');
        if (targetWrapper && container) {
            targetWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const lblPag = document.getElementById('lblPaginaActual');
            if (lblPag) lblPag.textContent = pageNum;
        }
    }

    <?php if ($esAdmin): ?>
    // =========================================================================
    // 3. CONSULTAR BITÁCORA AUDITABLE DE LECTURAS, FILTROS Y BÚSQUEDA (ADMIN)
    // =========================================================================
    let bitacoraLecturasGlobal = [];
    let activeBitacoraLecturaId = null;

    function abrirModalAuditoriaLecturas() {
        const modalEl = document.getElementById('modalAuditoriaLecturas');
        if (!modalEl) return;

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        // Registrar ingreso a la bitácora
        if (!activeBitacoraLecturaId) {
            try {
                const formData = new FormData();
                formData.append('action', 'registrar_lectura');
                formData.append('politica_id', '0');
                formData.append('politica_titulo', 'Inspección de Bitácora Forense');
                formData.append('usuario_nombre', FORENSIC_USER_NAME);
                formData.append('usuario_login', FORENSIC_USER_LOGIN);
                formData.append('agencia', FORENSIC_AGENCIA);
                formData.append('ip', FORENSIC_IP);
                fetch('api_politicas.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(d => {
                        if (d.exito && d.lectura_id) {
                            activeBitacoraLecturaId = d.lectura_id;
                        }
                    })
                    .catch(() => {});
            } catch(e) {}
        }

        cargarDatosBitacora();
        cargarTerminosAceptadosCentral();
    }

    // Al cerrar el modal de bitácora, finalizar registro
    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('modalAuditoriaLecturas');
        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function() {
                if (activeBitacoraLecturaId) {
                    finalizarLecturaAuditoria(activeBitacoraLecturaId);
                    activeBitacoraLecturaId = null;
                }
            });
        }
    });

    function cargarDatosBitacora() {
        const tbody = document.getElementById('tbodyAuditoriaLecturas');
        if (!tbody) return;

        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-secondary">
                    <div class="spinner-border spinner-border-sm me-2" style="color: var(--gold-accent);"></div>
                    Cargando bitácora de auditoría en tiempo real...
                </td>
            </tr>
        `;

        fetch('api_politicas.php?action=obtener_lecturas')
            .then(res => res.json())
            .then(data => {
                if (!data.exito) {
                    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">${data.error || 'No se pudo cargar la auditoría'}</td></tr>`;
                    return;
                }

                if (data.stats) {
                    document.getElementById('statTotalLecturas').textContent = data.stats.total_lecturas || 0;
                    document.getElementById('statUsuariosUnicos').textContent = data.stats.usuarios_unicos || 0;
                    document.getElementById('statAgenciasActivas').textContent = data.stats.agencias_activas || 0;
                    const promSeg = parseInt(data.stats.promedio_duracion_seg, 10) || 0;
                    document.getElementById('statTiempoPromedio').textContent = promSeg > 0 ? formatearDuracion(promSeg) : '--';
                }

                bitacoraLecturasGlobal = data.lecturas || [];
                poblarSelectoresFiltro();
                filtrarBitacora();
            })
            .catch(err => {
                tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">Error de conexión al obtener bitácora.</td></tr>`;
            });
    }

    function poblarSelectoresFiltro() {
        const selAgencia = document.getElementById('filtroBitacoraAgencia');
        const selPolitica = document.getElementById('filtroBitacoraPolitica');
        if (!selAgencia || !selPolitica) return;

        const valAgenciaActual = selAgencia.value;
        const valPolActual = selPolitica.value;

        const agenciasSet = new Set();
        const politicasSet = new Set();

        bitacoraLecturasGlobal.forEach(l => {
            if (l.agencia) agenciasSet.add(l.agencia.trim());
            if (l.politica_titulo) politicasSet.add(l.politica_titulo.trim());
        });

        // Agencias
        selAgencia.innerHTML = '<option value="">Todas las agencias</option>';
        Array.from(agenciasSet).sort().forEach(ag => {
            const opt = document.createElement('option');
            opt.value = ag;
            opt.textContent = ag;
            selAgencia.appendChild(opt);
        });
        selAgencia.value = valAgenciaActual;

        // Políticas
        selPolitica.innerHTML = '<option value="">Todas las políticas / actividades</option>';
        Array.from(politicasSet).sort().forEach(pol => {
            const opt = document.createElement('option');
            opt.value = pol;
            opt.textContent = pol;
            selPolitica.appendChild(opt);
        });
        selPolitica.value = valPolActual;
    }

    function formatearDuracion(segundos) {
        const seg = parseInt(segundos, 10) || 0;
        if (seg <= 0) return '--';
        if (seg < 60) return seg + 's';
        const mins = Math.floor(seg / 60);
        const remSeg = seg % 60;
        if (mins < 60) {
            return `${mins}m ${remSeg > 0 ? remSeg + 's' : ''}`;
        }
        const hrs = Math.floor(mins / 60);
        const remMins = mins % 60;
        return `${hrs}h ${remMins}m`;
    }

    function formatearFechaHora(strFecha) {
        if (!strFecha) return { fecha: '', hora: '' };
        // Formato esperado: YYYY-MM-DD HH:MM:SS
        const partes = strFecha.split(' ');
        if (partes.length === 2) {
            return { fecha: partes[0], hora: partes[1] };
        }
        return { fecha: strFecha, hora: '' };
    }

    function filtrarBitacora() {
        const txt = (document.getElementById('filtroBitacoraTexto')?.value || '').toLowerCase().trim();
        const agenciaFiltro = document.getElementById('filtroBitacoraAgencia')?.value || '';
        const polFiltro = document.getElementById('filtroBitacoraPolitica')?.value || '';
        const periodoFiltro = document.getElementById('filtroBitacoraPeriodo')?.value || 'todos';
        const badgeFiltroActivo = document.getElementById('lblFiltroActivoBadge');
        const tbody = document.getElementById('tbodyAuditoriaLecturas');
        const lblContador = document.getElementById('lblContadorFiltrados');

        const hayFiltrosActivos = (txt !== '' || agenciaFiltro !== '' || polFiltro !== '' || periodoFiltro !== 'todos');
        if (badgeFiltroActivo) {
            badgeFiltroActivo.classList.toggle('d-none', !hayFiltrosActivos);
        }

        const ahora = new Date();
        const hoyIso = ahora.toISOString().slice(0, 10);
        const ayer = new Date(ahora.getTime() - 24 * 60 * 60 * 1000);
        const ayerIso = ayer.toISOString().slice(0, 10);
        const sieteDiasAtras = new Date(ahora.getTime() - 7 * 24 * 60 * 60 * 1000);

        const filtrados = bitacoraLecturasGlobal.filter(l => {
            // Filtro texto
            if (txt) {
                const cadena = [
                    l.politica_titulo,
                    l.usuario_nombre,
                    l.usuario_login,
                    l.agencia,
                    l.ip,
                    l.origen,
                    l.fecha_lectura,
                    l.fecha_fin
                ].join(' ').toLowerCase();
                if (!cadena.includes(txt)) return false;
            }

            // Filtro agencia
            if (agenciaFiltro && (l.agencia || '').trim() !== agenciaFiltro) return false;

            // Filtro política
            if (polFiltro && (l.politica_titulo || '').trim() !== polFiltro) return false;

            // Filtro periodo / estado
            if (periodoFiltro === 'hoy') {
                if (!l.fecha_lectura || !l.fecha_lectura.startsWith(hoyIso)) return false;
            } else if (periodoFiltro === 'ayer') {
                if (!l.fecha_lectura || !l.fecha_lectura.startsWith(ayerIso)) return false;
            } else if (periodoFiltro === '7dias') {
                if (!l.fecha_lectura) return false;
                const f = new Date(l.fecha_lectura.replace(' ', 'T'));
                if (isNaN(f.getTime()) || f < sieteDiasAtras) return false;
            } else if (periodoFiltro === 'activas') {
                if (l.fecha_fin) return false;
            } else if (periodoFiltro === 'concluidas') {
                if (!l.fecha_fin) return false;
            }

            return true;
        });

        if (lblContador) {
            lblContador.textContent = `Mostrando ${filtrados.length} de ${bitacoraLecturasGlobal.length} registros`;
        }

        if (filtrados.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5 text-secondary">
                        <i class="bi bi-search fs-2 d-block mb-2 text-warning opacity-75"></i>
                        No se encontraron registros que coincidan con los filtros aplicados.
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        filtrados.forEach(l => {
            const ingr = formatearFechaHora(l.fecha_lectura);
            const sal = formatearFechaHora(l.fecha_fin);

            let celdaSalida = '';
            let celdaPermanencia = '';

            if (l.fecha_fin) {
                celdaSalida = `
                    <div>
                        <span class="font-monospace fw-bold" style="color: var(--gold-accent-light);">
                            <i class="bi bi-box-arrow-right text-warning me-1"></i>${escapeHtml(sal.hora || l.fecha_fin)}
                        </span>
                        <div class="text-secondary small font-monospace">${escapeHtml(sal.fecha)}</div>
                    </div>
                `;
                celdaPermanencia = `
                    <span class="badge rounded-pill bg-dark border font-monospace" style="border-color: var(--gold-border) !important; color: var(--gold-accent-light);">
                        <i class="bi bi-stopwatch text-warning me-1"></i>${formatearDuracion(l.duracion_segundos)}
                    </span>
                `;
            } else {
                // Cálculo si es lectura reciente o no cerrada
                let esReciente = false;
                if (l.fecha_lectura) {
                    const fIngreso = new Date(l.fecha_lectura.replace(' ', 'T'));
                    if (!isNaN(fIngreso.getTime())) {
                        const difMins = (ahora.getTime() - fIngreso.getTime()) / (1000 * 60);
                        if (difMins <= 30) esReciente = true;
                    }
                }

                if (esReciente) {
                    celdaSalida = `
                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50">
                            <span class="spinner-grow spinner-grow-sm me-1" style="width: 7px; height: 7px;"></span>En visualización
                        </span>
                    `;
                    celdaPermanencia = `
                        <span class="text-success small font-monospace">
                            <i class="bi bi-hourglass-split me-1"></i>En curso...
                        </span>
                    `;
                } else {
                    celdaSalida = `
                        <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25">
                            Cierre no registrado
                        </span>
                    `;
                    celdaPermanencia = `<span class="text-secondary small font-monospace">--</span>`;
                }
            }

            const esInspeccionBitacora = (parseInt(l.politica_id, 10) === 0 || (l.politica_titulo || '').includes('Bitácora'));
            const badgeTipo = esInspeccionBitacora 
                ? `<span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-50 mb-1 d-inline-block small"><i class="bi bi-shield-check me-1"></i>Auditoría Forense</span>`
                : `<span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-50 mb-1 d-inline-block small"><i class="bi bi-file-earmark-lock2 me-1"></i>Normativa Institucional</span>`;

            html += `
                <tr>
                    <td>
                        <div>
                            <span class="font-monospace text-white fw-bold">
                                <i class="bi bi-box-arrow-in-right text-success me-1"></i>${escapeHtml(ingr.hora || l.fecha_lectura)}
                            </span>
                            <div class="text-secondary small font-monospace">${escapeHtml(ingr.fecha)}</div>
                        </div>
                    </td>
                    <td>${celdaSalida}</td>
                    <td>${celdaPermanencia}</td>
                    <td>
                        ${badgeTipo}
                        <div class="fw-bold text-white">${escapeHtml(l.politica_titulo)}</div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1.5">
                            <i class="bi bi-person-fill text-info"></i>
                            <div>
                                <span class="fw-semibold text-white">${escapeHtml(l.usuario_nombre)}</span>
                                <div class="text-secondary small font-monospace">(${escapeHtml(l.usuario_login)})</div>
                                ${l.fecha_terminos_aceptados 
                                    ? `<span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 mt-1 d-inline-block font-monospace" style="font-size: 0.68rem;" title="Firmó aviso el ${escapeHtml(l.fecha_terminos_aceptados)}"><i class="bi bi-patch-check-fill me-1"></i>Aviso Aceptado</span>`
                                    : `<span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50 mt-1 d-inline-block font-monospace" style="font-size: 0.68rem;"><i class="bi bi-clock-history me-1"></i>Aviso Pendiente</span>`}
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-dark border" style="border-color: var(--gold-border) !important; color: var(--gold-accent-light);">
                            ${escapeHtml(l.agencia)}
                        </span>
                    </td>
                    <td class="font-monospace small text-secondary">${escapeHtml(l.ip)}</td>
                    <td>
                        <span class="badge bg-secondary bg-opacity-25 text-light">${escapeHtml(l.origen)}</span>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    function limpiarFiltroBitacora() {
        const inp = document.getElementById('filtroBitacoraTexto');
        const selAg = document.getElementById('filtroBitacoraAgencia');
        const selPol = document.getElementById('filtroBitacoraPolitica');
        const selPer = document.getElementById('filtroBitacoraPeriodo');
        if (inp) inp.value = '';
        if (selAg) selAg.value = '';
        if (selPol) selPol.value = '';
        if (selPer) selPer.value = 'todos';
        filtrarBitacora();
    }

    
    // =========================================================================
    // GESTIÓN Y AUDITORÍA DE AVISOS DE PRIVACIDAD Y TÉRMINOS ACEPTADOS (CENTRAL GH)
    // =========================================================================
    let terminosAceptadosGlobal = [];

    function cargarTerminosAceptadosCentral() {
        const tbody = document.getElementById('tbodyTerminosAceptados');
        if (!tbody) return;

        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-4 text-secondary">
                    <div class="spinner-border spinner-border-sm me-2 text-success"></div>
                    Cargando avisos de privacidad y términos firmados...
                </td>
            </tr>
        `;

        fetch('api_politicas.php?action=obtener_terminos_aceptados')
            .then(res => res.json())
            .then(data => {
                if (!data.exito) {
                    tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">${data.error || 'Error al obtener avisos'}</td></tr>`;
                    return;
                }

                terminosAceptadosGlobal = data.terminos || [];

                // Actualizar stats
                const total = (data.stats && data.stats.total_aceptados) ? data.stats.total_aceptados : terminosAceptadosGlobal.length;
                const agencias = (data.stats && data.stats.agencias_participantes) ? data.stats.agencias_participantes : new Set(terminosAceptadosGlobal.map(t => t.agencia)).size;
                const ultima = (data.stats && data.stats.ultima_aceptacion) ? data.stats.ultima_aceptacion : (terminosAceptadosGlobal[0]?.fecha_aceptacion || '--');

                const elTotal = document.getElementById('statTerminosTotal');
                const elAgencias = document.getElementById('statTerminosAgencias');
                const elUltima = document.getElementById('statTerminosUltima');
                const elBadgeNav = document.getElementById('badgeTotalTerminosNav');

                if (elTotal) elTotal.textContent = total;
                if (elBadgeNav) elBadgeNav.textContent = total;
                if (elAgencias) elAgencias.textContent = agencias;
                if (elUltima) elUltima.textContent = ultima;

                // Poblar filtro de agencias
                poblarSelectAgenciasTerminos();
                filtrarTablaTerminos();
            })
            .catch(err => {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">Error de conexión: ${err.message}</td></tr>`;
            });
    }

    function poblarSelectAgenciasTerminos() {
        const sel = document.getElementById('filtroTerminosAgencia');
        if (!sel) return;
        const valActual = sel.value;
        const agenciasSet = new Set();
        terminosAceptadosGlobal.forEach(t => {
            if (t.agencia) agenciasSet.add(t.agencia.trim());
        });

        sel.innerHTML = '<option value="">Todas las agencias</option>';
        Array.from(agenciasSet).sort().forEach(ag => {
            const opt = document.createElement('option');
            opt.value = ag;
            opt.textContent = ag;
            sel.appendChild(opt);
        });
        sel.value = valActual;
    }

    function filtrarTablaTerminos() {
        const txt = (document.getElementById('filtroTerminosTexto')?.value || '').toLowerCase().trim();
        const agFiltro = document.getElementById('filtroTerminosAgencia')?.value || '';
        const tbody = document.getElementById('tbodyTerminosAceptados');
        const lblContador = document.getElementById('lblContadorTerminos');
        if (!tbody) return;

        const filtrados = terminosAceptadosGlobal.filter(t => {
            if (txt) {
                const cadena = [
                    t.usuario_nombre,
                    t.usuario_login,
                    t.agencia,
                    t.ip,
                    t.origen,
                    t.version_terminos,
                    t.fecha_aceptacion
                ].join(' ').toLowerCase();
                if (!cadena.includes(txt)) return false;
            }
            if (agFiltro && (t.agencia || '').trim() !== agFiltro) return false;
            return true;
        });

        if (lblContador) {
            lblContador.textContent = `Mostrando ${filtrados.length} de ${terminosAceptadosGlobal.length} registros`;
        }

        if (filtrados.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-secondary">
                        <i class="bi bi-search fs-2 d-block mb-2 text-warning opacity-75"></i>
                        No se encontraron colaboradores que coincidan con la búsqueda.
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        filtrados.forEach(t => {
            const fh = formatearFechaHora(t.fecha_aceptacion);
            html += `
                <tr>
                    <td>
                        <div>
                            <span class="font-monospace text-white fw-bold">
                                <i class="bi bi-calendar-check text-success me-1"></i>${escapeHtml(fh.hora || t.fecha_aceptacion)}
                            </span>
                            <div class="text-secondary small font-monospace">${escapeHtml(fh.fecha)}</div>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1.5">
                            <i class="bi bi-person-check-fill text-success fs-5"></i>
                            <div>
                                <span class="fw-semibold text-white">${escapeHtml(t.usuario_nombre)}</span>
                                <div class="text-secondary small font-monospace">ID / Login: ${escapeHtml(t.usuario_login)}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-dark border" style="border-color: var(--gold-border) !important; color: var(--gold-accent-light);">
                            ${escapeHtml(t.agencia)}
                        </span>
                    </td>
                    <td class="font-monospace small text-secondary">${escapeHtml(t.ip)}</td>
                    <td>
                        <span class="badge bg-secondary bg-opacity-25 text-light">${escapeHtml(t.origen || 'portal')}</span>
                    </td>
                    <td class="font-monospace small text-info">v${escapeHtml(t.version_terminos || '1.0')}</td>
                    <td>
                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-2 py-1">
                            <i class="bi bi-patch-check-fill me-1"></i>Firmado
                        </span>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    function exportarTerminosCSV() {
        if (!terminosAceptadosGlobal || terminosAceptadosGlobal.length === 0) {
            alert('No hay registros de avisos firmados para exportar.');
            return;
        }

        const encabezados = ['ID', 'Colaborador', 'Usuario Login', 'Agencia', 'Dirección IP', 'Origen', 'Versión', 'Fecha y Hora Firma', 'User Agent'];
        const lineas = [encabezados.join(',')];

        terminosAceptadosGlobal.forEach(t => {
            const fila = [
                t.id || '',
                `"${(t.usuario_nombre || '').replace(/"/g, '""')}"`,
                `"${(t.usuario_login || '').replace(/"/g, '""')}"`,
                `"${(t.agencia || '').replace(/"/g, '""')}"`,
                `"${(t.ip || '').replace(/"/g, '""')}"`,
                `"${(t.origen || '').replace(/"/g, '""')}"`,
                `"v${(t.version_terminos || '1.0').replace(/"/g, '""')}"`,
                `"${(t.fecha_aceptacion || '').replace(/"/g, '""')}"`,
                `"${(t.user_agent || '').replace(/"/g, '""')}"`
            ];
            lineas.push(fila.join(','));
        });

        const csvContent = '﻿' + lineas.join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const fechaHoy = new Date().toISOString().slice(0, 10);
        a.href = url;
        a.download = `Avisos_Privacidad_Terminos_GH_${fechaHoy}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function exportarBitacoraCSV() {
        if (!bitacoraLecturasGlobal || bitacoraLecturasGlobal.length === 0) {
            alert('No hay registros en la bitácora para exportar.');
            return;
        }

        const encabezados = [
            'ID',
            'Fecha Ingreso',
            'Hora Ingreso',
            'Fecha Salida',
            'Hora Salida',
            'Duracion Segundos',
            'Duracion Formateada',
            'Documento / Actividad',
            'Colaborador',
            'Usuario Login',
            'Agencia',
            'Direccion IP',
            'Origen'
        ];

        const filas = [encabezados.join(',')];

        bitacoraLecturasGlobal.forEach(l => {
            const ingr = formatearFechaHora(l.fecha_lectura);
            const sal = formatearFechaHora(l.fecha_fin);
            const durFormateada = formatearDuracion(l.duracion_segundos);

            const valores = [
                l.id || '',
                `"${ingr.fecha || ''}"`,
                `"${ingr.hora || ''}"`,
                `"${sal.fecha || ''}"`,
                `"${sal.hora || ''}"`,
                l.duracion_segundos || 0,
                `"${durFormateada}"`,
                `"${(l.politica_titulo || '').replace(/"/g, '""')}"`,
                `"${(l.usuario_nombre || '').replace(/"/g, '""')}"`,
                `"${(l.usuario_login || '').replace(/"/g, '""')}"`,
                `"${(l.agencia || '').replace(/"/g, '""')}"`,
                `"${(l.ip || '').replace(/"/g, '""')}"`,
                `"${(l.origen || '').replace(/"/g, '""')}"`
            ];
            filas.push(valores.join(','));
        });

        const csvContent = '\uFEFF' + filas.join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const nowStr = new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '_');
        a.href = url;
        a.download = `bitacora_auditoria_politicas_${nowStr}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }
    <?php endif; ?>

    // =========================================================================
    // VISOR BLINDADO Y PROTECCIÓN DLP POR CURSOR
    // =========================================================================
    let currentPdfDoc = null;
    let currentZoom = 1.0;
    let totalPdfPages = 0;
    let isViewerActive = false;
    let isPdfRenderCompleted = false;
    let isCursorOverDocument = false;
    let ultimoMouseX = null;
    let ultimoMouseY = null;
    let activeDocLecturaId = null;

    function actualizarEstadoBloqueoPdf(bloquear) {
        if (!isViewerActive || !isPdfRenderCompleted) return;

        const overlay = document.getElementById('pdfLockOverlay');
        const container = document.getElementById('visorCanvasContainer');
        if (!overlay || !container) return;

        if (bloquear) {
            overlay.classList.add('activo');
            container.classList.add('visor-bloqueado');
        } else {
            overlay.classList.remove('activo');
            container.classList.remove('visor-bloqueado');
        }
    }

    function verificarCursorSobrePdf(clientX, clientY) {
        ultimoMouseX = clientX;
        ultimoMouseY = clientY;

        if (!isViewerActive || !isPdfRenderCompleted) return;

        const wrappers = document.querySelectorAll('.pdf-page-wrapper');
        if (!wrappers || wrappers.length === 0) return;

        const elUnderCursor = document.elementFromPoint(clientX, clientY);
        if (elUnderCursor && (elUnderCursor.classList.contains('pdf-page-wrapper') || elUnderCursor.closest('.pdf-page-wrapper'))) {
            isCursorOverDocument = true;
            actualizarEstadoBloqueoPdf(false);
            return;
        }

        let dentroDeColumna = false;
        for (let i = 0; i < wrappers.length; i++) {
            const rect = wrappers[i].getBoundingClientRect();
            if (clientX >= (rect.left - 20) && clientX <= (rect.right + 20)) {
                if (clientY >= (rect.top - 25) && clientY <= (rect.bottom + 25)) {
                    dentroDeColumna = true;
                    break;
                }
            }
        }

        if (dentroDeColumna) {
            isCursorOverDocument = true;
            actualizarEstadoBloqueoPdf(false);
        } else {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    }

    window.addEventListener('mousemove', function(e) {
        if (!isViewerActive) return;
        verificarCursorSobrePdf(e.clientX, e.clientY);
    }, { passive: true });

    document.addEventListener('mouseleave', function() {
        if (isViewerActive && isPdfRenderCompleted) {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    });

    window.addEventListener('blur', function() {
        if (isViewerActive && isPdfRenderCompleted) {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    });

    document.addEventListener('visibilitychange', function() {
        if (document.hidden && isViewerActive && isPdfRenderCompleted) {
            isCursorOverDocument = false;
            actualizarEstadoBloqueoPdf(true);
        }
    });

    window.addEventListener('touchmove', function(e) {
        if (!isViewerActive) return;
        if (e.touches && e.touches.length > 0) {
            verificarCursorSobrePdf(e.touches[0].clientX, e.touches[0].clientY);
        }
    }, { passive: true });

    window.addEventListener('touchstart', function(e) {
        if (!isViewerActive) return;
        if (e.touches && e.touches.length > 0) {
            verificarCursorSobrePdf(e.touches[0].clientX, e.touches[0].clientY);
        }
    }, { passive: true });

    // Protección de atajos de teclado
    window.addEventListener('keydown', function(e) {
        if (!isViewerActive) return;

        if (e.key === 'PrintScreen' || e.keyCode === 44 || e.key === 'Snapshot' || (e.shiftKey && (e.key === 's' || e.key === 'S'))) {
            e.preventDefault();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText('');
            }
            return false;
        }

        if (e.ctrlKey && (e.key === 'p' || e.key === 'P' || e.key === 's' || e.key === 'S' || e.key === 'u' || e.key === 'U')) {
            e.preventDefault();
            return false;
        }

        if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j'))) {
            e.preventDefault();
            return false;
        }
    }, true);

    function abrirVisorBlindado(docId, tituloDoc, folioDoc) {
        const modal = document.getElementById('visorModalOverlay');
        const container = document.getElementById('visorCanvasContainer');
        const tituloLbl = document.getElementById('visorTituloDoc');
        const folioLbl = document.getElementById('visorFolioDoc');

        if (!modal || !container) return;

        tituloLbl.textContent = tituloDoc;
        if (folioLbl && folioDoc) {
            folioLbl.textContent = folioDoc;
        }
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        isViewerActive = true;
        isPdfRenderCompleted = false;
        isCursorOverDocument = false;
        const lockOv = document.getElementById('pdfLockOverlay');
        if (lockOv) lockOv.classList.remove('activo');
        container.classList.remove('visor-bloqueado');

        registrarLecturaAuditoria(docId, tituloDoc);

        container.innerHTML = `
            <div id="visorLoader" class="text-center py-5">
                <div class="spinner-border mb-3" role="status" style="width: 3rem; height: 3rem; color: var(--gold-accent);"></div>
                <h5 class="fw-bold text-white">Desencriptando y renderizando documento protegido...</h5>
                <p class="text-secondary small">Aplicando marcas de agua forenses y trama óptica anti-captura.</p>
            </div>
        `;

        const pdfUrl = 'api_politicas.php?action=stream_pdf&id=' + encodeURIComponent(docId);
        currentZoom = (window.innerWidth < 768) ? 0.9 : 1.25;
        document.getElementById('visorZoomBadge').textContent = Math.round(currentZoom * 100) + '%';

        pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true }).promise.then(function(pdf) {
            currentPdfDoc = pdf;
            totalPdfPages = pdf.numPages;
            document.getElementById('lblTotalPaginas').textContent = totalPdfPages;

            container.innerHTML = '';
            renderizarTodasLasPaginas(pdf, container);

        }).catch(function(error) {
            container.innerHTML = `
                <div class="alert alert-danger my-5 p-4 rounded-4 text-center">
                    <i class="bi bi-exclamation-triangle-fill fs-1 d-block mb-2"></i>
                    <h5 class="fw-bold">Error al abrir el documento</h5>
                    <p class="mb-0 small">${error.message || 'El documento no está disponible o no tienes permisos suficientes.'}</p>
                </div>
            `;
        });
    }

    function renderizarTodasLasPaginas(pdf, container) {
        container.innerHTML = '';
        const wrappers = [];
        let paginasRenderizadas = 0;

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const pageWrapper = document.createElement('div');
            pageWrapper.className = 'pdf-page-wrapper';
            pageWrapper.id = 'page-slot-' + pageNum;
            pageWrapper.style.flexShrink = '0';
            pageWrapper.innerHTML = `
                <div class="text-center py-5 text-secondary" style="min-height: 250px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div class="spinner-border spinner-border-sm mb-2" style="color: var(--gold-accent);"></div>
                    <span style="font-size: 0.8rem;">Cargando página ${pageNum} de ${pdf.numPages}...</span>
                </div>
            `;
            container.appendChild(pageWrapper);
            wrappers[pageNum] = pageWrapper;
        }

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            (function(num, wrapper) {
                pdf.getPage(num).then(function(page) {
                    const viewport = page.getViewport({ scale: currentZoom });
                    const w = Math.round(viewport.width);
                    const h = Math.round(viewport.height);

                    wrapper.style.width = w + 'px';
                    wrapper.style.height = h + 'px';
                    wrapper.style.minHeight = h + 'px';
                    wrapper.style.maxHeight = h + 'px';
                    wrapper.style.flexShrink = '0';
                    wrapper.innerHTML = '';

                    const canvas = document.createElement('canvas');
                    canvas.className = 'pdf-page-canvas';
                    canvas.width = w;
                    canvas.height = h;
                    canvas.style.width = w + 'px';
                    canvas.style.height = h + 'px';
                    const ctx = canvas.getContext('2d');

                    page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function() {
                        // 1. Blindaje Anti-IA directo sobre los píxeles del Canvas
                        aplicarMarcasAntiIADirectoEnCanvas(ctx, w, h, FORENSIC_USER_NAME, FORENSIC_USER_LOGIN, FORENSIC_AGENCIA, currentFolioDoc);
                        aplicarMicroRuidoAdversarialAntiIA(ctx, w, h);

                        // 2. Capas visuales DOM de textura de seguridad (sin sellos duplicados invasivos)
                        const securityPattern = document.createElement('div');
                        securityPattern.className = 'banknote-security-pattern';
                        wrapper.appendChild(securityPattern);

                        const centralLogo = document.createElement('div');
                        centralLogo.className = 'central-gh-watermark';
                        centralLogo.innerHTML = '<img src="uploads/logo_gh.png" alt="Grupo Huerta">';
                        wrapper.appendChild(centralLogo);

                        paginasRenderizadas++;
                        if (paginasRenderizadas === pdf.numPages) {
                            isPdfRenderCompleted = true;
                            setTimeout(function() {
                                if (targetPageParaScroll) {
                                    irAPaginaVisor(targetPageParaScroll);
                                    targetPageParaScroll = null;
                                }
                                if (ultimoMouseX !== null && ultimoMouseY !== null) {
                                    verificarCursorSobrePdf(ultimoMouseX, ultimoMouseY);
                                } else {
                                    actualizarEstadoBloqueoPdf(true);
                                }
                            }, 140);
                        }
                    });

                    wrapper.appendChild(canvas);
                });
            })(pageNum, wrappers[pageNum]);
        }
    }

    function cambiarZoomVisor(delta) {
        if (!currentPdfDoc) return;
        currentZoom = Math.max(0.5, Math.min(2.5, currentZoom + delta));
        document.getElementById('visorZoomBadge').textContent = Math.round(currentZoom * 100) + '%';
        const container = document.getElementById('visorCanvasContainer');
        container.innerHTML = '';
        isPdfRenderCompleted = false;
        const lockOv = document.getElementById('pdfLockOverlay');
        if (lockOv) lockOv.classList.remove('activo');
        container.classList.remove('visor-bloqueado');
        renderizarTodasLasPaginas(currentPdfDoc, container);
    }

    function cerrarVisorBlindado() {
        if (activeDocLecturaId) {
            finalizarLecturaAuditoria(activeDocLecturaId);
            activeDocLecturaId = null;
        }
        const modal = document.getElementById('visorModalOverlay');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
        isViewerActive = false;
        isPdfRenderCompleted = false;
        isCursorOverDocument = false;
        const lockOv = document.getElementById('pdfLockOverlay');
        if (lockOv) lockOv.classList.remove('activo');
        const container = document.getElementById('visorCanvasContainer');
        if (container) container.classList.remove('visor-bloqueado');
        currentPdfDoc = null;
    }

    const containerCanvasEl = document.getElementById('visorCanvasContainer');
    if (containerCanvasEl) {
        containerCanvasEl.addEventListener('scroll', function() {
            if (isViewerActive && isPdfRenderCompleted && ultimoMouseX !== null && ultimoMouseY !== null) {
                verificarCursorSobrePdf(ultimoMouseX, ultimoMouseY);
            }
        }, { passive: true });
    }

    function registrarLecturaAuditoria(docId, tituloDoc) {
        try {
            const formData = new FormData();
            formData.append('action', 'registrar_lectura');
            formData.append('politica_id', docId);
            formData.append('politica_titulo', tituloDoc);
            formData.append('usuario_nombre', FORENSIC_USER_NAME);
            formData.append('usuario_login', FORENSIC_USER_LOGIN);
            formData.append('agencia', FORENSIC_AGENCIA);
            formData.append('ip', FORENSIC_IP);

            fetch('api_politicas.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.exito && data.lectura_id) {
                    activeDocLecturaId = data.lectura_id;
                }
            })
            .catch(e => console.warn('Audit log notice:', e));
        } catch(err) {}
    }

    function finalizarLecturaAuditoria(lecturaId) {
        if (!lecturaId) return;
        try {
            const formData = new FormData();
            formData.append('action', 'finalizar_lectura');
            formData.append('lectura_id', lecturaId);
            if (navigator.sendBeacon) {
                navigator.sendBeacon('api_politicas.php?action=finalizar_lectura&lectura_id=' + encodeURIComponent(lecturaId));
            } else {
                fetch('api_politicas.php', {
                    method: 'POST',
                    body: formData,
                    keepalive: true
                }).catch(() => {});
            }
        } catch(err) {}
    }

    window.addEventListener('pagehide', function() {
        if (activeDocLecturaId) {
            finalizarLecturaAuditoria(activeDocLecturaId);
            activeDocLecturaId = null;
        }
        if (typeof activeBitacoraLecturaId !== 'undefined' && activeBitacoraLecturaId) {
            finalizarLecturaAuditoria(activeBitacoraLecturaId);
            activeBitacoraLecturaId = null;
        }
    });
</script>
</body>
</html>
