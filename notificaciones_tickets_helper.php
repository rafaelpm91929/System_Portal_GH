<?php
/**
 * =============================================================================
 * HELPER DE NOTIFICACIONES Y ALERTAS POR CORREO PARA TICKETS
 * DIRECCIÓN DE SISTEMAS - GRUPO HUERTA
 * =============================================================================
 * Garantiza:
 * 1. Alerta en portal y por correo a los especialistas asignados al área al crearse un ticket.
 * 2. Alerta en portal y por correo al creador/solicitante ante cualquier modificación o respuesta.
 * 3. Envió seguro sin bloqueos ni retrasos en la interfaz.
 */

if (!function_exists('obtenerUrlPortalSistemas')) {
    function obtenerUrlPortalSistemas() {
        if (getenv('APP_URL') && filter_var(getenv('APP_URL'), FILTER_VALIDATE_URL)) {
            return rtrim(getenv('APP_URL'), '/');
        }
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
            $proto = $isHttps ? 'https://' : 'http://';
            return $proto . $_SERVER['HTTP_HOST'];
        }
        return 'https://portal.grupohuerta.mx';
    }
}
/**
 * Resuelve la URL del portal donde debe verse el ticket:
 * - Si es para el creador / solicitante de sucursal: Devuelve la URL del portal de su agencia específica (ej: https://portal.divolavilla.com/sistemas/tickets.php?ver=TK-...)
 * - Si es para el especialista / agente de TI Central: Devuelve la URL del portal central (https://portal.grupohuerta.mx/tickets.php?ver=...)
 */
if (!function_exists('resolverEnlacePortalTicket')) {
    function resolverEnlacePortalTicket($pdo, $ticketData, $esParaCreador = true) {
        $folio = trim($ticketData['folio'] ?? '');
        $ticketId = $ticketData['id'] ?? '';
        $agencia = trim($ticketData['solicitante_agencia'] ?? '');
        $solEmail = trim($ticketData['solicitante_email'] ?? '');

        // Si es para un agente/especialista central TI, siempre va al portal central GH
        if (!$esParaCreador || empty($agencia)) {
            $urlCentral = obtenerUrlPortalSistemas();
            $paramVer = !empty($ticketId) ? $ticketId : $folio;
            return $urlCentral . '/tickets.php?ver=' . urlencode($paramVer);
        }

        // Si es para el creador / solicitante de una agencia sucursal:
        $urlAgencia = '';

        // 1. Si el ticket tiene guardado webhook_url, deducir la base del portal directamente de ahí
        if (!empty($ticketData['webhook_url'])) {
            $urlAgencia = preg_replace('#/(?:api_webhook_ticket|api_obtener_datos)\.php.*$#i', '', $ticketData['webhook_url']);
        } elseif ($pdo && !empty($folio)) {
            try {
                $stmtWhD = $pdo->prepare("SELECT webhook_url, solicitante_email FROM tickets_soporte WHERE folio = ? OR id = ? LIMIT 1");
                $stmtWhD->execute([$folio, $ticketId]);
                $rWh = $stmtWhD->fetch(PDO::FETCH_ASSOC);
                if (!empty($rWh['webhook_url'])) {
                    $urlAgencia = preg_replace('#/(?:api_webhook_ticket|api_obtener_datos)\.php.*$#i', '', $rWh['webhook_url']);
                }
                if (empty($solEmail) && !empty($rWh['solicitante_email'])) {
                    $solEmail = trim($rWh['solicitante_email']);
                }
            } catch (Throwable $eWhD) {}
        }

        // 2. Buscar en tabla agencias de la BD Central
        if (empty($urlAgencia) && $pdo) {
            try {
                $stmt = $pdo->prepare("SELECT subdominio, cpanel, endpoint FROM agencias WHERE LOWER(nombre) = LOWER(?) OR LOWER(clave) = LOWER(?) OR nombre LIKE ? LIMIT 1");
                $stmt->execute([$agencia, $agencia, '%' . $agencia . '%']);
                $agRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($agRow) {
                    if (!empty($agRow['endpoint'])) {
                        $baseEp = preg_replace('#/(?:api_webhook_ticket|api_obtener_datos)\.php.*$#i', '', $agRow['endpoint']);
                        $urlAgencia = rtrim($baseEp, '/');
                    } elseif (!empty($agRow['subdominio']) || !empty($agRow['cpanel'])) {
                        $sub = !empty($agRow['subdominio']) ? $agRow['subdominio'] : $agRow['cpanel'];
                        $sub = preg_replace('#^https?://#i', '', rtrim($sub, '/'));
                        $pathP = (stripos($sub, 'portal.') === 0 || strpos($sub, '/') !== false) ? '' : '/sistemas';
                        $urlAgencia = 'https://' . rtrim($sub, '/') . $pathP;
                    }
                }
            } catch (Throwable $e) {}
        }

        // 3. Fallback inteligente por dominio del correo del solicitante (ej: aux.sistemas@suzukimotosmontevideo.com)
        if (empty($urlAgencia) && !empty($solEmail) && filter_var($solEmail, FILTER_VALIDATE_EMAIL)) {
            $emDomain = substr(strrchr($solEmail, "@"), 1);
            if ($emDomain && stripos($emDomain, 'grupohuerta.mx') === false) {
                $urlAgencia = 'https://portal.' . $emDomain;
            }
        }

        // 4. Buscar en CATALOGO_AGENCIAS
        if (empty($urlAgencia)) {
            global $CATALOGO_AGENCIAS;
            if (!empty($CATALOGO_AGENCIAS)) {
                $agNorm = strtolower($agencia);
                foreach ($CATALOGO_AGENCIAS as $k => $ag) {
                    $nomAg = strtolower(trim($ag['nombre'] ?? ''));
                    if ($k === $agNorm || $nomAg === $agNorm || strpos($agNorm, $nomAg) !== false || strpos($nomAg, $agNorm) !== false) {
                        if (!empty($ag['endpoint'])) {
                            $baseEp = preg_replace('#/(?:api_webhook_ticket|api_obtener_datos)\.php.*$#i', '', $ag['endpoint']);
                            $urlAgencia = rtrim($baseEp, '/');
                        } elseif (!empty($ag['subdominio'])) {
                            $sub = preg_replace('#^https?://#i', '', rtrim($ag['subdominio'], '/'));
                            $pathP = (stripos($sub, 'portal.') === 0 || strpos($sub, '/') !== false) ? '' : '/sistemas';
                            $urlAgencia = 'https://' . rtrim($sub, '/') . $pathP;
                        }
                        break;
                    }
                }
            }
        }

        // Fallback final
        if (empty($urlAgencia)) {
            if (stripos($agencia, 'villa') !== false || stripos($agencia, 'divol') !== false) {
                $urlAgencia = 'https://portal.divolavilla.com/sistemas';
            } else {
                $urlAgencia = obtenerUrlPortalSistemas();
            }
        }

        $paramVer = !empty($folio) ? $folio : $ticketId;
        return rtrim($urlAgencia, '/') . '/tickets.php?ver=' . urlencode($paramVer);
    }
}


