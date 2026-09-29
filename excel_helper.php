<?php
/**
 * Helper Universal para Exportación a Microsoft Excel (.xls) con Diseño Corporativo Oficial
 * Utiliza formato MHTML (MIME-HTML multipart/related) para incrustar logotipos como imágenes
 * nativas reales (msoPicture). Esto EVITA el error de "No se puede mostrar la imagen vinculada"
 * y la X roja en todas las versiones de Microsoft Excel para Windows/Mac.
 * Compatible con cPanel MySQL y Local SQLite, PHP 7.x y 8.x.
 */

if (!function_exists('obtenerDatosAgenciaExcel')) {
    function obtenerDatosAgenciaExcel($pdo = null, $agenciaSesion = '') {
        if (empty($agenciaSesion) && isset($_SESSION['agencia'])) {
            $agenciaSesion = $_SESSION['agencia'];
        }

        $agenciaData = null;
        if ($pdo) {
            try {
                if (!empty($agenciaSesion)) {
                    $stmtA = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
                    $stmtA->execute([$agenciaSesion]);
                    $agenciaData = $stmtA->fetch(PDO::FETCH_ASSOC);

                    if (!$agenciaData) {
                        $stmtA2 = $pdo->prepare("SELECT * FROM agencias WHERE LOWER(nombre) LIKE LOWER(?) OR LOWER(?) LIKE '%' || LOWER(nombre) || '%' LIMIT 1");
                        $stmtA2->execute(['%' . $agenciaSesion . '%', $agenciaSesion]);
                        $agenciaData = $stmtA2->fetch(PDO::FETCH_ASSOC);
                    }
                }
                if (!$agenciaData) {
                    $stmtDef = $pdo->query("SELECT * FROM agencias ORDER BY id ASC LIMIT 1");
                    $agenciaData = $stmtDef ? $stmtDef->fetch(PDO::FETCH_ASSOC) : null;
                }
            } catch (Throwable $t) {
                $agenciaData = null;
            }
        }

        $agenciaNombre    = !empty($agenciaData['nombre']) ? $agenciaData['nombre'] : ($agenciaSesion ?: 'GRUPO HUERTA');
        $agenciaRazon     = !empty($agenciaData['razon_social']) ? $agenciaData['razon_social'] : 'Distribuidora de Vehículos de Oriente SAPI de CV';
        $agenciaRfc       = !empty($agenciaData['rfc']) ? $agenciaData['rfc'] : 'XAXX010101000';
        $agenciaDireccion = !empty($agenciaData['direccion']) ? $agenciaData['direccion'] : 'Av. Ferrocarril Hidalgo 883, Aragón, CDMX';
        $agenciaTel       = !empty($agenciaData['telefono_sistemas']) ? $agenciaData['telefono_sistemas'] : '';
        $agenciaEmail     = !empty($agenciaData['correo_sistemas']) ? $agenciaData['correo_sistemas'] : '';
        $agenciaEncargado = !empty($agenciaData['encargado_sistemas']) ? $agenciaData['encargado_sistemas'] : 'Departamento de Sistemas';
        $logoRel          = $agenciaData['logo_url'] ?? '';

        // Procesar Logo (Base64 puro y tipo MIME)
        $logoBase64Puro = '';
        $logoMimeType   = 'image/png';
        if (!empty($logoRel)) {
            $logoPaths = [
                $logoRel,
                __DIR__ . '/' . $logoRel,
                __DIR__ . '/' . ltrim($logoRel, '/'),
                ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . ltrim($logoRel, '/')
            ];
            foreach ($logoPaths as $lp) {
                if (!empty($lp) && file_exists($lp) && is_file($lp)) {
                    $sz = @getimagesize($lp);
                    if ($sz && !empty($sz['mime'])) {
                        $logoMimeType = $sz['mime'];
                        $content = @file_get_contents($lp);
                        if ($content !== false) {
                            $logoBase64Puro = base64_encode($content);
                            break;
                        }
                    }
                }
            }
        }

        $logoBase64Completo = !empty($logoBase64Puro) ? 'data:' . $logoMimeType . ';base64,' . $logoBase64Puro : '';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $rawUriDir = (php_sapi_name() === 'cli') ? '' : dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $uriDir = str_replace('\\', '/', $rawUriDir);
        $logoHttp = '';
        if (!empty($logoRel)) {
            if (strpos($logoRel, 'http') === 0) {
                $logoHttp = $logoRel;
            } else {
                $baseUri = rtrim($protocol . $host . '/' . ltrim($uriDir, '/'), '/');
                $logoHttp = $baseUri . '/' . ltrim($logoRel, '/');
            }
        }

        return [
            'nombre'             => $agenciaNombre,
            'razon_social'       => $agenciaRazon,
            'rfc'                => $agenciaRfc,
            'direccion'          => $agenciaDireccion,
            'telefono'           => $agenciaTel,
            'correo'             => $agenciaEmail,
            'encargado_sistemas' => $agenciaEncargado,
            'logo_url'           => $logoRel,
            'logo_base64_puro'   => $logoBase64Puro,
            'logo_mime_type'     => $logoMimeType,
            'logo_base64'        => $logoBase64Completo,
            'logo_http'          => $logoHttp
        ];
    }
}

