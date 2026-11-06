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
$usuarioGds = null;

if ($pdo && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM audit_gds_usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $usuarioGds = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $usuarioGds = null;
    }
}

if (!$usuarioGds) {
    die("<div style='font-family: sans-serif; padding: 40px; text-align: center;'><h2>⚠️ Error: Registro de Usuario GDS no encontrado.</h2><p>El ID #{$id} no existe en la base de datos.</p></div>");
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

$folioDoc = 'RESP-GDS-' . str_pad($usuarioGds['id'], 4, '0', STR_PAD_LEFT) . '-' . date('Y');
$fechaHoy = date('d/m/Y');
$fechaAsignacion = !empty($usuarioGds['fecha_asignacion']) ? date('d/m/Y', strtotime($usuarioGds['fecha_asignacion'])) : $fechaHoy;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responsiva Usuario GDS - <?php echo htmlspecialchars($usuarioGds['colaborador']); ?> (<?php echo htmlspecialchars($usuarioGds['usuario_gds']); ?>)</title>
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
            width: 28%;
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

<!-- Barra de Herramientas de Impresión -->
<div class="print-action-bar">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-shield-lock-fill fs-4 text-warning"></i>
        <div>
            <strong class="d-block text-white" style="font-size: 0.95rem;">CARTA RESPONSIVA DE USUARIO Y ACCESO AL SISTEMA GDS</strong>
            <small class="text-info"><?php echo htmlspecialchars($folioDoc); ?> | <?php echo htmlspecialchars($usuarioGds['colaborador']); ?></small>
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
        CARTA RESPONSIVA DE ASIGNACIÓN DE USUARIO Y CONFIDENCIALIDAD (SISTEMA GDS)
    </div>

    <!-- 1. Datos del Colaborador -->
    <div class="section-header">1. DATOS DEL COLABORADOR RECEPTOR</div>
    <table class="table-info-custom">
        <tr>
            <th>Nombre del Colaborador:</th>
            <td colspan="3"><strong class="text-primary"><?php echo htmlspecialchars($usuarioGds['colaborador']); ?></strong></td>
        </tr>
        <tr>
            <th>Área / Departamento:</th>
            <td><?php echo htmlspecialchars($usuarioGds['area'] ?: 'General'); ?></td>
            <th>Puesto / Función:</th>
            <td><?php echo htmlspecialchars($usuarioGds['puesto'] ?: 'No especificado'); ?></td>
        </tr>
        <tr>
            <th>Sucursal / Ubicación:</th>
            <td><?php echo htmlspecialchars($usuarioGds['sucursal'] ?: 'Matriz'); ?></td>
            <th>Fecha de Emisión / Entrega:</th>
            <td><?php echo $fechaHoy; ?></td>
        </tr>
    </table>

    <!-- 2. Credenciales y Perfil Asignado -->
    <div class="section-header">2. CREDENCIALES Y ACCESOS AL SISTEMA DMS / GDS</div>
    <table class="table-info-custom">
        <tr>
            <th>Sistema Autorizado:</th>
            <td><strong>GDS (DMS Institucional de Agencia)</strong></td>
            <th>Perfil / Rol Autorizado:</th>
            <td><strong class="text-dark"><?php echo htmlspecialchars($usuarioGds['perfil_gds'] ?: 'Acceso Operativo'); ?></strong></td>
        </tr>
        <tr>
            <th>Usuario de Acceso (Login):</th>
            <td><code style="font-size: 1rem; color: #0f172a; font-weight: bold;"><?php echo htmlspecialchars($usuarioGds['usuario_gds']); ?></code></td>
            <th>Contraseña Temporal Inicial:</th>
            <td><code style="font-size: 0.95rem; color: #b91c1c; font-weight: bold;"><?php echo htmlspecialchars($usuarioGds['password_gds']); ?></code></td>
        </tr>
        <tr>
            <th>Fecha de Asignación en Sistema:</th>
            <td><?php echo $fechaAsignacion; ?></td>
            <th>Estatus de la Cuenta:</th>
            <td><span class="badge bg-success text-white">Activo / Autorizado</span></td>
        </tr>
    </table>

    <!-- 3. Términos y Cláusulas de Confidencialidad -->
    <div class="section-header">3. POLÍTICA DE SEGURIDAD, USO Y CONFIDENCIALIDAD</div>
    <div class="policy-box">
        <p class="mb-2 fw-bold text-dark">Al firmar de conformidad este documento, el colaborador acepta expresamente las siguientes condiciones:</p>
        <ol>
            <li><strong>Carácter Personal e Intransferible:</strong> La cuenta de usuario y contraseña asignadas son estrictamente personales e intransferibles. Queda prohibido compartirlas, cederlas o anotarlas en lugares visibles.</li>
            <li><strong>Responsabilidad de Transacciones:</strong> Cualquier operación, consulta, emisión de comprobantes, modificación de registros o movimientos realizados dentro del sistema GDS bajo este usuario se considerará atribuida legalmente a mi persona.</li>
            <li><strong>Obligación de Cambio de Clave:</strong> Me comprometo a cambiar la contraseña temporal inicial en mi primer inicio de sesión por una contraseña segura y no revelarla a terceros.</li>
            <li><strong>Confidencialidad de la Información:</strong> Los datos de clientes, precios, existencias, números de serie y registros contables consultados en el sistema GDS son propiedad confidencial de <strong><?php echo htmlspecialchars($agenciaNombre); ?></strong> y están protegidos por las leyes de propiedad industrial y protección de datos personales.</li>
            <li><strong>Notificación en Caso de Baja o Traslado:</strong> En caso de renuncia, despido, cambio de puesto o traslado de sucursal, el usuario será desactivado inmediatamente por el Departamento de Sistemas.</li>
        </ol>
    </div>

    <!-- 4. Firmas de Conformidad -->
    <div class="signatures-grid">
        <div class="row text-center">
            <div class="col-6">
                <div class="signature-box">
                    <div style="height: 60px;"></div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?php echo htmlspecialchars($usuarioGds['colaborador']); ?></div>
                    <div class="signature-role">Firma del Colaborador Receptor<br><small class="text-secondary"><?php echo htmlspecialchars($usuarioGds['puesto'] ?: 'Usuario GDS'); ?></small></div>
                </div>
            </div>
            <div class="col-6">
                <div class="signature-box">
                    <div style="height: 60px;"></div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?php echo htmlspecialchars($encargadoSistemas); ?></div>
                    <div class="signature-role">Entrega y Auditoría de Sistemas<br><small class="text-secondary">Departamento de Tecnologías de la Información</small></div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center text-secondary mt-4 pt-2" style="font-size: 0.72rem; border-top: 1px dashed #e2e8f0;">
        Documento oficial de control interno y auditoría emitido por el Portal de Sistemas de <?php echo htmlspecialchars($agenciaNombre); ?>. Consérvese copia firmada en el expediente digital de auditoría interna.
    </div>

</div>

</body>
</html>
