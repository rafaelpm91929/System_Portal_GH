<?php
session_start();
require_once 'conexion.php';
require_once 'permisos_helper.php';
require_once 'excel_helper.php';

// Protección de Sesión y Rol de Administración
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
if (!in_array($rolActual, ['superadmin', 'admin'])) {
    requerirPermiso('usuarios', 'puede_ver');
}

$agenciaSesion = $_SESSION['agencia'] ?? '';
$mensaje = '';
$error = '';

// Obtener siempre el nombre de la ÚNICA agencia registrada en el sistema
if ($pdo) {
    asegurarTablasPermisos($pdo);
    try {
        $stmtAgReg = $pdo->query("SELECT nombre FROM agencias ORDER BY id ASC LIMIT 1");
        $nomAg = $stmtAgReg ? $stmtAgReg->fetchColumn() : null;
        if (!empty($nomAg)) {
            $agenciaSesion = $nomAg;
            $_SESSION['agencia'] = $nomAg;
        }
    } catch (Throwable $tAg) {}
}

// ====================================================
// EXPORTACIÓN A EXCEL (.XLS) CON DISEÑO CORPORATIVO OFICIAL
// ====================================================
if (isset($_GET['accion']) && $_GET['accion'] === 'exportar_excel') {
    requerirPermiso('usuarios', 'puede_exportar');
    $agenciaInfo = obtenerDatosAgenciaExcel($pdo, $agenciaSesion);
    $agenciaLimpia = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agenciaInfo['nombre']);
    $fileName = "Usuarios_{$agenciaLimpia}_" . date('Ymd_His') . ".xls";

    $stmtUsersExp = $pdo->query("SELECT * FROM usuarios ORDER BY nombre ASC");
    $usersDb = $stmtUsersExp ? $stmtUsersExp->fetchAll(PDO::FETCH_ASSOC) : [];

    $columnasExcel = [
        ['label' => '# ID', 'width' => '50px', 'align' => 'center'],
        ['label' => 'Nombre Completo', 'width' => '220px', 'align' => 'left'],
        ['label' => 'Usuario / Login', 'width' => '140px', 'align' => 'left', 'is_text' => true],
        ['label' => 'Correo Institucional', 'width' => '230px', 'align' => 'left', 'is_text' => true],
        ['label' => 'Puesto / Cargo', 'width' => '170px', 'align' => 'left'],
        ['label' => 'Área / Depto', 'width' => '140px', 'align' => 'left'],
        ['label' => 'Teléfono / Móvil', 'width' => '120px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Extensión', 'width' => '95px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Rol en Sistema', 'width' => '120px', 'align' => 'center'],
        ['label' => 'Acceso Portal', 'width' => '110px', 'align' => 'center'],
        ['label' => 'Estatus', 'width' => '90px', 'align' => 'center']
    ];

    $filasExcel = [];
    foreach ($usersDb as $u) {
        $tieneAcceso = (!isset($u['acceso_portal']) || intval($u['acceso_portal']) === 1);
        $esActivo = (!isset($u['activo']) || intval($u['activo']) === 1);
        $ext = !empty($u['extension']) ? $u['extension'] : (!empty($u['extension_poe']) ? $u['extension_poe'] : '');
        $emailVal = !empty($u['correo']) ? $u['correo'] : (!empty($u['email']) ? $u['email'] : '');

        $filasExcel[] = [
            ['val' => '<b>#' . $u['id'] . '</b>', 'align' => 'center'],
            ['val' => '<strong>' . htmlspecialchars($u['nombre']) . '</strong>', 'align' => 'left'],
            ['val' => htmlspecialchars($u['usuario'] ?? '---'), 'align' => 'left', 'is_text' => true],
            ['val' => !empty($emailVal) ? '<span style="color:#0284c7;">' . htmlspecialchars($emailVal) . '</span>' : '---', 'align' => 'left', 'is_text' => true],
            ['val' => htmlspecialchars($u['puesto'] ?? 'Sin especificar'), 'align' => 'left'],
            ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($u['area'] ?? 'General') . '</span>', 'align' => 'left'],
            ['val' => htmlspecialchars($u['telefono'] ?? '---'), 'align' => 'center', 'is_text' => true],
            ['val' => !empty($ext) ? '<span class="badge-pill badge-ext"><b>' . htmlspecialchars($ext) . '</b></span>' : '---', 'align' => 'center', 'is_text' => true],
            ['val' => htmlspecialchars($u['rol'] ?? 'Usuario'), 'align' => 'center'],
            ['val' => '<span class="badge-pill ' . ($tieneAcceso ? 'badge-status-ok' : 'badge-status-err') . '">' . ($tieneAcceso ? 'Habilitado' : 'Bloqueado') . '</span>', 'align' => 'center'],
            ['val' => '<span class="badge-pill ' . ($esActivo ? 'badge-status-ok' : 'badge-status-err') . '">' . ($esActivo ? 'Activo' : 'Inactivo') . '</span>', 'align' => 'center']
        ];
    }

    descargarExcelConDiseno(
        'PADRÓN OFICIAL DE USUARIOS DEL SISTEMA',
        'Control de Cuentas, Colaboradores y Permisos de Acceso',
        $columnasExcel,
        $filasExcel,
        $agenciaInfo,
        $fileName,
        'Usuarios'
    );
}

// ----------------------------------------------------
// PROCESAMIENTO DE FORMULARIOS (POST) - UNIVERSAL
// ----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. GUARDAR / CREAR / EDITAR USUARIOS Y ASIGNAR ROLES
    if ($accion === 'guardar_usuario') {
        $id = intval($_POST['user_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $usuario = $email; // El correo es el usuario de inicio de sesión
        $agencia = !empty($agenciaSesion) ? $agenciaSesion : trim($_POST['agencia'] ?? 'Cupra la villa');
        $area = trim($_POST['area'] ?? '');
        $puesto = trim($_POST['puesto'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $rol = $_POST['rol'] ?? 'Usuario';
        $activo = isset($_POST['activo']) ? 1 : 0;
        $acceso_portal = isset($_POST['acceso_portal']) ? 1 : 0;

        if (empty($nombre) || empty($email)) {
            $error = "Por favor completa los campos obligatorios: Nombre Completo y Correo Electrónico.";
        } else {
            try {
                // Procesar subida o recorte de Fotografía de Perfil
                $fotoUrl = '';
                $uploadDir = 'uploads/usuarios/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $croppedBase64 = $_POST['foto_cropped_base64'] ?? '';
                if (!empty($croppedBase64)) {
                    if (preg_match('/^data:image\/(\w+);base64,/', $croppedBase64, $typeMatch)) {
                        $imgData = substr($croppedBase64, strpos($croppedBase64, ',') + 1);
                        $ext = strtolower($typeMatch[1]);
                        $imgData = base64_decode($imgData);
                        if ($imgData !== false) {
                            $destFoto = $uploadDir . 'usr_' . ($id > 0 ? $id : time()) . '_' . time() . '.' . $ext;
                            if (file_put_contents($destFoto, $imgData)) {
                                $fotoUrl = $destFoto;
                            }
                        }
                    }
                } elseif (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                        $destFoto = $uploadDir . 'usr_' . ($id > 0 ? $id : time()) . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['foto_file']['tmp_name'], $destFoto)) {
                            $fotoUrl = $destFoto;
                        }
                    }
                }

                if ($id > 0) {
                    // Actualizar usuario existente
                    $sqlUpd = "UPDATE usuarios SET usuario=?, nombre=?, email=?, agencia=?, area=?, puesto=?, telefono=?, rol=?, activo=?, acceso_portal=?";
                    $params = [$usuario, $nombre, $email, $agencia, $area, $puesto, $telefono, $rol, $activo, $acceso_portal];

                    if (!empty($password)) {
                        $sqlUpd .= ", password=?";
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    if (!empty($fotoUrl)) {
                        $sqlUpd .= ", foto_url=?";
                        $params[] = $fotoUrl;
                    }

                    $sqlUpd .= " WHERE id=?";
                    $params[] = $id;

                    $stmt = $pdo->prepare($sqlUpd);
                    $stmt->execute($params);

                    $mensaje = "Usuario <strong>".htmlspecialchars($nombre)."</strong> ($email) actualizado correctamente.";
                    $nuevo_user_id = $id;
                } else {
                    // Crear nuevo usuario
                    if ($acceso_portal === 1 && empty($password)) {
                        $error = "La contraseña es obligatoria para registrar un nuevo usuario con acceso al portal.";
                    } else {
                        if (empty($password)) {
                            $password = bin2hex(random_bytes(12));
                        }
                        $hash = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $pdo->prepare("
                            INSERT INTO usuarios (usuario, nombre, email, password, agencia, area, puesto, telefono, foto_url, rol, activo, acceso_portal)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$usuario, $nombre, $email, $hash, $agencia, $area, $puesto, $telefono, $fotoUrl, $rol, $activo, $acceso_portal]);
                        $nuevo_user_id = $pdo->lastInsertId();

                        $tipoAccesoStr = ($acceso_portal === 1) ? "con acceso al portal y rol <strong>$rol</strong>" : "como información de personal (sin acceso al portal)";
                        $mensaje = "¡Usuario <strong>".htmlspecialchars($nombre)."</strong> ($email) registrado exitosamente $tipoAccesoStr!";
                    }
                }

                // Asignación automática de permisos por defecto según el rol seleccionado (si tiene acceso)
                if ($acceso_portal === 1 && isset($nuevo_user_id) && $nuevo_user_id > 0) {
                    $stmtMod = $pdo->query("SELECT clave FROM modulos WHERE estatus = 1");
                    $modulos = $stmtMod->fetchAll(PDO::FETCH_COLUMN);

                    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
                    if ($driver === 'sqlite') {
                        $stmtPerm = $pdo->prepare("
                            INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON CONFLICT(usuario_id, modulo_clave) DO UPDATE SET
                                puede_ver = excluded.puede_ver,
                                puede_crear = excluded.puede_crear,
                                puede_editar = excluded.puede_editar,
                                puede_exportar = excluded.puede_exportar
                        ");
                    } else {
                        $stmtPerm = $pdo->prepare("
                            INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                puede_ver = VALUES(puede_ver),
                                puede_crear = VALUES(puede_crear),
                                puede_editar = VALUES(puede_editar),
                                puede_exportar = VALUES(puede_exportar)
                        ");
                    }

                    foreach ($modulos as $modClave) {
                        if (in_array(strtolower($rol), ['superadmin', 'admin'])) {
                            // Admins tienen permisos totales habilitados por defecto
                            $stmtPerm->execute([$nuevo_user_id, $modClave, 1, 1, 1, 1, 1]);
                        } else {
                            // Rol Usuario: Acceso a consulta de Órdenes e Inventario de Equipos
                            $puedeVer = in_array($modClave, ['ordenes_servicio', 'equipos']) ? 1 : 0;
                            $stmtPerm->execute([$nuevo_user_id, $modClave, $puedeVer, 0, 0, 0, 0]);
                        }
                    }
                }
            } catch (PDOException $e) {
                $error = "Error al guardar el usuario: " . $e->getMessage();
            }
        }
    }

    // 2. ACTUALIZAR PERMISOS MATRICIALES POR MÓDULO
    elseif ($accion === 'guardar_permisos') {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);
        $permisosEnviados = $_POST['permisos'] ?? [];

        if ($targetUserId > 0) {
            try {
                $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
                if ($driver === 'sqlite') {
                    $stmtPerm = $pdo->prepare("
                        INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT(usuario_id, modulo_clave) DO UPDATE SET
                            puede_ver = excluded.puede_ver,
                            puede_crear = excluded.puede_crear,
                            puede_editar = excluded.puede_editar,
                            puede_eliminar = excluded.puede_eliminar,
                            puede_exportar = excluded.puede_exportar
                    ");
                } else {
                    $stmtPerm = $pdo->prepare("
                        INSERT INTO usuario_permisos (usuario_id, modulo_clave, puede_ver, puede_crear, puede_editar, puede_eliminar, puede_exportar)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            puede_ver = VALUES(puede_ver),
                            puede_crear = VALUES(puede_crear),
                            puede_editar = VALUES(puede_editar),
                            puede_eliminar = VALUES(puede_eliminar),
                            puede_exportar = VALUES(puede_exportar)
                    ");
                }

                $stmtMod = $pdo->query("SELECT clave FROM modulos WHERE estatus = 1");
                $modulos = $stmtMod->fetchAll(PDO::FETCH_COLUMN);

                foreach ($modulos as $modClave) {
                    $modPerms = $permisosEnviados[$modClave] ?? [];
                    $pVer = isset($modPerms['puede_ver']) ? 1 : 0;
                    $pCrear = isset($modPerms['puede_crear']) ? 1 : 0;
                    $pEditar = isset($modPerms['puede_editar']) ? 1 : 0;
                    $pEliminar = isset($modPerms['puede_eliminar']) ? 1 : 0;
                    $pExportar = isset($modPerms['puede_exportar']) ? 1 : 0;

                    $stmtPerm->execute([$targetUserId, $modClave, $pVer, $pCrear, $pEditar, $pEliminar, $pExportar]);
                }

                if ($targetUserId === (int)($_SESSION['usuario_id'] ?? 0)) {
                    cargarPermisosSesion($pdo, $targetUserId);
                }

                $mensaje = "Permisos actualizados correctamente para el usuario #$targetUserId.";
            } catch (PDOException $e) {
                $error = "Error al actualizar permisos: " . $e->getMessage();
            }
        }
    }

    // 3. CAMBIAR ROL RÁPIDO
    elseif ($accion === 'cambiar_rol') {
        $userId = intval($_POST['user_id'] ?? 0);
        $nuevoRol = $_POST['nuevo_rol'] ?? 'Usuario';

        if ($userId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
                $stmt->execute([$nuevoRol, $userId]);
                $mensaje = "Rol del usuario actualizado a <strong>" . htmlspecialchars($nuevoRol) . "</strong>.";
            } catch (PDOException $e) {
                $error = "Error al cambiar el rol: " . $e->getMessage();
            }
        }
    }

    // 4. ALTERNAR ESTATUS ACTIVO / INACTIVO
    elseif ($accion === 'toggle_status') {
        $userId = intval($_POST['user_id'] ?? 0);
        $nuevoEstatus = intval($_POST['nuevo_estatus'] ?? 1);

        if ($userId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
                $stmt->execute([$nuevoEstatus, $userId]);
                $mensaje = "Estatus del usuario actualizado correctamente.";
            } catch (PDOException $e) {
                $error = "Error al actualizar estatus: " . $e->getMessage();
            }
        }
    }

    // 5. ALTERNAR ACCESO AL PORTAL (DENTRO O SOLO INFO ADMIN)
    elseif ($accion === 'toggle_acceso_portal') {
        $userId = intval($_POST['user_id'] ?? 0);
        $nuevoAcceso = intval($_POST['nuevo_acceso'] ?? 1);

        if ($userId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET acceso_portal = ? WHERE id = ?");
                $stmt->execute([$nuevoAcceso, $userId]);
                $estadoTexto = ($nuevoAcceso === 1) ? "con acceso al portal" : "registrado solo como información de personal";
                $mensaje = "Acceso al portal actualizado: El usuario ahora está $estadoTexto.";
            } catch (PDOException $e) {
                $error = "Error al cambiar acceso al portal: " . $e->getMessage();
            }
        }
    }
}