/**
 * Envío de correos electrónicos corporativos (Soporta SMTP seguro y fallback a mail() nativo de PHP)
 */
if (!function_exists('enviarCorreoSistemas')) {
    function enviarCorreoSistemas($destinatarioEmail, $destinatarioNombre, $asunto, $cuerpoHtml, $cuerpoPlano = '') {
        $destinatarioEmail = trim($destinatarioEmail ?? '');
        if (empty($destinatarioEmail) || !filter_var($destinatarioEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $fromEmail = getenv('MAIL_FROM') ?: (defined('MAIL_FROM') ? MAIL_FROM : 'notificaciones@grupohuerta.mx');
        $fromName  = getenv('MAIL_FROM_NAME') ?: (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Dirección de Sistemas Grupo Huerta');

        // Normalizar Asunto para UTF-8 MIME
        $asuntoEncoded = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
        $destinatarioNombreLimpio = trim(preg_replace('/[\r\n]+/', '', $destinatarioNombre ?: 'Colaborador'));
        $paraHeader = !empty($destinatarioNombreLimpio) 
            ? '=?UTF-8?B?' . base64_encode($destinatarioNombreLimpio) . '?= <' . $destinatarioEmail . '>'
            : $destinatarioEmail;

        // 1. Intentar envío vía Socket SMTP si hay servidor configurado en .env
        $smtpHost = getenv('SMTP_HOST') ?: '';
        if (!empty($smtpHost)) {
            try {
                $smtpPort = intval(getenv('SMTP_PORT') ?: 587);
                $smtpUser = getenv('SMTP_USER') ?: '';
                $smtpPass = getenv('SMTP_PASS') ?: '';
                $smtpSecure = strtolower(getenv('SMTP_SECURE') ?: 'tls'); // 'tls', 'ssl' o 'none'

                $remoteHost = ($smtpSecure === 'ssl') ? ('ssl://' . $smtpHost) : $smtpHost;
                $socket = @fsockopen($remoteHost, $smtpPort, $errno, $errstr, 3.0);

                if ($socket) {
                    stream_set_timeout($socket, 3);
                    $leerRespuesta = function() use ($socket) {
                        $res = '';
                        while ($linea = fgets($socket, 512)) {
                            $res .= $linea;
                            if (substr($linea, 3, 1) === ' ') break;
                        }
                        return $res;
                    };

                    $leerRespuesta();
                    fputs($socket, "EHLO " . (gethostname() ?: 'localhost') . "\r\n");
                    $ehloRes = $leerRespuesta();

                    if ($smtpSecure === 'tls' && strpos($ehloRes, 'STARTTLS') !== false) {
                        fputs($socket, "STARTTLS\r\n");
                        $leerRespuesta();
                        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        fputs($socket, "EHLO " . (gethostname() ?: 'localhost') . "\r\n");
                        $leerRespuesta();
                    }

                    if (!empty($smtpUser) && !empty($smtpPass)) {
                        fputs($socket, "AUTH LOGIN\r\n");
                        $leerRespuesta();
                        fputs($socket, base64_encode($smtpUser) . "\r\n");
                        $leerRespuesta();
                        fputs($socket, base64_encode($smtpPass) . "\r\n");
                        $authRes = $leerRespuesta();
                        if (strpos($authRes, '235') === false) {
                            fclose($socket);
                            throw new Exception("Error auth SMTP: " . $authRes);
                        }
                    }

                    fputs($socket, "MAIL FROM: <$fromEmail>\r\n");
                    $leerRespuesta();
                    fputs($socket, "RCPT TO: <$destinatarioEmail>\r\n");
                    $leerRespuesta();
                    fputs($socket, "DATA\r\n");
                    $leerRespuesta();

                    $headersMail = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n";
                    $headersMail .= "To: $paraHeader\r\n";
                    $headersMail .= "Subject: $asuntoEncoded\r\n";
                    $headersMail .= "Date: " . date('r') . "\r\n";
                    $headersMail .= "MIME-Version: 1.0\r\n";
                    $headersMail .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $headersMail .= "Content-Transfer-Encoding: 8bit\r\n";
                    $headersMail .= "X-Mailer: GrupoHuerta-PortalTI/2.0\r\n\r\n";

                    fputs($socket, $headersMail . $cuerpoHtml . "\r\n.\r\n");
                    $dataRes = $leerRespuesta();
                    fputs($socket, "QUIT\r\n");
                    fclose($socket);

                    if (strpos($dataRes, '250') !== false) {
                        return true;
                    }
                }
            } catch (Throwable $smtpEx) {
                // Fallback automático a PHP mail()
            }
        }

        // 2. Fallback estándar a mail() de PHP (Exim / sendmail nativo en cPanel / Linux)
        try {
            // Salvaguarda para Windows Local: Si no hay servidor SMTP local escuchando, no congelar la petición
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && empty($smtpHost)) {
                $hostWin = ini_get('SMTP') ?: 'localhost';
                $portWin = intval(ini_get('smtp_port') ?: 25);
                $testSock = @fsockopen($hostWin, $portWin, $errno, $errstr, 0.2);
                if (!$testSock) {
                    return false; // Sin servidor SMTP local en Windows, continuar de inmediato
                }
                @fclose($testSock);
            }

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>',
                'Reply-To: ' . $fromEmail,
                'X-Mailer: GrupoHuerta-PortalTI/2.0',
                'X-Priority: 1 (Highest)',
                'Importance: High'
            ];
            $headersStr = implode("\r\n", $headers);
            $resMail = @mail($destinatarioEmail, $asuntoEncoded, $cuerpoHtml, $headersStr, "-f" . $fromEmail);
            if (!$resMail) {
                $resMail = @mail($destinatarioEmail, $asuntoEncoded, $cuerpoHtml, $headersStr);
            }
            return $resMail;
        } catch (Throwable $t) {
            return false;
        }
    }
}

/**
 * Genera el cuerpo HTML responsive corporativo de Grupo Huerta para notificaciones de tickets
 */
if (!function_exists('generarPlantillaCorreoTicket')) {
    function generarPlantillaCorreoTicket($datos) {
        $folio            = htmlspecialchars($datos['folio'] ?? 'TK-XXXX');
        $tituloTicket     = htmlspecialchars($datos['titulo'] ?? 'Ticket de Soporte');
        $area             = htmlspecialchars($datos['area_sistemas'] ?? 'SISTEMAS');
        $estado           = htmlspecialchars($datos['estado'] ?? 'Abierto');
        $prioridad        = htmlspecialchars($datos['prioridad'] ?? 'Media');
        $solicitante      = htmlspecialchars($datos['solicitante_nombre'] ?? 'Colaborador');
        $agencia          = htmlspecialchars($datos['solicitante_agencia'] ?? 'Grupo Huerta');
        $tecnico          = htmlspecialchars($datos['asignado_a'] ?: 'Por Asignar');
        $tipoAlerta       = htmlspecialchars($datos['tipo_alerta'] ?? 'NOTIFICACIÓN DE TICKET');
        $colorBadge       = $datos['color_badge'] ?? '#0284c7';
        $detalleHtml      = $datos['detalle_html'] ?? '';
        $enlaceBoton      = $datos['enlace_boton'] ?? '';
        $textoBoton       = htmlspecialchars($datos['texto_boton'] ?? 'Ver Ticket en Portal');
        $fechaEmision     = date('d/m/Y h:i A');

        // Color del estado
        $estUpper = strtoupper($estado);
        $colorEstado = '#38bdf8';
        if ($estUpper === 'ABIERTO')    $colorEstado = '#f59e0b';
        if ($estUpper === 'EN PROCESO') $colorEstado = '#06b6d4';
        if ($estUpper === 'RESUELTO')   $colorEstado = '#10b981';
        if ($estUpper === 'CERRADO')    $colorEstado = '#64748b';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$folio} - {$tituloTicket}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b1120; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #e2e8f0;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #0b1120; padding: 24px 12px;">
    <tr>
      <td align="center">
        <!-- Contenedor Principal -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 620px; background-color: #1e293b; border-radius: 16px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5);">
          
          <!-- Encabezado Corporativo -->
          <tr>
            <td style="background: linear-gradient(135deg, #090e1a 0%, #1e293b 100%); padding: 26px 30px; border-bottom: 2px solid #d4af37;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td>
                    <div style="font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: #d4af37; margin-bottom: 4px;">
                      GRUPO HUERTA &bull; DIRECCIÓN DE SISTEMAS
                    </div>
                    <div style="font-size: 20px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px;">
                      Portal Central de Soporte TI
                    </div>
                  </td>
                  <td align="right" valign="top">
                    <span style="display: inline-block; padding: 6px 12px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; border-radius: 9999px; background-color: {$colorBadge}; color: #ffffff;">
                      {$tipoAlerta}
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Resumen de Ticket y Título -->
          <tr>
            <td style="padding: 26px 30px 16px 30px;">
              <div style="font-size: 13px; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;">
                Folio: {$folio} &bull; Área: {$area}
              </div>
              <h1 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #f8fafc; line-height: 1.35;">
                {$tituloTicket}
              </h1>

              <!-- Tabla de Metadatos -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #0f172a; border-radius: 12px; border: 1px solid rgba(255,255,255,0.06); margin-bottom: 22px;">
                <tr>
                  <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); width: 50%;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Estado Actual</div>
                    <div style="font-size: 14px; font-weight: 700; color: {$colorEstado}; margin-top: 2px;">
                      ● {$estado}
                    </div>
                  </td>
                  <td style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); width: 50%;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Prioridad</div>
                    <div style="font-size: 14px; font-weight: 700; color: #f1f5f9; margin-top: 2px;">
                      {$prioridad}
                    </div>
                  </td>
                </tr>
                <tr>
                  <td style="padding: 12px 16px; width: 50%;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Solicitante y Sucursal</div>
                    <div style="font-size: 13px; font-weight: 600; color: #f1f5f9; margin-top: 2px;">
                      {$solicitante}
                    </div>
                    <div style="font-size: 11px; color: #94a3b8;">
                      {$agencia}
                    </div>
                  </td>
                  <td style="padding: 12px 16px; width: 50%;">
                    <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600;">Especialista Asignado</div>
                    <div style="font-size: 13px; font-weight: 600; color: #38bdf8; margin-top: 2px;">
                      {$tecnico}
                    </div>
                  </td>
                </tr>
              </table>

              <!-- Detalle de la Modificación / Mensaje -->
              <div style="background: linear-gradient(180deg, rgba(255,255,255,0.03) 0%, rgba(255,255,255,0.01) 100%); border-left: 4px solid #38bdf8; border-radius: 0 10px 10px 0; padding: 16px 20px; margin-bottom: 24px;">
                <div style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                  Detalles de la Notificación:
                </div>
                <div style="font-size: 14px; line-height: 1.6; color: #cbd5e1; white-space: pre-line;">
                  {$detalleHtml}
                </div>
              </div>

              <!-- Botón de Acción Directa -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 10px; margin-bottom: 12px;">
                <tr>
                  <td align="center">
                    <a href="{$enlaceBoton}" target="_blank" style="display: inline-block; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #ffffff; text-decoration: none; font-weight: 700; font-size: 14px; padding: 14px 28px; border-radius: 10px; box-shadow: 0 4px 12px rgba(2,132,199,0.35); letter-spacing: 0.3px;">
                      {$textoBoton} &rarr;
                    </a>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- Pie del Correo -->
          <tr>
            <td style="background-color: #0f172a; padding: 20px 30px; border-top: 1px solid rgba(255,255,255,0.06); text-align: center;">
              <div style="font-size: 11px; color: #64748b; line-height: 1.5; margin-bottom: 4px;">
                Este es un mensaje automático generado por el Sistema de Tickets de Grupo Huerta.
              </div>
              <div style="font-size: 11px; color: #64748b;">
                Emitido el {$fechaEmision} (Hora Centro de México) &bull; Favor de no responder a esta dirección de correo.
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}

