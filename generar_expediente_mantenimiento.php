<?php
/**
 * Generador Oficial de Expediente de Mantenimiento Preventivo y Correctivo
 * Formato Imprimible / Exportable a PDF con membrete corporativo y firmas
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/excel_helper.php';

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

if ($pdo) {
    asegurarTablasPermisos($pdo);
    asegurarTablasAuditoriaInterna($pdo);
}

$id = intval($_GET['id'] ?? 0);
$mantenimiento = null;

if ($pdo && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM audit_mantenimientos WHERE id = ?");
        $stmt->execute([$id]);
        $mantenimiento = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $mantenimiento = null;
    }
}

if (!$mantenimiento) {
    die("<div style='font-family: sans-serif; padding: 40px; text-align: center;'><h2>⚠️ Error: Registro de Mantenimiento no encontrado.</h2><p>El folio o ID #{$id} no existe en la base de datos.</p><p><a href='auditoria_interna.php#mantenimiento'>Volver a Auditoría Interna</a></p></div>");
}

// Buscar datos técnicos del equipo en el inventario
$equipoInfo = null;
if ($pdo) {
    try {
        if (!empty($mantenimiento['equipo_id'])) {
            $stmtEq = $pdo->prepare("SELECT * FROM inv_equipos_vw WHERE id = ? LIMIT 1");
            $stmtEq->execute([$mantenimiento['equipo_id']]);
            $equipoInfo = $stmtEq->fetch(PDO::FETCH_ASSOC);
        }
        if (!$equipoInfo && !empty($mantenimiento['nombre_equipo'])) {
            $stmtEq2 = $pdo->prepare("SELECT * FROM inv_equipos_vw WHERE LOWER(nombre_equipo) = LOWER(?) LIMIT 1");
            $stmtEq2->execute([trim($mantenimiento['nombre_equipo'])]);
            $equipoInfo = $stmtEq2->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $t) {}
}

// Datos de la Agencia
$agenciaData = obtenerDatosAgenciaExcel($pdo, $_SESSION['agencia'] ?? '');
$agenciaNombre = $agenciaData['nombre'] ?: 'GRUPO HUERTA';
$agenciaRazon  = $agenciaData['razon_social'] ?: 'Distribuidora de Vehículos de Oriente SAPI de CV';
$agenciaRfc    = $agenciaData['rfc'] ?: 'XAXX010101000';
$agenciaDir    = $agenciaData['direccion'] ?: 'Av. Ferrocarril Hidalgo 883, Aragón, CDMX';
$encargadoSistemas = $agenciaData['encargado_sistemas'] ?: 'Departamento de Sistemas';

$logoSrc = '';
if (!empty($agenciaData['logo_base64'])) {
    $logoSrc = $agenciaData['logo_base64'];
} elseif (!empty($agenciaData['logo_url']) && file_exists(__DIR__ . '/' . $agenciaData['logo_url'])) {
    $logoSrc = $agenciaData['logo_url'];
}

$folioDoc = 'EXP-MANT-' . str_pad($mantenimiento['id'], 4, '0', STR_PAD_LEFT) . '-' . date('Y', strtotime($mantenimiento['fecha_programada'] ?: date('Y-m-d')));
$fechaHoy = date('d/m/Y');
$fechaProg = !empty($mantenimiento['fecha_programada']) ? date('d/m/Y', strtotime($mantenimiento['fecha_programada'])) : $fechaHoy;
$fechaReal = !empty($mantenimiento['fecha_realizada']) ? date('d/m/Y', strtotime($mantenimiento['fecha_realizada'])) : 'Pendiente';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expediente de Mantenimiento - <?php echo htmlspecialchars($mantenimiento['nombre_equipo']); ?> (<?php echo $folioDoc; ?>)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.9rem;
            line-height: 1.45;
            padding-top: 50px;
            padding-bottom: 40px;
        }

        .paper-page {
            background: #ffffff;
            max-width: 850px;
            margin: 0 auto;
            padding: 40px 45px;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }

        .header-box {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }

        .company-title {
            font-weight: 800;
            color: #0f172a;
            font-size: 1.2rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .doc-title {
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
            text-align: center;
            padding: 6px 12px;
            font-size: 0.95rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            border-radius: 4px;
            margin-bottom: 16px;
        }

        .section-label {
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #0f172a;
            border-bottom: 1.5px solid #cbd5e1;
            padding-bottom: 4px;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-table {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 5px 8px;
            vertical-align: middle;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }

        .info-table .lbl {
            background-color: #f1f5f9;
            color: #475569;
            font-weight: 700;
            width: 25%;
            font-size: 0.78rem;
            text-transform: uppercase;
        }

        .info-table .val {
            color: #0f172a;
            font-weight: 600;
        }

        .checklist-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 16px;
            margin-bottom: 14px;
            font-size: 0.82rem;
        }

        .checklist-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 6px 10px;
            border-radius: 4px;
        }

        .checklist-check {
            color: #0f766e;
            font-weight: bold;
            font-size: 1rem;
            line-height: 1;
        }

        .signatures-container {
            margin-top: 30px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            text-align: center;
        }

        .sig-box {
            padding-top: 45px;
            border-top: 1.5px solid #0f172a;
        }

        .sig-title {
            font-weight: 700;
            font-size: 0.82rem;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .sig-subtitle {
            font-size: 0.72rem;
            color: #64748b;
        }

        /* BARRA FLOTANTE DE CONTROL */
        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #0f172a;
            color: #ffffff;
            padding: 10px 24px;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                font-size: 10.5pt !important;
            }
            .print-toolbar {
                display: none !important;
            }
            .paper-page {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body>

    <!-- BARRA SUPERIOR DE ACCIÓN -->
    <div class="print-toolbar">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-shield-check text-warning fs-5"></i>
            <span class="fw-bold">Expediente Oficial de Mantenimiento</span>
            <span class="badge bg-secondary ms-2"><?php echo $folioDoc; ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print()" class="btn btn-warning btn-sm fw-bold px-3">
                <i class="bi bi-printer-fill me-1"></i> Imprimir / Guardar en PDF
            </button>
            <a href="auditoria_interna.php#mantenimiento" class="btn btn-outline-light btn-sm px-3">
                <i class="bi bi-arrow-left me-1"></i> Regresar
            </a>
        </div>
    </div>

    <!-- DOCUMENTO OFICIAL IMPRIMIBLE -->
    <div class="paper-page">
        
        <!-- ENCABEZADO -->
        <div class="header-box d-flex align-items-center justify-content-between">
            <div>
                <?php if (!empty($logoSrc)): ?>
                    <img src="<?php echo $logoSrc; ?>" alt="Logo Corporativo" style="max-height: 48px; max-width: 180px; object-fit: contain;" class="mb-1">
                <?php else: ?>
                    <div class="company-title"><?php echo htmlspecialchars($agenciaNombre); ?></div>
                <?php endif; ?>
                <div class="text-secondary small" style="font-size: 0.76rem;">
                    <?php echo htmlspecialchars($agenciaRazon); ?> | RFC: <?php echo htmlspecialchars($agenciaRfc); ?><br>
                    <?php echo htmlspecialchars($agenciaDir); ?>
                </div>
            </div>
            <div class="text-end">
                <div class="fw-bold text-dark fs-6" style="letter-spacing: 0.5px;"><?php echo $folioDoc; ?></div>
                <div class="text-secondary small">Fecha de Emisión: <strong><?php echo $fechaHoy; ?></strong></div>
                <span class="badge <?php echo ($mantenimiento['estado'] === 'Realizado') ? 'bg-success' : 'bg-warning text-dark'; ?> text-uppercase mt-1">
                    Estatus: <?php echo htmlspecialchars($mantenimiento['estado']); ?>
                </span>
            </div>
        </div>

        <div class="doc-title">
            Expediente Técnico de Mantenimiento Preventivo y Correctivo de TI
        </div>

        <!-- 1. IDENTIFICACIÓN DEL EQUIPO Y ASIGNACIÓN -->
        <div class="section-label">
            <i class="bi bi-pc-display"></i> 1. Identificación del Equipo & Ubicación
        </div>
        <table class="info-table">
            <tr>
                <td class="lbl">Nombre de la Máquina:</td>
                <td class="val fw-bold text-primary"><?php echo htmlspecialchars($mantenimiento['nombre_equipo']); ?></td>
                <td class="lbl">Área / Departamento:</td>
                <td class="val"><?php echo htmlspecialchars($mantenimiento['area'] ?: 'General'); ?></td>
            </tr>
            <tr>
                <td class="lbl">Usuario Responsable:</td>
                <td class="val"><?php echo htmlspecialchars($mantenimiento['usuario_responsable'] ?: 'Responsable de Área'); ?></td>
                <td class="lbl">Puesto / Cargo:</td>
                <td class="val"><?php echo htmlspecialchars($equipoInfo['puesto'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td class="lbl">Tipo de Equipo:</td>
                <td class="val"><?php echo htmlspecialchars($equipoInfo['tipo'] ?? 'PC Desktop'); ?></td>
                <td class="lbl">Marca y Modelo:</td>
                <td class="val"><?php echo htmlspecialchars(($equipoInfo['marca'] ?? '') . ' ' . ($equipoInfo['modelo'] ?? '')); ?></td>
            </tr>
            <tr>
                <td class="lbl">Número de Serie:</td>
                <td class="val font-monospace"><?php echo htmlspecialchars($equipoInfo['serie'] ?? 'S/N'); ?></td>
                <td class="lbl">Dirección IP / Red:</td>
                <td class="val font-monospace"><?php echo htmlspecialchars($equipoInfo['ip_equipo'] ?? 'DHCP Dinámica'); ?></td>
            </tr>
        </table>

        <!-- 2. DATOS DEL SERVICIO -->
        <div class="section-label">
            <i class="bi bi-calendar-check"></i> 2. Parámetros de la Intervención Técnica
        </div>
        <table class="info-table">
            <tr>
                <td class="lbl">Tipo de Mantenimiento:</td>
                <td class="val fw-bold"><?php echo htmlspecialchars($mantenimiento['tipo_mantenimiento']); ?></td>
                <td class="lbl">Frecuencia Programada:</td>
                <td class="val"><?php echo htmlspecialchars($mantenimiento['frecuencia'] ?: 'Semestral'); ?></td>
            </tr>
            <tr>
                <td class="lbl">Fecha Programada:</td>
                <td class="val"><?php echo $fechaProg; ?></td>
                <td class="lbl">Fecha Realizado:</td>
                <td class="val"><?php echo $fechaReal; ?></td>
            </tr>
            <tr>
                <td class="lbl">Técnico de Sistemas:</td>
                <td class="val fw-bold"><?php echo htmlspecialchars($mantenimiento['tecnico_responsable'] ?: $encargadoSistemas); ?></td>
                <td class="lbl">Evidencia Documental:</td>
                <td class="val"><?php echo !empty($mantenimiento['evidencia_pdf_url']) ? 'Cargada en Sistema' : 'En proceso de integración'; ?></td>
            </tr>
        </table>

        <!-- 3. CHECKLIST TÉCNICO DE ACTIVIDADES EJECUTADAS -->
        <div class="section-label">
            <i class="bi bi-card-checklist"></i> 3. Protocolo Técnico de Mantenimiento Ejecutado
        </div>
        <div class="checklist-grid">
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Soplado y Limpieza de Chasis:</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Extracción de polvo ambiental de disipadores, ventiladores y rejillas térmicas.</div>
                </div>
            </div>
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Pasta Térmica de CPU:</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Limpieza de compuesto térmico degradado y aplicación de nueva pasta de alta disipación.</div>
                </div>
            </div>
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Fuente de Poder & Cableado:</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Verificación de voltajes operativos, estado de conectores y peinado de arnés.</div>
                </div>
            </div>
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Salud de Almacenamiento (S.M.A.R.T.):</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Comprobación de sectores, desgaste de celdas SSD y temperaturas de operación.</div>
                </div>
            </div>
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Optimización de Windows & Temporales:</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Depuración de caché (%temp%), optimización de inicio y espacio en disco C:.</div>
                </div>
            </div>
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Actualizaciones de Seguridad:</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Verificación de parches críticos de Windows Update y controladores oficiales.</div>
                </div>
            </div>
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Antivirus Corporativo & Escaneo:</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Validación del agente de protección endpoint, firmas al día y escaneo activo.</div>
                </div>
            </div>
            <div class="checklist-item">
                <span class="checklist-check">☑</span>
                <div>
                    <strong>Respaldos en Red (NAS):</strong>
                    <div class="text-secondary" style="font-size: 0.72rem;">Comprobación de mapeos de red, sincronización y carpetas críticas de usuario.</div>
                </div>
            </div>
        </div>

        <!-- 4. OBSERVACIONES Y DIAGNÓSTICO -->
        <div class="section-label">
            <i class="bi bi-chat-left-text"></i> 4. Diagnóstico Técnico y Observaciones
        </div>
        <div class="p-2 mb-3 rounded border border-secondary border-opacity-25 bg-light" style="font-size: 0.82rem; min-height: 48px;">
            <?php if (!empty($mantenimiento['observaciones'])): ?>
                <?php echo nl2br(htmlspecialchars($mantenimiento['observaciones'])); ?>
            <?php else: ?>
                <em>El equipo se sometió al protocolo estándar de mantenimiento preventivo, encontrándose en condiciones óptimas de operación de hardware y software. No se detectaron fallas críticas.</em>
            <?php endif; ?>
        </div>

        <!-- 5. FIRMAS DE CONFORMIDAD -->
        <div class="section-label">
            <i class="bi bi-pen"></i> 5. Aceptación y Firmas Oficiales
        </div>
        <p class="text-secondary" style="font-size: 0.73rem; text-align: justify; margin-bottom: 25px;">
            El colaborador abajo firmante certifica haber recibido a su entera satisfacción su equipo de cómputo en perfecto estado físico y operativo, con sus programas y archivos intactos tras la ejecución del mantenimiento programado.
        </p>

        <div class="signatures-container">
            <div class="sig-box">
                <div class="sig-title"><?php echo htmlspecialchars($mantenimiento['tecnico_responsable'] ?: 'Técnico de Sistemas'); ?></div>
                <div class="sig-subtitle">Ingeniero / Técnico Ejecutor<br>Soporte de TI</div>
            </div>

            <div class="sig-box">
                <div class="sig-title"><?php echo htmlspecialchars($mantenimiento['usuario_responsable'] ?: 'Usuario Asignado'); ?></div>
                <div class="sig-subtitle">Recibí de Conformidad<br>Responsable del Equipo</div>
            </div>

            <div class="sig-box">
                <div class="sig-title"><?php echo htmlspecialchars($encargadoSistemas); ?></div>
                <div class="sig-subtitle">Vo.Bo. Jefatura de Sistemas<br>Auditoría Interna TI</div>
            </div>
        </div>

    </div>

</body>
</html>
