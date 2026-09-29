<?php
// Controller y Procesador de Acciones POST / AJAX para Infraestructura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../permisos_helper.php';

// Asegurar Sesión Activa
if (!isset($_SESSION['usuario_id'])) {
    $_SESSION['usuario_id'] = 1;
    $_SESSION['usuario'] = 'Admin';
    $_SESSION['nombre'] = 'Administrador Sistemas';
    $_SESSION['rol'] = 'SuperAdmin';
}

// Asegurar tablas de permisos e infraestructura
asegurarTablasPermisos($pdo);
asegurarTablasInfraestructura($pdo);

$seccion_activa = $_GET['sec'] ?? 'diagramas';
$subseccion_red = $_GET['sub'] ?? 'menu';
$mensaje = '';
$error = '';

// Definición de Secciones Principales
$SECCIONES = [
    'diagramas' => [
        'nombre' => 'Diagramas y Planos 2D Agencia',
        'icono' => 'bi-diagram-3-fill',
        'descripcion' => 'Diseña y mapea interactivamente en 2D el plano de la agencia con figuras geométricas ajustables desde el contorno, habitaciones irregulares con puntos de doblaje (L, T, ángulos) y posiciona equipos del inventario.'
    ],
    'site' => [
        'nombre' => 'SITE',
        'icono' => 'bi-hdd-rack-fill',
        'descripcion' => 'Fichas técnicas físicas y plano interactivo del SITE: climatización, UPS, Racks, planta eléctrica y seguridad.'
    ],
    'red' => [
        'nombre' => 'RED e IDFs',
        'icono' => 'bi-diagram-2-fill',
        'descripcion' => 'Gestión de IDFs / Gabinetes secundarios de distribución, mapas de VLANs, segmentos de red IP, switches troncales y cobertura.'
    ]
];

if (!array_key_exists($seccion_activa, $SECCIONES)) {
    $seccion_activa = 'diagramas';
}

$infoSeccion = $SECCIONES[$seccion_activa];

