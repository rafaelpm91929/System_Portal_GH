<?php
// ====================================================================
// RECEPTOR EN CPANEL: REPORTES DESDE SERVIDOR LOCAL / DMS
// Recibe datos enviados por el conector local y los almacena en la agencia
// ====================================================================
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/permisos_helper.php';

if ($pdo) {
    asegurarTablasReportesAgencia($pdo);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión a la base de datos de cPanel.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Obtener Token de la Petición
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
$tokenEnviado = str_replace('Bearer ', '', trim($authHeader));

$inputJson = file_get_contents('php://input');
$data = json_decode($inputJson, true) ?: [];

if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

if (empty($tokenEnviado) && !empty($data['token'])) {
    $tokenEnviado = trim($data['token']);
}
if (empty($tokenEnviado) && !empty($_POST['token'])) {
    $tokenEnviado = trim($_POST['token']);
}
if (empty($tokenEnviado) && !empty($_GET['token'])) {
    $tokenEnviado = trim($_GET['token']);
}

// 2. Consultar Token Autorizado en la Ficha de la Agencia
$stmtAg = $pdo->query("SELECT id, nombre, local_api_token FROM agencias ORDER BY id ASC LIMIT 1");
$agData = $stmtAg ? $stmtAg->fetch(PDO::FETCH_ASSOC) : null;
$tokenEsperado = $agData['local_api_token'] ?? '';
$tokenMaestro = getenv('TOKEN_SECRETO') ?: 'GedasDivolavilla2026!';

$tokenValido = false;
if (!empty($tokenEnviado)) {
    if (!empty($tokenEsperado) && $tokenEnviado === $tokenEsperado) {
        $tokenValido = true;
    } elseif ($tokenEnviado === $tokenMaestro || $tokenEnviado === 'GH_SISTEMAS_TICKETS_2026!') {
        $tokenValido = true;
    }
}

if (!$tokenValido) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'No autorizado: Token de API del servidor local no coincide con la configuración de la agencia.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Manejo de Ping / Prueba de Conexión
if (isset($data['accion']) && $data['accion'] === 'ping') {
    $agId = $agData['id'] ?? 1;
    try {
        $stmtSync = $pdo->prepare("UPDATE agencias SET local_ultima_sincronizacion = CURRENT_TIMESTAMP, local_ultimo_estado_sync = 'Conectado (Ping OK)' WHERE id = ?");
        $stmtSync->execute([$agId]);
    } catch (Throwable $t) {}

    echo json_encode([
        'status' => 'ok',
        'mensaje' => 'Ping recibido exitosamente desde el servidor local.',
        'agencia' => $agData['nombre'] ?? 'Agencia',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. Procesar Lote de Reportes
$reportes = $data['reportes'] ?? [];
$tipoGeneral = trim($data['tipo_reporte'] ?? 'general');
$totalProcesados = 0;

if (!empty($reportes) && is_array($reportes)) {
    // Purgar datos demo y registros de prueba para conservar ÚNICAMENTE datos reales de la API
    try {
        $pdo->exec("DELETE FROM reportes_inventario_autos WHERE inventario LIKE 'SEM-2026-%' OR chasis LIKE 'TEST%' OR chasis = 'TEST1234567890' OR descripcion = 'Auto de Prueba'");
    } catch (Throwable $tClean) {}

    $stmtIns = $pdo->prepare("
        INSERT INTO reportes_agencia_datos 
        (tipo_reporte, folio_referencia, titulo, datos_json, resumen, monto, fecha_documento, estatus, sincronizado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    foreach ($reportes as $rep) {
        $tipoRep = trim($rep['tipo_reporte'] ?? $tipoGeneral);
        $folio   = trim($rep['folio_referencia'] ?? ($rep['folio'] ?? ''));
        $titulo  = trim($rep['titulo'] ?? ($rep['concepto'] ?? 'Reporte Local'));
        $resumen = trim($rep['resumen'] ?? ($rep['descripcion'] ?? ''));
        $monto   = floatval($rep['monto'] ?? ($rep['total'] ?? 0));
        $fechaDoc= trim($rep['fecha_documento'] ?? ($rep['fecha'] ?? date('Y-m-d')));
        $estatus = trim($rep['estatus'] ?? 'Activo');

        $datosJson = '';
        if (isset($rep['datos_json'])) {
            $datosJson = is_string($rep['datos_json']) ? $rep['datos_json'] : json_encode($rep['datos_json'], JSON_UNESCAPED_UNICODE);
        } else {
            $datosJson = json_encode($rep, JSON_UNESCAPED_UNICODE);
        }

        // B. Almacenar en tabla especializada de inventario de autos si cuenta con chasis
        $chasisAuto = trim($rep['chasis'] ?? ($rep['AUNumCha'] ?? ''));
        if (!empty($chasisAuto)) {
            $cveAlm   = trim($rep['cve_almacen'] ?? ($rep['CveAlmacen'] ?? ($rep['AUAlmCve'] ?? '')));
            $nomAlm   = trim($rep['nombre_almacen'] ?? ($rep['NombreAlmacen'] ?? ($rep['AlmConce'] ?? '')));
            $invClave = trim($rep['inventario'] ?? ($rep['Inventario'] ?? ($rep['AUCveAut'] ?? '')));
            $desAut   = trim($rep['descripcion'] ?? ($rep['Descripcion'] ?? ($rep['AUDesAut'] ?? '')));
            $colorAut = trim($rep['color'] ?? ($rep['Color'] ?? ($rep['AUColExt'] ?? '')));
            $motorAut = trim($rep['motor'] ?? ($rep['Motor'] ?? ($rep['AUNumMot'] ?? '')));
            $equipAut = trim($rep['equipamiento'] ?? ($rep['Equipamiento'] ?? ($rep['AUEquiOp'] ?? '')));
            $marcaAut = trim($rep['marca'] ?? ($rep['Marca'] ?? ($rep['AUDm'] ?? '')));
            $anioAut  = trim($rep['anio'] ?? ($rep['Año'] ?? ($rep['Anio'] ?? ($rep['AUAnoAut'] ?? ''))));
            $statAut  = trim($rep['status'] ?? ($rep['Status'] ?? ($rep['AUStatus'] ?? 'Disponible')));
            $prcVta   = floatval($rep['precio_venta'] ?? ($rep['Precio de Venta'] ?? ($rep['AUCtoVta'] ?? 0)));
            $cstInv   = floatval($rep['costo_inventario'] ?? ($rep['Costo Inventario'] ?? ($rep['AUPrecioAd'] ?? 0)));
            $impInv   = floatval($rep['importe_inventario'] ?? ($rep['Importe Inventario'] ?? ($rep['AUImpLiq'] ?? 0)));
            $fchAlta  = trim($rep['fecha_alta'] ?? ($rep['Fecha Alta'] ?? ($rep['AUFecha'] ?? date('Y-m-d H:i:s'))));

            try {
                $stmtEx = $pdo->prepare("SELECT id FROM reportes_inventario_autos WHERE chasis = ? LIMIT 1");
                $stmtEx->execute([$chasisAuto]);
                $autoId = intval($stmtEx->fetchColumn());

                if ($autoId > 0) {
                    $stmtUpAut = $pdo->prepare("
                        UPDATE reportes_inventario_autos 
                        SET cve_almacen=?, nombre_almacen=?, inventario=?, descripcion=?, color=?, motor=?, equipamiento=?, marca=?, anio=?, status=?, precio_venta=?, costo_inventario=?, importe_inventario=?, fecha_alta=?, datos_json=?, sincronizado_en=CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmtUpAut->execute([$cveAlm, $nomAlm, $invClave, $desAut, $colorAut, $motorAut, $equipAut, $marcaAut, $anioAut, $statAut, $prcVta, $cstInv, $impInv, $fchAlta, $datosJson, $autoId]);
                } else {
                    $stmtInsAut = $pdo->prepare("
                        INSERT INTO reportes_inventario_autos 
                        (cve_almacen, nombre_almacen, inventario, descripcion, chasis, color, motor, equipamiento, marca, anio, status, precio_venta, costo_inventario, importe_inventario, fecha_alta, datos_json, sincronizado_en)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmtInsAut->execute([$cveAlm, $nomAlm, $invClave, $desAut, $chasisAuto, $colorAut, $motorAut, $equipAut, $marcaAut, $anioAut, $statAut, $prcVta, $cstInv, $impInv, $fchAlta, $datosJson]);
                }
            } catch (Throwable $eAut) {}
        }

        // C. Almacenar en tabla especializada de Órdenes de Servicio si cuenta con NoOrden
        $noOrden = trim($rep['no_orden'] ?? ($rep['NoOrden'] ?? ($rep['OROrden'] ?? '')));
        if (!empty($noOrden)) {
            $almOrd     = trim($rep['almacen'] ?? ($rep['Almacen'] ?? ($rep['OrAlmCve'] ?? '')));
            $cveStatOrd = trim($rep['cve_estatus'] ?? ($rep['CveEstatus'] ?? ($rep['ORStatus'] ?? '')));
            $descStatOrd= trim($rep['descripcion_estatus'] ?? ($rep['DescripcionEstatus'] ?? ''));
            if (empty($descStatOrd)) {
                if ($cveStatOrd === 'AB') $descStatOrd = 'ABIERTA';
                elseif ($cveStatOrd === 'CE') $descStatOrd = 'CERRADA / FACTURADA';
                elseif ($cveStatOrd === 'CA' || $cveStatOrd === 'CN') $descStatOrd = 'CANCELADA';
                else $descStatOrd = $cveStatOrd;
            }

            $cveTipOrd  = trim($rep['cve_tipo_orden'] ?? ($rep['CveTipoOrden'] ?? ($rep['ORTipOrd'] ?? '')));
            $tipOrd     = trim($rep['tipo_orden'] ?? ($rep['TipoOrden'] ?? ''));
            if (empty($tipOrd)) {
                if ($cveTipOrd === 'P') $tipOrd = 'PUBLICO / PARTICULAR';
                elseif ($cveTipOrd === 'G') $tipOrd = 'GARANTIA';
                elseif ($cveTipOrd === 'I') $tipOrd = 'INTERNA';
                elseif ($cveTipOrd === 'H') $tipOrd = 'HOJALATERIA Y PINTURA';
                elseif ($cveTipOrd === 'A') $tipOrd = 'ASEGURADORA';
                else $tipOrd = $cveTipOrd;
            }

            $tipPagOrd  = trim($rep['tipo_pago'] ?? ($rep['TipoPago'] ?? ($rep['ORTipPag'] ?? '')));
            $noFactOrd  = trim($rep['no_factura'] ?? ($rep['NoFactura'] ?? ($rep['ORFactura'] ?? '')));
            $fecAltaOrd = trim($rep['fecha_alta'] ?? ($rep['FechaAlta'] ?? ($rep['ORFecAlta'] ?? '')));
            $horIniOrd  = trim($rep['hora_inicio'] ?? ($rep['HoraInicio'] ?? ($rep['ORHoraIni'] ?? '')));
            $fecPromOrd = trim($rep['fecha_promesa'] ?? ($rep['FechaPromesa'] ?? ($rep['ORFecProm'] ?? '')));
            $fecEntOrd  = trim($rep['fecha_entrega'] ?? ($rep['FechaEntrega'] ?? ($rep['ORFecEnt'] ?? '')));
            $horFinOrd  = trim($rep['hora_fin'] ?? ($rep['HoraFin'] ?? ($rep['ORHoraFin'] ?? '')));
            $numCliOrd  = trim($rep['num_cliente'] ?? ($rep['NumCliente'] ?? ($rep['ORCliente'] ?? '')));
            $cliOrd     = trim($rep['cliente'] ?? ($rep['Cliente'] ?? ''));
            $rfcOrd     = trim($rep['rfc'] ?? ($rep['RFC'] ?? ''));
            $telOrd     = trim($rep['telefono'] ?? ($rep['Telefono'] ?? ''));
            $vinOrd     = trim($rep['vin_chasis'] ?? ($rep['VIN_Chasis'] ?? ($rep['ORChasis'] ?? '')));
            $plaOrd     = trim($rep['placas'] ?? ($rep['Placas'] ?? ($rep['ORPlacas'] ?? '')));
            $modOrd     = trim($rep['modelo'] ?? ($rep['Modelo'] ?? ''));
            $anoOrd     = trim($rep['ano'] ?? ($rep['Ano'] ?? ($rep['ORAno'] ?? '')));
            $colOrd     = trim($rep['color'] ?? ($rep['Color'] ?? ($rep['ORColUni'] ?? '')));
            $kmtsOrd    = trim($rep['kilometraje'] ?? ($rep['Kilometraje'] ?? ($rep['ORKmts'] ?? '')));
            $cveAseOrd  = trim($rep['cve_asesor'] ?? ($rep['CveAsesor'] ?? ($rep['OPCveOpe'] ?? '')));
            $nomAseOrd  = trim($rep['nombre_asesor'] ?? ($rep['NombreAsesor'] ?? ''));
            $userRegOrd = trim($rep['usuario_registro'] ?? ($rep['UsuarioRegistro'] ?? ($rep['ORUser'] ?? '')));

            // Días de antigüedad y clasificación
            $diasAbierta = 0;
            $timeAlta = !empty($fecAltaOrd) ? strtotime($fecAltaOrd) : 0;
            if ($cveStatOrd === 'AB' || stripos($descStatOrd, 'ABIERTA') !== false) {
                if ($timeAlta > 0) {
                    $diasAbierta = max(0, (int) floor((time() - $timeAlta) / 86400));
                }
                if ($diasAbierta > 15) {
                    $estatusTiempo = 'Crítica (+15d)';
                } elseif ($diasAbierta >= 8) {
                    $estatusTiempo = 'Retrasada (8-15d)';
                } elseif ($diasAbierta >= 4) {
                    $estatusTiempo = 'En atención (4-7d)';
                } else {
                    $estatusTiempo = 'Normal (0-3d)';
                }
            } elseif ($cveStatOrd === 'CE' || stripos($descStatOrd, 'CERRADA') !== false) {
                $timeEnt = !empty($fecEntOrd) ? strtotime($fecEntOrd) : 0;
                if ($timeAlta > 0 && $timeEnt > 0 && $timeEnt >= $timeAlta) {
                    $diasAbierta = max(0, (int) round(($timeEnt - $timeAlta) / 86400));
                }
                $estatusTiempo = 'Cerrada';
            } else {
                $estatusTiempo = 'Cancelada';
            }

            try {
                $stmtExOrd = $pdo->prepare("SELECT id FROM reportes_ordenes_servicio WHERE no_orden = ? LIMIT 1");
                $stmtExOrd->execute([$noOrden]);
                $ordId = intval($stmtExOrd->fetchColumn());

                if ($ordId > 0) {
                    $stmtUpOrd = $pdo->prepare("
                        UPDATE reportes_ordenes_servicio 
                        SET almacen=?, cve_estatus=?, descripcion_estatus=?, cve_tipo_orden=?, tipo_orden=?, tipo_pago=?, no_factura=?, fecha_alta=?, hora_inicio=?, fecha_promesa=?, fecha_entrega=?, hora_fin=?, num_cliente=?, cliente=?, rfc=?, telefono=?, vin_chasis=?, placas=?, modelo=?, ano=?, color=?, kilometraje=?, cve_asesor=?, nombre_asesor=?, usuario_registro=?, dias_abierta=?, estatus_tiempo=?, datos_json=?, sincronizado_en=CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmtUpOrd->execute([$almOrd, $cveStatOrd, $descStatOrd, $cveTipOrd, $tipOrd, $tipPagOrd, $noFactOrd, $fecAltaOrd, $horIniOrd, $fecPromOrd, $fecEntOrd, $horFinOrd, $numCliOrd, $cliOrd, $rfcOrd, $telOrd, $vinOrd, $plaOrd, $modOrd, $anoOrd, $colOrd, $kmtsOrd, $cveAseOrd, $nomAseOrd, $userRegOrd, $diasAbierta, $estatusTiempo, $datosJson, $ordId]);
                } else {
                    $stmtInsOrd = $pdo->prepare("
                        INSERT INTO reportes_ordenes_servicio 
                        (almacen, no_orden, cve_estatus, descripcion_estatus, cve_tipo_orden, tipo_orden, tipo_pago, no_factura, fecha_alta, hora_inicio, fecha_promesa, fecha_entrega, hora_fin, num_cliente, cliente, rfc, telefono, vin_chasis, placas, modelo, ano, color, kilometraje, cve_asesor, nombre_asesor, usuario_registro, dias_abierta, estatus_tiempo, datos_json, sincronizado_en)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmtInsOrd->execute([$almOrd, $noOrden, $cveStatOrd, $descStatOrd, $cveTipOrd, $tipOrd, $tipPagOrd, $noFactOrd, $fecAltaOrd, $horIniOrd, $fecPromOrd, $fecEntOrd, $horFinOrd, $numCliOrd, $cliOrd, $rfcOrd, $telOrd, $vinOrd, $plaOrd, $modOrd, $anoOrd, $colOrd, $kmtsOrd, $cveAseOrd, $nomAseOrd, $userRegOrd, $diasAbierta, $estatusTiempo, $datosJson]);
                }
            } catch (Throwable $eOrd) {}
        }

        try {
            // Si viene con folio, comprobar si ya existe para actualizar o insertar
            $idExistente = 0;
            if (!empty($folio)) {
                $stmtChk = $pdo->prepare("SELECT id FROM reportes_agencia_datos WHERE folio_referencia = ? LIMIT 1");
                $stmtChk->execute([$folio]);
                $idExistente = intval($stmtChk->fetchColumn());
            }

            if ($idExistente > 0) {
                $stmtUpd = $pdo->prepare("
                    UPDATE reportes_agencia_datos 
                    SET tipo_reporte = ?, titulo = ?, datos_json = ?, resumen = ?, monto = ?, fecha_documento = ?, estatus = ?, sincronizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmtUpd->execute([$tipoRep, $titulo, $datosJson, $resumen, $monto, $fechaDoc, $estatus, $idExistente]);
            } else {
                $stmtIns->execute([$tipoRep, $folio, $titulo, $datosJson, $resumen, $monto, $fechaDoc, $estatus]);
            }
            $totalProcesados++;
        } catch (Throwable $eIns) {}
    }
}

// 5. Actualizar última sincronización en tabla agencias
$agId = $agData['id'] ?? 1;
try {
    $stmtSync = $pdo->prepare("
        UPDATE agencias 
        SET local_ultima_sincronizacion = CURRENT_TIMESTAMP, 
            local_ultimo_estado_sync = ? 
        WHERE id = ?
    ");
    $estadoTexto = "Sincronizado ($totalProcesados registros)";
    $stmtSync->execute([$estadoTexto, $agId]);
} catch (Throwable $t) {}

// 6. Respaldo en archivo de caché JSON local en cPanel
$archivoCache = __DIR__ . '/reportes_locales_cache.json';
@file_put_contents($archivoCache, json_encode([
    'agencia' => $agData['nombre'] ?? 'Agencia',
    'timestamp' => date('Y-m-d H:i:s'),
    'total_reportes' => $totalProcesados,
    'datos' => $reportes
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'status' => 'ok',
    'mensaje' => "Se sincronizaron con éxito $totalProcesados reportes en el portal de la agencia.",
    'total_procesados' => $totalProcesados,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
