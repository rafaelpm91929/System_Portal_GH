<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

/**
 * Función auxiliar para obtener el primer valor no vacío de un array dado una lista de llaves.
 * Previene el problema del operador ?? en PHP cuando un campo existe en la BD pero su valor es cadena vacía ("").
 */
function getValBD($array, $keys, $default = 'N/A') {
    if (!is_array($array)) return $default;
    foreach ((array)$keys as $k) {
        if (isset($array[$k]) && trim((string)$array[$k]) !== '') {
            return trim((string)$array[$k]);
        }
    }
    return $default;
}

$id = intval($_GET['id'] ?? 0);
$seccion = $_GET['sec'] ?? 'equipos_vw';

// Definición de tablas por sección
$TABLAS_MAP = [
    'equipos_vw'         => 'inv_equipos_vw',
    'equipos_baja'       => 'inv_equipos_baja',
    'equipos_corp'       => 'inv_equipos_corp',
    'site_vw'            => 'inv_site_vw',
    'moviles'            => 'inv_dispositivos_moviles',
    'monitores'          => 'inv_monitores',
    'dvr'                => 'inv_dvr_camaras',
    'archivo'            => 'inv_archivo',
    'licencias_office'   => 'inv_licencias_office',
    'nobreak_baja'       => 'inv_nobreak_baja'
];

$tablaActual = $TABLAS_MAP[$seccion] ?? 'inv_equipos_vw';
$equipo = null;

if ($pdo && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM `$tablaActual` WHERE id = ?");
        $stmt->execute([$id]);
        $equipo = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $equipo = null;
    }
}

if (!$equipo) {
    die("<div style='font-family: sans-serif; padding: 40px; text-align: center;'><h2>⚠️ Error: Registro de equipo no encontrado.</h2><p>El ID #{$id} no existe en la sección seleccionada.</p></div>");
}

// 1. OBTENER DATOS DEL USUARIO ASIGNADO (100% DINÁMICO DESDE LA BASE DE DATOS)
$nombreOUser = getValBD($equipo, ['usuario', 'nombre', 'usuario_equipo', 'usuario_equipo_dominio'], '');

