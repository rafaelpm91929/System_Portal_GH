<?php
// Polyfills de compatibilidad para PHP 7 en cPanel
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || $needle === substr($haystack, -strlen($needle));
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$agenciaSesion = $_SESSION['agencia'] ?? '';

// 1. OBTENER DATOS OFICIALES DE LA AGENCIA DESDE LA BASE DE DATOS
$agenciaData = null;
if ($pdo) {
    try {
        if (!empty($agenciaSesion)) {
            $stmtA = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmtA->execute([$agenciaSesion]);
            $agenciaData = $stmtA->fetch(PDO::FETCH_ASSOC);

            if (!$agenciaData) {
                $stmtA = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) LIKE LOWER(?) OR LOWER(?) LIKE '%' || LOWER(nombre) || '%' LIMIT 1");
                $stmtA->execute(['%' . $agenciaSesion . '%', $agenciaSesion]);
                $agenciaData = $stmtA->fetch(PDO::FETCH_ASSOC);
            }
        }
        if (!$agenciaData) {
            $stmtA3 = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
            $agenciaData = $stmtA3 ? $stmtA3->fetch(PDO::FETCH_ASSOC) : null;
        }
    } catch (Throwable $e) {}
}

$agenciaNombre    = !empty($agenciaData['nombre']) ? $agenciaData['nombre'] : ($agenciaSesion ?: 'DIVOL LA VILLA');
$agenciaRazon     = !empty($agenciaData['razon_social']) ? $agenciaData['razon_social'] : 'Divol La Villa SAPI';
$agenciaRfc       = !empty($agenciaData['rfc']) ? $agenciaData['rfc'] : 'XAXX010101000';
$agenciaDireccion = !empty($agenciaData['direccion']) ? $agenciaData['direccion'] : 'Av. Ferrocarril Hidalgo 883, Aragón, Gustavo A. Madero, 07000 CDMX';
$agenciaTel       = !empty($agenciaData['telefono_sistemas']) ? $agenciaData['telefono_sistemas'] : '';
$agenciaEmail     = !empty($agenciaData['correo_sistemas']) ? $agenciaData['correo_sistemas'] : '';
$agenciaEncargado = !empty($agenciaData['encargado_sistemas']) ? $agenciaData['encargado_sistemas'] : 'Departamento de Sistemas';
$logoRel          = $agenciaData['logo_url'] ?? '';

// Convertir logo a Base64 para garantizar que el PDF y la impresión NUNCA fallen
$logoBase64 = '';
if (!empty($logoRel)) {
    $logoPaths = [
        $logoRel,
        __DIR__ . '/' . $logoRel,
        __DIR__ . '/' . ltrim($logoRel, '/'),
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . ltrim($logoRel, '/')
    ];
    foreach ($logoPaths as $lp) {
        if (!empty($lp) && file_exists($lp) && is_file($lp)) {
            $sz = @getimagesize($lp);
            if ($sz && !empty($sz['mime'])) {
                $content = @file_get_contents($lp);
                if ($content !== false) {
                    $logoBase64 = 'data:' . $sz['mime'] . ';base64,' . base64_encode($content);
                    break;
                }
            }
        }
    }
}

// 2. CARGA Y CRUCE DE COLABORADORES Y EXTENSIONES
$contactosDirectorio = [];
$matchedExtensions = [];
$totalExtensiones = 0;
$totalCorreos = 0;

