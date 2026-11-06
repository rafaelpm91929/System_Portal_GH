<?php
/**
 * API REST para el Módulo de Auditoría Interna y Control Operativo
 * Gestiona:
 * 1. Responsivas de Equipos (Inventario)
 * 2. Planes de Mantenimiento por Área (Cronograma y Evidencias PDF)
 * 3. Control de Correos (Matriz, Altas, Bajas y Eliminaciones)
 * 4. Licencias de Office (Product Keys, Asignaciones y Vigencias)
 * 5. Usuarios GDS (Credenciales y Responsivas Firmadas)
 * 6. Password GDS (Contraseñas de Movimientos Críticos)
 * 7. Control de Usuarios GRP (Asignación de Roles y Accesos)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/excel_helper.php';

if ($pdo) {
    asegurarTablasPermisos($pdo);
    asegurarTablasAuditoriaInterna($pdo);
} else {
    echo json_encode(['success' => false, 'error' => 'No hay conexión a la base de datos']);
    exit();
}

// Validación de sesión
function validarSesionAuditoria() {
    if (!isset($_SESSION['usuario_id'])) {
        echo json_encode(['success' => false, 'error' => 'Sesión expirada. Inicie sesión nuevamente.']);
        exit();
    }
}

validarSesionAuditoria();

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

try {
    switch ($action) {

        // =====================================================================
        // 1. RESPONSIVAS DE EQUIPOS (VINCULADO AL INVENTARIO)
        // =====================================================================
        case 'listar_responsivas_equipos':
            requerirPermiso('auditoria_interna', 'puede_ver');

            $area = trim($_GET['area'] ?? '');
            $estadoResp = trim($_GET['estado_resp'] ?? ''); // 'firmada', 'pendiente'
            $busqueda = trim($_GET['busqueda'] ?? '');

            // Determinar tabla de inventario existente
            $tablas = ['inv_equipos_vw', 'inv_equipos_corp'];
            $equipos = [];

            foreach ($tablas as $tbl) {
                try {
                    $sql = "SELECT id, '$tbl' as origen_seccion, nombre_equipo, usuario, departamento, puesto, tipo, marca, modelo, serie, responsiva_url FROM `$tbl` WHERE (estado IS NULL OR estado != 'Baja') ORDER BY departamento ASC, nombre_equipo ASC";
                    $stmt = $pdo->query($sql);
                    if ($stmt) {
                        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $equipos[] = [
                                'id' => $r['id'],
                                'seccion' => $tbl,
                                'nombre_equipo' => $r['nombre_equipo'] ?: 'Sin nombre',
                                'usuario' => $r['usuario'] ?: 'Sin asignar',
                                'area' => $r['departamento'] ?: 'General',
                                'puesto' => $r['puesto'] ?: 'N/A',
                                'tipo' => $r['tipo'] ?: 'PC',
                                'marca' => $r['marca'] ?: '',
                                'modelo' => $r['modelo'] ?: '',
                                'serie' => $r['serie'] ?: 'S/N',
                                'responsiva_url' => $r['responsiva_url'] ?: '',
                                'tiene_responsiva' => (!empty($r['responsiva_url']) && trim($r['responsiva_url']) !== '') ? 1 : 0
                            ];
                        }
                    }
                } catch (Throwable $eTbl) {}
            }

            // Filtrado en memoria
            if (!empty($area)) {
                $equipos = array_values(array_filter($equipos, fn($e) => $e['area'] === $area));
            }
            if ($estadoResp === 'firmada') {
                $equipos = array_values(array_filter($equipos, fn($e) => $e['tiene_responsiva'] === 1));
            } elseif ($estadoResp === 'pendiente') {
                $equipos = array_values(array_filter($equipos, fn($e) => $e['tiene_responsiva'] === 0));
            }
            if (!empty($busqueda)) {
                $q = mb_strtolower($busqueda);
                $equipos = array_values(array_filter($equipos, function($e) use ($q) {
                    return strpos(mb_strtolower($e['nombre_equipo']), $q) !== false ||
                           strpos(mb_strtolower($e['usuario']), $q) !== false ||
                           strpos(mb_strtolower($e['area']), $q) !== false ||
                           strpos(mb_strtolower($e['serie']), $q) !== false;
                }));
            }

            echo json_encode(['success' => true, 'total' => count($equipos), 'datos' => $equipos]);
            break;

        case 'subir_responsiva_equipo':
            requerirPermiso('auditoria_interna', 'puede_editar');

            $equipoId = intval($_POST['equipo_id'] ?? 0);
            $seccion = trim($_POST['seccion'] ?? 'inv_equipos_vw');

            if ($equipoId <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID de equipo inválido']);
                exit();
            }

            if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No se seleccionó ningún archivo PDF válido']);
                exit();
            }

            $ext = strtolower(pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                echo json_encode(['success' => false, 'error' => 'Únicamente se permiten archivos en formato PDF']);
                exit();
            }

            $targetDir = __DIR__ . '/uploads/auditoria/responsivas';
            if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

            $nombreArchivo = 'RESP_EQ_' . $equipoId . '_' . date('Ymd_His') . '.pdf';
            $targetPath = $targetDir . '/' . $nombreArchivo;
            $relUrl = 'uploads/auditoria/responsivas/' . $nombreArchivo;

            if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $targetPath)) {
                $stmtUpd = $pdo->prepare("UPDATE `$seccion` SET responsiva_url = ? WHERE id = ?");
                $stmtUpd->execute([$relUrl, $equipoId]);

                echo json_encode([
                    'success' => true,
                    'mensaje' => 'Carta responsiva firmada subida correctamente',
                    'url' => $relUrl
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al mover el archivo al servidor']);
            }
            break;

        case 'eliminar_responsiva_equipo':
            requerirPermiso('auditoria_interna', 'puede_eliminar');
            $equipoId = intval($_POST['equipo_id'] ?? 0);
            $seccion = trim($_POST['seccion'] ?? 'inv_equipos_vw');

            $stmtUpd = $pdo->prepare("UPDATE `$seccion` SET responsiva_url = NULL WHERE id = ?");
            $stmtUpd->execute([$equipoId]);
            echo json_encode(['success' => true, 'mensaje' => 'Responsiva desvinculada del equipo']);
            break;

        // =====================================================================
        // 2. PLANES DE MANTENIMIENTO
        // =====================================================================
        case 'listar_mantenimientos':
            requerirPermiso('auditoria_interna', 'puede_ver');

            $area = trim($_GET['area'] ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $mes = trim($_GET['mes'] ?? '');
            $busqueda = trim($_GET['busqueda'] ?? '');

            $sql = "SELECT * FROM audit_mantenimientos WHERE 1=1";
            $params = [];

            if (!empty($area)) {
                $sql .= " AND area = ?";
                $params[] = $area;
            }
            if (!empty($estado)) {
                $sql .= " AND estado = ?";
                $params[] = $estado;
            }
            if (!empty($mes)) {
                $sql .= " AND strftime('%Y-%m', fecha_programada) = ? OR fecha_programada LIKE ?";
                $params[] = $mes;
                $params[] = "$mes%";
            }
            if (!empty($busqueda)) {
                $sql .= " AND (LOWER(nombre_equipo) LIKE ? OR LOWER(usuario_responsable) LIKE ? OR LOWER(tecnico_responsable) LIKE ?)";
                $bParam = '%' . mb_strtolower($busqueda) . '%';
                $params[] = $bParam;
                $params[] = $bParam;
                $params[] = $bParam;
            }

            $sql .= " ORDER BY fecha_programada ASC, area ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'total' => count($rows), 'datos' => $rows]);
            break;

        case 'guardar_mantenimiento':
            requerirPermiso('auditoria_interna', 'puede_crear');

            $id = intval($_POST['id'] ?? 0);
            $nombre_equipo = trim($_POST['nombre_equipo'] ?? '');
            $area = trim($_POST['area'] ?? 'General');
            $usuario_responsable = trim($_POST['usuario_responsable'] ?? '');
            $tipo_mantenimiento = trim($_POST['tipo_mantenimiento'] ?? 'Preventivo');
            $fecha_programada = trim($_POST['fecha_programada'] ?? date('Y-m-d'));
            $fecha_realizada = !empty($_POST['fecha_realizada']) ? trim($_POST['fecha_realizada']) : null;
            $frecuencia = trim($_POST['frecuencia'] ?? 'Semestral');
            $estado = trim($_POST['estado'] ?? 'Programado');
            $tecnico_responsable = trim($_POST['tecnico_responsable'] ?? 'Sistemas');
            $observaciones = trim($_POST['observaciones'] ?? '');

            if (empty($nombre_equipo)) {
                echo json_encode(['success' => false, 'error' => 'El nombre del equipo es obligatorio']);
                exit();
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE audit_mantenimientos SET 
                        nombre_equipo = ?, area = ?, usuario_responsable = ?, tipo_mantenimiento = ?,
                        fecha_programada = ?, fecha_realizada = ?, frecuencia = ?, estado = ?,
                        tecnico_responsable = ?, observaciones = ?, actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $nombre_equipo, $area, $usuario_responsable, $tipo_mantenimiento,
                    $fecha_programada, $fecha_realizada, $frecuencia, $estado,
                    $tecnico_responsable, $observaciones, $id
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Plan de mantenimiento actualizado correctamente']);
            } else {
                $creadoPor = $_SESSION['usuario_nombre'] ?? 'Sistemas';
                $stmt = $pdo->prepare("
                    INSERT INTO audit_mantenimientos 
                    (nombre_equipo, area, usuario_responsable, tipo_mantenimiento, fecha_programada, fecha_realizada, frecuencia, estado, tecnico_responsable, observaciones, creado_por)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre_equipo, $area, $usuario_responsable, $tipo_mantenimiento,
                    $fecha_programada, $fecha_realizada, $frecuencia, $estado,
                    $tecnico_responsable, $observaciones, $creadoPor
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Mantenimiento programado exitosamente']);
            }
            break;

        case 'subir_evidencia_mantenimiento':
            requerirPermiso('auditoria_interna', 'puede_editar');

            $id = intval($_POST['mantenimiento_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID de mantenimiento inválido']);
                exit();
            }

            if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Seleccione un archivo PDF de evidencia válido']);
                exit();
            }

            $ext = strtolower(pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                echo json_encode(['success' => false, 'error' => 'Únicamente se permiten archivos en formato PDF']);
                exit();
            }

            $targetDir = __DIR__ . '/uploads/auditoria/mantenimiento';
            if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

            $nombreArchivo = 'EVID_MANT_' . $id . '_' . date('Ymd_His') . '.pdf';
            $targetPath = $targetDir . '/' . $nombreArchivo;
            $relUrl = 'uploads/auditoria/mantenimiento/' . $nombreArchivo;

            if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $targetPath)) {
                $marcarRealizado = !empty($_POST['marcar_realizado']);
                $fRealizada = date('Y-m-d');

                if ($marcarRealizado) {
                    $stmt = $pdo->prepare("UPDATE audit_mantenimientos SET evidencia_pdf_url = ?, estado = 'Realizado', fecha_realizada = ? WHERE id = ?");
                    $stmt->execute([$relUrl, $fRealizada, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE audit_mantenimientos SET evidencia_pdf_url = ? WHERE id = ?");
                    $stmt->execute([$relUrl, $id]);
                }

                echo json_encode(['success' => true, 'mensaje' => 'Expediente de evidencia en PDF guardado correctamente', 'url' => $relUrl]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al mover el archivo de evidencia']);
            }
            break;

        case 'eliminar_mantenimiento':
            requerirPermiso('auditoria_interna', 'puede_eliminar');
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM audit_mantenimientos WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'mensaje' => 'Registro de mantenimiento eliminado']);
            break;

        // =====================================================================
        // 3. CONTROL DE CORREOS (MATRIZ, BAJAS Y ELIMINACIONES)
        // =====================================================================
        case 'listar_correos':
            requerirPermiso('auditoria_interna', 'puede_ver');

            $area = trim($_GET['area'] ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $busqueda = trim($_GET['busqueda'] ?? '');

            $sql = "SELECT * FROM audit_correos WHERE 1=1";
            $params = [];

            if (!empty($area)) {
                $sql .= " AND area = ?";
                $params[] = $area;
            }
            if (!empty($estado)) {
                $sql .= " AND estado = ?";
                $params[] = $estado;
            }
            if (!empty($busqueda)) {
                $sql .= " AND (LOWER(nombre_colaborador) LIKE ? OR LOWER(correo) LIKE ? OR LOWER(puesto) LIKE ?)";
                $bParam = '%' . mb_strtolower($busqueda) . '%';
                $params[] = $bParam;
                $params[] = $bParam;
                $params[] = $bParam;
            }

            $sql .= " ORDER BY estado ASC, area ASC, nombre_colaborador ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'total' => count($rows), 'datos' => $rows]);
            break;

        case 'guardar_correo':
            requerirPermiso('auditoria_interna', 'puede_crear');

            $id = intval($_POST['id'] ?? 0);
            $nombre_colaborador = trim($_POST['nombre_colaborador'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $area = trim($_POST['area'] ?? 'General');
            $puesto = trim($_POST['puesto'] ?? '');
            $tipo_cuenta = trim($_POST['tipo_cuenta'] ?? 'Corporativo');
            $estado = trim($_POST['estado'] ?? 'Activo');
            $fecha_alta = !empty($_POST['fecha_alta']) ? trim($_POST['fecha_alta']) : date('Y-m-d');
            $fecha_baja = !empty($_POST['fecha_baja']) ? trim($_POST['fecha_baja']) : null;
            $motivo_baja = trim($_POST['motivo_baja'] ?? '');
            $contrasena_guardada = trim($_POST['contrasena_guardada'] ?? '');
            $observaciones = trim($_POST['observaciones'] ?? '');

            if (empty($nombre_colaborador) || empty($correo)) {
                echo json_encode(['success' => false, 'error' => 'Nombre y correo son obligatorios']);
                exit();
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE audit_correos SET 
                        nombre_colaborador = ?, correo = ?, area = ?, puesto = ?, tipo_cuenta = ?,
                        estado = ?, fecha_alta = ?, fecha_baja = ?, motivo_baja = ?,
                        contrasena_guardada = ?, observaciones = ?, actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $nombre_colaborador, $correo, $area, $puesto, $tipo_cuenta,
                    $estado, $fecha_alta, $fecha_baja, $motivo_baja,
                    $contrasena_guardada, $observaciones, $id
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Cuenta de correo actualizada correctamente']);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_correos 
                    (nombre_colaborador, correo, area, puesto, tipo_cuenta, estado, fecha_alta, fecha_baja, motivo_baja, contrasena_guardada, observaciones)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre_colaborador, $correo, $area, $puesto, $tipo_cuenta,
                    $estado, $fecha_alta, $fecha_baja, $motivo_baja, $contrasena_guardada, $observaciones
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Cuenta de correo registrada']);
            }
            break;

        case 'cambiar_estado_correo':
            requerirPermiso('auditoria_interna', 'puede_editar');

            $id = intval($_POST['id'] ?? 0);
            $nuevoEstado = trim($_POST['estado'] ?? 'En Baja'); // 'Activo', 'En Baja', 'Eliminado'
            $motivo = trim($_POST['motivo_baja'] ?? '');
            $fechaBaja = ($nuevoEstado !== 'Activo') ? date('Y-m-d') : null;

            $stmt = $pdo->prepare("UPDATE audit_correos SET estado = ?, fecha_baja = ?, motivo_baja = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$nuevoEstado, $fechaBaja, $motivo, $id]);

            echo json_encode(['success' => true, 'mensaje' => "Estado de correo cambiado a $nuevoEstado"]);
            break;

        case 'eliminar_correo':
            requerirPermiso('auditoria_interna', 'puede_eliminar');
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM audit_correos WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'mensaje' => 'Registro de correo eliminado']);
            break;

        case 'sincronizar_correos':
            requerirPermiso('auditoria_interna', 'puede_crear');

            $stmtEx = $pdo->query("SELECT LOWER(correo) FROM audit_correos");
            $existentes = $stmtEx ? $stmtEx->fetchAll(PDO::FETCH_COLUMN) : [];
            $existentesMap = array_fill_keys($existentes, true);

            $stmtInsC = $pdo->prepare("
                INSERT INTO audit_correos (nombre_colaborador, correo, area, puesto, tipo_cuenta, estado, fecha_alta, contrasena_guardada, usuario_id)
                VALUES (?, ?, ?, ?, 'Corporativo', 'Activo', ?, ?, ?)
            ");

            $nuevos = 0;
            // 1. Desde usuarios
            try {
                $stmtU = $pdo->query("SELECT id, nombre, email, area, puesto, contrasena_correo, creado_en FROM usuarios WHERE email IS NOT NULL AND email != ''");
                if ($stmtU) {
                    while ($u = $stmtU->fetch(PDO::FETCH_ASSOC)) {
                        $em = strtolower(trim($u['email']));
                        if (!empty($em) && !isset($existentesMap[$em])) {
                            $existentesMap[$em] = true;
                            $fAlta = !empty($u['creado_en']) ? substr($u['creado_en'], 0, 10) : date('Y-m-d');
                            $stmtInsC->execute([$u['nombre'] ?: 'Colaborador', $em, $u['area'] ?: 'General', $u['puesto'] ?: 'Colaborador', $fAlta, $u['contrasena_correo'] ?? '', $u['id']]);
                            $nuevos++;
                        }
                    }
                }
            } catch (Throwable $e1) {}

            // 2. Desde inventario
            try {
                $stmtEq = $pdo->query("SELECT DISTINCT usuario, correo, departamento, puesto FROM inv_equipos_vw WHERE correo IS NOT NULL AND correo != ''");
                if ($stmtEq) {
                    while ($eq = $stmtEq->fetch(PDO::FETCH_ASSOC)) {
                        $em = strtolower(trim($eq['correo']));
                        if (!empty($em) && !isset($existentesMap[$em])) {
                            $existentesMap[$em] = true;
                            $stmtInsC->execute([$eq['usuario'] ?: 'Usuario Equipo', $em, $eq['departamento'] ?: 'General', $eq['puesto'] ?: 'Usuario', date('Y-m-d'), '', null]);
                            $nuevos++;
                        }
                    }
                }
            } catch (Throwable $e2) {}

            echo json_encode([
                'success' => true,
                'mensaje' => "Sincronización completada. Se importaron $nuevos cuentas nuevas de correo."
            ]);
            break;

        // =====================================================================
        // 4. LICENCIAS DE OFFICE
        // =====================================================================
        case 'listar_licencias':
            requerirPermiso('auditoria_interna', 'puede_ver');

            $area = trim($_GET['area'] ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $busqueda = trim($_GET['busqueda'] ?? '');

            $sql = "SELECT * FROM audit_licencias_office WHERE 1=1";
            $params = [];

            if (!empty($area)) {
                $sql .= " AND area = ?";
                $params[] = $area;
            }
            if (!empty($estado)) {
                $sql .= " AND estado = ?";
                $params[] = $estado;
            }
            if (!empty($busqueda)) {
                $sql .= " AND (LOWER(codigo_licencia) LIKE ? OR LOWER(nombre_producto) LIKE ? OR LOWER(usuario_asignado) LIKE ? OR LOWER(equipo_asignado) LIKE ?)";
                $bParam = '%' . mb_strtolower($busqueda) . '%';
                $params[] = $bParam;
                $params[] = $bParam;
                $params[] = $bParam;
                $params[] = $bParam;
            }

            $sql .= " ORDER BY estado ASC, nombre_producto ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'total' => count($rows), 'datos' => $rows]);
            break;

        case 'guardar_licencia':
            requerirPermiso('auditoria_interna', 'puede_crear');

            $id = intval($_POST['id'] ?? 0);
            $codigo_licencia = trim($_POST['codigo_licencia'] ?? '');
            $nombre_producto = trim($_POST['nombre_producto'] ?? 'Microsoft 365 Business Standard');
            $tipo_licencia = trim($_POST['tipo_licencia'] ?? 'Digital / ESD');
            $cuenta_microsoft = trim($_POST['cuenta_microsoft'] ?? '');
            $equipo_asignado = trim($_POST['equipo_asignado'] ?? '');
            $usuario_asignado = trim($_POST['usuario_asignado'] ?? '');
            $area = trim($_POST['area'] ?? 'General');
            $estado = trim($_POST['estado'] ?? 'Activa');
            $fecha_compra = !empty($_POST['fecha_compra']) ? trim($_POST['fecha_compra']) : null;
            $fecha_vencimiento = !empty($_POST['fecha_vencimiento']) ? trim($_POST['fecha_vencimiento']) : null;
            $notas = trim($_POST['notas'] ?? '');

            if (empty($codigo_licencia) || empty($nombre_producto)) {
                echo json_encode(['success' => false, 'error' => 'Código de licencia y nombre de producto son obligatorios']);
                exit();
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE audit_licencias_office SET 
                        codigo_licencia = ?, nombre_producto = ?, tipo_licencia = ?, cuenta_microsoft = ?,
                        equipo_asignado = ?, usuario_asignado = ?, area = ?, estado = ?,
                        fecha_compra = ?, fecha_vencimiento = ?, notas = ?, actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $codigo_licencia, $nombre_producto, $tipo_licencia, $cuenta_microsoft,
                    $equipo_asignado, $usuario_asignado, $area, $estado,
                    $fecha_compra, $fecha_vencimiento, $notas, $id
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Licencia de Office actualizada correctamente']);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_licencias_office 
                    (codigo_licencia, nombre_producto, tipo_licencia, cuenta_microsoft, equipo_asignado, usuario_asignado, area, estado, fecha_compra, fecha_vencimiento, notas)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $codigo_licencia, $nombre_producto, $tipo_licencia, $cuenta_microsoft,
                    $equipo_asignado, $usuario_asignado, $area, $estado,
                    $fecha_compra, $fecha_vencimiento, $notas
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Licencia registrada exitosamente']);
            }
            break;

        case 'eliminar_licencia':
            requerirPermiso('auditoria_interna', 'puede_eliminar');
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM audit_licencias_office WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'mensaje' => 'Licencia eliminada']);
            break;

        // =====================================================================
        // 5. SEGURIDAD: USUARIOS GDS
        // =====================================================================
        case 'listar_gds_usuarios':
            requerirPermiso('auditoria_interna', 'puede_ver');

            $area = trim($_GET['area'] ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $busqueda = trim($_GET['busqueda'] ?? '');

            $sql = "SELECT * FROM audit_gds_usuarios WHERE 1=1";
            $params = [];

            if (!empty($area)) {
                $sql .= " AND area = ?";
                $params[] = $area;
            }
            if (!empty($estado)) {
                $sql .= " AND estado = ?";
                $params[] = $estado;
            }
            if (!empty($busqueda)) {
                $sql .= " AND (LOWER(colaborador) LIKE ? OR LOWER(usuario_gds) LIKE ? OR LOWER(perfil_gds) LIKE ?)";
                $bParam = '%' . mb_strtolower($busqueda) . '%';
                $params[] = $bParam;
                $params[] = $bParam;
                $params[] = $bParam;
            }

            $sql .= " ORDER BY estado ASC, colaborador ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'total' => count($rows), 'datos' => $rows]);
            break;

        case 'guardar_gds_usuario':
            requerirPermiso('auditoria_interna', 'puede_crear');

            $id = intval($_POST['id'] ?? 0);
            $colaborador = trim($_POST['colaborador'] ?? '');
            $usuario_id = !empty($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;
            $area = trim($_POST['area'] ?? 'General');
            $puesto = trim($_POST['puesto'] ?? '');
            $usuario_gds = trim($_POST['usuario_gds'] ?? '');
            $password_gds = trim($_POST['password_gds'] ?? '');
            $perfil_gds = trim($_POST['perfil_gds'] ?? 'Operativo');
            $sucursal = trim($_POST['sucursal'] ?? 'Matriz');
            $estado = trim($_POST['estado'] ?? 'Activo');
            $fecha_asignacion = !empty($_POST['fecha_asignacion']) ? trim($_POST['fecha_asignacion']) : date('Y-m-d');
            $notas = trim($_POST['notas'] ?? '');

            if (empty($colaborador) || empty($usuario_gds) || empty($password_gds)) {
                echo json_encode(['success' => false, 'error' => 'Colaborador, usuario GDS y contraseña son obligatorios']);
                exit();
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE audit_gds_usuarios SET 
                        colaborador = ?, usuario_id = ?, area = ?, puesto = ?, usuario_gds = ?,
                        password_gds = ?, perfil_gds = ?, sucursal = ?, estado = ?,
                        fecha_asignacion = ?, notas = ?, actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $colaborador, $usuario_id, $area, $puesto, $usuario_gds,
                    $password_gds, $perfil_gds, $sucursal, $estado,
                    $fecha_asignacion, $notas, $id
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Usuario GDS actualizado']);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_gds_usuarios 
                    (colaborador, usuario_id, area, puesto, usuario_gds, password_gds, perfil_gds, sucursal, estado, fecha_asignacion, notas)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $colaborador, $usuario_id, $area, $puesto, $usuario_gds,
                    $password_gds, $perfil_gds, $sucursal, $estado, $fecha_asignacion, $notas
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Usuario GDS registrado exitosamente']);
            }
            break;

        case 'subir_responsiva_gds_usuario':
            requerirPermiso('auditoria_interna', 'puede_editar');

            $id = intval($_POST['gds_usuario_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido']);
                exit();
            }

            if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Seleccione un archivo PDF válido']);
                exit();
            }

            $targetDir = __DIR__ . '/uploads/auditoria/gds_usuarios';
            if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

            $nombreArchivo = 'RESP_GDS_USR_' . $id . '_' . date('Ymd_His') . '.pdf';
            $targetPath = $targetDir . '/' . $nombreArchivo;
            $relUrl = 'uploads/auditoria/gds_usuarios/' . $nombreArchivo;

            if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $targetPath)) {
                $stmt = $pdo->prepare("UPDATE audit_gds_usuarios SET responsiva_firmada_url = ? WHERE id = ?");
                $stmt->execute([$relUrl, $id]);
                echo json_encode(['success' => true, 'mensaje' => 'Responsiva firmada de usuario GDS guardada', 'url' => $relUrl]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al guardar PDF']);
            }
            break;

        case 'eliminar_gds_usuario':
            requerirPermiso('auditoria_interna', 'puede_eliminar');
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM audit_gds_usuarios WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'mensaje' => 'Usuario GDS eliminado']);
            break;

        // =====================================================================
        // 6. SEGURIDAD: CONTRASEÑAS DE MOVIMIENTOS GDS
        // =====================================================================
        case 'listar_gds_passwords':
            requerirPermiso('auditoria_interna', 'puede_ver');

            $area = trim($_GET['area'] ?? '');
            $nivel = trim($_GET['nivel'] ?? '');
            $busqueda = trim($_GET['busqueda'] ?? '');

            $sql = "SELECT * FROM audit_gds_passwords_movimientos WHERE 1=1";
            $params = [];

            if (!empty($area)) {
                $sql .= " AND area = ?";
                $params[] = $area;
            }
            if (!empty($nivel)) {
                $sql .= " AND nivel_seguridad = ?";
                $params[] = $nivel;
            }
            if (!empty($busqueda)) {
                $sql .= " AND (LOWER(tipo_movimiento) LIKE ? OR LOWER(usuario_autorizado) LIKE ? OR LOWER(descripcion_movimiento) LIKE ?)";
                $bParam = '%' . mb_strtolower($busqueda) . '%';
                $params[] = $bParam;
                $params[] = $bParam;
                $params[] = $bParam;
            }

            $sql .= " ORDER BY nivel_seguridad ASC, tipo_movimiento ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'total' => count($rows), 'datos' => $rows]);
            break;

        case 'guardar_gds_password':
            requerirPermiso('auditoria_interna', 'puede_crear');

            $id = intval($_POST['id'] ?? 0);
            $tipo_movimiento = trim($_POST['tipo_movimiento'] ?? '');
            $descripcion_movimiento = trim($_POST['descripcion_movimiento'] ?? '');
            $password_movimiento = trim($_POST['password_movimiento'] ?? '');
            $usuario_autorizado = trim($_POST['usuario_autorizado'] ?? '');
            $puesto_autorizado = trim($_POST['puesto_autorizado'] ?? '');
            $area = trim($_POST['area'] ?? 'General');
            $nivel_seguridad = trim($_POST['nivel_seguridad'] ?? 'Alto');
            $fecha_actualizacion = !empty($_POST['fecha_actualizacion']) ? trim($_POST['fecha_actualizacion']) : date('Y-m-d');
            $fecha_vigencia = !empty($_POST['fecha_vigencia']) ? trim($_POST['fecha_vigencia']) : null;
            $estado = trim($_POST['estado'] ?? 'Activo');
            $notas = trim($_POST['notas'] ?? '');

            if (empty($tipo_movimiento) || empty($password_movimiento) || empty($usuario_autorizado)) {
                echo json_encode(['success' => false, 'error' => 'Tipo de movimiento, contraseña y usuario autorizado son obligatorios']);
                exit();
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE audit_gds_passwords_movimientos SET 
                        tipo_movimiento = ?, descripcion_movimiento = ?, password_movimiento = ?,
                        usuario_autorizado = ?, puesto_autorizado = ?, area = ?, nivel_seguridad = ?,
                        fecha_actualizacion = ?, fecha_vigencia = ?, estado = ?, notas = ?, actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $tipo_movimiento, $descripcion_movimiento, $password_movimiento,
                    $usuario_autorizado, $puesto_autorizado, $area, $nivel_seguridad,
                    $fecha_actualizacion, $fecha_vigencia, $estado, $notas, $id
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Contraseña de movimiento actualizada']);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_gds_passwords_movimientos 
                    (tipo_movimiento, descripcion_movimiento, password_movimiento, usuario_autorizado, puesto_autorizado, area, nivel_seguridad, fecha_actualizacion, fecha_vigencia, estado, notas)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $tipo_movimiento, $descripcion_movimiento, $password_movimiento,
                    $usuario_autorizado, $puesto_autorizado, $area, $nivel_seguridad,
                    $fecha_actualizacion, $fecha_vigencia, $estado, $notas
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Contraseña de movimiento registrada']);
            }
            break;

        case 'subir_responsiva_gds_password':
            requerirPermiso('auditoria_interna', 'puede_editar');

            $id = intval($_POST['movimiento_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido']);
                exit();
            }

            if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Seleccione un archivo PDF válido']);
                exit();
            }

            $targetDir = __DIR__ . '/uploads/auditoria/gds_passwords';
            if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

            $nombreArchivo = 'RESP_MOV_' . $id . '_' . date('Ymd_His') . '.pdf';
            $targetPath = $targetDir . '/' . $nombreArchivo;
            $relUrl = 'uploads/auditoria/gds_passwords/' . $nombreArchivo;

            if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $targetPath)) {
                $stmt = $pdo->prepare("UPDATE audit_gds_passwords_movimientos SET responsiva_firmada_url = ? WHERE id = ?");
                $stmt->execute([$relUrl, $id]);
                echo json_encode(['success' => true, 'mensaje' => 'Responsiva de clave de movimiento guardada', 'url' => $relUrl]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al mover PDF']);
            }
            break;

        case 'eliminar_gds_password':
            requerirPermiso('auditoria_interna', 'puede_eliminar');
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM audit_gds_passwords_movimientos WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'mensaje' => 'Clave de movimiento eliminada']);
            break;

        // =====================================================================
        // 7. SEGURIDAD: CONTROL DE USUARIOS GRP
        // =====================================================================
        case 'listar_grp_usuarios':
            requerirPermiso('auditoria_interna', 'puede_ver');

            $area = trim($_GET['area'] ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $busqueda = trim($_GET['busqueda'] ?? '');

            $sql = "SELECT * FROM audit_grp_usuarios WHERE 1=1";
            $params = [];

            if (!empty($area)) {
                $sql .= " AND area = ?";
                $params[] = $area;
            }
            if (!empty($estado)) {
                $sql .= " AND estado = ?";
                $params[] = $estado;
            }
            if (!empty($busqueda)) {
                $sql .= " AND (LOWER(colaborador) LIKE ? OR LOWER(usuario_grp) LIKE ? OR LOWER(roles_grp) LIKE ?)";
                $bParam = '%' . mb_strtolower($busqueda) . '%';
                $params[] = $bParam;
                $params[] = $bParam;
                $params[] = $bParam;
            }

            $sql .= " ORDER BY estado ASC, colaborador ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'total' => count($rows), 'datos' => $rows]);
            break;

        case 'guardar_grp_usuario':
            requerirPermiso('auditoria_interna', 'puede_crear');

            $id = intval($_POST['id'] ?? 0);
            $colaborador = trim($_POST['colaborador'] ?? '');
            $usuario_id = !empty($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;
            $area = trim($_POST['area'] ?? 'General');
            $puesto = trim($_POST['puesto'] ?? '');
            $usuario_grp = trim($_POST['usuario_grp'] ?? '');
            $password_grp = trim($_POST['password_grp'] ?? '');
            $roles_grp = trim($_POST['roles_grp'] ?? 'Consultas');
            $modulos_permitidos = trim($_POST['modulos_permitidos'] ?? '');
            $fecha_alta = !empty($_POST['fecha_alta']) ? trim($_POST['fecha_alta']) : date('Y-m-d');
            $estado = trim($_POST['estado'] ?? 'Activo');
            $notas_auditoria = trim($_POST['notas_auditoria'] ?? '');

            if (empty($colaborador) || empty($usuario_grp) || empty($password_grp) || empty($roles_grp)) {
                echo json_encode(['success' => false, 'error' => 'Colaborador, usuario GRP, contraseña y roles son obligatorios']);
                exit();
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE audit_grp_usuarios SET 
                        colaborador = ?, usuario_id = ?, area = ?, puesto = ?, usuario_grp = ?,
                        password_grp = ?, roles_grp = ?, modulos_permitidos = ?, fecha_alta = ?,
                        estado = ?, notas_auditoria = ?, actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $colaborador, $usuario_id, $area, $puesto, $usuario_grp,
                    $password_grp, $roles_grp, $modulos_permitidos, $fecha_alta,
                    $estado, $notas_auditoria, $id
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Usuario GRP actualizado correctamente']);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_grp_usuarios 
                    (colaborador, usuario_id, area, puesto, usuario_grp, password_grp, roles_grp, modulos_permitidos, fecha_alta, estado, notas_auditoria)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $colaborador, $usuario_id, $area, $puesto, $usuario_grp,
                    $password_grp, $roles_grp, $modulos_permitidos, $fecha_alta, $estado, $notas_auditoria
                ]);
                echo json_encode(['success' => true, 'mensaje' => 'Usuario GRP registrado exitosamente']);
            }
            break;

        case 'subir_responsiva_grp_usuario':
            requerirPermiso('auditoria_interna', 'puede_editar');

            $id = intval($_POST['grp_usuario_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido']);
                exit();
            }

            if (!isset($_FILES['archivo_pdf']) || $_FILES['archivo_pdf']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Seleccione un archivo PDF válido']);
                exit();
            }

            $targetDir = __DIR__ . '/uploads/auditoria/grp';
            if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

            $nombreArchivo = 'RESP_GRP_USR_' . $id . '_' . date('Ymd_His') . '.pdf';
            $targetPath = $targetDir . '/' . $nombreArchivo;
            $relUrl = 'uploads/auditoria/grp/' . $nombreArchivo;

            if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $targetPath)) {
                $stmt = $pdo->prepare("UPDATE audit_grp_usuarios SET responsiva_firmada_url = ? WHERE id = ?");
                $stmt->execute([$relUrl, $id]);
                echo json_encode(['success' => true, 'mensaje' => 'Responsiva firmada de usuario GRP guardada', 'url' => $relUrl]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al mover PDF']);
            }
            break;

        case 'eliminar_grp_usuario':
            requerirPermiso('auditoria_interna', 'puede_eliminar');
            $id = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM audit_grp_usuarios WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'mensaje' => 'Usuario GRP eliminado']);
            break;

        // =====================================================================
        // EXPORTACIÓN A EXCEL POR BACKEND (RESPALDO MHTML CORPORATIVO)
        // =====================================================================
        case 'exportar_excel_seccion':
            requerirPermiso('auditoria_interna', 'puede_exportar');

            $seccion = trim($_GET['seccion'] ?? 'mantenimientos');
            $agenciaData = obtenerDatosAgenciaExcel($pdo, $_SESSION['agencia'] ?? '');

            if ($seccion === 'mantenimientos') {
                $columnas = [
                    ['label' => 'Equipo', 'width' => '170px'],
                    ['label' => 'Área / Depto', 'width' => '150px'],
                    ['label' => 'Usuario Responsable', 'width' => '180px'],
                    ['label' => 'Tipo Mantenimiento', 'width' => '140px', 'align' => 'center'],
                    ['label' => 'Fecha Programada', 'width' => '130px', 'align' => 'center'],
                    ['label' => 'Fecha Realizada', 'width' => '130px', 'align' => 'center'],
                    ['label' => 'Frecuencia', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Estado', 'width' => '120px', 'align' => 'center'],
                    ['label' => 'Técnico Responsable', 'width' => '150px'],
                    ['label' => 'Tiene Evidencia PDF', 'width' => '120px', 'align' => 'center'],
                    ['label' => 'Observaciones', 'width' => '250px']
                ];
                $rows = $pdo->query("SELECT * FROM audit_mantenimientos ORDER BY fecha_programada ASC")->fetchAll(PDO::FETCH_ASSOC);
                $filas = [];
                foreach ($rows as $r) {
                    $filas[] = [
                        ['val' => '<b>' . htmlspecialchars($r['nombre_equipo']) . '</b>'],
                        ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($r['area']) . '</span>'],
                        ['val' => htmlspecialchars($r['usuario_responsable'] ?: 'N/A')],
                        ['val' => htmlspecialchars($r['tipo_mantenimiento']), 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_programada']), 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_realizada'] ?: '-'), 'align' => 'center'],
                        ['val' => htmlspecialchars($r['frecuencia']), 'align' => 'center'],
                        ['val' => '<span class="badge-pill ' . ($r['estado'] === 'Realizado' ? 'badge-status-ok' : ($r['estado'] === 'Atrasado' ? 'badge-status-err' : 'badge-status-warn')) . '">' . htmlspecialchars($r['estado']) . '</span>', 'align' => 'center'],
                        ['val' => htmlspecialchars($r['tecnico_responsable'] ?: 'Sistemas')],
                        ['val' => !empty($r['evidencia_pdf_url']) ? '<span style="color: #15803d; font-weight: bold;">SÍ (PDF)</span>' : '<span style="color: #b91c1c;">PENDIENTE</span>', 'align' => 'center'],
                        ['val' => htmlspecialchars($r['observaciones'] ?: '')]
                    ];
                }
                descargarExcelConDiseno('Auditoría - Planes de Mantenimiento', 'Cronograma y Evidencias de Mantenimiento', $columnas, $filas, $agenciaData, 'Planes_Mantenimiento_' . date('Ymd_His') . '.xls', 'Mantenimientos');
            } elseif ($seccion === 'correos') {
                $columnas = [
                    ['label' => 'Colaborador', 'width' => '200px'],
                    ['label' => 'Correo Electrónico', 'width' => '230px'],
                    ['label' => 'Área / Depto', 'width' => '150px'],
                    ['label' => 'Puesto', 'width' => '170px'],
                    ['label' => 'Tipo Cuenta', 'width' => '120px', 'align' => 'center'],
                    ['label' => 'Estado', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Fecha Alta', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Fecha Baja', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Motivo de Baja', 'width' => '220px']
                ];
                $rows = $pdo->query("SELECT * FROM audit_correos ORDER BY estado ASC, area ASC, nombre_colaborador ASC")->fetchAll(PDO::FETCH_ASSOC);
                $filas = [];
                foreach ($rows as $r) {
                    $badge = ($r['estado'] === 'Activo') ? 'badge-status-ok' : (($r['estado'] === 'En Baja') ? 'badge-status-warn' : 'badge-status-err');
                    $filas[] = [
                        ['val' => '<b>' . htmlspecialchars($r['nombre_colaborador']) . '</b>'],
                        ['val' => '<code>' . htmlspecialchars($r['correo']) . '</code>'],
                        ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($r['area']) . '</span>'],
                        ['val' => htmlspecialchars($r['puesto'] ?: 'N/A')],
                        ['val' => htmlspecialchars($r['tipo_cuenta']), 'align' => 'center'],
                        ['val' => '<span class="badge-pill ' . $badge . '">' . htmlspecialchars($r['estado']) . '</span>', 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_alta'] ?: '-'), 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_baja'] ?: '-'), 'align' => 'center'],
                        ['val' => htmlspecialchars($r['motivo_baja'] ?: '')]
                    ];
                }
                descargarExcelConDiseno('Auditoría - Control de Correos', 'Matriz de Cuentas, Altas y Bajas', $columnas, $filas, $agenciaData, 'Control_Correos_' . date('Ymd_His') . '.xls', 'Correos');
            } elseif ($seccion === 'gds_usuarios') {
                $columnas = [
                    ['label' => 'Colaborador', 'width' => '200px'],
                    ['label' => 'Usuario GDS', 'width' => '150px'],
                    ['label' => 'Contraseña GDS', 'width' => '150px'],
                    ['label' => 'Área / Depto', 'width' => '150px'],
                    ['label' => 'Puesto', 'width' => '160px'],
                    ['label' => 'Perfil GDS', 'width' => '160px'],
                    ['label' => 'Sucursal', 'width' => '120px'],
                    ['label' => 'Estado', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Fecha Asignación', 'width' => '130px', 'align' => 'center'],
                    ['label' => 'Responsiva Firmada', 'width' => '130px', 'align' => 'center']
                ];
                $rows = $pdo->query("SELECT * FROM audit_gds_usuarios ORDER BY estado ASC, colaborador ASC")->fetchAll(PDO::FETCH_ASSOC);
                $filas = [];
                foreach ($rows as $r) {
                    $filas[] = [
                        ['val' => '<b>' . htmlspecialchars($r['colaborador']) . '</b>'],
                        ['val' => '<code>' . htmlspecialchars($r['usuario_gds']) . '</code>'],
                        ['val' => '<code>' . htmlspecialchars($r['password_gds']) . '</code>', 'is_text' => true],
                        ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($r['area']) . '</span>'],
                        ['val' => htmlspecialchars($r['puesto'] ?: 'N/A')],
                        ['val' => htmlspecialchars($r['perfil_gds'] ?: 'General')],
                        ['val' => htmlspecialchars($r['sucursal'] ?: 'Matriz')],
                        ['val' => '<span class="badge-pill ' . ($r['estado'] === 'Activo' ? 'badge-status-ok' : 'badge-status-err') . '">' . htmlspecialchars($r['estado']) . '</span>', 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_asignacion'] ?: '-'), 'align' => 'center'],
                        ['val' => !empty($r['responsiva_firmada_url']) ? '<span style="color: #15803d; font-weight: bold;">SÍ (FIRMADA)</span>' : '<span style="color: #b91c1c;">PENDIENTE</span>', 'align' => 'center']
                    ];
                }
                descargarExcelConDiseno('Auditoría - Usuarios GDS', 'Control de Accesos y Credenciales DMS/GDS', $columnas, $filas, $agenciaData, 'Usuarios_GDS_' . date('Ymd_His') . '.xls', 'Usuarios GDS');
            } elseif ($seccion === 'gds_passwords') {
                $columnas = [
                    ['label' => 'Tipo de Movimiento', 'width' => '240px'],
                    ['label' => 'Descripción', 'width' => '250px'],
                    ['label' => 'Contraseña de Autorización', 'width' => '180px'],
                    ['label' => 'Usuario / Gerente Autorizado', 'width' => '200px'],
                    ['label' => 'Puesto', 'width' => '160px'],
                    ['label' => 'Área', 'width' => '140px'],
                    ['label' => 'Nivel Seguridad', 'width' => '120px', 'align' => 'center'],
                    ['label' => 'Fecha Modificación', 'width' => '130px', 'align' => 'center'],
                    ['label' => 'Responsiva Firmada', 'width' => '130px', 'align' => 'center']
                ];
                $rows = $pdo->query("SELECT * FROM audit_gds_passwords_movimientos ORDER BY nivel_seguridad ASC, tipo_movimiento ASC")->fetchAll(PDO::FETCH_ASSOC);
                $filas = [];
                foreach ($rows as $r) {
                    $filas[] = [
                        ['val' => '<b>' . htmlspecialchars($r['tipo_movimiento']) . '</b>'],
                        ['val' => htmlspecialchars($r['descripcion_movimiento'] ?: '')],
                        ['val' => '<code style="color: #b91c1c; font-weight: bold;">' . htmlspecialchars($r['password_movimiento']) . '</code>', 'is_text' => true],
                        ['val' => '<b>' . htmlspecialchars($r['usuario_autorizado']) . '</b>'],
                        ['val' => htmlspecialchars($r['puesto_autorizado'] ?: 'Gerente')],
                        ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($r['area']) . '</span>'],
                        ['val' => '<span class="badge-pill badge-status-err">' . htmlspecialchars($r['nivel_seguridad']) . '</span>', 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_actualizacion'] ?: '-'), 'align' => 'center'],
                        ['val' => !empty($r['responsiva_firmada_url']) ? '<span style="color: #15803d; font-weight: bold;">SÍ (FIRMADA)</span>' : '<span style="color: #b91c1c;">PENDIENTE</span>', 'align' => 'center']
                    ];
                }
                descargarExcelConDiseno('Auditoría - Contraseñas de Movimientos GDS', 'Claves de Autorización Especial y Auditoría', $columnas, $filas, $agenciaData, 'Passwords_Movimientos_GDS_' . date('Ymd_His') . '.xls', 'Claves Movimientos');
            } elseif ($seccion === 'grp') {
                $columnas = [
                    ['label' => 'Colaborador', 'width' => '200px'],
                    ['label' => 'Usuario GRP', 'width' => '150px'],
                    ['label' => 'Contraseña GRP', 'width' => '150px'],
                    ['label' => 'Área / Depto', 'width' => '150px'],
                    ['label' => 'Puesto', 'width' => '160px'],
                    ['label' => 'Roles Asignados en GRP', 'width' => '240px'],
                    ['label' => 'Módulos Permitidos', 'width' => '200px'],
                    ['label' => 'Estado', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Fecha Alta', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Responsiva Firmada', 'width' => '130px', 'align' => 'center']
                ];
                $rows = $pdo->query("SELECT * FROM audit_grp_usuarios ORDER BY estado ASC, colaborador ASC")->fetchAll(PDO::FETCH_ASSOC);
                $filas = [];
                foreach ($rows as $r) {
                    $filas[] = [
                        ['val' => '<b>' . htmlspecialchars($r['colaborador']) . '</b>'],
                        ['val' => '<code>' . htmlspecialchars($r['usuario_grp']) . '</code>'],
                        ['val' => '<code>' . htmlspecialchars($r['password_grp']) . '</code>', 'is_text' => true],
                        ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($r['area']) . '</span>'],
                        ['val' => htmlspecialchars($r['puesto'] ?: 'N/A')],
                        ['val' => htmlspecialchars($r['roles_grp'] ?: 'Consulta')],
                        ['val' => htmlspecialchars($r['modulos_permitidos'] ?: 'Todos')],
                        ['val' => '<span class="badge-pill ' . ($r['estado'] === 'Activo' ? 'badge-status-ok' : 'badge-status-err') . '">' . htmlspecialchars($r['estado']) . '</span>', 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_alta'] ?: '-'), 'align' => 'center'],
                        ['val' => !empty($r['responsiva_firmada_url']) ? '<span style="color: #15803d; font-weight: bold;">SÍ (FIRMADA)</span>' : '<span style="color: #b91c1c;">PENDIENTE</span>', 'align' => 'center']
                    ];
                }
                descargarExcelConDiseno('Auditoría - Control de Usuarios GRP', 'Asignación de Roles y Credenciales GRP', $columnas, $filas, $agenciaData, 'Usuarios_GRP_' . date('Ymd_His') . '.xls', 'Usuarios GRP');
            } elseif ($seccion === 'licencias') {
                $columnas = [
                    ['label' => 'Código / Clave de Licencia', 'width' => '240px'],
                    ['label' => 'Producto', 'width' => '220px'],
                    ['label' => 'Tipo Licencia', 'width' => '140px', 'align' => 'center'],
                    ['label' => 'Cuenta Microsoft Vinculada', 'width' => '220px'],
                    ['label' => 'Equipo Asignado', 'width' => '160px'],
                    ['label' => 'Usuario Asignado', 'width' => '180px'],
                    ['label' => 'Área', 'width' => '140px'],
                    ['label' => 'Estado', 'width' => '110px', 'align' => 'center'],
                    ['label' => 'Fecha Compra', 'width' => '120px', 'align' => 'center'],
                    ['label' => 'Notas', 'width' => '200px']
                ];
                $rows = $pdo->query("SELECT * FROM audit_licencias_office ORDER BY estado ASC, nombre_producto ASC")->fetchAll(PDO::FETCH_ASSOC);
                $filas = [];
                foreach ($rows as $r) {
                    $filas[] = [
                        ['val' => '<code style="font-weight: bold;">' . htmlspecialchars($r['codigo_licencia']) . '</code>', 'is_text' => true],
                        ['val' => '<b>' . htmlspecialchars($r['nombre_producto']) . '</b>'],
                        ['val' => htmlspecialchars($r['tipo_licencia']), 'align' => 'center'],
                        ['val' => htmlspecialchars($r['cuenta_microsoft'] ?: 'N/A')],
                        ['val' => htmlspecialchars($r['equipo_asignado'] ?: 'Disponible')],
                        ['val' => htmlspecialchars($r['usuario_asignado'] ?: 'Sin asignar')],
                        ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($r['area']) . '</span>'],
                        ['val' => '<span class="badge-pill ' . ($r['estado'] === 'Activa' ? 'badge-status-ok' : ($r['estado'] === 'Disponible' ? 'badge-area' : 'badge-status-err')) . '">' . htmlspecialchars($r['estado']) . '</span>', 'align' => 'center'],
                        ['val' => htmlspecialchars($r['fecha_compra'] ?: '-'), 'align' => 'center'],
                        ['val' => htmlspecialchars($r['notas'] ?: '')]
                    ];
                }
                descargarExcelConDiseno('Auditoría - Licencias de Office', 'Matriz de Licencias y Product Keys', $columnas, $filas, $agenciaData, 'Licencias_Office_' . date('Ymd_His') . '.xls', 'Licencias Office');
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => "Acción '$action' no reconocida"]);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