/**
 * Obtiene todos los usuarios y especialistas activos asignados al área de un ticket
 */
if (!function_exists('normalizarAreaSistemas')) {
    function normalizarAreaSistemas($str) {
        $str = strtoupper(trim($str ?? ''));
        // Quitar acentos y diacríticos
        $str = str_replace(['Á','É','Í','Ó','Ú','Ñ'], ['A','E','I','O','U','N'], $str);
        // Quitar caracteres especiales
        $clean = preg_replace('/[^A-Z0-9]/', '', $str);

        // Sinónimos y equivalencias oficiales
        if ($clean === 'CIBERSEGURIDAD' || $clean === 'CYBERSEGURIDAD') {
            return 'CYBERSEGURIDAD';
        }
        if (strpos($clean, 'DIRECCION') !== false && strpos($clean, 'SISTEMA') !== false) {
            return 'DIRECCIONSISTEMAS';
        }
        if ($clean === 'REDESSOCIALES' || $clean === 'REDES') {
            return 'REDESSOCIALES';
        }
        if ($clean === 'AUDITORIA' || $clean === 'AUDITORIAS') {
            return 'AUDITORIA';
        }
        if (strpos($clean, 'INFRAESTRUCTURA') !== false || $clean === 'SITE' || $clean === 'IDF') {
            return 'INFRAESTRUCTURA';
        }
        if (strpos($clean, 'DESARROLLO') !== false || $clean === 'SOFTWARE') {
            return 'DESARROLLO';
        }
        if ($clean === 'CORPORATIVO' || $clean === 'CORP') {
            return 'CORPORATIVO';
        }
        return $clean;
    }
}

