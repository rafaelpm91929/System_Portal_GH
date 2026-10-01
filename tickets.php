<?php
// Polyfills de compatibilidad para servidores con PHP 7.x
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'conexion.php';
require_once 'permisos_helper.php';
require_once 'excel_helper.php';
include_once 'config_agencias.php';

// Normalizador unificado de nombres de agencia
if (!function_exists('normalizarAgencia')) {
    function normalizarAgencia($nombre) {
        $nombre = trim($nombre ?? '');
        if (empty($nombre)) return 'Oficina Central Grupo Huerta';
        if (stripos($nombre, 'divol') !== false) return 'Divol La Villa';
        if (stripos($nombre, 'central') !== false) return 'Oficina Central Grupo Huerta';
        return $nombre;
    }
}

// Protección de Sesión y Control de Acceso Estricto
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

if ($pdo) {
    cargarPermisosSesion($pdo, $_SESSION['usuario_id']);
}
requerirPermiso('tickets', 'puede_ver');

// Cargar datos de usuario
$usuarioId = $_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['usuario_nombre'] ?? ($_SESSION['nombre'] ?? 'Agente de Sistemas');
$agenciaUsuario = $_SESSION['agencia'] ?? 'Oficina Central Grupo Huerta';
$rolActual = strtolower($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'usuario');
$esSuperAdmin = ($rolActual === 'superadmin');
$esAdmin = in_array($rolActual, ['superadmin', 'admin']);

if ($pdo) {
    asegurarTablaTickets($pdo);
    asegurarTablaCitas($pdo);
}

// Catálogo Oficial Maestro de Áreas de Sistemas
$AREAS_SISTEMAS_CATALOGO = [
    'DESARROLLO'      => ['nombre' => 'DESARROLLO', 'icono' => 'bi-code-slash', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.18)', 'border' => 'rgba(56, 189, 248, 0.45)'],
    'CYBERSEGURIDAD'  => ['nombre' => 'CYBERSEGURIDAD', 'icono' => 'bi-shield-lock-fill', 'color' => '#f43f5e', 'bg' => 'rgba(244, 63, 94, 0.18)', 'border' => 'rgba(244, 63, 94, 0.45)'],
    'INFRAESTRUCTURA' => ['nombre' => 'INFRAESTRUCTURA', 'icono' => 'bi-hdd-rack-fill', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.18)', 'border' => 'rgba(16, 185, 129, 0.45)'],
    'REDES SOCIALES'  => ['nombre' => 'REDES SOCIALES', 'icono' => 'bi-share-fill', 'color' => '#a855f7', 'bg' => 'rgba(168, 85, 247, 0.18)', 'border' => 'rgba(168, 85, 247, 0.45)'],
    'AUDITORIA'       => ['nombre' => 'AUDITORIA', 'icono' => 'bi-clipboard-check-fill', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.18)', 'border' => 'rgba(245, 158, 11, 0.45)'],
    'CORPORATIVO'     => ['nombre' => 'CORPORATIVO', 'icono' => 'bi-building-fill', 'color' => '#6366f1', 'bg' => 'rgba(99, 102, 241, 0.18)', 'border' => 'rgba(99, 102, 241, 0.45)']
];

// Obtener áreas de tickets autorizadas para este usuario (desde sesión o recargar de BD)
$areasRaw = trim($_SESSION['areas_tickets'] ?? '');
if (empty($areasRaw) && $pdo && !empty($usuarioId)) {
    try {
        $stmtU = $pdo->prepare("SELECT rol, areas_tickets FROM usuarios WHERE id = ?");
        $stmtU->execute([$usuarioId]);
        $rowU = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($rowU) {
            $areasRaw = trim($rowU['areas_tickets'] ?? '');
            $_SESSION['areas_tickets'] = $areasRaw;
            if (isset($rowU['rol'])) {
                $_SESSION['usuario_rol'] = $rowU['rol'];
                $rolActual = strtolower($rowU['rol']);
                $esSuperAdmin = ($rolActual === 'superadmin');
                $esAdmin = in_array($rolActual, ['superadmin', 'admin']);
            }
        }
    } catch (Throwable $e) {}
}

$accesoTodasAreas = ($esSuperAdmin || strtoupper($areasRaw) === 'TODOS' || $areasRaw === '*');
$areasAutorizadas = [];

if ($accesoTodasAreas) {
    $areasAutorizadas = array_keys($AREAS_SISTEMAS_CATALOGO);
} elseif (!empty($areasRaw) && strtoupper($areasRaw) !== 'NINGUNA') {
    $parts = array_map('trim', explode(',', strtoupper($areasRaw)));
    $areasAutorizadas = array_values(array_intersect($parts, array_keys($AREAS_SISTEMAS_CATALOGO)));
}

// Catálogo activo para la sesión actual (determina qué chips y selects se despliegan)
$AREAS_SISTEMAS = [];
foreach ($areasAutorizadas as $aKey) {
    if (isset($AREAS_SISTEMAS_CATALOGO[$aKey])) {
        $AREAS_SISTEMAS[$aKey] = $AREAS_SISTEMAS_CATALOGO[$aKey];
    }
}

$mensaje = $_SESSION['flash_mensaje'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);

// ====================================================
// MANEJO DE ACCIONES POST (CREAR, EDITAR, ATENDER)
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $accion = $_POST['accion'] ?? '';

    // 1. Crear / Registrar Nuevo Ticket de Soporte (Por Agente)
    if ($accion === 'crear_ticket') {
        $area = trim($_POST['area_sistemas'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $prioridad = trim($_POST['prioridad'] ?? 'Media');
        $solicitanteNombre = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
        $solicitanteEmail = trim($_POST['solicitante_email'] ?? '');
        $solicitanteAgencia = normalizarAgencia(trim($_POST['solicitante_agencia'] ?? 'Divol La Villa'));
        $asignadoA = trim($_POST['asignado_a'] ?? '');

        if (!$accesoTodasAreas && !in_array(strtoupper($area), $areasAutorizadas)) {
            $error = "Acceso denegado: No tienes autorización para registrar tickets en el área '$area'.";
        } elseif (empty($titulo) || empty($descripcion) || empty($area)) {
            $error = "Por favor completa el Área de Sistemas, Título y Descripción del ticket.";
        } else {
            $archivoUrl = null;
            if (isset($_FILES['archivo_adjunto']) && $_FILES['archivo_adjunto']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['archivo_adjunto']['tmp_name'];
                $fileName = $_FILES['archivo_adjunto']['name'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $extPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

                if (in_array($fileExt, $extPermitidas)) {
                    $dirUpload = __DIR__ . '/uploads/tickets/';
                    if (!is_dir($dirUpload)) {
                        @mkdir($dirUpload, 0777, true);
                    }
                    $nuevoNombre = 'ticket_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $destino = $dirUpload . $nuevoNombre;
                    if (move_uploaded_file($fileTmp, $destino)) {
                        $archivoUrl = 'uploads/tickets/' . $nuevoNombre;
                    }
                }
            }

            try {
                $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                if ($driver === 'sqlite') {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM tickets_soporte");
                } else {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM `tickets_soporte`");
                }
                $nextNum = ($countStmt ? (int)$countStmt->fetchColumn() : 0) + 1;
                $folio = 'TK-' . date('Y') . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

                $solicitanteUsuario = $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? '');
                $stmt = $pdo->prepare("
                    INSERT INTO tickets_soporte 
                    (folio, area_sistemas, titulo, descripcion, prioridad, estado, solicitante_id, solicitante_usuario, solicitante_nombre, solicitante_email, solicitante_agencia, asignado_a, archivo_adjunto)
                    VALUES
                    (:folio, :area, :titulo, :desc, :prio, 'Abierto', :sol_id, :sol_usr, :sol_nom, :sol_em, :sol_ag, :asignado, :archivo)
                ");
                $stmt->execute([
                    ':folio'     => $folio,
                    ':area'      => $area,
                    ':titulo'    => $titulo,
                    ':desc'      => $descripcion,
                    ':prio'      => $prioridad,
                    ':sol_id'    => $usuarioId,
                    ':sol_usr'   => $solicitanteUsuario,
                    ':sol_nom'   => $solicitanteNombre,
                    ':sol_em'    => $solicitanteEmail,
                    ':sol_ag'    => $solicitanteAgencia,
                    ':asignado'  => $asignadoA,
                    ':archivo'   => $archivoUrl
                ]);
                $mensaje = "Ticket con Folio <strong>$folio</strong> para la agencia <strong>" . htmlspecialchars($solicitanteAgencia) . "</strong> registrado con éxito.";
            } catch (Throwable $e) {
                $error = "Error al registrar el ticket: " . $e->getMessage();
            }
        }
    }

    // 2. Actualizar Estado, Asignación y Notas de Resolución por el Agente
    if ($accion === 'actualizar_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $nuevoEstado = trim($_POST['estado'] ?? 'Abierto');
        $asignadoA = trim($_POST['asignado_a'] ?? '');
        $prioridad = trim($_POST['prioridad'] ?? 'Media');
        $notasResolucion = trim($_POST['notas_resolucion'] ?? '');

        if ($ticketId > 0) {
            try {
                // Validación estricta de área autorizada
                $stmtChk = $pdo->prepare("SELECT area_sistemas FROM tickets_soporte WHERE id = ?");
                $stmtChk->execute([$ticketId]);
                $areaTicket = strtoupper(trim($stmtChk->fetchColumn() ?: ''));

                if (!$accesoTodasAreas && !in_array($areaTicket, $areasAutorizadas)) {
                    $error = "Acceso denegado: No tienes autorización para modificar tickets del área '$areaTicket'.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tickets_soporte 
                        SET estado = :estado, asignado_a = :asignado, prioridad = :prioridad, notas_resolucion = :notas 
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':estado'    => $nuevoEstado,
                        ':asignado'  => $asignadoA,
                        ':prioridad' => $prioridad,
                        ':notas'     => $notasResolucion,
                        ':id'        => $ticketId
                    ]);

                    // Notificar a la agencia sucursal en tiempo real si tiene endpoint configurado
                    try {
                        $stmtTInfo = $pdo->prepare("SELECT folio, solicitante_agencia FROM tickets_soporte WHERE id = ?");
                        $stmtTInfo->execute([$ticketId]);
                        $tInfo = $stmtTInfo->fetch(PDO::FETCH_ASSOC);

                        if ($tInfo && !empty($tInfo['solicitante_agencia'])) {
                            $agKey = strpos(strtolower($tInfo['solicitante_agencia']), 'divol') !== false ? 'divolavilla' : '';
                            $targetUrl = 'https://portal.divolavilla.com/sistemas/api_webhook_ticket.php';
                            if (!empty($CATALOGO_AGENCIAS[$agKey]['endpoint'])) {
                                $targetUrl = str_replace('api_obtener_datos.php', 'api_webhook_ticket.php', $CATALOGO_AGENCIAS[$agKey]['endpoint']);
                            }

                            $payloadWebhook = json_encode([
                                'token'            => 'GedasDivolavilla2026!',
                                'folio'            => $tInfo['folio'],
                                'estado'           => $nuevoEstado,
                                'asignado_a'       => $asignadoA,
                                'prioridad'        => $prioridad,
                                'notas_resolucion' => $notasResolucion,
                                'actualizado_en'   => date('Y-m-d H:i:s')
                            ]);

                            $chWh = curl_init($targetUrl);
                            curl_setopt_array($chWh, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_POST           => true,
                                CURLOPT_POSTFIELDS     => $payloadWebhook,
                                CURLOPT_TIMEOUT        => 3,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
                            ]);
                            @curl_exec($chWh);
                            @curl_close($chWh);
                        }
                    } catch (Throwable $eWh) {}

                    $mensaje = "Ticket ID #$ticketId actualizado correctamente por el agente.";
                }
            } catch (Throwable $e) {
                $error = "Error al actualizar el ticket: " . $e->getMessage();
            }
        }
    }

    // 3. Auto-asignarme ticket
    if ($accion === 'autoasignar_ticket') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        if ($ticketId > 0) {
            try {
                $stmtChk = $pdo->prepare("SELECT area_sistemas FROM tickets_soporte WHERE id = ?");
                $stmtChk->execute([$ticketId]);
                $areaTicket = strtoupper(trim($stmtChk->fetchColumn() ?: ''));

                if (!$accesoTodasAreas && !in_array($areaTicket, $areasAutorizadas)) {
                    $error = "Acceso denegado: No tienes autorización para tomar tickets del área '$areaTicket'.";
                } else {
                    $stmt = $pdo->prepare("UPDATE tickets_soporte SET asignado_a = :nombre, estado = CASE WHEN estado = 'Abierto' THEN 'En Proceso' ELSE estado END WHERE id = :id");
                    $stmt->execute([':nombre' => $nombreUsuario, ':id' => $ticketId]);
                    $mensaje = "Te has autoasignado el ticket #$ticketId. Estado actualizado a 'En Proceso'.";
                }
            } catch (Throwable $e) {
                $error = "Error al autoasignar: " . $e->getMessage();
            }
        }
    }

    // 4. Eliminar Ticket
    if ($accion === 'eliminar_ticket' && $esAdmin) {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        if ($ticketId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM tickets_soporte WHERE id = :id");
                $stmt->execute([':id' => $ticketId]);
                $mensaje = "El ticket #$ticketId ha sido eliminado correctamente.";
            } catch (Throwable $e) {
                $error = "Error al eliminar el ticket: " . $e->getMessage();
            }
        }
    }

    // 5. Vaciar Todos los Tickets (Solo SuperAdmin)
    if ($accion === 'vaciar_todos_tickets' && $esAdmin) {
        try {
            $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') {
                $pdo->exec("DELETE FROM tickets_soporte;");
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name='tickets_soporte';");
            } else {
                $pdo->exec("TRUNCATE TABLE `tickets_soporte`;");
            }
            $dir = __DIR__ . '/uploads/tickets/';
            if (is_dir($dir)) {
                foreach (glob($dir . '*.*') as $f) {
                    if (basename($f) !== '.gitkeep') {
                        @unlink($f);
                    }
                }
            }
            $mensaje = "Se han eliminado todos los tickets y se ha reiniciado el contador de folios a 0.";
        } catch (Throwable $e) {
            $error = "Error al vaciar tickets: " . $e->getMessage();
        }
    }

    // 6. Agendar Cita de Soporte Técnico
    if ($accion === 'agendar_cita') {
        $agenciaCita = trim($_POST['agencia'] ?? '');
        $areaCita = trim($_POST['area_sistemas'] ?? 'INFRAESTRUCTURA');
        $tipoCita = trim($_POST['tipo_cita'] ?? 'Presencial');
        $asuntoCita = trim($_POST['asunto'] ?? '');
        $descCita = trim($_POST['descripcion'] ?? '');
        $solNomCita = trim($_POST['solicitante_nombre'] ?? $nombreUsuario);
        $solEmailCita = trim($_POST['solicitante_email'] ?? '');
        $tecnicoCita = trim($_POST['tecnico_asignado'] ?? $nombreUsuario);
        $fechaCita = trim($_POST['fecha_cita'] ?? '');
        $horaCita = trim($_POST['hora_cita'] ?? '09:00');
        $notasCita = trim($_POST['notas_atencion'] ?? '');

        if (empty($agenciaCita) || empty($asuntoCita) || empty($fechaCita)) {
            $error = "Por favor completa la Agencia, Asunto y Fecha de la cita.";
        } else {
            try {
                $stmtCConteo = $pdo->query("SELECT COUNT(*) FROM citas_soporte");
                $numC = ($stmtCConteo ? (int)$stmtCConteo->fetchColumn() : 0) + 1;
                $folioCita = 'CTA-' . date('Y') . '-' . str_pad($numC, 4, '0', STR_PAD_LEFT);

                $stmtInsC = $pdo->prepare("
                    INSERT INTO citas_soporte 
                    (folio, agencia, area_sistemas, tipo_cita, asunto, descripcion, solicitante_nombre, solicitante_usuario, solicitante_email, tecnico_asignado, fecha_cita, hora_cita, estado, notas_atencion)
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Programada', ?)
                ");
                $stmtInsC->execute([
                    $folioCita,
                    $agenciaCita,
                    $areaCita,
                    $tipoCita,
                    $asuntoCita,
                    $descCita,
                    $solNomCita,
                    $_SESSION['usuario_login'] ?? '',
                    $solEmailCita,
                    $tecnicoCita,
                    $fechaCita,
                    $horaCita,
                    $notasCita
                ]);
                $mensaje = "Cita programada con éxito con folio <strong>$folioCita</strong> para <strong>" . htmlspecialchars($agenciaCita) . "</strong>.";
                $_SESSION['tab_activa'] = 'citas';
            } catch (Throwable $eCita) {
                $error = "Error al agendar cita: " . $eCita->getMessage();
            }
        }
    }

    // 7. Actualizar Cita de Soporte
    if ($accion === 'actualizar_cita') {
        $citaId = intval($_POST['cita_id'] ?? 0);
        $estadoCita = trim($_POST['estado'] ?? 'Programada');
        $tecnicoCita = trim($_POST['tecnico_asignado'] ?? '');
        $fechaCita = trim($_POST['fecha_cita'] ?? '');
        $horaCita = trim($_POST['hora_cita'] ?? '');
        $notasCita = trim($_POST['notas_atencion'] ?? '');

        if ($citaId > 0) {
            try {
                $stmtUpC = $pdo->prepare("
                    UPDATE citas_soporte 
                    SET estado = ?, tecnico_asignado = ?, fecha_cita = ?, hora_cita = ?, notas_atencion = ?
                    WHERE id = ?
                ");
                $stmtUpC->execute([$estadoCita, $tecnicoCita, $fechaCita, $horaCita, $notasCita, $citaId]);
                $mensaje = "Cita ID #$citaId actualizada correctamente.";
                $_SESSION['tab_activa'] = 'citas';
            } catch (Throwable $eUpC) {
                $error = "Error al actualizar cita: " . $eUpC->getMessage();
            }
        }
    }

    // 8. Eliminar Cita
    if ($accion === 'eliminar_cita' && $esAdmin) {
        $citaId = intval($_POST['cita_id'] ?? 0);
        if ($citaId > 0) {
            try {
                $stmtDelC = $pdo->prepare("DELETE FROM citas_soporte WHERE id = ?");
                $stmtDelC->execute([$citaId]);
                $mensaje = "Cita ID #$citaId eliminada correctamente.";
                $_SESSION['tab_activa'] = 'citas';
            } catch (Throwable $eDelC) {
                $error = "Error al eliminar cita: " . $eDelC->getMessage();
            }
        }
    }

    // Patrón Post/Redirect/Get: Previene duplicados al recargar con F5
    $_SESSION['flash_mensaje'] = $mensaje;
    $_SESSION['flash_error'] = $error;
    $tabRedir = $_SESSION['tab_activa'] ?? '';
    unset($_SESSION['tab_activa']);
    $urlDestino = "tickets.php" . (!empty($tabRedir) ? "?tab=" . urlencode($tabRedir) : "");
    header("Location: $urlDestino");
    exit();
}

// ====================================================
// EXPORTACIÓN A EXCEL CORPORATIVO
// ====================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel' && $pdo) {
    $agenciaFiltro = trim($_GET['agencia'] ?? '');
    $whereParts = [];
    $whereParams = [];

    if (!empty($agenciaFiltro) && $agenciaFiltro !== 'TODAS') {
        $whereParts[] = "LOWER(solicitante_agencia) LIKE ?";
        $whereParams[] = '%' . strtolower($agenciaFiltro) . '%';
        $fileName = 'Reporte_Tickets_' . preg_replace('/[^A-Za-z0-9]/', '_', $agenciaFiltro) . '_' . date('Ymd_His') . '.xls';
        $agenciaInfo = ['nombre' => $agenciaFiltro . ' - Grupo Huerta'];
    } else {
        $fileName = 'Reporte_Tickets_Helpdesk_TI_' . date('Ymd_His') . '.xls';
        $agenciaInfo = ['nombre' => 'Dirección Central de Sistemas - Grupo Huerta'];
    }

    if (!$accesoTodasAreas) {
        if (empty($areasAutorizadas)) {
            $whereParts[] = "1 = 0";
        } else {
            $inQ = implode(',', array_fill(0, count($areasAutorizadas), '?'));
            $whereParts[] = "UPPER(area_sistemas) IN ($inQ)";
            foreach ($areasAutorizadas as $ar) {
                $whereParams[] = $ar;
            }
        }
    }

    $sqlExp = "SELECT * FROM tickets_soporte";
    if (!empty($whereParts)) {
        $sqlExp .= " WHERE " . implode(" AND ", $whereParts);
    }
    $sqlExp .= " ORDER BY id DESC";

    $stmtExp = $pdo->prepare($sqlExp);
    $stmtExp->execute($whereParams);
    $registros = $stmtExp ? $stmtExp->fetchAll(PDO::FETCH_ASSOC) : [];

    $columnasExcel = [
        ['titulo' => 'FOLIO', 'ancho' => 110, 'align' => 'center'],
        ['titulo' => 'AGENCIA ORIGEN', 'ancho' => 180, 'align' => 'left'],
        ['titulo' => 'ÁREA SISTEMAS', 'ancho' => 140, 'align' => 'center'],
        ['titulo' => 'TÍTULO / ASUNTO', 'ancho' => 240, 'align' => 'left'],
        ['titulo' => 'PRIORIDAD', 'ancho' => 110, 'align' => 'center'],
        ['titulo' => 'ESTADO', 'ancho' => 120, 'align' => 'center'],
        ['titulo' => 'SOLICITANTE', 'ancho' => 170, 'align' => 'left'],
        ['titulo' => 'AGENTE ASIGNADO', 'ancho' => 170, 'align' => 'left'],
        ['titulo' => 'FECHA REGISTRO', 'ancho' => 150, 'align' => 'center'],
        ['titulo' => 'NOTAS RESOLUCIÓN', 'ancho' => 280, 'align' => 'left']
    ];

    $filasExcel = [];
    foreach ($registros as $r) {
        $filasExcel[] = [
            ['val' => htmlspecialchars($r['folio'] ?? 'TK-' . $r['id']), 'align' => 'center', 'is_bold' => true],
            ['val' => htmlspecialchars($r['solicitante_agencia'] ?? 'Central'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['area_sistemas'] ?? '---'), 'align' => 'center'],
            ['val' => htmlspecialchars($r['titulo'] ?? '---'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['prioridad'] ?? 'Media'), 'align' => 'center'],
            ['val' => htmlspecialchars($r['estado'] ?? 'Abierto'), 'align' => 'center'],
            ['val' => htmlspecialchars($r['solicitante_nombre'] ?? '---'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['asignado_a'] ?? 'Sin asignar'), 'align' => 'left'],
            ['val' => htmlspecialchars($r['creado_en'] ?? '---'), 'align' => 'center', 'is_text' => true],
            ['val' => htmlspecialchars($r['notas_resolucion'] ?? '---'), 'align' => 'left']
        ];
    }

    descargarExcelConDiseno(
        'TICKETS DE SOPORTE &bull; MESA DE AYUDA TI',
        'Panel de Agentes - Control de Incidencias de Agencias Grupo Huerta',
        $columnasExcel,
        $filasExcel,
        $agenciaInfo,
        $fileName,
        'Tickets Helpdesk'
    );
}

