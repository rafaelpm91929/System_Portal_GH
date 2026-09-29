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
require_once 'excel_helper.php';

// Protección de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Usuario');
$agenciaUsuario = $_SESSION['agencia'] ?? 'Grupo Huerta';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

// Asegurar tablas de permisos, usuarios e inventarios
if ($pdo) {
    asegurarTablasPermisos($pdo);
    asegurarTablasEquipos($pdo);

    // Auto-migración defensiva de columnas para Directorio
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN contrasena_correo VARCHAR(255) NULL;");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN extension VARCHAR(50) NULL;");
    } catch (Throwable $e) {}
}

$mensaje = '';
$error = '';

// ====================================================
// MANEJO DE ACCIONES POST / AJAX
// ====================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. Guardar, modificar o eliminar contraseña de correo (EXCLUSIVO DE DIRECTORIO)
    if ($accion === 'guardar_contrasena_correo') {
        $userId = intval($_POST['user_id'] ?? 0);
        $subAccion = $_POST['sub_accion'] ?? 'guardar';
        $nuevaPass = trim($_POST['contrasena_correo'] ?? '');

        if ($userId > 0) {
            try {
                if ($subAccion === 'eliminar') {
                    $stmt = $pdo->prepare("UPDATE usuarios SET contrasena_correo = NULL WHERE id = ?");
                    $stmt->execute([$userId]);
                    $msgTexto = "Contraseña de correo eliminada correctamente.";
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET contrasena_correo = ? WHERE id = ?");
                    $stmt->execute([$nuevaPass, $userId]);
                    $msgTexto = "Contraseña de correo guardada exitosamente.";
                }

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'success', 'message' => $msgTexto, 'nueva_pass' => $subAccion === 'eliminar' ? '' : $nuevaPass]);
                    exit();
                }
                $mensaje = $msgTexto;
            } catch (Throwable $t) {
                $errTexto = "Error al actualizar contraseña: " . $t->getMessage();
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => $errTexto]);
                    exit();
                }
                $error = $errTexto;
            }
        }
    }

    // 2. Guardar o Editar Cuenta Departamental / General (Sin usuario personal del portal)
    if ($accion === 'guardar_cuenta_departamental') {
        $userId = intval($_POST['user_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $area = trim($_POST['area'] ?? 'General');
        $puesto = trim($_POST['puesto'] ?? 'Cuenta Departamental');
        $extension = trim($_POST['extension'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $contrasenaCorreo = trim($_POST['contrasena_correo'] ?? '');

        if (empty($nombre)) {
            $error = "El nombre o descripción de la cuenta departamental es obligatorio.";
        } else {
            try {
                if ($userId > 0) {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, area = ?, puesto = ?, extension = ?, telefono = ?, contrasena_correo = ? WHERE id = ? AND rol = 'Contacto'");
                    $stmt->execute([$nombre, $email, $area, $puesto, $extension, $telefono, $contrasenaCorreo ?: null, $userId]);
                    $mensaje = "Cuenta departamental <strong>" . htmlspecialchars($nombre) . "</strong> actualizada.";
                } else {
                    $usrLogin = !empty($email) ? $email : ('dept_' . time() . '_' . rand(100, 999));
                    $passHash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, email, area, puesto, extension, telefono, contrasena_correo, agencia, rol, activo, acceso_portal, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Contacto', 1, 0, ?)");
                    $stmt->execute([$usrLogin, $nombre, $email, $area, $puesto, $extension, $telefono, $contrasenaCorreo ?: null, $agenciaUsuario, $passHash]);
                    $mensaje = "Cuenta general / departamental <strong>" . htmlspecialchars($nombre) . "</strong> agregada al Directorio.";
                }
            } catch (Throwable $t) {
                $error = "Error al guardar cuenta departamental: " . $t->getMessage();
            }
        }
    }

    // 3. Eliminar Cuenta Departamental (Solo las de rol 'Contacto')
    if ($accion === 'eliminar_cuenta_departamental') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'Contacto'");
                $stmt->execute([$userId]);
                $mensaje = "Cuenta general / departamental eliminada del Directorio.";
            } catch (Throwable $t) {
                $error = "Error al eliminar: " . $t->getMessage();
            }
        }
    }

    // 4. Sincronizar automáticamente usuarios y extensiones desde el Inventario
    if ($accion === 'sincronizar_desde_inventario') {
        try {
            $agregados = 0;
            $actualizados = 0;

            // 1. Sincronizar desde Equipos VW
            $stmtEquipos = $pdo->query("SELECT usuario, departamento, puesto, correo, extension, nombre_equipo FROM inv_equipos_vw WHERE (usuario IS NOT NULL AND TRIM(usuario) != '') AND (estado IS NULL OR estado != 'Baja')");
            $equiposRows = $stmtEquipos ? $stmtEquipos->fetchAll(PDO::FETCH_ASSOC) : [];

            foreach ($equiposRows as $eq) {
                $nom = trim($eq['usuario'] ?? '');
                $corr = trim($eq['correo'] ?? '');
                $ext = trim($eq['extension'] ?? '');
                $dep = trim($eq['departamento'] ?? '');
                $pues = trim($eq['puesto'] ?? '');

                if (empty($nom) || in_array(strtolower($nom), ['disponible', 'libre', 'stock', 'baja', 'almacen', 'almacén', 'prestamo', 'préstamo', 'sistemas'])) {
                    continue;
                }

                $stmtCheck = $pdo->prepare("SELECT id, extension, email, area, puesto FROM usuarios WHERE (LOWER(TRIM(nombre)) = LOWER(TRIM(?))) OR (email IS NOT NULL AND email != '' AND LOWER(TRIM(email)) = LOWER(TRIM(?))) LIMIT 1");
                $stmtCheck->execute([$nom, $corr]);
                $userFound = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($userFound) {
                    $updFields = [];
                    $updParams = [];
                    if (empty($userFound['extension']) && !empty($ext)) {
                        $updFields[] = "extension = ?";
                        $updParams[] = $ext;
                    }
                    if (empty($userFound['area']) && !empty($dep)) {
                        $updFields[] = "area = ?";
                        $updParams[] = $dep;
                    }
                    if (empty($userFound['puesto']) && !empty($pues)) {
                        $updFields[] = "puesto = ?";
                        $updParams[] = $pues;
                    }
                    if (empty($userFound['email']) && !empty($corr)) {
                        $updFields[] = "email = ?";
                        $updParams[] = $corr;
                    }

                    if (!empty($updFields)) {
                        $updParams[] = $userFound['id'];
                        $stmtSync = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $updFields) . " WHERE id = ?");
                        $stmtSync->execute($updParams);
                        $actualizados++;
                    }
                }
            }

            // 2. Sincronizar desde Teléfonos PoE
            try {
                $stmtTel = $pdo->query("SELECT * FROM inv_telefonos_poe WHERE extension IS NOT NULL AND extension != ''");
                if ($stmtTel) {
                    while ($tel = $stmtTel->fetch(PDO::FETCH_ASSOC)) {
                        $uTel = trim($tel['usuario'] ?? '');
                        $cTel = trim($tel['correo'] ?? '');
                        $eTel = trim($tel['extension'] ?? '');
                        $tNum = trim($tel['numero_telefonico'] ?? '');
                        $aTel = trim($tel['area'] ?? ($tel['departamento'] ?? ''));

                        if (!empty($eTel) && (!empty($uTel) || !empty($cTel))) {
                            $stmtSyncTel = $pdo->prepare("UPDATE usuarios SET 
                                extension = COALESCE(NULLIF(extension, ''), ?),
                                telefono = COALESCE(NULLIF(telefono, ''), ?),
                                area = COALESCE(NULLIF(area, ''), ?)
                                WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) OR (email IS NOT NULL AND email != '' AND LOWER(TRIM(email)) = LOWER(TRIM(?)))");
                            $stmtSyncTel->execute([$eTel, $tNum ?: null, $aTel ?: null, $uTel, $cTel]);
                            $actualizados++;
                        }
                    }
                }
            } catch (Throwable $tTel) {}

            $mensaje = "Sincronización completada: datos actualizados desde Inventario.";
        } catch (Throwable $t) {
            $error = "Error durante la sincronización: " . $t->getMessage();
        }
    }
}