if (!function_exists('generarHtmlExcelDocumento')) {
    function generarHtmlExcelDocumento($tituloDoc, $subtituloDoc, $columnas, $filas, $agenciaData, $nombreHoja = 'Datos', $esParaMhtml = false) {
        $fechaHoy = date('d/m/Y');
        $horaHoy  = date('H:i');
        $totalCols = max(4, count($columnas));
        $colspanHeader = $totalCols - 1;

        $tieneLogo = !empty($agenciaData['logo_base64_puro']) || !empty($agenciaData['logo_base64']);
        // En MHTML la referencia local interna garantiza que Excel muestre la imagen sin bloquearla
        $imgSrc = $esParaMhtml ? 'file:///C:/agencia_logo.png' : ($agenciaData['logo_http'] ?: $agenciaData['logo_base64']);

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        $html .= '<head>';
        $html .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
        $html .= '<!--[if gte mso 9]>';
        $html .= '<xml>';
        $html .= ' <x:ExcelWorkbook>';
        $html .= '  <x:ExcelWorksheets>';
        $html .= '   <x:ExcelWorksheet>';
        $html .= '    <x:Name>' . htmlspecialchars(substr($nombreHoja, 0, 31)) . '</x:Name>';
        $html .= '    <x:WorksheetOptions>';
        $html .= '     <x:DisplayGridlines/>';
        $html .= '     <x:Print><x:ValidPrinterInfo/><x:PaperSizeIndex>1</x:PaperSizeIndex></x:Print>';
        $html .= '    </x:WorksheetOptions>';
        $html .= '   </x:ExcelWorksheet>';
        $html .= '  </x:ExcelWorksheets>';
        $html .= ' </x:ExcelWorkbook>';
        $html .= '</xml>';
        $html .= '<![endif]-->';

        $html .= '<style>';
        $html .= 'body, table, td, th { font-family: "Segoe UI", Calibri, Arial, sans-serif; font-size: 10pt; }';
        $html .= '.header-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }';
        $html .= '.logo-cell { width: 150px; min-width: 150px; text-align: center; vertical-align: middle; background-color: #ffffff; border: 1px solid #cbd5e1; padding: 6px; }';
        $html .= '.title-agencia { font-size: 16pt; font-weight: bold; color: #0f172a; padding-left: 15px; height: 28px; }';
        $html .= '.subtitle-doc { font-size: 12pt; font-weight: bold; color: #0284c7; padding-left: 15px; height: 22px; }';
        $html .= '.meta-text { font-size: 9pt; color: #475569; padding-left: 15px; height: 18px; }';
        $html .= '.badge-header { font-size: 8.5pt; font-weight: bold; color: #1e293b; background-color: #f1f5f9; padding: 4px 8px; border: 1px solid #cbd5e1; }';
        $html .= '.data-table { width: 100%; border-collapse: collapse; }';
        $html .= '.th-col { background-color: #0f172a; color: #ffffff; font-weight: bold; font-size: 9.5pt; text-align: center; border: 1px solid #334155; padding: 8px 6px; height: 32px; }';
        $html .= '.td-cell { font-size: 9pt; border: 1px solid #cbd5e1; padding: 5px 7px; vertical-align: middle; color: #1e293b; }';
        $html .= '.td-center { text-align: center; }';
        $html .= '.td-right { text-align: right; }';
        $html .= '.td-text { mso-number-format: "\@"; }';
        $html .= '.row-even { background-color: #f8fafc; }';
        $html .= '.row-odd { background-color: #ffffff; }';
        $html .= '.badge-pill { font-weight: bold; padding: 2px 6px; border-radius: 4px; text-align: center; font-size: 8.5pt; }';
        $html .= '.badge-area { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }';
        $html .= '.badge-ext { background-color: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; font-weight: bold; }';
        $html .= '.badge-status-ok { background-color: #dcfce7; color: #15803d; }';
        $html .= '.badge-status-warn { background-color: #fef3c7; color: #b45309; }';
        $html .= '.badge-status-err { background-color: #fee2e2; color: #b91c1c; }';
        $html .= '.tfoot-summary { background-color: #f1f5f9; font-weight: bold; font-size: 9pt; border: 1px solid #94a3b8; padding: 8px 10px; color: #334155; }';
        $html .= '</style>';
        $html .= '</head>';
        $html .= '<body>';

        $colspanLogo = ($totalCols >= 4) ? 2 : 1;
        $colspanText = max(1, $totalCols - $colspanLogo);

        // 1. MEMBRETE OFICIAL DE LA AGENCIA
        $html .= '<table class="header-table">';
        $html .= '<tr style="height: 28px;">';
        $html .= '<td colspan="' . $colspanLogo . '" rowspan="4" class="logo-cell">';
        if ($tieneLogo) {
            $html .= '<img src="' . $imgSrc . '" width="130" height="50" style="max-height: 52px; max-width: 130px;" alt="Logo ' . htmlspecialchars($agenciaData['nombre']) . '">';
        } else {
            $html .= '<div style="font-size: 15pt; font-weight: bold; color: #0284c7;">' . htmlspecialchars($agenciaData['nombre']) . '</div>';
        }
        $html .= '</td>';
        $html .= '<td colspan="' . $colspanText . '" class="title-agencia">' . htmlspecialchars($agenciaData['nombre']) . '</td>';
        $html .= '</tr>';

        $html .= '<tr style="height: 24px;">';
        $html .= '<td colspan="' . $colspanText . '" class="subtitle-doc">' . htmlspecialchars($tituloDoc) . (!empty($subtituloDoc) ? ' &mdash; <span style="font-size: 10pt; color: #64748b; font-weight: normal;">' . htmlspecialchars($subtituloDoc) . '</span>' : '') . '</td>';
        $html .= '</tr>';

        $html .= '<tr style="height: 20px;">';
        $html .= '<td colspan="' . $colspanText . '" class="meta-text">';
        $html .= '<strong>Razón Social:</strong> ' . htmlspecialchars($agenciaData['razon_social']) . ' &nbsp;|&nbsp; ';
        $html .= '<strong>RFC:</strong> ' . htmlspecialchars($agenciaData['rfc']) . ' &nbsp;|&nbsp; ';
        $html .= '<strong>Dirección:</strong> ' . htmlspecialchars($agenciaData['direccion']);
        $html .= '</td>';
        $html .= '</tr>';

        $html .= '<tr style="height: 20px;">';
        $html .= '<td colspan="' . $colspanText . '" class="meta-text">';
        $html .= '<strong>Fecha de Emisión:</strong> ' . $fechaHoy . ' ' . $horaHoy . ' hrs &nbsp;|&nbsp; ';
        $html .= '<strong>Control de Sistemas:</strong> ' . htmlspecialchars($agenciaData['encargado_sistemas']) . ' &nbsp;|&nbsp; ';
        $html .= '<strong>Total de Registros:</strong> ' . count($filas);
        $html .= '</td>';
        $html .= '</tr>';

        // Fila espaciadora
        $html .= '<tr style="height: 12px;"><td colspan="' . $totalCols . '" style="border: none;"></td></tr>';
        $html .= '</table>';

        // 2. TABLA PRINCIPAL DE DATOS
        $html .= '<table class="data-table">';
        $html .= '<thead><tr>';
        foreach ($columnas as $col) {
            $colTitle = is_array($col) ? ($col['label'] ?? '') : $col;
            $colWidth = is_array($col) && !empty($col['width']) ? ' style="width: ' . $col['width'] . ';"' : '';
            $html .= '<th class="th-col"' . $colWidth . '>' . htmlspecialchars($colTitle) . '</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        if (empty($filas)) {
            $html .= '<tr><td colspan="' . $totalCols . '" class="td-cell td-center" style="padding: 25px; color: #64748b;">No hay registros disponibles para exportar.</td></tr>';
        } else {
            $rowIndex = 0;
            foreach ($filas as $fila) {
                $rowClass = ($rowIndex % 2 === 0) ? 'row-even' : 'row-odd';
                $html .= '<tr class="' . $rowClass . '">';
                
                $colIndex = 0;
                foreach ($fila as $celda) {
                    $align = 'left';
                    $isText = false;
                    $customClass = '';
                    $val = $celda;

                    if (is_array($celda)) {
                        $val = $celda['val'] ?? '';
                        $align = $celda['align'] ?? 'left';
                        $isText = !empty($celda['is_text']);
                        $customClass = $celda['class'] ?? '';
                    } else {
                        if (isset($columnas[$colIndex]) && is_array($columnas[$colIndex])) {
                            $align = $columnas[$colIndex]['align'] ?? 'left';
                            $isText = !empty($columnas[$colIndex]['is_text']);
                        }
                    }

                    $alignClass = ($align === 'center') ? 'td-center ' : (($align === 'right') ? 'td-right ' : '');
                    $textClass = $isText ? 'td-text ' : '';

                    $html .= '<td class="td-cell ' . $alignClass . $textClass . $customClass . '">' . $val . '</td>';
                    $colIndex++;
                }
                $html .= '</tr>';
                $rowIndex++;
            }
        }
        $html .= '</tbody>';

        // 3. PIE DE PÁGINA DE RESUMEN
        $html .= '<tfoot>';
        $html .= '<tr>';
        $html .= '<td colspan="' . $totalCols . '" class="tfoot-summary">';
        $html .= '<div style="display: flex; justify-content: space-between;">';
        $html .= '<span><strong>Reporte Oficial de Sistemas</strong> | ' . htmlspecialchars($agenciaData['nombre']) . '</span> &nbsp;&mdash;&nbsp; ';
        $html .= '<span>Registros Emitidos: <strong>' . count($filas) . '</strong> | Generado: ' . $fechaHoy . ' ' . $horaHoy . ' hrs</span>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '</tr>';
        $html .= '</tfoot>';

        $html .= '</table>';
        $html .= '</body></html>';

        return $html;
    }
}