// ====================================================
// CONSULTA DE TICKETS Y MÉTRICAS DE AGENTES
// ====================================================
$tickets = [];
$totalTickets = 0;
$totalAbiertos = 0;
$totalEnProceso = 0;
$totalResueltos = 0;
$totalSinAsignar = 0;
$conteoPorAgencia = [];
$agenciasList = [];
$ticketsSinVer = [];

// 1. Inicializar agencias del catálogo oficial y personalizadas
if (!empty($CATALOGO_AGENCIAS)) {
    foreach ($CATALOGO_AGENCIAS as $k => $ag) {
        $nomNorm = normalizarAgencia($ag['nombre'] ?? '');
        $conteoPorAgencia[$nomNorm] = 0;
        $agenciasList[$nomNorm] = [
            'id'          => $ag['id'] ?? $k,
            'nombre'      => $nomNorm,
            'cpanel'      => $ag['cpanel'] ?? ($ag['subdominio'] ?? 'cPanel Conectado'),
            'subdominio'  => $ag['subdominio'] ?? '',
            'icono'       => $ag['icono'] ?? 'bi-building-fill-check',
            'color'       => $ag['color'] ?? '#2563eb',
            'descripcion' => $ag['descripcion'] ?? 'Agencia cPanel Conectada',
            'total'       => 0,
            'abiertos'    => 0,
            'en_proceso'  => 0,
            'resueltos'   => 0,
            'ultimo_ticket' => null
        ];
    }
}

// 2. Garantizar presencia de Oficina Central Grupo Huerta
if (!isset($agenciasList['Oficina Central Grupo Huerta'])) {
    $conteoPorAgencia['Oficina Central Grupo Huerta'] = 0;
    $agenciasList['Oficina Central Grupo Huerta'] = [
        'id'          => 'oficina_central',
        'nombre'      => 'Oficina Central Grupo Huerta',
        'cpanel'      => 'portal.grupohuerta.mx',
        'subdominio'  => 'portal.grupohuerta.mx',
        'icono'       => 'bi-buildings-fill',
        'color'       => '#10b981',
        'descripcion' => 'Dirección Central de Sistemas y TI',
        'total'       => 0,
        'abiertos'    => 0,
        'en_proceso'  => 0,
        'resueltos'   => 0,
        'ultimo_ticket' => null
    ];
}

if ($pdo) {
    try {
        if ($accesoTodasAreas) {
            $stmtAll = $pdo->query("SELECT * FROM tickets_soporte ORDER BY id DESC");
            $tickets = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            if (empty($areasAutorizadas)) {
                $tickets = [];
            } else {
                $inQ = implode(',', array_fill(0, count($areasAutorizadas), '?'));
                $stmtAll = $pdo->prepare("SELECT * FROM tickets_soporte WHERE UPPER(area_sistemas) IN ($inQ) ORDER BY id DESC");
                $stmtAll->execute($areasAutorizadas);
                $tickets = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];
            }
        }

        $totalTickets = count($tickets);
        foreach ($tickets as $t) {
            $estLower = strtolower(trim($t['estado'] ?? ''));
            if ($estLower === 'abierto') {
                $totalAbiertos++;
                $ticketsSinVer[] = $t;
            } elseif ($estLower === 'en proceso') {
                $totalEnProceso++;
            } elseif ($estLower === 'resuelto' || $estLower === 'cerrado') {
                $totalResueltos++;
            }

            if (empty(trim($t['asignado_a'] ?? ''))) {
                $totalSinAsignar++;
            }

            $agNom = normalizarAgencia($t['solicitante_agencia'] ?? 'Oficina Central Grupo Huerta');
            if (!isset($agenciasList[$agNom])) {
                $conteoPorAgencia[$agNom] = 0;
                $agenciasList[$agNom] = [
                    'id'          => preg_replace('/[^a-z0-9]/', '_', strtolower($agNom)),
                    'nombre'      => $agNom,
                    'cpanel'      => 'Sucursal Registrada',
                    'subdominio'  => '',
                    'icono'       => 'bi-building',
                    'color'       => '#6366f1',
                    'descripcion' => 'Sucursal Grupo Huerta',
                    'total'       => 0,
                    'abiertos'    => 0,
                    'en_proceso'  => 0,
                    'resueltos'   => 0,
                    'ultimo_ticket' => null
                ];
            }

            $agenciasList[$agNom]['total']++;
            if ($estLower === 'abierto') {
                $agenciasList[$agNom]['abiertos']++;
            } elseif ($estLower === 'en proceso') {
                $agenciasList[$agNom]['en_proceso']++;
            } elseif ($estLower === 'resuelto' || $estLower === 'cerrado') {
                $agenciasList[$agNom]['resueltos']++;
            }

            if ($agenciasList[$agNom]['ultimo_ticket'] === null) {
                $agenciasList[$agNom]['ultimo_ticket'] = $t;
            }

            if ($estLower !== 'resuelto' && $estLower !== 'cerrado') {
                $conteoPorAgencia[$agNom]++;
            }
        }
    } catch (Throwable $e) {}
}

// ====================================================
// CONSULTA DE CITAS DE SOPORTE TÉCNICO
// ====================================================
$citas = [];
$totalCitas = 0;
$totalCitasProgramadas = 0;
$totalCitasEnCurso = 0;
$totalCitasRealizadas = 0;

if ($pdo) {
    try {
        $stmtC = $pdo->query("SELECT * FROM citas_soporte ORDER BY fecha_cita ASC, hora_cita ASC");
        $citas = $stmtC ? $stmtC->fetchAll(PDO::FETCH_ASSOC) : [];
        $totalCitas = count($citas);
        foreach ($citas as $c) {
            $estC = strtolower(trim($c['estado'] ?? 'programada'));
            if ($estC === 'programada') $totalCitasProgramadas++;
            elseif ($estC === 'en curso' || $estC === 'en atencion') $totalCitasEnCurso++;
            elseif ($estC === 'realizada' || $estC === 'completada') $totalCitasRealizadas++;
        }
    } catch (Throwable $eC) {}
}

// ====================================================
// CÁLCULO DE MÉTRICAS Y ESTADÍSTICAS DEL SISTEMA
// ====================================================
$conteoPorArea = [];
foreach ($AREAS_SISTEMAS as $k => $v) {
    $conteoPorArea[$k] = 0;
}
$conteoPorEstado = ['Abierto' => 0, 'En Proceso' => 0, 'Resuelto' => 0, 'Cerrado' => 0];
$conteoPorPrioridad = ['Baja' => 0, 'Media' => 0, 'Alta' => 0, 'Urgente' => 0];

foreach ($tickets as $t) {
    $a = strtoupper(trim($t['area_sistemas'] ?? ''));
    if (isset($conteoPorArea[$a])) {
        $conteoPorArea[$a]++;
    }

    $e = strtolower(trim($t['estado'] ?? 'abierto'));
    if ($e === 'abierto') $conteoPorEstado['Abierto']++;
    elseif ($e === 'en proceso') $conteoPorEstado['En Proceso']++;
    elseif ($e === 'resuelto') $conteoPorEstado['Resuelto']++;
    elseif ($e === 'cerrado') $conteoPorEstado['Cerrado']++;
    else $conteoPorEstado['Abierto']++;

    $p = ucfirst(strtolower(trim($t['prioridad'] ?? 'Media')));
    if (isset($conteoPorPrioridad[$p])) {
        $conteoPorPrioridad[$p]++;
    }
}