// ====================================================
// CARGA Y CRUCE DE DATOS EN TIEMPO REAL
// ====================================================
$contactosDirectorio = [];
$areasDisponibles = [];
$totalConExtension = 0;
$totalConCorreo = 0;
$totalConPassword = 0;
$matchedExtensions = [];

if ($pdo) {
    // 1. Obtener teléfonos PoE para mapear extensiones y números
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

    // 2. Obtener equipos asignados (VW y Corp) para mapear extensión y nombre de PC
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

    // 3. Consultar todos los colaboradores desde la tabla usuarios
    try {
        $stmtUsers = $pdo->query("SELECT id, usuario, nombre, email, area, puesto, telefono, extension, foto_url, contrasena_correo, rol, activo FROM usuarios WHERE activo = 1 ORDER BY area ASC, nombre ASC");
        $rawUsers = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($rawUsers as $u) {
            $nomKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($u['nombre'] ?? '')));
            $mailKey = preg_replace('/\s+/', ' ', mb_strtolower(trim($u['email'] ?? '')));

            $extFinal = trim($u['extension'] ?? '');
            $telFinal = trim($u['telefono'] ?? '');
            $pcAsignada = '';

            // Cruce con Teléfonos PoE
            $matchTel = $mapTelefonosPorCorreo[$mailKey] ?? ($mapTelefonosPorUsuario[$nomKey] ?? null);
            if ($matchTel) {
                if (empty($extFinal) && !empty($matchTel['extension'])) {
                    $extFinal = trim($matchTel['extension']);
                }
                if (empty($telFinal) && !empty($matchTel['numero_telefonico'])) {
                    $telFinal = trim($matchTel['numero_telefonico']);
                }
                if (empty($u['area']) && !empty($matchTel['area'])) {
                    $u['area'] = trim($matchTel['area']);
                } elseif (empty($u['area']) && !empty($matchTel['departamento'])) {
                    $u['area'] = trim($matchTel['departamento']);
                }
            }

            // Cruce con Equipos VW / Corp
            $matchEq = $mapEquiposPorCorreo[$mailKey] ?? ($mapEquiposPorUsuario[$nomKey] ?? null);
            if ($matchEq) {
                if (empty($extFinal) && !empty($matchEq['extension'])) {
                    $extFinal = trim($matchEq['extension']);
                }
                $pcAsignada = trim($matchEq['nombre_equipo'] ?? '');
                if (empty($u['area']) && !empty($matchEq['departamento'])) {
                    $u['area'] = trim($matchEq['departamento']);
                }
                if (empty($u['puesto']) && !empty($matchEq['puesto'])) {
                    $u['puesto'] = trim($matchEq['puesto']);
                }
            }

            // Auto-persistir la extensión en usuarios si estaba vacía
            if (!empty($extFinal) && empty($u['extension'])) {
                try {
                    $stmtAutoExt = $pdo->prepare("UPDATE usuarios SET extension = ? WHERE id = ?");
                    $stmtAutoExt->execute([$extFinal, $u['id']]);
                } catch (Throwable $tAuto) {}
            }

            if (!empty($extFinal)) {
                $matchedExtensions[$extFinal] = true;
            }

            $areaFinal = !empty($u['area']) ? trim($u['area']) : 'Sin Área';
            if (!in_array($areaFinal, $areasDisponibles) && $areaFinal !== 'Sin Área') {
                $areasDisponibles[] = $areaFinal;
            }

            $tieneExt = !empty($extFinal);
            $tieneMail = !empty($u['email']);
            $tienePass = !empty($u['contrasena_correo']);
            $esDepartamental = ($u['rol'] === 'Contacto');

            if ($tieneExt) $totalConExtension++;
            if ($tieneMail) $totalConCorreo++;
            if ($tienePass) $totalConPassword++;

            $contactosDirectorio[] = [
                'id' => $u['id'],
                'nombre' => $u['nombre'],
                'email' => $u['email'] ?? '',
                'area' => $areaFinal,
                'puesto' => $u['puesto'] ?? ($esDepartamental ? 'Buzón Departamental' : 'Colaborador'),
                'extension' => $extFinal,
                'telefono' => $telFinal,
                'foto_url' => $u['foto_url'] ?? '',
                'pc_asignada' => $pcAsignada,
                'contrasena_correo' => $u['contrasena_correo'] ?? '',
                'tiene_pass' => $tienePass,
                'es_departamental' => $esDepartamental,
                'es_fija' => false
            ];
        }
    } catch (Throwable $tUsers) {
        $error = "Error al consultar colaboradores: " . $tUsers->getMessage();
    }

    // 4. Extensiones que existen en Teléfonos PoE pero no están asignadas a ningún usuario personal
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

            if (!in_array($pArea, $areasDisponibles) && $pArea !== 'Sin Área') {
                $areasDisponibles[] = $pArea;
            }

            $totalConExtension++;
            $contactosDirectorio[] = [
                'id' => 'poe_' . $poe['id'],
                'nombre' => $pNom,
                'email' => $poe['correo'] ?? '',
                'area' => $pArea,
                'puesto' => 'Extensión Telefónica PoE',
                'extension' => $pExt,
                'telefono' => $poe['numero_telefonico'] ?? '',
                'foto_url' => '',
                'pc_asignada' => !empty($poe['ip']) ? ('IP: ' . $poe['ip']) : '',
                'contrasena_correo' => '',
                'tiene_pass' => false,
                'es_departamental' => true,
                'es_fija' => true
            ];
        }
    }

    // 5. Extensiones en Equipos VW que no estaban vinculadas a usuarios
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

            if (!in_array($eqArea, $areasDisponibles) && $eqArea !== 'Sin Área') {
                $areasDisponibles[] = $eqArea;
            }

            $totalConExtension++;
            $contactosDirectorio[] = [
                'id' => 'eq_' . rand(100, 999),
                'nombre' => $eqNom,
                'email' => $eqRow['correo'] ?? '',
                'area' => $eqArea,
                'puesto' => 'Extensión Equipo Fijo',
                'extension' => $eqExt,
                'telefono' => '',
                'foto_url' => '',
                'pc_asignada' => $eqRow['nombre_equipo'] ?? '',
                'contrasena_correo' => '',
                'tiene_pass' => false,
                'es_departamental' => true,
                'es_fija' => true
            ];
        }
    }
}
sort($areasDisponibles);