// MANEJO DE POST AJAX / NORMALES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar_plano_2d') {
        $plano_id = intval($_POST['plano_id'] ?? 0);
        $nombre_plano = trim($_POST['nombre_plano'] ?? 'Plano Agencia');
        $elementos_json = $_POST['elementos_json'] ?? '[]';
        $imagen_fondo_url = '';

        // Manejo de carga de imagen de fondo del plano
        if (isset($_FILES['imagen_fondo_file']) && $_FILES['imagen_fondo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['imagen_fondo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                $dirUploads = 'uploads/planos/';
                if (!is_dir($dirUploads)) mkdir($dirUploads, 0777, true);
                $targetPath = $dirUploads . 'plano_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($_FILES['imagen_fondo_file']['tmp_name'], $targetPath)) {
                    $imagen_fondo_url = $targetPath;
                }
            }
        }

        if ($plano_id > 0) {
            if (!empty($imagen_fondo_url)) {
                $stmt = $pdo->prepare("UPDATE infra_planos_2d SET nombre_plano = ?, imagen_fondo_url = ?, elementos_json = ? WHERE id = ?");
                $stmt->execute([$nombre_plano, $imagen_fondo_url, $elementos_json, $plano_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE infra_planos_2d SET nombre_plano = ?, elementos_json = ? WHERE id = ?");
                $stmt->execute([$nombre_plano, $elementos_json, $plano_id]);
            }
            $mensaje = 'Plano 2D guardado correctamente.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO infra_planos_2d (nombre_plano, imagen_fondo_url, elementos_json) VALUES (?, ?, ?)");
            $stmt->execute([$nombre_plano, $imagen_fondo_url, $elementos_json]);
            $mensaje = 'Nuevo Plano 2D creado exitosamente.';
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => $mensaje, 'plano_id' => $plano_id ?: $pdo->lastInsertId()]);
            exit();
        }
    } elseif ($accion === 'eliminar_plano_2d') {
        $plano_id = intval($_POST['plano_id'] ?? 0);
        if ($plano_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM infra_planos_2d WHERE id = ?");
            $stmt->execute([$plano_id]);
            $mensaje = 'Plano 2D eliminado exitosamente.';
            header("Location: infraestructura.php?sec=diagramas");
            exit();
        }
    } elseif ($accion === 'guardar_site') {
        $id = intval($_POST['id'] ?? 0);
        $nombre_site = trim($_POST['nombre_site'] ?? 'SITE Principal');
        $ubicacion = trim($_POST['ubicacion'] ?? '');
        $tipo_espacio = trim($_POST['tipo_espacio'] ?? 'SITE Principal');
        $temperatura_objetivo = trim($_POST['temperatura_objetivo'] ?? '');
        $aire_acondicionado = trim($_POST['aire_acondicionado'] ?? '');
        $btu = trim($_POST['btu'] ?? '');
        $aire_estatus = trim($_POST['aire_estatus'] ?? 'Operativo');
        $ups_principal = trim($_POST['ups_principal'] ?? '');
        $cap_ups = trim($_POST['cap_ups'] ?? '');
        $planta_luz = trim($_POST['planta_luz'] ?? '');
        $control_acceso = trim($_POST['control_acceso'] ?? '');
        $contra_incendio = trim($_POST['contra_incendio'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $foto_site = '';

        if (isset($_FILES['foto_site_file']) && $_FILES['foto_site_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_site_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $dirUploads = 'uploads/site/';
                if (!is_dir($dirUploads)) mkdir($dirUploads, 0777, true);
                $targetPath = $dirUploads . 'site_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($_FILES['foto_site_file']['tmp_name'], $targetPath)) {
                    $foto_site = $targetPath;
                }
            }
        }

        if ($id > 0) {
            if (!empty($foto_site)) {
                $stmt = $pdo->prepare("UPDATE infra_site SET nombre_site = ?, ubicacion = ?, tipo_espacio = ?, temperatura_objetivo = ?, aire_acondicionado = ?, btu = ?, aire_estatus = ?, ups_principal = ?, cap_ups = ?, planta_luz = ?, control_acceso = ?, contra_incendio = ?, foto_site = ?, observaciones = ? WHERE id = ?");
                $stmt->execute([$nombre_site, $ubicacion, $tipo_espacio, $temperatura_objetivo, $aire_acondicionado, $btu, $aire_estatus, $ups_principal, $cap_ups, $planta_luz, $control_acceso, $contra_incendio, $foto_site, $observaciones, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE infra_site SET nombre_site = ?, ubicacion = ?, tipo_espacio = ?, temperatura_objetivo = ?, aire_acondicionado = ?, btu = ?, aire_estatus = ?, ups_principal = ?, cap_ups = ?, planta_luz = ?, control_acceso = ?, contra_incendio = ?, observaciones = ? WHERE id = ?");
                $stmt->execute([$nombre_site, $ubicacion, $tipo_espacio, $temperatura_objetivo, $aire_acondicionado, $btu, $aire_estatus, $ups_principal, $cap_ups, $planta_luz, $control_acceso, $contra_incendio, $observaciones, $id]);
            }
            $mensaje = 'Ficha de SITE actualizada exitosamente.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO infra_site (nombre_site, ubicacion, tipo_espacio, temperatura_objetivo, aire_acondicionado, btu, aire_estatus, ups_principal, cap_ups, planta_luz, control_acceso, contra_incendio, foto_site, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre_site, $ubicacion, $tipo_espacio, $temperatura_objetivo, $aire_acondicionado, $btu, $aire_estatus, $ups_principal, $cap_ups, $planta_luz, $control_acceso, $contra_incendio, $foto_site, $observaciones]);
            $mensaje = 'Ficha de SITE registrada exitosamente.';
        }
    } elseif ($accion === 'guardar_site_layout') {
        $racks_json = $_POST['racks_json'] ?? '{}';
        $floorplan_json = $_POST['floorplan_json'] ?? '';

        $dataToSave = $racks_json;
        if (!empty($floorplan_json)) {
            $dataToSave = json_encode([
                'racks' => json_decode($racks_json, true),
                'floorplan' => json_decode($floorplan_json, true)
            ]);
        }

        $stmtCount = $pdo->query("SELECT COUNT(*) FROM infra_site");
        if ($stmtCount->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO infra_site (nombre_site, observaciones) VALUES ('SITE Principal', ?)");
            $stmt->execute([$dataToSave]);
        } else {
            $stmtGet = $pdo->query("SELECT id FROM infra_site ORDER BY id ASC LIMIT 1");
            $siteId = $stmtGet->fetchColumn();
            $stmt = $pdo->prepare("UPDATE infra_site SET observaciones = ? WHERE id = ?");
            $stmt->execute([$dataToSave, $siteId]);
        }
        $mensaje = 'Configuración del Layout SITE guardada exitosamente.';

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => $mensaje]);
            exit();
        }
    } elseif ($accion === 'guardar_red') {
        $id = intval($_POST['id'] ?? 0);
        $nombre_idf = trim($_POST['nombre_idf'] ?? '');
        $ubicacion = trim($_POST['ubicacion'] ?? '');
        $rango_ips = trim($_POST['rango_ips'] ?? '');
        $vlans = trim($_POST['vlans'] ?? '');
        $switch_principal = trim($_POST['switch_principal'] ?? '');
        $no_puertos = trim($_POST['no_puertos'] ?? '');
        $no_racks = trim($_POST['no_racks'] ?? '');
        $estatus = trim($_POST['estatus'] ?? 'Activo');
        $notas = trim($_POST['notas'] ?? '');
        $foto_idf = '';

        if (isset($_FILES['foto_idf_file']) && $_FILES['foto_idf_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_idf_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $dirUploads = 'uploads/idf/';
                if (!is_dir($dirUploads)) mkdir($dirUploads, 0777, true);
                $targetPath = $dirUploads . 'idf_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($_FILES['foto_idf_file']['tmp_name'], $targetPath)) {
                    $foto_idf = $targetPath;
                }
            }
        }

        if ($id > 0) {
            if (!empty($foto_idf)) {
                $stmt = $pdo->prepare("UPDATE infra_red_idf SET nombre_idf = ?, ubicacion = ?, rango_ips = ?, vlans = ?, switch_principal = ?, no_puertos = ?, no_racks = ?, estatus = ?, foto_idf = ?, notas = ? WHERE id = ?");
                $stmt->execute([$nombre_idf, $ubicacion, $rango_ips, $vlans, $switch_principal, $no_puertos, $no_racks, $estatus, $foto_idf, $notas, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE infra_red_idf SET nombre_idf = ?, ubicacion = ?, rango_ips = ?, vlans = ?, switch_principal = ?, no_puertos = ?, no_racks = ?, estatus = ?, notas = ? WHERE id = ?");
                $stmt->execute([$nombre_idf, $ubicacion, $rango_ips, $vlans, $switch_principal, $no_puertos, $no_racks, $estatus, $notas, $id]);
            }
            $mensaje = 'IDF / Registro de Red actualizado exitosamente.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO infra_red_idf (nombre_idf, ubicacion, rango_ips, vlans, switch_principal, no_puertos, no_racks, estatus, foto_idf, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre_idf, $ubicacion, $rango_ips, $vlans, $switch_principal, $no_puertos, $no_racks, $estatus, $foto_idf, $notas]);
            $mensaje = 'IDF / Registro de Red guardado exitosamente.';
        }
    } elseif ($accion === 'guardar_nodo') {
        $id = intval($_POST['id'] ?? 0);
        $codigo_nodo = trim($_POST['codigo_nodo'] ?? '');
        $tipo_nodo = trim($_POST['tipo_nodo'] ?? 'Voz y Datos');
        $ubicacion = trim($_POST['ubicacion'] ?? '');
        $patch_panel = trim($_POST['patch_panel'] ?? '');
        $switch_puerto = trim($_POST['switch_puerto'] ?? '');
        $vlan = trim($_POST['vlan'] ?? '');
        $estatus = trim($_POST['estatus'] ?? 'Activo');
        $notas = trim($_POST['notas'] ?? '');
        $tiene_telefono_poe = intval($_POST['tiene_telefono_poe'] ?? 0);
        $telefono_poe_id = intval($_POST['telefono_poe_id'] ?? 0);
        $telefono_poe_info = '';

        if ($tiene_telefono_poe && $telefono_poe_id > 0) {
            try {
                $stTel = $pdo->prepare("SELECT id, extension, usuario, modelo, ip FROM inv_telefonos_poe WHERE id = ?");
                $stTel->execute([$telefono_poe_id]);
                $rTel = $stTel->fetch(PDO::FETCH_ASSOC);
                if ($rTel) {
                    $extVal = trim($rTel['extension'] ?? '');
                    $telefono_poe_info = !empty($extVal) ? ('Ext. ' . $extVal) : '';
                    // Sincronizar hacia inv_telefonos_poe el nodo y switch_puerto
                    $sqlUpTel = "UPDATE inv_telefonos_poe SET numero_nodo = ?";
                    $paramsUpTel = [$codigo_nodo];
                    if (!empty($switch_puerto)) {
                        $sqlUpTel .= ", puerto_sw = ?";
                        $paramsUpTel[] = $switch_puerto;
                        if (strpos($switch_puerto, '/') !== false) {
                            $pParts = explode('/', $switch_puerto);
                            $sqlUpTel .= ", switch_nombre = ?";
                            $paramsUpTel[] = trim($pParts[0]);
                        }
                    }
                    $sqlUpTel .= " WHERE id = ?";
                    $paramsUpTel[] = $telefono_poe_id;
                    $pdo->prepare($sqlUpTel)->execute($paramsUpTel);
                }
            } catch (Throwable $tTel) {}
        }

        $oldCodigoNodo = '';
        if ($id > 0) {
            $stmtOldNd = $pdo->prepare("SELECT codigo_nodo FROM infra_nodos WHERE id = ?");
            $stmtOldNd->execute([$id]);
            $oldCodigoNodo = $stmtOldNd->fetchColumn() ?: '';

            $stmt = $pdo->prepare("UPDATE infra_nodos SET codigo_nodo = ?, tipo_nodo = ?, ubicacion = ?, patch_panel = ?, switch_puerto = ?, vlan = ?, estatus = ?, notas = ?, tiene_telefono_poe = ?, telefono_poe_id = ?, telefono_poe_info = ? WHERE id = ?");
            $stmt->execute([$codigo_nodo, $tipo_nodo, $ubicacion, $patch_panel, $switch_puerto, $vlan, $estatus, $notas, $tiene_telefono_poe, $telefono_poe_id, $telefono_poe_info, $id]);
            $mensaje = 'Nodo de Red actualizado exitosamente.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO infra_nodos (codigo_nodo, tipo_nodo, ubicacion, patch_panel, switch_puerto, vlan, estatus, notas, tiene_telefono_poe, telefono_poe_id, telefono_poe_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$codigo_nodo, $tipo_nodo, $ubicacion, $patch_panel, $switch_puerto, $vlan, $estatus, $notas, $tiene_telefono_poe, $telefono_poe_id, $telefono_poe_info]);
            $mensaje = 'Nodo de Red guardado exitosamente.';
        }

        // =========================================================================
        // SINCRONIZACIÓN AUTOMÁTICA EN TIEMPO REAL: NODOS -> SWITCHES & INVENTARIO
        // =========================================================================
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS infra_switch_puertos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                switch_key TEXT NOT NULL,
                switch_nombre TEXT NOT NULL,
                puerto_numero INTEGER NOT NULL,
                equipo_key TEXT,
                equipo_nombre TEXT,
                equipo_tipo TEXT,
                equipo_ip TEXT,
                nodo_codigo TEXT,
                vlan TEXT,
                estatus TEXT DEFAULT 'activo',
                notas TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $swNombre = '';
            $puertoNum = 0;
            if (!empty($switch_puerto)) {
                if (strpos($switch_puerto, '/') !== false) {
                    $pParts = explode('/', $switch_puerto);
                    $swNombre = trim($pParts[0]);
                    $puertoNum = intval(preg_replace('/[^0-9]/', '', $pParts[1] ?? ''));
                } else {
                    $puertoNum = intval(preg_replace('/[^0-9]/', '', $switch_puerto));
                    $swNombre = trim(preg_replace('/puerto\s*[0-9]+/i', '', $switch_puerto));
                }
            }

            // 1. Limpiar este nodo de otros puertos de switch si cambió
            if (!empty($oldCodigoNodo)) {
                $pdo->prepare("UPDATE infra_switch_puertos SET nodo_codigo = '' WHERE nodo_codigo = ?")->execute([$oldCodigoNodo]);
            }
            $pdo->prepare("UPDATE infra_switch_puertos SET nodo_codigo = '' WHERE nodo_codigo = ?")->execute([$codigo_nodo]);

            // 2. Asociar al nuevo puerto de switch si se especificó
            if (!empty($swNombre) && $puertoNum > 0) {
                $stmtSwExist = $pdo->prepare("SELECT id FROM infra_switch_puertos WHERE (switch_nombre = ? OR switch_key = ?) AND puerto_numero = ?");
                $stmtSwExist->execute([$swNombre, 'SW-' . $swNombre, $puertoNum]);
                $swExistId = $stmtSwExist->fetchColumn();

                if ($swExistId) {
                    $pdo->prepare("UPDATE infra_switch_puertos SET nodo_codigo = ?, vlan = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                        ->execute([$codigo_nodo, $vlan, $swExistId]);
                } else {
                    $pdo->prepare("INSERT INTO infra_switch_puertos (switch_key, switch_nombre, puerto_numero, nodo_codigo, vlan, estatus, updated_at) VALUES (?, ?, ?, ?, ?, 'activo', CURRENT_TIMESTAMP)")
                        ->execute(['SW-' . $swNombre, $swNombre, $puertoNum, $codigo_nodo, $vlan]);
                }
            }

            // 3. Sincronizar hacia todas las tablas de Inventario que usan nodos
            $tablasInventario = ['inv_equipos_vw', 'inv_equipos_corp', 'inv_monitores', 'inv_equipos_baja', 'inv_telefonos_poe'];
            $codigosBuscar = array_unique(array_filter([$codigo_nodo, $oldCodigoNodo]));

            foreach ($tablasInventario as $tblInv) {
                try {
                    foreach ($codigosBuscar as $cBusq) {
                        $sqlUpInv = "UPDATE `$tblInv` SET numero_nodo = ?";
                        $paramsUpInv = [$codigo_nodo];
                        if (!empty($switch_puerto)) {
                            $sqlUpInv .= ", puerto_sw = ?";
                            $paramsUpInv[] = $switch_puerto;
                        }
                        if (!empty($patch_panel)) {
                            $sqlUpInv .= ", puerto_patch_panel = ?";
                            $paramsUpInv[] = $patch_panel;
                        }
                        $sqlUpInv .= " WHERE numero_nodo = ?";
                        $paramsUpInv[] = $cBusq;
                        $pdo->prepare($sqlUpInv)->execute($paramsUpInv);
                    }
                } catch (Throwable $tTbl) {}
            }
        } catch (Throwable $tSyncNd) {}

        $subseccion_red = 'nodos';
    } elseif ($accion === 'guardar_vlan') {
        $id = intval($_POST['id'] ?? 0);
        $vlan_id = intval($_POST['vlan_id'] ?? 0);
        $nombre_vlan = trim($_POST['nombre_vlan'] ?? '');
        $subred = trim($_POST['subred'] ?? '');
        $gateway = trim($_POST['gateway'] ?? '');
        $dhcp_rango = trim($_POST['dhcp_rango'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $estatus = trim($_POST['estatus'] ?? 'Activa');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE infra_vlans SET vlan_id = ?, nombre_vlan = ?, subred = ?, gateway = ?, dhcp_rango = ?, descripcion = ?, estatus = ? WHERE id = ?");
            $stmt->execute([$vlan_id, $nombre_vlan, $subred, $gateway, $dhcp_rango, $descripcion, $estatus, $id]);
            $mensaje = 'VLAN actualizada exitosamente.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO infra_vlans (vlan_id, nombre_vlan, subred, gateway, dhcp_rango, descripcion, estatus) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vlan_id, $nombre_vlan, $subred, $gateway, $dhcp_rango, $descripcion, $estatus]);
            $mensaje = 'VLAN registrada exitosamente.';
        }
        $subseccion_red = 'vlans';
    } elseif ($accion === 'eliminar_registro') {
        $id = intval($_POST['registro_id'] ?? 0);
        $tipo_tabla = $_POST['tipo_tabla'] ?? '';
        $tabla = 'infra_site';
        if ($seccion_activa === 'red') {
            if ($tipo_tabla === 'nodo') {
                $tabla = 'infra_nodos';
                $subseccion_red = 'nodos';
            } elseif ($tipo_tabla === 'vlan') {
                $tabla = 'infra_vlans';
                $subseccion_red = 'vlans';
            } else {
                $tabla = 'infra_red_idf';
            }
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM {$tabla} WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = 'Registro eliminado exitosamente.';
        }
    } elseif ($accion === 'obtener_expediente_usuario') {
        header('Content-Type: application/json');
        $usuario_str = trim($_POST['usuario'] ?? '');
        $u = null;
        if (!empty($usuario_str)) {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE LOWER(usuario) = LOWER(?) OR LOWER(nombre) LIKE LOWER(?) LIMIT 1");
            $stmt->execute([$usuario_str, "%$usuario_str%"]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        echo json_encode([
            'status' => 'success',
            'found' => ($u !== null && $u !== false),
            'usuario' => $u ?: null
        ]);
        exit();
    } elseif ($accion === 'guardar_puerto_switch') {
        header('Content-Type: application/json');
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS infra_switch_puertos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                switch_key TEXT NOT NULL,
                switch_nombre TEXT NOT NULL,
                puerto_numero INTEGER NOT NULL,
                equipo_key TEXT,
                equipo_nombre TEXT,
                equipo_tipo TEXT,
                equipo_ip TEXT,
                nodo_codigo TEXT,
                vlan TEXT,
                estatus TEXT DEFAULT 'activo',
                notas TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $switchKey = trim($_POST['switch_key'] ?? '');
            $switchNombre = trim($_POST['switch_nombre'] ?? '');
            $puertoNum = intval($_POST['puerto_numero'] ?? 0);
            $tipoOperacion = trim($_POST['tipo_operacion'] ?? 'asignar');
            $equipoKey = trim($_POST['equipo_key'] ?? '');
            $equipoNombre = trim($_POST['equipo_nombre'] ?? '');
            $equipoTipo = trim($_POST['equipo_tipo'] ?? '');
            $equipoIp = trim($_POST['equipo_ip'] ?? '');
            $nodoCodigo = trim($_POST['nodo_codigo'] ?? '');
            $vlan = trim($_POST['vlan'] ?? '');
            $notas = trim($_POST['notas'] ?? '');
            $tieneTelefonoPoe = intval($_POST['tiene_telefono_poe'] ?? 0);
            $telefonoKey = trim($_POST['telefono_key'] ?? '');

            if ($puertoNum <= 0 || empty($switchNombre)) {
                echo json_encode(['status' => 'error', 'message' => 'Switch y Puerto inválidos']);
                exit();
            }

            // Obtener asignación actual si existe
            $stmtCur = $pdo->prepare("SELECT * FROM infra_switch_puertos WHERE (switch_nombre = ? OR switch_key = ?) AND puerto_numero = ?");
            $stmtCur->execute([$switchNombre, $switchKey, $puertoNum]);
            $currentAssigned = $stmtCur->fetch(PDO::FETCH_ASSOC);

            $oldKey = $currentAssigned['equipo_key'] ?? '';
            $oldNodo = $currentAssigned['nodo_codigo'] ?? '';
            $oldTelKey = $currentAssigned['telefono_poe_key'] ?? '';
            $tablasInvEq = ['inv_equipos_vw', 'inv_equipos_corp', 'inv_monitores', 'inv_equipos_baja'];

            if (!empty($oldTelKey) && ($tipoOperacion === 'liberar' || $oldTelKey !== $telefonoKey)) {
                if (strpos($oldTelKey, 'TEL-') === 0) {
                    $oldTelId = intval(substr($oldTelKey, 4));
                    try {
                        $pdo->prepare("UPDATE inv_telefonos_poe SET puerto_sw = '', switch_nombre = '' WHERE id = ?")->execute([$oldTelId]);
                    } catch (Throwable $t) {}
                }
            }

            if (!empty($oldKey) && ($tipoOperacion === 'liberar' || $oldKey !== $equipoKey)) {
                if (strpos($oldKey, 'EQ-') === 0) {
                    $eqId = intval(substr($oldKey, 3));
                    foreach ($tablasInvEq as $tbl) {
                        try {
                            $pdo->prepare("UPDATE `$tbl` SET puerto_sw = '', switch_nombre = '' WHERE id = ?")->execute([$eqId]);
                        } catch (Throwable $t) {}
                    }
                } elseif (strpos($oldKey, 'PRN-') === 0) {
                    $prnId = intval(substr($oldKey, 4));
                    try {
                        $pdo->prepare("UPDATE inv_monitores SET puerto_sw = '', switch_nombre = '' WHERE id = ?")->execute([$prnId]);
                    } catch (Throwable $t) {}
                } elseif (strpos($oldKey, 'SITE-') === 0) {
                    $siteId = intval(substr($oldKey, 5));
                    try {
                        $pdo->prepare("UPDATE inv_site_vw SET puerto_sw = '', switch_nombre = '' WHERE id = ?")->execute([$siteId]);
                    } catch (Throwable $t) {}
                } elseif (strpos($oldKey, 'TEL-') === 0) {
                    $telId = intval(substr($oldKey, 4));
                    try {
                        $pdo->prepare("UPDATE inv_telefonos_poe SET puerto_sw = '', switch_nombre = '' WHERE id = ?")->execute([$telId]);
                    } catch (Throwable $t) {}
                }
            }

            if ($tipoOperacion === 'liberar' && !empty($oldNodo)) {
                try {
                    $pdo->prepare("UPDATE infra_nodos SET switch_puerto = '', estatus = 'Disponible' WHERE codigo_nodo = ?")->execute([$oldNodo]);
                } catch (Throwable $t) {}
            }

            $pdo->prepare("DELETE FROM infra_switch_puertos WHERE (switch_nombre = ? OR switch_key = ?) AND puerto_numero = ?")
                ->execute([$switchNombre, $switchKey, $puertoNum]);

            if ($tipoOperacion === 'asignar') {
                // 1. Validar que la IP (si fue ingresada) no pertenezca a otro equipo del inventario
                if (!empty($equipoIp) && !in_array(strtoupper($equipoIp), ['SIN IP', '0.0.0.0', 'DHCP', 'DINAMICA', 'DINÁMICA', 'N/A', '--'])) {
                    $tablasIpCheck = [
                        'inv_equipos_vw' => 'EQ-',
                        'inv_equipos_corp' => 'CORP-',
                        'inv_site_vw' => 'SITE-',
                        'inv_monitores' => 'PRN-',
                        'inv_telefonos_poe' => 'TEL-'
                    ];
                    foreach ($tablasIpCheck as $tbl => $pfx) {
                        try {
                            $colCond = ($tbl === 'inv_site_vw') ? "(LOWER(TRIM(ip)) = ? OR LOWER(TRIM(ip_local)) = ?)" : "LOWER(TRIM(ip)) = ?";
                            $paramsIp = ($tbl === 'inv_site_vw') ? [strtolower($equipoIp), strtolower($equipoIp)] : [strtolower($equipoIp)];
                            $sCheck = $pdo->prepare("SELECT id, nombre_equipo FROM `$tbl` WHERE $colCond");
                            $sCheck->execute($paramsIp);
                            $matchedRow = $sCheck->fetch(PDO::FETCH_ASSOC);
                            if ($matchedRow) {
                                $matchedKey = $pfx . $matchedRow['id'];
                                if ($matchedKey !== $equipoKey) {
                                    $eqNomOtro = $matchedRow['nombre_equipo'] ?: ($tbl . ' #' . $matchedRow['id']);
                                    echo json_encode(['status' => 'error', 'message' => "La dirección IP '{$equipoIp}' ya está asignada al equipo '{$eqNomOtro}'. No se permiten direcciones IP duplicadas."]);
                                    exit();
                                }
                            }
                        } catch (Throwable $t) {}
                    }
                }

                // Si equipoIp viene vacío pero el equipo ya tenía IP en el inventario, conservarla
                if (empty($equipoIp) && !empty($equipoKey)) {
                    if (strpos($equipoKey, 'EQ-') === 0) {
                        $eqId = intval(substr($equipoKey, 3));
                        foreach ($tablasInvEq as $tbl) {
                            try {
                                $sIp = $pdo->prepare("SELECT ip FROM `$tbl` WHERE id = ?");
                                $sIp->execute([$eqId]);
                                $fIp = $sIp->fetchColumn();
                                if (!empty($fIp)) { $equipoIp = $fIp; break; }
                            } catch (Throwable $t) {}
                        }
                    } elseif (strpos($equipoKey, 'SITE-') === 0) {
                        $siteId = intval(substr($equipoKey, 5));
                        try {
                            $sIp = $pdo->prepare("SELECT ip, ip_local FROM inv_site_vw WHERE id = ?");
                            $sIp->execute([$siteId]);
                            $rSite = $sIp->fetch(PDO::FETCH_ASSOC);
                            $fIp = !empty($rSite['ip']) ? $rSite['ip'] : ($rSite['ip_local'] ?? '');
                            if (!empty($fIp)) $equipoIp = $fIp;
                        } catch (Throwable $t) {}
                    } elseif (strpos($equipoKey, 'PRN-') === 0) {
                        $prnId = intval(substr($equipoKey, 4));
                        try {
                            $sIp = $pdo->prepare("SELECT ip FROM inv_monitores WHERE id = ?");
                            $sIp->execute([$prnId]);
                            $fIp = $sIp->fetchColumn();
                            if (!empty($fIp)) $equipoIp = $fIp;
                        } catch (Throwable $t) {}
                    }
                }

                // 2. Garantizar exclusividad: Desvincular este equipo de cualquier OTRO puerto
                if (!empty($equipoKey)) {
                    $stmtPrevPorts = $pdo->prepare("SELECT switch_nombre, puerto_numero FROM infra_switch_puertos WHERE equipo_key = ? AND (switch_nombre != ? OR puerto_numero != ?)");
                    $stmtPrevPorts->execute([$equipoKey, $switchNombre, $puertoNum]);
                    $prevPorts = $stmtPrevPorts->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($prevPorts as $pp) {
                        $pdo->prepare("DELETE FROM infra_switch_puertos WHERE switch_nombre = ? AND puerto_numero = ?")->execute([$pp['switch_nombre'], $pp['puerto_numero']]);
                    }
                }

                // 3. Garantizar exclusividad: Desvincular este nodo de cualquier OTRO puerto
                if (!empty($nodoCodigo)) {
                    $pdo->prepare("UPDATE infra_switch_puertos SET nodo_codigo = '' WHERE nodo_codigo = ? AND (switch_nombre != ? OR puerto_numero != ?)")
                        ->execute([$nodoCodigo, $switchNombre, $puertoNum]);
                }

                $telCascadaNombre = '';
                $telCascadaIp = '';
                $telCascadaExt = '';
                $telCascadaId = 0;
                if ($tieneTelefonoPoe && !empty($telefonoKey) && strpos($telefonoKey, 'TEL-') === 0) {
                    $telCascadaId = intval(substr($telefonoKey, 4));
                    try {
                        $stTelQ = $pdo->prepare("SELECT id, extension, usuario, modelo, ip FROM inv_telefonos_poe WHERE id = ?");
                        $stTelQ->execute([$telCascadaId]);
                        $rowTelQ = $stTelQ->fetch(PDO::FETCH_ASSOC);
                        if ($rowTelQ) {
                            $telCascadaExt = $rowTelQ['extension'] ?: '';
                            $telCascadaIp = $rowTelQ['ip'] ?: '';
                            $telCascadaNombre = 'Ext. ' . ($telCascadaExt ?: 'S/E') . ' (' . ($rowTelQ['usuario'] ?: ($rowTelQ['modelo'] ?: 'Teléfono')) . ')';
                        }
                    } catch (Throwable $tTelQ) {}
                }

                $stmtIns = $pdo->prepare("INSERT INTO infra_switch_puertos 
                    (switch_key, switch_nombre, puerto_numero, equipo_key, equipo_nombre, equipo_tipo, equipo_ip, nodo_codigo, vlan, estatus, notas, tiene_telefono_poe, telefono_poe_key, telefono_poe_nombre, telefono_poe_ip, telefono_poe_ext, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                $stmtIns->execute([$switchKey, $switchNombre, $puertoNum, $equipoKey, $equipoNombre, $equipoTipo, $equipoIp, $nodoCodigo, $vlan, $notas, $tieneTelefonoPoe, $telefonoKey, $telCascadaNombre, $telCascadaIp, $telCascadaExt]);

                $puertoFormatted = $switchNombre . " / Puerto " . $puertoNum;

                // Sincronizar teléfono cascada hacia inv_telefonos_poe
                if ($telCascadaId > 0) {
                    try {
                        $sqlUpTelC = "UPDATE inv_telefonos_poe SET puerto_sw = ?, switch_nombre = ?";
                        $paramsUpTelC = [$puertoFormatted, $switchNombre];
                        if (!empty($nodoCodigo)) {
                            $sqlUpTelC .= ", numero_nodo = ?";
                            $paramsUpTelC[] = $nodoCodigo;
                        }
                        $sqlUpTelC .= " WHERE id = ?";
                        $paramsUpTelC[] = $telCascadaId;
                        $pdo->prepare($sqlUpTelC)->execute($paramsUpTelC);
                    } catch (Throwable $tTelUp) {}
                }

                // 4. Sincronizar hacia el inventario (Puerto, Switch, Nodo e IP)
                if (!empty($equipoKey)) {
                    if (strpos($equipoKey, 'EQ-') === 0) {
                        $eqId = intval(substr($equipoKey, 3));
                        foreach ($tablasInvEq as $tbl) {
                            try {
                                $sqlUp = "UPDATE `$tbl` SET puerto_sw = ?, switch_nombre = ?";
                                $paramsUp = [$puertoFormatted, $switchNombre];
                                if (!empty($nodoCodigo)) {
                                    $sqlUp .= ", numero_nodo = ?";
                                    $paramsUp[] = $nodoCodigo;
                                }
                                if (!empty($equipoIp)) {
                                    $sqlUp .= ", ip = ?";
                                    $paramsUp[] = $equipoIp;
                                }
                                $sqlUp .= " WHERE id = ?";
                                $paramsUp[] = $eqId;
                                $pdo->prepare($sqlUp)->execute($paramsUp);
                            } catch (Throwable $t) {}
                        }
                    } elseif (strpos($equipoKey, 'PRN-') === 0) {
                        $prnId = intval(substr($equipoKey, 4));
                        try {
                            $sqlUp = "UPDATE inv_monitores SET puerto_sw = ?, switch_nombre = ?";
                            $paramsUp = [$puertoFormatted, $switchNombre];
                            if (!empty($nodoCodigo)) {
                                $sqlUp .= ", numero_nodo = ?";
                                $paramsUp[] = $nodoCodigo;
                            }
                            if (!empty($equipoIp)) {
                                $sqlUp .= ", ip = ?";
                                $paramsUp[] = $equipoIp;
                            }
                            $sqlUp .= " WHERE id = ?";
                            $paramsUp[] = $prnId;
                            $pdo->prepare($sqlUp)->execute($paramsUp);
                        } catch (Throwable $t) {}
                    } elseif (strpos($equipoKey, 'SITE-') === 0) {
                        $siteId = intval(substr($equipoKey, 5));
                        try {
                            $sqlUp = "UPDATE inv_site_vw SET puerto_sw = ?, switch_nombre = ?";
                            $paramsUp = [$puertoFormatted, $switchNombre];
                            if (!empty($nodoCodigo)) {
                                $sqlUp .= ", numero_nodo = ?";
                                $paramsUp[] = $nodoCodigo;
                            }
                            if (!empty($equipoIp)) {
                                $sqlUp .= ", ip = ?";
                                $paramsUp[] = $equipoIp;
                            }
                            $sqlUp .= " WHERE id = ?";
                            $paramsUp[] = $siteId;
                            $pdo->prepare($sqlUp)->execute($paramsUp);
                        } catch (Throwable $t) {}
                    } elseif (strpos($equipoKey, 'TEL-') === 0) {
                        $telId = intval(substr($equipoKey, 4));
                        try {
                            $sqlUp = "UPDATE inv_telefonos_poe SET puerto_sw = ?, switch_nombre = ?";
                            $paramsUp = [$puertoFormatted, $switchNombre];
                            if (!empty($nodoCodigo)) {
                                $sqlUp .= ", numero_nodo = ?";
                                $paramsUp[] = $nodoCodigo;
                            }
                            if (!empty($equipoIp)) {
                                $sqlUp .= ", ip = ?";
                                $paramsUp[] = $equipoIp;
                            }
                            $sqlUp .= " WHERE id = ?";
                            $paramsUp[] = $telId;
                            $pdo->prepare($sqlUp)->execute($paramsUp);
                        } catch (Throwable $t) {}
                    }
                }

                // 2. Sincronizar hacia infra_nodos
                if (!empty($nodoCodigo)) {
                    try {
                        $stmtCheckNd = $pdo->prepare("SELECT id FROM infra_nodos WHERE codigo_nodo = ? LIMIT 1");
                        $stmtCheckNd->execute([$nodoCodigo]);
                        $ndId = $stmtCheckNd->fetchColumn();

                        if ($ndId) {
                            $pdo->prepare("UPDATE infra_nodos SET switch_puerto = ?, vlan = ?, estatus = 'Activo' WHERE id = ?")
                                ->execute([$puertoFormatted, $vlan, $ndId]);
                        } else {
                            $pdo->prepare("INSERT INTO infra_nodos (codigo_nodo, tipo_nodo, ubicacion, switch_puerto, vlan, estatus, notas) VALUES (?, 'Voz y Datos', 'SITE / Conectado', ?, ?, 'Activo', ?)")
                                ->execute([$nodoCodigo, $puertoFormatted, $vlan, 'Auto-creado desde Switch 2D: ' . $equipoNombre]);
                        }
                    } catch (Throwable $t) {}
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => ($tipoOperacion === 'liberar' ? "Puerto {$puertoNum} liberado correctamente." : "Puerto {$puertoNum} enlazado a {$equipoNombre}.")
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit();
    } elseif ($accion === 'obtener_puertos_switch') {
        header('Content-Type: application/json');
        try {
            $switchNombre = trim($_POST['switch_nombre'] ?? '');
            $switchKey = trim($_POST['switch_key'] ?? '');

            $pdo->exec("CREATE TABLE IF NOT EXISTS infra_switch_puertos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                switch_key TEXT NOT NULL,
                switch_nombre TEXT NOT NULL,
                puerto_numero INTEGER NOT NULL,
                equipo_key TEXT,
                equipo_nombre TEXT,
                equipo_tipo TEXT,
                equipo_ip TEXT,
                nodo_codigo TEXT,
                vlan TEXT,
                estatus TEXT DEFAULT 'activo',
                notas TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $stmtP = $pdo->prepare("SELECT * FROM infra_switch_puertos WHERE switch_nombre = ? OR switch_key = ? ORDER BY puerto_numero ASC");
            $stmtP->execute([$switchNombre, $switchKey]);
            $puertos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            $indexed = [];
            foreach ($puertos as $p) {
                $indexed[intval($p['puerto_numero'])] = $p;
            }

            // Mapeo sincronizado desde inv_equipos_vw
            try {
                $stmtEq = $pdo->query("SELECT id, nombre_equipo, tipo_equipo, ip, puerto_sw, switch_nombre FROM inv_equipos_vw WHERE (puerto_sw IS NOT NULL AND puerto_sw != '')");
                if ($stmtEq) {
                    foreach ($stmtEq->fetchAll(PDO::FETCH_ASSOC) as $eqRow) {
                        $swMatch = empty($eqRow['switch_nombre']) || stripos($eqRow['switch_nombre'], $switchNombre) !== false || stripos($switchNombre, $eqRow['switch_nombre']) !== false;
                        if ($swMatch && preg_match('/(?:puerto\s*)?(\d+)/i', $eqRow['puerto_sw'], $m)) {
                            $pNum = intval($m[1]);
                            if (!isset($indexed[$pNum])) {
                                $indexed[$pNum] = [
                                    'puerto_numero' => $pNum,
                                    'equipo_key' => 'EQ-' . $eqRow['id'],
                                    'equipo_nombre' => $eqRow['nombre_equipo'] ?: ('Equipo #' . $eqRow['id']),
                                    'equipo_tipo' => $eqRow['tipo_equipo'] ?: 'Workstation',
                                    'equipo_ip' => $eqRow['ip'] ?: '',
                                    'nodo_codigo' => '',
                                    'vlan' => '',
                                    'estatus' => 'activo',
                                    'notas' => 'Vía inventario de equipos'
                                ];
                            }
                        }
                    }
                }
            } catch (Throwable $tEqSync) {}

            echo json_encode([
                'status' => 'success',
                'switch' => $switchNombre,
                'puertos' => array_values($indexed)
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] !== 'obtener_expediente_usuario') {
        $redirSec = $_GET['sec'] ?? $seccion_activa;
        $redirSub = $_GET['sub'] ?? $subseccion_red;
        $redirectUrl = "infraestructura.php?sec=" . urlencode($redirSec);
        if (!empty($redirSub) && $redirSec === 'red') {
            $redirectUrl .= "&sub=" . urlencode($redirSub);
        }
        if (!empty($mensaje)) {
            $redirectUrl .= "&msg=" . urlencode($mensaje);
        }
        header("Location: " . $redirectUrl);
        exit();
    }
}

// OBTENER PLANOS 2D REGISTRADOS
$planos2D = [];
try {
    $stmt = $pdo->query("SELECT * FROM infra_planos_2d ORDER BY id ASC");
    $planos2D = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$planoSeleccionadoId = intval($_GET['plano_id'] ?? ($planos2D[0]['id'] ?? 0));
$planoActual = null;
foreach ($planos2D as $p) {
    if ($p['id'] == $planoSeleccionadoId) {
        $planoActual = $p;
        break;
    }
}
if (!$planoActual && !empty($planos2D)) {
    $planoActual = $planos2D[0];
}

// CARGA COMPLETA DE EQUIPOS DE INVENTARIO PARA VINCULACIÓN EN PLANO Y FICHA TÉCNICA
$equiposInventario = [];
try {
    // 1. Cargar equipos del inventario SITE (inv_site_vw: Switches, Firewalls, NAS, Servidores, UPS, etc.)
    $stmtSiteInv = $pdo->query("SELECT * FROM inv_site_vw ORDER BY id ASC");
    if ($stmtSiteInv) {
        $rawSite = $stmtSiteInv->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawSite as $r) {
            $key = 'SITE-' . $r['id'];
            $tipo = !empty($r['tipo_registro']) ? $r['tipo_registro'] : (!empty($r['equipo']) ? $r['equipo'] : 'Equipo SITE');
            $label = !empty($r['nombre_equipo']) ? $r['nombre_equipo'] : (!empty($r['equipo']) ? $r['equipo'] : ($tipo . ' #' . $r['id']));
            $sub = trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''));
            $equiposInventario[$key] = [
                'id' => $r['id'],
                'key' => $key,
                'origen' => 'inv_site_vw',
                'label' => $label,
                'sub' => $sub ?: 'SITE Core',
                'tipo' => $tipo,
                'ip' => !empty($r['ip']) ? $r['ip'] : (!empty($r['ip_local']) ? $r['ip_local'] : 'Sin IP'),
                'mac' => !empty($r['mac_ethernet']) ? $r['mac_ethernet'] : (!empty($r['mac_wifi']) ? $r['mac_wifi'] : 'Sin MAC'),
                'serie' => !empty($r['serie']) ? $r['serie'] : 'Sin Serie',
                'estatus' => !empty($r['estado']) ? $r['estado'] : 'Operativo',
                'usuario' => !empty($r['usuario']) ? $r['usuario'] : 'Sistemas',
                'departamento' => !empty($r['departamento']) ? $r['departamento'] : 'Sistemas',
                'ubicacion' => !empty($r['ubicacion']) ? $r['ubicacion'] : 'SITE Principal',
                'nodo' => $r['numero_nodo'] ?? '',
                'foto_url' => !empty($r['foto_equipo']) ? $r['foto_equipo'] : '',
                'raw' => $r
            ];
        }
    }
} catch (Throwable $e) {}

try {
    // 2. Cargar equipos de computo / workstations (inv_equipos_vw)
    $stmtEqVw = $pdo->query("SELECT * FROM inv_equipos_vw ORDER BY id ASC");
    if ($stmtEqVw) {
        $rawEq = $stmtEqVw->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawEq as $r) {
            $key = 'EQ-' . $r['id'];
            $tipo = !empty($r['tipo_equipo']) ? $r['tipo_equipo'] : 'Desktop/Laptop';
            $label = !empty($r['nombre_equipo']) ? $r['nombre_equipo'] : ($tipo . ' #' . $r['id']);
            $sub = trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''));
            $equiposInventario[$key] = [
                'id' => $r['id'],
                'key' => $key,
                'origen' => 'inv_equipos_vw',
                'label' => $label,
                'sub' => $sub ?: 'Equipo Usuario',
                'tipo' => $tipo,
                'ip' => !empty($r['ip']) ? $r['ip'] : 'Sin IP',
                'mac' => !empty($r['direccion_mac']) ? $r['direccion_mac'] : (!empty($r['mac_ethernet']) ? $r['mac_ethernet'] : 'Sin MAC'),
                'serie' => !empty($r['serie']) ? $r['serie'] : 'Sin Serie',
                'estatus' => !empty($r['estado']) ? $r['estado'] : 'Activo',
                'usuario' => !empty($r['usuario']) ? $r['usuario'] : 'No asignado',
                'departamento' => !empty($r['departamento']) ? $r['departamento'] : '',
                'ubicacion' => !empty($r['ubicacion']) ? $r['ubicacion'] : 'Oficinas',
                'nodo' => $r['numero_nodo'] ?? '',
                'foto_url' => !empty($r['foto_equipo']) ? $r['foto_equipo'] : '',
                'raw' => $r
            ];
        }
    }
} catch (Throwable $e) {}

try {
    // 2b. Cargar equipos corporativo (inv_equipos_corp)
    $stmtCorp = $pdo->query("SELECT * FROM inv_equipos_corp ORDER BY id ASC");
    if ($stmtCorp) {
        $rawCorp = $stmtCorp->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawCorp as $r) {
            $key = 'CORP-' . $r['id'];
            $tipo = !empty($r['tipo_equipo']) ? $r['tipo_equipo'] : 'Desktop/Laptop Corp';
            $label = !empty($r['nombre_equipo']) ? $r['nombre_equipo'] : ($tipo . ' #' . $r['id']);
            $sub = trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''));
            $equiposInventario[$key] = [
                'id' => $r['id'],
                'key' => $key,
                'origen' => 'inv_equipos_corp',
                'label' => $label,
                'sub' => $sub ?: 'Equipo Corporativo',
                'tipo' => $tipo,
                'ip' => !empty($r['ip']) ? $r['ip'] : 'Sin IP',
                'mac' => !empty($r['direccion_mac']) ? $r['direccion_mac'] : (!empty($r['mac_ethernet']) ? $r['mac_ethernet'] : 'Sin MAC'),
                'serie' => !empty($r['serie']) ? $r['serie'] : 'Sin Serie',
                'estatus' => !empty($r['estado']) ? $r['estado'] : 'Activo',
                'usuario' => !empty($r['usuario']) ? $r['usuario'] : 'No asignado',
                'departamento' => !empty($r['departamento']) ? $r['departamento'] : '',
                'ubicacion' => !empty($r['ubicacion']) ? $r['ubicacion'] : 'Corporativo',
                'nodo' => $r['numero_nodo'] ?? '',
                'foto_url' => !empty($r['foto_equipo']) ? $r['foto_equipo'] : '',
                'raw' => $r
            ];
        }
    }
} catch (Throwable $e) {}

try {
    // 3. Impresoras (inv_monitores)
    $stmtMon = $pdo->query("SELECT * FROM inv_monitores ORDER BY id ASC");
    if ($stmtMon) {
        $rawMon = $stmtMon->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawMon as $r) {
            $key = 'PRN-' . $r['id'];
            $label = trim(($r['marca'] ?? '') . ' ' . ($r['modelo_exacto'] ?? 'Impresora'));
            if (empty($label)) $label = 'Impresora #' . $r['id'];
            $equiposInventario[$key] = [
                'id' => $r['id'],
                'key' => $key,
                'origen' => 'inv_monitores',
                'label' => $label,
                'sub' => $r['departamento'] ?: 'Impresora',
                'tipo' => 'Impresora',
                'ip' => !empty($r['ip']) ? $r['ip'] : 'Sin IP',
                'mac' => 'Sin MAC',
                'serie' => !empty($r['serie']) ? $r['serie'] : 'Sin Serie',
                'estatus' => 'Activo',
                'usuario' => $r['usuario_impresora'] ?: 'Compartida',
                'departamento' => $r['departamento'] ?: '',
                'ubicacion' => $r['ubicacion'] ?: 'Oficinas',
                'nodo' => $r['numero_nodo'] ?? '',
                'foto_url' => '',
                'raw' => $r
            ];
        }
    }
} catch (Throwable $e) {}

try {
    // 4. Teléfonos PoE (inv_telefonos_poe)
    $stmtTel = $pdo->query("SELECT * FROM inv_telefonos_poe ORDER BY id ASC");
    if ($stmtTel) {
        $rawTel = $stmtTel->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawTel as $r) {
            $key = 'TEL-' . $r['id'];
            $ext = !empty($r['extension']) ? ('Ext. ' . $r['extension']) : ('Teléfono #' . $r['id']);
            $mod = trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''));
            $label = $ext . ($mod ? " ($mod)" : '');
            $equiposInventario[$key] = [
                'id' => $r['id'],
                'key' => $key,
                'origen' => 'inv_telefonos_poe',
                'label' => $label,
                'sub' => !empty($r['usuario']) ? ('Asignado a: ' . $r['usuario']) : ($r['departamento'] ?: 'Teléfono PoE'),
                'tipo' => 'Teléfono PoE',
                'ip' => !empty($r['ip']) ? $r['ip'] : 'Sin IP',
                'mac' => !empty($r['mac']) ? $r['mac'] : 'Sin MAC',
                'serie' => !empty($r['serie']) ? $r['serie'] : 'Sin Serie',
                'estatus' => !empty($r['estado']) ? $r['estado'] : 'Activo',
                'usuario' => !empty($r['usuario']) ? $r['usuario'] : 'No asignado',
                'departamento' => !empty($r['departamento']) ? $r['departamento'] : '',
                'ubicacion' => !empty($r['ubicacion']) ? $r['ubicacion'] : 'Oficinas',
                'nodo' => $r['numero_nodo'] ?? '',
                'foto_url' => !empty($r['foto_equipo']) ? $r['foto_equipo'] : '',
                'raw' => $r
            ];
        }
    }
} catch (Throwable $e) {}

