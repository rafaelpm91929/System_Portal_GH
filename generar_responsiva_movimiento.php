<?php
session_start();
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
$movimiento = null;

if ($pdo && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM audit_gds_passwords_movimientos WHERE id = ?");
        $stmt->execute([$id]);
        $movimiento = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $movimiento = null;
    }
}

if (!$movimiento) {
    die("<div style='font-family: sans-serif; padding: 40px; text-align: center;'><h2>⚠️ Error: Clave de movimiento no encontrada.</h2><p>El ID #{$id} no existe en la base de datos de auditoría.</p></div>");
}

// Datos de la Agencia
$agenciaData = obtenerDatosAgenciaExcel($pdo, $_SESSION['agencia'] ?? '');
$agenciaNombre = $agenciaData['nombre'] ?: 'GRUPO HUERTA';
$agenciaRazon  = $agenciaData['razon_social'] ?: 'Distribuidora de Vehículos de Oriente SAPI de CV';
$agenciaRfc    = $agenciaData['rfc'] ?: 'XAXX010101000';
$agenciaDir    = $agenciaData['direccion'] ?: 'Av. Ferrocarril Hidalgo 883, CDMX';
$encargadoSistemas = $agenciaData['encargado_sistemas'] ?: 'Departamento de Sistemas';

$logoSrc = '';
if (!empty($agenciaData['logo_base64'])) {
    $logoSrc = $agenciaData['logo_base64'];
} elseif (!empty($agenciaData['logo_url']) && file_exists(__DIR__ . '/' . $agenciaData['logo_url'])) {
    $logoSrc = $agenciaData['logo_url'];
}

