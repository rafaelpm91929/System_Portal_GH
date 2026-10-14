<?php
// ====================================================================
// VISTA OFICIAL IMPRIMIBLE / EXPORTACIÓN PDF DE REPORTE DE SERVICIO
// ÁREA DE INFRAESTRUCTURA - GRUPO HUERTA
// ====================================================================
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

date_default_timezone_set('America/Mexico_City');
if ($pdo) {
    try { $pdo->exec("SET time_zone = '-06:00'"); } catch (Throwable $tzE) {}
    asegurarTablaTickets($pdo);
}

// 1. Validar Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$usuarioId     = (int)$_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$loginUsuario  = $_SESSION['usuario_login'] ?? '';
$emailUsuario  = $_SESSION['usuario_email'] ?? '';
$esAdmin       = tienePermiso('tickets', 'puede_editar') || tienePermiso('usuarios', 'puede_editar') || (isset($_SESSION['es_admin']) && $_SESSION['es_admin']);

$ticketIdParam = trim($_GET['id'] ?? ($_GET['ticket_id'] ?? ''));
$folioParam    = trim($_GET['folio'] ?? '');

$ticket = null;
if ($pdo) {
    try {
        if (!empty($ticketIdParam) && is_numeric($ticketIdParam)) {
            $stmt = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ? LIMIT 1");
            $stmt->execute([intval($ticketIdParam)]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($folioParam)) {
            $stmt = $pdo->prepare("SELECT * FROM tickets_soporte WHERE folio = ? OR id = ? LIMIT 1");
            $stmt->execute([$folioParam, intval($folioParam)]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($ticketIdParam)) {
            $stmt = $pdo->prepare("SELECT * FROM tickets_soporte WHERE folio = ? LIMIT 1");
            $stmt->execute([$ticketIdParam]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
}

if (!$ticket) {
    die("<h3>Error: No se encontró el ticket o reporte de servicio solicitado.</h3><p><a href='tickets.php'>Regresar a Tickets</a></p>");
}

// Validación de acceso
$tieneAcceso = false;
if ($esAdmin) {
    $tieneAcceso = true;
} else {
    if (!empty($ticket['solicitante_id']) && (int)$ticket['solicitante_id'] === $usuarioId) $tieneAcceso = true;
    if (!empty($ticket['solicitante_usuario']) && !empty($loginUsuario) && strtolower($ticket['solicitante_usuario']) === strtolower($loginUsuario)) $tieneAcceso = true;
    if (!empty($ticket['solicitante_email']) && !empty($emailUsuario) && strtolower($ticket['solicitante_email']) === strtolower($emailUsuario)) $tieneAcceso = true;
    if (!empty($ticket['solicitante_nombre']) && !empty($nombreUsuario) && strtolower($ticket['solicitante_nombre']) === strtolower($nombreUsuario)) $tieneAcceso = true;
    // Sucursal
    if (isset($_SESSION['usuario_agencia']) && !empty($ticket['solicitante_agencia'])) {
        if (stripos($ticket['solicitante_agencia'], $_SESSION['usuario_agencia']) !== false || stripos($_SESSION['usuario_agencia'], $ticket['solicitante_agencia']) !== false) {
            $tieneAcceso = true;
        }
    }
}

if (!$tieneAcceso) {
    die("<h3>Acceso restringido: No cuentas con permisos para ver este reporte de servicio.</h3><p><a href='tickets.php'>Regresar a Tickets</a></p>");
}

$ordenNo       = !empty($ticket['reporte_orden_no']) ? $ticket['reporte_orden_no'] : ($ticket['folio'] ?? ('TK-' . $ticket['id']));
$problema      = !empty($ticket['reporte_problema']) ? $ticket['reporte_problema'] : ($ticket['descripcion'] ?? '');
$trabajoSol    = !empty($ticket['reporte_trabajo_solicitado']) ? $ticket['reporte_trabajo_solicitado'] : 'Revisión y soporte técnico de infraestructura.';
$trabajosReal  = !empty($ticket['reporte_trabajos_realizados']) ? $ticket['reporte_trabajos_realizados'] : 'Trabajos concluidos de conformidad.';
$observaciones = !empty($ticket['reporte_observaciones']) ? $ticket['reporte_observaciones'] : 'Sin observaciones adicionales.';
$tecnico       = !empty($ticket['reporte_tecnico_nombre']) ? $ticket['reporte_tecnico_nombre'] : (!empty($ticket['asignado_a']) ? $ticket['asignado_a'] : 'Dirección Central de Sistemas');
$firmado       = !empty($ticket['reporte_firmado']);
$firmaImg      = $ticket['reporte_firma_digital'] ?? '';
$firmadoPor    = !empty($ticket['reporte_firmado_por']) ? $ticket['reporte_firmado_por'] : ($ticket['solicitante_nombre'] ?? 'Solicitante');
$firmadoEn     = !empty($ticket['reporte_firmado_en']) ? $ticket['reporte_firmado_en'] : date('Y-m-d H:i:s');
$autoPrint     = isset($_GET['auto']) || isset($_GET['autoprint']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Servicio Técnico - Orden No. <?php echo htmlspecialchars($ordenNo); ?> - Grupo Huerta</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
            padding: 20px 10px;
        }
        .hoja-documento {
            background: #ffffff;
            max-width: 860px;
            margin: 0 auto;
            padding: 40px 45px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            position: relative;
        }
        .header-logo-text {
            font-size: 22px;
            font-weight: 800;
            color: #0284c7;
            letter-spacing: -0.5px;
        }
        .badge-orden {
            background: #0f172a;
            color: #ffffff;
            font-family: monospace;
            font-size: 14px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 6px;
            display: inline-block;
        }
        .seccion-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin-bottom: 5px;
        }
        .seccion-caja {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 13.5px;
            line-height: 1.55;
            color: #0f172a;
            white-space: pre-line;
            margin-bottom: 14px;
        }
        .meta-tabla td {
            padding: 5px 8px;
            font-size: 13px;
        }
        .meta-label {
            font-weight: 700;
            color: #475569;
            width: 25%;
        }
        .meta-val {
            font-weight: 600;
            color: #0f172a;
            width: 25%;
        }
        .barra-superior {
            max-width: 860px;
            margin: 0 auto 20px auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        @media print {
            @page {
                size: letter portrait;
                margin: 10mm 12mm;
            }
            body {
                background: #ffffff !important;
                padding: 0 !important;
                color: #000000 !important;
            }
            .barra-superior, .no-print {
                display: none !important;
            }
            .hoja-documento {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            .seccion-caja {
                border-color: #cbd5e1 !important;
                background: #f8fafc !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .badge-orden {
                background: #0f172a !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- BARRA DE ACCIONES (NO IMPRIMIBLE) -->
    <div class="barra-superior no-print">
        <a href="tickets.php?ver=<?php echo $ticket['id']; ?>" class="btn btn-outline-light rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2">
            <i class="bi bi-arrow-left"></i> Volver al Ticket
        </a>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-danger rounded-pill px-4 py-2 fw-bold d-inline-flex align-items-center gap-2 shadow" onclick="window.print()">
                <i class="bi bi-file-earmark-pdf-fill fs-5"></i> Guardar como PDF / Imprimir Formato
            </button>
        </div>
    </div>

    <!-- HOJA OFICIAL MEMBRETADA -->
    <div class="hoja-documento" id="hojaImpresion">
        
        <!-- Membrete Superior -->
        <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3" style="border-color: #0284c7 !important; border-bottom-width: 3px !important;">
            <div>
                <div class="header-logo-text">GRUPO HUERTA</div>
                <div style="font-size: 11px; font-weight: 700; color: #64748b; letter-spacing: 1px; text-transform: uppercase;">
                    Dirección Central de Sistemas &bull; Soporte e Infraestructura TI
                </div>
                <div class="fw-bold text-dark mt-1" style="font-size: 15px;">
                    FORMATO DE REPORTE Y CONFORMIDAD DE SERVICIO TÉCNICO
                </div>
            </div>
            <div class="text-end">
                <div class="badge-orden">
                    ORDEN NO. <?php echo htmlspecialchars($ordenNo); ?>
                </div>
                <div class="text-muted small mt-1" style="font-size: 11.5px;">
                    Fecha: <?php echo htmlspecialchars(date('d/m/Y', strtotime($ticket['creado_en']))); ?>
                </div>
                <?php if ($firmado): ?>
                    <div class="badge bg-success bg-opacity-10 text-success border border-success mt-1 fw-bold" style="font-size: 10px;">
                        ✓ CERTIFICADO DIGITALMENTE
                    </div>
                <?php else: ?>
                    <div class="badge bg-warning text-dark mt-1 fw-bold" style="font-size: 10px;">
                        PENDIENTE DE FIRMA
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabla de Metadatos -->
        <table class="w-100 meta-tabla mb-3 pb-2 border-bottom" style="border-color: #e2e8f0;">
            <tr>
                <td class="meta-label">Agencia / Sucursal:</td>
                <td class="meta-val"><?php echo htmlspecialchars($ticket['solicitante_agencia'] ?? 'General'); ?></td>
                <td class="meta-label">Técnico que Atendió:</td>
                <td class="meta-val text-primary"><?php echo htmlspecialchars($tecnico); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Solicitante del Ticket:</td>
                <td class="meta-val"><?php echo htmlspecialchars($ticket['solicitante_nombre'] ?? 'Solicitante'); ?></td>
                <td class="meta-label">Área Especializada:</td>
                <td class="meta-val text-info fw-bold">INFRAESTRUCTURA</td>
            </tr>
            <tr>
                <td class="meta-label">Email de Contacto:</td>
                <td class="meta-val text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($ticket['solicitante_email'] ?? '---'); ?></td>
                <td class="meta-label">Estatus del Ticket:</td>
                <td class="meta-val"><strong><?php echo htmlspecialchars($ticket['estado'] ?? 'En Proceso'); ?></strong></td>
            </tr>
        </table>

        <!-- 1. Necesidad o Problema Reportado -->
        <div>
            <div class="seccion-label">1. Necesidad o Problema Reportado (Ticket Original):</div>
            <div class="seccion-caja">
                <?php echo htmlspecialchars($problema); ?>
            </div>
        </div>

        <!-- 2. Trabajo Solicitado -->
        <div>
            <div class="seccion-label">2. Trabajo Solicitado:</div>
            <div class="seccion-caja">
                <?php echo htmlspecialchars($trabajoSol); ?>
            </div>
        </div>

        <!-- 3. Trabajos Realizados por el Técnico -->
        <div>
            <div class="seccion-label">3. Trabajos Realizados por el Técnico:</div>
            <div class="seccion-caja" style="background: #f0fdf4; border-color: #bbf7d0;">
                <?php echo htmlspecialchars($trabajosReal); ?>
            </div>
        </div>

        <!-- 4. Observaciones Técnicas y Recomendaciones -->
        <div>
            <div class="seccion-label">4. Observaciones Técnicas y Recomendaciones:</div>
            <div class="seccion-caja">
                <?php echo htmlspecialchars($observaciones); ?>
            </div>
        </div>

        <!-- Firmas de Conformidad -->
        <div style="margin-top: 30px; padding-top: 15px; border-top: 2px solid #cbd5e1;">
            <div class="row align-items-end text-center">
                <!-- Firma Técnico -->
                <div class="col-6">
                    <div style="height: 90px; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #0f172a;">
                        <?php echo htmlspecialchars($tecnico); ?>
                    </div>
                    <div style="border-top: 1.5px solid #0f172a; width: 80%; margin: 0 auto; padding-top: 6px;">
                        <div class="fw-bold" style="font-size: 12px; color: #0f172a;">Técnico Especialista Responsable</div>
                        <div class="text-muted" style="font-size: 10.5px;">Dirección Central de Sistemas &bull; Grupo Huerta</div>
                    </div>
                </div>

                <!-- Firma Digital Solicitante -->
                <div class="col-6">
                    <div style="height: 90px; display: flex; align-items: center; justify-content: center;">
                        <?php if ($firmado && !empty($firmaImg)): ?>
                            <img src="<?php echo htmlspecialchars($firmaImg); ?>" alt="Firma Digital" style="max-height: 85px; max-width: 240px; object-fit: contain;">
                        <?php elseif ($firmado): ?>
                            <div class="text-success fw-bold small">✓ Firmado Digitalmente</div>
                        <?php else: ?>
                            <div class="text-muted small italic">Pendiente de firma digital</div>
                        <?php endif; ?>
                    </div>
                    <div style="border-top: 1.5px solid #0f172a; width: 80%; margin: 0 auto; padding-top: 6px;">
                        <div class="fw-bold" style="font-size: 12px; color: #0f172a;">
                            <?php echo htmlspecialchars($firmadoPor); ?>
                        </div>
                        <?php if ($firmado): ?>
                            <div class="text-success fw-bold" style="font-size: 10px;">
                                Firma Digital de Conformidad &bull; <?php echo htmlspecialchars(date('d/m/Y h:i A', strtotime($firmadoEn))); ?>
                            </div>
                        <?php else: ?>
                            <div class="text-muted" style="font-size: 10px;">Firma de Conformidad del Solicitante</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pie de Página Institucional -->
        <div class="text-center text-muted mt-4 pt-3 border-top" style="font-size: 9.5px; border-color: #e2e8f0;">
            Documento Institucional de Conformidad Técnica &bull; Grupo Huerta &bull; Orden No. <?php echo htmlspecialchars($ordenNo); ?> &bull; Generado el <?php echo date('d/m/Y h:i A'); ?>
        </div>
    </div>

    <?php if ($autoPrint): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    window.print();
                }, 500);
            });
        </script>
    <?php endif; ?>

</body>
</html>