if (!function_exists('usuarioTieneAreaAsignada')) {
    function usuarioTieneAreaAsignada($areasUsuarioStr, $areaTicket) {
        $areaTicketNorm = normalizarAreaSistemas($areaTicket);
        if (empty($areaTicketNorm)) return false;

        $areasUsuarioStr = trim($areasUsuarioStr ?? '');
        if (empty($areasUsuarioStr) || strtoupper($areasUsuarioStr) === 'NINGUNA') {
            return false;
        }

        // Si explícitamente tiene 'TODOS' o '*', está asignado a todas las áreas
        if (strtoupper($areasUsuarioStr) === 'TODOS' || $areasUsuarioStr === '*') {
            return true;
        }

        // Revisar cada área asignada en la lista separada por comas
        $lista = explode(',', $areasUsuarioStr);
        foreach ($lista as $item) {
            if (normalizarAreaSistemas($item) === $areaTicketNorm) {
                return true;
            }
        }
        return false;
    }
}

/**
 * Obtiene todos los usuarios y especialistas activos asignados al área de un ticket.
 * FILTRADO ESTRICTO: Solo retorna usuarios que tengan asignada esa área específica.
 * No envía notificaciones por correo de áreas no asignadas.
 */
if (!function_exists('obtenerEspecialistasAreaTicket')) {
    function obtenerEspecialistasAreaTicket($pdo, $area) {
        if (!$pdo || empty(trim($area ?? ''))) return [];

        try {
            // Compatibilidad total de columna activo / estatus
            $colActivo = 'activo';
            try {
                $pdo->query("SELECT activo FROM usuarios LIMIT 1");
            } catch (Throwable $tCol) {
                $colActivo = 'estatus';
            }

            // Usuarios activos
            $stmt = $pdo->query("SELECT id, usuario, nombre, email, rol, areas_tickets FROM usuarios WHERE $colActivo = 1");
            $todos = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $asignados = [];

            foreach ($todos as $u) {
                $areasU = trim($u['areas_tickets'] ?? '');

                // FILTRADO ESTRICTO:
                // Que por correo (y portal), solo le llegue el área que tiene asignada,
                // NO de otras áreas no asignadas.
                if (usuarioTieneAreaAsignada($areasU, $area)) {
                    $asignados[] = $u;
                }
            }

            return $asignados;
        } catch (Throwable $e) {
            return [];
        }
    }
}

