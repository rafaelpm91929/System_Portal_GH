<?php
/**
 * Módulo de Auditoría Interna & Control Operativo
 * 100% Local y Autónomo (Sin APIs externas)
 * Diseño Basado en Botones y Tarjetas Ejecutivas (Cero Tablas)
 * 
 * Secciones:
 * 1. Gestión de Infraestructura:
 *    - Responsivas de Equipos (Inventario)
 *    - Planes de Mantenimiento (Calendario Interactivo, Asignación de Días, Expedientes PDF y Filtros por Máquina y Área)
 *    - Control de Correos (Altas, Bajas, Eliminados y Sincronización)
 *    - Licencias de Office (Product Keys y Cuentas Vinculadas)
 * 2. Seguridad de la Información:
 *    - Usuarios GDS (Credenciales y Responsivas Firmadas)
 *    - Password GDS - Movimientos Críticos (Contraseñas y Actas)
 *    - Control de Usuarios GRP (Roles y Permisos de Acceso)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/excel_helper.php';

date_default_timezone_set('America/Mexico_City');

// Asegurar tablas de base de datos
if ($pdo) {
    asegurarTablasPermisos($pdo);
    asegurarTablasAuditoriaInterna($pdo);
}

requerirPermiso('auditoria_interna', 'puede_ver');

$puedeEditar   = tienePermiso('auditoria_interna', 'puede_editar');
$puedeEliminar = tienePermiso('auditoria_interna', 'puede_eliminar');

$usuarioId     = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Auditor de Sistemas');
$rolActual     = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');

// Datos de la Agencia
$agenciaInfo = null;
if ($pdo) {
    try {
        $stmtAg = $pdo->query("SELECT nombre, logo_url, razon_social FROM agencias ORDER BY id ASC LIMIT 1");
        $agenciaInfo = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Throwable $e) {}
}
$agenciaNombre = !empty($agenciaInfo['nombre']) ? trim($agenciaInfo['nombre']) : ($_SESSION['agencia'] ?? 'Agencia Automotriz');
$agenciaLogo   = !empty($agenciaInfo['logo_url']) ? $agenciaInfo['logo_url'] : 'img/logo.png';

// Directorios locales para evidencias y responsivas
$dirUploads = __DIR__ . '/uploads/auditoria';
$dirsReq = [
    $dirUploads,
    $dirUploads . '/responsivas',
    $dirUploads . '/mantenimiento',
    $dirUploads . '/gds_usuarios',
    $dirUploads . '/gds_passwords',
    $dirUploads . '/grp'
];
foreach ($dirsReq as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}

// =============================================================================
// PROCESAMIENTO LOCAL DE ACCIONES (POST / GET)
// =============================================================================
$mensajeAlerta = '';
$tipoAlerta = '';

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

if (!empty($action) && $pdo) {
    $esAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['es_ajax']);

    try {
        switch ($action) {

            // 1. RESPONSIVAS DE EQUIPOS
            case 'subir_responsiva_equipo':
                if (!$puedeEditar) throw new Exception("No cuenta con permisos para subir archivos.");
                $eqId = intval($_POST['equipo_id'] ?? 0);
                $seccion = trim($_POST['seccion'] ?? 'inv_equipos_vw');
                if ($eqId <= 0) throw new Exception("Equipo no válido.");
                if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Seleccione un archivo PDF válido.");
                }
                $ext = strtolower(pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') throw new Exception("Únicamente se admiten archivos en formato PDF.");
                
                $nomArch = 'RESP_EQ_' . $eqId . '_' . date('Ymd_His') . '.pdf';
                $destPath = $dirUploads . '/responsivas/' . $nomArch;
                $relUrl = 'uploads/auditoria/responsivas/' . $nomArch;
                if (!move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $destPath)) {
                    throw new Exception("Error al guardar el archivo en el servidor.");
                }
                $stmtU = $pdo->prepare("UPDATE `$seccion` SET responsiva_url = ? WHERE id = ?");
                $stmtU->execute([$relUrl, $eqId]);

                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Responsiva subida con éxito', 'url' => $relUrl]);
                    exit();
                }
                $mensajeAlerta = 'Responsiva de equipo actualizada correctamente.';
                $tipoAlerta = 'success';
                break;

            case 'eliminar_responsiva_equipo':
                if (!$puedeEliminar) throw new Exception("No cuenta con permisos de eliminación.");
                $eqId = intval($_POST['equipo_id'] ?? 0);
                $seccion = trim($_POST['seccion'] ?? 'inv_equipos_vw');
                $stmtU = $pdo->prepare("UPDATE `$seccion` SET responsiva_url = NULL WHERE id = ?");
                $stmtU->execute([$eqId]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Responsiva eliminada']);
                    exit();
                }
                $mensajeAlerta = 'Responsiva desvinculada del equipo.';
                $tipoAlerta = 'info';
                break;

            // 2. PLANES DE MANTENIMIENTO (ASIGNACIÓN DE DÍAS Y CALENDARIO)
            case 'guardar_mantenimiento':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idM = intval($_POST['mantenimiento_id'] ?? 0);
                $nomEq = trim($_POST['nombre_equipo'] ?? '');
                $area = trim($_POST['area'] ?? 'General');
                $user = trim($_POST['usuario_responsable'] ?? '');
                $tipoM = trim($_POST['tipo_mantenimiento'] ?? 'Preventivo');
                $fProg = trim($_POST['fecha_programada'] ?? date('Y-m-d'));
                $frec = trim($_POST['frecuencia'] ?? 'Semestral');
                $est = trim($_POST['estado'] ?? 'Programado');
                $tec = trim($_POST['tecnico_responsable'] ?? 'Sistemas');
                $obs = trim($_POST['observaciones'] ?? '');
                $eqId = !empty($_POST['equipo_id']) ? intval($_POST['equipo_id']) : null;

                if (empty($nomEq)) throw new Exception("El nombre del equipo es obligatorio.");

                if ($idM > 0) {
                    $stmtM = $pdo->prepare("UPDATE audit_mantenimientos SET equipo_id=?, nombre_equipo=?, area=?, usuario_responsable=?, tipo_mantenimiento=?, fecha_programada=?, frecuencia=?, estado=?, tecnico_responsable=?, observaciones=? WHERE id=?");
                    $stmtM->execute([$eqId, $nomEq, $area, $user, $tipoM, $fProg, $frec, $est, $tec, $obs, $idM]);
                    $msg = 'Mantenimiento actualizado correctamente.';
                } else {
                    $stmtM = $pdo->prepare("INSERT INTO audit_mantenimientos (equipo_id, nombre_equipo, area, usuario_responsable, tipo_mantenimiento, fecha_programada, frecuencia, estado, tecnico_responsable, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtM->execute([$eqId, $nomEq, $area, $user, $tipoM, $fProg, $frec, $est, $tec, $obs]);
                    $msg = 'Día de mantenimiento programado exitosamente.';
                }
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => $msg]);
                    exit();
                }
                $mensajeAlerta = $msg;
                $tipoAlerta = 'success';
                break;

            case 'subir_evidencia_mantenimiento':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idM = intval($_POST['mantenimiento_id'] ?? 0);
                if ($idM <= 0) throw new Exception("ID de mantenimiento no válido.");
                if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Seleccione un archivo PDF válido.");
                }
                $ext = strtolower(pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') throw new Exception("Sólo se admiten archivos PDF.");

                $nomArch = 'MANT_EVID_' . $idM . '_' . date('Ymd_His') . '.pdf';
                $destPath = $dirUploads . '/mantenimiento/' . $nomArch;
                $relUrl = 'uploads/auditoria/mantenimiento/' . $nomArch;
                if (!move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $destPath)) {
                    throw new Exception("Error al guardar la evidencia.");
                }
                $hoy = date('Y-m-d');
                $stmtU = $pdo->prepare("UPDATE audit_mantenimientos SET evidencia_pdf_url=?, estado='Realizado', fecha_realizada=IFNULL(fecha_realizada, ?) WHERE id=?");
                $stmtU->execute([$relUrl, $hoy, $idM]);

                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Expediente/Evidencia PDF guardada y estatus marcado como Realizado.', 'url' => $relUrl]);
                    exit();
                }
                $mensajeAlerta = 'Expediente en PDF registrado con éxito.';
                $tipoAlerta = 'success';
                break;

            case 'eliminar_mantenimiento':
                if (!$puedeEliminar) throw new Exception("Permiso denegado.");
                $idM = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM audit_mantenimientos WHERE id = ?");
                $stmt->execute([$idM]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Mantenimiento eliminado']);
                    exit();
                }
                $mensajeAlerta = 'Registro de mantenimiento eliminado.';
                $tipoAlerta = 'info';
                break;

            // 3. CONTROL DE CORREOS
            case 'guardar_correo':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idC = intval($_POST['correo_id'] ?? 0);
                $correo = trim($_POST['correo'] ?? '');
                $nom = trim($_POST['nombre_colaborador'] ?? '');
                $area = trim($_POST['area'] ?? 'General');
                $puesto = trim($_POST['puesto'] ?? '');
                $tipoC = trim($_POST['tipo_correo'] ?? 'Personalizado');
                $est = trim($_POST['estado'] ?? 'Activo');
                $pass = trim($_POST['password_cuenta'] ?? '');
                $mot = trim($_POST['motivo_baja'] ?? '');

                if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Ingrese una dirección de correo válida.");
                }

                $fBaja = ($est !== 'Activo') ? date('Y-m-d') : null;

                if ($idC > 0) {
                    $stmtC = $pdo->prepare("UPDATE audit_correos SET correo=?, nombre_colaborador=?, area=?, puesto=?, tipo_correo=?, estado=?, password_cuenta=?, motivo_baja=?, fecha_baja=COALESCE(?, fecha_baja) WHERE id=?");
                    $stmtC->execute([$correo, $nom, $area, $puesto, $tipoC, $est, $pass, $mot, $fBaja, $idC]);
                    $msg = 'Cuenta de correo actualizada.';
                } else {
                    $stmtC = $pdo->prepare("INSERT INTO audit_correos (correo, nombre_colaborador, area, puesto, tipo_correo, estado, password_cuenta, motivo_baja, fecha_baja) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtC->execute([$correo, $nom, $area, $puesto, $tipoC, $est, $pass, $mot, $fBaja]);
                    $msg = 'Correo corporativo registrado.';
                }
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => $msg]);
                    exit();
                }
                $mensajeAlerta = $msg;
                $tipoAlerta = 'success';
                break;

            case 'cambiar_estado_correo':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idC = intval($_POST['id'] ?? 0);
                $nuevoEst = trim($_POST['nuevo_estado'] ?? 'En Baja');
                $mot = trim($_POST['motivo'] ?? 'Baja operativa del colaborador');
                $fBaja = ($nuevoEst !== 'Activo') ? date('Y-m-d') : null;

                $stmt = $pdo->prepare("UPDATE audit_correos SET estado = ?, motivo_baja = ?, fecha_baja = ? WHERE id = ?");
                $stmt->execute([$nuevoEst, $mot, $fBaja, $idC]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => "Estado actualizado a $nuevoEst."]);
                    exit();
                }
                $mensajeAlerta = "Cuenta de correo marcada como $nuevoEst.";
                $tipoAlerta = 'warning';
                break;

            case 'eliminar_correo':
                if (!$puedeEliminar) throw new Exception("Permiso denegado.");
                $idC = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM audit_correos WHERE id = ?");
                $stmt->execute([$idC]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Correo eliminado de la matriz']);
                    exit();
                }
                $mensajeAlerta = 'Cuenta de correo eliminada.';
                $tipoAlerta = 'info';
                break;

            case 'sincronizar_correos':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $nuevos = 0;
                $stmtIns = $pdo->prepare("INSERT INTO audit_correos (correo, nombre_colaborador, area, puesto, tipo_correo, estado) VALUES (?, ?, ?, ?, 'Personalizado', 'Activo')");

                $resU = $pdo->query("SELECT email, nombre, departamento, puesto FROM usuarios WHERE email IS NOT NULL AND TRIM(email) != ''");
                if ($resU) {
                    while ($ru = $resU->fetch(PDO::FETCH_ASSOC)) {
                        $em = strtolower(trim($ru['email']));
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                            $chk = $pdo->prepare("SELECT COUNT(*) FROM audit_correos WHERE LOWER(correo) = ?");
                            $chk->execute([$em]);
                            if ($chk->fetchColumn() == 0) {
                                $stmtIns->execute([$em, $ru['nombre'] ?: 'Colaborador', $ru['departamento'] ?: 'General', $ru['puesto'] ?: 'Usuario']);
                                $nuevos++;
                            }
                        }
                    }
                }
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => "Sincronización completada. Se añadieron $nuevos nuevas cuentas.", 'nuevos' => $nuevos]);
                    exit();
                }
                $mensajeAlerta = "Sincronización completada ($nuevos correos nuevos agregados).";
                $tipoAlerta = 'success';
                break;

            // 4. LICENCIAS DE OFFICE
            case 'guardar_licencia':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idL = intval($_POST['licencia_id'] ?? 0);
                $pk = trim($_POST['product_key'] ?? '');
                $ver = trim($_POST['version_office'] ?? 'Microsoft 365');
                $tipoL = trim($_POST['tipo_licencia'] ?? 'Suscripción');
                $cMs = trim($_POST['cuenta_microsoft'] ?? '');
                $eq = trim($_POST['equipo_asignado'] ?? '');
                $col = trim($_POST['colaborador'] ?? '');
                $area = trim($_POST['area'] ?? 'General');
                $est = trim($_POST['estado'] ?? 'Activa');
                $fVenc = !empty($_POST['fecha_vencimiento']) ? trim($_POST['fecha_vencimiento']) : null;

                if (empty($pk)) throw new Exception("El Product Key o código de activación es requerido.");

                if ($idL > 0) {
                    $stmtL = $pdo->prepare("UPDATE audit_licencias_office SET product_key=?, version_office=?, tipo_licencia=?, cuenta_microsoft=?, equipo_asignado=?, colaborador=?, area=?, estado=?, fecha_vencimiento=? WHERE id=?");
                    $stmtL->execute([$pk, $ver, $tipoL, $cMs, $eq, $col, $area, $est, $fVenc, $idL]);
                    $msg = 'Licencia de Office actualizada.';
                } else {
                    $stmtL = $pdo->prepare("INSERT INTO audit_licencias_office (product_key, version_office, tipo_licencia, cuenta_microsoft, equipo_asignado, colaborador, area, estado, fecha_vencimiento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtL->execute([$pk, $ver, $tipoL, $cMs, $eq, $col, $area, $est, $fVenc]);
                    $msg = 'Licencia de Office registrada con éxito.';
                }
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => $msg]);
                    exit();
                }
                $mensajeAlerta = $msg;
                $tipoAlerta = 'success';
                break;

            case 'eliminar_licencia':
                if (!$puedeEliminar) throw new Exception("Permiso denegado.");
                $idL = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM audit_licencias_office WHERE id = ?");
                $stmt->execute([$idL]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Licencia eliminada']);
                    exit();
                }
                $mensajeAlerta = 'Licencia de software eliminada.';
                $tipoAlerta = 'info';
                break;

            // 5. USUARIOS GDS
            case 'guardar_gds_usuario':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idU = intval($_POST['gds_id'] ?? 0);
                $usr = trim($_POST['usuario_gds'] ?? '');
                $col = trim($_POST['nombre_colaborador'] ?? '');
                $pwd = trim($_POST['password_gds'] ?? '');
                $prof = trim($_POST['perfil_gds'] ?? 'Operativo');
                $suc = trim($_POST['sucursal'] ?? 'Matriz');
                $area = trim($_POST['area'] ?? 'General');
                $est = trim($_POST['estado'] ?? 'Activo');
                $uId = !empty($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;

                if (empty($usr)) throw new Exception("El usuario de GDS es requerido.");

                if ($idU > 0) {
                    $stmtU = $pdo->prepare("UPDATE audit_gds_usuarios SET usuario_id=?, usuario_gds=?, nombre_colaborador=?, password_gds=?, perfil_gds=?, sucursal=?, area=?, estado=? WHERE id=?");
                    $stmtU->execute([$uId, $usr, $col, $pwd, $prof, $suc, $area, $est, $idU]);
                    $msg = 'Usuario GDS actualizado.';
                } else {
                    $stmtU = $pdo->prepare("INSERT INTO audit_gds_usuarios (usuario_id, usuario_gds, nombre_colaborador, password_gds, perfil_gds, sucursal, area, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtU->execute([$uId, $usr, $col, $pwd, $prof, $suc, $area, $est]);
                    $msg = 'Usuario GDS asignado exitosamente.';
                }
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => $msg]);
                    exit();
                }
                $mensajeAlerta = $msg;
                $tipoAlerta = 'success';
                break;

            case 'subir_responsiva_gds':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idU = intval($_POST['id'] ?? 0);
                if ($idU <= 0) throw new Exception("ID no válido.");
                if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Seleccione un archivo PDF válido.");
                }
                $nomArch = 'GDS_USR_' . $idU . '_' . date('Ymd_His') . '.pdf';
                $destPath = $dirUploads . '/gds_usuarios/' . $nomArch;
                $relUrl = 'uploads/auditoria/gds_usuarios/' . $nomArch;
                if (!move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $destPath)) {
                    throw new Exception("Error al guardar archivo.");
                }
                $stmt = $pdo->prepare("UPDATE audit_gds_usuarios SET responsiva_pdf_url = ? WHERE id = ?");
                $stmt->execute([$relUrl, $idU]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Responsiva GDS subida con éxito', 'url' => $relUrl]);
                    exit();
                }
                $mensajeAlerta = 'Responsiva de Usuario GDS cargada.';
                $tipoAlerta = 'success';
                break;

            case 'eliminar_gds_usuario':
                if (!$puedeEliminar) throw new Exception("Permiso denegado.");
                $idU = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM audit_gds_usuarios WHERE id = ?");
                $stmt->execute([$idU]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Usuario GDS eliminado']);
                    exit();
                }
                $mensajeAlerta = 'Usuario GDS eliminado.';
                $tipoAlerta = 'info';
                break;

            // 6. PASSWORD GDS - MOVIMIENTOS
            case 'guardar_gds_password':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idP = intval($_POST['movimiento_id'] ?? 0);
                $mov = trim($_POST['tipo_movimiento'] ?? '');
                $desc = trim($_POST['descripcion'] ?? '');
                $pass = trim($_POST['password'] ?? '');
                $resp = trim($_POST['responsable_autorizado'] ?? '');
                $cargo = trim($_POST['cargo_responsable'] ?? 'Gerencia');
                $nivel = trim($_POST['nivel_seguridad'] ?? 'Alto');

                if (empty($mov) || empty($pass)) throw new Exception("El movimiento y la contraseña son obligatorios.");

                if ($idP > 0) {
                    $stmtP = $pdo->prepare("UPDATE audit_gds_passwords_movimientos SET tipo_movimiento=?, descripcion=?, password=?, responsable_autorizado=?, cargo_responsable=?, nivel_seguridad=? WHERE id=?");
                    $stmtP->execute([$mov, $desc, $pass, $resp, $cargo, $nivel, $idP]);
                    $msg = 'Contraseña de movimiento actualizada.';
                } else {
                    $stmtP = $pdo->prepare("INSERT INTO audit_gds_passwords_movimientos (tipo_movimiento, descripcion, password, responsable_autorizado, cargo_responsable, nivel_seguridad) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtP->execute([$mov, $desc, $pass, $resp, $cargo, $nivel]);
                    $msg = 'Clave de movimiento configurada.';
                }
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => $msg]);
                    exit();
                }
                $mensajeAlerta = $msg;
                $tipoAlerta = 'success';
                break;

            case 'subir_responsiva_movimiento':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idP = intval($_POST['id'] ?? 0);
                if ($idP <= 0) throw new Exception("ID no válido.");
                if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Seleccione un archivo PDF válido.");
                }
                $nomArch = 'GDS_MOV_' . $idP . '_' . date('Ymd_His') . '.pdf';
                $destPath = $dirUploads . '/gds_passwords/' . $nomArch;
                $relUrl = 'uploads/auditoria/gds_passwords/' . $nomArch;
                if (!move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $destPath)) {
                    throw new Exception("Error al guardar archivo.");
                }
                $stmt = $pdo->prepare("UPDATE audit_gds_passwords_movimientos SET responsiva_pdf_url = ? WHERE id = ?");
                $stmt->execute([$relUrl, $idP]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Responsiva de movimiento cargada', 'url' => $relUrl]);
                    exit();
                }
                $mensajeAlerta = 'Acta responsiva de movimiento subida.';
                $tipoAlerta = 'success';
                break;

            case 'eliminar_gds_password':
                if (!$puedeEliminar) throw new Exception("Permiso denegado.");
                $idP = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM audit_gds_passwords_movimientos WHERE id = ?");
                $stmt->execute([$idP]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Clave de movimiento eliminada']);
                    exit();
                }
                $mensajeAlerta = 'Clave de movimiento eliminada.';
                $tipoAlerta = 'info';
                break;

            // 7. USUARIOS GRP
            case 'guardar_grp_usuario':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idG = intval($_POST['grp_id'] ?? 0);
                $usr = trim($_POST['usuario_grp'] ?? '');
                $col = trim($_POST['nombre_colaborador'] ?? '');
                $pwd = trim($_POST['password_grp'] ?? '');
                $roles = isset($_POST['roles']) ? (is_array($_POST['roles']) ? implode(', ', $_POST['roles']) : trim($_POST['roles'])) : 'Usuario';
                $mods = trim($_POST['modulos_permitidos'] ?? 'General');
                $est = trim($_POST['estado'] ?? 'Activo');

                if (empty($usr)) throw new Exception("El usuario de GRP es requerido.");

                if ($idG > 0) {
                    $stmtG = $pdo->prepare("UPDATE audit_grp_usuarios SET usuario_grp=?, nombre_colaborador=?, password_grp=?, roles=?, modulos_permitidos=?, estado=? WHERE id=?");
                    $stmtG->execute([$usr, $col, $pwd, $roles, $mods, $est, $idG]);
                    $msg = 'Usuario GRP actualizado.';
                } else {
                    $stmtG = $pdo->prepare("INSERT INTO audit_grp_usuarios (usuario_grp, nombre_colaborador, password_grp, roles, modulos_permitidos, estado) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtG->execute([$usr, $col, $pwd, $roles, $mods, $est]);
                    $msg = 'Usuario y roles GRP asignados correctamente.';
                }
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => $msg]);
                    exit();
                }
                $mensajeAlerta = $msg;
                $tipoAlerta = 'success';
                break;

            case 'subir_responsiva_grp':
                if (!$puedeEditar) throw new Exception("Permiso denegado.");
                $idG = intval($_POST['id'] ?? 0);
                if ($idG <= 0) throw new Exception("ID no válido.");
                if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Seleccione un archivo PDF.");
                }
                $nomArch = 'GRP_USR_' . $idG . '_' . date('Ymd_His') . '.pdf';
                $destPath = $dirUploads . '/grp/' . $nomArch;
                $relUrl = 'uploads/auditoria/grp/' . $nomArch;
                if (!move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $destPath)) {
                    throw new Exception("Error al guardar archivo.");
                }
                $stmt = $pdo->prepare("UPDATE audit_grp_usuarios SET responsiva_pdf_url = ? WHERE id = ?");
                $stmt->execute([$relUrl, $idG]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Responsiva GRP subida con éxito', 'url' => $relUrl]);
                    exit();
                }
                $mensajeAlerta = 'Responsiva de acceso GRP cargada.';
                $tipoAlerta = 'success';
                break;

            case 'eliminar_grp_usuario':
                if (!$puedeEliminar) throw new Exception("Permiso denegado.");
                $idG = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM audit_grp_usuarios WHERE id = ?");
                $stmt->execute([$idG]);
                if ($esAjax) {
                    echo json_encode(['success' => true, 'mensaje' => 'Usuario GRP eliminado']);
                    exit();
                }
                $mensajeAlerta = 'Usuario GRP eliminado.';
                $tipoAlerta = 'info';
                break;
        }
    } catch (Throwable $eAccion) {
        if ($esAjax) {
            echo json_encode(['success' => false, 'error' => $eAccion->getMessage()]);
            exit();
        }
        $mensajeAlerta = $eAccion->getMessage();
        $tipoAlerta = 'danger';
    }
}

// =============================================================================
// CONSULTA DE DATOS LOCALES PARA RENDERIZADO INMEDIATO
// =============================================================================
$equiposInventario = [];
$mantenimientos    = [];
$correos           = [];
$licenciasOffice   = [];
$gdsUsuarios       = [];
$gdsPasswords      = [];
$grpUsuarios       = [];
$catalogoUsuarios  = [];
$catalogoAreas     = [];

if ($pdo) {
    // 1. Equipos de Inventario para Responsivas
    try {
        $stmtEq = $pdo->query("SELECT id, nombre_equipo, usuario, departamento, puesto, tipo, marca, modelo, serie, responsiva_url FROM inv_equipos_vw WHERE (estado IS NULL OR estado != 'Baja') ORDER BY departamento ASC, nombre_equipo ASC");
        if ($stmtEq) {
            $equiposInventario = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $t) {
        try {
            $stmtEq = $pdo->query("SELECT id, nombre_equipo, usuario, departamento, puesto, tipo, marca, modelo, serie, responsiva_url FROM inv_equipos_corp WHERE (estado IS NULL OR estado != 'Baja') ORDER BY departamento ASC, nombre_equipo ASC");
            if ($stmtEq) $equiposInventario = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $t2) {}
    }

    // 2. Planes de Mantenimiento
    try {
        $stmtM = $pdo->query("SELECT * FROM audit_mantenimientos ORDER BY fecha_programada DESC, id DESC");
        if ($stmtM) $mantenimientos = $stmtM->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // 3. Control de Correos
    try {
        $stmtC = $pdo->query("SELECT * FROM audit_correos ORDER BY CASE WHEN estado = 'Activo' THEN 1 WHEN estado = 'En Baja' THEN 2 ELSE 3 END, correo ASC");
        if ($stmtC) $correos = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // 4. Licencias de Office
    try {
        $stmtL = $pdo->query("SELECT * FROM audit_licencias_office ORDER BY version_office ASC, id DESC");
        if ($stmtL) $licenciasOffice = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // 5. Usuarios GDS
    try {
        $stmtG = $pdo->query("SELECT * FROM audit_gds_usuarios ORDER BY usuario_gds ASC");
        if ($stmtG) $gdsUsuarios = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // 6. Password GDS Movimientos
    try {
        $stmtP = $pdo->query("SELECT * FROM audit_gds_passwords_movimientos ORDER BY id ASC");
        if ($stmtP) $gdsPasswords = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // 7. Usuarios GRP
    try {
        $stmtGr = $pdo->query("SELECT * FROM audit_grp_usuarios ORDER BY usuario_grp ASC");
        if ($stmtGr) $grpUsuarios = $stmtGr->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // Catálogos para Modales
    try {
        $stmtU = $pdo->query("SELECT id, nombre, usuario, email, departamento, puesto FROM usuarios ORDER BY nombre ASC");
        if ($stmtU) $catalogoUsuarios = $stmtU->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {}

    // Áreas únicas encontradas
    $areasSet = [];
    foreach ($equiposInventario as $e) { if (!empty($e['departamento'])) $areasSet[trim($e['departamento'])] = true; }
    foreach ($correos as $c) { if (!empty($c['area'])) $areasSet[trim($c['area'])] = true; }
    foreach ($mantenimientos as $m) { if (!empty($m['area'])) $areasSet[trim($m['area'])] = true; }
    ksort($areasSet);
    $catalogoAreas = array_keys($areasSet);
    if (!in_array('General', $catalogoAreas)) $catalogoAreas[] = 'General';
}

// Estadísticas de Resumen para los Botones del Menú Principal
$cntTotalEquipos   = count($equiposInventario);
$cntFirmadasEq     = count(array_filter($equiposInventario, fn($e) => !empty($e['responsiva_url'])));
$cntPendientesEq   = $cntTotalEquipos - $cntFirmadasEq;

$cntTotalManten    = count($mantenimientos);
$cntRealizadosM    = count(array_filter($mantenimientos, fn($m) => $m['estado'] === 'Realizado'));
$cntProgramadosM   = count(array_filter($mantenimientos, fn($m) => $m['estado'] === 'Programado'));

$cntTotalCorreos   = count($correos);
$cntActivosCorr    = count(array_filter($correos, fn($c) => $c['estado'] === 'Activo'));
$cntBajaCorr       = count(array_filter($correos, fn($c) => $c['estado'] === 'En Baja'));
$cntEliminCorr     = count(array_filter($correos, fn($c) => $c['estado'] === 'Eliminado'));

$cntTotalLicencias = count($licenciasOffice);
$cntTotalGds       = count($gdsUsuarios);
$cntGdsResp        = count(array_filter($gdsUsuarios, fn($u) => !empty($u['responsiva_pdf_url'])));

$cntTotalPassMov   = count($gdsPasswords);
$cntTotalGrp       = count($grpUsuarios);
$cntGrpResp        = count(array_filter($grpUsuarios, fn($u) => !empty($u['responsiva_pdf_url'])));

// Agrupación de Mantenimientos por Área
$mantenimientosPorArea = [];
foreach ($catalogoAreas as $ar) {
    $mantenimientosPorArea[$ar] = [];
}
foreach ($mantenimientos as $m) {
    $ar = !empty($m['area']) ? trim($m['area']) : 'General';
    if (!isset($mantenimientosPorArea[$ar])) $mantenimientosPorArea[$ar] = [];
    $mantenimientosPorArea[$ar][] = $m;
}

// Mapa de Máquinas / Equipos con su historial y próxima fecha de mantenimiento
$equiposConMantenimiento = [];
foreach ($equiposInventario as $eq) {
    $eqId = $eq['id'];
    $nomEq = strtolower(trim($eq['nombre_equipo']));
    $mantsDelEq = array_values(array_filter($mantenimientos, function($m) use ($eqId, $nomEq) {
        return (!empty($m['equipo_id']) && (int)$m['equipo_id'] === (int)$eqId) ||
               (strtolower(trim($m['nombre_equipo'])) === $nomEq);
    }));
    $proximoMant = !empty($mantsDelEq[0]) ? $mantsDelEq[0] : null;
    $equiposConMantenimiento[] = [
        'equipo' => $eq,
        'mantenimientos' => $mantsDelEq,
        'proximo' => $proximoMant
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría Interna & Control Operativo | <?php echo htmlspecialchars($agenciaNombre); ?></title>
    <?php include_once 'pwa_head.php'; ?>
    <!-- Bootstrap 5 y Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- ExcelJS para Exportación Premium Multi-pestaña -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <style>
        :root {
            --bg-dark: #040d1a;
            --bg-card: #09172c;
            --bg-card-hover: #0d213f;
            --gold-accent: #d4af37;
            --gold-light: #f5df9e;
            --gold-border: rgba(212, 175, 55, 0.4);
            --gold-glow: rgba(212, 175, 55, 0.25);
            --cyan-accent: #38bdf8;
            --cyan-border: rgba(56, 189, 248, 0.4);
            --cyan-glow: rgba(56, 189, 248, 0.25);
            --emerald-accent: #10b981;
            --emerald-border: rgba(16, 185, 129, 0.4);
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 15% 15%, rgba(212, 175, 55, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 85% 20%, rgba(56, 189, 248, 0.12) 0%, transparent 45%),
                radial-gradient(circle at 50% 90%, rgba(9, 23, 44, 0.8) 0%, transparent 60%);
            background-attachment: fixed;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
        }

        /* NAVBAR SUPERIOR */
        .executive-navbar {
            background: rgba(4, 13, 26, 0.95);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--gold-border);
            padding: 14px 24px;
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.6);
        }

        .brand-title {
            font-family: 'Cinzel', serif;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: #ffffff;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ESTILOS DE BOTONES EJECUTIVOS */
        .btn-gold-main {
            background: linear-gradient(135deg, #d4af37 0%, #aa851d 100%);
            color: #040d1a !important;
            border: 1px solid var(--gold-border);
            font-weight: 700;
            font-size: 0.88rem;
            padding: 9px 18px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-gold-main:hover {
            background: linear-gradient(135deg, #f5df9e 0%, #c49d29 100%);
            color: #000000 !important;
            box-shadow: 0 6px 22px rgba(212, 175, 55, 0.5);
            transform: translateY(-2px);
        }

        .btn-cyan-main {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff !important;
            border: 1px solid var(--cyan-border);
            font-weight: 700;
            font-size: 0.88rem;
            padding: 9px 18px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(56, 189, 248, 0.3);
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-cyan-main:hover {
            background: linear-gradient(135deg, #38bdf8 0%, #0284c7 100%);
            box-shadow: 0 6px 22px rgba(56, 189, 248, 0.5);
            transform: translateY(-2px);
        }

        .btn-outline-gold {
            color: var(--gold-light) !important;
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
            cursor: pointer;
        }
        .btn-outline-gold:hover {
            color: #ffffff !important;
            background: rgba(212, 175, 55, 0.22);
            border-color: var(--gold-accent);
            box-shadow: 0 0 14px var(--gold-glow);
            transform: translateY(-1px);
        }

        .btn-action-tile {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #e2e8f0;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 7px 14px;
            border-radius: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .btn-action-tile:hover {
            background: rgba(56, 189, 248, 0.2);
            border-color: var(--cyan-accent);
            color: #ffffff;
            transform: translateY(-1px);
        }
        .btn-action-tile.btn-tile-gold:hover {
            background: rgba(212, 175, 55, 0.25);
            border-color: var(--gold-accent);
            color: #ffffff;
        }
        .btn-action-tile.btn-tile-danger:hover {
            background: rgba(239, 68, 68, 0.25);
            border-color: #ef4444;
            color: #ffffff;
        }

        /* BOTONES INTERACTIVOS GIGANTES */
        .section-header-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            padding: 6px 16px;
            border-radius: 30px;
            margin-bottom: 20px;
        }
        .pill-infra {
            background: rgba(56, 189, 248, 0.12);
            color: #7dd3fc;
            border: 1px solid var(--cyan-border);
        }
        .pill-seguridad {
            background: rgba(212, 175, 55, 0.14);
            color: #f5df9e;
            border: 1px solid var(--gold-border);
        }

        .big-hub-button {
            background: linear-gradient(145deg, #09172c 0%, #061224 100%);
            border: 1.5px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            cursor: pointer;
            text-align: left;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 220px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }
        .big-hub-button::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: all 0.3s ease;
        }
        .big-hub-button:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6);
        }
        .hub-btn-infra:hover {
            border-color: var(--cyan-accent);
            box-shadow: 0 20px 45px rgba(56, 189, 248, 0.25);
        }
        .hub-btn-infra:hover::before {
            background: linear-gradient(90deg, transparent, var(--cyan-accent), transparent);
        }
        .hub-btn-seguridad:hover {
            border-color: var(--gold-accent);
            box-shadow: 0 20px 45px rgba(212, 175, 55, 0.25);
        }
        .hub-btn-seguridad:hover::before {
            background: linear-gradient(90deg, transparent, var(--gold-accent), transparent);
        }

        .hub-icon-box {
            width: 58px;
            height: 58px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        .hub-btn-infra .hub-icon-box {
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
            border: 1px solid var(--cyan-border);
        }
        .hub-btn-seguridad .hub-icon-box {
            background: rgba(212, 175, 55, 0.12);
            color: #d4af37;
            border: 1px solid var(--gold-border);
        }
        .big-hub-button:hover .hub-icon-box {
            transform: scale(1.1) rotate(4deg);
        }

        .hub-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
        }
        .hub-desc {
            font-size: 0.82rem;
            color: #94a3b8;
            line-height: 1.45;
            margin-bottom: 16px;
            flex-grow: 1;
        }

        .hub-stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .hub-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
        }
        .hub-badge.badge-success-glow {
            background: rgba(16, 185, 129, 0.15);
            border-color: rgba(16, 185, 129, 0.4);
            color: #6ee7b7;
        }
        .hub-badge.badge-warning-glow {
            background: rgba(245, 158, 11, 0.15);
            border-color: rgba(245, 158, 11, 0.4);
            color: #fde68a;
        }

        .hub-open-cta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            font-weight: 700;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .hub-btn-infra .hub-open-cta { color: #38bdf8; }
        .hub-btn-seguridad .hub-open-cta { color: #f5df9e; }

        /* VISTAS DE CADA SECCIÓN (TARJETAS DE ACCIÓN - CERO TABLAS) */
        .section-view-panel {
            display: none;
            animation: fadeIn 0.3s ease forwards;
        }
        .section-view-panel.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .sub-header-toolbar {
            background: rgba(9, 23, 44, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid var(--gold-border);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
        }

        /* TARJETAS EJECUTIVAS PARA ELEMENTOS */
        .item-action-card {
            background: linear-gradient(145deg, #08162b 0%, #061122 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.25s ease;
            position: relative;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
        }
        .item-action-card:hover {
            border-color: rgba(56, 189, 248, 0.6);
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5), 0 0 20px rgba(56, 189, 248, 0.15);
        }
        .card-gold-accent:hover {
            border-color: var(--gold-accent) !important;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5), 0 0 20px var(--gold-glow) !important;
        }

        .item-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .item-title-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .item-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #38bdf8;
            flex-shrink: 0;
        }
        .item-icon.icon-gold {
            background: rgba(212, 175, 55, 0.12);
            color: #d4af37;
            border-color: var(--gold-border);
        }

        .item-main-title {
            font-size: 1.02rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0;
            line-height: 1.3;
        }
        .item-sub-title {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 0;
        }

        .item-details-grid {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 10px;
            font-size: 0.78rem;
        }
        .detail-item-label {
            color: #64748b;
            text-transform: uppercase;
            font-size: 0.68rem;
            font-weight: 700;
            margin-bottom: 2px;
            display: block;
        }
        .detail-item-val {
            color: #e2e8f0;
            font-weight: 600;
            word-break: break-all;
        }

        .item-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        /* MODALES */
        .modal-content {
            background-color: #061325;
            border: 1px solid var(--gold-border);
            color: #ffffff;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.8);
            border-radius: 18px;
        }
        .modal-header {
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            padding: 18px 24px;
        }
        .modal-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding: 16px 24px;
        }
        .form-control, .form-select {
            background-color: rgba(13, 30, 54, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.9rem;
        }
        .form-control:focus, .form-select:focus {
            background-color: #0b1e38;
            border-color: var(--gold-accent);
            color: #ffffff;
            box-shadow: 0 0 12px var(--gold-glow);
        }

        /* PILL FILTERS */
        .filter-pill-btn {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .filter-pill-btn:hover {
            color: #ffffff;
            border-color: var(--cyan-accent);
        }
        .filter-pill-btn.active {
            background: rgba(56, 189, 248, 0.2);
            border-color: var(--cyan-accent);
            color: #ffffff;
            box-shadow: 0 0 12px rgba(56, 189, 248, 0.3);
        }

        /* ESTILOS DEL CALENDARIO MENSUAL DE MANTENIMIENTO */
        .calendar-wrapper {
            background: rgba(9, 23, 44, 0.9);
            border: 1px solid var(--gold-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.5);
            margin-bottom: 24px;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 8px;
        }
        .calendar-day-header {
            text-align: center;
            font-weight: 800;
            font-size: 0.78rem;
            color: var(--gold-light);
            padding: 10px 4px;
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid var(--gold-border);
            border-radius: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .calendar-day-cell {
            min-height: 125px;
            background: rgba(13, 30, 54, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 8px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            transition: all 0.2s ease;
        }
        .calendar-day-cell:hover {
            border-color: var(--cyan-accent);
            background: rgba(13, 30, 54, 0.85);
        }
        .calendar-day-cell.day-today {
            border: 1.5px solid var(--gold-accent);
            background: rgba(212, 175, 55, 0.09);
            box-shadow: inset 0 0 15px rgba(212, 175, 55, 0.15);
        }
        .calendar-day-cell.day-other-month {
            opacity: 0.25;
            background: rgba(0, 0, 0, 0.2);
        }
        .calendar-day-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .calendar-day-number {
            font-size: 0.88rem;
            font-weight: 800;
            color: #e2e8f0;
        }
        .btn-cal-add {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            font-size: 0.68rem;
            padding: 1px 5px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-cal-add:hover {
            background: var(--gold-accent);
            color: #000000;
            border-color: var(--gold-accent);
        }

        .calendar-event-badge {
            background: rgba(56, 189, 248, 0.16);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #7dd3fc;
            font-size: 0.72rem;
            padding: 4px 6px;
            border-radius: 6px;
            margin-bottom: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .calendar-event-badge:hover {
            transform: scale(1.02);
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(56, 189, 248, 0.4);
        }
        .calendar-event-badge.badge-realizado {
            background: rgba(16, 185, 129, 0.18);
            border-color: rgba(16, 185, 129, 0.45);
            color: #6ee7b7;
        }
        .calendar-event-badge.badge-proceso {
            background: rgba(56, 189, 248, 0.2);
            border-color: rgba(56, 189, 248, 0.5);
            color: #bae6fd;
        }
        .calendar-event-badge.badge-programado {
            background: rgba(245, 158, 11, 0.18);
            border-color: rgba(245, 158, 11, 0.45);
            color: #fde68a;
        }

        /* VISTA POR ÁREA */
        .area-group-container {
            background: rgba(9, 23, 44, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .area-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .password-peek {
            font-family: monospace;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>

    <!-- NAVBAR SUPERIOR -->
    <header class="executive-navbar">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <a href="menu.php" class="btn-outline-gold" title="Regresar al Menú Principal">
                    <i class="bi bi-arrow-left"></i> Menú
                </a>
                <div class="brand-title">
                    <i class="bi bi-shield-check text-warning"></i>
                    <span>AUDITORÍA INTERNA & CONTROL TI</span>
                </div>
                <span class="badge" style="background: rgba(212, 175, 55, 0.15); color: #f5df9e; border: 1px solid var(--gold-border);">
                    <?php echo htmlspecialchars($agenciaNombre); ?>
                </span>
            </div>

            <!-- BOTONES SUPERIORES DE ACCIÓN -->
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn-gold-main" onclick="exportarAuditoriaCompletaExcel()" title="Descargar Libro Completo con 7 Hojas de Cálculo">
                    <i class="bi bi-file-earmark-excel-fill"></i>
                    <span>Exportar Todo a Excel</span>
                </button>
                <button type="button" class="btn-outline-gold" onclick="window.print()" title="Imprimir informe en formato PDF">
                    <i class="bi bi-printer-fill"></i> Imprimir PDF
                </button>
            </div>
        </div>
    </header>

    <!-- ALERTAS DEL SISTEMA -->
    <div class="container-fluid px-4 mt-3">
        <?php if (!empty($mensajeAlerta)): ?>
        <div class="alert alert-<?php echo $tipoAlerta; ?> alert-dismissible fade show d-flex align-items-center gap-2" role="alert" style="background: rgba(13, 30, 54, 0.95); border: 1px solid var(--gold-border); color: #ffffff;">
            <i class="bi bi-info-circle-fill text-warning fs-5"></i>
            <div><?php echo htmlspecialchars($mensajeAlerta); ?></div>
            <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
    </div>

    <!-- CONTENEDOR PRINCIPAL -->
    <main class="container-fluid px-4 py-3">

        <!-- ===================================================================
             VISTA 0: TABLERO PRINCIPAL DE BOTONES (HUB DE NAVEGACIÓN)
             =================================================================== -->
        <section id="vista-hub" class="section-view-panel active">
            
            <div class="text-center mb-4">
                <h2 style="font-family: 'Cinzel', serif; font-weight: 700; color: #ffffff; letter-spacing: 1px;">
                    Panel de Control y Auditoría Operativa
                </h2>
                <p class="text-secondary mb-0" style="font-size: 0.95rem;">
                    Seleccione una de las secciones a continuación para gestionar responsivas, calendarios de mantenimiento, credenciales y expedientes.
                </p>
            </div>

            <!-- SECCIÓN 1: GESTIÓN DE INFRAESTRUCTURA -->
            <div class="mb-5">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="section-header-pill pill-infra">
                        <i class="bi bi-hdd-network-fill"></i> 1. Gestión de Infraestructura
                    </span>
                    <span class="text-secondary small">4 Módulos Operativos</span>
                </div>

                <div class="row g-4">
                    <!-- BOTÓN 1: RESPONSIVAS DE EQUIPOS -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="big-hub-button hub-btn-infra" onclick="abrirModulo('responsivas')">
                            <div>
                                <div class="hub-icon-box">
                                    <i class="bi bi-laptop"></i>
                                </div>
                                <div class="hub-title">Responsivas de Equipos</div>
                                <div class="hub-desc">
                                    Generación de responsivas físicas de inventario para firma y subida del expediente firmado en PDF.
                                </div>
                                <div class="hub-stats-row">
                                    <span class="hub-badge"><?php echo $cntTotalEquipos; ?> Equipos</span>
                                    <span class="hub-badge badge-success-glow"><?php echo $cntFirmadasEq; ?> Firmadas</span>
                                    <span class="hub-badge badge-warning-glow"><?php echo $cntPendientesEq; ?> Pendientes</span>
                                </div>
                            </div>
                            <div class="hub-open-cta">
                                <span>Abrir Módulo</span>
                                <i class="bi bi-arrow-right-circle-fill fs-5"></i>
                            </div>
                        </div>
                    </div>

                    <!-- BOTÓN 2: PLANES DE MANTENIMIENTO -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="big-hub-button hub-btn-infra" onclick="abrirModulo('mantenimiento')">
                            <div>
                                <div class="hub-icon-box">
                                    <i class="bi bi-calendar-check-fill"></i>
                                </div>
                                <div class="hub-title">Planes de Mantenimiento</div>
                                <div class="hub-desc">
                                    Calendario mensual, asignación de días por máquina o área, expedientes de servicio y evidencia en PDF.
                                </div>
                                <div class="hub-stats-row">
                                    <span class="hub-badge"><?php echo $cntTotalManten; ?> Programados</span>
                                    <span class="hub-badge badge-success-glow"><?php echo $cntRealizadosM; ?> Realizados</span>
                                </div>
                            </div>
                            <div class="hub-open-cta">
                                <span>Abrir Calendario & Planes</span>
                                <i class="bi bi-arrow-right-circle-fill fs-5"></i>
                            </div>
                        </div>
                    </div>

                    <!-- BOTÓN 3: CONTROL DE CORREOS -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="big-hub-button hub-btn-infra" onclick="abrirModulo('correos')">
                            <div>
                                <div class="hub-icon-box">
                                    <i class="bi bi-envelope-at-fill"></i>
                                </div>
                                <div class="hub-title">Control de Correos</div>
                                <div class="hub-desc">
                                    Matriz corporativa de cuentas de correo, gestión de bajas, eliminaciones y sincronización directa.
                                </div>
                                <div class="hub-stats-row">
                                    <span class="hub-badge badge-success-glow"><?php echo $cntActivosCorr; ?> Activos</span>
                                    <span class="hub-badge badge-warning-glow"><?php echo $cntBajaCorr; ?> En Baja</span>
                                    <span class="hub-badge"><?php echo $cntEliminCorr; ?> Eliminados</span>
                                </div>
                            </div>
                            <div class="hub-open-cta">
                                <span>Abrir Correos</span>
                                <i class="bi bi-arrow-right-circle-fill fs-5"></i>
                            </div>
                        </div>
                    </div>

                    <!-- BOTÓN 4: LICENCIAS DE OFFICE -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="big-hub-button hub-btn-infra" onclick="abrirModulo('licencias')">
                            <div>
                                <div class="hub-icon-box">
                                    <i class="bi bi-microsoft"></i>
                                </div>
                                <div class="hub-title">Licencias de Office</div>
                                <div class="hub-desc">
                                    Product Keys corporativas, cuentas Microsoft vinculadas, equipos asignados y estatus de vigencia.
                                </div>
                                <div class="hub-stats-row">
                                    <span class="hub-badge badge-success-glow"><?php echo $cntTotalLicencias; ?> Licencias Activas</span>
                                </div>
                            </div>
                            <div class="hub-open-cta">
                                <span>Abrir Licencias</span>
                                <i class="bi bi-arrow-right-circle-fill fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 2: SEGURIDAD DE LA INFORMACIÓN -->
            <div>
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="section-header-pill pill-seguridad">
                        <i class="bi bi-shield-lock-fill"></i> 2. Seguridad de la Información
                    </span>
                    <span class="text-secondary small">3 Módulos de Accesos & Criptografía</span>
                </div>

                <div class="row g-4">
                    <!-- BOTÓN 5: USUARIOS GDS -->
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="big-hub-button hub-btn-seguridad" onclick="abrirModulo('gds_usuarios')">
                            <div>
                                <div class="hub-icon-box">
                                    <i class="bi bi-person-badge-fill"></i>
                                </div>
                                <div class="hub-title">Usuarios GDS (DMS)</div>
                                <div class="hub-desc">
                                    Asignación de credenciales del sistema central GDS, generación de responsiva formal y subida de PDF firmado.
                                </div>
                                <div class="hub-stats-row">
                                    <span class="hub-badge"><?php echo $cntTotalGds; ?> Usuarios Asignados</span>
                                    <span class="hub-badge badge-success-glow"><?php echo $cntGdsResp; ?> Responsivas OK</span>
                                </div>
                            </div>
                            <div class="hub-open-cta">
                                <span>Abrir Usuarios GDS</span>
                                <i class="bi bi-arrow-right-circle-fill fs-5"></i>
                            </div>
                        </div>
                    </div>

                    <!-- BOTÓN 6: PASSWORD GDS (MOVIMIENTOS CRÍTICOS) -->
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="big-hub-button hub-btn-seguridad" onclick="abrirModulo('gds_passwords')">
                            <div>
                                <div class="hub-icon-box">
                                    <i class="bi bi-key-fill"></i>
                                </div>
                                <div class="hub-title">Password GDS (Movimientos)</div>
                                <div class="hub-desc">
                                    Claves de cancelación, notas de crédito y descuentos especiales con acta responsiva para gerentes.
                                </div>
                                <div class="hub-stats-row">
                                    <span class="hub-badge badge-warning-glow"><?php echo $cntTotalPassMov; ?> Claves Críticas</span>
                                </div>
                            </div>
                            <div class="hub-open-cta">
                                <span>Abrir Claves</span>
                                <i class="bi bi-arrow-right-circle-fill fs-5"></i>
                            </div>
                        </div>
                    </div>

                    <!-- BOTÓN 7: CONTROL DE USUARIOS GRP -->
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="big-hub-button hub-btn-seguridad" onclick="abrirModulo('grp_usuarios')">
                            <div>
                                <div class="hub-icon-box">
                                    <i class="bi bi-shield-shaded"></i>
                                </div>
                                <div class="hub-title">Usuarios & Roles GRP</div>
                                <div class="hub-desc">
                                    Asignación de usuarios, contraseñas y roles autorizados en GRP con generación y custodia de responsiva.
                                </div>
                                <div class="hub-stats-row">
                                    <span class="hub-badge"><?php echo $cntTotalGrp; ?> Usuarios GRP</span>
                                    <span class="hub-badge badge-success-glow"><?php echo $cntGrpResp; ?> Responsivas OK</span>
                                </div>
                            </div>
                            <div class="hub-open-cta">
                                <span>Abrir Usuarios GRP</span>
                                <i class="bi bi-arrow-right-circle-fill fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </section>


        <!-- ===================================================================
             SUB-MÓDULO 1: RESPONSIVAS DE EQUIPOS (BASADO EN TARJETAS Y BOTONES)
             =================================================================== -->
        <section id="vista-responsivas" class="section-view-panel">
            <div class="sub-header-toolbar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn-outline-gold" onclick="volverAlHub()">
                        <i class="bi bi-arrow-left"></i> Volver a Botones
                    </button>
                    <div>
                        <h4 class="m-0 fw-bold text-white"><i class="bi bi-laptop text-info me-2"></i> Responsivas de Equipos</h4>
                        <span class="text-secondary small">Equipos de cómputo vinculados al Inventario</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="input-group" style="width: 260px;">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                        <input type="text" id="busq-equipos" class="form-control form-control-sm" placeholder="Buscar equipo o usuario..." onkeyup="filtrarCards('card-equipo', this.value)">
                    </div>

                    <button type="button" class="filter-pill-btn active" onclick="filtrarEstadoResp(this, 'todos')">Todos (<?php echo $cntTotalEquipos; ?>)</button>
                    <button type="button" class="filter-pill-btn" onclick="filtrarEstadoResp(this, 'firmadas')">Firmadas (<?php echo $cntFirmadasEq; ?>)</button>
                    <button type="button" class="filter-pill-btn" onclick="filtrarEstadoResp(this, 'pendientes')">Pendientes (<?php echo $cntPendientesEq; ?>)</button>

                    <button type="button" class="btn-cyan-main btn-sm ms-2" onclick="exportarSeccionExcel('responsivas')">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </button>
                </div>
            </div>

            <!-- GRID DE TARJETAS DE EQUIPOS (NO TABLAS) -->
            <div class="row g-3" id="contenedor-equipos">
                <?php if (empty($equiposInventario)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-laptop text-secondary fs-1 d-block mb-3"></i>
                    <h5 class="text-secondary">No hay equipos registrados en el inventario.</h5>
                </div>
                <?php else: ?>
                <?php foreach ($equiposInventario as $eq): 
                    $tieneResp = !empty($eq['responsiva_url']);
                ?>
                <div class="col-12 col-md-6 col-lg-4 col-xl-3 card-equipo" 
                     data-search="<?php echo htmlspecialchars(strtolower($eq['nombre_equipo'] . ' ' . $eq['usuario'] . ' ' . $eq['departamento'] . ' ' . $eq['serie'])); ?>"
                     data-estado="<?php echo $tieneResp ? 'firmadas' : 'pendientes'; ?>">
                    <div class="item-action-card">
                        <div>
                            <div class="item-card-header">
                                <div class="item-title-box">
                                    <div class="item-icon">
                                        <i class="bi bi-pc-display"></i>
                                    </div>
                                    <div>
                                        <h6 class="item-main-title"><?php echo htmlspecialchars($eq['nombre_equipo']); ?></h6>
                                        <p class="item-sub-title"><?php echo htmlspecialchars($eq['departamento'] ?: 'General'); ?></p>
                                    </div>
                                </div>
                                <?php if ($tieneResp): ?>
                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50"><i class="bi bi-check-circle-fill"></i> Firmada</span>
                                <?php else: ?>
                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50"><i class="bi bi-clock-history"></i> Pendiente</span>
                                <?php endif; ?>
                            </div>

                            <div class="item-details-grid">
                                <div>
                                    <span class="detail-item-label">Responsable</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($eq['usuario'] ?: 'Sin Asignar'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Puesto</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($eq['puesto'] ?: 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Marca / Modelo</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">No. Serie</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($eq['serie'] ?: 'S/N'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- BOTONES DE ACCIÓN PARA EL EQUIPO -->
                        <div class="item-card-actions">
                            <!-- BOTÓN GENERAR E IMPRIMIR RESPONSIVA CON DATOS -->
                            <a href="generar_responsiva.php?id=<?php echo $eq['id']; ?>&sec=equipos_vw" target="_blank" class="btn-action-tile btn-tile-gold" title="Imprimir Responsiva Oficial con datos para firma física">
                                <i class="bi bi-printer-fill"></i> Imprimir Responsiva
                            </a>

                            <!-- BOTÓN SUBIR ARCHIVO FIRMADO -->
                            <button type="button" class="btn-action-tile" onclick="abrirModalSubirPdf('equipo', <?php echo $eq['id']; ?>, '<?php echo htmlspecialchars($eq['nombre_equipo']); ?>')" title="Subir expediente PDF firmado">
                                <i class="bi bi-cloud-arrow-up-fill"></i> Subir PDF
                            </button>

                            <!-- BOTÓN VER PDF SI YA ESTÁ SUBIDO -->
                            <?php if ($tieneResp): ?>
                            <button type="button" class="btn-action-tile" onclick="verPdfModal('<?php echo htmlspecialchars($eq['responsiva_url']); ?>', 'Responsiva - <?php echo htmlspecialchars($eq['nombre_equipo']); ?>')" title="Ver PDF firmado">
                                <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Ver PDF
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>


        <!-- ===================================================================
             SUB-MÓDULO 2: PLANES DE MANTENIMIENTO (CALENDARIO, DÍAS, EXPEDIENTE PDF, MÁQUINAS Y ÁREAS)
             =================================================================== -->
        <section id="vista-mantenimiento" class="section-view-panel">
            <div class="sub-header-toolbar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn-outline-gold" onclick="volverAlHub()">
                        <i class="bi bi-arrow-left"></i> Volver a Botones
                    </button>
                    <div>
                        <h4 class="m-0 fw-bold text-white"><i class="bi bi-calendar-check-fill text-info me-2"></i> Planes de Mantenimiento</h4>
                        <span class="text-secondary small">Calendario interactivo, asignación de días, expedientes oficiales PDF por máquina y área</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn-gold-main btn-sm" onclick="abrirModalMantenimiento(0)">
                        <i class="bi bi-calendar-plus-fill"></i> Asignar Día de Mantenimiento
                    </button>
                    <button type="button" class="btn-cyan-main btn-sm ms-2" onclick="exportarSeccionExcel('mantenimiento')">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </button>
                </div>
            </div>

            <!-- SELECTOR DE VISTAS CON BOTONES INTERACTIVOS -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 rounded-4 mb-4 border border-secondary border-opacity-25" style="background: rgba(13, 30, 54, 0.7);">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="text-secondary small fw-bold text-uppercase me-2"><i class="bi bi-eye-fill text-warning me-1"></i> Modo de Vista:</span>
                    <button type="button" class="filter-pill-btn active" id="btn-mant-cal" onclick="cambiarSubvistaMantenimiento('cal')">
                        <i class="bi bi-calendar3"></i> 🗓️ Ver Calendario
                    </button>
                    <button type="button" class="filter-pill-btn" id="btn-mant-area" onclick="cambiarSubvistaMantenimiento('area')">
                        <i class="bi bi-building"></i> 🏢 Ver por Área
                    </button>
                    <button type="button" class="filter-pill-btn" id="btn-mant-maquina" onclick="cambiarSubvistaMantenimiento('maquina')">
                        <i class="bi bi-pc-display"></i> 💻 Ver por Máquina
                    </button>
                    <button type="button" class="filter-pill-btn" id="btn-mant-fichas" onclick="cambiarSubvistaMantenimiento('fichas')">
                        <i class="bi bi-grid-fill"></i> 📋 Todas las Fichas
                    </button>
                </div>

                <!-- Búsqueda rápida general -->
                <div class="input-group" style="max-width: 280px;">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" id="busq-mantenimiento-gen" class="form-control form-control-sm" placeholder="Buscar máquina o área..." onkeyup="filtrarCards('card-mantenimiento', this.value)">
                </div>
            </div>

            <!-- ===============================================================
                 SUB-VISTA 1: CALENDARIO MENSUAL INTERACTIVO
                 =============================================================== -->
            <div id="mant-subview-cal" class="mant-subview">
                <div class="calendar-wrapper">
                    <!-- BARRA DE CONTROL DEL CALENDARIO -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pb-3 mb-3 border-bottom border-secondary border-opacity-25">
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn-action-tile" onclick="calMesAnterior()" title="Mes Anterior">
                                <i class="bi bi-chevron-left"></i> Anterior
                            </button>
                            <h4 id="cal-titulo-mes" class="text-warning fw-bold m-0 text-uppercase px-2" style="font-family: 'Cinzel', serif; letter-spacing: 1px;">
                                Noviembre 2026
                            </h4>
                            <button type="button" class="btn-action-tile" onclick="calMesSiguiente()" title="Mes Siguiente">
                                Siguiente <i class="bi bi-chevron-right"></i>
                            </button>
                            <button type="button" class="btn-outline-gold btn-sm ms-2" onclick="calIrHoy()">
                                Hoy
                            </button>
                        </div>

                        <!-- FILTRO POR ÁREA EN EL CALENDARIO -->
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-secondary small fw-bold">Filtrar Área:</span>
                            <select id="cal-filtro-area" class="form-select form-select-sm" style="min-width: 180px;" onchange="renderCalendario()">
                                <option value="todas">-- Todas las Áreas --</option>
                                <?php foreach ($catalogoAreas as $ar): ?>
                                <option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-gold-main btn-sm py-1" onclick="abrirModalMantenimiento(0)">
                                <i class="bi bi-plus-lg"></i> Asignar Día
                            </button>
                        </div>
                    </div>

                    <!-- CUADRÍCULA DE DÍAS DE LA SEMANA -->
                    <div class="calendar-grid mb-2">
                        <div class="calendar-day-header text-danger">Domingo</div>
                        <div class="calendar-day-header">Lunes</div>
                        <div class="calendar-day-header">Martes</div>
                        <div class="calendar-day-header">Miércoles</div>
                        <div class="calendar-day-header">Jueves</div>
                        <div class="calendar-day-header">Viernes</div>
                        <div class="calendar-day-header text-info">Sábado</div>
                    </div>

                    <!-- CONTENEDOR DINÁMICO DE CELDAS DE DÍAS -->
                    <div class="calendar-grid" id="cal-grid-dias">
                        <!-- Generado por JavaScript renderCalendario() -->
                    </div>
                </div>
            </div>

            <!-- ===============================================================
                 SUB-VISTA 2: VER POR ÁREA
                 =============================================================== -->
            <div id="mant-subview-area" class="mant-subview" style="display: none;">
                <?php foreach ($catalogoAreas as $ar): 
                    $mantsArea = $mantenimientosPorArea[$ar] ?? [];
                    $cntTotalAr = count($mantsArea);
                    $cntRealizAr = count(array_filter($mantsArea, fn($m) => $m['estado'] === 'Realizado'));
                    $porcCumpl = ($cntTotalAr > 0) ? round(($cntRealizAr / $cntTotalAr) * 100) : 0;
                ?>
                <div class="area-group-container">
                    <div class="area-header-bar">
                        <div class="d-flex align-items-center gap-3">
                            <div class="item-icon">
                                <i class="bi bi-building"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-white m-0">Área: <?php echo htmlspecialchars($ar); ?></h5>
                                <span class="text-secondary small">
                                    <?php echo $cntTotalAr; ?> Mantenimientos registrados (<?php echo $cntRealizAr; ?> Realizados, <?php echo $cntTotalAr - $cntRealizAr; ?> Pendientes)
                                </span>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3">
                            <div class="text-end d-none d-md-block" style="min-width: 140px;">
                                <div class="text-secondary small fw-bold">Cumplimiento: <?php echo $porcCumpl; ?>%</div>
                                <div class="progress" style="height: 6px; background: rgba(255,255,255,0.1);">
                                    <div class="progress-bar bg-success" style="width: <?php echo $porcCumpl; ?>%"></div>
                                </div>
                            </div>

                            <button type="button" class="btn-outline-gold btn-sm" onclick="asignarMantenimientoArea('<?php echo htmlspecialchars($ar); ?>')">
                                <i class="bi bi-plus-circle"></i> Asignar en esta Área
                            </button>
                        </div>
                    </div>

                    <!-- MÁQUINAS DEL ÁREA -->
                    <div class="row g-3">
                        <?php if (empty($mantsArea)): ?>
                        <div class="col-12 py-3 text-center text-secondary small">
                            <i class="bi bi-calendar-x me-1"></i> No hay planes de mantenimiento registrados para esta área actualmente.
                            <button type="button" class="btn btn-sm btn-link text-warning" onclick="asignarMantenimientoArea('<?php echo htmlspecialchars($ar); ?>')">
                                Programar día ahora
                            </button>
                        </div>
                        <?php else: ?>
                        <?php foreach ($mantsArea as $m): 
                            $tieneEvid = !empty($m['evidencia_pdf_url']);
                            $stClass = ($m['estado'] === 'Realizado') ? 'success' : (($m['estado'] === 'En Proceso') ? 'info' : 'warning');
                        ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="item-action-card">
                                <div>
                                    <div class="item-card-header">
                                        <div class="item-title-box">
                                            <div class="item-icon">
                                                <i class="bi bi-pc-display"></i>
                                            </div>
                                            <div>
                                                <h6 class="item-main-title"><?php echo htmlspecialchars($m['nombre_equipo']); ?></h6>
                                                <p class="item-sub-title"><?php echo htmlspecialchars($m['usuario_responsable'] ?: 'Sin Asignar'); ?></p>
                                            </div>
                                        </div>
                                        <span class="badge bg-<?php echo $stClass; ?> bg-opacity-25 text-<?php echo $stClass; ?> border border-<?php echo $stClass; ?> border-opacity-50">
                                            <?php echo htmlspecialchars($m['estado']); ?>
                                        </span>
                                    </div>

                                    <div class="item-details-grid">
                                        <div>
                                            <span class="detail-item-label">Día Asignado</span>
                                            <span class="detail-item-val fw-bold text-warning"><?php echo htmlspecialchars($m['fecha_programada']); ?></span>
                                        </div>
                                        <div>
                                            <span class="detail-item-label">Tipo</span>
                                            <span class="detail-item-val"><?php echo htmlspecialchars($m['tipo_mantenimiento']); ?></span>
                                        </div>
                                        <div>
                                            <span class="detail-item-label">Frecuencia</span>
                                            <span class="detail-item-val"><?php echo htmlspecialchars($m['frecuencia'] ?: 'Semestral'); ?></span>
                                        </div>
                                        <div>
                                            <span class="detail-item-label">Técnico</span>
                                            <span class="detail-item-val"><?php echo htmlspecialchars($m['tecnico_responsable'] ?: 'Sistemas'); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- BOTONES DE ACCIÓN PARA MANTENIMIENTO DEL ÁREA -->
                                <div class="item-card-actions">
                                    <!-- BOTÓN IMPRIMIR EXPEDIENTE OFICIAL DE MANTENIMIENTO (PDF) -->
                                    <a href="generar_expediente_mantenimiento.php?id=<?php echo $m['id']; ?>" target="_blank" class="btn-action-tile btn-tile-gold" title="Imprimir / Exportar Expediente Oficial de Mantenimiento PDF">
                                        <i class="bi bi-printer-fill"></i> Imprimir Expediente
                                    </a>

                                    <!-- BOTÓN SUBIR EVIDENCIA PDF -->
                                    <button type="button" class="btn-action-tile" onclick="abrirModalSubirPdf('mantenimiento', <?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['nombre_equipo']); ?>')">
                                        <i class="bi bi-cloud-arrow-up-fill"></i> Subir PDF
                                    </button>

                                    <!-- BOTÓN VER EVIDENCIA PDF SI EXISTE -->
                                    <?php if ($tieneEvid): ?>
                                    <button type="button" class="btn-action-tile" onclick="verPdfModal('<?php echo htmlspecialchars($m['evidencia_pdf_url']); ?>', 'Evidencia - <?php echo htmlspecialchars($m['nombre_equipo']); ?>')">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Ver PDF
                                    </button>
                                    <?php endif; ?>

                                    <!-- BOTÓN EDITAR / REASIGNAR DÍA -->
                                    <button type="button" class="btn-action-tile ms-auto" onclick='editarMantenimiento(<?php echo json_encode($m); ?>)' title="Cambiar fecha o datos">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ===============================================================
                 SUB-VISTA 3: VER POR MÁQUINA / EQUIPO
                 =============================================================== -->
            <div id="mant-subview-maquina" class="mant-subview" style="display: none;">
                <div class="row g-3">
                    <?php if (empty($equiposConMantenimiento)): ?>
                    <div class="col-12 py-5 text-center text-secondary">
                        <i class="bi bi-pc-display fs-1 d-block mb-3"></i>
                        No se encontraron máquinas en el inventario.
                    </div>
                    <?php else: ?>
                    <?php foreach ($equiposConMantenimiento as $em): 
                        $eq = $em['equipo'];
                        $mProximo = $em['proximo'];
                        $tienePlan = !empty($mProximo);
                        $tieneEvid = $tienePlan && !empty($mProximo['evidencia_pdf_url']);
                        $stClass = $tienePlan ? (($mProximo['estado'] === 'Realizado') ? 'success' : (($mProximo['estado'] === 'En Proceso') ? 'info' : 'warning')) : 'secondary';
                    ?>
                    <div class="col-12 col-md-6 col-lg-4 col-xl-3 card-equipo-mant" data-search="<?php echo htmlspecialchars(strtolower($eq['nombre_equipo'] . ' ' . $eq['usuario'] . ' ' . $eq['departamento'] . ' ' . $eq['serie'])); ?>">
                        <div class="item-action-card">
                            <div>
                                <div class="item-card-header">
                                    <div class="item-title-box">
                                        <div class="item-icon">
                                            <i class="bi bi-laptop"></i>
                                        </div>
                                        <div>
                                            <h6 class="item-main-title"><?php echo htmlspecialchars($eq['nombre_equipo']); ?></h6>
                                            <p class="item-sub-title"><?php echo htmlspecialchars($eq['departamento'] ?: 'General'); ?></p>
                                        </div>
                                    </div>
                                    <?php if ($tienePlan): ?>
                                    <span class="badge bg-<?php echo $stClass; ?> bg-opacity-25 text-<?php echo $stClass; ?> border border-<?php echo $stClass; ?> border-opacity-50">
                                        <?php echo htmlspecialchars($mProximo['estado']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-50">
                                        Sin Programar
                                    </span>
                                    <?php endif; ?>
                                </div>

                                <div class="item-details-grid">
                                    <div>
                                        <span class="detail-item-label">Responsable</span>
                                        <span class="detail-item-val"><?php echo htmlspecialchars($eq['usuario'] ?: 'Sin Asignar'); ?></span>
                                    </div>
                                    <div>
                                        <span class="detail-item-label">No. Serie</span>
                                        <span class="detail-item-val"><?php echo htmlspecialchars($eq['serie'] ?: 'S/N'); ?></span>
                                    </div>
                                    <div class="grid-span-2" style="grid-column: span 2;">
                                        <span class="detail-item-label">Día de Mantenimiento</span>
                                        <span class="detail-item-val fw-bold text-warning">
                                            <?php echo $tienePlan ? htmlspecialchars($mProximo['fecha_programada']) . ' (' . htmlspecialchars($mProximo['tipo_mantenimiento']) . ')' : 'No programado'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- BOTONES DE ACCIÓN POR MÁQUINA -->
                            <div class="item-card-actions">
                                <!-- BOTÓN ASIGNAR / CAMBIAR DÍA -->
                                <button type="button" class="btn-action-tile btn-tile-gold" onclick="asignarDiaMaquina(<?php echo $eq['id']; ?>, '<?php echo htmlspecialchars($eq['nombre_equipo']); ?>', '<?php echo htmlspecialchars($eq['departamento']); ?>', '<?php echo htmlspecialchars($eq['usuario']); ?>')">
                                    <i class="bi bi-calendar-event"></i> <?php echo $tienePlan ? 'Reasignar Día' : 'Asignar Día'; ?>
                                </button>

                                <!-- BOTÓN IMPRIMIR EXPEDIENTE SI TIENE MANTENIMIENTO -->
                                <?php if ($tienePlan): ?>
                                <a href="generar_expediente_mantenimiento.php?id=<?php echo $mProximo['id']; ?>" target="_blank" class="btn-action-tile" title="Imprimir / Exportar Expediente Oficial de Mantenimiento PDF">
                                    <i class="bi bi-printer-fill text-warning"></i> Expediente
                                </a>

                                <button type="button" class="btn-action-tile" onclick="abrirModalSubirPdf('mantenimiento', <?php echo $mProximo['id']; ?>, '<?php echo htmlspecialchars($mProximo['nombre_equipo']); ?>')" title="Subir Evidencia PDF">
                                    <i class="bi bi-cloud-arrow-up-fill"></i> Subir
                                </button>

                                <?php if ($tieneEvid): ?>
                                <button type="button" class="btn-action-tile" onclick="verPdfModal('<?php echo htmlspecialchars($mProximo['evidencia_pdf_url']); ?>', 'Evidencia - <?php echo htmlspecialchars($mProximo['nombre_equipo']); ?>')" title="Ver Evidencia PDF">
                                    <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Ver PDF
                                </button>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===============================================================
                 SUB-VISTA 4: TODAS LAS FICHAS DE MANTENIMIENTO
                 =============================================================== -->
            <div id="mant-subview-fichas" class="mant-subview" style="display: none;">
                <div class="row g-3">
                    <?php if (empty($mantenimientos)): ?>
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-calendar-x text-secondary fs-1 d-block mb-3"></i>
                        <h5 class="text-secondary">No hay mantenimientos programados.</h5>
                    </div>
                    <?php else: ?>
                    <?php foreach ($mantenimientos as $m): 
                        $tieneEvid = !empty($m['evidencia_pdf_url']);
                        $stClass = ($m['estado'] === 'Realizado') ? 'success' : (($m['estado'] === 'En Proceso') ? 'info' : 'warning');
                    ?>
                    <div class="col-12 col-md-6 col-lg-4 card-mantenimiento" data-search="<?php echo htmlspecialchars(strtolower($m['nombre_equipo'] . ' ' . $m['area'] . ' ' . $m['usuario_responsable'] . ' ' . $m['tecnico_responsable'])); ?>">
                        <div class="item-action-card">
                            <div>
                                <div class="item-card-header">
                                    <div class="item-title-box">
                                        <div class="item-icon">
                                            <i class="bi bi-tools"></i>
                                        </div>
                                        <div>
                                            <h6 class="item-main-title"><?php echo htmlspecialchars($m['nombre_equipo']); ?></h6>
                                            <p class="item-sub-title"><?php echo htmlspecialchars($m['area']); ?> | <?php echo htmlspecialchars($m['tipo_mantenimiento']); ?></p>
                                        </div>
                                    </div>
                                    <span class="badge bg-<?php echo $stClass; ?> bg-opacity-25 text-<?php echo $stClass; ?> border border-<?php echo $stClass; ?> border-opacity-50">
                                        <?php echo htmlspecialchars($m['estado']); ?>
                                    </span>
                                </div>

                                <div class="item-details-grid">
                                    <div>
                                        <span class="detail-item-label">Fecha Programada</span>
                                        <span class="detail-item-val"><?php echo htmlspecialchars($m['fecha_programada']); ?></span>
                                    </div>
                                    <div>
                                        <span class="detail-item-label">Frecuencia</span>
                                        <span class="detail-item-val"><?php echo htmlspecialchars($m['frecuencia'] ?: 'Semestral'); ?></span>
                                    </div>
                                    <div>
                                        <span class="detail-item-label">Técnico</span>
                                        <span class="detail-item-val"><?php echo htmlspecialchars($m['tecnico_responsable'] ?: 'Sistemas'); ?></span>
                                    </div>
                                    <div>
                                        <span class="detail-item-label">Responsable</span>
                                        <span class="detail-item-val"><?php echo htmlspecialchars($m['usuario_responsable'] ?: 'N/A'); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($m['observaciones'])): ?>
                                <p class="text-secondary small mb-3 fst-italic" style="font-size: 0.78rem;">
                                    "<?php echo htmlspecialchars($m['observaciones']); ?>"
                                </p>
                                <?php endif; ?>
                            </div>

                            <!-- BOTONES DE ACCIÓN PARA MANTENIMIENTO -->
                            <div class="item-card-actions">
                                <!-- BOTÓN IMPRIMIR EXPEDIENTE OFICIAL DE MANTENIMIENTO (PDF) -->
                                <a href="generar_expediente_mantenimiento.php?id=<?php echo $m['id']; ?>" target="_blank" class="btn-action-tile btn-tile-gold" title="Imprimir / Exportar Expediente Oficial de Mantenimiento PDF">
                                    <i class="bi bi-printer-fill"></i> Imprimir Expediente
                                </a>

                                <!-- BOTÓN SUBIR EVIDENCIA PDF -->
                                <button type="button" class="btn-action-tile" onclick="abrirModalSubirPdf('mantenimiento', <?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['nombre_equipo']); ?>')">
                                    <i class="bi bi-cloud-arrow-up-fill"></i> Subir PDF
                                </button>

                                <?php if ($tieneEvid): ?>
                                <button type="button" class="btn-action-tile" onclick="verPdfModal('<?php echo htmlspecialchars($m['evidencia_pdf_url']); ?>', 'Evidencia - <?php echo htmlspecialchars($m['nombre_equipo']); ?>')">
                                    <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Ver PDF
                                </button>
                                <?php endif; ?>

                                <button type="button" class="btn-action-tile" onclick='editarMantenimiento(<?php echo json_encode($m); ?>)'>
                                    <i class="bi bi-pencil-square"></i> Editar
                                </button>

                                <?php if ($puedeEliminar): ?>
                                <button type="button" class="btn-action-tile btn-tile-danger ms-auto" onclick="confirmarEliminar('mantenimiento', <?php echo $m['id']; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>


        <!-- ===================================================================
             SUB-MÓDULO 3: CONTROL DE CORREOS (BASADO EN TARJETAS Y BOTONES)
             =================================================================== -->
        <section id="vista-correos" class="section-view-panel">
            <div class="sub-header-toolbar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn-outline-gold" onclick="volverAlHub()">
                        <i class="bi bi-arrow-left"></i> Volver a Botones
                    </button>
                    <div>
                        <h4 class="m-0 fw-bold text-white"><i class="bi bi-envelope-at-fill text-info me-2"></i> Control de Correos</h4>
                        <span class="text-secondary small">Cuentas corporativas, bajas y eliminaciones</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="input-group" style="width: 240px;">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control form-control-sm" placeholder="Buscar correo o persona..." onkeyup="filtrarCards('card-correo', this.value)">
                    </div>

                    <button type="button" class="btn-gold-main btn-sm" onclick="abrirModalCorreo(0)">
                        <i class="bi bi-plus-circle-fill"></i> Nuevo Correo
                    </button>
                    <button type="button" class="btn-outline-gold btn-sm" onclick="sincronizarCorreos()">
                        <i class="bi bi-arrow-repeat"></i> Sincronizar
                    </button>
                    <button type="button" class="btn-cyan-main btn-sm ms-2" onclick="exportarSeccionExcel('correos')">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </button>
                </div>
            </div>

            <!-- GRID DE TARJETAS DE CORREOS (NO TABLAS) -->
            <div class="row g-3">
                <?php if (empty($correos)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-envelope-x text-secondary fs-1 d-block mb-3"></i>
                    <h5 class="text-secondary">No hay correos registrados.</h5>
                    <button type="button" class="btn-gold-main mt-2" onclick="sincronizarCorreos()">
                        <i class="bi bi-arrow-repeat"></i> Sincronizar Automáticamente
                    </button>
                </div>
                <?php else: ?>
                <?php foreach ($correos as $c): 
                    $estCorr = $c['estado'];
                    $badgeCls = ($estCorr === 'Activo') ? 'success' : (($estCorr === 'En Baja') ? 'warning' : 'danger');
                ?>
                <div class="col-12 col-md-6 col-lg-4 col-xl-3 card-correo" data-search="<?php echo htmlspecialchars(strtolower($c['correo'] . ' ' . $c['nombre_colaborador'] . ' ' . $c['area'] . ' ' . $c['puesto'])); ?>">
                    <div class="item-action-card">
                        <div>
                            <div class="item-card-header">
                                <div class="item-title-box">
                                    <div class="item-icon">
                                        <i class="bi bi-envelope-check"></i>
                                    </div>
                                    <div style="overflow: hidden;">
                                        <h6 class="item-main-title text-truncate" title="<?php echo htmlspecialchars($c['correo']); ?>">
                                            <?php echo htmlspecialchars($c['correo']); ?>
                                        </h6>
                                        <p class="item-sub-title text-truncate"><?php echo htmlspecialchars($c['nombre_colaborador']); ?></p>
                                    </div>
                                </div>
                                <span class="badge bg-<?php echo $badgeCls; ?> bg-opacity-25 text-<?php echo $badgeCls; ?> border border-<?php echo $badgeCls; ?> border-opacity-50">
                                    <?php echo htmlspecialchars($estCorr); ?>
                                </span>
                            </div>

                            <div class="item-details-grid">
                                <div>
                                    <span class="detail-item-label">Área</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($c['area'] ?: 'General'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Puesto</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($c['puesto'] ?: 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Tipo</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($c['tipo_correo'] ?: 'Personal'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Fecha Estado</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($c['fecha_baja'] ?: 'Activo'); ?></span>
                                </div>
                            </div>
                            <?php if (!empty($c['motivo_baja'])): ?>
                            <p class="text-warning small mb-2 fst-italic" style="font-size: 0.75rem;">
                                Motivo: <?php echo htmlspecialchars($c['motivo_baja']); ?>
                            </p>
                            <?php endif; ?>
                        </div>

                        <!-- BOTONES DE ACCIÓN PARA CORREO -->
                        <div class="item-card-actions">
                            <?php if ($estCorr === 'Activo'): ?>
                            <button type="button" class="btn-action-tile btn-tile-danger" onclick="cambiarEstadoCorreo(<?php echo $c['id']; ?>, 'En Baja')" title="Marcar cuenta de correo En Baja">
                                <i class="bi bi-slash-circle"></i> Dar de Baja
                            </button>
                            <button type="button" class="btn-action-tile btn-tile-danger" onclick="cambiarEstadoCorreo(<?php echo $c['id']; ?>, 'Eliminado')" title="Marcar como Eliminado">
                                <i class="bi bi-x-octagon"></i> Eliminar
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn-action-tile btn-tile-gold" onclick="cambiarEstadoCorreo(<?php echo $c['id']; ?>, 'Activo')" title="Reactivar cuenta de correo">
                                <i class="bi bi-check-circle"></i> Reactivar
                            </button>
                            <?php endif; ?>

                            <button type="button" class="btn-action-tile ms-auto" onclick='editarCorreo(<?php echo json_encode($c); ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>


        <!-- ===================================================================
             SUB-MÓDULO 4: LICENCIAS DE OFFICE (BASADO EN TARJETAS Y BOTONES)
             =================================================================== -->
        <section id="vista-licencias" class="section-view-panel">
            <div class="sub-header-toolbar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn-outline-gold" onclick="volverAlHub()">
                        <i class="bi bi-arrow-left"></i> Volver a Botones
                    </button>
                    <div>
                        <h4 class="m-0 fw-bold text-white"><i class="bi bi-microsoft text-info me-2"></i> Licencias de Office</h4>
                        <span class="text-secondary small">Product Keys, cuentas vinculadas y equipos asignados</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="input-group" style="width: 240px;">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control form-control-sm" placeholder="Buscar versión o clave..." onkeyup="filtrarCards('card-licencia', this.value)">
                    </div>

                    <button type="button" class="btn-gold-main btn-sm" onclick="abrirModalLicencia(0)">
                        <i class="bi bi-plus-circle-fill"></i> Registrar Licencia
                    </button>
                    <button type="button" class="btn-cyan-main btn-sm ms-2" onclick="exportarSeccionExcel('licencias')">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </button>
                </div>
            </div>

            <!-- GRID DE TARJETAS DE LICENCIAS (NO TABLAS) -->
            <div class="row g-3">
                <?php if (empty($licenciasOffice)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-key text-secondary fs-1 d-block mb-3"></i>
                    <h5 class="text-secondary">No hay licencias registradas.</h5>
                </div>
                <?php else: ?>
                <?php foreach ($licenciasOffice as $lic): ?>
                <div class="col-12 col-md-6 col-lg-4 card-licencia" data-search="<?php echo htmlspecialchars(strtolower($lic['product_key'] . ' ' . $lic['version_office'] . ' ' . $lic['cuenta_microsoft'] . ' ' . $lic['colaborador'] . ' ' . $lic['equipo_asignado'])); ?>">
                    <div class="item-action-card">
                        <div>
                            <div class="item-card-header">
                                <div class="item-title-box">
                                    <div class="item-icon">
                                        <i class="bi bi-shield-check"></i>
                                    </div>
                                    <div>
                                        <h6 class="item-main-title"><?php echo htmlspecialchars($lic['version_office']); ?></h6>
                                        <p class="item-sub-title"><?php echo htmlspecialchars($lic['tipo_licencia'] ?: 'Suscripción'); ?></p>
                                    </div>
                                </div>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50">
                                    <?php echo htmlspecialchars($lic['estado'] ?: 'Activa'); ?>
                                </span>
                            </div>

                            <!-- CLAVE DE PRODUCTO -->
                            <div class="p-2 mb-2 rounded border border-secondary border-opacity-25 d-flex align-items-center justify-content-between" style="background: rgba(0,0,0,0.35);">
                                <code class="text-warning fw-bold password-peek" id="pk-<?php echo $lic['id']; ?>" data-real="<?php echo htmlspecialchars($lic['product_key']); ?>">
                                    •••••-•••••-•••••-•••••
                                </code>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="togglePeek('pk-<?php echo $lic['id']; ?>', this)" title="Mostrar / Ocultar">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copiarTexto('<?php echo htmlspecialchars($lic['product_key']); ?>')" title="Copiar Clave">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="item-details-grid">
                                <div>
                                    <span class="detail-item-label">Cuenta MS</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($lic['cuenta_microsoft'] ?: 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Equipo Asignado</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($lic['equipo_asignado'] ?: 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Usuario / Área</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars(($lic['colaborador'] ?? '') . ' (' . ($lic['area'] ?? 'General') . ')'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Vencimiento</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($lic['fecha_vencimiento'] ?: 'Perpetua'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- BOTONES DE ACCIÓN PARA LICENCIA -->
                        <div class="item-card-actions">
                            <button type="button" class="btn-action-tile" onclick='editarLicencia(<?php echo json_encode($lic); ?>)'>
                                <i class="bi bi-pencil-square"></i> Editar
                            </button>
                            <?php if ($puedeEliminar): ?>
                            <button type="button" class="btn-action-tile btn-tile-danger ms-auto" onclick="confirmarEliminar('licencia', <?php echo $lic['id']; ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>


        <!-- ===================================================================
             SUB-MÓDULO 5: USUARIOS GDS (BASADO EN TARJETAS Y BOTONES)
             =================================================================== -->
        <section id="vista-gds_usuarios" class="section-view-panel">
            <div class="sub-header-toolbar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn-outline-gold" onclick="volverAlHub()">
                        <i class="bi bi-arrow-left"></i> Volver a Botones
                    </button>
                    <div>
                        <h4 class="m-0 fw-bold text-white"><i class="bi bi-person-badge-fill text-warning me-2"></i> Usuarios GDS (DMS)</h4>
                        <span class="text-secondary small">Asignación de credenciales, responsivas oficiales y custodia en PDF</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="input-group" style="width: 240px;">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control form-control-sm" placeholder="Buscar usuario GDS..." onkeyup="filtrarCards('card-gds', this.value)">
                    </div>

                    <button type="button" class="btn-gold-main btn-sm" onclick="abrirModalGdsUsuario(0)">
                        <i class="bi bi-plus-circle-fill"></i> Asignar Usuario GDS
                    </button>
                    <button type="button" class="btn-cyan-main btn-sm ms-2" onclick="exportarSeccionExcel('gds_usuarios')">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </button>
                </div>
            </div>

            <!-- GRID DE TARJETAS DE USUARIOS GDS (NO TABLAS) -->
            <div class="row g-3">
                <?php if (empty($gdsUsuarios)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-person-x text-secondary fs-1 d-block mb-3"></i>
                    <h5 class="text-secondary">No hay usuarios de GDS registrados.</h5>
                    <button type="button" class="btn-gold-main mt-2" onclick="abrirModalGdsUsuario(0)">
                        <i class="bi bi-plus-lg"></i> Asignar Primer Usuario GDS
                    </button>
                </div>
                <?php else: ?>
                <?php foreach ($gdsUsuarios as $gu): 
                    $tieneResp = !empty($gu['responsiva_pdf_url']);
                ?>
                <div class="col-12 col-md-6 col-lg-4 card-gds" data-search="<?php echo htmlspecialchars(strtolower($gu['usuario_gds'] . ' ' . $gu['nombre_colaborador'] . ' ' . $gu['area'] . ' ' . $gu['perfil_gds'])); ?>">
                    <div class="item-action-card card-gold-accent">
                        <div>
                            <div class="item-card-header">
                                <div class="item-title-box">
                                    <div class="item-icon icon-gold">
                                        <i class="bi bi-person-check-fill"></i>
                                    </div>
                                    <div>
                                        <h6 class="item-main-title"><?php echo htmlspecialchars($gu['usuario_gds']); ?></h6>
                                        <p class="item-sub-title"><?php echo htmlspecialchars($gu['nombre_colaborador']); ?></p>
                                    </div>
                                </div>
                                <?php if ($tieneResp): ?>
                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50"><i class="bi bi-check-circle-fill"></i> Responsiva OK</span>
                                <?php else: ?>
                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50"><i class="bi bi-clock-history"></i> Sin Responsiva</span>
                                <?php endif; ?>
                            </div>

                            <!-- CONTRASEÑA GDS OCULTA / VISIBLE -->
                            <div class="p-2 mb-2 rounded border border-warning border-opacity-25 d-flex align-items-center justify-content-between" style="background: rgba(212, 175, 55, 0.06);">
                                <div>
                                    <span class="detail-item-label text-warning">Password GDS</span>
                                    <code class="text-light fw-bold password-peek" id="pwd-gds-<?php echo $gu['id']; ?>" data-real="<?php echo htmlspecialchars($gu['password_gds']); ?>">
                                        ••••••••
                                    </code>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="togglePeek('pwd-gds-<?php echo $gu['id']; ?>', this)" title="Ver Contraseña">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="copiarTexto('<?php echo htmlspecialchars($gu['password_gds']); ?>')" title="Copiar Contraseña">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="item-details-grid">
                                <div>
                                    <span class="detail-item-label">Perfil GDS</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($gu['perfil_gds'] ?: 'Operativo'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Área</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($gu['area'] ?: 'General'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Sucursal</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($gu['sucursal'] ?: 'Matriz'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Estado</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($gu['estado'] ?: 'Activo'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- BOTONES DE ACCIÓN PARA USUARIO GDS -->
                        <div class="item-card-actions">
                            <!-- BOTÓN GENERAR E IMPRIMIR RESPONSIVA CON DATOS -->
                            <a href="generar_responsiva_gds.php?id=<?php echo $gu['id']; ?>" target="_blank" class="btn-action-tile btn-tile-gold" title="Imprimir Responsiva Oficial de Credenciales para firma">
                                <i class="bi bi-printer-fill"></i> Imprimir Responsiva
                            </a>

                            <!-- BOTÓN SUBIR PDF -->
                            <button type="button" class="btn-action-tile" onclick="abrirModalSubirPdf('gds', <?php echo $gu['id']; ?>, '<?php echo htmlspecialchars($gu['usuario_gds']); ?>')">
                                <i class="bi bi-cloud-arrow-up-fill"></i> Subir PDF
                            </button>

                            <!-- BOTÓN VER PDF SI YA ESTÁ SUBIDO -->
                            <?php if ($tieneResp): ?>
                            <button type="button" class="btn-action-tile" onclick="verPdfModal('<?php echo htmlspecialchars($gu['responsiva_pdf_url']); ?>', 'Responsiva GDS - <?php echo htmlspecialchars($gu['usuario_gds']); ?>')">
                                <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Ver PDF
                            </button>
                            <?php endif; ?>

                            <button type="button" class="btn-action-tile" onclick='editarGdsUsuario(<?php echo json_encode($gu); ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>

                            <?php if ($puedeEliminar): ?>
                            <button type="button" class="btn-action-tile btn-tile-danger ms-auto" onclick="confirmarEliminar('gds_usuario', <?php echo $gu['id']; ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>


        <!-- ===================================================================
             SUB-MÓDULO 6: PASSWORD GDS MOVIMIENTOS (BASADO EN TARJETAS Y BOTONES)
             =================================================================== -->
        <section id="vista-gds_passwords" class="section-view-panel">
            <div class="sub-header-toolbar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn-outline-gold" onclick="volverAlHub()">
                        <i class="bi bi-arrow-left"></i> Volver a Botones
                    </button>
                    <div>
                        <h4 class="m-0 fw-bold text-white"><i class="bi bi-key-fill text-warning me-2"></i> Password GDS - Movimientos Críticos</h4>
                        <span class="text-secondary small">Claves de cancelaciones, notas de crédito, descuentos y actas de autorización</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn-gold-main btn-sm" onclick="abrirModalGdsPassword(0)">
                        <i class="bi bi-plus-circle-fill"></i> Nueva Clave de Movimiento
                    </button>
                    <button type="button" class="btn-cyan-main btn-sm ms-2" onclick="exportarSeccionExcel('gds_passwords')">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </button>
                </div>
            </div>

            <!-- GRID DE TARJETAS DE CLAVES DE MOVIMIENTOS (NO TABLAS) -->
            <div class="row g-3">
                <?php if (empty($gdsPasswords)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-shield-x text-secondary fs-1 d-block mb-3"></i>
                    <h5 class="text-secondary">No hay claves de movimiento configuradas.</h5>
                </div>
                <?php else: ?>
                <?php foreach ($gdsPasswords as $gp): 
                    $tieneResp = !empty($gp['responsiva_pdf_url']);
                ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="item-action-card card-gold-accent">
                        <div>
                            <div class="item-card-header">
                                <div class="item-title-box">
                                    <div class="item-icon icon-gold">
                                        <i class="bi bi-shield-lock-fill"></i>
                                    </div>
                                    <div>
                                        <h6 class="item-main-title"><?php echo htmlspecialchars($gp['tipo_movimiento']); ?></h6>
                                        <p class="item-sub-title"><?php echo htmlspecialchars($gp['cargo_responsable'] ?: 'Gerencia'); ?></p>
                                    </div>
                                </div>
                                <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50">
                                    Nivel <?php echo htmlspecialchars($gp['nivel_seguridad'] ?: 'Crítico'); ?>
                                </span>
                            </div>

                            <p class="text-secondary small mb-2" style="font-size: 0.82rem;">
                                <?php echo htmlspecialchars($gp['descripcion']); ?>
                            </p>

                            <!-- CONTRASEÑA DEL MOVIMIENTO -->
                            <div class="p-2 mb-2 rounded border border-warning border-opacity-25 d-flex align-items-center justify-content-between" style="background: rgba(212, 175, 55, 0.08);">
                                <div>
                                    <span class="detail-item-label text-warning">Contraseña de Autorización</span>
                                    <code class="text-warning fw-bold password-peek" id="pwd-mov-<?php echo $gp['id']; ?>" data-real="<?php echo htmlspecialchars($gp['password']); ?>">
                                        ••••••••
                                    </code>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="togglePeek('pwd-mov-<?php echo $gp['id']; ?>', this)" title="Ver">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="copiarTexto('<?php echo htmlspecialchars($gp['password']); ?>')" title="Copiar">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="item-details-grid">
                                <div>
                                    <span class="detail-item-label">Responsable Autorizado</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($gp['responsable_autorizado'] ?: 'Gerente General'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Expediente Firmado</span>
                                    <span class="detail-item-val"><?php echo $tieneResp ? 'Cargado en PDF' : 'Pendiente de Firma'; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- BOTONES DE ACCIÓN PARA MOVIMIENTO -->
                        <div class="item-card-actions">
                            <!-- BOTÓN GENERAR E IMPRIMIR RESPONSIVA CON DATOS -->
                            <a href="generar_responsiva_movimiento.php?id=<?php echo $gp['id']; ?>" target="_blank" class="btn-action-tile btn-tile-gold" title="Imprimir Acta Responsiva Oficial para firma">
                                <i class="bi bi-printer-fill"></i> Imprimir Responsiva
                            </a>

                            <!-- BOTÓN SUBIR PDF -->
                            <button type="button" class="btn-action-tile" onclick="abrirModalSubirPdf('movimiento', <?php echo $gp['id']; ?>, '<?php echo htmlspecialchars($gp['tipo_movimiento']); ?>')">
                                <i class="bi bi-cloud-arrow-up-fill"></i> Subir PDF
                            </button>

                            <!-- BOTÓN VER PDF SI YA ESTÁ SUBIDO -->
                            <?php if ($tieneResp): ?>
                            <button type="button" class="btn-action-tile" onclick="verPdfModal('<?php echo htmlspecialchars($gp['responsiva_pdf_url']); ?>', 'Responsiva Movimiento - <?php echo htmlspecialchars($gp['tipo_movimiento']); ?>')">
                                <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Ver PDF
                            </button>
                            <?php endif; ?>

                            <button type="button" class="btn-action-tile" onclick='editarGdsPassword(<?php echo json_encode($gp); ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>

                            <?php if ($puedeEliminar): ?>
                            <button type="button" class="btn-action-tile btn-tile-danger ms-auto" onclick="confirmarEliminar('gds_password', <?php echo $gp['id']; ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>


        <!-- ===================================================================
             SUB-MÓDULO 7: CONTROL DE USUARIOS GRP (BASADO EN TARJETAS Y BOTONES)
             =================================================================== -->
        <section id="vista-grp_usuarios" class="section-view-panel">
            <div class="sub-header-toolbar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn-outline-gold" onclick="volverAlHub()">
                        <i class="bi bi-arrow-left"></i> Volver a Botones
                    </button>
                    <div>
                        <h4 class="m-0 fw-bold text-white"><i class="bi bi-shield-shaded text-warning me-2"></i> Control de Usuarios de GRP</h4>
                        <span class="text-secondary small">Asignación de usuario, contraseña, roles y responsivas</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="input-group" style="width: 240px;">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control form-control-sm" placeholder="Buscar usuario GRP..." onkeyup="filtrarCards('card-grp', this.value)">
                    </div>

                    <button type="button" class="btn-gold-main btn-sm" onclick="abrirModalGrpUsuario(0)">
                        <i class="bi bi-plus-circle-fill"></i> Asignar Usuario GRP
                    </button>
                    <button type="button" class="btn-cyan-main btn-sm ms-2" onclick="exportarSeccionExcel('grp_usuarios')">
                        <i class="bi bi-file-earmark-excel-fill"></i> Excel
                    </button>
                </div>
            </div>

            <!-- GRID DE TARJETAS DE USUARIOS GRP (NO TABLAS) -->
            <div class="row g-3">
                <?php if (empty($grpUsuarios)): ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-shield-x text-secondary fs-1 d-block mb-3"></i>
                    <h5 class="text-secondary">No hay usuarios de GRP configurados.</h5>
                    <button type="button" class="btn-gold-main mt-2" onclick="abrirModalGrpUsuario(0)">
                        <i class="bi bi-plus-lg"></i> Asignar Primer Usuario GRP
                    </button>
                </div>
                <?php else: ?>
                <?php foreach ($grpUsuarios as $gr): 
                    $tieneResp = !empty($gr['responsiva_pdf_url']);
                ?>
                <div class="col-12 col-md-6 col-lg-4 card-grp" data-search="<?php echo htmlspecialchars(strtolower($gr['usuario_grp'] . ' ' . $gr['nombre_colaborador'] . ' ' . $gr['roles'] . ' ' . $gr['modulos_permitidos'])); ?>">
                    <div class="item-action-card card-gold-accent">
                        <div>
                            <div class="item-card-header">
                                <div class="item-title-box">
                                    <div class="item-icon icon-gold">
                                        <i class="bi bi-person-lock"></i>
                                    </div>
                                    <div>
                                        <h6 class="item-main-title"><?php echo htmlspecialchars($gr['usuario_grp']); ?></h6>
                                        <p class="item-sub-title"><?php echo htmlspecialchars($gr['nombre_colaborador']); ?></p>
                                    </div>
                                </div>
                                <?php if ($tieneResp): ?>
                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50"><i class="bi bi-check-circle-fill"></i> Responsiva OK</span>
                                <?php else: ?>
                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50"><i class="bi bi-clock-history"></i> Sin Responsiva</span>
                                <?php endif; ?>
                            </div>

                            <!-- CONTRASEÑA GRP OCULTA / VISIBLE -->
                            <div class="p-2 mb-2 rounded border border-warning border-opacity-25 d-flex align-items-center justify-content-between" style="background: rgba(212, 175, 55, 0.08);">
                                <div>
                                    <span class="detail-item-label text-warning">Contraseña GRP</span>
                                    <code class="text-light fw-bold password-peek" id="pwd-grp-<?php echo $gr['id']; ?>" data-real="<?php echo htmlspecialchars($gr['password_grp']); ?>">
                                        ••••••••
                                    </code>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="togglePeek('pwd-grp-<?php echo $gr['id']; ?>', this)" title="Ver">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="copiarTexto('<?php echo htmlspecialchars($gr['password_grp']); ?>')" title="Copiar">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- ROLES GRP ASIGNADOS -->
                            <div class="mb-3">
                                <span class="detail-item-label mb-1">Roles Asignados en GRP</span>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php 
                                    $rolesArr = explode(',', $gr['roles'] ?: 'Usuario');
                                    foreach ($rolesArr as $ra):
                                        $rTrim = trim($ra);
                                        if (empty($rTrim)) continue;
                                    ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-50">
                                        <?php echo htmlspecialchars($rTrim); ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="item-details-grid">
                                <div>
                                    <span class="detail-item-label">Módulos</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($gr['modulos_permitidos'] ?: 'General'); ?></span>
                                </div>
                                <div>
                                    <span class="detail-item-label">Estatus</span>
                                    <span class="detail-item-val"><?php echo htmlspecialchars($gr['estado'] ?: 'Activo'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- BOTONES DE ACCIÓN PARA USUARIO GRP -->
                        <div class="item-card-actions">
                            <!-- BOTÓN GENERAR E IMPRIMIR RESPONSIVA CON DATOS -->
                            <a href="generar_responsiva_grp.php?id=<?php echo $gr['id']; ?>" target="_blank" class="btn-action-tile btn-tile-gold" title="Imprimir Responsiva Oficial de Acceso GRP para firma">
                                <i class="bi bi-printer-fill"></i> Imprimir Responsiva
                            </a>

                            <!-- BOTÓN SUBIR PDF -->
                            <button type="button" class="btn-action-tile" onclick="abrirModalSubirPdf('grp', <?php echo $gr['id']; ?>, '<?php echo htmlspecialchars($gr['usuario_grp']); ?>')">
                                <i class="bi bi-cloud-arrow-up-fill"></i> Subir PDF
                            </button>

                            <!-- BOTÓN VER PDF SI YA ESTÁ SUBIDO -->
                            <?php if ($tieneResp): ?>
                            <button type="button" class="btn-action-tile" onclick="verPdfModal('<?php echo htmlspecialchars($gr['responsiva_pdf_url']); ?>', 'Responsiva GRP - <?php echo htmlspecialchars($gr['usuario_grp']); ?>')">
                                <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Ver PDF
                            </button>
                            <?php endif; ?>

                            <button type="button" class="btn-action-tile" onclick='editarGrpUsuario(<?php echo json_encode($gr); ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>

                            <?php if ($puedeEliminar): ?>
                            <button type="button" class="btn-action-tile btn-tile-danger ms-auto" onclick="confirmarEliminar('grp_usuario', <?php echo $gr['id']; ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

    </main>


    <!-- ===================================================================
         MODALES DE ACCIÓN DIRECTA (SIN REDIRECCIONES EXTERNAS)
         =================================================================== -->

    <!-- 1. MODAL SUBIR PDF DE EVIDENCIA O RESPONSIVA -->
    <div class="modal fade" id="modalSubirPdf" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="formSubirPdf" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="subir-action" value="">
                    <input type="hidden" name="equipo_id" id="subir-equipo-id" value="">
                    <input type="hidden" name="mantenimiento_id" id="subir-mant-id" value="">
                    <input type="hidden" name="id" id="subir-generic-id" value="">
                    <input type="hidden" name="seccion" value="inv_equipos_vw">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                            <i class="bi bi-cloud-arrow-up-fill text-warning"></i>
                            <span id="subir-modal-titulo">Subir Archivo PDF</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <p class="text-secondary small mb-3" id="subir-modal-desc">
                            Seleccione el archivo PDF escaneado con las firmas oficiales de autorización y entrega.
                        </p>
                        <div class="mb-3">
                            <label class="form-label text-warning fw-bold small">ARCHIVO EN FORMATO PDF</label>
                            <input type="file" name="archivo_pdf" class="form-control" accept="application/pdf" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn-gold-main">
                            <i class="bi bi-check-lg"></i> Guardar Archivo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. MODAL PROGRAMAR / ASIGNAR DÍA DE MANTENIMIENTO -->
    <div class="modal fade" id="modalMantenimiento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="guardar_mantenimiento">
                    <input type="hidden" name="mantenimiento_id" id="mant-id" value="0">
                    <input type="hidden" name="equipo_id" id="mant-equipo-id" value="">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-white" id="mant-modal-titulo">Asignar Día de Mantenimiento</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-warning small fw-bold">SELECCIONAR MÁQUINA / EQUIPO DEL INVENTARIO</label>
                                <input type="text" name="nombre_equipo" id="mant-nom-equipo" list="lista-equipos" class="form-control" placeholder="Ej: SRV-DATACENTER-01" required onchange="vincularDatosEquipoModal(this)">
                                <datalist id="lista-equipos">
                                    <?php foreach ($equiposInventario as $eq): ?>
                                    <option value="<?php echo htmlspecialchars($eq['nombre_equipo']); ?>" data-area="<?php echo htmlspecialchars($eq['departamento']); ?>" data-user="<?php echo htmlspecialchars($eq['usuario']); ?>" data-id="<?php echo $eq['id']; ?>">
                                        <?php echo htmlspecialchars($eq['nombre_equipo'] . ' - ' . ($eq['departamento'] ?: 'General') . ' (' . ($eq['usuario'] ?: 'Sin asignar') . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label text-warning small fw-bold">ÁREA O DEPARTAMENTO</label>
                                <input type="text" name="area" id="mant-area" class="form-control" placeholder="Ej: Sistemas, Ventas, Taller" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label text-secondary small fw-bold">USUARIO RESPONSABLE</label>
                                <input type="text" name="usuario_responsable" id="mant-usuario" class="form-control" placeholder="Colaborador asignado">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label text-secondary small fw-bold">TIPO DE MANTENIMIENTO</label>
                                <select name="tipo_mantenimiento" id="mant-tipo" class="form-select">
                                    <option value="Preventivo">Preventivo (Hardware & Software)</option>
                                    <option value="Correctivo">Correctivo (Reparación / Cambio de Componentes)</option>
                                    <option value="Auditoría / Limpieza">Auditoría / Limpieza Profunda</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label text-warning small fw-bold">DÍA / FECHA ASIGNADA</label>
                                <input type="date" name="fecha_programada" id="mant-fecha-prog" class="form-control fw-bold" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold">FRECUENCIA</label>
                                <select name="frecuencia" id="mant-frecuencia" class="form-select">
                                    <option value="Mensual">Mensual</option>
                                    <option value="Bimestral">Bimestral</option>
                                    <option value="Trimestral">Trimestral</option>
                                    <option value="Semestral" selected>Semestral</option>
                                    <option value="Anual">Anual</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold">ESTADO</label>
                                <select name="estado" id="mant-estado" class="form-select">
                                    <option value="Programado">Programado</option>
                                    <option value="En Proceso">En Proceso</option>
                                    <option value="Realizado">Realizado</option>
                                </select>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label text-secondary small fw-bold">TÉCNICO / INGENIERO DE SISTEMAS</label>
                                <input type="text" name="tecnico_responsable" id="mant-tecnico" class="form-control" value="Soporte Sistemas">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label text-secondary small fw-bold">DETALLE / OBSERVACIONES DE LA INTERVENCIÓN</label>
                                <textarea name="observaciones" id="mant-obs" class="form-control" rows="2" placeholder="Describa tareas a realizar: soplado de polvo, pasta térmica, antivirus, optimización..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn-gold-main">Guardar y Programar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL DETALLE RÁPIDO DE MANTENIMIENTO DESDE EL CALENDARIO -->
    <div class="modal fade" id="modalDetalleCal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                        <i class="bi bi-tools text-warning"></i>
                        <span id="cal-det-equipo">Mantenimiento</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3 p-3 rounded border border-secondary border-opacity-25" style="background: rgba(0,0,0,0.3);">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small fw-bold">DÍA PROGRAMADO:</span>
                            <span id="cal-det-fecha" class="text-warning fw-bold"></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small fw-bold">ÁREA:</span>
                            <span id="cal-det-area" class="text-light"></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small fw-bold">RESPONSABLE:</span>
                            <span id="cal-det-usuario" class="text-light"></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small fw-bold">ESTADO:</span>
                            <span id="cal-det-estado" class="badge"></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary small fw-bold">TÉCNICO:</span>
                            <span id="cal-det-tecnico" class="text-light"></span>
                        </div>
                    </div>

                    <!-- BOTONES DE ACCIÓN DESDE EL DETALLE DEL CALENDARIO -->
                    <div class="d-grid gap-2">
                        <!-- IMPRIMIR EXPEDIENTE PDF -->
                        <a id="btn-cal-imprimir-exp" href="#" target="_blank" class="btn btn-warning fw-bold py-2">
                            <i class="bi bi-printer-fill me-1"></i> Imprimir Expediente de Mantenimiento (PDF)
                        </a>

                        <!-- SUBIR EVIDENCIA PDF -->
                        <button type="button" id="btn-cal-subir-evid" class="btn btn-outline-info py-2" onclick="">
                            <i class="bi bi-cloud-arrow-up-fill me-1"></i> Subir Evidencia PDF Firmada
                        </button>

                        <!-- VER EVIDENCIA PDF -->
                        <button type="button" id="btn-cal-ver-evid" class="btn btn-outline-light py-2" style="display: none;" onclick="">
                            <i class="bi bi-file-earmark-pdf-fill text-danger me-1"></i> Ver Evidencia PDF Cargada
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" id="btn-cal-editar" class="btn-action-tile btn-tile-gold" onclick="">
                        <i class="bi bi-pencil-square"></i> Modificar Datos
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. MODAL NUEVO / EDITAR CORREO -->
    <div class="modal fade" id="modalCorreo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="guardar_correo">
                    <input type="hidden" name="correo_id" id="correo-id" value="0">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-white" id="correo-modal-titulo">Registrar Cuenta de Correo</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-warning small fw-bold">DIRECCIÓN DE CORREO CORPORATIVO</label>
                            <input type="email" name="correo" id="correo-email" class="form-control" placeholder="ejemplo@agenciagrupohuerta.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">COLABORADOR ASIGNADO</label>
                            <input type="text" name="nombre_colaborador" id="correo-nombre" class="form-control" placeholder="Nombre y Apellidos" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">ÁREA</label>
                                <input type="text" name="area" id="correo-area" class="form-control" placeholder="Ej: Ventas, Administración" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">PUESTO</label>
                                <input type="text" name="puesto" id="correo-puesto" class="form-control" placeholder="Ej: Asesor, Gerente">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">TIPO DE CORREO</label>
                                <select name="tipo_correo" id="correo-tipo" class="form-select">
                                    <option value="Personalizado">Personalizado</option>
                                    <option value="Genérico">Genérico / Área</option>
                                    <option value="Notificaciones">Notificaciones</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">ESTADO</label>
                                <select name="estado" id="correo-estado" class="form-select">
                                    <option value="Activo">Activo</option>
                                    <option value="En Baja">En Baja</option>
                                    <option value="Eliminado">Eliminado</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">CONTRASEÑA ASIGNADA (OPCIONAL)</label>
                            <input type="text" name="password_cuenta" id="correo-pass" class="form-control" placeholder="Contraseña de acceso seguro">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn-gold-main">Guardar Cuenta</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 4. MODAL NUEVA / EDITAR LICENCIA DE OFFICE -->
    <div class="modal fade" id="modalLicencia" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="guardar_licencia">
                    <input type="hidden" name="licencia_id" id="lic-id" value="0">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-white" id="lic-modal-titulo">Registrar Licencia de Office</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-warning small fw-bold">PRODUCT KEY / CÓDIGO DE ACTIVACIÓN</label>
                            <input type="text" name="product_key" id="lic-pk" class="form-control fw-bold" placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">VERSIÓN DE OFFICE</label>
                                <select name="version_office" id="lic-ver" class="form-select">
                                    <option value="Microsoft 365 Business Standard">Microsoft 365 Business Standard</option>
                                    <option value="Microsoft 365 Business Basic">Microsoft 365 Business Basic</option>
                                    <option value="Office Hogar y Empresas 2021">Office Hogar y Empresas 2021</option>
                                    <option value="Office Hogar y Empresas 2019">Office Hogar y Empresas 2019</option>
                                    <option value="Office Professional Plus 2019">Office Professional Plus 2019</option>
                                    <option value="Office 2016 OEM">Office 2016 OEM</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">TIPO DE LICENCIA</label>
                                <select name="tipo_licencia" id="lic-tipo" class="form-select">
                                    <option value="Suscripción Anual">Suscripción Anual</option>
                                    <option value="Digital / ESD">Digital / ESD</option>
                                    <option value="OEM / FPP">OEM / FPP</option>
                                    <option value="Volumen (MAK/KMS)">Volumen (MAK/KMS)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">CUENTA MICROSOFT VINCULADA</label>
                            <input type="email" name="cuenta_microsoft" id="lic-cuenta-ms" class="form-control" placeholder="admin@agenciagrupohuerta.com">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">EQUIPO ASIGNADO</label>
                                <input type="text" name="equipo_asignado" id="lic-equipo" class="form-control" placeholder="Ej: SRV-AD-01">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">COLABORADOR / ÁREA</label>
                                <input type="text" name="colaborador" id="lic-colab" class="form-control" placeholder="Nombre del usuario">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn-gold-main">Guardar Licencia</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 5. MODAL ASIGNAR / EDITAR USUARIO GDS -->
    <div class="modal fade" id="modalGdsUsuario" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="guardar_gds_usuario">
                    <input type="hidden" name="gds_id" id="gds-id" value="0">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-white" id="gds-modal-titulo">Asignar Credenciales GDS (DMS)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-warning small fw-bold">VINCULAR CON COLABORADOR DEL SISTEMA</label>
                            <select name="usuario_id" id="gds-usuario-select" class="form-select" onchange="autocompletarColaboradorGds(this)">
                                <option value="">-- Seleccionar Colaborador --</option>
                                <?php foreach ($catalogoUsuarios as $u): ?>
                                <option value="<?php echo $u['id']; ?>" data-nombre="<?php echo htmlspecialchars($u['nombre']); ?>" data-area="<?php echo htmlspecialchars($u['departamento']); ?>" data-login="<?php echo htmlspecialchars($u['usuario']); ?>">
                                    <?php echo htmlspecialchars($u['nombre'] . ' (' . ($u['departamento'] ?: 'General') . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">NOMBRE COMPLETO DEL COLABORADOR</label>
                            <input type="text" name="nombre_colaborador" id="gds-colaborador" class="form-control" placeholder="Nombre completo" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-warning small fw-bold">USUARIO GDS</label>
                                <input type="text" name="usuario_gds" id="gds-usuario" class="form-control text-uppercase" placeholder="EJ: JPEREZ" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-warning small fw-bold">CONTRASEÑA GDS</label>
                                <input type="text" name="password_gds" id="gds-pass" class="form-control" placeholder="Contraseña segura" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">PERFIL GDS</label>
                                <select name="perfil_gds" id="gds-perfil" class="form-select">
                                    <option value="Asesor Ventas">Asesor Ventas</option>
                                    <option value="Asesor Servicio">Asesor Servicio</option>
                                    <option value="Caja / Cobranza">Caja / Cobranza</option>
                                    <option value="Facturación">Facturación</option>
                                    <option value="Refacciones">Refacciones</option>
                                    <option value="Gerencial">Gerencial</option>
                                    <option value="Administrador">Administrador</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">ÁREA</label>
                                <input type="text" name="area" id="gds-area" class="form-control" placeholder="Ej: Ventas, Administración">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn-gold-main">Guardar Credenciales GDS</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 6. MODAL NUEVA / EDITAR CLAVE DE MOVIMIENTO GDS -->
    <div class="modal fade" id="modalGdsPassword" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="guardar_gds_password">
                    <input type="hidden" name="movimiento_id" id="mov-id" value="0">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-white" id="mov-modal-titulo">Configurar Clave de Movimiento GDS</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-warning small fw-bold">TIPO DE MOVIMIENTO CRÍTICO</label>
                            <input type="text" name="tipo_movimiento" id="mov-tipo" class="form-control" placeholder="Ej: Cancelación de Facturas y Notas de Crédito" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">DESCRIPCIÓN DEL MOVIMIENTO Y CONDICIONES</label>
                            <textarea name="descripcion" id="mov-desc" class="form-control" rows="2" placeholder="Describa para qué operaciones se utiliza esta clave y quién la custodia..." required></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-warning small fw-bold">CONTRASEÑA DE AUTORIZACIÓN</label>
                                <input type="text" name="password" id="mov-pass" class="form-control" placeholder="Contraseña de autorización" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">NIVEL DE SEGURIDAD</label>
                                <select name="nivel_seguridad" id="mov-nivel" class="form-select">
                                    <option value="Crítico">Crítico</option>
                                    <option value="Muy Alto">Muy Alto</option>
                                    <option value="Alto">Alto</option>
                                    <option value="Medio">Medio</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">RESPONSABLE AUTORIZADO</label>
                                <input type="text" name="responsable_autorizado" id="mov-resp" class="form-control" placeholder="Ej: Gerente General" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold">CARGO</label>
                                <input type="text" name="cargo_responsable" id="mov-cargo" class="form-control" placeholder="Ej: Gerencia Administrativa">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn-gold-main">Guardar Clave de Movimiento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 7. MODAL ASIGNAR / EDITAR USUARIO GRP -->
    <div class="modal fade" id="modalGrpUsuario" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="guardar_grp_usuario">
                    <input type="hidden" name="grp_id" id="grp-id" value="0">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-white" id="grp-modal-titulo">Asignar Credenciales y Roles GRP</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">NOMBRE COMPLETO DEL COLABORADOR</label>
                            <input type="text" name="nombre_colaborador" id="grp-nombre" class="form-control" placeholder="Nombre completo del usuario" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-warning small fw-bold">USUARIO GRP</label>
                                <input type="text" name="usuario_grp" id="grp-usr" class="form-control" placeholder="ej: maria.lopez" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-warning small fw-bold">CONTRASEÑA GRP</label>
                                <input type="text" name="password_grp" id="grp-pass" class="form-control" placeholder="Contraseña de acceso" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-warning small fw-bold">ROLES ASIGNADOS EN GRP (SELECCIONE LOS APLICABLES)</label>
                            <div class="row g-2 p-2 rounded border border-secondary border-opacity-25" style="background: rgba(0,0,0,0.25);">
                                <?php 
                                $rolesCatalogo = ['Administrador General', 'Contabilidad', 'Tesorería & Bancos', 'Facturación', 'Cobranza & Cuentas por Cobrar', 'Compras & Proveedores', 'Inventarios & Almacén', 'Recursos Humanos', 'Consulta / Auditor'];
                                foreach ($rolesCatalogo as $rc):
                                ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input check-grp-role" type="checkbox" name="roles[]" value="<?php echo $rc; ?>" id="rc-<?php echo md5($rc); ?>">
                                        <label class="form-check-label small" for="rc-<?php echo md5($rc); ?>">
                                            <?php echo $rc; ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold">MÓDULOS PERMITIDOS</label>
                            <input type="text" name="modulos_permitidos" id="grp-mods" class="form-control" placeholder="Ej: Facturación, Contabilidad, Bancos" value="General">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-action-tile" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn-gold-main">Guardar Usuario GRP</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 8. MODAL VISOR DE PDF INTEGRADO -->
    <div class="modal fade" id="modalVisorPdf" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered" style="height: 90vh;">
            <div class="modal-content h-100">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-bold text-white small" id="visor-pdf-titulo">Expediente Oficial PDF</h5>
                    <div class="d-flex align-items-center gap-2">
                        <a id="visor-pdf-descargar" href="#" download class="btn btn-sm btn-outline-warning py-1 px-3">
                            <i class="bi bi-download"></i> Descargar
                        </a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0 h-100">
                    <iframe id="visor-pdf-frame" src="" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
            </div>
        </div>
    </div>


    <!-- ===================================================================
         JAVASCRIPT NATIVO: NAVEGACIÓN BASADA EN BOTONES Y ACCIONES LOCALES
         =================================================================== -->
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // DATOS LOCALES PARA EXPORTACIÓN EN EXCEL MULTI-HOJA Y CALENDARIO
        const DATOS_AUDITORIA = {
            agencia: <?php echo json_encode($agenciaNombre); ?>,
            equipos: <?php echo json_encode($equiposInventario); ?>,
            mantenimientos: <?php echo json_encode($mantenimientos); ?>,
            correos: <?php echo json_encode($correos); ?>,
            licencias: <?php echo json_encode($licenciasOffice); ?>,
            gdsUsuarios: <?php echo json_encode($gdsUsuarios); ?>,
            gdsPasswords: <?php echo json_encode($gdsPasswords); ?>,
            grpUsuarios: <?php echo json_encode($grpUsuarios); ?>
        };

        // ESTADO GLOBAL DEL CALENDARIO
        let calFechaActual = new Date();
        const nombresMeses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        // NAVEGACIÓN ENTRE VISTAS DE BOTONES
        function abrirModulo(seccionId) {
            document.querySelectorAll('.section-view-panel').forEach(p => p.classList.remove('active'));
            const target = document.getElementById('vista-' + seccionId);
            if (target) {
                target.classList.add('active');
                window.scrollTo({ top: 0, behavior: 'smooth' });
                window.location.hash = seccionId;
                if (seccionId === 'mantenimiento') {
                    renderCalendario();
                }
            }
        }

        function volverAlHub() {
            document.querySelectorAll('.section-view-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('vista-hub').classList.add('active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
            history.pushState("", document.title, window.location.pathname + window.location.search);
        }

        // CONTROL DE SUBVISTAS DE MANTENIMIENTO (CALENDARIO, ÁREA, MÁQUINA, FICHAS)
        function cambiarSubvistaMantenimiento(sub) {
            document.querySelectorAll('.mant-subview').forEach(v => v.style.display = 'none');
            document.getElementById('btn-mant-cal').classList.remove('active');
            document.getElementById('btn-mant-area').classList.remove('active');
            document.getElementById('btn-mant-maquina').classList.remove('active');
            document.getElementById('btn-mant-fichas').classList.remove('active');

            const target = document.getElementById('mant-subview-' + sub);
            const btn = document.getElementById('btn-mant-' + sub);
            if (target) target.style.display = 'block';
            if (btn) btn.classList.add('active');

            if (sub === 'cal') {
                renderCalendario();
            }
        }

        // =====================================================================
        // MOTOR DE RENDERIZADO DEL CALENDARIO MENSUAL DE MANTENIMIENTO
        // =====================================================================
        function renderCalendario() {
            const contenedor = document.getElementById('cal-grid-dias');
            if (!contenedor) return;

            const year = calFechaActual.getFullYear();
            const month = calFechaActual.getMonth(); // 0 a 11
            const filtroArea = document.getElementById('cal-filtro-area')?.value || 'todas';

            document.getElementById('cal-titulo-mes').innerText = `${nombresMeses[month]} ${year}`;

            // Primer día del mes y total de días
            const primerDia = new Date(year, month, 1);
            const diaSemanaInicio = primerDia.getDay(); // 0 = Domingo
            const ultimoDia = new Date(year, month + 1, 0);
            const totalDiasMes = ultimoDia.getDate();

            // Días del mes anterior para relleno
            const ultimoDiaMesAnt = new Date(year, month, 0).getDate();

            // Mantenimientos del mes mapeados por día
            const mantsDelMes = {};
            DATOS_AUDITORIA.mantenimientos.forEach(m => {
                if (!m.fecha_programada) return;
                const partes = m.fecha_programada.split('-');
                if (partes.length === 3) {
                    const mYear = parseInt(partes[0], 10);
                    const mMonth = parseInt(partes[1], 10) - 1;
                    const mDia = parseInt(partes[2], 10);

                    if (mYear === year && mMonth === month) {
                        if (filtroArea === 'todas' || m.area === filtroArea) {
                            if (!mantsDelMes[mDia]) mantsDelMes[mDia] = [];
                            mantsDelMes[mDia].push(m);
                        }
                    }
                }
            });

            const hoy = new Date();
            const esHoyMesAno = (hoy.getFullYear() === year && hoy.getMonth() === month);

            let html = '';

            // Celdas de relleno del mes anterior
            for (let i = diaSemanaInicio - 1; i >= 0; i--) {
                const numDia = ultimoDiaMesAnt - i;
                html += `<div class="calendar-day-cell day-other-month">
                    <div class="calendar-day-top">
                        <span class="calendar-day-number">${numDia}</span>
                    </div>
                </div>`;
            }

            // Celdas de los días del mes
            for (let d = 1; d <= totalDiasMes; d++) {
                const esHoy = esHoyMesAno && (hoy.getDate() === d);
                const fechaStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const mantsHoy = mantsDelMes[d] || [];

                html += `<div class="calendar-day-cell ${esHoy ? 'day-today' : ''}">
                    <div class="calendar-day-top">
                        <span class="calendar-day-number ${esHoy ? 'text-warning' : ''}">${d} ${esHoy ? '<small class="text-warning fw-normal" style="font-size: 0.65rem;">(Hoy)</small>' : ''}</span>
                        <button type="button" class="btn-cal-add" onclick="abrirModalAsignarFecha('${fechaStr}')" title="Asignar mantenimiento a esta fecha">
                            + Asignar
                        </button>
                    </div>
                    <div class="calendar-day-events" style="overflow-y: auto; max-height: 95px;">`;

                mantsHoy.forEach(m => {
                    const st = m.estado || 'Programado';
                    let cls = 'badge-programado';
                    if (st === 'Realizado') cls = 'badge-realizado';
                    else if (st === 'En Proceso') cls = 'badge-proceso';

                    html += `<a href="javascript:void(0)" class="calendar-event-badge ${cls}" onclick='abrirDetalleMantenimientoCal(${JSON.stringify(m)})' title="${m.nombre_equipo} (${m.area}) - ${st}">
                        <i class="bi bi-pc"></i>
                        <span>${m.nombre_equipo}</span>
                    </a>`;
                });

                html += `   </div>
                </div>`;
            }

            // Celdas de relleno del mes siguiente (para completar la cuadrícula de 7 columnas)
            const celdasOcupadas = diaSemanaInicio + totalDiasMes;
            const celdasRestantes = (celdasOcupadas % 7 === 0) ? 0 : 7 - (celdasOcupadas % 7);
            for (let j = 1; j <= celdasRestantes; j++) {
                html += `<div class="calendar-day-cell day-other-month">
                    <div class="calendar-day-top">
                        <span class="calendar-day-number">${j}</span>
                    </div>
                </div>`;
            }

            contenedor.innerHTML = html;
        }

        function calMesAnterior() {
            calFechaActual.setMonth(calFechaActual.getMonth() - 1);
            renderCalendario();
        }

        function calMesSiguiente() {
            calFechaActual.setMonth(calFechaActual.getMonth() + 1);
            renderCalendario();
        }

        function calIrHoy() {
            calFechaActual = new Date();
            renderCalendario();
        }

        // ABRIR MODAL PARA ASIGNAR UN DÍA DESDE EL CALENDARIO O MÁQUINA
        function abrirModalAsignarFecha(fechaStr) {
            document.getElementById('mant-id').value = '0';
            document.getElementById('mant-modal-titulo').innerText = 'Asignar Día de Mantenimiento (' + fechaStr + ')';
            document.getElementById('mant-nom-equipo').value = '';
            document.getElementById('mant-area').value = '';
            document.getElementById('mant-usuario').value = '';
            document.getElementById('mant-fecha-prog').value = fechaStr;
            document.getElementById('mant-obs').value = 'Mantenimiento preventivo programado.';
            new bootstrap.Modal(document.getElementById('modalMantenimiento')).show();
        }

        function asignarDiaMaquina(eqId, nomEquipo, area, usuario) {
            document.getElementById('mant-id').value = '0';
            document.getElementById('mant-equipo-id').value = eqId;
            document.getElementById('mant-modal-titulo').innerText = 'Asignar Día para ' + nomEquipo;
            document.getElementById('mant-nom-equipo').value = nomEquipo;
            document.getElementById('mant-area').value = area || 'General';
            document.getElementById('mant-usuario').value = usuario || '';
            document.getElementById('mant-fecha-prog').value = new Date().toISOString().slice(0, 10);
            new bootstrap.Modal(document.getElementById('modalMantenimiento')).show();
        }

        function asignarMantenimientoArea(area) {
            document.getElementById('mant-id').value = '0';
            document.getElementById('mant-modal-titulo').innerText = 'Programar Mantenimiento en Área: ' + area;
            document.getElementById('mant-nom-equipo').value = '';
            document.getElementById('mant-area').value = area;
            document.getElementById('mant-usuario').value = '';
            document.getElementById('mant-fecha-prog').value = new Date().toISOString().slice(0, 10);
            new bootstrap.Modal(document.getElementById('modalMantenimiento')).show();
        }

        // DETALLE RÁPIDO DE UN EVENTO DESDE EL CALENDARIO
        function abrirDetalleMantenimientoCal(m) {
            document.getElementById('cal-det-equipo').innerText = m.nombre_equipo;
            document.getElementById('cal-det-fecha').innerText = m.fecha_programada;
            document.getElementById('cal-det-area').innerText = m.area || 'General';
            document.getElementById('cal-det-usuario').innerText = m.usuario_responsable || 'Sin Asignar';
            document.getElementById('cal-det-tecnico').innerText = m.tecnico_responsable || 'Sistemas';

            const badgeEst = document.getElementById('cal-det-estado');
            badgeEst.innerText = m.estado;
            badgeEst.className = 'badge ' + ((m.estado === 'Realizado') ? 'bg-success' : ((m.estado === 'En Proceso') ? 'bg-info' : 'bg-warning text-dark'));

            // Botón Imprimir Expediente Oficial PDF
            document.getElementById('btn-cal-imprimir-exp').href = 'generar_expediente_mantenimiento.php?id=' + m.id;

            // Botón Subir Evidencia PDF
            document.getElementById('btn-cal-subir-evid').onclick = function() {
                bootstrap.Modal.getInstance(document.getElementById('modalDetalleCal')).hide();
                abrirModalSubirPdf('mantenimiento', m.id, m.nombre_equipo);
            };

            // Botón Ver Evidencia PDF si existe
            const btnVer = document.getElementById('btn-cal-ver-evid');
            if (m.evidencia_pdf_url) {
                btnVer.style.display = 'block';
                btnVer.onclick = function() {
                    bootstrap.Modal.getInstance(document.getElementById('modalDetalleCal')).hide();
                    verPdfModal(m.evidencia_pdf_url, 'Evidencia - ' + m.nombre_equipo);
                };
            } else {
                btnVer.style.display = 'none';
            }

            // Botón Editar
            document.getElementById('btn-cal-editar').onclick = function() {
                bootstrap.Modal.getInstance(document.getElementById('modalDetalleCal')).hide();
                editarMantenimiento(m);
            };

            new bootstrap.Modal(document.getElementById('modalDetalleCal')).show();
        }

        // AUTOCOMPLETAR DATOS DE EQUIPO SELECCIONADO EN MODAL
        function vincularDatosEquipoModal(input) {
            const val = input.value.trim().toLowerCase();
            const option = Array.from(document.querySelectorAll('#lista-equipos option')).find(opt => opt.value.toLowerCase() === val);
            if (option) {
                document.getElementById('mant-area').value = option.getAttribute('data-area') || '';
                document.getElementById('mant-usuario').value = option.getAttribute('data-user') || '';
                document.getElementById('mant-equipo-id').value = option.getAttribute('data-id') || '';
            }
        }

        // Revisar hash al cargar la página
        window.addEventListener('DOMContentLoaded', () => {
            const hash = window.location.hash.replace('#', '');
            if (hash && document.getElementById('vista-' + hash)) {
                abrirModulo(hash);
            }
            renderCalendario();
        });

        // FILTRO EN TIEMPO REAL DE TARJETAS
        function filtrarCards(cardClass, query) {
            const q = query.toLowerCase().trim();
            document.querySelectorAll('.' + cardClass).forEach(card => {
                const searchStr = card.getAttribute('data-search') || '';
                card.style.display = searchStr.includes(q) ? '' : 'none';
            });
        }

        // FILTRO POR ESTADO EN RESPONSIVAS
        function filtrarEstadoResp(btn, estado) {
            btn.parentElement.querySelectorAll('.filter-pill-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            document.querySelectorAll('.card-equipo').forEach(card => {
                if (estado === 'todos') {
                    card.style.display = '';
                } else {
                    card.style.display = (card.getAttribute('data-estado') === estado) ? '' : 'none';
                }
            });
        }

        // MOSTRAR / OCULTAR CONTRASEÑAS (PEEK)
        function togglePeek(elemId, btn) {
            const el = document.getElementById(elemId);
            if (!el) return;
            const real = el.getAttribute('data-real');
            const icon = btn.querySelector('i');
            if (el.innerText.includes('•')) {
                el.innerText = real;
                if (icon) icon.className = 'bi bi-eye-slash';
            } else {
                el.innerText = '••••••••';
                if (icon) icon.className = 'bi bi-eye';
            }
        }

        // COPIAR AL PORTAPAPELES
        function copiarTexto(texto) {
            if (!texto) return;
            navigator.clipboard.writeText(texto).then(() => {
                const toast = document.createElement('div');
                toast.className = 'position-fixed bottom-0 end-0 p-3';
                toast.style.zIndex = '9999';
                toast.innerHTML = `<div class="toast show align-items-center text-white bg-success border-0 py-2 px-3 rounded shadow">
                    <i class="bi bi-check2-circle me-2"></i> Copiado al portapapeles
                </div>`;
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 2000);
            });
        }

        // VISOR MODAL DE PDF
        function verPdfModal(url, titulo) {
            if (!url) return;
            document.getElementById('visor-pdf-titulo').innerText = titulo;
            document.getElementById('visor-pdf-descargar').href = url;
            document.getElementById('visor-pdf-frame').src = url;
            new bootstrap.Modal(document.getElementById('modalVisorPdf')).show();
        }

        // MODAL PARA SUBIR ARCHIVO PDF
        function abrirModalSubirPdf(tipo, id, nombre) {
            document.getElementById('subir-equipo-id').value = '';
            document.getElementById('subir-mant-id').value = '';
            document.getElementById('subir-generic-id').value = '';

            let action = '';
            let titulo = '';
            if (tipo === 'equipo') {
                action = 'subir_responsiva_equipo';
                titulo = 'Subir Responsiva de Equipo: ' + nombre;
                document.getElementById('subir-equipo-id').value = id;
            } else if (tipo === 'mantenimiento') {
                action = 'subir_evidencia_mantenimiento';
                titulo = 'Subir Evidencia de Mantenimiento: ' + nombre;
                document.getElementById('subir-mant-id').value = id;
            } else if (tipo === 'gds') {
                action = 'subir_responsiva_gds';
                titulo = 'Subir Responsiva GDS: ' + nombre;
                document.getElementById('subir-generic-id').value = id;
            } else if (tipo === 'movimiento') {
                action = 'subir_responsiva_movimiento';
                titulo = 'Subir Acta de Movimiento: ' + nombre;
                document.getElementById('subir-generic-id').value = id;
            } else if (tipo === 'grp') {
                action = 'subir_responsiva_grp';
                titulo = 'Subir Responsiva GRP: ' + nombre;
                document.getElementById('subir-generic-id').value = id;
            }

            document.getElementById('subir-action').value = action;
            document.getElementById('subir-modal-titulo').innerText = titulo;
            new bootstrap.Modal(document.getElementById('modalSubirPdf')).show();
        }

        // AUTOCOMPLETAR DATOS DE COLABORADOR GDS
        function autocompletarColaboradorGds(select) {
            const opt = select.options[select.selectedIndex];
            if (opt && opt.value) {
                document.getElementById('gds-colaborador').value = opt.getAttribute('data-nombre') || '';
                document.getElementById('gds-area').value = opt.getAttribute('data-area') || '';
                if (!document.getElementById('gds-usuario').value) {
                    document.getElementById('gds-usuario').value = (opt.getAttribute('data-login') || '').toUpperCase();
                }
            }
        }

        // APERTURA Y EDICIÓN DE MODALES
        function abrirModalMantenimiento(id) {
            document.getElementById('mant-id').value = '0';
            document.getElementById('mant-modal-titulo').innerText = 'Programar Plan de Mantenimiento';
            document.getElementById('mant-nom-equipo').value = '';
            document.getElementById('mant-area').value = '';
            document.getElementById('mant-usuario').value = '';
            document.getElementById('mant-obs').value = '';
            document.getElementById('mant-fecha-prog').value = new Date().toISOString().slice(0, 10);
            new bootstrap.Modal(document.getElementById('modalMantenimiento')).show();
        }

        function editarMantenimiento(m) {
            document.getElementById('mant-id').value = m.id;
            document.getElementById('mant-modal-titulo').innerText = 'Editar Plan de Mantenimiento #' + m.id;
            document.getElementById('mant-nom-equipo').value = m.nombre_equipo || '';
            document.getElementById('mant-area').value = m.area || '';
            document.getElementById('mant-usuario').value = m.usuario_responsable || '';
            document.getElementById('mant-tipo').value = m.tipo_mantenimiento || 'Preventivo';
            document.getElementById('mant-fecha-prog').value = m.fecha_programada || '';
            document.getElementById('mant-frecuencia').value = m.frecuencia || 'Semestral';
            document.getElementById('mant-estado').value = m.estado || 'Programado';
            document.getElementById('mant-tecnico').value = m.tecnico_responsable || 'Sistemas';
            document.getElementById('mant-obs').value = m.observaciones || '';
            new bootstrap.Modal(document.getElementById('modalMantenimiento')).show();
        }

        function abrirModalCorreo(id) {
            document.getElementById('correo-id').value = '0';
            document.getElementById('correo-modal-titulo').innerText = 'Registrar Cuenta de Correo';
            document.getElementById('correo-email').value = '';
            document.getElementById('correo-nombre').value = '';
            document.getElementById('correo-area').value = '';
            document.getElementById('correo-puesto').value = '';
            document.getElementById('correo-pass').value = '';
            new bootstrap.Modal(document.getElementById('modalCorreo')).show();
        }

        function editarCorreo(c) {
            document.getElementById('correo-id').value = c.id;
            document.getElementById('correo-modal-titulo').innerText = 'Editar Cuenta de Correo #' + c.id;
            document.getElementById('correo-email').value = c.correo || '';
            document.getElementById('correo-nombre').value = c.nombre_colaborador || '';
            document.getElementById('correo-area').value = c.area || '';
            document.getElementById('correo-puesto').value = c.puesto || '';
            document.getElementById('correo-tipo').value = c.tipo_correo || 'Personalizado';
            document.getElementById('correo-estado').value = c.estado || 'Activo';
            document.getElementById('correo-pass').value = c.password_cuenta || '';
            new bootstrap.Modal(document.getElementById('modalCorreo')).show();
        }

        function abrirModalLicencia(id) {
            document.getElementById('lic-id').value = '0';
            document.getElementById('lic-modal-titulo').innerText = 'Registrar Licencia de Office';
            document.getElementById('lic-pk').value = '';
            document.getElementById('lic-cuenta-ms').value = '';
            document.getElementById('lic-equipo').value = '';
            document.getElementById('lic-colab').value = '';
            new bootstrap.Modal(document.getElementById('modalLicencia')).show();
        }

        function editarLicencia(lic) {
            document.getElementById('lic-id').value = lic.id;
            document.getElementById('lic-modal-titulo').innerText = 'Editar Licencia #' + lic.id;
            document.getElementById('lic-pk').value = lic.product_key || '';
            document.getElementById('lic-ver').value = lic.version_office || 'Microsoft 365 Business Standard';
            document.getElementById('lic-tipo').value = lic.tipo_licencia || 'Suscripción Anual';
            document.getElementById('lic-cuenta-ms').value = lic.cuenta_microsoft || '';
            document.getElementById('lic-equipo').value = lic.equipo_asignado || '';
            document.getElementById('lic-colab').value = lic.colaborador || '';
            new bootstrap.Modal(document.getElementById('modalLicencia')).show();
        }

        function abrirModalGdsUsuario(id) {
            document.getElementById('gds-id').value = '0';
            document.getElementById('gds-modal-titulo').innerText = 'Asignar Credenciales GDS (DMS)';
            document.getElementById('gds-usuario').value = '';
            document.getElementById('gds-colaborador').value = '';
            document.getElementById('gds-pass').value = '';
            document.getElementById('gds-area').value = '';
            new bootstrap.Modal(document.getElementById('modalGdsUsuario')).show();
        }

        function editarGdsUsuario(gu) {
            document.getElementById('gds-id').value = gu.id;
            document.getElementById('gds-modal-titulo').innerText = 'Editar Credenciales GDS #' + gu.id;
            document.getElementById('gds-usuario').value = gu.usuario_gds || '';
            document.getElementById('gds-colaborador').value = gu.nombre_colaborador || '';
            document.getElementById('gds-pass').value = gu.password_gds || '';
            document.getElementById('gds-perfil').value = gu.perfil_gds || 'Operativo';
            document.getElementById('gds-area').value = gu.area || '';
            new bootstrap.Modal(document.getElementById('modalGdsUsuario')).show();
        }

        function abrirModalGdsPassword(id) {
            document.getElementById('mov-id').value = '0';
            document.getElementById('mov-modal-titulo').innerText = 'Configurar Clave de Movimiento GDS';
            document.getElementById('mov-tipo').value = '';
            document.getElementById('mov-desc').value = '';
            document.getElementById('mov-pass').value = '';
            document.getElementById('mov-resp').value = '';
            document.getElementById('mov-cargo').value = 'Gerencia';
            new bootstrap.Modal(document.getElementById('modalGdsPassword')).show();
        }

        function editarGdsPassword(gp) {
            document.getElementById('mov-id').value = gp.id;
            document.getElementById('mov-modal-titulo').innerText = 'Editar Clave de Movimiento #' + gp.id;
            document.getElementById('mov-tipo').value = gp.tipo_movimiento || '';
            document.getElementById('mov-desc').value = gp.descripcion || '';
            document.getElementById('mov-pass').value = gp.password || '';
            document.getElementById('mov-nivel').value = gp.nivel_seguridad || 'Crítico';
            document.getElementById('mov-resp').value = gp.responsable_autorizado || '';
            document.getElementById('mov-cargo').value = gp.cargo_responsable || '';
            new bootstrap.Modal(document.getElementById('modalGdsPassword')).show();
        }

        function abrirModalGrpUsuario(id) {
            document.getElementById('grp-id').value = '0';
            document.getElementById('grp-modal-titulo').innerText = 'Asignar Credenciales y Roles GRP';
            document.getElementById('grp-usr').value = '';
            document.getElementById('grp-nombre').value = '';
            document.getElementById('grp-pass').value = '';
            document.querySelectorAll('.check-grp-role').forEach(c => c.checked = false);
            new bootstrap.Modal(document.getElementById('modalGrpUsuario')).show();
        }

        function editarGrpUsuario(gr) {
            document.getElementById('grp-id').value = gr.id;
            document.getElementById('grp-modal-titulo').innerText = 'Editar Usuario GRP #' + gr.id;
            document.getElementById('grp-usr').value = gr.usuario_grp || '';
            document.getElementById('grp-nombre').value = gr.nombre_colaborador || '';
            document.getElementById('grp-pass').value = gr.password_grp || '';
            document.getElementById('grp-mods').value = gr.modulos_permitidos || 'General';

            const rolesStr = (gr.roles || '').toLowerCase();
            document.querySelectorAll('.check-grp-role').forEach(c => {
                c.checked = rolesStr.includes(c.value.toLowerCase());
            });
            new bootstrap.Modal(document.getElementById('modalGrpUsuario')).show();
        }

        // ACCIONES LOCALES RÁPIDAS
        function cambiarEstadoCorreo(id, nuevoEstado) {
            let mot = '';
            if (nuevoEstado !== 'Activo') {
                mot = prompt(`Ingrese el motivo para marcar la cuenta como "${nuevoEstado}":`, 'Baja operativa del colaborador');
                if (mot === null) return;
            }

            const formData = new FormData();
            formData.append('action', 'cambiar_estado_correo');
            formData.append('id', id);
            formData.append('nuevo_estado', nuevoEstado);
            formData.append('motivo', mot);
            formData.append('es_ajax', '1');

            fetch('auditoria_interna.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        window.location.hash = 'correos';
                        window.location.reload();
                    } else {
                        alert(res.error || 'Error al actualizar');
                    }
                });
        }

        function sincronizarCorreos() {
            if (!confirm('¿Desea sincronizar automáticamente la matriz de correos con los usuarios y equipos registrados?')) return;
            const formData = new FormData();
            formData.append('action', 'sincronizar_correos');
            formData.append('es_ajax', '1');

            fetch('auditoria_interna.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    alert(res.mensaje || 'Sincronización terminada');
                    window.location.hash = 'correos';
                    window.location.reload();
                });
        }

        function confirmarEliminar(tipo, id) {
            if (!confirm(`¿Está seguro de eliminar este registro (#${id})? Esta acción no se puede deshacer.`)) return;

            let action = '';
            let hash = '';
            if (tipo === 'mantenimiento') { action = 'eliminar_mantenimiento'; hash = 'mantenimiento'; }
            else if (tipo === 'licencia') { action = 'eliminar_licencia'; hash = 'licencias'; }
            else if (tipo === 'gds_usuario') { action = 'eliminar_gds_usuario'; hash = 'gds_usuarios'; }
            else if (tipo === 'gds_password') { action = 'eliminar_gds_password'; hash = 'gds_passwords'; }
            else if (tipo === 'grp_usuario') { action = 'eliminar_grp_usuario'; hash = 'grp_usuarios'; }

            const formData = new FormData();
            formData.append('action', action);
            formData.append('id', id);
            formData.append('es_ajax', '1');

            fetch('auditoria_interna.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        window.location.hash = hash;
                        window.location.reload();
                    } else {
                        alert(res.error || 'Error al eliminar');
                    }
                });
        }

        // =====================================================================
        // EXPORTADOR PREMIUM DE EXCEL MULTI-PESTAÑA CON EXCELJS
        // =====================================================================
        async function exportarAuditoriaCompletaExcel() {
            const wb = new ExcelJS.Workbook();
            wb.creator = 'Sistema de Auditoría Interna TI';
            wb.created = new Date();

            const estiloHeader = {
                font: { name: 'Calibri', size: 11, bold: true, color: { argb: 'FFFFFFFF' } },
                fill: { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0D213F' } },
                alignment: { vertical: 'middle', horizontal: 'center' },
                border: {
                    top: { style: 'thin', color: { argb: 'FFD4AF37' } },
                    bottom: { style: 'medium', color: { argb: 'FFD4AF37' } }
                }
            };

            // 1. HOJA: RESPONSIVAS EQUIPOS
            const ws1 = wb.addWorksheet('1. Responsivas Equipos');
            ws1.columns = [
                { header: 'ID', key: 'id', width: 8 },
                { header: 'Equipo', key: 'nombre', width: 22 },
                { header: 'Área', key: 'area', width: 18 },
                { header: 'Responsable', key: 'usuario', width: 26 },
                { header: 'Puesto', key: 'puesto', width: 22 },
                { header: 'Marca / Modelo', key: 'marca_modelo', width: 22 },
                { header: 'No. Serie', key: 'serie', width: 18 },
                { header: 'Estatus Responsiva', key: 'estatus', width: 20 }
            ];
            ws1.getRow(1).eachCell(cell => Object.assign(cell, estiloHeader));
            DATOS_AUDITORIA.equipos.forEach(eq => {
                ws1.addRow({
                    id: eq.id,
                    nombre: eq.nombre_equipo,
                    area: eq.departamento || 'General',
                    usuario: eq.usuario || 'Sin Asignar',
                    puesto: eq.puesto || 'N/A',
                    marca_modelo: (eq.marca || '') + ' ' + (eq.modelo || ''),
                    serie: eq.serie || 'S/N',
                    estatus: eq.responsiva_url ? 'Firmada' : 'Pendiente'
                });
            });

            // 2. HOJA: PLANES DE MANTENIMIENTO
            const ws2 = wb.addWorksheet('2. Mantenimientos');
            ws2.columns = [
                { header: 'ID', key: 'id', width: 8 },
                { header: 'Equipo', key: 'equipo', width: 22 },
                { header: 'Área', key: 'area', width: 18 },
                { header: 'Tipo', key: 'tipo', width: 18 },
                { header: 'Fecha Programada', key: 'fecha_prog', width: 18 },
                { header: 'Frecuencia', key: 'frec', width: 15 },
                { header: 'Estado', key: 'estado', width: 15 },
                { header: 'Técnico', key: 'tecnico', width: 22 },
                { header: 'Evidencia PDF', key: 'evidencia', width: 16 }
            ];
            ws2.getRow(1).eachCell(cell => Object.assign(cell, estiloHeader));
            DATOS_AUDITORIA.mantenimientos.forEach(m => {
                ws2.addRow({
                    id: m.id,
                    equipo: m.nombre_equipo,
                    area: m.area,
                    tipo: m.tipo_mantenimiento,
                    fecha_prog: m.fecha_programada,
                    frec: m.frecuencia,
                    estado: m.estado,
                    tecnico: m.tecnico_responsable,
                    evidencia: m.evidencia_pdf_url ? 'Sí (Cargada)' : 'No'
                });
            });

            // 3. HOJA: CONTROL DE CORREOS
            const ws3 = wb.addWorksheet('3. Control de Correos');
            ws3.columns = [
                { header: 'ID', key: 'id', width: 8 },
                { header: 'Correo Electrónico', key: 'correo', width: 32 },
                { header: 'Colaborador', key: 'colab', width: 26 },
                { header: 'Área', key: 'area', width: 18 },
                { header: 'Puesto', key: 'puesto', width: 22 },
                { header: 'Tipo', key: 'tipo', width: 16 },
                { header: 'Estado', key: 'estado', width: 14 },
                { header: 'Fecha Baja', key: 'fecha_baja', width: 15 },
                { header: 'Motivo Baja', key: 'motivo', width: 30 }
            ];
            ws3.getRow(1).eachCell(cell => Object.assign(cell, estiloHeader));
            DATOS_AUDITORIA.correos.forEach(c => {
                ws3.addRow({
                    id: c.id,
                    correo: c.correo,
                    colab: c.nombre_colaborador,
                    area: c.area,
                    puesto: c.puesto,
                    tipo: c.tipo_correo,
                    estado: c.estado,
                    fecha_baja: c.fecha_baja || '-',
                    motivo: c.motivo_baja || '-'
                });
            });

            // 4. HOJA: LICENCIAS DE OFFICE
            const ws4 = wb.addWorksheet('4. Licencias Office');
            ws4.columns = [
                { header: 'ID', key: 'id', width: 8 },
                { header: 'Versión de Office', key: 'ver', width: 30 },
                { header: 'Product Key / Clave', key: 'pk', width: 32 },
                { header: 'Tipo Licencia', key: 'tipo', width: 20 },
                { header: 'Cuenta Microsoft', key: 'cuenta', width: 30 },
                { header: 'Equipo Asignado', key: 'eq', width: 20 },
                { header: 'Colaborador', key: 'colab', width: 24 },
                { header: 'Área', key: 'area', width: 18 },
                { header: 'Estado', key: 'estado', width: 14 }
            ];
            ws4.getRow(1).eachCell(cell => Object.assign(cell, estiloHeader));
            DATOS_AUDITORIA.licencias.forEach(l => {
                ws4.addRow({
                    id: l.id,
                    ver: l.version_office,
                    pk: l.product_key,
                    tipo: l.tipo_licencia,
                    cuenta: l.cuenta_microsoft,
                    eq: l.equipo_asignado,
                    colab: l.colaborador,
                    area: l.area,
                    estado: l.estado
                });
            });

            // 5. HOJA: USUARIOS GDS
            const ws5 = wb.addWorksheet('5. Usuarios GDS');
            ws5.columns = [
                { header: 'ID', key: 'id', width: 8 },
                { header: 'Usuario GDS', key: 'usr', width: 18 },
                { header: 'Colaborador', key: 'colab', width: 26 },
                { header: 'Contraseña GDS', key: 'pass', width: 20 },
                { header: 'Perfil GDS', key: 'perfil', width: 20 },
                { header: 'Área', key: 'area', width: 18 },
                { header: 'Sucursal', key: 'sucursal', width: 16 },
                { header: 'Responsiva Firmada', key: 'resp', width: 20 }
            ];
            ws5.getRow(1).eachCell(cell => Object.assign(cell, estiloHeader));
            DATOS_AUDITORIA.gdsUsuarios.forEach(gu => {
                ws5.addRow({
                    id: gu.id,
                    usr: gu.usuario_gds,
                    colab: gu.nombre_colaborador,
                    pass: gu.password_gds,
                    perfil: gu.perfil_gds,
                    area: gu.area,
                    sucursal: gu.sucursal,
                    resp: gu.responsiva_pdf_url ? 'Firmada (PDF)' : 'Pendiente'
                });
            });

            // 6. HOJA: PASSWORD GDS MOVIMIENTOS
            const ws6 = wb.addWorksheet('6. Password GDS Movimientos');
            ws6.columns = [
                { header: 'ID', key: 'id', width: 8 },
                { header: 'Tipo de Movimiento', key: 'mov', width: 32 },
                { header: 'Descripción', key: 'desc', width: 45 },
                { header: 'Contraseña', key: 'pass', width: 20 },
                { header: 'Nivel Seguridad', key: 'nivel', width: 16 },
                { header: 'Responsable Autorizado', key: 'resp', width: 26 },
                { header: 'Cargo', key: 'cargo', width: 22 },
                { header: 'Acta Firmada', key: 'acta', width: 18 }
            ];
            ws6.getRow(1).eachCell(cell => Object.assign(cell, estiloHeader));
            DATOS_AUDITORIA.gdsPasswords.forEach(gp => {
                ws6.addRow({
                    id: gp.id,
                    mov: gp.tipo_movimiento,
                    desc: gp.descripcion,
                    pass: gp.password,
                    nivel: gp.nivel_seguridad,
                    resp: gp.responsable_autorizado,
                    cargo: gp.cargo_responsable,
                    acta: gp.responsiva_pdf_url ? 'Sí (Cargada)' : 'No'
                });
            });

            // 7. HOJA: USUARIOS GRP
            const ws7 = wb.addWorksheet('7. Usuarios GRP');
            ws7.columns = [
                { header: 'ID', key: 'id', width: 8 },
                { header: 'Usuario GRP', key: 'usr', width: 20 },
                { header: 'Colaborador', key: 'colab', width: 26 },
                { header: 'Contraseña GRP', key: 'pass', width: 20 },
                { header: 'Roles Asignados', key: 'roles', width: 40 },
                { header: 'Módulos Permitidos', key: 'mods', width: 25 },
                { header: 'Estatus', key: 'est', width: 14 },
                { header: 'Responsiva Firmada', key: 'resp', width: 20 }
            ];
            ws7.getRow(1).eachCell(cell => Object.assign(cell, estiloHeader));
            DATOS_AUDITORIA.grpUsuarios.forEach(gr => {
                ws7.addRow({
                    id: gr.id,
                    usr: gr.usuario_grp,
                    colab: gr.nombre_colaborador,
                    pass: gr.password_grp,
                    roles: gr.roles,
                    mods: gr.modulos_permitidos,
                    est: gr.estado,
                    resp: gr.responsiva_pdf_url ? 'Firmada (PDF)' : 'Pendiente'
                });
            });

            // Descargar el archivo
            const buffer = await wb.xlsx.writeBuffer();
            const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
            saveAs(blob, `Auditoria_Interna_${DATOS_AUDITORIA.agencia.replace(/\s+/g, '_')}_${new Date().toISOString().slice(0, 10)}.xlsx`);
        }

        function exportarSeccionExcel(seccion) {
            exportarAuditoriaCompletaExcel();
        }
    </script>
</body>
</html>