if ($pdo) {
    // A. Teléfonos PoE
    $mapTelefonosPorUsuario = [];
    $mapTelefonosPorCorreo = [];
    $listaTodosPoe = [];
    try {
        $stmtTel = $pdo->query("SELECT * FROM inv_telefonos_poe");
        if ($stmtTel) {
            while ($rowTel = $stmtTel->fetch(PDO::FETCH_ASSOC)) {
                $listaTodosPoe[] = $rowTel;
                $uKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($rowTel['usuario'] ?? '')));
                $cKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($rowTel['correo'] ?? '')));
                if (!empty($uKey)) $mapTelefonosPorUsuario[$uKey] = $rowTel;
                if (!empty($cKey)) $mapTelefonosPorCorreo[$cKey] = $rowTel;
            }
        }
    } catch (Throwable $t) {}

    // B. Equipos asignados (VW y Corp)
    $mapEquiposPorUsuario = [];
    $mapEquiposPorCorreo = [];
    $listaTodosEquipos = [];
    foreach (['inv_equipos_vw', 'inv_equipos_corp'] as $tblEq) {
        try {
            $stmtEq = $pdo->query("SELECT usuario, correo, extension, nombre_equipo, departamento, puesto FROM `$tblEq`");
            if ($stmtEq) {
                while ($rowEq = $stmtEq->fetch(PDO::FETCH_ASSOC)) {
                    $listaTodosEquipos[] = $rowEq;
                    $uKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($rowEq['usuario'] ?? '')));
                    $cKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($rowEq['correo'] ?? '')));
                    if (!empty($uKey) && !isset($mapEquiposPorUsuario[$uKey])) $mapEquiposPorUsuario[$uKey] = $rowEq;
                    if (!empty($cKey) && !isset($mapEquiposPorCorreo[$cKey])) $mapEquiposPorCorreo[$cKey] = $rowEq;
                }
            }
        } catch (Throwable $t) {}
    }

    // C. Usuarios activos
    try {
        $stmtUsers = $pdo->query("SELECT id, usuario, nombre, email, area, puesto, telefono, extension, foto_url, rol, activo FROM usuarios WHERE activo = 1 ORDER BY area ASC, nombre ASC");
        $rawUsers = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($rawUsers as $u) {
            $nomKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($u['nombre'] ?? '')));
            $mailKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($u['email'] ?? '')));

            $extFinal = trim($u['extension'] ?? '');
            $telFinal = trim($u['telefono'] ?? '');
            $pcAsignada = '';

            // PoE
            $matchTel = $mapTelefonosPorCorreo[$mailKey] ?? ($mapTelefonosPorUsuario[$nomKey] ?? null);
            if ($matchTel) {
                if (empty($extFinal) && !empty($matchTel['extension'])) $extFinal = trim($matchTel['extension']);
                if (empty($telFinal) && !empty($matchTel['numero_telefonico'])) $telFinal = trim($matchTel['numero_telefonico']);
                if (empty($u['area']) && !empty($matchTel['area'])) $u['area'] = trim($matchTel['area']);
                elseif (empty($u['area']) && !empty($matchTel['departamento'])) $u['area'] = trim($matchTel['departamento']);
            }

            // PC
            $matchEq = $mapEquiposPorCorreo[$mailKey] ?? ($mapEquiposPorUsuario[$nomKey] ?? null);
            if ($matchEq) {
                if (empty($extFinal) && !empty($matchEq['extension'])) $extFinal = trim($matchEq['extension']);
                $pcAsignada = trim($matchEq['nombre_equipo'] ?? '');
                if (empty($u['area']) && !empty($matchEq['departamento'])) $u['area'] = trim($matchEq['departamento']);
                if (empty($u['puesto']) && !empty($matchEq['puesto'])) $u['puesto'] = trim($matchEq['puesto']);
            }

            if (!empty($extFinal)) {
                $matchedExtensions[$extFinal] = true;
                $totalExtensiones++;
            }
            if (!empty($u['email'])) $totalCorreos++;

            $contactosDirectorio[] = [
                'id' => $u['id'],
                'nombre' => $u['nombre'],
                'email' => $u['email'] ?? '',
                'area' => !empty($u['area']) ? $u['area'] : 'General',
                'puesto' => !empty($u['puesto']) ? $u['puesto'] : ($u['rol'] === 'Contacto' ? 'Buzón Departamental' : 'Colaborador'),
                'extension' => $extFinal,
                'telefono' => $telFinal,
                'pc_asignada' => $pcAsignada,
                'es_departamental' => ($u['rol'] === 'Contacto'),
                'es_fija' => false
            ];
        }
    } catch (Throwable $e) {}

    // D. Teléfonos PoE no asignados
    foreach ($listaTodosPoe as $poe) {
        $pExt = trim($poe['extension'] ?? '');
        if (!empty($pExt) && !isset($matchedExtensions[$pExt])) {
            $matchedExtensions[$pExt] = true;
            $pNom = trim($poe['usuario'] ?? '');
            if (empty($pNom) || in_array(strtolower($pNom), ['disponible', 'libre', 'stock', 'sin asignar', '---', 'n/a'])) {
                $pNom = 'Teléfono Fijo Ext. ' . $pExt . (!empty($poe['modelo']) ? (' (' . $poe['modelo'] . ')') : '');
            }
            $pArea = trim($poe['area'] ?? ($poe['departamento'] ?? 'General'));
            if (empty($pArea)) $pArea = 'General';

            $totalExtensiones++;
            $contactosDirectorio[] = [
                'id' => 'poe_' . $poe['id'],
                'nombre' => $pNom,
                'email' => $poe['correo'] ?? '',
                'area' => $pArea,
                'puesto' => 'Extensión Telefónica PoE',
                'extension' => $pExt,
                'telefono' => $poe['numero_telefonico'] ?? '',
                'pc_asignada' => !empty($poe['ip']) ? ('IP: ' . $poe['ip']) : '',
                'es_departamental' => true,
                'es_fija' => true
            ];
        }
    }

    // E. Equipos VW con extensión no asignada
    foreach ($listaTodosEquipos as $eqRow) {
        $eqExt = trim($eqRow['extension'] ?? '');
        if (!empty($eqExt) && !isset($matchedExtensions[$eqExt])) {
            $matchedExtensions[$eqExt] = true;
            $eqNom = trim($eqRow['usuario'] ?? '');
            if (empty($eqNom) || in_array(strtolower($eqNom), ['disponible', 'libre', 'stock', 'sin asignar', '---', 'n/a'])) {
                $eqNom = 'Extensión ' . $eqExt . ' (' . ($eqRow['nombre_equipo'] ?? 'Equipo') . ')';
            }
            $eqArea = trim($eqRow['departamento'] ?? 'General');
            if (empty($eqArea)) $eqArea = 'General';

            $totalExtensiones++;
            $contactosDirectorio[] = [
                'id' => 'eq_' . rand(100, 999),
                'nombre' => $eqNom,
                'email' => $eqRow['correo'] ?? '',
                'area' => $eqArea,
                'puesto' => 'Extensión Equipo Fijo',
                'extension' => $eqExt,
                'telefono' => '',
                'pc_asignada' => $eqRow['nombre_equipo'] ?? '',
                'es_departamental' => true,
                'es_fija' => true
            ];
        }
    }
}