/**
 * Resuelve y complementa los datos de contacto y usuario del creador/solicitante del ticket
 */
if (!function_exists('obtenerDatosCreadorTicket')) {
    function obtenerDatosCreadorTicket($pdo, $ticketIdOrData) {
        $tk = null;
        if (is_array($ticketIdOrData)) {
            $tk = $ticketIdOrData;
        } elseif ($pdo && intval($ticketIdOrData) > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ? LIMIT 1");
                $stmt->execute([intval($ticketIdOrData)]);
                $tk = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $t) {}
        }

        if (!$tk) return null;

        $creadorId     = !empty($tk['solicitante_id']) ? intval($tk['solicitante_id']) : null;
        $creadorEmail  = trim($tk['solicitante_email'] ?? '');
        $creadorNombre = trim($tk['solicitante_nombre'] ?? '');
        $creadorUsr    = trim($tk['solicitante_usuario'] ?? '');

        // Si falta creadorId o creadorEmail, intentar resolver desde la tabla usuarios
        if ($pdo && (!$creadorId || empty($creadorEmail))) {
            try {
                $uMatch = null;
                if (!empty($creadorEmail) && filter_var($creadorEmail, FILTER_VALIDATE_EMAIL)) {
                    $stmtU = $pdo->prepare("SELECT id, usuario, nombre, email FROM usuarios WHERE LOWER(email) = LOWER(?) LIMIT 1");
                    $stmtU->execute([$creadorEmail]);
                    $uMatch = $stmtU->fetch(PDO::FETCH_ASSOC);
                } elseif (!empty($creadorUsr)) {
                    $stmtU = $pdo->prepare("SELECT id, usuario, nombre, email FROM usuarios WHERE LOWER(usuario) = LOWER(?) LIMIT 1");
                    $stmtU->execute([$creadorUsr]);
                    $uMatch = $stmtU->fetch(PDO::FETCH_ASSOC);
                }

                if ($uMatch) {
                    if (!$creadorId) $creadorId = intval($uMatch['id']);
                    if (empty($creadorEmail)) $creadorEmail = trim($uMatch['email'] ?? '');
                    if (empty($creadorNombre)) $creadorNombre = trim($uMatch['nombre'] ?? '');
                }
            } catch (Throwable $tU) {}
        }

        return [
            'id'      => $creadorId,
            'nombre'  => $creadorNombre ?: 'Solicitante',
            'email'   => $creadorEmail,
            'usuario' => $creadorUsr,
            'agencia' => trim($tk['solicitante_agencia'] ?? '')
        ];
    }
}