/**
 * Empaqueta un documento HTML y su imagen en formato MHTML (MIME multipart/related).
 * Esto permite a Excel cargar la imagen como parte integral del archivo (.xls),
 * eliminando por completo el mensaje de error de imagen vinculada o la cruz roja.
 */
if (!function_exists('empaquetarMhtmlExcel')) {
    function empaquetarMhtmlExcel($htmlContenido, $logoBase64Raw = '', $mimeType = 'image/png') {
        $boundary = "----=_NextPart_01D9" . strtoupper(bin2hex(random_bytes(4)));

        $mhtml = "MIME-Version: 1.0\r\n";
        $mhtml .= "Content-Type: multipart/related; boundary=\"{$boundary}\"\r\n\r\n";

        // PARTE 1: Documento HTML
        $mhtml .= "--{$boundary}\r\n";
        $mhtml .= "Content-Location: file:///C:/excel_sheet.htm\r\n";
        $mhtml .= "Content-Transfer-Encoding: 7bit\r\n";
        $mhtml .= "Content-Type: text/html; charset=\"utf-8\"\r\n\r\n";
        $mhtml .= $htmlContenido . "\r\n\r\n";

        // PARTE 2: Imagen incrustada como recurso interno
        if (!empty($logoBase64Raw)) {
            $mhtml .= "--{$boundary}\r\n";
            $mhtml .= "Content-Location: file:///C:/agencia_logo.png\r\n";
            $mhtml .= "Content-Transfer-Encoding: base64\r\n";
            $mhtml .= "Content-Type: {$mimeType}\r\n\r\n";
            $mhtml .= chunk_split($logoBase64Raw, 76, "\r\n") . "\r\n\r\n";
        }

        $mhtml .= "--{$boundary}--\r\n";
        return $mhtml;
    }
}