$tasaResolucion = $totalTickets > 0 ? round((($conteoPorEstado['Resuelto'] + $conteoPorEstado['Cerrado']) / $totalTickets) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Agentes TI - Tickets Soporte Dirección Sistemas</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Chart.js para Estadísticas -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #061325;
            background-image: radial-gradient(#0e2440 1px, transparent 1px);
            background-size: 28px 28px;
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        /* Estructura de Layout con Menú Lateral */
        .app-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .app-sidebar {
            width: 260px;
            background: #061528;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 1030;
            transition: all 0.25s ease;
        }

        .sidebar-brand {
            padding: 18px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar-brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(14, 165, 233, 0.4);
            color: #38bdf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .sidebar-menu {
            padding: 16px 12px;
            flex-grow: 1;
            overflow-y: auto;
        }

        .sidebar-group-title {
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: #64748b;
            padding: 12px 14px 6px;
        }

        .sidebar-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 16px;
            border-radius: 12px;
            border: 1px solid transparent;
            background: transparent;
            color: #94a3b8;
            font-size: 0.92rem;
            font-weight: 600;
            text-align: left;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            margin-bottom: 5px;
        }

        .sidebar-btn:hover {
            background: rgba(56, 189, 248, 0.1);
            color: #38bdf8;
            border-color: rgba(56, 189, 248, 0.25);
            transform: translateX(3px);
        }

        .sidebar-btn.active {
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.35), rgba(14, 165, 233, 0.18));
            border-color: rgba(56, 189, 248, 0.5);
            color: #38bdf8;
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.25);
        }

        .sidebar-btn i {
            font-size: 1.2rem;
            line-height: 1;
        }

        .sidebar-footer {
            padding: 15px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.25);
        }

        .app-content {
            flex-grow: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            padding-bottom: 60px;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 1025;
        }

        @media (max-width: 991px) {
            .app-sidebar {
                position: fixed;
                left: -280px;
                top: 0;
                bottom: 0;
                z-index: 1050;
            }
            .app-sidebar.show {
                left: 0;
            }
            .sidebar-backdrop.show {
                display: block;
            }
        }

        .top-navbar {
            background: rgba(6, 19, 37, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 35px;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .main-container {
            max-width: 1560px;
            margin: 0 auto;
            padding: 25px 35px;
            width: 100%;
        }

        /* Banner de Agente */
        .agent-badge {
            background: rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(14, 165, 233, 0.35);
            color: #38bdf8;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* Barra de Agencias Conectadas */
        .agencies-monitor-bar {
            background: #091a32;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 25px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        .agency-pill-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            padding: 7px 16px;
            border-radius: 25px;
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            user-select: none;
        }
        .agency-pill-btn:hover {
            background: rgba(56, 189, 248, 0.15);
            border-color: #38bdf8;
            color: #ffffff;
            transform: translateY(-2px);
        }
        .agency-pill-btn.active {
            background: #0284c7 !important;
            border-color: #38bdf8 !important;
            color: #ffffff !important;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.45);
        }
        .pending-count-badge {
            font-size: 0.7rem;
            padding: 2px 7px;
            border-radius: 12px;
            font-weight: 700;
        }

        /* Hub de Agencias (Diseño Formal Futurista) */
        .agency-ticket-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(390px, 1fr));
            gap: 24px;
        }
        .agency-ticket-card {
            background: linear-gradient(145deg, rgba(13, 27, 48, 0.94) 0%, rgba(8, 18, 36, 0.98) 100%);
            border: 1px solid rgba(56, 189, 248, 0.2);
            border-radius: 22px;
            padding: 24px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .agency-ticket-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #38bdf8, #818cf8, #a855f7);
            opacity: 0.6;
            transition: opacity 0.3s ease;
        }
        .agency-ticket-card:hover {
            transform: translateY(-6px);
            border-color: rgba(56, 189, 248, 0.6);
            background: linear-gradient(145deg, rgba(16, 36, 64, 0.96) 0%, rgba(10, 24, 48, 0.98) 100%);
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.65), 0 0 28px rgba(56, 189, 248, 0.22);
        }
        .agency-ticket-card:hover::before {
            opacity: 1;
        }
        .agency-ticket-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.55rem;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.15), rgba(99, 102, 241, 0.2));
            border: 1px solid rgba(56, 189, 248, 0.3);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.35);
        }
        .agency-stat-box {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.85) 0%, rgba(8, 15, 28, 0.95) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 12px 8px;
            text-align: center;
            transition: all 0.25s ease;
        }
        .agency-stat-box.stat-totales {
            border-color: rgba(255, 255, 255, 0.1);
        }
        .agency-stat-box.stat-sin-atender {
            background: linear-gradient(180deg, rgba(245, 158, 11, 0.12) 0%, rgba(30, 20, 5, 0.85) 100%);
            border-color: rgba(245, 158, 11, 0.25);
        }
        .agency-stat-box.highlight-open {
            border-color: rgba(245, 158, 11, 0.65) !important;
            background: linear-gradient(180deg, rgba(245, 158, 11, 0.22) 0%, rgba(50, 30, 5, 0.95) 100%) !important;
            box-shadow: 0 0 16px rgba(245, 158, 11, 0.3);
            animation: pulseGlow 2.5s infinite;
        }
        .agency-stat-box.stat-en-proceso {
            background: linear-gradient(180deg, rgba(14, 165, 233, 0.12) 0%, rgba(5, 25, 45, 0.85) 100%);
            border-color: rgba(14, 165, 233, 0.25);
        }
        .agency-stat-box.stat-resueltos {
            background: linear-gradient(180deg, rgba(16, 185, 129, 0.12) 0%, rgba(5, 35, 25, 0.85) 100%);
            border-color: rgba(16, 185, 129, 0.25);
        }
        .agency-ticket-card:hover .agency-stat-box {
            transform: translateY(-2px);
        }
        .stat-number {
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.5px;
        }
        .stat-label {
            font-size: 0.65rem;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.8px;
            margin-top: 5px;
            text-transform: uppercase;
        }
        .btn-futuristic-view {
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.9) 0%, rgba(37, 99, 235, 0.95) 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.8rem;
            padding: 6px 16px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
            transition: all 0.2s ease;
            text-decoration: none;
            border: 1px solid rgba(56, 189, 248, 0.4);
        }
        .agency-ticket-card:hover .btn-futuristic-view {
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            box-shadow: 0 6px 18px rgba(2, 132, 199, 0.6);
            transform: scale(1.04);
        }
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 10px rgba(245, 158, 11, 0.25); }
            50% { box-shadow: 0 0 20px rgba(245, 158, 11, 0.55); }
        }

        /* ==================================================== */
        /* GALERÍA 3D COVERFLOW (INSPIRADA EN LA REFERENCIA)   */
        /* ==================================================== */
        .coverflow-3d-wrapper {
            position: relative;
            width: 100%;
            padding: 20px 0 50px;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow: visible;
        }

        .coverflow-3d-stage {
            position: relative;
            width: 100%;
            max-width: 1100px;
            height: 480px;
            perspective: 1200px;
            transform-style: preserve-3d;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto;
        }

        .coverflow-card {
            position: absolute;
            width: 350px;
            height: 460px;
            border-radius: 24px;
            background: linear-gradient(165deg, rgba(13, 27, 48, 0.96) 0%, rgba(8, 16, 32, 0.98) 100%);
            border: 1px solid rgba(56, 189, 248, 0.25);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.75);
            transition: transform 0.55s cubic-bezier(0.25, 1, 0.5, 1),
                        opacity 0.55s ease,
                        filter 0.55s ease,
                        box-shadow 0.55s ease,
                        border-color 0.55s ease;
            cursor: pointer;
            user-select: none;
            padding: 24px 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            -webkit-box-reflect: below 10px linear-gradient(to bottom, transparent 65%, rgba(0, 0, 0, 0.4) 100%);
            overflow: hidden;
        }

        .coverflow-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #38bdf8, #818cf8, #c084fc, #38bdf8);
            opacity: 0.8;
        }

        /* Posiciones en el espacio 3D */
        .coverflow-card.pos-center {
            transform: translateX(0) translateZ(0) rotateY(0deg) scale(1);
            z-index: 10;
            opacity: 1;
            filter: blur(0);
            border-color: rgba(56, 189, 248, 0.65);
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.85), 0 0 40px rgba(56, 189, 248, 0.25);
            pointer-events: auto;
        }

        .coverflow-card.pos-left {
            transform: translateX(-280px) translateZ(-160px) rotateY(38deg) scale(0.85);
            z-index: 5;
            opacity: 0.6;
            filter: brightness(0.65) blur(0.5px);
            pointer-events: auto;
        }
        .coverflow-card.pos-left:hover {
            opacity: 0.85;
            filter: brightness(0.85);
            transform: translateX(-280px) translateZ(-130px) rotateY(34deg) scale(0.88);
        }

        .coverflow-card.pos-right {
            transform: translateX(280px) translateZ(-160px) rotateY(-38deg) scale(0.85);
            z-index: 5;
            opacity: 0.6;
            filter: brightness(0.65) blur(0.5px);
            pointer-events: auto;
        }
        .coverflow-card.pos-right:hover {
            opacity: 0.85;
            filter: brightness(0.85);
            transform: translateX(280px) translateZ(-130px) rotateY(-34deg) scale(0.88);
        }

        .coverflow-card.pos-hidden-left {
            transform: translateX(-520px) translateZ(-320px) rotateY(55deg) scale(0.65);
            z-index: 1;
            opacity: 0;
            pointer-events: none;
        }

        .coverflow-card.pos-hidden-right {
            transform: translateX(520px) translateZ(-320px) rotateY(-55deg) scale(0.65);
            z-index: 1;
            opacity: 0;
            pointer-events: none;
        }

        /* Controles circulares de navegación (como en la imagen de referencia) */
        .coverflow-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 22px;
            margin-top: 30px;
            z-index: 25;
            position: relative;
        }

        .coverflow-btn-circle {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: rgba(13, 27, 48, 0.7);
            border: 2px solid rgba(255, 255, 255, 0.4);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            cursor: pointer;
            transition: all 0.25s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        }

        .coverflow-btn-circle:hover {
            background: #0284c7;
            border-color: #38bdf8;
            color: #ffffff;
            transform: scale(1.12);
            box-shadow: 0 0 25px rgba(56, 189, 248, 0.6);
        }

        .coverflow-btn-circle:active {
            transform: scale(0.94);
        }

        /* Indicador de agencia y selector de puntos */
        .coverflow-dots {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 15px;
        }
        .coverflow-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transition: all 0.25s ease;
            cursor: pointer;
        }
        .coverflow-dot.active {
            width: 28px;
            border-radius: 12px;
            background: #38bdf8;
            box-shadow: 0 0 12px #38bdf8;
        }

        /* KPIs */
        .kpi-card {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            border-color: rgba(56, 189, 248, 0.4);
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
        .kpi-cyan { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
        .kpi-yellow { background: rgba(234, 179, 8, 0.15); color: #fde047; }
        .kpi-red { background: rgba(244, 63, 94, 0.15); color: #fb7185; }
        .kpi-blue { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .kpi-green { background: rgba(34, 197, 94, 0.15); color: #4ade80; }

        /* Filtros por Área */
        .area-pill {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            user-select: none;
        }
        .area-pill:hover, .area-pill.active {
            color: #ffffff;
            border-color: #38bdf8;
            background: rgba(56, 189, 248, 0.2);
            box-shadow: 0 2px 8px rgba(56, 189, 248, 0.25);
        }

        .search-box-wrapper {
            background: #0d1e36;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 7px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .search-box-wrapper:focus-within {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25);
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

        /* Tabla de Tickets */
        .tickets-table-card {
            background: #091a32;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.45);
        }
        .table {
            margin-bottom: 0;
            --bs-table-bg: transparent;
            --bs-table-color: #ffffff;
            border-collapse: collapse;
        }
        .table thead th {
            background-color: #0b2242 !important;
            color: #94a3b8;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 15px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            vertical-align: middle;
        }
        .table tbody tr {
            background-color: #08172c !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background-color 0.15s ease;
        }
        .table tbody tr:nth-of-type(even) {
            background-color: #0b1f3b !important;
        }
        .table tbody tr:hover {
            background-color: #122b52 !important;
        }
        .table tbody td {
            padding: 14px 18px;
            vertical-align: middle;
            font-size: 0.88rem;
            color: #e2e8f0;
            border-top: none;
        }

        .badge-folio {
            font-family: monospace;
            font-weight: 700;
            font-size: 0.85rem;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.3);
            padding: 3px 8px;
            border-radius: 6px;
        }
        .badge-agencia {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 12px;
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .badge-prio-urgente { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .badge-prio-alta    { background: rgba(249, 115, 22, 0.2); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.4); }
        .badge-prio-media   { background: rgba(234, 179, 8, 0.2); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-prio-baja    { background: rgba(100, 116, 139, 0.2); color: #94a3b8; border: 1px solid rgba(100, 116, 139, 0.4); }

        .badge-status-abierto    { background: rgba(234, 179, 8, 0.18); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-status-proceso    { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        .badge-status-resuelto   { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.4); }
        .badge-status-cerrado    { background: rgba(100, 116, 139, 0.25); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.4); }

        /* Modales */
        .modal-content {
            background-color: #0b1f3b;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 20px;
        }
        .modal-header { border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 18px 25px; }
        .modal-footer { border-top: 1px solid rgba(255, 255, 255, 0.08); padding: 15px 25px; }
        .form-control, .form-select {
            background-color: #061325;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 10px;
        }
        .form-control:focus, .form-select:focus {
            background-color: #0a1f3d;
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25);
        }
    </style>
</head>
<body>

<!-- Backdrop para móviles -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<div class="app-layout">
    <!-- ============================================== -->
    <!-- MENÚ LATERAL (SIDEBAR DEL AGENTE DE TICKETS) -->
    <!-- ============================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- Encabezado del Menú Lateral -->
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon">
                <i class="bi bi-headset"></i>
            </div>
            <div class="overflow-hidden">
                <div class="fw-bold text-white text-truncate" style="font-size: 0.95rem;">Mesa de Ayuda TI</div>
                <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Dirección Central &bull; Soporte</div>
            </div>
        </div>

        <!-- Botones Principales del Menú Lateral -->
        <div class="sidebar-menu">
            <div class="sidebar-group-title">MÓDULOS DE SOPORTE</div>

            <!-- Botón 1: Tickets -->
            <button type="button" class="sidebar-btn active" id="sidebarBtn_tickets" onclick="cambiarTab('tickets')">
                <i class="bi bi-ticket-detailed-fill text-info"></i>
                <span class="flex-grow-1">Tickets</span>
                <?php if ($totalAbiertos > 0): ?>
                    <span class="badge bg-warning text-dark font-monospace fw-bold" style="font-size: 0.72rem;"><?php echo $totalAbiertos; ?></span>
                <?php else: ?>
                    <span class="badge bg-dark border border-secondary text-secondary font-monospace" style="font-size: 0.72rem;"><?php echo $totalTickets; ?></span>
                <?php endif; ?>
            </button>

            <!-- Botón 2: Citas -->
            <button type="button" class="sidebar-btn" id="sidebarBtn_citas" onclick="cambiarTab('citas')">
                <i class="bi bi-calendar-event-fill text-success"></i>
                <span class="flex-grow-1">Citas</span>
                <?php if ($totalCitasProgramadas > 0): ?>
                    <span class="badge bg-success text-white font-monospace fw-bold" style="font-size: 0.72rem;"><?php echo $totalCitasProgramadas; ?></span>
                <?php else: ?>
                    <span class="badge bg-dark border border-secondary text-secondary font-monospace" style="font-size: 0.72rem;"><?php echo $totalCitas; ?></span>
                <?php endif; ?>
            </button>

            <!-- Botón 3: Estadísticas -->
            <button type="button" class="sidebar-btn" id="sidebarBtn_estadisticas" onclick="cambiarTab('estadisticas')">
                <i class="bi bi-bar-chart-line-fill text-primary"></i>
                <span class="flex-grow-1">Estadísticas</span>
                <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25" style="font-size: 0.65rem;">Métricas</span>
            </button>

            <div class="sidebar-group-title mt-3">NAVEGACIÓN SISTEMA</div>
            <a href="menu.php" class="sidebar-btn text-secondary">
                <i class="bi bi-arrow-left-circle"></i>
                <span>Menú Principal</span>
            </a>
            <a href="agencias.php" class="sidebar-btn text-secondary">
                <i class="bi bi-buildings"></i>
                <span>cPanels / Agencias</span>
            </a>
            <?php if (tienePermiso('usuarios', 'puede_ver')): ?>
                <a href="usuarios.php" class="sidebar-btn text-secondary">
                    <i class="bi bi-people"></i>
                    <span>Usuarios</span>
                </a>
            <?php endif; ?>
        </div>

        <!-- Footer del Perfil en el Sidebar -->
        <div class="sidebar-footer">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <div class="rounded-circle bg-dark border border-secondary d-flex align-items-center justify-content-center text-info fw-bold" style="width: 36px; height: 36px; flex-shrink: 0; font-size: 0.85rem;">
                        <?php echo strtoupper(substr($nombreUsuario, 0, 1)); ?>
                    </div>
                    <div class="overflow-hidden">
                        <div class="text-white fw-semibold small text-truncate" style="font-size: 0.82rem;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                        <div class="text-secondary small font-monospace text-truncate" style="font-size: 0.72rem;">@<?php echo htmlspecialchars($_SESSION['usuario_login'] ?? 'agente'); ?></div>
                    </div>
                </div>
                <a href="logout.php" class="btn btn-sm btn-outline-danger border-0 p-1" title="Cerrar Sesión">
                    <i class="bi bi-power fs-5"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- CONTENIDO DE LA APLICACIÓN -->
    <div class="app-content">
        <!-- Navbar Superior -->
        <div class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-secondary btn-sm d-lg-none text-white border-opacity-25" onclick="toggleSidebar()">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold tracking-wide" id="topNavTituloSeccion">
                        <i class="bi bi-ticket-detailed-fill text-info me-1"></i> Tickets de Soporte
                    </span>
                    <span class="agent-badge ms-2 d-none d-sm-inline-flex"><i class="bi bi-person-badge-fill"></i> PANEL DE AGENTE TI</span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end d-none d-md-block">
                    <div class="small fw-semibold"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                    <div class="text-secondary" style="font-size: 0.75rem;"><?php echo htmlspecialchars($agenciaUsuario); ?> &bull; <span class="badge bg-primary text-uppercase"><?php echo htmlspecialchars($rolActual); ?></span></div>
                </div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3">
                    <i class="bi bi-box-arrow-right me-1"></i> Salir
                </a>
            </div>
        </div>

        <div class="main-container">

            <!-- Notificaciones -->
            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-success alert-dismissible fade show rounded-3 bg-success bg-opacity-25 text-white border-success mb-3" role="alert">
                    <i class="bi bi-check-circle-fill me-2 fs-5"></i> <?php echo $mensaje; ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-3 bg-danger bg-opacity-25 text-white border-danger mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$accesoTodasAreas): ?>
                <?php if (empty($areasAutorizadas)): ?>
                    <div class="alert alert-warning border-0 rounded-4 p-4 text-center my-4" style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.35) !important;" role="alert">
                        <i class="bi bi-shield-lock-fill text-warning fs-1 d-block mb-2"></i>
                        <h5 class="fw-bold text-white mb-2">Sin Áreas de Sistemas Autorizadas</h5>
                        <p class="text-secondary small mb-0" style="max-width: 600px; margin: 0 auto;">
                            Tu usuario no tiene ninguna área de Sistemas asignada para tickets. Por políticas de seguridad, no puedes consultar ni dar seguimiento a requerimientos de otras áreas. Solicita a un Administrador que configure tus áreas en la Gestión de Usuarios.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info border-0 rounded-3 py-2 px-3 mb-4 d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: rgba(2, 132, 199, 0.15); border: 1px solid rgba(56, 189, 248, 0.3) !important;">
                        <div class="small text-light d-flex flex-wrap align-items-center gap-2">
                            <i class="bi bi-shield-lock-fill text-info fs-5"></i>
                            <span>Áreas de Sistemas autorizadas para tu perfil:</span>
                            <?php foreach ($areasAutorizadas as $ak): 
                                $inf = $AREAS_SISTEMAS_CATALOGO[$ak] ?? null;
                            ?>
                                <span class="badge" style="background: <?php echo $inf['bg'] ?? '#0284c7'; ?>; color: <?php echo $inf['color'] ?? '#38bdf8'; ?>; border: 1px solid <?php echo $inf['border'] ?? '#38bdf8'; ?>;">
                                    <i class="bi <?php echo $inf['icono'] ?? 'bi-tag'; ?> me-1"></i><?php echo $ak; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <span class="badge bg-secondary bg-opacity-50 text-light small">Filtro de Seguridad Activo</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ======================================================= -->
            <!-- SECCIÓN 1: GESTIÓN DE TICKETS Y AGENCIA S -->
            <!-- ======================================================= -->
            <div id="seccionTab_tickets" class="tab-seccion">
                <div id="vistaAgencias">
                    <!-- Encabezado de Vista: Tickets Dirección Sistemas -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-primary bg-opacity-20 text-info border border-info border-opacity-30 rounded-pill px-3 py-1 fw-bold">
                                    <i class="bi bi-shield-check me-1"></i> Mesa de Ayuda TI
                                </span>
                                <span class="text-secondary small">&bull; Dirección Central de Sistemas</span>
                            </div>
                            <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                                <i class="bi bi-ticket-perforated-fill text-info"></i> Tickets Dirección Sistemas
                            </h3>
                            <p class="text-secondary mb-0 small">
                                Monitoreo y control central de requerimientos técnicos e infraestructura en todas las sucursales de Grupo Huerta.
                            </p>
                        </div>
                    </div>

                    <!-- KPIs Generales Globales del Sistema -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <div class="kpi-icon kpi-cyan"><i class="bi bi-inbox-fill"></i></div>
                                <div>
                                    <div class="fs-4 fw-bold text-white"><?php echo $totalTickets; ?></div>
                                    <div class="small text-secondary fw-semibold">TOTAL TICKETS SISTEMA</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <div class="kpi-icon kpi-yellow"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="fs-4 fw-bold text-warning"><?php echo $totalAbiertos; ?></div>
                                    <div class="small text-secondary fw-semibold">ABIERTOS / SIN ATENDER</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <div class="kpi-icon kpi-blue"><i class="bi bi-gear-wide-connected"></i></div>
                                <div>
                                    <div class="fs-4 fw-bold text-info"><?php echo $totalEnProceso; ?></div>
                                    <div class="small text-secondary fw-semibold">EN ATENCIÓN / PROCESO</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <div class="kpi-icon kpi-green"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="fs-4 fw-bold text-success"><?php echo $totalResueltos; ?></div>
                                    <div class="small text-secondary fw-semibold">RESUELTOS / CERRADOS</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- SECCIÓN: TICKETS SIN VER / PENDIENTES DE ATENCIÓN -->
                    <!-- ============================================== -->
                    <div class="mb-5">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <div>
                                <h5 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-bell-fill text-warning"></i> Tickets Sin Ver / Pendientes de Atención
                                </h5>
                                <span class="text-secondary small">Requerimientos reportados que esperan ser atendidos por el equipo de sistemas</span>
                            </div>
                            <div>
                                <span class="badge <?php echo count($ticketsSinVer) > 0 ? 'bg-warning text-dark' : 'bg-success bg-opacity-20 text-success border border-success border-opacity-30'; ?> px-3 py-2 rounded-pill fw-bold">
                                    <i class="bi <?php echo count($ticketsSinVer) > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'; ?> me-1"></i>
                                    <?php echo count($ticketsSinVer); ?> <?php echo count($ticketsSinVer) === 1 ? 'Ticket Pendiente' : 'Tickets Pendientes'; ?>
                                </span>
                            </div>
                        </div>

                        <?php if (empty($ticketsSinVer)): ?>
                            <div class="p-4 rounded-4 text-center" style="background: linear-gradient(145deg, rgba(16, 185, 129, 0.08) 0%, rgba(9, 26, 50, 0.6) 100%); border: 1px solid rgba(16, 185, 129, 0.25); box-shadow: 0 10px 25px rgba(0,0,0,0.25);">
                                <div class="d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); color: #34d399; font-size: 1.6rem; border: 1px solid rgba(16, 185, 129, 0.4);">
                                    <i class="bi bi-check2-all"></i>
                                </div>
                                <h6 class="text-white fw-bold mb-1">¡Bandeja al día! No hay tickets sin ver ni pendientes de atención</h6>
                                <p class="text-secondary small mb-0">Todas las incidencias recibidas en tus áreas autorizadas han sido atendidas o están en proceso.</p>
                            </div>
                        <?php else: ?>
                            <div class="p-3 rounded-4" style="background: linear-gradient(145deg, rgba(13, 27, 48, 0.95) 0%, rgba(8, 18, 36, 0.98) 100%); border: 1px solid rgba(245, 158, 11, 0.3); box-shadow: 0 12px 32px rgba(0,0,0,0.45);">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                                                <th class="text-secondary small fw-bold">FOLIO</th>
                                                <th class="text-secondary small fw-bold">SUCURSAL</th>
                                                <th class="text-secondary small fw-bold">ÁREA TI</th>
                                                <th class="text-secondary small fw-bold">ASUNTO / INCIDENCIA</th>
                                                <th class="text-secondary small fw-bold">SOLICITANTE</th>
                                                <th class="text-secondary small fw-bold">FECHA</th>
                                                <th class="text-end text-secondary small fw-bold">ACCIÓN</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ticketsSinVer as $tsv): 
                                                $areaK = strtoupper(trim($tsv['area_sistemas'] ?? ''));
                                                $ainf = $AREAS_SISTEMAS[$areaK] ?? ($AREAS_SISTEMAS_CATALOGO[$areaK] ?? ['nombre' => $areaK, 'color' => '#38bdf8', 'bg' => 'rgba(56,189,248,0.15)', 'border' => 'rgba(56,189,248,0.3)', 'icono' => 'bi-tag']);
                                                $folioTsv = $tsv['folio'] ?? ('TK-' . $tsv['id']);
                                            ?>
                                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                                    <td>
                                                        <span class="badge bg-danger bg-opacity-20 text-danger border border-danger border-opacity-40 px-2 py-1 font-monospace fw-bold">
                                                            <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem; vertical-align: middle;"></i><?php echo htmlspecialchars($folioTsv); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge-agencia">
                                                            <i class="bi bi-building"></i> <?php echo htmlspecialchars($tsv['solicitante_agencia'] ?? 'Agencia'); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 10px; background: <?php echo $ainf['bg']; ?>; color: <?php echo $ainf['color']; ?>; border: 1px solid <?php echo $ainf['border']; ?>; display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="bi <?php echo $ainf['icono']; ?>"></i> <?php echo $ainf['nombre']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="fw-bold text-white text-truncate" style="max-width: 280px;" title="<?php echo htmlspecialchars($tsv['titulo'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($tsv['titulo'] ?? 'Sin título'); ?>
                                                        </div>
                                                        <div class="text-secondary small text-truncate" style="max-width: 280px;">
                                                            <?php echo htmlspecialchars(mb_substr($tsv['descripcion'] ?? '', 0, 60)) . '...'; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-white small fw-semibold"><?php echo htmlspecialchars($tsv['solicitante_nombre'] ?? 'Usuario'); ?></div>
                                                        <?php if (!empty($tsv['solicitante_usuario'])): ?>
                                                            <span class="text-secondary font-monospace" style="font-size: 0.75rem;">@<?php echo htmlspecialchars($tsv['solicitante_usuario']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="text-secondary small font-monospace"><?php echo date('d/m/Y H:i', strtotime($tsv['creado_en'] ?? 'now')); ?></span>
                                                    </td>
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-warning btn-sm fw-bold rounded-pill px-3 shadow-sm text-dark d-inline-flex align-items-center gap-1" onclick='abrirModalAtender(<?php echo json_encode($tsv); ?>)'>
                                                            <i class="bi bi-headset"></i> Atender
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ============================================== -->
                    <!-- GALERÍA 3D COVERFLOW DE AGENCIAS CONECTADAS   -->
                    <!-- ============================================== -->
                    <div class="mb-5">
                        <!-- Barra de Control y Título de la Galería 3D -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <div>
                                <h5 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-boxes text-info"></i> Galería 3D de Sedes Operativas
                                </h5>
                                <span class="text-secondary small">Visualizador interactivo 3D. Haz clic en una tarjeta o usa las flechas para explorar el historial de cada agencia.</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span id="coverflowContadorBadge" class="badge bg-dark text-info border border-info border-opacity-30 rounded-pill px-3 py-2 font-monospace">
                                    Agencia 1 de <?php echo count($agenciasList); ?>
                                </span>
                                <button type="button" id="btnToggleAutoCoverflow" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 fw-bold" onclick="toggleAutoCoverflow()" title="Pausar o reanudar rotación automática">
                                    <i class="bi bi-pause-fill" id="iconAutoCoverflow"></i> <span id="txtAutoCoverflow">Pausar</span>
                                </button>
                            </div>
                        </div>

                        <!-- Escenario 3D Coverflow -->
                        <div class="coverflow-3d-wrapper">
                            <div class="coverflow-3d-stage" id="coverflowStage">
                                <?php 
                                $cardIdx = 0;
                                foreach ($agenciasList as $nomAg => $agInfo): 
                                    $posClass = ($cardIdx === 0) ? 'pos-center' : (($cardIdx === 1) ? 'pos-right' : 'pos-hidden-right');
                                ?>
                                    <div class="coverflow-card <?php echo $posClass; ?>" id="cardCoverflow_<?php echo $cardIdx; ?>" onclick="clickCard3D(<?php echo $cardIdx; ?>, '<?php echo htmlspecialchars(addslashes($nomAg)); ?>')">
                                        <!-- Header de Tarjeta -->
                                        <div>
                                            <div class="d-flex align-items-center justify-content-between mb-3">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div style="width: 44px; height: 44px; border-radius: 12px; background: <?php echo $agInfo['color']; ?>22; color: <?php echo $agInfo['color']; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; border: 1px solid <?php echo $agInfo['color']; ?>44; box-shadow: 0 0 12px <?php echo $agInfo['color']; ?>33;">
                                                        <i class="bi <?php echo $agInfo['icono']; ?>"></i>
                                                    </div>
                                                    <div>
                                                        <span class="text-secondary small fw-bold text-uppercase d-block" style="letter-spacing: 1px; font-size: 0.68rem;">Sede Conectada</span>
                                                        <h4 class="fw-bold text-white mb-0 text-truncate" style="max-width: 170px;" title="<?php echo htmlspecialchars($nomAg); ?>"><?php echo htmlspecialchars($nomAg); ?></h4>
                                                    </div>
                                                </div>
                                                <div>
                                                    <?php if ($agInfo['abiertos'] > 0): ?>
                                                        <span class="badge bg-danger rounded-pill px-2 py-1 small">
                                                            <i class="bi bi-exclamation-circle-fill"></i> <?php echo $agInfo['abiertos']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-30 rounded-pill px-2 py-1 small">
                                                            <i class="bi bi-check2"></i> Al Día
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <span class="font-monospace small px-2 py-1 rounded text-info" style="background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.25); font-size: 0.75rem;">
                                                    <i class="bi bi-link-45deg me-1"></i><?php echo htmlspecialchars($agInfo['cpanel']); ?>
                                                </span>
                                            </div>

                                            <!-- Métricas de Tickets -->
                                            <div class="row g-2 mb-3">
                                                <div class="col-6">
                                                    <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold text-white"><?php echo $agInfo['total']; ?></div>
                                                        <div class="text-secondary small" style="font-size: 0.65rem; letter-spacing: 0.5px;">TOTALES</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div style="background: <?php echo $agInfo['abiertos'] > 0 ? 'rgba(239, 68, 68, 0.15)' : 'rgba(255,255,255,0.04)'; ?>; border: 1px solid <?php echo $agInfo['abiertos'] > 0 ? 'rgba(239, 68, 68, 0.4)' : 'rgba(255,255,255,0.08)'; ?>; border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold <?php echo $agInfo['abiertos'] > 0 ? 'text-danger' : 'text-warning'; ?>"><?php echo $agInfo['abiertos']; ?></div>
                                                        <div class="<?php echo $agInfo['abiertos'] > 0 ? 'text-danger' : 'text-warning'; ?> small fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">SIN ATENDER</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold text-info"><?php echo $agInfo['en_proceso']; ?></div>
                                                        <div class="text-info small" style="font-size: 0.65rem; letter-spacing: 0.5px;">EN PROCESO</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 8px 10px; text-align: center;">
                                                        <div class="fs-4 fw-bold text-success"><?php echo $agInfo['resueltos']; ?></div>
                                                        <div class="text-success small" style="font-size: 0.65rem; letter-spacing: 0.5px;">RESUELTOS</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Snippet Último Requerimiento -->
                                            <div class="p-2 rounded mb-2" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.05); font-size: 0.72rem; min-height: 48px;">
                                                <?php if ($agInfo['ultimo_ticket']): ?>
                                                    <div class="text-secondary text-truncate" title="<?php echo htmlspecialchars($agInfo['ultimo_ticket']['titulo'] ?? ''); ?>">
                                                        <i class="bi bi-clock-history me-1 text-info"></i>
                                                        <strong class="text-white"><?php echo htmlspecialchars($agInfo['ultimo_ticket']['folio'] ?? ('TK-'.$agInfo['ultimo_ticket']['id'])); ?></strong>:
                                                        <?php echo htmlspecialchars($agInfo['ultimo_ticket']['titulo'] ?? ''); ?>
                                                    </div>
                                                    <div class="text-secondary small" style="font-size: 0.68rem;">
                                                        <?php echo date('d/m/Y H:i', strtotime($agInfo['ultimo_ticket']['creado_en'])); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-secondary fst-italic pt-1">
                                                        <i class="bi bi-info-circle me-1"></i> Sin incidencias registradas.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Botón de Acción -->
                                        <div>
                                            <button type="button" class="btn btn-sm w-100 rounded-pill py-2 fw-bold text-white d-flex align-items-center justify-content-center gap-2" style="background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%); border: 1px solid rgba(56, 189, 248, 0.4); box-shadow: 0 4px 15px rgba(2, 132, 199, 0.35);" onclick="event.stopPropagation(); mostrarHistorialAgencia('<?php echo htmlspecialchars(addslashes($nomAg)); ?>')">
                                                <span>Ver Historial de Tickets</span>
                                                <i class="bi bi-arrow-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php 
                                    $cardIdx++;
                                endforeach; 
                                ?>
                            </div>

                            <!-- Controles Circulares Inferiores (inspirados en la referencia) -->
                            <div class="coverflow-controls">
                                <button type="button" class="coverflow-btn-circle" onclick="cambiar3DCoverflow(-1)" title="Agencia Anterior" aria-label="Anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <button type="button" class="coverflow-btn-circle" onclick="cambiar3DCoverflow(1)" title="Agencia Siguiente" aria-label="Siguiente">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>

                            <!-- Indicadores Dots -->
                            <div class="coverflow-dots">
                                <?php for ($d = 0; $d < count($agenciasList); $d++): ?>
                                    <div class="coverflow-dot <?php echo $d === 0 ? 'active' : ''; ?>" id="coverflowDot_<?php echo $d; ?>" onclick="irA3DCoverflow(<?php echo $d; ?>)" title="Ir a agencia <?php echo ($d + 1); ?>"></div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>

    <!-- ======================================================= -->
    <!-- VISTA 2: HISTORIAL DE TICKETS DE LA AGENCIA SELECCIONADA -->
    <!-- ======================================================= -->
    <div id="vistaHistorialTickets" style="display: none;">
        <!-- Botón de Retorno y Título Dinámico -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-3 border-bottom border-secondary border-opacity-20">
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-secondary text-white rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2" onclick="mostrarVistaAgencias()">
                    <i class="bi bi-arrow-left"></i> <span>Volver a Selección de Agencias</span>
                </button>
                <div>
                    <h4 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history text-info"></i>
                        <span id="tituloHistorialAgencia">Historial de Tickets</span>
                    </h4>
                    <span class="text-secondary small" id="subtituloHistorialAgencia">Auditoría detallada e incidencias reportadas</span>
                </div>
            </div>

            <!-- Mini Recuadros de Métricas de la Agencia Seleccionada -->
            <div class="d-flex flex-wrap gap-2" id="kpisAgenciaSeleccionada">
                <!-- Se actualiza reactivamente vía JS -->
            </div>
        </div>

        <!-- Barra de Filtro Rápido entre Agencias (Pills) -->
        <div class="agencies-monitor-bar mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-funnel-fill text-info"></i>
                    <span class="fw-bold text-white small text-uppercase">Cambiar Agencia:</span>
                </div>
                <button type="button" class="btn btn-link text-info text-decoration-none btn-sm p-0" onclick="mostrarVistaAgencias()">
                    <i class="bi bi-grid-3x3-gap-fill me-1"></i> Ver todas en cuadrícula
                </button>
            </div>
            <div class="d-flex flex-wrap gap-2 pt-1" id="agenciasFilterContainer">
                <button class="agency-pill-btn active" data-agencia="TODAS" onclick="filtrarPorAgencia('TODAS', this)">
                    <i class="bi bi-globe2 text-info"></i>
                    <span>Todas las Agencias</span>
                    <span class="pending-count-badge bg-primary text-white"><?php echo $totalTickets; ?></span>
                </button>
                <?php foreach ($agenciasList as $nomAg => $agInfo): ?>
                    <button class="agency-pill-btn" data-agencia="<?php echo htmlspecialchars($nomAg); ?>" onclick="filtrarPorAgencia('<?php echo htmlspecialchars(addslashes($nomAg)); ?>', this)">
                        <i class="bi <?php echo $agInfo['icono']; ?> text-warning"></i>
                        <span><?php echo htmlspecialchars($nomAg); ?></span>
                        <?php if ($agInfo['abiertos'] > 0): ?>
                            <span class="pending-count-badge bg-danger text-white"><?php echo $agInfo['abiertos']; ?> pend.</span>
                        <?php else: ?>
                            <span class="pending-count-badge bg-secondary text-light"><?php echo $agInfo['total']; ?> tot.</span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Barra de Búsqueda y Acciones de la Mesa de Ayuda -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <!-- Buscador -->
            <div class="search-box-wrapper flex-grow-1" style="max-width: 500px;">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="busquedaInput" class="search-input" placeholder="Buscar por folio, requerimiento, solicitante o técnico..." oninput="filtrarTickets()">
                <button type="button" class="btn btn-link btn-sm text-secondary p-0" onclick="limpiarBusqueda()"><i class="bi bi-x-circle-fill"></i></button>
            </div>

            <!-- Botones de Acción -->
            <div class="d-flex align-items-center gap-2">
                <?php if ($esAdmin && $totalTickets > 0): ?>
                    <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar TODOS los tickets registrados y reiniciar el contador a 0?');" style="display:inline;">
                        <input type="hidden" name="accion" value="vaciar_todos_tickets">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-1" title="Eliminar todos los tickets">
                            <i class="bi bi-trash3-fill"></i> <span>Vaciar Tickets</span>
                        </button>
                    </form>
                <?php endif; ?>
                <a href="tickets.php?export=excel" id="btnExportarExcel" class="btn btn-success btn-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-excel-fill"></i> <span>Exportar a Excel</span>
                </a>
                <button class="btn btn-primary btn-sm rounded-3 px-3 py-2 fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalNuevoTicket">
                    <i class="bi bi-plus-circle-fill"></i> <span>+ Registrar Ticket Manual</span>
                </button>
            </div>
        </div>

        <!-- Chips de Filtrado por Área de Sistemas -->
        <div class="d-flex flex-wrap gap-2 mb-4" id="areasFilterContainer">
            <div class="area-pill active" onclick="filtrarPorArea('TODAS', this)">
                <i class="bi bi-grid-fill"></i> Todas las Áreas
            </div>
            <?php foreach ($AREAS_SISTEMAS as $areaKey => $areaInfo): ?>
                <div class="area-pill" onclick="filtrarPorArea('<?php echo $areaKey; ?>', this)">
                    <i class="bi <?php echo $areaInfo['icono']; ?>"></i> <?php echo $areaInfo['nombre']; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <!-- ============================================== -->
    <!-- 5. TABLA DE TICKETS - VISTA DE AGENTE TI -->
    <!-- ============================================== -->
    <div class="tickets-table-card">
        <div class="table-responsive">
            <table class="table align-middle" id="tablaTickets">
                <thead>
                    <tr>
                        <th style="width: 130px;">FOLIO & FECHA</th>
                        <th style="width: 170px;">AGENCIA ORIGEN</th>
                        <th style="width: 140px;">ÁREA DESTINO</th>
                        <th>REQUERIMIENTO / ASUNTO</th>
                        <th style="width: 110px;">PRIORIDAD</th>
                        <th style="width: 120px;">ESTADO</th>
                        <th style="width: 160px;">SOLICITANTE</th>
                        <th style="width: 160px;">AGENTE ASIGNADO</th>
                        <th style="width: 140px;" class="text-center">ACCIONES</th>
                    </tr>
                </thead>
                <tbody id="ticketsTbody">
                    <?php if (empty($tickets)): ?>
                        <tr id="filaSinTickets">
                            <td colspan="9" class="text-center py-5">
                                <i class="bi bi-inbox text-secondary display-3 d-block mb-3"></i>
                                <h5 class="fw-bold text-white mb-1">No hay tickets registrados en el sistema</h5>
                                <p class="small text-secondary mb-0">Los tickets enviados desde los cPanels de las agencias o registrados por llamada aparecerán aquí.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): 
                            $folio = $t['folio'] ?: ('TK-' . date('Y') . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT));
                            $area = strtoupper($t['area_sistemas'] ?? '');
                            $infoArea = $AREAS_SISTEMAS[$area] ?? ['nombre' => $area, 'icono' => 'bi-ticket-detailed', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.15)', 'border' => 'rgba(56, 189, 248, 0.3)'];
                            $prio = ucfirst(strtolower($t['prioridad'] ?? 'Media'));
                            $estado = ucfirst(strtolower($t['estado'] ?? 'Abierto'));
                            $agenciaTicket = normalizarAgencia($t['solicitante_agencia'] ?? 'Desconocida');
                            $asignado = $t['asignado_a'] ?? '';

                            $prioClass = 'badge-prio-media';
                            if ($prio === 'Urgente') $prioClass = 'badge-prio-urgente';
                            elseif ($prio === 'Alta') $prioClass = 'badge-prio-alta';
                            elseif ($prio === 'Baja') $prioClass = 'badge-prio-baja';

                            $statusClass = 'badge-status-abierto';
                            if ($estado === 'En proceso') $statusClass = 'badge-status-proceso';
                            elseif ($estado === 'Resuelto') $statusClass = 'badge-status-resuelto';
                            elseif ($estado === 'Cerrado') $statusClass = 'badge-status-cerrado';
                        ?>
                            <tr class="ticket-row" 
                                data-id="<?php echo $t['id']; ?>"
                                data-folio="<?php echo htmlspecialchars($folio); ?>"
                                data-area="<?php echo htmlspecialchars($area); ?>"
                                data-agencia="<?php echo htmlspecialchars($agenciaTicket); ?>"
                                data-estado="<?php echo htmlspecialchars($estado); ?>"
                                data-titulo="<?php echo htmlspecialchars($t['titulo']); ?>"
                                data-solicitante="<?php echo htmlspecialchars(($t['solicitante_nombre'] ?? '') . ' ' . ($t['solicitante_usuario'] ?? '')); ?>"
                                data-asignado="<?php echo htmlspecialchars($asignado); ?>">
                                
                                <td>
                                    <span class="badge-folio"><?php echo htmlspecialchars($folio); ?></span>
                                    <div class="small text-secondary mt-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-clock me-1"></i><?php echo date('d/m/Y H:i', strtotime($t['creado_en'])); ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge-agencia">
                                        <i class="bi bi-building"></i> <?php echo htmlspecialchars($agenciaTicket); ?>
                                    </span>
                                </td>

                                <td>
                                    <span style="font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 12px; background: <?php echo $infoArea['bg']; ?>; color: <?php echo $infoArea['color']; ?>; border: 1px solid <?php echo $infoArea['border']; ?>; display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="bi <?php echo $infoArea['icono']; ?>"></i> <?php echo $infoArea['nombre']; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-bold text-white mb-1"><?php echo htmlspecialchars($t['titulo']); ?></div>
                                    <div class="text-secondary small text-truncate" style="max-width: 320px;">
                                        <?php echo htmlspecialchars(mb_strimwidth($t['descripcion'], 0, 75, '...')); ?>
                                    </div>
                                    <?php if (!empty($t['archivo_adjunto'])): ?>
                                        <span class="badge bg-dark border border-secondary text-info mt-1" style="font-size: 0.7rem;">
                                            <i class="bi bi-paperclip me-1"></i> Evidencia adjunta
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge rounded-pill <?php echo $prioClass; ?> px-2 py-1" style="font-size: 0.75rem;">
                                        <?php echo $prio; ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge rounded-pill <?php echo $statusClass; ?> px-2 py-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 0.45rem;"></i> <?php echo $estado; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold text-white d-flex align-items-center gap-1.5 flex-wrap">
                                        <span><?php echo htmlspecialchars($t['solicitante_nombre'] ?? 'Usuario'); ?></span>
                                        <?php if (!empty($t['solicitante_usuario'])): ?>
                                            <span class="badge bg-dark border text-info font-monospace py-0 px-1" style="font-size: 0.68rem;" title="Usuario del sistema: <?php echo htmlspecialchars($t['solicitante_usuario']); ?>">
                                                @<?php echo htmlspecialchars($t['solicitante_usuario']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-secondary small" style="font-size: 0.73rem;">
                                        <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($t['solicitante_email'] ?? '---'); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($asignado)): ?>
                                        <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25 px-2 py-1">
                                            <i class="bi bi-person-fill-check me-1"></i> <?php echo htmlspecialchars($asignado); ?>
                                        </span>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="accion" value="autoasignar_ticket">
                                            <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-outline-warning btn-sm rounded-pill px-2 py-0" style="font-size: 0.75rem;" title="Tomar este ticket y asignármelo">
                                                <i class="bi bi-hand-index-thumb"></i> Asignarme
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <button class="btn btn-sm btn-info rounded-3 px-2 py-1 text-dark fw-bold" onclick="abrirModalAtender(<?php echo htmlspecialchars(json_encode($t)); ?>)" title="Gestionar y resolver ticket">
                                        <i class="bi bi-headset me-1"></i> Atender
                                    </button>
                                    <?php if ($esAdmin): ?>
                                        <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar permanentemente este ticket?');" style="display:inline;">
                                            <input type="hidden" name="accion" value="eliminar_ticket">
                                            <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-1" title="Eliminar ticket">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div> <!-- /#vistaHistorialTickets -->
    </div> <!-- /#seccionTab_tickets -->

    <!-- ======================================================= -->
    <!-- SECCIÓN 2: CITAS Y AGENDA DE SOPORTE TI -->
    <!-- ======================================================= -->
    <div id="seccionTab_citas" class="tab-seccion" style="display: none;">
        <!-- Encabezado de Citas -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-30 rounded-pill px-3 py-1 fw-bold">
                        <i class="bi bi-calendar2-check-fill me-1"></i> Agenda de Soporte
                    </span>
                    <span class="text-secondary small">&bull; Visitas Técnicas y Mantenimientos</span>
                </div>
                <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-event text-success"></i> Citas y Visitas Programadas
                </h3>
                <p class="text-secondary mb-0 small">
                    Coordinación de visitas presenciales, mantenimientos preventivos y soporte técnico en sucursales.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success rounded-3 px-3 py-2 fw-bold d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNuevaCita">
                    <i class="bi bi-calendar-plus-fill"></i> <span>+ Agendar Nueva Cita</span>
                </button>
            </div>
        </div>

        <!-- KPIs de Citas -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-cyan"><i class="bi bi-calendar3"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-white"><?php echo $totalCitas; ?></div>
                        <div class="small text-secondary fw-semibold">TOTAL DE CITAS</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-green"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-success"><?php echo $totalCitasProgramadas; ?></div>
                        <div class="small text-secondary fw-semibold">PROGRAMADAS</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-yellow"><i class="bi bi-tools"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-warning"><?php echo $totalCitasEnCurso; ?></div>
                        <div class="small text-secondary fw-semibold">EN ATENCIÓN</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-blue"><i class="bi bi-check-circle-fill"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-info"><?php echo $totalCitasRealizadas; ?></div>
                        <div class="small text-secondary fw-semibold">REALIZADAS</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra de Filtros y Búsqueda de Citas -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3 p-3 rounded-3" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
            <div class="d-flex align-items-center gap-2 overflow-x-auto" id="filtrosEstadoCitas">
                <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 active filter-cita-pill" onclick="filtrarCitasPorEstado('TODAS', this)">Todas (<?php echo $totalCitas; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 filter-cita-pill" onclick="filtrarCitasPorEstado('Programada', this)">Programadas (<?php echo $totalCitasProgramadas; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3 filter-cita-pill" onclick="filtrarCitasPorEstado('En Curso', this)">En Curso (<?php echo $totalCitasEnCurso; ?>)</button>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 filter-cita-pill" onclick="filtrarCitasPorEstado('Realizada', this)">Realizadas (<?php echo $totalCitasRealizadas; ?>)</button>
            </div>
            <div class="search-box-wrapper" style="min-width: 280px;">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="busquedaCitasInput" class="search-input" placeholder="Buscar cita por sucursal, técnico, asunto..." oninput="filtrarCitasTexto()">
            </div>
        </div>

        <!-- Tabla de Citas -->
        <div class="tickets-table-card mb-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 14%;">Folio / Fecha</th>
                            <th style="width: 16%;">Agencia / Sucursal</th>
                            <th style="width: 14%;">Tipo / Área</th>
                            <th style="width: 24%;">Asunto / Objetivo</th>
                            <th style="width: 14%;">Técnico Asignado</th>
                            <th style="width: 10%;">Estado</th>
                            <th style="width: 8%; text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyCitas">
                        <?php if (empty($citas)): ?>
                            <tr id="filaSinCitas">
                                <td colspan="7" class="text-center py-5 text-secondary">
                                    <i class="bi bi-calendar-x fs-1 d-block mb-2 text-muted"></i>
                                    <div class="fw-semibold">No hay citas de soporte técnico registradas.</div>
                                    <div class="small text-muted mt-1">Presiona <strong>"+ Agendar Nueva Cita"</strong> para programar una visita técnica a una agencia.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($citas as $c): 
                                $estC = ucfirst(strtolower($c['estado'] ?? 'Programada'));
                                $badgeClassC = 'badge-status-proceso';
                                if ($estC === 'Programada') $badgeClassC = 'badge-status-abierto';
                                elseif ($estC === 'En curso' || $estC === 'En Curso') $badgeClassC = 'badge-status-proceso';
                                elseif ($estC === 'Realizada' || $estC === 'Completada') $badgeClassC = 'badge-status-resuelto';
                                elseif ($estC === 'Cancelada') $badgeClassC = 'badge-prio-urgente';
                            ?>
                                <tr class="cita-row"
                                    data-id="<?php echo $c['id']; ?>"
                                    data-estado="<?php echo htmlspecialchars($estC); ?>"
                                    data-agencia="<?php echo htmlspecialchars($c['agencia'] ?? ''); ?>"
                                    data-asunto="<?php echo htmlspecialchars($c['asunto'] ?? ''); ?>"
                                    data-tecnico="<?php echo htmlspecialchars($c['tecnico_asignado'] ?? ''); ?>"
                                    data-folio="<?php echo htmlspecialchars($c['folio'] ?? ''); ?>">
                                    <td>
                                        <span class="badge-folio"><?php echo htmlspecialchars($c['folio'] ?? ('CTA-' . $c['id'])); ?></span>
                                        <div class="small text-secondary mt-1" style="font-size: 0.72rem;">
                                            <i class="bi bi-calendar-event me-1"></i><?php echo date('d/m/Y', strtotime($c['fecha_cita'])); ?> &bull; <?php echo htmlspecialchars($c['hora_cita']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-agencia">
                                            <i class="bi bi-building"></i> <?php echo htmlspecialchars($c['agencia'] ?? 'General'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-white small"><?php echo htmlspecialchars($c['tipo_cita'] ?? 'Presencial'); ?></div>
                                        <span class="badge bg-dark border text-info" style="font-size: 0.68rem;"><?php echo htmlspecialchars($c['area_sistemas'] ?? 'INFRAESTRUCTURA'); ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-white mb-1"><?php echo htmlspecialchars($c['asunto']); ?></div>
                                        <div class="text-secondary small text-truncate" style="max-width: 320px;">
                                            <?php echo htmlspecialchars($c['descripcion'] ?? 'Sin descripción adicional'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-white small fw-semibold">
                                            <i class="bi bi-person-gear text-info me-1"></i><?php echo htmlspecialchars($c['tecnico_asignado'] ?? 'Sin asignar'); ?>
                                        </div>
                                        <div class="text-secondary small" style="font-size: 0.72rem;">
                                            Solicita: <?php echo htmlspecialchars($c['solicitante_nombre'] ?? '---'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?php echo $badgeClassC; ?> px-2.5 py-1" style="font-size: 0.75rem;">
                                            <?php echo $estC; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1.5">
                                            <button type="button" class="btn btn-outline-info btn-sm rounded-3 py-1 px-2" onclick="abrirModalActualizarCita(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)" title="Atender / Modificar Cita">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <?php if ($esAdmin): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que deseas eliminar esta cita?');">
                                                    <input type="hidden" name="accion" value="eliminar_cita">
                                                    <input type="hidden" name="cita_id" value="<?php echo $c['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm border-0 py-1 px-2" title="Eliminar Cita">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
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
    </div> <!-- /#seccionTab_citas -->

    <!-- ======================================================= -->
    <!-- SECCIÓN 3: ESTADÍSTICAS Y ANALÍTICA TI -->
    <!-- ======================================================= -->
    <div id="seccionTab_estadisticas" class="tab-seccion" style="display: none;">
        <!-- Encabezado de Estadísticas -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary bg-opacity-20 text-info border border-info border-opacity-30 rounded-pill px-3 py-1 fw-bold">
                        <i class="bi bi-graph-up-arrow me-1"></i> Inteligencia Operativa
                    </span>
                    <span class="text-secondary small">&bull; Rendimiento de Mesa de Ayuda</span>
                </div>
                <h3 class="fw-bold text-white mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-bar-chart-line text-primary"></i> Métricas y Estadísticas de Sistemas
                </h3>
                <p class="text-secondary mb-0 small">
                    Análisis consolidado de incidencias por área, volumen por agencia y eficacia en tiempo de atención.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="tickets.php?export=excel" class="btn btn-outline-success rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-excel-fill"></i> <span>Exportar Datos (Excel)</span>
                </a>
            </div>
        </div>

        <!-- KPIs Ejecutivos de Estadísticas -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-cyan"><i class="bi bi-collection-fill"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-white"><?php echo $totalTickets; ?></div>
                        <div class="small text-secondary fw-semibold">TOTAL INCIDENCIAS</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-green"><i class="bi bi-shield-check"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-success"><?php echo $tasaResolucion; ?>%</div>
                        <div class="small text-secondary fw-semibold">TASA DE RESOLUCIÓN</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-yellow"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-warning"><?php echo $totalAbiertos; ?></div>
                        <div class="small text-secondary fw-semibold">TICKETS SIN ATENDER</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-blue"><i class="bi bi-calendar2-week"></i></div>
                    <div>
                        <div class="fs-4 fw-bold text-info"><?php echo $totalCitas; ?></div>
                        <div class="small text-secondary fw-semibold">CITAS Y VISITAS TI</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fila de Gráficas de Rendimiento (Chart.js) -->
        <div class="row g-4 mb-4">
            <!-- Gráfica 1: Tickets por Estado -->
            <div class="col-lg-4">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-pie-chart-fill text-info me-2"></i> Estado de Requerimientos</h6>
                        <span class="badge bg-dark text-secondary border border-secondary" style="font-size: 0.7rem;">Tiempo Real</span>
                    </div>
                    <div style="height: 250px; position: relative;">
                        <canvas id="chartTicketsEstado"></canvas>
                    </div>
                </div>
            </div>

            <!-- Gráfica 2: Tickets por Área -->
            <div class="col-lg-4">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-bar-chart-fill text-warning me-2"></i> Carga por Área de Sistemas</h6>
                        <span class="badge bg-dark text-info border border-info border-opacity-25" style="font-size: 0.7rem;"><?php echo count($AREAS_SISTEMAS); ?> <?php echo count($AREAS_SISTEMAS) === 1 ? 'Área Asignada' : 'Áreas Asignadas'; ?></span>
                    </div>
                    <div style="height: 250px; position: relative;">
                        <canvas id="chartTicketsArea"></canvas>
                    </div>
                </div>
            </div>

            <!-- Gráfica 3: Tickets por Agencia -->
            <div class="col-lg-4">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-buildings-fill text-success me-2"></i> Demanda por Sucursal</h6>
                        <span class="badge bg-dark text-secondary border border-secondary" style="font-size: 0.7rem;">Agencias</span>
                    </div>
                    <div style="height: 250px; position: relative;">
                        <canvas id="chartTicketsAgencia"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla Analítica de Áreas y Agencias -->
        <div class="row g-4 mb-4">
            <!-- Desglose por Área -->
            <div class="col-lg-6">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-white mb-0"><i class="bi bi-diagram-3-fill text-info me-2"></i> Desglose Detallado por Área TI</h6>
                        <span class="badge bg-dark text-secondary border border-secondary" style="font-size: 0.7rem;"><?php echo count($AREAS_SISTEMAS); ?> Visibles</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Área Asignada</th>
                                    <th class="text-center">Tickets</th>
                                    <th>% del Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($AREAS_SISTEMAS)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-secondary py-3">No tienes áreas de sistemas asignadas a tu usuario.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($AREAS_SISTEMAS as $ak => $ainf): 
                                        $cnt = $conteoPorArea[$ak] ?? 0;
                                        $pct = $totalTickets > 0 ? round(($cnt / $totalTickets) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <span style="font-size: 0.78rem; font-weight: 700; padding: 4px 10px; border-radius: 12px; background: <?php echo $ainf['bg']; ?>; color: <?php echo $ainf['color']; ?>; border: 1px solid <?php echo $ainf['border']; ?>; display: inline-flex; align-items: center; gap: 6px;">
                                                    <i class="bi <?php echo $ainf['icono']; ?>"></i> <?php echo $ainf['nombre']; ?>
                                                </span>
                                            </td>
                                            <td class="text-center fw-bold text-white"><?php echo $cnt; ?></td>
                                            <td style="width: 40%;">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 6px; background: rgba(255,255,255,0.08);">
                                                        <div class="progress-bar" style="width: <?php echo $pct; ?>%; background: <?php echo $ainf['color']; ?>;"></div>
                                                    </div>
                                                    <span class="small font-monospace text-secondary" style="font-size: 0.75rem; width: 45px;"><?php echo $pct; ?>%</span>
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

            <!-- Desglose por Agencia -->
            <div class="col-lg-6">
                <div class="p-3 rounded-4 h-100" style="background: #091a32; border: 1px solid rgba(255,255,255,0.08);">
                    <h6 class="fw-bold text-white mb-3"><i class="bi bi-building-check text-success me-2"></i> Desglose por Agencia / Sucursal</h6>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center text-warning">Sin Atender</th>
                                    <th class="text-center text-success">Resueltos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agenciasList as $agN => $agD): ?>
                                    <tr>
                                        <td>
                                            <span class="badge-agencia"><i class="bi <?php echo $agD['icono']; ?>"></i> <?php echo htmlspecialchars($agN); ?></span>
                                        </td>
                                        <td class="text-center fw-bold text-white"><?php echo $agD['total']; ?></td>
                                        <td class="text-center fw-bold text-warning"><?php echo $agD['abiertos']; ?></td>
                                        <td class="text-center fw-bold text-success"><?php echo $agD['resueltos']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- /#seccionTab_estadisticas -->

</div> <!-- /.main-container -->
</div> <!-- /.app-content -->
</div> <!-- /.app-layout -->

<!-- ============================================== -->
<!-- MODAL: REGISTRAR TICKET MANUAL POR EL AGENTE -->
<!-- ============================================== -->
<div class="modal fade" id="modalNuevoTicket" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="crear_ticket">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                        <i class="bi bi-headset text-primary"></i> Registrar Ticket de Soporte (Mesa de Ayuda)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Agencia de Origen -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Agencia / Sucursal de Origen *</label>
                            <select name="solicitante_agencia" id="form_solicitante_agencia" class="form-select" required>
                                <?php foreach ($conteoPorAgencia as $nomAg => $c): ?>
                                    <option value="<?php echo htmlspecialchars($nomAg); ?>"><?php echo htmlspecialchars($nomAg); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Área de Sistemas -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Área Destino de Sistemas *</label>
                            <select name="area_sistemas" class="form-select" required>
                                <option value="" disabled selected>-- Selecciona el Área --</option>
                                <?php foreach ($AREAS_SISTEMAS as $areaKey => $areaInfo): ?>
                                    <option value="<?php echo $areaKey; ?>"><?php echo $areaInfo['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Prioridad -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Nivel de Prioridad *</label>
                            <select name="prioridad" class="form-select" required>
                                <option value="Baja">Baja (Requerimiento programado)</option>
                                <option value="Media" selected>Media (Operación normal)</option>
                                <option value="Alta">Alta (Afecta operación de usuario)</option>
                                <option value="Urgente">Urgente (Sistema o red caída)</option>
                            </select>
                        </div>

                        <!-- Agente Asignado -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Asignar a Técnico / Agente</label>
                            <input type="text" name="asignado_a" class="form-control" value="<?php echo htmlspecialchars($nombreUsuario); ?>" placeholder="Nombre del especialista de TI">
                        </div>

                        <!-- Solicitante -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Nombre del Usuario / Solicitante *</label>
                            <input type="text" name="solicitante_nombre" class="form-control" placeholder="Ej: Lic. Carlos Ramírez" required>
                        </div>

                        <!-- Email Solicitante -->
                        <div class="col-md-6">
                            <label class="form-label small text-secondary fw-semibold">Correo o Teléfono del Solicitante</label>
                            <input type="text" name="solicitante_email" class="form-control" placeholder="carlos@divolavilla.com o Ext. 104">
                        </div>

                        <!-- Título -->
                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Título / Asunto Breve *</label>
                            <input type="text" name="titulo" class="form-control" placeholder="Ej: No conecta a SQL Server en taller o Falla switch principal" required>
                        </div>

                        <!-- Descripción -->
                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Descripción Detallada del Requerimiento *</label>
                            <textarea name="descripcion" class="form-control" rows="4" placeholder="Describe los síntomas, equipo afectado y datos importantes del caso..." required></textarea>
                        </div>

                        <!-- Archivo de Evidencia -->
                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Adjuntar Captura o Archivo (Opcional)</label>
                            <input type="file" name="archivo_adjunto" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold">
                        <i class="bi bi-save2 me-1"></i> Registrar Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- MODAL: ATENCIÓN Y SEGUIMIENTO POR EL AGENTE -->
<!-- ============================================== -->
<div class="modal fade" id="modalAtenderTicket" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="actualizar_ticket">
                <input type="hidden" name="ticket_id" id="atenderTicketId">

                <div class="modal-header">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge-folio fs-6" id="atenderFolio">TK-2026-0000</span>
                            <span class="badge-agencia" id="atenderAgencia">VW Divol La Villa</span>
                        </div>
                        <h5 class="modal-title fw-bold mt-2" id="atenderTitulo">Detalle del Ticket</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Solicitud Original -->
                    <div class="p-3 rounded-3 mb-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08);">
                        <div class="d-flex justify-content-between small text-secondary mb-2">
                            <div><i class="bi bi-person me-1"></i> Solicitante: <strong class="text-white" id="atenderSolicitante">---</strong> (<span id="atenderEmail">---</span>)</div>
                            <div><i class="bi bi-diagram-3 me-1"></i> Área: <strong class="text-info" id="atenderArea">---</strong></div>
                        </div>
                        <div class="text-light small" id="atenderDescripcion" style="white-space: pre-line; line-height: 1.6;"></div>

                        <div id="contenedorAdjunto" class="mt-3 pt-2 border-top border-secondary border-opacity-25 d-none">
                            <span class="small text-secondary">Archivo de Evidencia: </span>
                            <a href="#" id="linkAdjunto" target="_blank" class="btn btn-outline-info btn-sm rounded-pill py-0 px-3">
                                <i class="bi bi-paperclip me-1"></i> Ver / Descargar Evidencia
                            </a>
                        </div>
                    </div>

                    <!-- Panel de Gestión del Agente -->
                    <h6 class="fw-bold text-info mb-3"><i class="bi bi-pencil-square me-1"></i> Gestión y Resolución del Agente TI</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Estado del Ticket *</label>
                            <select name="estado" id="atenderEstado" class="form-select" required>
                                <option value="Abierto">Abierto (En espera)</option>
                                <option value="En Proceso">En Proceso (En atención)</option>
                                <option value="Resuelto">Resuelto (Trabajo terminado)</option>
                                <option value="Cerrado">Cerrado (Finalizado)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Prioridad</label>
                            <select name="prioridad" id="atenderPrioridad" class="form-select">
                                <option value="Baja">Baja</option>
                                <option value="Media">Media</option>
                                <option value="Alta">Alta</option>
                                <option value="Urgente">Urgente</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Agente / Especialista Asignado</label>
                            <input type="text" name="asignado_a" id="atenderAsignado" class="form-control" placeholder="Técnico a cargo">
                        </div>

                        <div class="col-12">
                            <label class="form-label small text-secondary fw-semibold">Bitácora / Notas de Resolución del Agente</label>
                            <textarea name="notas_resolucion" id="atenderNotas" class="form-control" rows="4" placeholder="Escribe aquí las acciones realizadas, diagnóstico, solución aplicada o motivo de cierre..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-info rounded-3 px-4 fw-bold text-dark">
                        <i class="bi bi-check2-circle me-1"></i> Guardar Cambios de Atención
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Nueva Cita Técnica -->
<div class="modal fade" id="modalNuevaCita" tabindex="-1" aria-labelledby="modalNuevaCitaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="background: #1e293b; color: #f8fafc; border-radius: 16px;">
            <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                <h5 class="modal-title fw-bold text-white" id="modalNuevaCitaLabel">
                    <i class="bi bi-calendar-plus text-primary me-2"></i> Agendar Nueva Cita Técnica / Soporte
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="tickets.php" method="POST">
                <input type="hidden" name="accion" value="agendar_cita">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Agencia o Sucursal Destino *</label>
                            <select name="agencia" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <option value="">-- Seleccionar Agencia --</option>
                                <?php foreach ($agenciasList as $nomAg => $infoAg): ?>
                                    <option value="<?php echo htmlspecialchars($nomAg); ?>"><?php echo htmlspecialchars($nomAg); ?></option>
                                <?php endforeach; ?>
                                <option value="Oficina Central Grupo Huerta">Oficina Central Grupo Huerta</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Tipo de Cita / Visita *</label>
                            <select name="tipo_cita" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <option value="Visita Técnica Presencial">Visita Técnica Presencial</option>
                                <option value="Mantenimiento Preventivo">Mantenimiento Preventivo</option>
                                <option value="Revisión de Red / Servidores">Revisión de Red / Servidores</option>
                                <option value="Reunión de Sistemas">Reunión de Sistemas</option>
                                <option value="Soporte Remoto Agendado">Soporte Remoto Agendado</option>
                                <option value="Auditoría Informática">Auditoría Informática</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Fecha de la Cita *</label>
                            <input type="date" name="fecha_cita" class="form-control bg-dark text-white border-secondary border-opacity-50" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Hora de la Cita</label>
                            <input type="time" name="hora_cita" class="form-control bg-dark text-white border-secondary border-opacity-50" value="10:00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Área de Sistemas *</label>
                            <select name="area_sistemas" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <?php foreach ($AREAS_SISTEMAS as $kArea => $ainfo): ?>
                                    <option value="<?php echo $kArea; ?>"><?php echo htmlspecialchars($ainfo['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Técnico / Ingeniero Asignado</label>
                            <input type="text" name="tecnico_asignado" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Ej. Ing. Daniel / Soporte Central" value="<?php echo htmlspecialchars($nombreUsuario); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Asunto de la Cita *</label>
                            <input type="text" name="asunto" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Ej. Mantenimiento general a enlace y switches" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Descripción detallada / Objetivos</label>
                            <textarea name="descripcion" class="form-control bg-dark text-white border-secondary border-opacity-50" rows="3" placeholder="Detalles de la visita, equipos a revisar o requerimientos..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Contacto / Solicitante en Agencia</label>
                            <input type="text" name="solicitante_nombre" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Nombre de quien recibe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Correo de Contacto</label>
                            <input type="email" name="solicitante_email" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="correo@agencia.com">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold">
                        <i class="bi bi-calendar-check me-1"></i> Agendar Cita
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Actualizar Cita -->
<div class="modal fade" id="modalActualizarCita" tabindex="-1" aria-labelledby="modalActualizarCitaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="background: #1e293b; color: #f8fafc; border-radius: 16px;">
            <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                <h5 class="modal-title fw-bold text-white" id="modalActualizarCitaLabel">
                    <i class="bi bi-pencil-square text-warning me-2"></i> Actualizar Cita <span id="citaActualizarFolio" class="badge bg-secondary ms-2"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="tickets.php" method="POST">
                <input type="hidden" name="accion" value="actualizar_cita">
                <input type="hidden" name="cita_id" id="citaActualizarId">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <div class="text-secondary small fw-bold text-uppercase">Agencia / Sucursal</div>
                        <div id="citaActualizarAgencia" class="fw-bold fs-6 text-info"></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-secondary small fw-bold text-uppercase">Asunto</div>
                        <div id="citaActualizarAsunto" class="fw-semibold text-white"></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Estado de la Cita *</label>
                            <select name="estado" id="citaActualizarEstado" class="form-select bg-dark text-white border-secondary border-opacity-50" required>
                                <option value="Programada">Programada</option>
                                <option value="En Curso">En Curso</option>
                                <option value="Realizada">Realizada</option>
                                <option value="Cancelada">Cancelada</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Fecha</label>
                            <input type="date" name="fecha_cita" id="citaActualizarFecha" class="form-control bg-dark text-white border-secondary border-opacity-50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small fw-semibold">Hora</label>
                            <input type="time" name="hora_cita" id="citaActualizarHora" class="form-control bg-dark text-white border-secondary border-opacity-50">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Técnico Asignado</label>
                            <input type="text" name="tecnico_asignado" id="citaActualizarTecnico" class="form-control bg-dark text-white border-secondary border-opacity-50" placeholder="Nombre del técnico">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small fw-semibold">Notas de Atención / Resultados</label>
                            <textarea name="notas_atencion" id="citaActualizarNotas" class="form-control bg-dark text-white border-secondary border-opacity-50" rows="3" placeholder="Resultados de la visita, observaciones técnicas o pendientes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-warning rounded-3 px-4 fw-bold text-dark">
                        <i class="bi bi-save me-1"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const agenciasData = <?php echo json_encode($agenciasList); ?>;
const totalesGlobales = {
    total: <?php echo $totalTickets; ?>,
    abiertos: <?php echo $totalAbiertos; ?>,
    en_proceso: <?php echo $totalEnProceso; ?>,
    resueltos: <?php echo $totalResueltos; ?>
};

let filtroAgenciaActual = 'TODAS';
let filtroAreaActual = 'TODAS';
let filtroCitaEstadoActual = 'TODOS';

// Control de Tabs en Menú Lateral
function cambiarTab(tabName, updateUrl = true) {
    const tabsPermitidos = ['tickets', 'citas', 'estadisticas'];
    if (!tabsPermitidos.includes(tabName)) {
        tabName = 'tickets';
    }

    // Actualizar botones de navegación en sidebar
    tabsPermitidos.forEach(t => {
        const btn = document.getElementById('navBtn_' + t);
        const sec = document.getElementById('seccionTab_' + t);
        if (btn) {
            if (t === tabName) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }
        if (sec) {
            sec.style.display = (t === tabName) ? 'block' : 'none';
        }
    });

    // Actualizar título superior
    const titleEl = document.getElementById('navbarTabTitle');
    if (titleEl) {
        if (tabName === 'tickets') {
            titleEl.innerText = 'Gestión Central de Tickets';
        } else if (tabName === 'citas') {
            titleEl.innerText = 'Agenda de Citas y Visitas Técnicas';
        } else if (tabName === 'estadisticas') {
            titleEl.innerText = 'Estadísticas e Inteligencia TI';
        }
    }

    // Si entramos a estadísticas, inicializar gráficos Chart.js
    if (tabName === 'estadisticas') {
        setTimeout(renderizarGraficasEstadisticas, 80);
    }

    // Actualizar URL sin recargar
    if (updateUrl) {
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('tab', tabName);
        if (tabName !== 'tickets') {
            currentUrl.searchParams.delete('agencia');
        }
        history.pushState(null, '', currentUrl.toString());
    }

    // Cerrar sidebar en dispositivos móviles
    cerrarSidebarMobile();
}

function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) sidebar.classList.toggle('show');
    if (backdrop) backdrop.classList.toggle('show');
}

function cerrarSidebarMobile() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) sidebar.classList.remove('show');
    if (backdrop) backdrop.classList.remove('show');
}

function mostrarVistaAgencias() {
    const vistaAg = document.getElementById('vistaAgencias');
    const vistaHist = document.getElementById('vistaHistorialTickets');
    if (vistaAg && vistaHist) {
        vistaAg.style.display = 'block';
        vistaHist.style.display = 'none';
    }
    if (typeof actualizarCoverflow3D === 'function') {
        actualizarCoverflow3D();
    }
    if (typeof iniciarAutoCoverflow === 'function' && autoCoverflowActivo) {
        iniciarAutoCoverflow();
    }
    history.pushState(null, '', 'tickets.php?tab=tickets');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function mostrarHistorialAgencia(agencia) {
    filtroAgenciaActual = agencia;
    if (typeof detenerAutoCoverflow === 'function') {
        detenerAutoCoverflow();
    }

    const vistaAg = document.getElementById('vistaAgencias');
    const vistaHist = document.getElementById('vistaHistorialTickets');
    if (vistaAg && vistaHist) {
        vistaAg.style.display = 'none';
        vistaHist.style.display = 'block';
    }

    const tituloEl = document.getElementById('tituloHistorialAgencia');
    const subtituloEl = document.getElementById('subtituloHistorialAgencia');
    const kpisEl = document.getElementById('kpisAgenciaSeleccionada');
    const btnExcel = document.getElementById('btnExportarExcel');
    const selectModalAgencia = document.getElementById('form_solicitante_agencia');

    let metrics = totalesGlobales;
    if (agencia !== 'TODAS' && agenciasData[agencia]) {
        metrics = agenciasData[agencia];
        if (tituloEl) tituloEl.innerHTML = `<span class="text-info">${agencia}</span> &bull; Historial de Tickets`;
        if (subtituloEl) subtituloEl.textContent = `Listado cronológico de incidencias y requerimientos de ${agencia}`;
        if (btnExcel) btnExcel.href = `tickets.php?export=excel&agencia=${encodeURIComponent(agencia)}`;
        if (selectModalAgencia) selectModalAgencia.value = agencia;
        history.pushState(null, '', `tickets.php?tab=tickets&agencia=${encodeURIComponent(agencia)}`);
    } else {
        filtroAgenciaActual = 'TODAS';
        if (tituloEl) tituloEl.innerHTML = `Todas las Agencias &bull; Historial Consolidado`;
        if (subtituloEl) subtituloEl.textContent = `Mostrando incidencias globales de todas las sucursales conectadas`;
        if (btnExcel) btnExcel.href = `tickets.php?export=excel`;
        history.pushState(null, '', `tickets.php?tab=tickets&agencia=TODAS`);
    }

    // Mini recuadros en la barra de historial
    if (kpisEl) {
        kpisEl.innerHTML = `
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);">
                <span class="small text-secondary">Totales:</span>
                <strong class="text-white">${metrics.total}</strong>
            </div>
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(234,179,8,0.12); border: 1px solid rgba(234,179,8,0.3);">
                <span class="small text-warning">Sin atender:</span>
                <strong class="text-warning">${metrics.abiertos}</strong>
            </div>
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.3);">
                <span class="small text-info">En proceso:</span>
                <strong class="text-info">${metrics.en_proceso}</strong>
            </div>
            <div class="px-3 py-1 rounded-3 d-flex align-items-center gap-2" style="background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3);">
                <span class="small text-success">Resueltos:</span>
                <strong class="text-success">${metrics.resueltos}</strong>
            </div>
        `;
    }

    // Sincronizar pills activas
    document.querySelectorAll('#agenciasFilterContainer .agency-pill-btn').forEach(btn => {
        const btnAg = btn.dataset.agencia || '';
        if (btnAg.toLowerCase() === filtroAgenciaActual.toLowerCase()) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    filtrarTickets();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function filtrarPorAgencia(agencia, elem) {
    mostrarHistorialAgencia(agencia);
}

function filtrarPorArea(area, elem) {
    filtroAreaActual = area;
    document.querySelectorAll('#areasFilterContainer .area-pill').forEach(pill => pill.classList.remove('active'));
    if (elem) elem.classList.add('active');
    filtrarTickets();
}

function filtrarTickets() {
    const texto = (document.getElementById('busquedaInput').value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.ticket-row');
    let visibles = 0;

    rows.forEach(row => {
        const areaRow = (row.dataset.area || '').toUpperCase();
        const agenciaRow = (row.dataset.agencia || '').toLowerCase();
        const folioRow = (row.dataset.folio || '').toLowerCase();
        const tituloRow = (row.dataset.titulo || '').toLowerCase();
        const solicitanteRow = (row.dataset.solicitante || '').toLowerCase();
        const asignadoRow = (row.dataset.asignado || '').toLowerCase();

        // 1. Filtro por Agencia
        let matchAgencia = true;
        if (filtroAgenciaActual !== 'TODAS') {
            matchAgencia = (agenciaRow === filtroAgenciaActual.toLowerCase());
        }

        // 2. Filtro por Área
        let matchArea = true;
        if (filtroAreaActual !== 'TODAS') {
            matchArea = (areaRow === filtroAreaActual);
        }

        // 3. Filtro por Búsqueda de texto
        let matchTexto = true;
        if (texto !== '') {
            matchTexto = folioRow.includes(texto) ||
                         tituloRow.includes(texto) ||
                         solicitanteRow.includes(texto) ||
                         asignadoRow.includes(texto) ||
                         agenciaRow.includes(texto);
        }

        if (matchAgencia && matchArea && matchTexto) {
            row.style.display = '';
            visibles++;
        } else {
            row.style.display = 'none';
        }
    });

    const filaSin = document.getElementById('filaSinTickets');
    if (filaSin) {
        filaSin.style.display = (visibles === 0) ? '' : 'none';
    }
}

function limpiarBusqueda() {
    document.getElementById('busquedaInput').value = '';
    filtrarTickets();
}

function abrirModalAtender(ticket) {
    document.getElementById('atenderTicketId').value = ticket.id;
    document.getElementById('atenderFolio').innerText = ticket.folio || ('TK-' + ticket.id);
    document.getElementById('atenderAgencia').innerText = ticket.solicitante_agencia || 'Agencia General';
    document.getElementById('atenderTitulo').innerText = ticket.titulo || 'Sin título';
    let solicitanteTxt = ticket.solicitante_nombre || 'Usuario';
    if (ticket.solicitante_usuario) {
        solicitanteTxt += ' (@' + ticket.solicitante_usuario + ')';
    }
    document.getElementById('atenderSolicitante').innerText = solicitanteTxt;
    document.getElementById('atenderEmail').innerText = ticket.solicitante_email || 'Sin correo';
    document.getElementById('atenderArea').innerText = ticket.area_sistemas || 'SISTEMAS';
    document.getElementById('atenderDescripcion').innerText = ticket.descripcion || '';
    document.getElementById('atenderEstado').value = ticket.estado || 'Abierto';
    document.getElementById('atenderPrioridad').value = ticket.prioridad || 'Media';
    document.getElementById('atenderAsignado').value = ticket.asignado_a || '';
    document.getElementById('atenderNotas').value = ticket.notas_resolucion || '';

    const contenedorAdj = document.getElementById('contenedorAdjunto');
    const linkAdj = document.getElementById('linkAdjunto');
    if (ticket.archivo_adjunto && ticket.archivo_adjunto !== '') {
        linkAdj.href = ticket.archivo_adjunto;
        contenedorAdj.classList.remove('d-none');
    } else {
        contenedorAdj.classList.add('d-none');
    }

    const modal = new bootstrap.Modal(document.getElementById('modalAtenderTicket'));
    modal.show();
}

// ----------------------------------------------------
// Gestión de Citas
// ----------------------------------------------------
function filtrarCitasPorEstado(estado, btn) {
    filtroCitaEstadoActual = estado;
    document.querySelectorAll('.filter-chip-cita').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filtrarCitas();
}

function filtrarCitasTexto() {
    filtrarCitas();
}

function filtrarCitas() {
    const input = document.getElementById('busquedaCitasInput');
    const texto = input ? input.value.toLowerCase().trim() : '';
    const filas = document.querySelectorAll('#tablaCitasBody tr');
    let visibles = 0;

    filas.forEach(row => {
        const estadoRow = (row.getAttribute('data-estado') || '').toLowerCase();
        const textoRow = row.innerText.toLowerCase();

        const matchEstado = (filtroCitaEstadoActual === 'TODOS' || estadoRow === filtroCitaEstadoActual.toLowerCase());
        const matchTexto = (texto === '' || textoRow.includes(texto));

        if (matchEstado && matchTexto) {
            row.style.display = '';
            visibles++;
        } else {
            row.style.display = 'none';
        }
    });

    const filaSin = document.getElementById('filaSinCitas');
    if (filaSin) {
        filaSin.style.display = (visibles === 0) ? '' : 'none';
    }
}

function abrirModalActualizarCita(cita) {
    document.getElementById('citaActualizarId').value = cita.id;
    document.getElementById('citaActualizarFolio').innerText = cita.folio || ('CIT-' + cita.id);
    document.getElementById('citaActualizarAgencia').innerText = cita.agencia || '';
    document.getElementById('citaActualizarAsunto').innerText = cita.asunto || '';
    document.getElementById('citaActualizarEstado').value = cita.estado || 'Programada';
    document.getElementById('citaActualizarFecha').value = cita.fecha_cita || '';
    document.getElementById('citaActualizarHora').value = cita.hora_cita || '';
    document.getElementById('citaActualizarTecnico').value = cita.tecnico_asignado || '';
    document.getElementById('citaActualizarNotas').value = cita.notas_atencion || '';

    const modal = new bootstrap.Modal(document.getElementById('modalActualizarCita'));
    modal.show();
}

// ----------------------------------------------------
// Gráficas de Estadísticas (Chart.js)
// ----------------------------------------------------
let chartEstado = null;
let chartArea = null;
let chartAgencia = null;

function renderizarGraficasEstadisticas() {
    if (typeof Chart === 'undefined') return;

    // Configuración visual dark mode
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.08)';
    Chart.defaults.font.family = "'Segoe UI', Roboto, system-ui, -apple-system, sans-serif";

    // 1. Gráfica Estado (Doughnut)
    const ctxEstado = document.getElementById('chartTicketsEstado');
    if (ctxEstado) {
        if (chartEstado) chartEstado.destroy();
        const dataEst = <?php echo json_encode($conteoPorEstado); ?>;
        chartEstado = new Chart(ctxEstado, {
            type: 'doughnut',
            data: {
                labels: Object.keys(dataEst),
                datasets: [{
                    data: Object.values(dataEst),
                    backgroundColor: [
                        '#ef4444', // Abierto
                        '#f59e0b', // En Proceso
                        '#10b981', // Resuelto
                        '#64748b'  // Cerrado
                    ],
                    borderWidth: 2,
                    borderColor: '#1e293b'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 15 }
                    }
                },
                cutout: '65%'
            }
        });
    }

    // 2. Gráfica Por Área (Bar)
    const ctxArea = document.getElementById('chartTicketsArea');
    if (ctxArea) {
        if (chartArea) chartArea.destroy();
        const dataArea = <?php echo json_encode($conteoPorArea); ?>;
        const colorsArea = <?php 
            $cList = [];
            foreach (array_keys($conteoPorArea) as $ak) {
                $cList[] = $AREAS_SISTEMAS[$ak]['color'] ?? '#6366f1';
            }
            echo json_encode($cList);
        ?>;
        chartArea = new Chart(ctxArea, {
            type: 'bar',
            data: {
                labels: Object.keys(dataArea),
                datasets: [{
                    label: 'Tickets',
                    data: Object.values(dataArea),
                    backgroundColor: colorsArea.length > 0 ? colorsArea : '#6366f1',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }

    // 3. Gráfica Por Agencia (Horizontal Bar)
    const ctxAgencia = document.getElementById('chartTicketsAgencia');
    if (ctxAgencia) {
        if (chartAgencia) chartAgencia.destroy();
        const dataAg = <?php 
            $labelsAg = [];
            $valsAg = [];
            foreach ($agenciasList as $nomAg => $infoAg) {
                $labelsAg[] = $nomAg;
                $valsAg[] = $infoAg['total'];
            }
            echo json_encode(['labels' => $labelsAg, 'values' => $valsAg]);
        ?>;
        chartAgencia = new Chart(ctxAgencia, {
            type: 'bar',
            data: {
                labels: dataAg.labels,
                datasets: [{
                    label: 'Total Tickets',
                    data: dataAg.values,
                    backgroundColor: '#06b6d4',
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }
}

// ----------------------------------------------------
// Galería 3D Coverflow de Sucursales
// ----------------------------------------------------
let indiceCoverflowActual = 0;
const totalCardsCoverflow = <?php echo count($agenciasList); ?>;
let autoCoverflowInterval = null;
let autoCoverflowActivo = true;

function actualizarCoverflow3D() {
    if (totalCardsCoverflow === 0) return;

    const cards = document.querySelectorAll('.coverflow-card');
    const dots = document.querySelectorAll('.coverflow-dot');
    const badge = document.getElementById('coverflowContadorBadge');

    cards.forEach((card, i) => {
        card.classList.remove('pos-center', 'pos-left', 'pos-right', 'pos-hidden-left', 'pos-hidden-right');

        if (totalCardsCoverflow === 1) {
            card.classList.add('pos-center');
            return;
        }

        if (totalCardsCoverflow === 2) {
            if (i === indiceCoverflowActual) {
                card.classList.add('pos-center');
            } else {
                card.classList.add('pos-right');
            }
            return;
        }

        // Distancia circular mínima para carrusel infinito en 3D
        let diff = i - indiceCoverflowActual;
        while (diff > totalCardsCoverflow / 2) diff -= totalCardsCoverflow;
        while (diff < -totalCardsCoverflow / 2) diff += totalCardsCoverflow;

        if (diff === 0) {
            card.classList.add('pos-center');
        } else if (diff === -1) {
            card.classList.add('pos-left');
        } else if (diff === 1) {
            card.classList.add('pos-right');
        } else if (diff < -1) {
            card.classList.add('pos-hidden-left');
        } else {
            card.classList.add('pos-hidden-right');
        }
    });

    // Actualizar dots indicadores
    dots.forEach((dot, i) => {
        if (i === indiceCoverflowActual) {
            dot.classList.add('active');
        } else {
            dot.classList.remove('active');
        }
    });

    if (badge) {
        badge.innerText = `Agencia ${indiceCoverflowActual + 1} de ${totalCardsCoverflow}`;
    }
}

function cambiar3DCoverflow(delta) {
    if (totalCardsCoverflow <= 1) return;
    indiceCoverflowActual = (indiceCoverflowActual + delta + totalCardsCoverflow) % totalCardsCoverflow;
    actualizarCoverflow3D();
}

function irA3DCoverflow(idx) {
    if (idx < 0 || idx >= totalCardsCoverflow) return;
    indiceCoverflowActual = idx;
    actualizarCoverflow3D();
}

function clickCard3D(idx, nomAgencia) {
    if (idx === indiceCoverflowActual) {
        mostrarHistorialAgencia(nomAgencia);
    } else {
        irA3DCoverflow(idx);
    }
}

function iniciarAutoCoverflow() {
    detenerAutoCoverflow();
    if (totalCardsCoverflow > 1 && autoCoverflowActivo) {
        autoCoverflowInterval = setInterval(() => {
            cambiar3DCoverflow(1);
        }, 5500);
    }
}

function detenerAutoCoverflow() {
    if (autoCoverflowInterval) {
        clearInterval(autoCoverflowInterval);
        autoCoverflowInterval = null;
    }
}

function toggleAutoCoverflow() {
    autoCoverflowActivo = !autoCoverflowActivo;
    const icon = document.getElementById('iconAutoCoverflow');
    const txt = document.getElementById('txtAutoCoverflow');
    if (autoCoverflowActivo) {
        if (icon) icon.className = 'bi bi-pause-fill';
        if (txt) txt.innerText = 'Pausar';
        iniciarAutoCoverflow();
    } else {
        if (icon) icon.className = 'bi bi-play-fill';
        if (txt) txt.innerText = 'Reanudar';
        detenerAutoCoverflow();
    }
}

// Navegación con teclado (Flechas izquierda y derecha)
window.addEventListener('keydown', (e) => {
    const vistaAg = document.getElementById('vistaAgencias');
    if (vistaAg && vistaAg.style.display !== 'none') {
        if (e.key === 'ArrowLeft') {
            cambiar3DCoverflow(-1);
        } else if (e.key === 'ArrowRight') {
            cambiar3DCoverflow(1);
        }
    }
});

// ----------------------------------------------------
// Router de inicio según URL
// ----------------------------------------------------
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab') || 'tickets';
    cambiarTab(tabParam, false);

    if (tabParam === 'tickets') {
        const agParam = urlParams.get('agencia');
        if (agParam) {
            mostrarHistorialAgencia(agParam);
        } else {
            mostrarVistaAgencias();
        }
    }

    // Inicializar Coverflow 3D y listeners de pausa en hover
    actualizarCoverflow3D();
    const stage = document.getElementById('coverflowStage');
    if (stage) {
        stage.addEventListener('mouseenter', detenerAutoCoverflow);
        stage.addEventListener('mouseleave', () => {
            if (autoCoverflowActivo) iniciarAutoCoverflow();
        });
    }
    iniciarAutoCoverflow();
});

// Navegación atrás / adelante del navegador
window.addEventListener('popstate', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab') || 'tickets';
    cambiarTab(tabParam, false);

    if (tabParam === 'tickets') {
        const agParam = urlParams.get('agencia');
        if (agParam) {
            mostrarHistorialAgencia(agParam);
        } else {
            mostrarVistaAgencias();
        }
    }
});
</script>
</body>
</html>