/**
 * 1. NOTIFICAR NUEVO TICKET AL ÁREA (PORTAL + CORREO)
 * Notifica a todos los especialistas que tienen asignada el área del ticket.
 */
if (!function_exists('notificarNuevoTicketArea')) {
    function notificarNuevoTicketArea($pdo, $ticketId, $ticketData = null) {
        if (!$pdo || empty($ticketId)) return;

        try {
            if (!$ticketData || !is_array($ticketData)) {
                $stmt = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
                $stmt->execute([$ticketId]);
                $ticketData = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$ticketData) return;

            $area = strtoupper(trim($ticketData['area_sistemas'] ?? ''));
            $folio = trim($ticketData['folio'] ?? ('TK-' . $ticketId));
            $titulo = trim($ticketData['titulo'] ?? 'Ticket de Soporte');
            $agencia = trim($ticketData['solicitante_agencia'] ?? 'Agencia Grupo Huerta');
            $solicitante = trim($ticketData['solicitante_nombre'] ?? 'Usuario');
            $prioridad = trim($ticketData['prioridad'] ?? 'Media');
            $desc = trim($ticketData['descripcion'] ?? '');

            $especialistas = obtenerEspecialistasAreaTicket($pdo, $area);
            // Si el área no tiene especialistas específicamente configurados, notificar a los Administradores de Sistemas
            if (empty($especialistas)) {
                try {
                    $stmtAdm = $pdo->query("SELECT id, usuario, nombre, email, rol, areas_tickets FROM usuarios WHERE activo = 1 AND LOWER(rol) IN ('superadmin', 'admin')");
                    $especialistas = $stmtAdm ? $stmtAdm->fetchAll(PDO::FETCH_ASSOC) : [];
                } catch (Throwable $tAdm) {}
            }
            if (empty($especialistas)) return;

            $enlaceTicket = resolverEnlacePortalTicket($pdo, $ticketData, false);

            $asuntoCorreo = "[SISTEMAS GH] Nuevo Ticket #$folio asignado a $area ($agencia)";
            $detalleHtml = "Se ha registrado un nuevo ticket de soporte asignado a tu área de especialidad:\n\n"
                         . "• Sucursal: $agencia\n"
                         . "• Solicitante: $solicitante\n"
                         . "• Prioridad: $prioridad\n\n"
                         . "Descripción del problema / requerimiento:\n$desc";

            $htmlCorreo = generarPlantillaCorreoTicket([
                'folio'              => $folio,
                'titulo'             => $titulo,
                'area_sistemas'      => $area,
                'estado'             => $ticketData['estado'] ?? 'Abierto',
                'prioridad'          => $prioridad,
                'solicitante_nombre' => $solicitante,
                'solicitante_agencia'=> $agencia,
                'asignado_a'         => $ticketData['asignado_a'] ?? 'Por Asignar',
                'tipo_alerta'        => 'NUEVO TICKET ASIGNADO A TU ÁREA',
                'color_badge'        => '#10b981',
                'detalle_html'       => $detalleHtml,
                'enlace_boton'       => $enlaceTicket,
                'texto_boton'        => 'Atender Ticket en el Portal'
            ]);

            $enviadosEmail = [];

            foreach ($especialistas as $esp) {
                $espId = intval($esp['id'] ?? 0);
                $espEmail = trim($esp['email'] ?? '');
                $espNom = trim($esp['nombre'] ?? ($esp['usuario'] ?? 'Especialista'));

                // A. Notificación en el Portal
                if ($espId > 0 && function_exists('crearNotificacion')) {
                    $tituloNotif = "Nuevo Ticket Asignado: #$folio ($area)";
                    $msgNotif = "Se registró nuevo ticket de $agencia para tu área: $titulo (Solicitante: $solicitante)";
                    crearNotificacion(
                        'tickets',
                        'nuevo_ticket',
                        $tituloNotif,
                        $msgNotif,
                        'tickets.php?ver=' . $ticketId,
                        'bi-ticket-detailed-fill',
                        'info',
                        $ticketId,
                        $espId,
                        $pdo
                    );
                }

                // B. Notificación por Correo Electrónico
                if (!empty($espEmail) && filter_var($espEmail, FILTER_VALIDATE_EMAIL) && !isset($enviadosEmail[strtolower($espEmail)])) {
                    $enviadosEmail[strtolower($espEmail)] = true;
                    enviarCorreoSistemas($espEmail, $espNom, $asuntoCorreo, $htmlCorreo);
                }
            }
        } catch (Throwable $e) {
            // Silencioso para no romper la petición
        }
    }
}

/**
 * 2. NOTIFICAR MODIFICACIÓN DE TICKET AL CREADOR (PORTAL + CORREO)
 * Notifica al usuario que subió/creó el ticket sobre cambios de estado, asignación, notas o reportes.
 */