if (!function_exists('descargarExcelConDiseno')) {
    function descargarExcelConDiseno($tituloDoc, $subtituloDoc, $columnas, $filas, $agenciaData = null, $nombreArchivo = null, $nombreHoja = 'Datos') {
        global $pdo;

        if ($agenciaData === null) {
            $agenciaData = obtenerDatosAgenciaExcel($pdo);
        }

        if (empty($nombreArchivo)) {
            $nombreLimpio = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agenciaData['nombre']);
            $tituloLimpio = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tituloDoc);
            $nombreArchivo = "{$tituloLimpio}_{$nombreLimpio}_" . date('Ymd_His') . ".xls";
        } elseif (!preg_match('/\.xls$/i', $nombreArchivo)) {
            $nombreArchivo .= '.xls';
        }

        // Limpiar buffers previos para evitar corrupción
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$nombreArchivo}\"");
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');
        header('Expires: 0');

        $html = generarHtmlExcelDocumento($tituloDoc, $subtituloDoc, $columnas, $filas, $agenciaData, $nombreHoja, true);
        $rawLogo = $agenciaData['logo_base64_puro'] ?? '';
        $mimeLogo = $agenciaData['logo_mime_type'] ?? 'image/png';

        if (!empty($rawLogo)) {
            echo empaquetarMhtmlExcel($html, $rawLogo, $mimeLogo);
        } else {
            // Si no hay logo, emitir HTML con BOM
            echo chr(0xEF) . chr(0xBB) . chr(0xBF);
            echo $html;
        }
        exit();
    }
}