try {
    // 3. Tabla equipos tradicional si existiera en cPanel MySQL
    $stmtEq = $pdo->query("
        SELECT 
            e.id, e.folio_responsiva, e.tipo_equipo, e.marca, e.modelo, e.numero_serie, 
            e.hostname, e.ip, e.mac_address, e.estatus, e.observaciones, e.usuario_responsable, e.foto_url,
            u.nombre as usuario_nombre, u.area as usuario_area, u.puesto as usuario_puesto, 
            u.telefono as usuario_telefono, u.email as usuario_email, u.foto_url as usuario_foto_url
        FROM equipos e
        LEFT JOIN usuarios u ON LOWER(e.usuario_responsable) = LOWER(u.usuario) OR LOWER(e.usuario_responsable) = LOWER(u.nombre)
        ORDER BY e.id ASC
    ");
    if ($stmtEq) {
        $rawEquipos = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawEquipos as $r) {
            $key = 'EQ-' . $r['id'];
            if (!isset($equiposInventario[$key])) {
                $equiposInventario[$key] = [
                    'id' => $r['id'],
                    'key' => $key,
                    'origen' => 'equipos',
                    'label' => (!empty($r['hostname']) ? $r['hostname'] : $r['tipo_equipo'] . ' #' . $r['id']),
                    'sub' => ($r['marca'] . ' ' . $r['modelo']),
                    'tipo' => $r['tipo_equipo'],
                    'ip' => $r['ip'] ?: 'Sin IP',
                    'mac' => $r['mac_address'] ?: 'Sin MAC',
                    'serie' => $r['numero_serie'] ?: 'Sin Serie',
                    'estatus' => $r['estatus'],
                    'usuario' => $r['usuario_responsable'] ?: 'No asignado',
                    'departamento' => $r['usuario_area'] ?: '',
                    'foto_url' => $r['foto_url'] ?: '',
                    'raw' => $r
                ];
            }
        }
    }
} catch (Throwable $e) {}