$usuarioData = null;
if ($pdo && !empty($nombreOUser)) {
    try {
        $stmtU = $pdo->prepare("SELECT * FROM usuarios WHERE LOWER(nombre) = LOWER(?) OR LOWER(email) = LOWER(?) OR LOWER(usuario) = LOWER(?) LIMIT 1");
        $stmtU->execute([$nombreOUser, $nombreOUser, $nombreOUser]);
        $usuarioData = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$usuarioData) {
            $stmtU2 = $pdo->prepare("SELECT * FROM usuarios WHERE LOWER(nombre) LIKE LOWER(?) LIMIT 1");
            $stmtU2->execute(['%' . $nombreOUser . '%']);
            $usuarioData = $stmtU2->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
}

$nomUsuarioFinal  = getValBD($usuarioData ?: [], ['nombre'], getValBD($equipo, ['usuario', 'nombre', 'usuario_equipo'], 'Sin Asignar'));
$emailUsuario     = getValBD($usuarioData ?: [], ['email'], getValBD($equipo, ['correo', 'correo_oficial_vw', 'correo_oficial_seat', 'mail'], 'N/A'));
$puestoUsuario    = getValBD($usuarioData ?: [], ['puesto'], getValBD($equipo, ['puesto'], 'Sin Puesto Especificado'));
$areaUsuario      = getValBD($usuarioData ?: [], ['area'], getValBD($equipo, ['departamento', 'area'], 'General'));
$telefonoUsuario  = getValBD($usuarioData ?: [], ['telefono'], getValBD($equipo, ['extension', 'celular'], 'N/A'));
$agenciaUser      = getValBD($usuarioData ?: [], ['agencia'], getValBD($equipo, ['agencia'], $_SESSION['agencia'] ?? ''));

// 2. OBTENER DATOS DE LA AGENCIA Y RAZÓN SOCIAL (100% DINÁMICO Y UNIVERSAL DESDE LA BASE DE DATOS)
$agenciaSesion = $_SESSION['agencia'] ?? '';
$agenciaData = null;

if ($pdo) {
    try {
        // Criterio 1: Coincidencia exacta o parcial por el nombre de agencia asignado al usuario o equipo
        if (!empty($agenciaUser)) {
            $stmtA = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmtA->execute([$agenciaUser]);
            $agenciaData = $stmtA->fetch(PDO::FETCH_ASSOC);

            if (!$agenciaData) {
                $stmtA = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) LIKE LOWER(?) OR LOWER(?) LIKE '%' || LOWER(nombre) || '%' LIMIT 1");
                $stmtA->execute(['%' . $agenciaUser . '%', $agenciaUser]);
                $agenciaData = $stmtA->fetch(PDO::FETCH_ASSOC);
            }
        }

        // Criterio 2: Coincidencia con la agencia activa en la sesión actual del portal
        if (!$agenciaData && !empty($agenciaSesion)) {
            $stmtA = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmtA->execute([$agenciaSesion]);
            $agenciaData = $stmtA->fetch(PDO::FETCH_ASSOC);

            if (!$agenciaData) {
                $stmtA = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) LIKE LOWER(?) OR LOWER(?) LIKE '%' || LOWER(nombre) || '%' LIMIT 1");
                $stmtA->execute(['%' . $agenciaSesion . '%', $agenciaSesion]);
                $agenciaData = $stmtA->fetch(PDO::FETCH_ASSOC);
            }
        }

        // Criterio 3: Tomar la agencia editada o creada más recientemente en el sistema
        if (!$agenciaData) {
            $stmtA3 = $pdo->query("SELECT * FROM agencias ORDER BY actualizado_en DESC, id DESC LIMIT 1");
            $agenciaData = $stmtA3->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
}

$agenciaNombreDisplay     = getValBD($agenciaData ?: [], ['nombre'], !empty($agenciaUser) ? $agenciaUser : 'Agencia');
$razonSocialDisplay       = getValBD($agenciaData ?: [], ['razon_social'], 'N/A');
$rfcAgenciaDisplay        = getValBD($agenciaData ?: [], ['rfc'], 'N/A');
$direccionAgenciaDisplay  = getValBD($agenciaData ?: [], ['direccion'], 'N/A');
$encargadoSistemasDisplay = getValBD($agenciaData ?: [], ['encargado_sistemas'], 'Departamento de Sistemas / IT');
$logoAgenciaDisplay       = getValBD($agenciaData ?: [], ['logo_url'], '');

// 3. OBTENER ESPECIFICACIONES TÉCNICAS DEL EQUIPO (100% DINÁMICO DESDE LA BASE DE DATOS)
$marcaPrinter = getValBD($equipo, ['marca'], '');
$modeloPrinter = getValBD($equipo, ['modelo_exacto', 'modelo'], '');
$marcaModCombined = trim($marcaPrinter . ' ' . $modeloPrinter);

$nombreEquipo = getValBD($equipo, [
    'nombre_equipo', 'equipo', 'nombre', 'nombre_maquina', 'hostname', 'tablet', 'celular', 'pantalla', 'dvr', 'licencia', 'monitor'
], !empty($marcaModCombined) ? $marcaModCombined : 'N/A');

$serieEquipo = getValBD($equipo, [
    'serie', 'numero_serie', 'serie_equipo', 'serie_nobreak', 'serie_licencia', 'mac', 'mac_wifi', 'mac_ethernet', 'direccion_mac'
], 'N/A');

$tipoEquipo = getValBD($equipo, [
    'tipo_equipo', 'modelo_exacto', 'modelo', 'modelo_nobreak', 'dvr', 'licencia', 'monitor', 'tablet', 'celular', 'pantalla'
], ($seccion === 'monitores' ? 'Impresora / Multifuncional' : 'Cómputo / Sistemas'));

$procesador = getValBD($equipo, [
    'procesador', 'ghz', 'rom', 'android'
], 'N/A');

$ramRaw = getValBD($equipo, ['ram'], '');
$ramDisplay = !empty($ramRaw) ? ($ramRaw . (preg_match('/gb|mb/i', $ramRaw) ? '' : ' GB')) : 'N/A';

$ddRaw = getValBD($equipo, ['dd', 'almacenamiento', 'capacidad_actual', 'rom'], '');
$discoDisplay = !empty($ddRaw) ? $ddRaw : 'N/A';

$sistemaOp = getValBD($equipo, [
    'sistema_op', 'android', 'dominio', 'office'
], 'N/A');

$ipDisplay = getValBD($equipo, ['ip', 'ip_local', 'ip_publica'], 'N/A');
$nodoDisplay = getValBD($equipo, ['numero_nodo', 'nodo'], 'N/A');
$puertoDisplay = getValBD($equipo, ['puerto_sw', 'puerto_patch_panel'], 'N/A');
$ubicacionDisplay = getValBD($equipo, ['ubicacion'], 'N/A');

$folioFactura = getValBD($equipo, [
    'folio_factura', 'factura', 'compra', 'proveedor'
], 'N/A');

$costoRaw = getValBD($equipo, ['costo', 'costo_equipo'], 'N/A');
$costoDisplay = 'N/A';
if ($costoRaw !== 'N/A') {
    $numClean = floatval(preg_replace('/[^0-9.]/', '', $costoRaw));
    $costoDisplay = ($numClean > 0) ? ('$' . number_format($numClean, 2)) : $costoRaw;
}

$fechaHoy = date('d/m/Y');
$folioResponsiva = 'RESP-' . strtoupper(mb_substr($seccion, 0, 4)) . '-' . str_pad($id, 4, '0', STR_PAD_LEFT) . '-' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carta Responsiva - <?php echo htmlspecialchars($nombreEquipo); ?> - <?php echo htmlspecialchars($nomUsuarioFinal); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.92rem;
            line-height: 1.5;
            padding-top: 20px;
            padding-bottom: 40px;
        }

        .paper-page {
            background: #ffffff;
            max-width: 850px;
            margin: 0 auto;
            padding: 45px 50px;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }

        .header-box {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 18px;
            margin-bottom: 25px;
        }

        .company-title {
            font-weight: 800;
            color: #0f172a;
            font-size: 1.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .doc-title {
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 0.95rem;
            text-align: center;
            margin-bottom: 20px;
        }

        .section-header {
            font-weight: 700;
            color: #0f223d;
            font-size: 0.88rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1.5px solid #cbd5e1;
            padding-bottom: 5px;
            margin-top: 20px;
            margin-bottom: 12px;
        }

        .table-info-custom {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .table-info-custom th, .table-info-custom td {
            padding: 7px 12px;
            border: 1px solid #cbd5e1;
            font-size: 0.85rem;
        }

        .table-info-custom th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 700;
            width: 25%;
        }

        .table-info-custom td {
            background-color: #ffffff;
            color: #0f172a;
            font-weight: 500;
        }

        .policy-box {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #0284c7;
            padding: 14px 18px;
            border-radius: 4px;
            font-size: 0.82rem;
            color: #334155;
            margin-top: 15px;
            margin-bottom: 25px;
        }

        .policy-box ol {
            margin-bottom: 0;
            padding-left: 18px;
        }

        .policy-box li {
            margin-bottom: 6px;
        }

        .signatures-grid {
            margin-top: 45px;
            padding-top: 15px;
        }

        .signature-line {
            border-top: 1px solid #334155;
            width: 85%;
            margin: 0 auto 8px auto;
        }

        .signature-box {
            text-align: center;
        }

        .signature-role {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }

        .signature-name {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.85rem;
        }

        /* Barra de Herramientas de Impresión */
        .print-action-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: #09182b;
            color: #ffffff;
            padding: 12px 25px;
            z-index: 9999;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Estilos Exclusivos de Impresión */
        @media print {
            .print-action-bar {
                display: none !important;
            }

            body {
                background: #ffffff !important;
                padding: 0 !important;
            }

            .paper-page {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }

            .policy-box {
                background-color: #ffffff !important;
                border: 1px solid #94a3b8 !important;
                border-left: 4px solid #0f172a !important;
            }

            .table-info-custom th {
                background-color: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .doc-title {
                background-color: #0f172a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<!-- Barra superior de acciones (Oculta al imprimir) -->
<div class="print-action-bar">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-file-earmark-pdf-fill fs-4 text-warning"></i>
        <div>
            <strong class="d-block text-white" style="font-size: 0.95rem;">CARTA RESPONSIVA DE RESGUARDO DE EQUIPO</strong>
            <small class="text-info"><?php echo htmlspecialchars($folioResponsiva); ?> | <?php echo htmlspecialchars($nomUsuarioFinal); ?></small>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-success fw-bold px-4 rounded-3" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i> Imprimir / Guardar en PDF
        </button>
        <button type="button" class="btn btn-outline-light rounded-3" onclick="window.close()">
            <i class="bi bi-x-lg"></i> Cerrar
        </button>
    </div>
</div>

<div style="height: 60px;" class="d-print-none"></div>

<div class="paper-page">
    
    <!-- ENCABEZADO CORPORATIVO (DATOS DE BASE DE DATOS) -->
    <div class="header-box d-flex justify-content-between align-items-center">
        <div>
            <?php if (!empty($logoAgenciaDisplay) && file_exists($logoAgenciaDisplay)): ?>
                <img src="<?php echo htmlspecialchars($logoAgenciaDisplay); ?>" alt="Logo" style="max-height: 55px; max-width: 180px;" class="mb-2 d-block">
            <?php endif; ?>
            <div class="company-title"><?php echo htmlspecialchars($agenciaNombreDisplay); ?></div>
            <div class="fw-bold text-secondary small"><?php echo htmlspecialchars($razonSocialDisplay); ?></div>
            <div class="text-muted" style="font-size: 0.78rem;">RFC: <strong><?php echo htmlspecialchars($rfcAgenciaDisplay); ?></strong></div>
            <div class="text-muted" style="font-size: 0.75rem; max-width: 500px;"><?php echo htmlspecialchars($direccionAgenciaDisplay); ?></div>
        </div>
        <div class="text-end">
            <div class="badge bg-secondary text-white p-2 font-monospace mb-2" style="font-size: 0.85rem; border: 1px solid #475569; display: inline-block; text-transform: uppercase;">
                FOLIO: <?php echo htmlspecialchars($folioResponsiva); ?>
            </div>
            <div class="small text-secondary">
                <strong>Fecha de Emisión:</strong><br>
                <span class="text-dark fw-bold"><?php echo $fechaHoy; ?></span>
            </div>
        </div>
    </div>

    <!-- TÍTULO DE LA CARTA -->
    <div class="doc-title">
        CARTA RESPONSIVA Y ACTA DE ENTREGA DE EQUIPO DE CÓMPUTO Y SISTEMAS
    </div>

    <!-- SECCIÓN 1: DATOS DEL ASIGNATARIO / COLABORADOR -->
    <div class="section-header"><i class="bi bi-person-badge me-1"></i> 1. DATOS DEL COLABORADOR / ASIGNATARIO</div>
    <table class="table-info-custom">
        <tr>
            <th>Nombre del Usuario:</th>
            <td colspan="3"><strong class="text-uppercase"><?php echo htmlspecialchars($nomUsuarioFinal); ?></strong></td>
        </tr>
        <tr>
            <th>Puesto / Cargo:</th>
            <td><?php echo htmlspecialchars($puestoUsuario); ?></td>
            <th>Área / Depto:</th>
            <td><?php echo htmlspecialchars($areaUsuario); ?></td>
        </tr>
        <tr>
            <th>Sucursal / Agencia:</th>
            <td><?php echo htmlspecialchars($agenciaNombreDisplay); ?></td>
            <th>Correo Electrónico:</th>
            <td><?php echo htmlspecialchars($emailUsuario); ?></td>
        </tr>
    </table>

    <!-- SECCIÓN 2: DATOS TÉCNICOS DEL EQUIPO DE CÓMPUTO / IMPRESORA -->
    <div class="section-header"><i class="bi bi-display me-1"></i> 2. ESPECIFICACIONES DEL EQUIPO Y HARDWARE ASIGNADO</div>
    <table class="table-info-custom">
        <tr>
            <th>Nombre / Marca-Modelo:</th>
            <td><strong class="font-monospace text-primary"><?php echo htmlspecialchars($nombreEquipo); ?></strong></td>
            <th>Número de Serie (SN):</th>
            <td><strong class="font-monospace text-dark"><?php echo htmlspecialchars($serieEquipo); ?></strong></td>
        </tr>
        <tr>
            <th>Tipo / Categoría:</th>
            <td><?php echo htmlspecialchars($tipoEquipo); ?></td>
            <th>Dirección IP:</th>
            <td class="font-monospace fw-bold text-dark"><?php echo htmlspecialchars($ipDisplay); ?></td>
        </tr>
        <?php if ($seccion === 'monitores' || $nodoDisplay !== 'N/A' || $puertoDisplay !== 'N/A' || $ubicacionDisplay !== 'N/A'): ?>
        <tr>
            <th>Número de Nodo:</th>
            <td><strong class="font-monospace"><?php echo htmlspecialchars($nodoDisplay); ?></strong></td>
            <th>Puerto Switch:</th>
            <td><strong class="font-monospace"><?php echo htmlspecialchars($puertoDisplay); ?></strong></td>
        </tr>
        <tr>
            <th>Ubicación Física:</th>
            <td><?php echo htmlspecialchars($ubicacionDisplay); ?></td>
            <th>Folio Factura / Costo:</th>
            <td><?php echo htmlspecialchars($folioFactura); ?><?php if ($costoDisplay !== 'N/A'): ?> <span class="badge bg-light text-dark border border-secondary ms-1">Costo: <?php echo htmlspecialchars($costoDisplay); ?></span><?php endif; ?></td>
        </tr>
        <?php else: ?>
        <tr>
            <th>Procesador:</th>
            <td><?php echo htmlspecialchars($procesador); ?></td>
            <th>Memoria RAM:</th>
            <td><?php echo htmlspecialchars($ramDisplay); ?></td>
        </tr>
        <tr>
            <th>Disco / Almacenamiento:</th>
            <td><?php echo htmlspecialchars($discoDisplay); ?></td>
            <th>Folio Factura / Costo:</th>
            <td><?php echo htmlspecialchars($folioFactura); ?><?php if ($costoDisplay !== 'N/A'): ?> <span class="badge bg-light text-dark border border-secondary ms-1">Costo: <?php echo htmlspecialchars($costoDisplay); ?></span><?php endif; ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- SECCIÓN 3: POLÍTICAS Y TÉRMINOS DE RESPONSABILIDAD -->
    <div class="section-header"><i class="bi bi-shield-check me-1"></i> 3. POLÍTICAS DE USO Y TÉRMINOS DE RESPONSABILIDAD CORPORATIVA</div>
    <div class="policy-box">
        <p class="fw-bold mb-2">Por medio de la presente, el colaborador manifiesta recibir a su entera satisfacción el equipo de cómputo y las herramientas informáticas descritas en este documento, obligándose a cumplir rigurosamente con los siguientes términos:</p>
        <ol>
            <li><strong>Uso Exclusivo Laboral:</strong> El equipo es propiedad de <strong><?php echo htmlspecialchars($razonSocialDisplay !== 'N/A' ? $razonSocialDisplay : $agenciaNombreDisplay); ?></strong> y está destinado única y exclusivamente al desempeño de las funciones encomendadas al colaborador dentro de la empresa.</li>
            <li><strong>Cuidado y Custodia:</strong> El asignatario es responsable del correcto uso, resguardo y cuidado físico del equipo. Queda prohibido el consumo de alimentos o líquidos cerca del equipo, así como transportarlo sin la protección adecuada.</li>
            <li><strong>Credenciales y Contraseñas:</strong> Las cuentas corporativas, contraseñas de red, correos y accesos a sistemas son estrictamente personales, confidenciales e intransferibles.</li>
            <li><strong>Software No Autorizado:</strong> Queda strictly prohibida la instalación de programas, juegos, utilidades, herramientas de hackeo o cualquier software sin licencia o autorización expresa del Departamento de Sistemas (IT).</li>
            <li><strong>Resguardo de Información:</strong> El usuario es responsable de almacenar la información oficial de trabajo en los servidores o respaldos institucionales autorizados.</li>
            <li><strong>Devolución o Cancelación:</strong> En caso de concluir la relación laboral, cambio de puesto o cuando la dirección lo requiera, el colaborador deberá entregar el equipo en las mismas condiciones operativas en las que lo recibió.</li>
        </ol>
    </div>

    <!-- SECCIÓN 4: FIRMAS DE CONFORMIDAD -->
    <div class="section-header"><i class="bi bi-pen me-1"></i> 4. FIRMAS DE ENTREGADO Y CONFORMIDAD</div>
    <div class="row signatures-grid text-center g-4">
        <div class="col-4">
            <div class="signature-box">
                <div style="height: 50px;"></div>
                <div class="signature-line"></div>
                <div class="signature-name"><?php echo htmlspecialchars($nomUsuarioFinal); ?></div>
                <div class="signature-role">Colaborador / Asignatario</div>
                <div class="small text-muted" style="font-size: 0.7rem;">Firma de Recibido y Conformidad</div>
            </div>
        </div>
        <div class="col-4">
            <div class="signature-box">
                <div style="height: 50px;"></div>
                <div class="signature-line"></div>
                <div class="signature-name"><?php echo htmlspecialchars($encargadoSistemasDisplay); ?></div>
                <div class="signature-role">Departamento de Sistemas / IT</div>
                <div class="small text-muted" style="font-size: 0.7rem;">Entrega y Verificación Técnica</div>
            </div>
        </div>
        <div class="col-4">
            <div class="signature-box">
                <div style="height: 50px;"></div>
                <div class="signature-line"></div>
                <div class="signature-name">Recursos Humanos / Dirección</div>
                <div class="signature-role"><?php echo htmlspecialchars($agenciaNombreDisplay); ?></div>
                <div class="small text-muted" style="font-size: 0.7rem;">Autorización Administrativa</div>
            </div>
        </div>
    </div>

</div>

</body>
</html>