if (!function_exists('notificarModificacionTicketCreador')) {
    function notificarModificacionTicketCreador($pdo, $ticketId, $tipoModificacion, $detalleModificacion, $autorCambio = '', $nuevoEstado = null) {
        if (!$pdo || empty($ticketId)) return;

        try {
            $stmt = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
            $stmt->execute([$ticketId]);
            $tk = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tk) return;

            $creador = obtenerDatosCreadorTicket($pdo, $tk);
            if (!$creador) return;

            $folio = trim($tk['folio'] ?? ('TK-' . $ticketId));
            $titulo = trim($tk['titulo'] ?? 'Ticket de Soporte');
            $area = strtoupper(trim($tk['area_sistemas'] ?? ''));
            $estadoActual = $nuevoEstado ?: ($tk['estado'] ?? 'En Proceso');
            $agenteAsignado = trim($tk['asignado_a'] ?: ($autorCambio ?: 'Especialista de Sistemas'));

            $enlaceTicket = resolverEnlacePortalTicket($pdo, $tk, true);

            // A. Notificación en el Portal para el que subió el ticket
            if (!empty($creador['id']) && function_exists('crearNotificacion')) {
                $tituloNotif = "Actualización en tu Ticket #$folio";
                $msgNotif = "Tu ticket ha sido actualizado [$tipoModificacion]. " . mb_substr($detalleModificacion, 0, 150);
                crearNotificacion(
                    'tickets',
                    'actualizacion_ticket',
                    $tituloNotif,
                    $msgNotif,
                    'tickets.php?ver=' . $ticketId,
                    'bi-arrow-repeat',
                    'warning',
                    $ticketId,
                    $creador['id'],
                    $pdo
                );
            }

            // B. Notificación por Correo al que creó el ticket
            if (!empty($creador['email']) && filter_var($creador['email'], FILTER_VALIDATE_EMAIL)) {
                $asuntoCorreo = "[SISTEMAS GH] Actualización de tu Ticket #$folio: $tipoModificacion";
                $htmlCorreo = generarPlantillaCorreoTicket([
                    'folio'              => $folio,
                    'titulo'             => $titulo,
                    'area_sistemas'      => $area,
                    'estado'             => $estadoActual,
                    'prioridad'          => $tk['prioridad'] ?? 'Media',
                    'solicitante_nombre' => $creador['nombre'],
                    'solicitante_agencia'=> $creador['agencia'],
                    'asignado_a'         => $agenteAsignado,
                    'tipo_alerta'        => 'ACTUALIZACIÓN EN TU TICKET',
                    'color_badge'        => '#0284c7',
                    'detalle_html'       => "Tu ticket ha tenido la siguiente actualización realizada por <strong>" . htmlspecialchars($autorCambio ?: 'Sistemas') . "</strong>:\n\n" . $detalleModificacion,
                    'enlace_boton'       => $enlaceTicket,
                    'texto_boton'        => 'Consultar Estado en el Portal'
                ]);

                enviarCorreoSistemas($creador['email'], $creador['nombre'], $asuntoCorreo, $htmlCorreo);
            }
        } catch (Throwable $e) {
            // Silencioso
        }
    }
}

/**
 * 3. NOTIFICAR NUEVO MENSAJE O RESPUESTA EN EL CHAT DEL TICKET
 * - Si contesta un agente: Notifica al creador (portal + correo).
 * - Si contesta el creador/sucursal: Notifica al técnico asignado o al área (portal + correo).
 */