// REGISTROS PARA LAS DEMÁS SECCIONES
$registros = [];
$registrosNodos = [];
$registrosVlans = [];
$registrosSwitches = [];
$telefonosPoeDisponibles = [];
$agenciaData = [];

try {
    $stmtTelPoe = $pdo->query("SELECT id, extension, usuario, departamento, area, modelo, serie, mac, ip, numero_nodo, puerto_sw, switch_nombre FROM inv_telefonos_poe ORDER BY extension ASC, id ASC");
    if ($stmtTelPoe) {
        $telefonosPoeDisponibles = $stmtTelPoe->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $tTel) {}

try {
    $stmtAgencia = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
    if ($stmtAgencia) {
        $agenciaData = $stmtAgencia->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $stmtSiteInfo = $pdo->query("SELECT * FROM infra_site ORDER BY id DESC LIMIT 1");
    if ($stmtSiteInfo) {
        $siteData = $stmtSiteInfo->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if ($seccion_activa === 'site') {
        $stmt = $pdo->query("SELECT * FROM infra_site ORDER BY id DESC");
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($seccion_activa === 'red') {
        $stmt = $pdo->query("SELECT * FROM infra_red_idf ORDER BY id DESC");
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            $stmtNodos = $pdo->query("SELECT n.*, 
                   COALESCE(NULLIF(t1.extension, ''), NULLIF(t2.extension, ''), '') AS telefono_extension
            FROM infra_nodos n
            LEFT JOIN inv_telefonos_poe t1 ON (n.telefono_poe_id = t1.id AND n.tiene_telefono_poe = 1)
            LEFT JOIN inv_telefonos_poe t2 ON (n.codigo_nodo = t2.numero_nodo AND t2.numero_nodo != '')
            GROUP BY n.id
            ORDER BY CAST(n.codigo_nodo AS INTEGER) ASC, n.codigo_nodo ASC");
            $registrosNodos = $stmtNodos ? $stmtNodos->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $eN) {
            $stmtNodos = $pdo->query("SELECT * FROM infra_nodos ORDER BY id DESC");
            $registrosNodos = $stmtNodos ? $stmtNodos->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        $stmtVlans = $pdo->query("SELECT * FROM infra_vlans ORDER BY vlan_id ASC");
        $registrosVlans = $stmtVlans ? $stmtVlans->fetchAll(PDO::FETCH_ASSOC) : [];

        // Extraer switches de red desde inventario y registros de IDF
        if (!empty($equiposInventario)) {
            foreach ($equiposInventario as $eq) {
                $rawTipo = strtolower($eq['tipo'] ?? '');
                $rawLabel = strtolower($eq['label'] ?? '');
                $rawSub = strtolower($eq['sub'] ?? '');
                if (strpos($rawTipo, 'switch') !== false || strpos($rawLabel, 'switch') !== false || strpos($rawSub, 'switch') !== false || strpos($rawTipo, 'sw') !== false) {
                    $numPuertos = 48; // default
                    if (!empty($eq['raw']['cantidad_puertos']) && intval($eq['raw']['cantidad_puertos']) > 0) {
                        $numPuertos = intval($eq['raw']['cantidad_puertos']);
                    } elseif (preg_match('/\b(8|12|16|24|48|52)\b/i', $eq['sub'] . ' ' . $eq['label'] . ' ' . ($eq['raw']['modelo'] ?? ''), $m)) {
                        $numPuertos = intval($m[1]);
                    }
                    $eq['cantidad_puertos'] = $numPuertos;
                    $eq['puertos'] = $numPuertos;
                    $registrosSwitches[] = $eq;
                }
            }
        }
        if (!empty($registros)) {
            foreach ($registros as $idf) {
                if (!empty($idf['switch_principal'])) {
                    $existe = false;
                    foreach ($registrosSwitches as $swItem) {
                        if (stripos($swItem['label'], $idf['switch_principal']) !== false || stripos($idf['switch_principal'], $swItem['label']) !== false) {
                            $existe = true;
                            break;
                        }
                    }
                    if (!$existe) {
                        $pCount = !empty($idf['no_puertos']) ? intval($idf['no_puertos']) : 24;
                        $registrosSwitches[] = [
                            'key' => 'SW-IDF-' . $idf['id'],
                            'id' => $idf['id'],
                            'origen' => 'infra_red_idf',
                            'label' => $idf['switch_principal'],
                            'tipo' => 'Switch Distribución IDF',
                            'sub' => $idf['nombre_idf'],
                            'ip' => $idf['rango_ips'] ?: 'Sin IP',
                            'mac' => 'Sin MAC',
                            'serie' => 'N/A',
                            'estatus' => $idf['estatus'] ?: 'Activo',
                            'usuario' => 'Infraestructura TI',
                            'departamento' => $idf['ubicacion'] ?: 'IDF',
                            'ubicacion' => $idf['ubicacion'] ?: 'Gabinete IDF',
                            'cantidad_puertos' => $pCount,
                            'puertos' => $pCount,
                            'raw' => $idf
                        ];
                    }
                }
            }
        }
    }
} catch (Throwable $t) {}

if (isset($_GET['msg']) && empty($mensaje)) {
    $mensaje = trim($_GET['msg']);
}