// ====================================================
// EXPORTACIÓN OFICIAL A MICROSOFT EXCEL (.XLS CON DISEÑO)
// ====================================================
if (isset($_GET['action']) && $_GET['action'] === 'exportar_excel') {
    $agenciaInfo = obtenerDatosAgenciaExcel($pdo, $agenciaUsuario);
    $agenciaLimpia = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agenciaInfo['nombre']);
    $fechaHoy = date('Ymd_His');
    $fileName = "Directorio_Oficial_{$agenciaLimpia}_{$fechaHoy}.xls";

    $columnasExcel = [
        ['label' => '# ID', 'width' => '50px', 'align' => 'center'],
        ['label' => 'Colaborador / Concepto', 'width' => '220px', 'align' => 'left'],
        ['label' => 'Tipo Contacto', 'width' => '130px', 'align' => 'center'],
        ['label' => 'Área / Depto', 'width' => '140px', 'align' => 'left'],
        ['label' => 'Puesto / Cargo', 'width' => '170px', 'align' => 'left'],
        ['label' => 'Correo Institucional', 'width' => '230px', 'align' => 'left', 'is_text' => true],
        ['label' => 'Extensión', 'width' => '100px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Teléfono Móvil', 'width' => '120px', 'align' => 'center', 'is_text' => true],
        ['label' => 'Equipo / PC', 'width' => '130px', 'align' => 'left'],
        ['label' => 'Contraseña Correo', 'width' => '140px', 'align' => 'center', 'is_text' => true]
    ];

    $filasExcel = [];
    foreach ($contactosDirectorio as $c) {
        $tipoTxt = $c['es_departamental'] ? ($c['es_fija'] ? 'Extensión Fija' : 'Cuenta Departamental') : 'Colaborador';
        $badgeClass = $c['es_departamental'] ? ($c['es_fija'] ? 'badge-pill badge-status-warn' : 'badge-pill badge-area') : '';
        
        $filasExcel[] = [
            ['val' => '<b>#' . $c['id'] . '</b>', 'align' => 'center'],
            ['val' => '<strong>' . htmlspecialchars($c['nombre']) . '</strong>', 'align' => 'left'],
            ['val' => '<span class="' . $badgeClass . '">' . htmlspecialchars($tipoTxt) . '</span>', 'align' => 'center'],
            ['val' => '<span class="badge-pill badge-area">' . htmlspecialchars($c['area']) . '</span>', 'align' => 'left'],
            ['val' => htmlspecialchars($c['puesto']), 'align' => 'left'],
            ['val' => !empty($c['email']) ? '<span style="color:#0284c7;">' . htmlspecialchars($c['email']) . '</span>' : '<span style="color:#94a3b8; font-style:italic;">Sin correo</span>', 'align' => 'left', 'is_text' => true],
            ['val' => !empty($c['extension']) ? '<span class="badge-pill badge-ext"><b>' . htmlspecialchars($c['extension']) . '</b></span>' : '<span style="color:#94a3b8;">---</span>', 'align' => 'center', 'is_text' => true],
            ['val' => !empty($c['telefono']) ? htmlspecialchars($c['telefono']) : '---', 'align' => 'center', 'is_text' => true],
            ['val' => !empty($c['pc_asignada']) ? htmlspecialchars($c['pc_asignada']) : 'Sin Asignar', 'align' => 'left'],
            ['val' => $esAdmin ? (!empty($c['contrasena_correo']) ? '<span style="font-family:monospace; font-weight:bold; color:#0f172a;">' . htmlspecialchars($c['contrasena_correo']) . '</span>' : '<span style="color:#94a3b8; font-style:italic;">No asignada</span>') : 'Protegida', 'align' => 'center', 'is_text' => true]
        ];
    }

    descargarExcelConDiseno(
        'DIRECTORIO TELEFÓNICO Y CORREOS INSTITUCIONALES',
        'Padrón Oficial de Colaboradores, Cuentas y Extensiones',
        $columnasExcel,
        $filasExcel,
        $agenciaInfo,
        $fileName,
        'Directorio'
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Directorio Telefónico y de Correos - Grupo Huerta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #061325;
            background-image: radial-gradient(#0e2440 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            padding-bottom: 60px;
        }

        .top-navbar {
            background: rgba(6, 19, 37, 0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 35px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .main-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 30px 35px;
        }

        .kpi-card {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            transition: all 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            border-color: rgba(219, 39, 119, 0.4);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        }
        .kpi-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .kpi-pink { background: rgba(219, 39, 119, 0.15); color: #f472b6; }
        .kpi-blue { background: rgba(37, 99, 235, 0.15); color: #60a5fa; }
        .kpi-green { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .kpi-yellow { background: rgba(234, 179, 8, 0.15); color: #fde047; }

        .search-box-wrapper {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .search-box-wrapper:focus-within {
            border-color: #db2777;
            box-shadow: 0 0 0 3px rgba(219, 39, 119, 0.25);
            background: #112543;
        }
        .search-input {
            background: transparent;
            border: none;
            color: #ffffff;
            outline: none;
            width: 100%;
            font-size: 0.95rem;
        }
        .search-input::placeholder {
            color: #64748b;
        }

        .filter-pill {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            user-select: none;
        }
        .filter-pill:hover, .filter-pill.active {
            background: #db2777;
            border-color: #db2777;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(219, 39, 119, 0.35);
        }

        .directory-table-container {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }

        .table-custom {
            margin-bottom: 0;
            color: #ffffff;
            width: 100%;
        }
        .table-custom thead th {
            background: rgba(14, 36, 64, 0.95);
            color: #94a3b8;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            white-space: nowrap;
        }
        .table-custom tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background 0.15s ease;
        }
        .table-custom tbody tr:hover {
            background: rgba(255, 255, 255, 0.035);
        }
        .table-custom tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            font-size: 0.92rem;
        }

        .avatar-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #db2777, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            color: #ffffff;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.3);
        }
        .avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-dept {
            background: linear-gradient(135deg, #0284c7, #2563eb) !important;
        }

        .ext-badge {
            background: rgba(37, 99, 235, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #60a5fa;
            border-radius: 8px;
            padding: 5px 12px;
            font-family: 'Consolas', monospace;
            font-weight: 700;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .ext-badge-empty {
            background: rgba(234, 179, 8, 0.1);
            border: 1px dashed rgba(234, 179, 8, 0.3);
            color: #fde047;
            font-size: 0.78rem;
            padding: 4px 10px;
            border-radius: 8px;
        }

        .area-tag {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
        }
        .area-ventas { background: rgba(59, 130, 246, 0.18); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
        .area-servicio { background: rgba(34, 197, 94, 0.18); color: #86efac; border: 1px solid rgba(34, 197, 94, 0.3); }
        .area-refacciones { background: rgba(249, 115, 22, 0.18); color: #fdba74; border: 1px solid rgba(249, 115, 22, 0.3); }
        .area-administracion { background: rgba(168, 85, 247, 0.18); color: #d8b4fe; border: 1px solid rgba(168, 85, 247, 0.3); }
        .area-sistemas { background: rgba(6, 182, 212, 0.18); color: #67e8f9; border: 1px solid rgba(6, 182, 212, 0.3); }
        .area-default { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.25); }

        .btn-action-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            transition: all 0.15s;
            cursor: pointer;
        }
        .btn-action-icon:hover {
            background: rgba(219, 39, 119, 0.2);
            border-color: #db2777;
            color: #ffffff;
        }

        .password-container {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 4px 8px;
        }

        /* Estilos de Impresión */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding-bottom: 0 !important;
            }
            .top-navbar, .kpi-row, .action-bar-row, .btn-action-icon, .btn-no-print, .password-column {
                display: none !important;
            }
            .directory-table-container {
                background: #ffffff !important;
                border: 1px solid #cccccc !important;
                box-shadow: none !important;
            }
            .table-custom {
                color: #000000 !important;
            }
            .table-custom thead th {
                background: #f1f5f9 !important;
                color: #0f172a !important;
                border-bottom: 2px solid #94a3b8 !important;
            }
            .table-custom tbody tr {
                border-bottom: 1px solid #e2e8f0 !important;
            }
            .avatar-circle {
                display: none !important;
            }
            .ext-badge {
                border: 1px solid #000000 !important;
                color: #000000 !important;
                background: transparent !important;
                font-weight: 800 !important;
            }
            .area-tag {
                border: 1px solid #64748b !important;
                color: #000000 !important;
                background: transparent !important;
            }
        }
    </style>
</head>
<body>

<!-- Navbar Principal -->
<div class="top-navbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <a href="menu.php" class="btn btn-outline-secondary btn-sm rounded-3 px-3 text-white border-opacity-25" title="Regresar al Menú Principal">
            <i class="bi bi-arrow-left me-1"></i> Menú
        </a>
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-person-lines-fill fs-4" style="color: #f472b6;"></i>
            <span class="fw-bold tracking-wide">DIRECTORIO <span class="text-secondary fw-normal">| <?php echo htmlspecialchars($agenciaUsuario); ?></span></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="text-end d-none d-md-block">
            <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <div class="text-secondary" style="font-size: 0.75rem;"><?php echo strtoupper($rolActual); ?></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Salir
        </a>
    </div>
</div>

<div class="main-container">

    <!-- Mensajes de Notificación -->
    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 mb-4 border-0 shadow-sm" role="alert" style="background: rgba(34, 197, 94, 0.2); color: #86efac; border: 1px solid rgba(34, 197, 94, 0.3) !important;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 mb-4 border-0 shadow-sm" role="alert" style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3) !important;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo $error; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Encabezado y Título -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="text-uppercase tracking-wider small fw-bold text-secondary mb-1">Directorio Telefónico y Correos</div>
            <h2 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-telephone-inbound-fill" style="color: #ec4899;"></i> Directorio Oficial de la Sucursal
            </h2>
            <div class="text-secondary small">Los colaboradores y sus extensiones se sincronizan automáticamente desde <strong>Gestión de Usuarios</strong> e <strong>Inventario</strong>.</div>
        </div>

        <div class="d-flex flex-wrap gap-2 btn-no-print">
            <!-- Sincronizar desde Inventario -->
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas sincronizar los colaboradores y extensiones registrados en el Inventario de Equipos?');">
                <input type="hidden" name="accion" value="sincronizar_desde_inventario">
                <button type="submit" class="btn btn-outline-info rounded-3 px-3 py-2 fw-semibold small shadow-sm" title="Jalar automáticamente colaboradores y extensiones de inventario">
                    <i class="bi bi-arrow-repeat me-1"></i> Sincronizar con Inventario
                </button>
            </form>

            <!-- Exportar a Excel -->
            <a href="directorio.php?action=exportar_excel" class="btn btn-success rounded-3 px-3 py-2 fw-semibold small shadow-sm d-flex align-items-center gap-1.5" style="background: #16a34a; border-color: #16a34a;" title="Descargar Directorio en formato Microsoft Excel con diseño y logotipo oficial">
                <i class="bi bi-file-earmark-excel-fill fs-6"></i> Exportar a Excel (.xls)
            </a>

            <!-- Generar PDF / Imprimir Oficial con Membrete y Logo -->
            <a href="imprimir_directorio.php" target="_blank" class="btn btn-warning text-dark fw-bold rounded-3 px-3 py-2 small shadow-sm d-flex align-items-center gap-1.5" title="Abre el formato oficial membretado con logo y razón social listo para descargar en PDF o imprimir">
                <i class="bi bi-file-earmark-pdf-fill fs-6 text-danger"></i> Generar PDF / Imprimir
            </a>

            <!-- Nuevo Correo / Extensión Departamental (Sin Usuario) -->
            <button type="button" class="btn btn-pink rounded-3 px-3 py-2 fw-semibold small shadow-sm text-white" style="background: #db2777; border-color: #db2777;" onclick="abrirModalNuevaCuentaDept()">
                <i class="bi bi-envelope-plus me-1"></i> + Correo / Extensión General
            </button>
        </div>
    </div>

    <!-- KPIs Superiores -->
    <div class="row g-3 mb-4 kpi-row">
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-pink">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">TOTAL CONTACTOS</div>
                    <div class="fs-3 fw-bold"><?php echo count($contactosDirectorio); ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-blue">
                    <i class="bi bi-telephone-fill"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">CON EXTENSIÓN</div>
                    <div class="fs-3 fw-bold text-info"><?php echo $totalConExtension; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-green">
                    <i class="bi bi-envelope-check-fill"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">CON CORREO OFICIAL</div>
                    <div class="fs-3 fw-bold text-success"><?php echo $totalConCorreo; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon kpi-yellow">
                    <i class="bi bi-key-fill"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-bold">CONTRASEÑAS GUARDADAS</div>
                    <div class="fs-3 fw-bold text-warning"><?php echo $totalConPassword; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Barra de Búsqueda y Filtros de Área -->
    <div class="row g-3 align-items-center mb-4 action-bar-row">
        <div class="col-md-5 col-lg-4">
            <div class="search-box-wrapper">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="buscadorDirectorio" class="search-input" placeholder="Buscar por colaborador, correo, área o extensión..." onkeyup="filtrarDirectorioEnVivo()">
                <button type="button" class="btn btn-link btn-sm text-secondary p-0" onclick="limpiarBuscador()" title="Limpiar búsqueda">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        </div>

        <div class="col-md-7 col-lg-8">
            <div class="d-flex align-items-center gap-2 overflow-x-auto pb-1" id="contenedorPillsAreas">
                <span class="filter-pill active" onclick="filtrarPorArea('TODAS', this)">Todas las Áreas</span>
                <?php foreach ($areasDisponibles as $ar): ?>
                    <span class="filter-pill" onclick="filtrarPorArea('<?php echo htmlspecialchars($ar, ENT_QUOTES, 'UTF-8'); ?>', this)">
                        <?php echo htmlspecialchars($ar); ?>
                    </span>
                <?php endforeach; ?>
                <span class="filter-pill" onclick="filtrarPorArea('SIN ÁREA', this)">Sin Área</span>
            </div>
        </div>
    </div>

    <!-- Tabla Principal de Directorio -->
    <div class="directory-table-container">
        <div class="table-responsive">
            <table class="table table-custom align-middle" id="tablaDirectorio">
                <thead>
                    <tr>
                        <th style="width: 28%;">Colaborador / Concepto</th>
                        <th style="width: 15%;">Área</th>
                        <th style="width: 22%;">Correo Institucional</th>
                        <th style="width: 13%;">Extensión</th>
                        <th class="password-column" style="width: 14%;">Contraseña Correo</th>
                        <th class="text-end btn-no-print" style="width: 8%;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tbodyDirectorio">
                    <?php if (empty($contactosDirectorio)): ?>
                        <tr id="filaSinResultados">
                            <td colspan="6" class="text-center py-5 text-secondary">
                                <i class="bi bi-person-x fs-1 d-block mb-2 text-muted"></i>
                                <div class="fw-semibold">No hay colaboradores registrados en el Directorio.</div>
                                <div class="small text-muted mt-1">Los colaboradores se dan de alta en <strong>Gestión de Usuarios</strong> y se sincronizan aquí automáticamente.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($contactosDirectorio as $c): 
                            $areaLower = mb_strtolower($c['area']);
                            $tagClass = 'area-default';
                            if (strpos($areaLower, 'venta') !== false) $tagClass = 'area-ventas';
                            elseif (strpos($areaLower, 'serv') !== false) $tagClass = 'area-servicio';
                            elseif (strpos($areaLower, 'refacc') !== false) $tagClass = 'area-refacciones';
                            elseif (strpos($areaLower, 'admin') !== false || strpos($areaLower, 'conta') !== false) $tagClass = 'area-administracion';
                            elseif (strpos($areaLower, 'sistem') !== false || strpos($areaLower, 'it') !== false) $tagClass = 'area-sistemas';

                            $iniciales = '';
                            $partesNom = explode(' ', trim($c['nombre']));
                            if (!empty($partesNom[0])) $iniciales .= mb_substr($partesNom[0], 0, 1);
                            if (!empty($partesNom[1])) $iniciales .= mb_substr($partesNom[1], 0, 1);
                            
                            $isDept = !empty($c['es_departamental']);
                            $isFija = !empty($c['es_fija']);
                        ?>
                            <tr class="directorio-row" 
                                data-nombre="<?php echo htmlspecialchars(mb_strtolower($c['nombre']), ENT_QUOTES, 'UTF-8'); ?>"
                                data-area="<?php echo htmlspecialchars(mb_strtolower($c['area']), ENT_QUOTES, 'UTF-8'); ?>"
                                data-email="<?php echo htmlspecialchars(mb_strtolower($c['email']), ENT_QUOTES, 'UTF-8'); ?>"
                                data-extension="<?php echo htmlspecialchars(mb_strtolower($c['extension']), ENT_QUOTES, 'UTF-8'); ?>"
                                data-pc="<?php echo htmlspecialchars(mb_strtolower($c['pc_asignada']), ENT_QUOTES, 'UTF-8'); ?>">
                                
                                <!-- Colaborador / Concepto -->
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-circle <?php echo $isDept ? 'avatar-dept' : ''; ?>">
                                            <?php if (!empty($c['foto_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($c['foto_url']); ?>" alt="<?php echo htmlspecialchars($c['nombre']); ?>">
                                            <?php elseif ($isFija): ?>
                                                <i class="bi bi-telephone-fill"></i>
                                            <?php elseif ($isDept): ?>
                                                <i class="bi bi-building"></i>
                                            <?php else: ?>
                                                <?php echo strtoupper($iniciales ?: 'U'); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-white fs-6 d-flex align-items-center gap-2">
                                                <?php echo htmlspecialchars($c['nombre']); ?>
                                                <?php if ($isFija): ?>
                                                    <span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-25 py-0 px-1.5" style="font-size: 0.68rem;">Ext. Fija</span>
                                                <?php elseif ($isDept): ?>
                                                    <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary border-opacity-25 py-0 px-1.5" style="font-size: 0.68rem;">General</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-secondary small"><?php echo htmlspecialchars($c['puesto']); ?></div>
                                            <?php if (!empty($c['pc_asignada'])): ?>
                                                <div class="text-info font-monospace small" style="font-size: 0.72rem;">
                                                    <i class="bi bi-display me-1"></i><?php echo htmlspecialchars($c['pc_asignada']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Área -->
                                <td>
                                    <span class="area-tag <?php echo $tagClass; ?>">
                                        <?php echo htmlspecialchars($c['area']); ?>
                                    </span>
                                </td>

                                <!-- Correo Institucional -->
                                <td>
                                    <?php if (!empty($c['email'])): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>" class="text-info text-decoration-none font-monospace small" title="Clic para redactar correo">
                                                <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($c['email']); ?>
                                            </a>
                                            <button type="button" class="btn-action-icon btn-no-print" style="width: 26px; height: 26px;" onclick="copiarAlPortapapeles('<?php echo htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8'); ?>', 'Correo copiado al portapapeles')" title="Copiar correo">
                                                <i class="bi bi-copy" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-secondary small fst-italic">Sin correo</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Extensión Telefónica -->
                                <td>
                                    <?php if (!empty($c['extension'])): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="ext-badge" title="Extensión telefónica">
                                                <i class="bi bi-telephone-fill"></i> Ext. <?php echo htmlspecialchars($c['extension']); ?>
                                            </span>
                                            <button type="button" class="btn-action-icon btn-no-print" style="width: 26px; height: 26px;" onclick="copiarAlPortapapeles('<?php echo htmlspecialchars($c['extension'], ENT_QUOTES, 'UTF-8'); ?>', 'Extensión <?php echo htmlspecialchars($c['extension'], ENT_QUOTES, 'UTF-8'); ?> copiada')" title="Copiar extensión">
                                                <i class="bi bi-copy" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="ext-badge-empty" title="Sin extensión registrada en inventario">
                                            Sin Extensión
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Contraseña de Correo (Exclusivo) -->
                                <td class="password-column">
                                    <?php if ($isFija): ?>
                                        <span class="text-muted small fst-italic">No aplica</span>
                                    <?php elseif (!empty($c['contrasena_correo'])): ?>
                                        <div class="password-container" id="pass_container_<?php echo $c['id']; ?>">
                                            <span class="font-monospace small text-warning pass-masked" id="pass_masked_<?php echo $c['id']; ?>" data-pass="<?php echo htmlspecialchars($c['contrasena_correo'], ENT_QUOTES, 'UTF-8'); ?>">••••••••</span>
                                            
                                            <!-- Ver / Ocultar -->
                                            <button type="button" class="btn-action-icon" style="width: 24px; height: 24px;" onclick="togglePasswordVisibilidad('<?php echo $c['id']; ?>')" title="Mostrar / Ocultar contraseña">
                                                <i class="bi bi-eye" id="eye_icon_<?php echo $c['id']; ?>" style="font-size: 0.75rem;"></i>
                                            </button>
                                            
                                            <!-- Copiar -->
                                            <button type="button" class="btn-action-icon" style="width: 24px; height: 24px;" onclick="copiarAlPortapapeles(document.getElementById('pass_masked_<?php echo $c['id']; ?>').getAttribute('data-pass'), 'Contraseña de correo copiada')" title="Copiar contraseña">
                                                <i class="bi bi-clipboard" style="font-size: 0.75rem;"></i>
                                            </button>

                                            <!-- Editar Contraseña -->
                                            <button type="button" class="btn-action-icon" style="width: 24px; height: 24px;" onclick="abrirModalGestionarPassword(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)" title="Modificar / Eliminar contraseña">
                                                <i class="bi bi-pencil" style="font-size: 0.72rem;"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-0.5 small text-secondary border-opacity-25" onclick="abrirModalGestionarPassword(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)" title="Asignar contraseña de correo">
                                            <i class="bi bi-key me-1"></i> Asignar Pass
                                        </button>
                                    <?php endif; ?>
                                </td>

                                <!-- Acciones -->
                                <td class="text-end btn-no-print">
                                    <div class="d-inline-flex gap-1">
                                        <?php if ($isFija): ?>
                                            <a href="equipos.php?sec=telefonos_poe" class="btn-action-icon" title="Ver en Inventario de Teléfonos PoE">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        <?php elseif ($isDept): ?>
                                            <!-- Cuenta Departamental: se puede editar y eliminar -->
                                            <button type="button" class="btn-action-icon" onclick="abrirModalEditarCuentaDept(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)" title="Editar cuenta departamental">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Deseas eliminar esta cuenta general/departamental del Directorio?');">
                                                <input type="hidden" name="accion" value="eliminar_cuenta_departamental">
                                                <input type="hidden" name="user_id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="btn-action-icon text-danger" title="Eliminar cuenta departamental">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <!-- Colaborador Personal: ÚNICAMENTE se gestiona su contraseña de correo -->
                                            <button type="button" class="btn-action-icon" onclick="abrirModalGestionarPassword(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)" title="Gestionar Contraseña de Correo">
                                                <i class="bi bi-key-fill text-warning"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- ====================================================
     MODAL EXCLUSIVO: GESTIÓN DE CONTRASEÑA DE CORREO
     (Datos de colaborador en SOLO LECTURA, contraseña editable)
     ==================================================== -->
<div class="modal fade" id="modalPasswordCorreo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg" style="background: #0d1e36; color: #ffffff; border: 1px solid rgba(255,255,255,0.1) !important;">
            <div class="modal-header border-bottom border-white border-opacity-10 py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="kpi-icon kpi-pink" style="width: 38px; height: 38px; font-size: 1.1rem;">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0">Contraseña de Correo Institucional</h6>
                        <small class="text-secondary" id="modalPassSubtitulo">Administración exclusiva de credencial</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form method="POST" id="formPasswordCorreo" onsubmit="guardarPasswordCorreoAjax(event)">
                <input type="hidden" name="accion" value="guardar_contrasena_correo">
                <input type="hidden" name="user_id" id="modalPassUserId" value="0">
                <input type="hidden" name="sub_accion" id="modalPassSubAccion" value="guardar">

                <div class="modal-body p-4">
                    <!-- Ficha informativa en SOLO LECTURA -->
                    <div class="p-3 rounded-3 mb-3" style="background: rgba(6, 19, 37, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                        <div class="row g-2">
                            <div class="col-7">
                                <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Colaborador</div>
                                <div class="fw-bold text-white text-truncate" id="modalPassColaborador">--</div>
                                <div class="text-secondary small" id="modalPassPuesto">--</div>
                            </div>
                            <div class="col-5">
                                <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Área / Extensión</div>
                                <div class="text-info fw-semibold small" id="modalPassArea">--</div>
                                <div class="text-warning fw-bold small" id="modalPassExtension">--</div>
                            </div>
                            <div class="col-12 mt-2 pt-2 border-top border-white border-opacity-10">
                                <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Correo Institucional</div>
                                <div class="font-monospace text-success small" id="modalPassCorreo">--</div>
                            </div>
                        </div>
                    </div>

                    <!-- Campo ÚNICO Editable: Contraseña del Correo -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label text-secondary small fw-bold text-uppercase mb-0">Contraseña del Correo Institucional</label>
                            <button type="button" class="btn btn-link btn-sm text-pink p-0 text-decoration-none small" style="color: #f472b6;" onclick="generarPasswordAleatoria()">
                                <i class="bi bi-magic me-1"></i> Generar segura
                            </button>
                        </div>
                        <div class="input-group">
                            <input type="text" name="contrasena_correo" id="modalPassInput" class="form-control rounded-start-3 font-monospace text-warning fw-bold border-secondary border-opacity-25" style="background: #061325;" placeholder="Ingresa la contraseña..." autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary text-white border-secondary border-opacity-25" onclick="copiarAlPortapapeles(document.getElementById('modalPassInput').value, 'Contraseña copiada')" title="Copiar">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <div class="form-text text-secondary" style="font-size: 0.75rem;">
                            <i class="bi bi-info-circle me-1"></i> Esta es la contraseña para configurar Outlook, Webmail o el celular del colaborador.
                        </div>
                    </div>

                    <div class="alert alert-dark border-0 rounded-3 p-2.5 small text-secondary mb-0" style="background: rgba(255,255,255,0.03); font-size: 0.75rem;">
                        <i class="bi bi-shield-check text-info me-1"></i> Los datos de perfil (nombre, área, puesto y correo) se gestionan desde el módulo <strong>Gestión de Usuarios</strong>. En este módulo únicamente se administra su contraseña de correo.
                    </div>
                </div>
                <div class="modal-footer border-top border-white border-opacity-10 py-3 justify-content-between">
                    <button type="button" id="btnEliminarPass" class="btn btn-outline-danger btn-sm rounded-3 px-3" onclick="confirmarEliminarPassword()">
                        <i class="bi bi-trash me-1"></i> Eliminar Contraseña
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm text-white rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-pink btn-sm rounded-3 px-3 fw-bold text-white" style="background: #db2777; border-color: #db2777;">
                            <i class="bi bi-check2-circle me-1"></i> Guardar Contraseña
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====================================================
     MODAL: CORREO O EXTENSIÓN DEPARTAMENTAL / GENERAL
     (Para cuentas como Recepción, Facturación, Citas, etc. que no son un usuario)
     ==================================================== -->
<div class="modal fade" id="modalCuentaDept" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg" style="background: #0d1e36; color: #ffffff; border: 1px solid rgba(255,255,255,0.1) !important;">
            <div class="modal-header border-bottom border-white border-opacity-10 py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="kpi-icon kpi-blue" style="width: 38px; height: 38px; font-size: 1.1rem;">
                        <i class="bi bi-building"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="modalDeptTitulo">Correo o Extensión Departamental</h6>
                        <small class="text-secondary">Cuentas generales que no pertenecen a un usuario individual</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_cuenta_departamental">
                <input type="hidden" name="user_id" id="deptUserId" value="0">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Nombre del Buzón / Ubicación <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" id="deptNombre" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" required placeholder="Ej. Recepción, Citas de Servicio, Facturación, Caseta...">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Área / Departamento</label>
                            <input type="text" name="area" id="deptArea" list="listaAreasDept" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Ej. Ventas, Servicio...">
                            <datalist id="listaAreasDept">
                                <?php foreach ($areasDisponibles as $ar): ?>
                                    <option value="<?php echo htmlspecialchars($ar); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Descripción / Concepto</label>
                            <input type="text" name="puesto" id="deptPuesto" class="form-control rounded-3 border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Ej. Buzón General, Extensión Fija...">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Extensión Telefónica (Opcional)</label>
                            <div class="input-group">
                                <span class="input-group-text border-secondary border-opacity-25" style="background: #061325; color: #60a5fa;"><i class="bi bi-telephone-fill"></i></span>
                                <input type="text" name="extension" id="deptExtension" class="form-control font-monospace fw-bold border-secondary border-opacity-25 text-info" style="background: #061325;" placeholder="Ej. 1000, 1024...">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Correo General (Opcional)</label>
                            <div class="input-group">
                                <span class="input-group-text border-secondary border-opacity-25" style="background: #061325; color: #86efac;"><i class="bi bi-envelope-at-fill"></i></span>
                                <input type="email" name="email" id="deptEmail" class="form-control font-monospace border-secondary border-opacity-25 text-success" style="background: #061325;" placeholder="ejemplo@divolavilla.com">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Teléfono Directo / Celular (Opcional)</label>
                            <input type="text" name="telefono" id="deptTelefono" class="form-control border-secondary border-opacity-25" style="background: #061325; color: #ffffff;" placeholder="Ej. 55 1234 5678">
                        </div>

                        <div class="col-12">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Contraseña de Correo (Opcional)</label>
                            <div class="input-group">
                                <span class="input-group-text border-secondary border-opacity-25" style="background: #061325; color: #fde047;"><i class="bi bi-key-fill"></i></span>
                                <input type="text" name="contrasena_correo" id="deptContrasenaCorreo" class="form-control font-monospace text-warning fw-bold border-secondary border-opacity-25" style="background: #061325;" placeholder="Ingresar contraseña si tiene buzón activo">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-white border-opacity-10 py-3 justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm text-white rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-pink btn-sm rounded-3 px-4 fw-bold text-white" style="background: #db2777; border-color: #db2777;">
                        <i class="bi bi-check2-circle me-1"></i> Guardar Cuenta
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast Flotante de Notificaciones -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 999999;">
    <div id="toastNotif" class="toast align-items-center text-white border-0 rounded-4 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" style="background: #102442; border: 1px solid #db2777 !important;">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2" id="toastNotifMsg">
                <i class="bi bi-check-circle-fill text-success fs-5"></i> ¡Copiado con éxito!
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let areaFiltroActivo = 'TODAS';

    // 1. FILTRADO EN TIEMPO REAL (BUSCADOR + ÁREAS)
    function filtrarDirectorioEnVivo() {
        const query = document.getElementById('buscadorDirectorio').value.toLowerCase().trim();
        const filas = document.querySelectorAll('.directorio-row');
        let visibles = 0;

        filas.forEach(fila => {
            const nom = fila.getAttribute('data-nombre') || '';
            const ar = fila.getAttribute('data-area') || '';
            const em = fila.getAttribute('data-email') || '';
            const ex = fila.getAttribute('data-extension') || '';
            const pc = fila.getAttribute('data-pc') || '';

            const matchTexto = !query || nom.includes(query) || ar.includes(query) || em.includes(query) || ex.includes(query) || pc.includes(query);
            
            let matchArea = true;
            if (areaFiltroActivo !== 'TODAS') {
                if (areaFiltroActivo === 'SIN ÁREA') {
                    matchArea = (!ar || ar === 'sin área');
                } else {
                    matchArea = (ar === areaFiltroActivo.toLowerCase());
                }
            }

            if (matchTexto && matchArea) {
                fila.style.display = '';
                visibles++;
            } else {
                fila.style.display = 'none';
            }
        });

        // Manejo de fila sin resultados
        let sinResFila = document.getElementById('filaSinBusqueda');
        if (visibles === 0) {
            if (!sinResFila) {
                const tbody = document.getElementById('tbodyDirectorio');
                sinResFila = document.createElement('tr');
                sinResFila.id = 'filaSinBusqueda';
                sinResFila.innerHTML = '<td colspan="6" class="text-center py-5 text-secondary"><i class="bi bi-search fs-2 d-block mb-2 text-muted"></i>No se encontraron colaboradores con el criterio de búsqueda seleccionado.</td>';
                tbody.appendChild(sinResFila);
            }
            sinResFila.style.display = '';
        } else if (sinResFila) {
            sinResFila.style.display = 'none';
        }
    }

    function filtrarPorArea(areaNombre, elementoPill) {
        areaFiltroActivo = areaNombre;
        document.querySelectorAll('#contenedorPillsAreas .filter-pill').forEach(p => p.classList.remove('active'));
        if (elementoPill) elementoPill.classList.add('active');
        filtrarDirectorioEnVivo();
    }

    function limpiarBuscador() {
        const input = document.getElementById('buscadorDirectorio');
        input.value = '';
        filtrarDirectorioEnVivo();
        input.focus();
    }

    // 2. TOGGLE VISIBILIDAD DE CONTRASEÑA
    function togglePasswordVisibilidad(userId) {
        const span = document.getElementById('pass_masked_' + userId);
        const icon = document.getElementById('eye_icon_' + userId);
        if (!span || !icon) return;

        const pass = span.getAttribute('data-pass');
        if (span.classList.contains('pass-masked')) {
            span.classList.remove('pass-masked');
            span.textContent = pass;
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            span.classList.add('pass-masked');
            span.textContent = '••••••••';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }

    // 3. COPIAR AL PORTAPAPELES
    function copiarAlPortapapeles(texto, mensajeToast) {
        if (!texto) return;
        navigator.clipboard.writeText(texto).then(() => {
            mostrarToastNotif(mensajeToast || '¡Copiado al portapapeles!');
        }).catch(err => {
            const ta = document.createElement('textarea');
            ta.value = texto;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            mostrarToastNotif(mensajeToast || '¡Copiado al portapapeles!');
        });
    }

    function mostrarToastNotif(msg) {
        const el = document.getElementById('toastNotif');
        const txt = document.getElementById('toastNotifMsg');
        if (el && txt) {
            txt.innerHTML = '<i class="bi bi-check-circle-fill text-success fs-5"></i> ' + msg;
            const toast = new bootstrap.Toast(el, { delay: 2500 });
            toast.show();
        }
    }

    // 4. MODAL EXCLUSIVO PARA GESTIÓN DE PASSWORD DE CORREO
    function abrirModalGestionarPassword(contacto) {
        if (!contacto || !contacto.id) return;
        document.getElementById('modalPassUserId').value = contacto.id;
        document.getElementById('modalPassSubAccion').value = 'guardar';
        document.getElementById('modalPassColaborador').textContent = contacto.nombre || '--';
        document.getElementById('modalPassPuesto').textContent = contacto.puesto || '--';
        document.getElementById('modalPassArea').textContent = contacto.area || 'Sin Área';
        document.getElementById('modalPassExtension').textContent = contacto.extension ? ('Ext. ' + contacto.extension) : 'Sin Extensión';
        document.getElementById('modalPassCorreo').textContent = contacto.email || 'Sin correo asignado';
        document.getElementById('modalPassInput').value = contacto.contrasena_correo || '';

        const btnDel = document.getElementById('btnEliminarPass');
        if (btnDel) {
            btnDel.style.display = contacto.contrasena_correo ? 'inline-block' : 'none';
        }

        const modal = new bootstrap.Modal(document.getElementById('modalPasswordCorreo'));
        modal.show();
    }

    function generarPasswordAleatoria() {
        const caracteres = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
        let pass = 'H';
        for (let i = 0; i < 9; i++) {
            pass += caracteres.charAt(Math.floor(Math.random() * caracteres.length));
        }
        pass += '26!';
        document.getElementById('modalPassInput').value = pass;
    }

    function confirmarEliminarPassword() {
        if (confirm('¿Estás seguro de que deseas eliminar la contraseña de correo de este colaborador?')) {
            document.getElementById('modalPassSubAccion').value = 'eliminar';
            document.getElementById('modalPassInput').value = '';
            document.getElementById('formPasswordCorreo').requestSubmit();
        }
    }

    function guardarPasswordCorreoAjax(e) {
        e.preventDefault();
        const form = document.getElementById('formPasswordCorreo');
        const formData = new FormData(form);

        fetch('directorio.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const modalEl = document.getElementById('modalPasswordCorreo');
                const modalInst = bootstrap.Modal.getInstance(modalEl);
                if (modalInst) modalInst.hide();
                mostrarToastNotif(data.message || 'Contraseña actualizada');
                setTimeout(() => location.reload(), 700);
            } else {
                alert(data.message || 'Error al guardar');
            }
        })
        .catch(err => {
            form.submit();
        });
    }

    // 5. MODAL PARA CUENTAS GENERALES / DEPARTAMENTALES
    function abrirModalNuevaCuentaDept() {
        document.getElementById('modalDeptTitulo').textContent = 'Registrar Correo o Extensión Departamental';
        document.getElementById('deptUserId').value = '0';
        document.getElementById('deptNombre').value = '';
        document.getElementById('deptArea').value = '';
        document.getElementById('deptPuesto').value = 'Cuenta Departamental';
        document.getElementById('deptExtension').value = '';
        document.getElementById('deptEmail').value = '';
        document.getElementById('deptTelefono').value = '';
        document.getElementById('deptContrasenaCorreo').value = '';

        const modal = new bootstrap.Modal(document.getElementById('modalCuentaDept'));
        modal.show();
    }

    function abrirModalEditarCuentaDept(contacto) {
        document.getElementById('modalDeptTitulo').textContent = 'Editar Cuenta: ' + contacto.nombre;
        document.getElementById('deptUserId').value = contacto.id;
        document.getElementById('deptNombre').value = contacto.nombre || '';
        document.getElementById('deptArea').value = (contacto.area !== 'Sin Área') ? contacto.area : '';
        document.getElementById('deptPuesto').value = contacto.puesto || '';
        document.getElementById('deptExtension').value = contacto.extension || '';
        document.getElementById('deptEmail').value = contacto.email || '';
        document.getElementById('deptTelefono').value = contacto.telefono || '';
        document.getElementById('deptContrasenaCorreo').value = contacto.contrasena_correo || '';

        const modal = new bootstrap.Modal(document.getElementById('modalCuentaDept'));
        modal.show();
    }
</script>
</body>
</html>