/**
 * Función para imprimir el script JS que permite a cualquier vista con tabla
 * exportar inmediatamente a Excel con el encabezado oficial y logotipo incrustado vía MHTML.
 */
if (!function_exists('imprimirScriptExportadorExcelJS')) {
    function imprimirScriptExportadorExcelJS($agenciaData = null) {
        global $pdo;
        if ($agenciaData === null) {
            $agenciaData = obtenerDatosAgenciaExcel($pdo);
        }
        $rawLogo = $agenciaData['logo_base64_puro'] ?? '';
        $mimeLogo = $agenciaData['logo_mime_type'] ?? 'image/png';
        ?>
        <script>
        window.DATOS_AGENCIA_EXCEL = {
            nombre: <?php echo json_encode($agenciaData['nombre']); ?>,
            razon_social: <?php echo json_encode($agenciaData['razon_social']); ?>,
            rfc: <?php echo json_encode($agenciaData['rfc']); ?>,
            direccion: <?php echo json_encode($agenciaData['direccion']); ?>,
            encargado: <?php echo json_encode($agenciaData['encargado_sistemas']); ?>,
            logo_raw: <?php echo json_encode($rawLogo); ?>,
            logo_mime: <?php echo json_encode($mimeLogo); ?>
        };

        function exportarTablaAExcelConDiseno(tableId, tituloDoc, subtituloDoc, nombreArchivo) {
            const table = document.getElementById(tableId);
            if (!table) {
                alert('No se encontró la tabla de datos a exportar (' + tableId + ').');
                return;
            }

            const agencia = window.DATOS_AGENCIA_EXCEL || {
                nombre: 'GRUPO HUERTA',
                razon_social: 'Distribuidora de Vehículos de Oriente SAPI de CV',
                rfc: 'XAXX010101000',
                direccion: 'Av. Ferrocarril Hidalgo 883, CDMX',
                encargado: 'Departamento de Sistemas',
                logo_raw: '',
                logo_mime: 'image/png'
            };

            const now = new Date();
            const fechaHoy = now.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
            const horaHoy = now.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });

            // Clonar tabla para limpiar botones y acciones
            const clone = table.cloneNode(true);

            // Eliminar columnas de acciones / expediente si existen
            const rows = clone.querySelectorAll('tr');
            let actionColIndex = -1;

            if (rows.length > 0) {
                const headerCells = rows[0].querySelectorAll('th, td');
                headerCells.forEach((cell, idx) => {
                    const txt = cell.innerText.trim().toLowerCase();
                    if (txt.includes('accion') || txt.includes('acciones') || txt.includes('expediente') || txt.includes('opciones')) {
                        actionColIndex = idx;
                    }
                });
            }

            // Limpiar celdas en todas las filas
            let countFilas = 0;
            rows.forEach((r, rIdx) => {
                if (rIdx > 0) countFilas++;
                const cells = r.querySelectorAll('th, td');
                if (actionColIndex >= 0 && cells[actionColIndex]) {
                    cells[actionColIndex].remove();
                }
                // Limpiar botones, íconos de ojo y toggles dentro de las celdas
                r.querySelectorAll('button, .btn, input[type="checkbox"], script').forEach(el => el.remove());
                
                // Mostrar passwords si estaban en data-pass
                r.querySelectorAll('.pass-cell, [data-pass]').forEach(el => {
                    const pass = el.getAttribute('data-pass');
                    if (pass) el.innerText = pass;
                });
            });

            // Contar columnas restantes
            const remainingCols = rows[0] ? rows[0].querySelectorAll('th, td').length : 5;
            const colspanLogo = (remainingCols >= 4) ? 2 : 1;
            const colspanText = Math.max(1, remainingCols - colspanLogo);

            let headerHtml = `
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-family: 'Segoe UI', Arial, sans-serif;">
                <tr style="height: 28px;">
                    <td colspan="${colspanLogo}" rowspan="4" style="width: 150px; min-width: 150px; text-align: center; vertical-align: middle; background-color: #ffffff; border: 1px solid #cbd5e1; padding: 6px;">
                        ${agencia.logo_raw ? `<img src="file:///C:/agencia_logo.png" width="130" height="50" style="max-height: 52px; max-width: 130px;" alt="Logo">` : `<b style="color: #0284c7; font-size: 14pt;">${agencia.nombre}</b>`}
                    </td>
                    <td colspan="${colspanText}" style="font-size: 16pt; font-weight: bold; color: #0f172a; padding-left: 15px; height: 28px;">
                        ${agencia.nombre}
                    </td>
                </tr>
                <tr style="height: 24px;">
                    <td colspan="${colspanText}" style="font-size: 12pt; font-weight: bold; color: #0284c7; padding-left: 15px; height: 22px;">
                        ${tituloDoc} ${subtituloDoc ? `&mdash; <span style="font-size: 10pt; color: #64748b; font-weight: normal;">${subtituloDoc}</span>` : ''}
                    </td>
                </tr>
                <tr style="height: 20px;">
                    <td colspan="${colspanText}" style="font-size: 9pt; color: #475569; padding-left: 15px; height: 18px;">
                        <strong>Razón Social:</strong> ${agencia.razon_social} &nbsp;|&nbsp; <strong>RFC:</strong> ${agencia.rfc} &nbsp;|&nbsp; <strong>Dirección:</strong> ${agencia.direccion}
                    </td>
                </tr>
                <tr style="height: 20px;">
                    <td colspan="${colspanText}" style="font-size: 9pt; color: #475569; padding-left: 15px; height: 18px;">
                        <strong>Fecha de Emisión:</strong> ${fechaHoy} ${horaHoy} hrs &nbsp;|&nbsp; <strong>Control de Sistemas:</strong> ${agencia.encargado} &nbsp;|&nbsp; <strong>Total Registros:</strong> ${countFilas}
                    </td>
                </tr>
                <tr style="height: 12px;"><td colspan="${remainingCols}" style="border: none;"></td></tr>
            </table>
            `;

            // Aplicar estilos a la tabla clonada
            clone.style.width = '100%';
            clone.style.borderCollapse = 'collapse';
            clone.style.fontFamily = "'Segoe UI', Arial, sans-serif";
            clone.style.fontSize = "9.5pt";

            clone.querySelectorAll('thead th, thead td').forEach(th => {
                th.style.backgroundColor = '#0f172a';
                th.style.color = '#ffffff';
                th.style.fontWeight = 'bold';
                th.style.fontSize = '9.5pt';
                th.style.textAlign = 'center';
                th.style.border = '1px solid #334155';
                th.style.padding = '8px 6px';
                th.style.height = '32px';
            });

            clone.querySelectorAll('tbody tr').forEach((tr, idx) => {
                tr.style.backgroundColor = (idx % 2 === 0) ? '#f8fafc' : '#ffffff';
                tr.querySelectorAll('td').forEach(td => {
                    td.style.border = '1px solid #cbd5e1';
                    td.style.padding = '5px 7px';
                    td.style.verticalAlign = 'middle';
                    td.style.fontSize = '9pt';
                    td.style.color = '#1e293b';
                    td.setAttribute('style', td.getAttribute('style') + '; mso-number-format: "\\@";');
                });
            });

            // Resumen inferior
            const tfoot = document.createElement('tfoot');
            tfoot.innerHTML = `
                <tr>
                    <td colspan="${remainingCols}" style="background-color: #f1f5f9; font-weight: bold; font-size: 9pt; border: 1px solid #94a3b8; padding: 8px 10px; color: #334155;">
                        Reporte Oficial emitido por el Portal de Sistemas Grupo Huerta &bull; ${agencia.nombre} (${fechaHoy} ${horaHoy} hrs) &bull; Total: ${countFilas} registros
                    </td>
                </tr>
            `;
            clone.appendChild(tfoot);

            const htmlBody = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head>
                    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                    <!--[if gte mso 9]>
                    <xml>
                        <x:ExcelWorkbook>
                            <x:ExcelWorksheets>
                                <x:ExcelWorksheet>
                                    <x:Name>Reporte</x:Name>
                                    <x:WorksheetOptions>
                                        <x:DisplayGridlines/>
                                    </x:WorksheetOptions>
                                </x:ExcelWorksheet>
                            </x:ExcelWorksheets>
                        </x:ExcelWorkbook>
                    </xml>
                    <![endif]-->
                    <style>
                        body { font-family: 'Segoe UI', Arial, sans-serif; }
                    </style>
                </head>
                <body>
                    ${headerHtml}
                    ${clone.outerHTML}
                </body>
                </html>
            `;

            let finalContent = "";
            let finalType = "application/vnd.ms-excel;charset=utf-8;";

            if (agencia.logo_raw) {
                const boundary = "----=_NextPart_01D9" + Math.random().toString(36).substring(2).toUpperCase();
                finalContent = [
                    'MIME-Version: 1.0',
                    'Content-Type: multipart/related; boundary="' + boundary + '"',
                    '',
                    '--' + boundary,
                    'Content-Location: file:///C:/sheet.htm',
                    'Content-Type: text/html; charset=utf-8',
                    'Content-Transfer-Encoding: 7bit',
                    '',
                    htmlBody,
                    '',
                    '--' + boundary,
                    'Content-Location: file:///C:/agencia_logo.png',
                    'Content-Transfer-Encoding: base64',
                    'Content-Type: ' + (agencia.logo_mime || 'image/png'),
                    '',
                    agencia.logo_raw,
                    '',
                    '--' + boundary + '--'
                ].join('\r\n');
            } else {
                finalContent = '\uFEFF' + htmlBody;
            }

            const blob = new Blob([finalContent], { type: finalType });
            const link = document.createElement('a');
            const cleanFilename = (nombreArchivo || 'Reporte_Oficial').replace(/[^a-zA-Z0-9_-]/g, '_') + '_' + now.toISOString().slice(0, 10) + '.xls';
            link.href = URL.createObjectURL(blob);
            link.download = cleanFilename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        </script>
        <?php
    }
}