// Ordenar alfabéticamente por Área y luego por Nombre
usort($contactosDirectorio, function ($a, $b) {
    $cmpArea = strcasecmp($a['area'], $b['area']);
    if ($cmpArea !== 0) return $cmpArea;
    return strcasecmp($a['nombre'], $b['nombre']);
});

$fechaHoy = date('d/m/Y');
$horaHoy = date('H:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Directorio Oficial - <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Librería html2pdf para descarga directa en PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <style>
        /* Estilos generales de la pantalla de vista previa */
        body {
            background-color: #1e293b;
            color: #0f172a;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        /* Barra de herramientas superior flotante */
        .preview-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #0f172a;
            color: #ffffff;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 9999;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .preview-wrapper {
            padding: 75px 15px 40px 15px;
            display: flex;
            justify-content: center;
        }

        /* Hoja de papel simulada (formato horizontal / apaisado para directorio) */
        .paper-sheet {
            background: #ffffff;
            width: 100%;
            max-width: 1100px;
            min-height: 1400px;
            padding: 35px 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border-radius: 4px;
            box-sizing: border-box;
            color: #0f172a;
        }

        /* Encabezado Oficial Membretado */
        .official-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #002e62;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }

        .header-logo-box {
            max-width: 220px;
            text-align: left;
        }
        .header-logo-box img {
            max-height: 65px;
            max-width: 200px;
            object-fit: contain;
        }

        .header-agency-info {
            text-align: right;
            line-height: 1.25;
        }
        .agency-name-title {
            color: #002e62;
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            text-transform: uppercase;
        }
        .agency-razon {
            color: #334155;
            font-size: 0.88rem;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .agency-meta {
            color: #64748b;
            font-size: 0.76rem;
        }

        /* Título del Documento */
        .doc-banner {
            background: #002e62;
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }
        .doc-banner h3 {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .doc-banner-badge {
            background: rgba(255,255,255,0.2);
            font-size: 0.78rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        /* Tabla de Directorio Imprimible */
        .table-directory-print {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-bottom: 25px;
        }
        .table-directory-print thead th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.74rem;
            letter-spacing: 0.5px;
            padding: 9px 12px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 2px solid #94a3b8;
        }
        .table-directory-print tbody td {
            padding: 9px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .table-directory-print tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .ext-badge-print {
            font-family: 'Consolas', monospace;
            font-size: 0.95rem;
            font-weight: 800;
            color: #0369a1;
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
            white-space: nowrap;
        }
        .ext-badge-empty {
            color: #94a3b8;
            font-size: 0.75rem;
            font-style: italic;
        }

        .area-pill-print {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            white-space: nowrap;
        }

        /* Pie de página institucional */
        .official-footer {
            border-top: 1px solid #cbd5e1;
            padding-top: 12px;
            margin-top: 25px;
            font-size: 0.74rem;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Configuración de Impresión / Guardado PDF (@media print) */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .preview-toolbar {
                display: none !important;
            }
            .preview-wrapper {
                padding: 0 !important;
                display: block !important;
            }
            .paper-sheet {
                box-shadow: none !important;
                border: none !important;
                max-width: 100% !important;
                width: 100% !important;
                padding: 10mm 15mm !important;
                min-height: auto !important;
            }
            .doc-banner {
                background: #002e62 !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .table-directory-print thead th {
                background: #f1f5f9 !important;
                color: #000000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .ext-badge-print {
                background: #e0f2fe !important;
                color: #0369a1 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .table-directory-print tbody tr:nth-child(even) {
                background: #f8fafc !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            @page {
                size: letter portrait;
                margin: 8mm;
            }
        }
    </style>
</head>
<body>

<!-- Barra de Herramientas Superior Flotante -->
<div class="preview-toolbar d-print-none">
    <div class="d-flex align-items-center gap-3">
        <a href="directorio.php" class="btn btn-outline-light btn-sm rounded-3 px-3">
            <i class="bi bi-arrow-left me-1"></i> Volver al Directorio
        </a>
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-pdf-fill text-danger fs-4"></i>
            <div>
                <strong class="d-block text-white" style="font-size: 0.95rem;">VISTA PREVIA DEL DIRECTORIO OFICIAL</strong>
                <small class="text-secondary"><?php echo htmlspecialchars($agenciaNombre); ?> | <?php echo count($contactosDirectorio); ?> Registros</small>
            </div>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <!-- Botón Descargar PDF Directo -->
        <button type="button" class="btn btn-danger btn-sm fw-bold px-3 py-1.5 rounded-3 shadow-sm d-flex align-items-center gap-1.5" onclick="generarPDFDirecto()" id="btnDescargarPdf">
            <i class="bi bi-file-earmark-pdf-fill"></i> Descargar PDF (.pdf)
        </button>

        <!-- Botón Imprimir / Guardar como PDF de Windows -->
        <button type="button" class="btn btn-success btn-sm fw-bold px-3 py-1.5 rounded-3 shadow-sm d-flex align-items-center gap-1.5" onclick="window.print()">
            <i class="bi bi-printer-fill"></i> Imprimir / Guardar en PDF
        </button>

        <button type="button" class="btn btn-outline-secondary btn-sm text-white rounded-3 px-3" onclick="window.close()">
            <i class="bi bi-x-lg me-1"></i> Cerrar
        </button>
    </div>
</div>

<!-- Contenedor del Documento -->
<div class="preview-wrapper">
    <div class="paper-sheet" id="documentoDirectorio">

        <!-- 1. ENCABEZADO OFICIAL DE LA AGENCIA -->
        <div class="official-header">
            <div class="header-logo-box">
                <?php if (!empty($logoBase64)): ?>
                    <img src="<?php echo $logoBase64; ?>" alt="Logo <?php echo htmlspecialchars($agenciaNombre); ?>">
                <?php else: ?>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #002e62;">
                        <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($agenciaNombre); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="header-agency-info">
                <div class="agency-name-title"><?php echo htmlspecialchars($agenciaNombre); ?></div>
                <div class="agency-razon"><?php echo htmlspecialchars($agenciaRazon); ?></div>
                <div class="agency-meta"><strong>RFC:</strong> <?php echo htmlspecialchars($agenciaRfc); ?></div>
                <div class="agency-meta" style="max-width: 450px;"><?php echo htmlspecialchars($agenciaDireccion); ?></div>
            </div>
        </div>

        <!-- 2. BANNER DE TÍTULO OFICIAL -->
        <div class="doc-banner">
            <div>
                <h3><i class="bi bi-telephone-inbound-fill me-2"></i> Directorio Telefónico y de Correos Oficial</h3>
            </div>
            <div class="doc-banner-badge">
                VIGENCIA: <?php echo $fechaHoy; ?> | TOTAL: <?php echo count($contactosDirectorio); ?> CONTACTOS
            </div>
        </div>

        <!-- 3. TABLA DEL DIRECTORIO -->
        <table class="table-directory-print">
            <thead>
                <tr>
                    <th style="width: 28%;">Colaborador / Concepto</th>
                    <th style="width: 15%;">Área</th>
                    <th style="width: 20%;">Puesto / Cargo</th>
                    <th style="width: 23%;">Correo Institucional</th>
                    <th style="width: 14%; text-align: center;">Extensión</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contactosDirectorio)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 30px; color: #64748b;">
                            No hay colaboradores ni extensiones registradas en este directorio.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contactosDirectorio as $c): ?>
                        <tr>
                            <td>
                                <strong style="color: #0f172a; font-size: 0.9rem;"><?php echo htmlspecialchars($c['nombre']); ?></strong>
                                <?php if (!empty($c['telefono']) && $c['telefono'] !== $c['extension']): ?>
                                    <div style="font-size: 0.72rem; color: #64748b;"><i class="bi bi-phone"></i> <?php echo htmlspecialchars($c['telefono']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="area-pill-print"><?php echo htmlspecialchars($c['area']); ?></span>
                            </td>
                            <td style="color: #475569; font-size: 0.8rem;">
                                <?php echo htmlspecialchars($c['puesto']); ?>
                            </td>
                            <td>
                                <?php if (!empty($c['email'])): ?>
                                    <span style="font-family: 'Consolas', monospace; font-size: 0.78rem; color: #0284c7;">
                                        <?php echo htmlspecialchars($c['email']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 0.75rem; font-style: italic;">Sin correo</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if (!empty($c['extension'])): ?>
                                    <span class="ext-badge-print">
                                        <i class="bi bi-telephone-fill" style="font-size: 0.7rem;"></i> <?php echo htmlspecialchars($c['extension']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="ext-badge-empty">---</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 4. PIE DE PÁGINA OFICIAL -->
        <div class="official-footer">
            <div>
                <strong>Control de Sistemas:</strong> <?php echo htmlspecialchars($agenciaEncargado); ?> | 
                <strong>Fecha de Emisión:</strong> <?php echo $fechaHoy; ?> <?php echo $horaHoy; ?> hrs
            </div>
            <div>
                Portal de Sistemas Grupo Huerta | <strong><?php echo htmlspecialchars($agenciaNombre); ?></strong>
            </div>
        </div>

    </div>
</div>

<script>
    // Generación y descarga directa del archivo PDF utilizando html2pdf
    function generarPDFDirecto() {
        const btn = document.getElementById('btnDescargarPdf');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Generando PDF...';

        const elemento = document.getElementById('documentoDirectorio');
        const nombreArchivo = 'Directorio_Oficial_<?php echo preg_replace('/[^a-zA-Z0-9_-]/', '_', $agenciaNombre); ?>_<?php echo date('Ymd'); ?>.pdf';

        const opciones = {
            margin: [8, 8, 8, 8],
            filename: nombreArchivo,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, letterRendering: true },
            jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' }
        };

        html2pdf().set(opciones).from(elemento).save().then(() => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }).catch(err => {
            alert('No se pudo generar el archivo directo. Usa el botón "Imprimir / Guardar en PDF" del navegador.');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    }
</script>
</body>
</html>