if (!function_exists('notificarMensajeTicket')) {
    function notificarMensajeTicket($pdo, $ticketId, $mensajeTexto, $autorNombre, $autorRol = 'agente', $esNotaInterna = false) {
        if (!$pdo || empty($ticketId) || empty(trim($mensajeTexto ?? ''))) return;

        // Notas internas nunca se notifican al solicitante/creador
        if ($esNotaInterna) return;

        try {
            $stmt = $pdo->prepare("SELECT * FROM tickets_soporte WHERE id = ?");
            $stmt->execute([$ticketId]);
            $tk = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tk) return;

            $folio = trim($tk['folio'] ?? ('TK-' . $ticketId));
            $area = strtoupper(trim($tk['area_sistemas'] ?? ''));
            $enlaceTicketCreador = resolverEnlacePortalTicket($pdo, $tk, true);
            $enlaceTicketCentral = resolverEnlacePortalTicket($pdo, $tk, false);
            $resumenMsg = mb_substr($mensajeTexto, 0, 180) . (mb_strlen($mensajeTexto) > 180 ? '...' : '');

            // CASO A: El especialista/agente respondió -> Notificar al solicitante/creador
            if ($autorRol === 'agente' || strtolower($autorRol) === 'admin' || strtolower($autorRol) === 'superadmin') {
                $creador = obtenerDatosCreadorTicket($pdo, $tk);
                if ($creador) {
                    // 1. Alerta en el Portal
                    if (!empty($creador['id']) && function_exists('crearNotificacion')) {
                        crearNotificacion(
                            'tickets',
                            'mensaje_ticket',
                            "Respuesta en tu Ticket #$folio",
                            "El especialista $autorNombre contestó: \"$resumenMsg\"",
                            'tickets.php?ver=' . $ticketId,
                            'bi-chat-dots-fill',
                            'primary',
                            $ticketId,
                            $creador['id'],
                            $pdo
                        );
                    }

                    // 2. Correo al solicitante
                    if (!empty($creador['email']) && filter_var($creador['email'], FILTER_VALIDATE_EMAIL)) {
                        $asunto = "[SISTEMAS GH] Nueva Respuesta en Ticket #$folio de $autorNombre";
                        $html = generarPlantillaCorreoTicket([
                            'folio'              => $folio,
                            'titulo'             => $tk['titulo'] ?? 'Ticket de Soporte',
                            'area_sistemas'      => $area,
                            'estado'             => $tk['estado'] ?? 'En Proceso',
                            'prioridad'          => $tk['prioridad'] ?? 'Media',
                            'solicitante_nombre' => $creador['nombre'],
                            'solicitante_agencia'=> $creador['agencia'],
                            'asignado_a'         => $tk['asignado_a'] ?: $autorNombre,
                            'tipo_alerta'        => 'NUEVA RESPUESTA DE SOPORTE',
                            'color_badge'        => '#8b5cf6',
                            'detalle_html'       => "El especialista <strong>" . htmlspecialchars($autorNombre) . "</strong> ha dejado una respuesta en el ticket:\n\n\"" . htmlspecialchars($mensajeTexto) . "\"",
                            'enlace_boton'       => $enlaceTicketCreador,
                            'texto_boton'        => 'Ver y Responder en el Portal'
                        ]);
                        enviarCorreoSistemas($creador['email'], $creador['nombre'], $asunto, $html);
                    }
                }
            } 
            // CASO B: El solicitante/sucursal respondió -> Notificar al técnico asignado o a especialistas del área
            else {
                $tecnicoAsignado = trim($tk['asignado_a'] ?? '');
                $destinatarios = [];

                if (!empty($tecnicoAsignado)) {
                    // Buscar técnico por nombre o usuario
                    $stmtTec = $pdo->prepare("SELECT id, usuario, nombre, email FROM usuarios WHERE (nombre = ? OR usuario = ?) LIMIT 1");
                    $stmtTec->execute([$tecnicoAsignado, $tecnicoAsignado]);
                    $uTec = $stmtTec->fetch(PDO::FETCH_ASSOC);
                    if ($uTec) {
                        $destinatarios[] = $uTec;
                    }
                }

                if (empty($destinatarios)) {
                    $destinatarios = obtenerEspecialistasAreaTicket($pdo, $area);
                }

                if (empty($destinatarios)) {
                    try {
                        $stmtAdm = $pdo->query("SELECT id, usuario, nombre, email, rol, areas_tickets FROM usuarios WHERE (activo = 1 OR estatus = 1) AND LOWER(rol) IN ('superadmin', 'admin')");
                        $destinatarios = $stmtAdm ? $stmtAdm->fetchAll(PDO::FETCH_ASSOC) : [];
                    } catch (Throwable $tAdm) {}
                }

                $asunto = "[SISTEMAS GH] Mensaje del usuario en Ticket #$folio ($autorNombre)";
                $html = generarPlantillaCorreoTicket([
                    'folio'              => $folio,
                    'titulo'             => $tk['titulo'] ?? 'Ticket de Soporte',
                    'area_sistemas'      => $area,
                    'estado'             => $tk['estado'] ?? 'En Proceso',
                    'prioridad'          => $tk['prioridad'] ?? 'Media',
                    'solicitante_nombre' => $tk['solicitante_nombre'] ?? $autorNombre,
                    'solicitante_agencia'=> $tk['solicitante_agencia'] ?? '',
                    'asignado_a'         => $tecnicoAsignado ?: 'Por Asignar',
                    'tipo_alerta'        => 'NUEVA RESPUESTA DEL SOLICITANTE',
                    'color_badge'        => '#f59e0b',
                    'detalle_html'       => "El solicitante <strong>" . htmlspecialchars($autorNombre) . "</strong> ha enviado un nuevo mensaje en el ticket:\n\n\"" . htmlspecialchars($mensajeTexto) . "\"",
                    'enlace_boton'       => $enlaceTicketCentral,
                    'texto_boton'        => 'Atender Respuesta en el Portal'
                ]);

                foreach ($destinatarios as $d) {
                    if (!empty($d['id']) && function_exists('crearNotificacion')) {
                        crearNotificacion(
                            'tickets',
                            'mensaje_ticket',
                            "Respuesta en Ticket #$folio de $autorNombre",
                            "El solicitante envió: \"$resumenMsg\"",
                            'tickets.php?ver=' . $ticketId,
                            'bi-chat-left-text-fill',
                            'warning',
                            $ticketId,
                            $d['id'],
                            $pdo
                        );
                    }
                    if (!empty($d['email']) && filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
                        enviarCorreoSistemas($d['email'], $d['nombre'] ?? $d['usuario'], $asunto, $html);
                    }
                }
            }
        } catch (Throwable $e) {}
    }
}