// ----------------------------------------------------
// CONSULTA DE USUARIOS, ÁREAS Y MÓDULOS DEL PORTAL
// ----------------------------------------------------

$usuarios = [];
$modulosCat = [];
$permisosMap = [];
$areasDisponibles = [];
$equiposPorUsuarioMap = [];

if ($pdo) {
    try {
        $stmtU = $pdo->query("SELECT * FROM usuarios ORDER BY id DESC");
        $usuarios = $stmtU->fetchAll(PDO::FETCH_ASSOC);

        $stmtM = $pdo->query("SELECT * FROM modulos WHERE estatus = 1 ORDER BY orden ASC");
        $modulosCat = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        $stmtP = $pdo->query("SELECT * FROM usuario_permisos");
        $allPerms = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allPerms as $pm) {
            $permisosMap[$pm['usuario_id']][$pm['modulo_clave']] = $pm;
        }

        // Consultar áreas registradas para la agencia activa
        $stmtAg = $pdo->prepare("SELECT id FROM agencias WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
        $stmtAg->execute([$agenciaSesion]);
        $agId = $stmtAg->fetchColumn();

        if ($agId) {
            $stmtAr = $pdo->prepare("SELECT nombre FROM agencia_areas WHERE agencia_id = ? AND estatus = 1 ORDER BY nombre ASC");
            $stmtAr->execute([$agId]);
            $areasDisponibles = $stmtAr->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($areasDisponibles)) {
            $areasDisponibles = ['Administración', 'Servicio', 'Refacciones', 'Ventas', 'HyP', 'CRM'];
        }

        // AGREGACIÓN DE EQUIPOS E INVENTARIO ASIGNADO POR USUARIO
        $tablasInv = [
            ['tabla' => 'inv_equipos_vw', 'tipo' => 'Equipo VW', 'user_col' => 'usuario', 'name_col' => 'nombre_equipo'],
            ['tabla' => 'inv_equipos_corp', 'tipo' => 'Equipo Corporativo', 'user_col' => 'usuario', 'name_col' => 'nombre_equipo'],
            ['tabla' => 'inv_dispositivos_moviles', 'tipo' => 'Dispositivo Móvil', 'user_col' => 'nombre', 'name_col' => 'celular'],
            ['tabla' => 'inv_monitores', 'tipo' => 'Monitor', 'user_col' => 'nombre', 'name_col' => 'monitor'],
            ['tabla' => 'inv_site_vw', 'tipo' => 'SITE VW', 'user_col' => 'usuario', 'name_col' => 'nombre_equipo'],
            ['tabla' => 'inv_telefonos_poe', 'tipo' => 'Teléfono PoE', 'user_col' => 'usuario', 'name_col' => 'modelo'],
            ['tabla' => 'inv_licencias_office', 'tipo' => 'Licencia Office', 'user_col' => 'nombre', 'name_col' => 'licencia'],
            ['tabla' => 'inv_archivo', 'tipo' => 'Archivo / Bodega', 'user_col' => 'nombre', 'name_col' => 'monitor']
        ];

        foreach ($tablasInv as $tInfo) {
            $t = $tInfo['tabla'];
            try {
                $stmtInv = $pdo->query("SELECT * FROM `$t`");
                if ($stmtInv) {
                    $rows = $stmtInv->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $usrVal = trim($row[$tInfo['user_col']] ?? '');
                        $correoVal = trim($row['correo'] ?? '');
                        if ((!empty($usrVal) && $usrVal !== 'N/A' && $usrVal !== 'Sin especificar' && $usrVal !== '---') || !empty($correoVal)) {
                            foreach ($usuarios as $uKey => $u) {
                                $uNom = trim($u['nombre'] ?? '');
                                $uMail = trim($u['email'] ?? '');
                                
                                $normNom = preg_replace('/\s+/', ' ', mb_strtolower($uNom));
                                $normUsr = preg_replace('/\s+/', ' ', mb_strtolower($usrVal));

                                $matchName = (!empty($normUsr) && $normNom === $normUsr);
                                $matchEmail = (!empty($uMail) && (
                                    (!empty($usrVal) && mb_strtolower($uMail) === mb_strtolower($usrVal)) ||
                                    (!empty($correoVal) && mb_strtolower($uMail) === mb_strtolower($correoVal))
                                ));

                                if ($matchName || $matchEmail) {
                                    $uId = $u['id'];
                                    $isPoe = ($t === 'inv_telefonos_poe');

                                    if ($isPoe) {
                                        if (!empty($row['extension'])) {
                                            $usuarios[$uKey]['extension_poe'] = $row['extension'];
                                        }
                                        if (!empty($row['numero_telefonico'])) {
                                            $usuarios[$uKey]['telefono_poe'] = $row['numero_telefonico'];
                                        }
                                        if (!empty($row['modelo'])) {
                                            $usuarios[$uKey]['modelo_poe'] = $row['modelo'];
                                        }
                                        if (!empty($row['numero_nodo'])) {
                                            $usuarios[$uKey]['nodo_poe'] = $row['numero_nodo'];
                                        }

                                        $nomItem = 'Teléfono IP PoE' . (!empty($row['modelo']) ? (' - ' . $row['modelo']) : '');
                                    } else {
                                        $nomItem = $row[$tInfo['name_col']] ?? $row['nombre'] ?? $row['modelo'] ?? 'Equipo';
                                        if (empty($nomItem)) $nomItem = 'Equipo #' . ($row['id'] ?? '');
                                    }

                                    $equiposPorUsuarioMap[$uId][] = [
                                        'tipo' => $tInfo['tipo'],
                                        'nombre' => $nomItem,
                                        'serie' => $row['serie'] ?? $row['numero_serie'] ?? $row['serie_nobreak'] ?? 'N/A',
                                        'departamento' => $row['departamento'] ?? $row['area'] ?? 'N/A',
                                        'puesto' => ($isPoe && !empty($row['extension'])) ? ('Ext. ' . $row['extension']) : ($row['puesto'] ?? 'N/A'),
                                        'estado' => $row['estado'] ?? 'Activo',
                                        'id' => $row['id'] ?? 0,
                                        'extension' => $row['extension'] ?? '',
                                        'ip' => $row['ip'] ?? '',
                                        'mac' => $row['mac'] ?? '',
                                        'nodo' => $row['numero_nodo'] ?? '',
                                        'puerto_sw' => $row['puerto_sw'] ?? '',
                                        'switch' => $row['switch_nombre'] ?? ''
                                    ];
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $tInv) {
                // Ignorar si alguna tabla de inventario no existe aún
            }
        }

    } catch (PDOException $e) {
        $error = "Error al consultar los datos: " . $e->getMessage();
    }
}

// AGRUPACIÓN DE USUARIOS POR ÁREA DE LA AGENCIA
$usuariosPorAreaMap = [];
foreach ($areasDisponibles as $arNom) {
    $usuariosPorAreaMap[$arNom] = [];
}
$usuariosPorAreaMap['Sin Área / General'] = [];

foreach ($usuarios as $u) {
    $uArea = trim($u['area'] ?? '');
    if (empty($uArea)) {
        $usuariosPorAreaMap['Sin Área / General'][] = $u;
    } else {
        if (!isset($usuariosPorAreaMap[$uArea])) {
            $usuariosPorAreaMap[$uArea] = [];
        }
        $usuariosPorAreaMap[$uArea][] = $u;
    }
}

// Estadísticas de Resumen
$totalUsuarios = count($usuarios);
$totalConAcceso = count(array_filter($usuarios, fn($u) => (!isset($u['acceso_portal']) || intval($u['acceso_portal']) === 1)));
$totalSinAcceso = count(array_filter($usuarios, fn($u) => (isset($u['acceso_portal']) && intval($u['acceso_portal']) === 0)));
$totalEquiposAsignados = array_sum(array_map('count', $equiposPorUsuarioMap));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Usuarios y Expedientes - Portal de Sistemas Grupo Huerta</title>
    <?php include_once 'pwa_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #050c1a;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 50px;
        }
        .top-navbar {
            background: #09182b;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding: 15px 35px;
        }
        .card-custom {
            background: #0a1b33;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 24px;
        }
        .stat-card {
            background: linear-gradient(145deg, #0d2442 0%, #0a1b33 100%);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 14px;
            padding: 18px 20px;
            transition: transform 0.2s, border-color 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #38bdf8;
        }

        /* RECTÁNGULOS DE USUARIOS (CLIC EN CUALQUIER PARTE ABRE FICHA) */
        .user-card-rect {
            background: #0e2444;
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-radius: 16px;
            padding: 20px;
            height: 100%;
            transition: all 0.25s ease-in-out;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
        }
        .user-card-rect:hover {
            border-color: #38bdf8;
            box-shadow: 0 8px 24px rgba(14, 165, 233, 0.25);
            transform: translateY(-3px);
            background: #112c52;
        }
        .user-avatar-rect {
            width: 65px;
            height: 65px;
            border-radius: 16px;
            object-fit: cover;
            border: 2px solid #38bdf8;
            background: linear-gradient(135deg, #1e3a8a 0%, #0f223d 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.5rem;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.35);
        }
        .user-avatar-ficha {
            width: 125px;
            height: 125px;
            border-radius: 22px;
            object-fit: cover;
            border: 3px solid #38bdf8;
            background: linear-gradient(135deg, #1e3a8a 0%, #0f223d 100%);
            box-shadow: 0 8px 25px rgba(56, 189, 248, 0.45);
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }
        .user-avatar-ficha:hover {
            transform: scale(1.06);
            box-shadow: 0 12px 32px rgba(56, 189, 248, 0.65);
            border-color: #60a5fa;
        }

        /* Z-INDEX PRIORITARIO PARA MODALES SUPERPUESTOS */
        #modalAjustarFoto {
            z-index: 1090 !important;
        }
        #modalVerFotoGrande {
            z-index: 1095 !important;
        }

        /* TABLA DE ALTO CONTRASTE */
        .table-custom {
            color: #ffffff;
        }
        .table-custom th {
            background-color: #0b1d38;
            color: #38bdf8;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 2px solid rgba(56, 189, 248, 0.3);
            padding: 14px 12px;
        }
        .table-custom td {
            vertical-align: middle;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 14px 12px;
            background-color: #0e2444;
            color: #ffffff;
        }
        .user-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .user-row:hover td {
            background-color: #13315c !important;
        }

        .modal-content {
            background-color: #0a1b33;
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: #ffffff;
            border-radius: 16px;
        }
        .modal-header, .modal-footer {
            border-color: rgba(255, 255, 255, 0.12);
        }
        .form-control, .form-select {
            background-color: #0e2444;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-weight: 500;
        }
        .form-control:focus, .form-select:focus {
            background-color: #13315c;
            color: #ffffff;
            border-color: #38bdf8;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.3);
        }
        .form-control::placeholder {
            color: #94a3b8;
        }

        /* ALTO CONTRASTE EN ETIQUETAS Y TEXTO */
        .text-high-contrast {
            color: #f8fafc !important;
            font-weight: 600;
        }
        .text-label-contrast {
            color: #cbd5e1 !important;
            font-weight: 700;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-agencia {
            background: rgba(37, 99, 235, 0.3);
            color: #60a5fa;
            border: 1px solid #3b82f6;
            font-weight: 600;
        }
        .badge-area {
            background: rgba(14, 165, 233, 0.25);
            color: #38bdf8;
            border: 1px solid #0284c7;
            font-weight: 600;
        }
        .badge-equipos {
            background: #059669;
            color: #ffffff;
            border: 1px solid #10b981;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="top-navbar d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm text-white rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Menú Principal
        </a>
        <span class="fw-bold fs-5">PORTAL DE SISTEMAS <span class="text-primary">| Control de Usuarios y Roles</span></span>
    </div>
    <div>
        <span class="badge bg-primary p-2 fs-6"><i class="bi bi-building-fill me-1"></i> <?php echo htmlspecialchars($agenciaSesion); ?></span>
    </div>
</div>

<div class="container-fluid px-4">

    <!-- Mensajes de Notificación -->
    <?php if ($mensaje): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- TARJETAS DE ESTADÍSTICAS RÁPIDAS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-label-contrast d-block mb-1">Total Personal / Usuarios</span>
                    <h3 class="fw-bold mb-0 text-white"><?php echo $totalUsuarios; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(37, 99, 235, 0.2); color: #60a5fa;">
                    <i class="bi bi-people-fill fs-3"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-label-contrast d-block mb-1">Con Acceso al Portal</span>
                    <h3 class="fw-bold mb-0 text-info"><?php echo $totalConAcceso; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(14, 165, 233, 0.2); color: #38bdf8;">
                    <i class="bi bi-door-open-fill fs-3"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-label-contrast d-block mb-1">Solo Registro Admin</span>
                    <h3 class="fw-bold mb-0 text-warning"><?php echo $totalSinAcceso; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                    <i class="bi bi-person-badge fs-3"></i>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-label-contrast d-block mb-1">Equipos Asignados</span>
                    <h3 class="fw-bold mb-0 text-success"><?php echo $totalEquiposAsignados; ?></h3>
                </div>
                <div class="p-3 rounded-3" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                    <i class="bi bi-display-fill fs-3"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ENCABEZADO DE ACCIONES, CAMBIO DE VISTA Y BUSCADOR -->
    <div class="card-custom mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="fw-bold mb-1 text-white"><i class="bi bi-grid-3x3-gap-fill text-primary me-2"></i> Control de Usuarios del Sistema</h4>
                <p class="text-label-contrast mb-0">Selecciona entre la vista general de rectángulos, agrupación por áreas de la agencia o vista en lista tabla.</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <!-- Conmutador de Vista (Rectángulos vs Por Áreas vs Tabla) -->
                <div class="btn-group btn-group-sm" role="group" aria-label="Cambio de vista">
                    <button type="button" class="btn btn-primary fw-bold" id="btnVistaGrid" onclick="cambiarVista('grid')">
                        <i class="bi bi-grid-fill me-1"></i> Rectángulos
                    </button>
                    <button type="button" class="btn btn-outline-secondary text-white fw-bold" id="btnVistaAreas" onclick="cambiarVista('areas')">
                        <i class="bi bi-diagram-3-fill me-1"></i> Por Áreas
                    </button>
                    <button type="button" class="btn btn-outline-secondary text-white fw-bold" id="btnVistaTabla" onclick="cambiarVista('tabla')">
                        <i class="bi bi-table me-1"></i> Lista Tabla
                    </button>
                </div>

                <!-- Buscador -->
                <div class="input-group input-group-sm" style="width: 250px;">
                    <span class="input-group-text bg-dark border-secondary text-info"><i class="bi bi-search"></i></span>
                    <input type="text" id="busquedaUsuario" class="form-control px-3" placeholder="🔍 Buscar nombre, correo..." onkeyup="filtrarUsuarios()">
                </div>

                <!-- Botón Exportar a Excel -->
                <?php if (tienePermiso('usuarios', 'puede_exportar')): ?>
                    <a href="usuarios.php?accion=exportar_excel" class="btn btn-success btn-sm rounded-3 px-3 fw-bold d-flex align-items-center gap-1.5 shadow-sm" style="background: #16a34a; border-color: #16a34a;" title="Exportar Padrón de Usuarios a Microsoft Excel con logotipo oficial">
                        <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel (.xls)
                    </a>
                <?php endif; ?>

                <!-- Botón Nuevo Usuario -->
                <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-bold" onclick="abrirModalNuevoUsuario()">
                    <i class="bi bi-person-plus-fill me-1"></i> Nuevo Usuario
                </button>
            </div>
        </div>
    </div>

    <!-- =================================================== -->
    <!-- VISTA 1: RECTÁNGULOS DE USUARIOS (GRID GENERAL)     -->
    <!-- =================================================== -->
    <div id="vistaGridUsuarios" class="row g-4">
        <?php if (empty($usuarios)): ?>
            <div class="col-12 text-center py-5 text-secondary">
                <i class="bi bi-info-circle fs-2 d-block mb-2 text-info"></i>
                No hay usuarios registrados en el sistema. Haz clic en <strong>Nuevo Usuario</strong> para agregar el primero.
            </div>
        <?php else: ?>
            <?php foreach ($usuarios as $u): ?>
                <?php 
                    $tieneAcceso = (!isset($u['acceso_portal']) || intval($u['acceso_portal']) === 1);
                    $equiposUser = $equiposPorUsuarioMap[$u['id']] ?? [];
                    $cantEquipos = count($equiposUser);
                ?>
                <div class="col-xl-4 col-md-6 user-card-item">
                    <div class="user-card-rect" onclick='abrirFichaSiNoEsBoton(event, <?php echo json_encode($u); ?>)'>
                        <div>
                            <!-- Encabezado del Rectángulo -->
                            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                                <div class="d-flex align-items-center gap-3">
                                    <?php if (!empty($u['foto_url']) && file_exists($u['foto_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($u['foto_url']); ?>" alt="Foto" class="user-avatar-rect" onclick="event.stopPropagation(); verFotoFull('<?php echo htmlspecialchars($u['foto_url']); ?>', '<?php echo htmlspecialchars(addslashes($u['nombre'])); ?>')" style="cursor: pointer;" title="Haz clic para amplificar la imagen en tamaño completo">
                                    <?php else: ?>
                                        <div class="user-avatar-rect">
                                            <?php echo strtoupper(mb_substr($u['nombre'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <h5 class="fw-bold text-white mb-0 fs-6"><?php echo htmlspecialchars($u['nombre']); ?></h5>
                                        <span class="text-info font-monospace small fw-bold d-block text-truncate" style="max-width: 200px; font-size: 0.8rem;"><?php echo htmlspecialchars($u['email']); ?></span>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($u['activo']): ?>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-2 py-1 small fw-bold"><i class="bi bi-circle-fill me-1" style="font-size: 0.4rem;"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary rounded-pill px-2 py-1 small fw-bold">Inactivo</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Cuerpo del Rectángulo con Datos de Alto Contraste -->
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <span class="text-label-contrast d-block mb-1">Puesto / Cargo</span>
                                    <span class="text-high-contrast fs-7 d-block text-truncate" title="<?php echo htmlspecialchars($u['puesto'] ?? 'Sin especificar'); ?>">
                                        <i class="bi bi-briefcase-fill text-primary me-1"></i> <?php echo htmlspecialchars($u['puesto'] ?? 'Sin especificar'); ?>
                                    </span>
                                </div>
                                <div class="col-6">
                                    <span class="text-label-contrast d-block mb-1">Teléfono / Ext.</span>
                                    <span class="text-high-contrast fs-7 d-block text-truncate">
                                        <?php if (!empty($u['extension_poe'])): ?>
                                            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2 py-0 small fw-bold me-1">
                                                <i class="bi bi-telephone-fill me-1"></i>Ext. <?php echo htmlspecialchars($u['extension_poe']); ?>
                                            </span>
                                            <?php if (!empty($u['telefono']) && $u['telefono'] !== $u['extension_poe']): ?>
                                                <span class="small text-light"><?php echo htmlspecialchars($u['telefono']); ?></span>
                                            <?php endif; ?>
                                        <?php elseif (!empty($u['telefono']) && $u['telefono'] !== 'N/A' && $u['telefono'] !== 'Sin especificar'): ?>
                                            <i class="bi bi-telephone text-secondary me-1"></i><?php echo htmlspecialchars($u['telefono']); ?>
                                        <?php else: ?>
                                            <span class="text-secondary">N/A</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="col-6">
                                    <span class="text-label-contrast d-block mb-1">Agencia / Área</span>
                                    <span class="badge badge-agencia text-truncate d-inline-block mw-100 mb-1">
                                        <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($u['agencia'] ?? $agenciaSesion); ?>
                                    </span>
                                    <?php if (!empty($u['area'])): ?>
                                        <br>
                                        <span class="badge badge-area text-truncate d-inline-block mw-100">
                                            <i class="bi bi-diagram-3 me-1"></i> <?php echo htmlspecialchars($u['area']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <span class="text-label-contrast d-block mb-1">Equipos & Acceso</span>
                                    <div class="mb-1">
                                        <?php if ($cantEquipos > 0): ?>
                                            <span class="badge badge-equipos">
                                                <i class="bi bi-display-fill me-1"></i> <?php echo $cantEquipos; ?> Equipos
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-dark border text-secondary">0 Equipos</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php if ($tieneAcceso): ?>
                                            <span class="badge bg-primary bg-opacity-25 text-primary border border-primary"><i class="bi bi-door-open-fill me-1"></i> Con Acceso</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning"><i class="bi bi-door-closed-fill me-1"></i> Solo Info</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pie del Rectángulo (Insignia de Rol y Acciones) -->
                        <div class="d-flex align-items-center justify-content-between pt-2 border-top border-secondary border-opacity-25">
                            <div>
                                <?php if ($tieneAcceso): ?>
                                    <span class="badge bg-dark text-info border border-secondary px-2 py-1 small fw-bold"><i class="bi bi-person-gear me-1"></i> <?php echo htmlspecialchars($u['rol'] ?? 'Usuario'); ?></span>
                                <?php else: ?>
                                    <span class="text-secondary small fst-italic">Sin Rol Portal</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-inline-flex gap-1">
                                <?php if ($tieneAcceso): ?>
                                    <button type="button" class="btn btn-sm btn-outline-info rounded-2" onclick='abrirModalPermisos(<?php echo json_encode($u); ?>)' title="Permisos Módulos">
                                        <i class="bi bi-shield-lock-fill"></i> Permisos
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-warning rounded-2" onclick='abrirModalEditarUsuario(<?php echo json_encode($u); ?>)' title="Editar Usuario">
                                    <i class="bi bi-pencil-square"></i> Editar
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas cambiar el estatus de este usuario?');">
                                    <input type="hidden" name="accion" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="nuevo_estatus" value="<?php echo $u['activo'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $u['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> rounded-2">
                                        <i class="bi <?php echo $u['activo'] ? 'bi-person-x-fill' : 'bi-person-check-fill'; ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- =================================================== -->
    <!-- VISTA 2: USUARIOS AGRUPADOS POR ÁREAS DE LA AGENCIA -->
    <!-- =================================================== -->
    <div id="vistaAreasUsuarios" class="d-none">
        <?php foreach ($usuariosPorAreaMap as $nomArea => $usersInArea): ?>
            <?php $cantUsersInArea = count($usersInArea); ?>
            <div class="card-custom mb-4 area-group-block">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary p-2 fs-6 rounded-3">
                            <i class="bi bi-diagram-3-fill"></i>
                        </span>
                        <div>
                            <h5 class="fw-bold text-white mb-0"><?php echo htmlspecialchars($nomArea); ?></h5>
                            <small class="text-label-contrast">Área de Operación de la Agencia</small>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary rounded-pill px-3 py-2 fw-bold fs-7">
                            <i class="bi bi-people-fill me-1"></i> <?php echo $cantUsersInArea; ?> <?php echo ($cantUsersInArea === 1) ? 'Usuario Creado' : 'Usuarios Creados'; ?>
                        </span>
                    </div>
                </div>

                <?php if ($cantUsersInArea === 0): ?>
                    <div class="text-secondary small py-2 fst-italic">
                        <i class="bi bi-info-circle me-1 text-info"></i> No hay usuarios registrados en el área de <strong><?php echo htmlspecialchars($nomArea); ?></strong>.
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($usersInArea as $u): ?>
                            <?php 
                                $tieneAcceso = (!isset($u['acceso_portal']) || intval($u['acceso_portal']) === 1);
                                $equiposUser = $equiposPorUsuarioMap[$u['id']] ?? [];
                                $cantEquipos = count($equiposUser);
                            ?>
                            <div class="col-xl-4 col-md-6 user-card-item">
                                <div class="user-card-rect" onclick='abrirFichaSiNoEsBoton(event, <?php echo json_encode($u); ?>)'>
                                    <div>
                                        <!-- Encabezado del Rectángulo -->
                                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                                            <div class="d-flex align-items-center gap-3">
                                                <?php if (!empty($u['foto_url']) && file_exists($u['foto_url'])): ?>
                                                    <img src="<?php echo htmlspecialchars($u['foto_url']); ?>" alt="Foto" class="user-avatar-rect" onclick="event.stopPropagation(); verFotoFull('<?php echo htmlspecialchars($u['foto_url']); ?>', '<?php echo htmlspecialchars(addslashes($u['nombre'])); ?>')" style="cursor: pointer;" title="Haz clic para amplificar la imagen en tamaño completo">
                                                <?php else: ?>
                                                    <div class="user-avatar-rect">
                                                        <?php echo strtoupper(mb_substr($u['nombre'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <h5 class="fw-bold text-white mb-0 fs-6"><?php echo htmlspecialchars($u['nombre']); ?></h5>
                                                    <span class="text-info font-monospace small fw-bold d-block text-truncate" style="max-width: 200px; font-size: 0.8rem;"><?php echo htmlspecialchars($u['email']); ?></span>
                                                </div>
                                            </div>
                                            <div>
                                                <?php if ($u['activo']): ?>
                                                    <span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-2 py-1 small fw-bold"><i class="bi bi-circle-fill me-1" style="font-size: 0.4rem;"></i> Activo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary rounded-pill px-2 py-1 small fw-bold">Inactivo</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Cuerpo del Rectángulo con Datos de Alto Contraste -->
                                        <div class="row g-2 mb-3">
                                            <div class="col-6">
                                                <span class="text-label-contrast d-block mb-1">Puesto / Cargo</span>
                                                <span class="text-high-contrast fs-7 d-block text-truncate" title="<?php echo htmlspecialchars($u['puesto'] ?? 'Sin especificar'); ?>">
                                                    <i class="bi bi-briefcase-fill text-primary me-1"></i> <?php echo htmlspecialchars($u['puesto'] ?? 'Sin especificar'); ?>
                                                </span>
                                            </div>
                                            <div class="col-6">
                                                <span class="text-label-contrast d-block mb-1">Teléfono / Ext.</span>
                                                <span class="text-high-contrast fs-7 d-block text-truncate">
                                                    <?php if (!empty($u['extension_poe'])): ?>
                                                        <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2 py-0 small fw-bold me-1">
                                                            <i class="bi bi-telephone-fill me-1"></i>Ext. <?php echo htmlspecialchars($u['extension_poe']); ?>
                                                        </span>
                                                        <?php if (!empty($u['telefono']) && $u['telefono'] !== $u['extension_poe']): ?>
                                                            <span class="small text-light"><?php echo htmlspecialchars($u['telefono']); ?></span>
                                                        <?php endif; ?>
                                                    <?php elseif (!empty($u['telefono']) && $u['telefono'] !== 'N/A' && $u['telefono'] !== 'Sin especificar'): ?>
                                                        <i class="bi bi-telephone text-secondary me-1"></i><?php echo htmlspecialchars($u['telefono']); ?>
                                                    <?php else: ?>
                                                        <span class="text-secondary">N/A</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="col-6">
                                                <span class="text-label-contrast d-block mb-1">Agencia / Área</span>
                                                <span class="badge badge-agencia text-truncate d-inline-block mw-100 mb-1">
                                                    <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($u['agencia'] ?? $agenciaSesion); ?>
                                                </span>
                                                <?php if (!empty($u['area'])): ?>
                                                    <br>
                                                    <span class="badge badge-area text-truncate d-inline-block mw-100">
                                                        <i class="bi bi-diagram-3 me-1"></i> <?php echo htmlspecialchars($u['area']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-6">
                                                <span class="text-label-contrast d-block mb-1">Equipos & Acceso</span>
                                                <div class="mb-1">
                                                    <?php if ($cantEquipos > 0): ?>
                                                        <span class="badge badge-equipos">
                                                            <i class="bi bi-display-fill me-1"></i> <?php echo $cantEquipos; ?> Equipos
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-dark border text-secondary">0 Equipos</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <?php if ($tieneAcceso): ?>
                                                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary"><i class="bi bi-door-open-fill me-1"></i> Con Acceso</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning bg-opacity-25 text-warning border border-warning"><i class="bi bi-door-closed-fill me-1"></i> Solo Info</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pie del Rectángulo -->
                                    <div class="d-flex align-items-center justify-content-between pt-2 border-top border-secondary border-opacity-25">
                                        <div>
                                            <?php if ($tieneAcceso): ?>
                                                <span class="badge bg-dark text-info border border-secondary px-2 py-1 small fw-bold"><i class="bi bi-person-gear me-1"></i> <?php echo htmlspecialchars($u['rol'] ?? 'Usuario'); ?></span>
                                            <?php else: ?>
                                                <span class="text-secondary small fst-italic">Sin Rol Portal</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-inline-flex gap-1">
                                            <?php if ($tieneAcceso): ?>
                                                <button type="button" class="btn btn-sm btn-outline-info rounded-2" onclick='abrirModalPermisos(<?php echo json_encode($u); ?>)' title="Permisos Módulos">
                                                    <i class="bi bi-shield-lock-fill"></i> Permisos
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning rounded-2" onclick='abrirModalEditarUsuario(<?php echo json_encode($u); ?>)' title="Editar Usuario">
                                                <i class="bi bi-pencil-square"></i> Editar
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas cambiar el estatus de este usuario?');">
                                                <input type="hidden" name="accion" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <input type="hidden" name="nuevo_estatus" value="<?php echo $u['activo'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm <?php echo $u['activo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> rounded-2">
                                                    <i class="bi <?php echo $u['activo'] ? 'bi-person-x-fill' : 'bi-person-check-fill'; ?>"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- =================================================== -->
    <!-- VISTA 3: TABLA DE LISTA DE USUARIOS (ALTO CONTRASTE)-->
    <!-- =================================================== -->
    <div id="vistaTablaUsuarios" class="card-custom d-none">
        <div class="table-responsive">
            <table class="table table-custom table-hover align-middle mb-0" id="tablaUsuarios">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Foto</th>
                        <th>Nombre Completo y Correo</th>
                        <th>Puesto</th>
                        <th>Agencia / Área</th>
                        <th>Acceso Portal</th>
                        <th>Equipos</th>
                        <th>Rol</th>
                        <th>Estatus</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4 text-secondary">
                                <i class="bi bi-info-circle fs-4 d-block mb-2"></i> No hay usuarios registrados aún.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u): ?>
                            <?php 
                                $tieneAcceso = (!isset($u['acceso_portal']) || intval($u['acceso_portal']) === 1);
                                $equiposUser = $equiposPorUsuarioMap[$u['id']] ?? [];
                                $cantEquipos = count($equiposUser);
                            ?>
                            <tr class="user-row user-card-item" onclick='abrirFichaSiNoEsBoton(event, <?php echo json_encode($u); ?>)'>
                                <td class="fw-bold text-secondary">#<?php echo $u['id']; ?></td>
                                <td>
                                    <?php if (!empty($u['foto_url']) && file_exists($u['foto_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($u['foto_url']); ?>" alt="Foto" class="user-avatar-rect" onclick="event.stopPropagation(); verFotoFull('<?php echo htmlspecialchars($u['foto_url']); ?>', '<?php echo htmlspecialchars(addslashes($u['nombre'])); ?>')" style="width: 40px; height: 40px; cursor: pointer;" title="Haz clic para amplificar la imagen en tamaño completo">
                                    <?php else: ?>
                                        <div class="user-avatar-rect" style="width: 40px; height: 40px; font-size: 1rem;">
                                            <?php echo strtoupper(mb_substr($u['nombre'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-white fs-6"><?php echo htmlspecialchars($u['nombre']); ?></div>
                                    <div class="text-info font-monospace small fw-bold"><?php echo htmlspecialchars($u['email']); ?></div>
                                    <?php if (!empty($u['extension_poe'])): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2 py-0 small fw-bold">
                                                <i class="bi bi-telephone-fill me-1"></i> Ext. <?php echo htmlspecialchars($u['extension_poe']); ?>
                                            </span>
                                            <?php if (!empty($u['telefono']) && $u['telefono'] !== $u['extension_poe']): ?>
                                                <span class="text-secondary small ms-1"><?php echo htmlspecialchars($u['telefono']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif (!empty($u['telefono']) && $u['telefono'] !== 'N/A' && $u['telefono'] !== 'Sin especificar'): ?>
                                        <div class="mt-1 small text-secondary">
                                            <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($u['telefono']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-white fw-bold small"><?php echo htmlspecialchars($u['puesto'] ?? 'Sin especificar'); ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-agencia px-2 py-1 mb-1 d-inline-block small">
                                        <i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($u['agencia'] ?? $agenciaSesion); ?>
                                    </span>
                                    <?php if (!empty($u['area'])): ?>
                                        <br>
                                        <span class="badge badge-area px-2 py-1 small">
                                            <i class="bi bi-diagram-3 me-1"></i> <?php echo htmlspecialchars($u['area']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tieneAcceso): ?>
                                        <span class="badge bg-primary bg-opacity-25 text-primary border border-primary px-3 py-1 fw-bold"><i class="bi bi-door-open-fill me-1"></i> Con Acceso</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-3 py-1 fw-bold"><i class="bi bi-door-closed-fill me-1"></i> Solo Info</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($cantEquipos > 0): ?>
                                        <span class="badge badge-equipos px-2 py-1 fw-bold">
                                            <i class="bi bi-display-fill me-1"></i> <?php echo $cantEquipos; ?> Equipos
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-dark text-secondary border">0 Equipos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tieneAcceso): ?>
                                        <span class="badge bg-dark text-info border border-secondary px-2 py-1 small fw-bold"><i class="bi bi-person-gear me-1"></i> <?php echo htmlspecialchars($u['rol'] ?? 'Usuario'); ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary small fst-italic">Sin Rol</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['activo']): ?>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success rounded-pill px-3 py-1 fw-bold"><i class="bi bi-circle-fill me-1" style="font-size: 0.4rem;"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary rounded-pill px-3 py-1 fw-bold">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <?php if ($tieneAcceso): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info rounded-2" onclick='abrirModalPermisos(<?php echo json_encode($u); ?>)'>
                                                <i class="bi bi-shield-lock-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-2" onclick='abrirModalEditarUsuario(<?php echo json_encode($u); ?>)'>
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- =================================================== -->
<!-- MODAL: FICHA / EXPEDIENTE DEL USUARIO Y EQUIPOS     -->
<!-- =================================================== -->
<div class="modal fade" id="modalFichaUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-info border-opacity-50">
            <div class="modal-header border-secondary p-4" style="background: #09182b;">
                <div class="d-flex align-items-center gap-4">
                    <div id="ficha_avatar_box"></div>
                    <div>
                        <h3 class="modal-title fw-bold text-white mb-1" id="ficha_nombre">---</h3>
                        <span class="text-info font-monospace fs-6 fw-bold" id="ficha_email">---</span>
                        <div class="mt-2 text-label-contrast small" style="font-size: 0.8rem;">
                            <i class="bi bi-zoom-in me-1 text-info"></i> Haz clic en la fotografía para verla en tamaño completo
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                
                <!-- DATOS GENERALES DEL USUARIO -->
                <div class="card p-3 mb-4" style="background: #0e2444; border: 1px solid rgba(56, 189, 248, 0.25);">
                    <h6 class="fw-bold text-info border-bottom border-secondary pb-2 mb-3">
                        <i class="bi bi-person-badge-fill me-2"></i> Expediente del Personal
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <span class="text-label-contrast d-block mb-1">Agencia / Sucursal</span>
                            <span class="text-white fw-bold" id="ficha_agencia">---</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-label-contrast d-block mb-1">Área / Departamento</span>
                            <span class="text-white fw-bold" id="ficha_area">---</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-label-contrast d-block mb-1">Puesto / Cargo</span>
                            <span class="text-white fw-bold" id="ficha_puesto">---</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-label-contrast d-block mb-1">Teléfono / Extensión</span>
                            <span class="text-white fw-bold" id="ficha_telefono">---</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-label-contrast d-block mb-1">Acceso al Portal</span>
                            <span id="ficha_acceso_badge">---</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-label-contrast d-block mb-1">Rol de Usuario</span>
                            <span class="text-white fw-bold" id="ficha_rol">---</span>
                        </div>
                    </div>
                </div>

                <!-- EQUIPOS E INVENTARIO ASIGNADOS -->
                <div class="card p-3" style="background: #0e2444; border: 1px solid rgba(56, 189, 248, 0.25);">
                    <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3">
                        <h6 class="fw-bold text-success mb-0">
                            <i class="bi bi-display-fill me-2"></i> Hardware e Inventario Asignado
                        </h6>
                        <span class="badge bg-success bg-opacity-25 text-success border border-success fw-bold" id="ficha_equipos_count">0 Equipos</span>
                    </div>

                    <div id="ficha_contenedor_equipos">
                        <!-- Inyectado dinámicamente con JS -->
                    </div>
                </div>

            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">Cerrar Ficha</button>
            </div>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: CREAR / EDITAR USUARIO                       -->
<!-- =================================================== -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="guardar_usuario">
                <input type="hidden" name="user_id" id="form_user_id" value="0">

                <div class="modal-header" style="background: #09182b;">
                    <h5 class="modal-title fw-bold text-white" id="modalUsuarioLabel"><i class="bi bi-person-plus text-primary me-2"></i> Crear Nuevo Usuario / Personal</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- NOMBRE COMPLETO -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-label-contrast">Nombre Completo del Usuario</label>
                            <input type="text" name="nombre" id="form_nombre" class="form-control" placeholder="ej. Juan Pérez" required>
                        </div>

                        <!-- CORREO ELECTRÓNICO (USUARIO PORTAL) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-label-contrast">Correo Electrónico Corporativo</label>
                            <input type="email" name="email" id="form_email" class="form-control" placeholder="ej. juan.perez@grupohuerta.mx" required>
                            <small class="text-secondary fs-7">Será la cuenta ingresada para iniciar sesión en el portal.</small>
                        </div>

                        <!-- SELECCIÓN DE AGENCIA (AGENCIA ACTUAL VS CORPORATIVO) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-label-contrast">Agencia / Sucursal</label>
                            <select name="agencia" id="form_agencia" class="form-select border-primary fw-bold">
                                <option value="<?php echo htmlspecialchars($agenciaSesion); ?>"><?php echo htmlspecialchars($agenciaSesion); ?></option>
                                <option value="Corporativo">🏢 Corporativo</option>
                            </select>
                        </div>

                        <!-- ÁREA DE LA AGENCIA (CATÁLOGO DINÁMICO DE ÁREAS) -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-label-contrast">Área de la Agencia</label>
                            <select name="area" id="form_area" class="form-select border-primary">
                                <option value="">Selecciona un Área...</option>
                                <?php foreach ($areasDisponibles as $nomArea): ?>
                                    <option value="<?php echo htmlspecialchars($nomArea); ?>"><?php echo htmlspecialchars($nomArea); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- PUESTO -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-label-contrast">Puesto / Cargo</label>
                            <input type="text" name="puesto" id="form_puesto" class="form-control" placeholder="ej. Asesor de Ventas, Gerente de Servicio">
                        </div>

                        <!-- TELÉFONO / EXTENSIÓN -->
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-label-contrast">Teléfono / Conmutador</label>
                            <input type="text" name="telefono" id="form_telefono" class="form-control" placeholder="ej. 55 1234 5678">
                            <div id="form_poe_info" class="small text-warning mt-1 d-none">
                                <i class="bi bi-telephone-fill me-1"></i> Teléfono PoE vinculado: <strong>Ext. <span id="form_poe_ext"></span></strong>
                            </div>
                        </div>

                        <!-- SUBIR FOTOGRAFÍA / IMAGEN DE PERFIL -->
                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-info"><i class="bi bi-camera me-1"></i> Fotografía / Imagen de Perfil (Opcional)</label>
                            <input type="file" name="foto_file" id="form_foto_file" class="form-control" accept="image/*">
                            <input type="hidden" name="foto_cropped_base64" id="form_foto_cropped_base64" value="">
                            <small class="text-secondary fs-7 d-block mt-1">Formatos admitidos: JPG, PNG, WEBP. Al seleccionar una imagen se abrirá el encuadrador interactivo.</small>
                            
                            <!-- VISTA PREVIA Y BOTÓN PARA MOVER POSICIÓN -->
                            <div id="preview_foto_container" class="mt-2 d-none">
                                <div class="d-flex align-items-center gap-3 p-2 rounded-3" style="background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.35);">
                                    <img id="preview_foto_img" src="" class="rounded-3" style="width: 65px; height: 65px; object-fit: cover; border: 2px solid #38bdf8;">
                                    <div>
                                        <span class="text-white fw-bold d-block small"><i class="bi bi-check-circle-fill text-success me-1"></i> Imagen cargada correctamente</span>
                                        <button type="button" class="btn btn-sm btn-info text-dark p-1 px-3 mt-1 fw-bold" onclick="abrirInlineCropper()" style="font-size: 0.8rem;">
                                            <i class="bi bi-arrows-move me-1"></i> Mover Posición / Zoom Foto
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- EDITOR INLINE DESPLEGABLE PARA MOVER Y CENTRAR LA FOTO -->
                            <div id="inlineCropperBox" class="card p-3 mt-3 d-none" style="background: #061325; border: 2px solid #38bdf8; border-radius: 16px;">
                                <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary border-opacity-50">
                                    <span class="fw-bold text-info fs-6"><i class="bi bi-arrows-move me-2"></i> Encuadrar y Mover Fotografía</span>
                                    <button type="button" class="btn-close btn-close-white" onclick="cerrarInlineCropper()"></button>
                                </div>

                                <p class="text-white small mb-2 text-center" style="font-size: 0.82rem;">
                                    <i class="bi bi-hand-index-thumb me-1 text-info"></i> <strong>Haz clic y arrastra</strong> sobre la foto para moverla. Usa la barra o la rueda del mouse para hacer Zoom.
                                </p>

                                <!-- Canvas Visor -->
                                <div class="d-flex justify-content-center mb-3">
                                    <div id="cropContainer" style="position: relative; width: 260px; height: 260px; overflow: hidden; border-radius: 20px; border: 3px solid #38bdf8; background: #020813; cursor: grab; user-select: none;">
                                        <canvas id="cropCanvas" width="260" height="260" style="width: 260px; height: 260px; display: block;"></canvas>
                                        <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; pointer-events: none; border-radius: 50%; box-shadow: 0 0 0 9999px rgba(2, 8, 19, 0.7); border: 2px dashed rgba(56, 189, 248, 0.9);"></div>
                                    </div>
                                </div>

                                <!-- Controles de Zoom y Botones -->
                                <div class="row g-2 align-items-center justify-content-center mb-2">
                                    <div class="col-8">
                                        <label class="form-label text-secondary small fw-bold mb-0"><i class="bi bi-zoom-in me-1"></i> Zoom / Escala</label>
                                        <input type="range" class="form-range" id="cropZoomRange" min="0.2" max="3" step="0.02" value="1">
                                    </div>
                                    <div class="col-4 text-end">
                                        <button type="button" class="btn btn-outline-info btn-sm w-100 fw-bold py-1" onclick="rotarImagenCrop()">
                                            <i class="bi bi-arrow-clockwise me-1"></i> Rotar
                                        </button>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end gap-2 mt-2 pt-2 border-top border-secondary border-opacity-25">
                                    <button type="button" class="btn btn-secondary btn-sm fw-bold px-3" onclick="cerrarInlineCropper()">Cancelar</button>
                                    <button type="button" class="btn btn-primary btn-sm fw-bold px-3" onclick="guardarRecorteFoto()"><i class="bi bi-check-circle me-1"></i> Guardar Posición Foto</button>
                                </div>
                            </div>
                        </div>

                        <hr class="my-2 border-secondary border-opacity-50">

                        <!-- CONTROL DE ACCESO AL PORTAL (CHECKBOX / SWITCH) -->
                        <div class="col-md-12">
                            <div class="card p-3 mb-2" style="background: rgba(13, 110, 253, 0.12); border: 1px solid rgba(13, 110, 253, 0.4);">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="acceso_portal" id="form_acceso_portal" value="1" checked onchange="toggleAccesoPortalUI()">
                                    <label class="form-check-label fw-bold text-white fs-6" for="form_acceso_portal">
                                        <i class="bi bi-door-open-fill text-primary me-1"></i> Acceso al Portal de Sistemas
                                    </label>
                                </div>
                                <div class="small text-light mt-1" id="acceso_portal_help">
                                    Permite al usuario iniciar sesión y asignarle un rol con permisos dinámicos por módulo.
                                </div>
                            </div>
                        </div>

                        <!-- BANNER ALERTA SI NO TIENE ACCESO -->
                        <div class="col-md-12">
                            <div id="info_sin_acceso" class="alert alert-warning border-0 rounded-3 mb-2 d-none">
                                <i class="bi bi-info-circle-fill me-2"></i> Este usuario quedará registrado <strong>únicamente como información de personal</strong> para el inventario de equipos y otros módulos. No requerirá contraseña ni podrá ingresar al portal.
                            </div>
                        </div>

                        <!-- BLOQUE DE CONFIGURACIÓN DE ACCESO (CONTRASEÑA Y ROL) -->
                        <div class="col-md-12" id="block_acceso_portal">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-label-contrast">Contraseña de Acceso</label>
                                    <input type="password" name="password" id="form_password" class="form-control" placeholder="Asigna una contraseña segura">
                                    <small class="text-secondary fs-7 d-block mt-1" id="pass_help">Requerida al registrar un nuevo usuario con acceso.</small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-label-contrast">Selecciona el Rol de Usuario</label>
                                    <select name="rol" id="form_rol" class="form-select border-primary fw-bold">
                                        <option value="Usuario">👤 Usuario (Acceso limitado por módulo)</option>
                                        <option value="Admin">🛡️ Admin (Acceso total a la agencia)</option>
                                        <option value="SuperAdmin">👑 SuperAdmin (Administrador Central)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="activo" id="form_activo" value="1" checked>
                                <label class="form-check-label text-white fw-bold" for="form_activo">Usuario Activo (Habilita el registro en la base de datos)</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold"><i class="bi bi-save me-1"></i> Guardar Usuario / Personal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL: PERMISOS DETALLADOS POR MÓDULO               -->
<!-- =================================================== -->
<div class="modal fade" id="modalPermisos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_permisos">
                <input type="hidden" name="target_user_id" id="perm_target_user_id" value="0">

                <div class="modal-header" style="background: #09182b;">
                    <div>
                        <h5 class="modal-title fw-bold text-info mb-0"><i class="bi bi-shield-lock-fill me-2"></i> Permisos de Módulos</h5>
                        <small class="text-light">Usuario: <strong class="text-white" id="perm_target_username">---</strong></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-custom table-bordered align-middle">
                            <thead>
                                <tr class="text-center">
                                    <th class="text-start">Módulo</th>
                                    <th>Ver / Ingresar</th>
                                    <th>Crear</th>
                                    <th>Editar</th>
                                    <th>Eliminar</th>
                                    <th>Exportar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modulosCat as $mod): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi <?php echo $mod['icono']; ?> text-primary fs-5"></i>
                                                <div class="fw-bold text-white"><?php echo htmlspecialchars($mod['nombre']); ?></div>
                                            </div>
                                        </td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-ver" name="permisos[<?php echo $mod['clave']; ?>][puede_ver]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-crear" name="permisos[<?php echo $mod['clave']; ?>][puede_crear]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-editar" name="permisos[<?php echo $mod['clave']; ?>][puede_editar]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-eliminar" name="permisos[<?php echo $mod['clave']; ?>][puede_eliminar]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input perm-checkbox perm-exportar" name="permisos[<?php echo $mod['clave']; ?>][puede_exportar]" value="1" data-mod="<?php echo $mod['clave']; ?>"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info rounded-3 px-4 text-dark fw-bold"><i class="bi bi-shield-check me-1"></i> Guardar Permisos</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<!-- =================================================== -->
<!-- LIGHTBOX OVERLAY FLOTANTE (VER FOTO EN TAMAÑO COMPLETO) -->
<!-- =================================================== -->
<div id="customFotoLightbox" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 999999; background: rgba(3, 8, 20, 0.92); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; flex-direction: column; cursor: pointer; padding: 20px;" onclick="cerrarCustomLightbox()">
    <div style="position: absolute; top: 20px; right: 30px; font-size: 2.5rem; color: #ffffff; font-weight: bold; line-height: 1; cursor: pointer; text-shadow: 0 0 10px rgba(0,0,0,0.8);" onclick="cerrarCustomLightbox()">&times;</div>
    <div class="text-center" style="max-width: 90vw; max-height: 90vh;" onclick="event.stopPropagation()">
        <h4 class="text-white fw-bold mb-3"><i class="bi bi-person-circle text-info me-2"></i> Fotografía de Perfil: <span id="lightbox_nombre" class="text-info"></span></h4>
        <img id="lightbox_img" src="" class="img-fluid rounded-4 border border-info shadow-lg mb-3" style="max-height: 75vh; max-width: 85vw; object-fit: contain; border-width: 3px !important; box-shadow: 0 15px 40px rgba(0,0,0,0.8) !important;">
        <div class="text-secondary small fw-bold">
            <button type="button" class="btn btn-outline-light btn-sm rounded-pill px-4 py-1" onclick="cerrarCustomLightbox()">
                <i class="bi bi-x-circle me-1"></i> Haz clic en cualquier parte para cerrar
            </button>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const permisosMapGlobal = <?php echo json_encode($permisosMap); ?>;
    const agenciaSesionGlobal = <?php echo json_encode($agenciaSesion); ?>;
    const equiposPorUsuarioMapGlobal = <?php echo json_encode($equiposPorUsuarioMap); ?>;

    // LÓGICA DE CANVASCROPPER INLINE (MOVER / ZOOM / ROTAR)
    let cropImg = new Image();
    let cropX = 0;
    let cropY = 0;
    let cropScale = 1;
    let cropRotation = 0;
    let isDraggingCrop = false;
    let startDragX = 0;
    let startDragY = 0;
    let currentRawImageSrc = '';

    function abrirInlineCropper() {
        let src = currentRawImageSrc;
        if (!src) {
            const previewImg = document.getElementById('preview_foto_img');
            if (previewImg && previewImg.src) {
                src = previewImg.src;
            }
        }
        if (src) {
            initCropCanvas(src);
        } else {
            alert("Por favor selecciona una fotografía primero.");
        }
    }

    function cerrarInlineCropper() {
        const cropperBox = document.getElementById('inlineCropperBox');
        if (cropperBox) cropperBox.classList.add('d-none');
    }

    function initCropCanvas(imageSrc) {
        if (!imageSrc) return;
        currentRawImageSrc = imageSrc;

        const cropperBox = document.getElementById('inlineCropperBox');
        if (cropperBox) cropperBox.classList.remove('d-none');

        cropImg = new Image();
        cropImg.onload = function() {
            const cw = 260;
            const ch = 260;

            const minScale = Math.max(cw / cropImg.width, ch / cropImg.height);
            cropScale = minScale;
            cropX = (cw - cropImg.width * cropScale) / 2;
            cropY = (ch - cropImg.height * cropScale) / 2;
            cropRotation = 0;

            const zoomInput = document.getElementById('cropZoomRange');
            if (zoomInput) {
                zoomInput.min = (minScale * 0.4).toFixed(3);
                zoomInput.max = (minScale * 4).toFixed(3);
                zoomInput.value = minScale.toFixed(3);
            }

            drawCropCanvas();
        };

        cropImg.onerror = function() {
            console.error("Error al cargar la imagen:", imageSrc);
        };

        cropImg.src = imageSrc;
        if (cropImg.complete && cropImg.naturalWidth !== 0) {
            cropImg.onload();
        }
    }

    function drawCropCanvas() {
        const canvas = document.getElementById('cropCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const cw = canvas.width;
        const ch = canvas.height;

        ctx.clearRect(0, 0, cw, ch);
        ctx.save();

        ctx.translate(cw / 2, ch / 2);
        ctx.rotate((cropRotation * Math.PI) / 180);
        ctx.translate(-cw / 2, -ch / 2);

        ctx.drawImage(cropImg, cropX, cropY, cropImg.width * cropScale, cropImg.height * cropScale);
        ctx.restore();
    }

    function rotarImagenCrop() {
        cropRotation = (cropRotation + 90) % 360;
        drawCropCanvas();
    }

    function guardarRecorteFoto() {
        try {
            const outCanvas = document.createElement('canvas');
            outCanvas.width = 400;
            outCanvas.height = 400;
            const outCtx = outCanvas.getContext('2d');

            const scaleFactor = 400 / 260;

            outCtx.save();
            outCtx.scale(scaleFactor, scaleFactor);
            outCtx.translate(260 / 2, 260 / 2);
            outCtx.rotate((cropRotation * Math.PI) / 180);
            outCtx.translate(-260 / 2, -260 / 2);

            outCtx.drawImage(cropImg, cropX, cropY, cropImg.width * cropScale, cropImg.height * cropScale);
            outCtx.restore();

            const base64Data = outCanvas.toDataURL('image/png');
            document.getElementById('form_foto_cropped_base64').value = base64Data;

            const previewContainer = document.getElementById('preview_foto_container');
            const previewImg = document.getElementById('preview_foto_img');
            if (previewContainer && previewImg) {
                previewImg.src = base64Data;
                previewContainer.classList.remove('d-none');
            }
        } catch (err) {
            console.error("Error al guardar recorte:", err);
        }

        cerrarInlineCropper();
    }


    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('cropContainer');
        if (container) {
            container.addEventListener('mousedown', (e) => {
                isDraggingCrop = true;
                startDragX = e.clientX - cropX;
                startDragY = e.clientY - cropY;
                container.style.cursor = 'grabbing';
            });

            window.addEventListener('mousemove', (e) => {
                if (!isDraggingCrop) return;
                cropX = e.clientX - startDragX;
                cropY = e.clientY - startDragY;
                drawCropCanvas();
            });

            window.addEventListener('mouseup', () => {
                if (isDraggingCrop) {
                    isDraggingCrop = false;
                    container.style.cursor = 'grab';
                }
            });

            container.addEventListener('touchstart', (e) => {
                if (e.touches.length === 1) {
                    isDraggingCrop = true;
                    startDragX = e.touches[0].clientX - cropX;
                    startDragY = e.touches[0].clientY - cropY;
                }
            }, { passive: true });

            container.addEventListener('touchmove', (e) => {
                if (!isDraggingCrop || e.touches.length !== 1) return;
                cropX = e.touches[0].clientX - startDragX;
                cropY = e.touches[0].clientY - startDragY;
                drawCropCanvas();
            }, { passive: true });

            container.addEventListener('touchend', () => {
                isDraggingCrop = false;
            });

            const zoomInput = document.getElementById('cropZoomRange');
            if (zoomInput) {
                zoomInput.addEventListener('input', (e) => {
                    const oldScale = cropScale;
                    const newScale = parseFloat(e.target.value);
                    const cw = 260;
                    const ch = 260;
                    const centerX = cw / 2;
                    const centerY = ch / 2;

                    const imgCenterX = (centerX - cropX) / oldScale;
                    const imgCenterY = (centerY - cropY) / oldScale;

                    cropScale = newScale;
                    cropX = centerX - imgCenterX * cropScale;
                    cropY = centerY - imgCenterY * cropScale;

                    drawCropCanvas();
                });
            }

            container.addEventListener('wheel', (e) => {
                e.preventDefault();
                const zoomInput = document.getElementById('cropZoomRange');
                let delta = e.deltaY < 0 ? 0.05 : -0.05;
                let newScale = parseFloat(cropScale) + delta;
                const minS = parseFloat(zoomInput.min);
                const maxS = parseFloat(zoomInput.max);
                newScale = Math.max(minS, Math.min(maxS, newScale));
                zoomInput.value = newScale;

                const cw = 260;
                const ch = 260;
                const centerX = cw / 2;
                const centerY = ch / 2;

                const imgCenterX = (centerX - cropX) / cropScale;
                const imgCenterY = (centerY - cropY) / cropScale;

                cropScale = newScale;
                cropX = centerX - imgCenterX * cropScale;
                cropY = centerY - imgCenterY * cropScale;

                drawCropCanvas();
            }, { passive: false });
        }

        const inputFoto = document.getElementById('form_foto_file');
        if (inputFoto) {
            inputFoto.addEventListener('change', function(e) {
                if (e.target.files && e.target.files[0]) {
                    const file = e.target.files[0];
                    const reader = new FileReader();
                    reader.onload = function(evt) {
                        initCropCanvas(evt.target.result);
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    });

    function verFotoFull(url, nombre) {
        if (!url) return;
        const lightbox = document.getElementById('customFotoLightbox');
        const lightboxImg = document.getElementById('lightbox_img');
        const lightboxNombre = document.getElementById('lightbox_nombre');

        if (lightbox && lightboxImg && lightboxNombre) {
            lightboxNombre.textContent = nombre || '';
            lightboxImg.src = url;
            lightbox.style.display = 'flex';
        }
    }

    function cerrarCustomLightbox() {
        const lightbox = document.getElementById('customFotoLightbox');
        if (lightbox) {
            lightbox.style.display = 'none';
        }
    }


    function abrirFichaSiNoEsBoton(event, u) {
        if (event.target.closest('button, a, select, input, form, .btn, .form-check-input')) {
            return;
        }
        verFichaUsuario(u);
    }

    function cambiarVista(tipo) {
        const grid = document.getElementById('vistaGridUsuarios');
        const areas = document.getElementById('vistaAreasUsuarios');
        const tabla = document.getElementById('vistaTablaUsuarios');

        const btnGrid = document.getElementById('btnVistaGrid');
        const btnAreas = document.getElementById('btnVistaAreas');
        const btnTabla = document.getElementById('btnVistaTabla');

        grid.classList.add('d-none');
        areas.classList.add('d-none');
        tabla.classList.add('d-none');

        btnGrid.className = 'btn btn-outline-secondary text-white fw-bold';
        btnAreas.className = 'btn btn-outline-secondary text-white fw-bold';
        btnTabla.className = 'btn btn-outline-secondary text-white fw-bold';

        if (tipo === 'grid') {
            grid.classList.remove('d-none');
            btnGrid.className = 'btn btn-primary fw-bold';
        } else if (tipo === 'areas') {
            areas.classList.remove('d-none');
            btnAreas.className = 'btn btn-primary fw-bold';
        } else if (tipo === 'tabla') {
            tabla.classList.remove('d-none');
            btnTabla.className = 'btn btn-primary fw-bold';
        }
    }

    function filtrarUsuarios() {
        const query = document.getElementById('busquedaUsuario').value.toLowerCase().trim();
        const items = document.querySelectorAll('.user-card-item');

        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            item.style.display = text.includes(query) ? '' : 'none';
        });

        // Ocultar o mostrar bloques de área en la vista por áreas si no coinciden
        const areaBlocks = document.querySelectorAll('.area-group-block');
        areaBlocks.forEach(block => {
            const visibleCards = block.querySelectorAll('.user-card-item:not([style*="display: none"])');
            if (query !== '' && visibleCards.length === 0) {
                block.style.display = 'none';
            } else {
                block.style.display = '';
            }
        });
    }

    function verFichaUsuario(u) {
        const eqList = equiposPorUsuarioMapGlobal[u.id] || [];
        const poeEq = eqList.find(e => e.tipo === 'Teléfono PoE');
        const extPoe = u.extension_poe || (poeEq && poeEq.extension) || '';
        const telNum = u.telefono_poe || u.telefono || '';

        document.getElementById('ficha_nombre').textContent = u.nombre;
        document.getElementById('ficha_email').textContent = u.email;
        document.getElementById('ficha_agencia').textContent = u.agencia || agenciaSesionGlobal;
        document.getElementById('ficha_area').textContent = u.area || 'Sin especificar';
        document.getElementById('ficha_puesto').textContent = u.puesto || 'Sin especificar';
        document.getElementById('ficha_rol').textContent = u.rol || 'Sin Rol';

        // Renderizar Teléfono / Extensión
        const telEl = document.getElementById('ficha_telefono');
        if (extPoe) {
            let tHtml = `<span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2 py-1 fs-6 fw-bold">` +
                        `<i class="bi bi-telephone-fill me-1"></i> Ext. ${extPoe}</span>`;
            if (telNum && telNum !== extPoe && telNum !== 'Sin especificar' && telNum !== 'N/A' && telNum !== '---') {
                tHtml += ` <span class="text-white ms-2 small"><i class="bi bi-phone text-secondary me-1"></i>${telNum}</span>`;
            }
            telEl.innerHTML = tHtml;
        } else if (u.telefono && u.telefono !== 'Sin especificar' && u.telefono !== 'N/A' && u.telefono !== '---') {
            telEl.innerHTML = `<span class="text-white"><i class="bi bi-telephone text-secondary me-1"></i> ${u.telefono}</span>`;
        } else {
            telEl.innerHTML = `<span class="text-secondary fst-italic">Sin especificar</span>`;
        }

        // Avatar
        const avatarBox = document.getElementById('ficha_avatar_box');
        const cleanNombreEsc = String(u.nombre).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        if (u.foto_url && u.foto_url !== '') {
            avatarBox.innerHTML = `<img src="${u.foto_url}" class="user-avatar-ficha" alt="Foto de ${cleanNombreEsc}" onclick="verFotoFull('${u.foto_url}', '${cleanNombreEsc}')" title="Haz clic para amplificar la imagen en tamaño completo">`;
        } else {
            avatarBox.innerHTML = `<div class="user-avatar-ficha d-flex align-items-center justify-content-center fw-bold fs-1 text-white">${u.nombre.charAt(0).toUpperCase()}</div>`;
        }


        // Acceso Portal Badge
        const tieneAcceso = (!u.hasOwnProperty('acceso_portal') || parseInt(u.acceso_portal) === 1);
        const badgeBox = document.getElementById('ficha_acceso_badge');
        if (tieneAcceso) {
            badgeBox.innerHTML = `<span class="badge bg-primary bg-opacity-25 text-primary border border-primary rounded-pill px-3 py-1 fw-bold"><i class="bi bi-door-open-fill me-1"></i> Con Acceso al Portal</span>`;
        } else {
            badgeBox.innerHTML = `<span class="badge bg-warning bg-opacity-25 text-warning border border-warning rounded-pill px-3 py-1 fw-bold"><i class="bi bi-door-closed-fill me-1"></i> Solo Info Admin (Sin Acceso)</span>`;
        }

        // Renderizar Equipos Asignados
        const countBox = document.getElementById('ficha_equipos_count');
        countBox.textContent = eqList.length + ' Equipos Asignados';

        const container = document.getElementById('ficha_contenedor_equipos');
        container.innerHTML = '';

        if (eqList.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-inbox fs-3 d-block mb-2 text-muted"></i>
                    No hay equipos de hardware o licencias registrados a nombre de <strong>${u.nombre}</strong>.
                </div>
            `;
        } else {
            const grid = document.createElement('div');
            grid.className = 'row g-3';

            eqList.forEach(eq => {
                const cardCol = document.createElement('div');
                cardCol.className = 'col-md-6';
                if (eq.tipo === 'Teléfono PoE') {
                    cardCol.innerHTML = `
                        <div class="p-3 rounded-3 h-100" style="background: #09182b; border: 1px solid rgba(245, 158, 11, 0.45); box-shadow: 0 4px 15px rgba(245, 158, 11, 0.08);">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-warning bg-opacity-25 text-warning border border-warning small px-2 py-1 fw-bold">
                                    <i class="bi bi-telephone-fill me-1"></i> Teléfono PoE
                                </span>
                                <span class="badge bg-dark border text-secondary small fw-bold">${eq.estado || 'Activo'}</span>
                            </div>
                            <div class="fw-bold text-white mb-2 fs-6">
                                <i class="bi bi-telephone-outbound text-warning me-2"></i> ${eq.nombre}
                            </div>
                            ${eq.extension ? `
                            <div class="mb-2">
                                <span class="badge bg-warning text-dark fw-bold px-2 py-1">
                                    <i class="bi bi-telephone me-1"></i> Extensión: ${eq.extension}
                                </span>
                            </div>` : ''}
                            <div class="small text-secondary mb-1">
                                <strong>Serie / MAC:</strong> <span class="font-monospace text-light">${eq.serie || 'S/N'}</span>
                                ${eq.mac ? ` &bull; <span class="font-monospace text-info">${eq.mac}</span>` : ''}
                            </div>
                            ${eq.ip ? `<div class="small text-secondary mb-1"><strong>IP:</strong> <span class="text-info font-monospace">${eq.ip}</span></div>` : ''}
                            ${eq.nodo ? `
                            <div class="small text-secondary mb-1">
                                <strong>Nodo de Red:</strong> <span class="badge bg-dark text-info border border-secondary">Nodo ${eq.nodo}</span>
                                ${eq.switch ? ` &bull; SW: <span class="text-light">${eq.switch} ${eq.puerto_sw ? `[P${eq.puerto_sw}]` : ''}</span>` : ''}
                            </div>` : ''}
                            <div class="small text-secondary"><strong>Área:</strong> ${eq.departamento || 'General'}</div>
                        </div>
                    `;
                } else {
                    cardCol.innerHTML = `
                        <div class="p-3 rounded-3 h-100" style="background: #09182b; border: 1px solid rgba(56, 189, 248, 0.3);">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-primary bg-opacity-25 text-primary border border-primary small px-2 py-1 fw-bold">${eq.tipo}</span>
                                <span class="badge bg-dark border text-secondary small fw-bold">${eq.estado}</span>
                            </div>
                            <div class="fw-bold text-white mb-1"><i class="bi bi-display text-info me-2"></i> ${eq.nombre}</div>
                            <div class="small text-secondary mb-1"><strong>Serie:</strong> <span class="font-monospace text-light">${eq.serie}</span></div>
                            <div class="small text-secondary"><strong>Dept / Puesto:</strong> ${eq.departamento} (${eq.puesto})</div>
                        </div>
                    `;
                }
                grid.appendChild(cardCol);
            });

            container.appendChild(grid);
        }

        const modal = new bootstrap.Modal(document.getElementById('modalFichaUsuario'));
        modal.show();
    }

    function toggleAccesoPortalUI() {
        const hasAccess = document.getElementById('form_acceso_portal').checked;
        const blockAcceso = document.getElementById('block_acceso_portal');
        const infoSinAcceso = document.getElementById('info_sin_acceso');
        const help = document.getElementById('acceso_portal_help');

        if (hasAccess) {
            blockAcceso.classList.remove('d-none');
            infoSinAcceso.classList.add('d-none');
            help.textContent = 'Permite al usuario iniciar sesión y asignarle un rol con permisos dinámicos por módulo.';
        } else {
            blockAcceso.classList.add('d-none');
            infoSinAcceso.classList.remove('d-none');
            help.textContent = 'Usuario sin acceso a sesión. Guardado como información de personal para el inventario de equipos y otros módulos.';
        }
    }

    function abrirModalNuevoUsuario() {
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-person-plus text-primary me-2"></i> Crear Nuevo Usuario / Personal';
        document.getElementById('form_user_id').value = '0';
        document.getElementById('form_nombre').value = '';
        document.getElementById('form_email').value = '';
        document.getElementById('form_agencia').value = agenciaSesionGlobal;
        document.getElementById('form_area').value = '';
        const poeInfo = document.getElementById('form_poe_info');
        if (poeInfo) poeInfo.classList.add('d-none');
        document.getElementById('form_puesto').value = '';
        document.getElementById('form_telefono').value = '';
        document.getElementById('form_foto_file').value = '';
        document.getElementById('form_foto_cropped_base64').value = '';
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = 'Usuario';
        document.getElementById('form_activo').checked = true;
        document.getElementById('form_acceso_portal').checked = true;
        document.getElementById('pass_help').textContent = 'Requerida al registrar un nuevo usuario con acceso.';

        const previewContainer = document.getElementById('preview_foto_container');
        if (previewContainer) previewContainer.classList.add('d-none');
        currentRawImageSrc = '';

        toggleAccesoPortalUI();

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalEditarUsuario(u) {
        document.getElementById('modalUsuarioLabel').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Usuario / Personal';
        document.getElementById('form_user_id').value = u.id;
        document.getElementById('form_nombre').value = u.nombre;
        document.getElementById('form_email').value = u.email;
        document.getElementById('form_agencia').value = u.agencia || agenciaSesionGlobal;
        document.getElementById('form_area').value = u.area || '';
        document.getElementById('form_puesto').value = u.puesto || '';
        document.getElementById('form_telefono').value = u.telefono || '';

        const poeInfo = document.getElementById('form_poe_info');
        const poeExt = document.getElementById('form_poe_ext');
        if (poeInfo && poeExt) {
            const extVal = u.extension_poe || ((equiposPorUsuarioMapGlobal[u.id] || []).find(e => e.tipo === 'Teléfono PoE')?.extension);
            if (extVal) {
                poeExt.textContent = extVal;
                poeInfo.classList.remove('d-none');
            } else {
                poeInfo.classList.add('d-none');
            }
        }
        document.getElementById('form_foto_file').value = '';
        document.getElementById('form_foto_cropped_base64').value = '';
        document.getElementById('form_password').value = '';
        document.getElementById('form_rol').value = u.rol || 'Usuario';
        document.getElementById('form_activo').checked = (parseInt(u.activo) === 1);
        document.getElementById('form_acceso_portal').checked = (!u.hasOwnProperty('acceso_portal') || parseInt(u.acceso_portal) === 1);
        document.getElementById('pass_help').textContent = 'Dejar en blanco para mantener la contraseña actual.';

        const previewContainer = document.getElementById('preview_foto_container');
        const previewImg = document.getElementById('preview_foto_img');
        if (u.foto_url && u.foto_url !== '' && previewContainer && previewImg) {
            previewImg.src = u.foto_url;
            currentRawImageSrc = u.foto_url;
            previewContainer.classList.remove('d-none');
        } else if (previewContainer) {
            previewContainer.classList.add('d-none');
            currentRawImageSrc = '';
        }

        toggleAccesoPortalUI();

        const modal = new bootstrap.Modal(document.getElementById('modalUsuario'));
        modal.show();
    }

    function abrirModalPermisos(u) {
        document.getElementById('perm_target_user_id').value = u.id;
        document.getElementById('perm_target_username').textContent = u.nombre + ' (' + u.email + ') - Rol: ' + u.rol;

        const checkboxes = document.querySelectorAll('.perm-checkbox');
        checkboxes.forEach(cb => cb.checked = false);

        if (u.rol === 'SuperAdmin' || u.rol === 'Admin') {
            checkboxes.forEach(cb => cb.checked = true);
        } else {
            const uPerms = permisosMapGlobal[u.id] || {};
            for (const moduloClave in uPerms) {
                const pm = uPerms[moduloClave];
                if (pm.puede_ver == 1) marcarCheck(moduloClave, 'perm-ver');
                if (pm.puede_crear == 1) marcarCheck(moduloClave, 'perm-crear');
                if (pm.puede_editar == 1) marcarCheck(moduloClave, 'perm-editar');
                if (pm.puede_eliminar == 1) marcarCheck(moduloClave, 'perm-eliminar');
                if (pm.puede_exportar == 1) marcarCheck(moduloClave, 'perm-exportar');
            }
        }

        const modal = new bootstrap.Modal(document.getElementById('modalPermisos'));
        modal.show();
    }

    function marcarCheck(modClave, claseCheck) {
        const el = document.querySelector('.' + claseCheck + '[data-mod="' + modClave + '"]');
        if (el) el.checked = true;
    }
</script>
<?php imprimirScriptExportadorExcelJS(); ?>
<?php include_once 'pwa_body.php'; ?>
</body>
</html>