$folioDoc = 'RESP-MOV-' . str_pad($movimiento['id'], 4, '0', STR_PAD_LEFT) . '-' . date('Y');
$fechaHoy = date('d/m/Y');
$fechaActualizacion = !empty($movimiento['fecha_actualizacion']) ? date('d/m/Y', strtotime($movimiento['fecha_actualizacion'])) : $fechaHoy;
$fechaVigencia = !empty($movimiento['fecha_vigencia']) ? date('d/m/Y', strtotime($movimiento['fecha_vigencia'])) : 'Indefinida / Hasta nuevo aviso';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responsiva Password de Movimiento - <?php echo htmlspecialchars($movimiento['tipo_movimiento']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.92rem;
            line-height: 1.5;
            padding-top: 60px;
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
            margin-bottom: 20px;
        }

        .company-title {
            font-weight: 800;
            color: #0f172a;
            font-size: 1.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .doc-title {
            background: #7f1d1d;
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
            width: 30%;
        }

        .table-info-custom td {
            background-color: #ffffff;
            color: #0f172a;
            font-weight: 500;
        }

        .policy-box {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid #d97706;
            padding: 14px 18px;
            border-radius: 4px;
            font-size: 0.82rem;
            color: #451a03;
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
                border-left: 4px solid #7f1d1d !important;
            }
            .table-info-custom th {
                background-color: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .doc-title {
                background-color: #7f1d1d !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<!-- Barra de Herramientas de Impresión -->
<div class="print-action-bar">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-key-fill fs-4 text-danger"></i>
        <div>
            <strong class="d-block text-white" style="font-size: 0.95rem;">CARTA RESPONSIVA DE CUSTODIA Y CLAVE DE AUTORIZACIÓN ESPECIAL (GDS)</strong>
            <small class="text-warning"><?php echo htmlspecialchars($folioDoc); ?> | <?php echo htmlspecialchars($movimiento['tipo_movimiento']); ?></small>
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

<div class="paper-page">

    <!-- Membrete de la Empresa -->
    <div class="header-box">
        <div class="row align-items-center">
            <div class="col-8">
                <div class="company-title"><?php echo htmlspecialchars($agenciaNombre); ?></div>
                <div style="font-size: 0.85rem; color: #475569;" class="mt-1">
                    <strong>Razón Social:</strong> <?php echo htmlspecialchars($agenciaRazon); ?><br>
                    <strong>RFC:</strong> <?php echo htmlspecialchars($agenciaRfc); ?><br>
                    <strong>Dirección:</strong> <?php echo htmlspecialchars($agenciaDir); ?>
                </div>
            </div>
            <div class="col-4 text-end">
                <?php if (!empty($logoSrc)): ?>
                <img src="<?php echo $logoSrc; ?>" alt="Logo" style="max-height: 60px; max-width: 170px;" class="img-fluid">
                <?php else: ?>
                <div style="border: 1px dashed #cbd5e1; padding: 12px; font-weight: bold; color: #0284c7; text-align: center;">
                    <?php echo htmlspecialchars($agenciaNombre); ?>
                </div>
                <?php endif; ?>
                <div class="mt-2 text-secondary font-monospace" style="font-size: 0.78rem;">
                    FOLIO: <strong><?php echo htmlspecialchars($folioDoc); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Título Oficial del Documento -->
    <div class="doc-title">
        CARTA RESPONSIVA DE CUSTODIA Y CLAVE DE AUTORIZACIÓN ESPECIAL (SISTEMA GDS)
    </div>

    <!-- 1. Datos del Responsable Autorizado -->
    <div class="section-header">1. DATOS DEL RESPONSABLE AUTORIZADO DE LA CLAVE</div>
    <table class="table-info-custom">
        <tr>
            <th>Nombre del Responsable:</th>
            <td colspan="3"><strong class="text-danger" style="font-size: 1rem;"><?php echo htmlspecialchars($movimiento['usuario_autorizado']); ?></strong></td>
        </tr>
        <tr>
            <th>Puesto / Cargo:</th>
            <td><?php echo htmlspecialchars($movimiento['puesto_autorizado'] ?: 'Gerente / Jefe de Área'); ?></td>
            <th>Área / Departamento:</th>
            <td><?php echo htmlspecialchars($movimiento['area'] ?: 'General'); ?></td>
        </tr>
        <tr>
            <th>Nivel de Sensibilidad:</th>
            <td>
                <span class="badge bg-danger text-white fw-bold px-2 py-1">
                    <i class="bi bi-shield-fill-exclamation me-1"></i> <?php echo htmlspecialchars($movimiento['nivel_seguridad'] ?: 'Alto'); ?>
                </span>
            </td>
            <th>Fecha de Emisión:</th>
            <td><?php echo $fechaHoy; ?></td>
        </tr>
    </table>

    <!-- 2. Detalle del Movimiento y Contraseña de Autorización -->
    <div class="section-header">2. ESPECIFICACIÓN DEL MOVIMIENTO AUTORIZADO Y CLAVE ENTREGADA</div>
    <table class="table-info-custom">
        <tr>
            <th>Tipo de Movimiento / Operación:</th>
            <td colspan="3"><strong style="color: #0f172a; font-size: 0.95rem;"><?php echo htmlspecialchars($movimiento['tipo_movimiento']); ?></strong></td>
        </tr>
        <tr>
            <th>Descripción y Alcance:</th>
            <td colspan="3"><?php echo nl2br(htmlspecialchars($movimiento['descripcion_movimiento'] ?: 'Autorización exclusiva en el sistema DMS/GDS para el movimiento especificado.')); ?></td>
        </tr>
        <tr>
            <th>Contraseña de Autorización Asignada:</th>
            <td><code style="font-size: 1.1rem; color: #b91c1c; font-weight: 800; letter-spacing: 1px;"><?php echo htmlspecialchars($movimiento['password_movimiento']); ?></code></td>
            <th>Vigencia de la Clave:</th>
            <td><?php echo $fechaVigencia; ?></td>
        </tr>
        <tr>
            <th>Fecha de Última Modificación:</th>
            <td><?php echo $fechaActualizacion; ?></td>
            <th>Estatus de Operación:</th>
            <td><span class="badge bg-success text-white">Activa / Vigente</span></td>
        </tr>
    </table>

    <!-- 3. Cláusulas de Auditoría y Responsabilidad -->
    <div class="section-header">3. POLÍTICA DE AUDITORÍA Y CUSTODIA ESTRICTA</div>
    <div class="policy-box">
        <p class="mb-2 fw-bold text-dark">Al recibir y custodiar esta contraseña de autorización especial, el titular asume plenamente las siguientes obligaciones:</p>
        <ol>
            <li><strong>Custodia Estricta y Secrecía:</strong> La clave de movimiento entregada constituye una firma electrónica de autorización de alto impacto operativo y financiero. Queda terminantemente prohibido proporcionarla a subordinados, capturistas, cajeros o asesores.</li>
            <li><strong>Presencia Física Obligatoria:</strong> El titular de la clave debe digitarla personalmente en la terminal del sistema cada vez que se requiera autorizar el movimiento; bajo ninguna circunstancia se dictará por teléfono, mensaje o papel.</li>
            <li><strong>Trazabilidad y Bitácora de Auditoría:</strong> Cada autorización ingresada con esta clave queda registrada de forma permanente en las tablas de auditoría de GDS con fecha, hora, número de folio y monto afectado, siendo auditable por la Dirección General y Auditores Externos.</li>
            <li><strong>Notificación Inmediata de Compromiso:</strong> En caso de sospecha de que la clave fue observada por un tercero, o ante rotación del personal a cargo, es obligación estricta solicitar su cambio inmediato al Departamento de Sistemas.</li>
        </ol>
    </div>

    <!-- 4. Firmas de Conformidad -->
    <div class="signatures-grid">
        <div class="row text-center">
            <div class="col-4">
                <div class="signature-box">
                    <div style="height: 55px;"></div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?php echo htmlspecialchars($movimiento['usuario_autorizado']); ?></div>
                    <div class="signature-role">Firma del Titular Responsable<br><small class="text-secondary"><?php echo htmlspecialchars($movimiento['puesto_autorizado'] ?: 'Responsable de Clave'); ?></small></div>
                </div>
            </div>
            <div class="col-4">
                <div class="signature-box">
                    <div style="height: 55px;"></div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?php echo htmlspecialchars($encargadoSistemas); ?></div>
                    <div class="signature-role">Entrega y Auditoría de Sistemas<br><small class="text-secondary">Departamento de TI</small></div>
                </div>
            </div>
            <div class="col-4">
                <div class="signature-box">
                    <div style="height: 55px;"></div>
                    <div class="signature-line"></div>
                    <div class="signature-name">Dirección / Gerencia General</div>
                    <div class="signature-role">Visto Bueno de Autorización<br><small class="text-secondary">Control Interno</small></div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center text-secondary mt-4 pt-2" style="font-size: 0.72rem; border-top: 1px dashed #e2e8f0;">
        Documento confidencial de auditoría interna de <?php echo htmlspecialchars($agenciaNombre); ?>. Consérvese copia original firmada y digitalizada en el expediente de control de accesos de Sistemas.
    </div>

</div>

</body>
</html>
