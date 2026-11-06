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
$usuarioGrp = null;

if ($pdo && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM audit_grp_usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $usuarioGrp = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $usuarioGrp = null;
    }
}

if (!$usuarioGrp) {
    die("<div style='font-family: sans-serif; padding: 40px; text-align: center;'><h2>⚠️ Error: Registro de Usuario GRP no encontrado.</h2><p>El ID #{$id} no existe en la base de datos de auditoría.</p></div>");
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

$folioDoc = 'RESP-GRP-' . str_pad($usuarioGrp['id'], 4, '0', STR_PAD_LEFT) . '-' . date('Y');
$fechaHoy = date('d/m/Y');
$fechaAlta = !empty($usuarioGrp['fecha_alta']) ? date('d/m/Y', strtotime($usuarioGrp['fecha_alta'])) : $fechaHoy;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responsiva Usuario GRP - <?php echo htmlspecialchars($usuarioGrp['colaborador']); ?> (<?php echo htmlspecialchars($usuarioGrp['usuario_grp']); ?>)</title>
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
            background: #065f46;
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

        .roles-badge-container {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .role-pill {
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
            font-size: 0.78rem;
            padding: 3px 8px;
            border-radius: 4px;
            border: 1px solid #bae6fd;
        }

        .policy-box {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #059669;
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
                border-left: 4px solid #065f46 !important;
            }
            .table-info-custom th {
                background-color: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .doc-title {
                background-color: #065f46 !important;
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
        <i class="bi bi-people-fill fs-4 text-success"></i>
        <div>
            <strong class="d-block text-white" style="font-size: 0.95rem;">CARTA RESPONSIVA DE ACCESO Y ASIGNACIÓN DE ROLES (SISTEMA GRP)</strong>
            <small class="text-info"><?php echo htmlspecialchars($folioDoc); ?> | <?php echo htmlspecialchars($usuarioGrp['colaborador']); ?></small>
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
        CARTA RESPONSIVA DE ASIGNACIÓN DE USUARIO, ROLES Y PERMISOS (SISTEMA GRP)
    </div>

    <!-- 1. Datos del Colaborador -->
    <div class="section-header">1. DATOS DEL COLABORADOR RECEPTOR</div>
    <table class="table-info-custom">
        <tr>
            <th>Nombre del Colaborador:</th>
            <td colspan="3"><strong class="text-success" style="font-size: 1rem;"><?php echo htmlspecialchars($usuarioGrp['colaborador']); ?></strong></td>
        </tr>
        <tr>
            <th>Área / Departamento:</th>
            <td><?php echo htmlspecialchars($usuarioGrp['area'] ?: 'General'); ?></td>
            <th>Puesto / Función:</th>
            <td><?php echo htmlspecialchars($usuarioGrp['puesto'] ?: 'No especificado'); ?></td>
        </tr>
        <tr>
            <th>Fecha de Alta / Asignación:</th>
            <td><?php echo $fechaAlta; ?></td>
            <th>Fecha de Emisión:</th>
            <td><?php echo $fechaHoy; ?></td>
        </tr>
    </table>

    <!-- 2. Credenciales y Perfiles de Acceso en GRP -->
    <div class="section-header">2. CREDENCIALES, ROLES Y MÓDULOS EN EL SISTEMA GRP</div>
    <table class="table-info-custom">
        <tr>
            <th>Usuario Asignado (Login GRP):</th>
            <td><code style="font-size: 1rem; color: #0f172a; font-weight: bold;"><?php echo htmlspecialchars($usuarioGrp['usuario_grp']); ?></code></td>
            <th>Contraseña Temporal Inicial:</th>
            <td><code style="font-size: 0.95rem; color: #b91c1c; font-weight: bold;"><?php echo htmlspecialchars($usuarioGrp['password_grp']); ?></code></td>
        </tr>
        <tr>
            <th>Roles Asignados en GRP:</th>
            <td colspan="3">
                <div class="roles-badge-container">
                    <?php 
                    $rolesArr = explode(',', $usuarioGrp['roles_grp'] ?: 'Consulta');
                    foreach ($rolesArr as $rItem): 
                        $rItem = trim($rItem);
                        if (!empty($rItem)):
                    ?>
                        <span class="role-pill"><i class="bi bi-shield-check me-1"></i><?php echo htmlspecialchars($rItem); ?></span>
                    <?php endif; endforeach; ?>
                </div>
            </td>
        </tr>
        <tr>
            <th>Módulos con Permiso de Acceso:</th>
            <td colspan="3">
                <div class="text-secondary small">
                    <?php echo htmlspecialchars($usuarioGrp['modulos_permitidos'] ?: 'Módulos operativos autorizados conforme a su perfil'); ?>
                </div>
            </td>
        </tr>
        <tr>
            <th>Estatus en Plataforma GRP:</th>
            <td colspan="3"><span class="badge bg-success text-white">Activo / Operativo</span></td>
        </tr>
    </table>

    <!-- 3. Términos y Cláusulas de Confidencialidad -->
    <div class="section-header">3. OBLIGACIONES DE SEGURIDAD Y AUDITORÍA INTERNA</div>
    <div class="policy-box">
        <p class="mb-2 fw-bold text-dark">Al aceptar las credenciales y roles asignados en el sistema GRP, el colaborador asume los siguientes compromisos:</p>
        <ol>
            <li><strong>Principio de Mínimo Privilegio:</strong> Los roles y accesos otorgados han sido configurados con base estricta en las funciones de su puesto. Se compromete a no intentar escalar privilegios ni ingresar a módulos fuera de su alcance.</li>
            <li><strong>Intransferibilidad de Credenciales:</strong> El usuario y contraseña son personales. Queda prohibido facilitar la sesión abierta a compañeros de trabajo para la ejecución de capturas o consultas.</li>
            <li><strong>Auditoría de Actividad:</strong> El sistema GRP registra bitácoras electrónicas de transacciones, creaciones, modificaciones y eliminaciones de registros con la firma electrónica del usuario.</li>
            <li><strong>Cierre de Sesión Obligatorio:</strong> Es responsabilidad del colaborador bloquear su equipo o cerrar la sesión de GRP cada vez que se retire de su estación de trabajo.</li>
        </ol>
    </div>

    <!-- 4. Firmas de Conformidad -->
    <div class="signatures-grid">
        <div class="row text-center">
            <div class="col-6">
                <div class="signature-box">
                    <div style="height: 60px;"></div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?php echo htmlspecialchars($usuarioGrp['colaborador']); ?></div>
                    <div class="signature-role">Firma del Colaborador Receptor<br><small class="text-secondary"><?php echo htmlspecialchars($usuarioGrp['puesto'] ?: 'Usuario GRP'); ?></small></div>
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
