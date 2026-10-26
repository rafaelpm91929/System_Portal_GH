<#
.SYNOPSIS
    Agente Colector Nativo de Respaldos SMB/NAS para Windows (PowerShell)
.DESCRIPTION
    1. Consulta la API del Portal cPanel para obtener las NAS y carpetas a vigilar.
    2. Modo Descubrir (-Descubrir): Se conecta a la NAS local y detecta las carpetas reales para el portal.
    3. Escanea por red local (UNC/SMB) las carpetas de las computadoras.
    4. Evalúa la fecha del archivo más reciente en las últimas 24h y detecta archivos temporales (.tmp / .part).
    5. Envía un reporte JSON consolidado en un solo POST HTTPS a cPanel.
#>

[CmdletBinding()]
param(
    [string]$ApiUrl = "https://portal.pirineosmotors.com/api_respaldos.php",
    [string]$ApiToken = "COPIAR_TOKEN_AQUI",
    [switch]$Descubrir,
    [switch]$IgnorarSsl
)

$ErrorActionPreference = "Continue"
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# Cargar configuración desde colector_config.json si existe (solo si no se pasó por parámetro)
$ConfigFile = Join-Path $PSScriptRoot "colector_config.json"
if (Test-Path $ConfigFile) {
    try {
        $jsonConf = Get-Content -Raw -Path $ConfigFile -Encoding UTF8 | ConvertFrom-Json
        if ($jsonConf.api_url -and ($ApiUrl -eq "https://portal.pirineosmotors.com/api_respaldos.php")) { $ApiUrl = $jsonConf.api_url }
        if ($jsonConf.api_token -and ($ApiToken -eq "COPIAR_TOKEN_AQUI" -or [string]::IsNullOrWhiteSpace($ApiToken))) { $ApiToken = $jsonConf.api_token }
    } catch {}
}

function Write-ColectorLog {
    param([string]$Mensaje, [string]$Nivel = "INFO")
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $linea = "[$timestamp] [$Nivel] $Mensaje"
    Write-Host $linea
    try {
        $logPath = Join-Path $PSScriptRoot "colector_respaldos.log"
        Add-Content -Path $logPath -Value $linea -Encoding UTF8
    } catch {}
}

if ($IgnorarSsl) {
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = {$true}
}

Write-ColectorLog "=== INICIANDO COLECTOR NATIVO DE RESPALDOS SMB/NAS (PowerShell) ==="
$stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

if ($ApiToken -eq "COPIAR_TOKEN_AQUI" -or [string]::IsNullOrWhiteSpace($ApiToken)) {
    Write-ColectorLog "ERROR: Falta el Bearer Token del portal cPanel." "ERROR"
    Write-ColectorLog "-> Puedes pasarlo en el comando: .\colector_respaldos.ps1 -ApiToken 'TU_TOKEN' -Descubrir" "WARN"
    Write-ColectorLog "-> O coloca el archivo 'colector_config.json' descargado desde el portal en esta misma carpeta." "WARN"
    exit 1
}

# 1. Consultar rutas a vigilar desde la API
$urlConsulta = "$ApiUrl?action=obtener_rutas_vigilar"
Write-ColectorLog "Consultando rutas en: $urlConsulta"

$headers = @{
    "Authorization" = "Bearer $ApiToken"
    "User-Agent" = "ColectorRespaldosPS/2.0"
}

try {
    $respApi = Invoke-RestMethod -Uri $urlConsulta -Method Get -Headers $headers -TimeoutSec 45
} catch {
    Write-ColectorLog "Falla al conectar con la API: $_" "ERROR"
    exit 1
}

if (-not $respApi.success) {
    Write-ColectorLog "La API devolvió error: $($respApi.error)" "ERROR"
    exit 1
}

$nasList = $respApi.nas_list
$patronesDescarte = @("@recycle", "#snapshot", "@recently-snapshot", ".dropbox.cache", "aquota.user", "aquota.group", "thumbs.db", "desktop.ini", ".ds_store")
if ($respApi.config.patrones_descarte) {
    $patronesDescarte = $respApi.config.patrones_descarte
}

# MODO DESCUBRIMIENTO DE CARPETAS NAS
if ($Descubrir) {
    Write-ColectorLog "Modo Detección Activo: Conectando a las NAS para listar carpetas reales..."
    foreach ($nas in $nasList) {
        $nasId = $nas.id
        $nasNombre = $nas.nombre
        $ipRuta = $nas.ip_ruta_red.Trim()
        $usuario = $nas.usuario_smb
        $pass = $nas.password_smb

        Write-ColectorLog "Conectando a NAS '$nasNombre' ($ipRuta)..."

        # Conectar credenciales si se especifican
        if (![string]::IsNullOrEmpty($usuario) -and ![string]::IsNullOrEmpty($pass)) {
            try {
                $servidor = ($ipRuta -replace "/", "\").Split("\") | Where-Object { $_ } | Select-Object -First 1
                if ($servidor) {
                    net use "\\$servidor" /user:"$usuario" "$pass" /persistent:no | Out-Null
                    Write-ColectorLog "Credenciales SMB aplicadas para \\$servidor"
                }
            } catch {}
        }

        $carpetasEncontradas = [System.Collections.Generic.List[string]]::new()
        $rutaLimpia = $ipRuta.TrimEnd("\/")
        if (-not $rutaLimpia.StartsWith("\\")) { $rutaLimpia = "\\$rutaLimpia" }

        # Caso 1: Ruta con recurso UNC directo ej. \\192.168.1.100\Backups
        if (Test-Path $rutaLimpia) {
            try {
                $dirs = Get-ChildItem -Path $rutaLimpia -Directory -ErrorAction SilentlyContinue
                foreach ($d in $dirs) {
                    $carpetasEncontradas.Add($d.Name)
                }
            } catch {}
        }

        # Caso 2: Enumerar recursos compartidos del servidor con 'net view' si es solo IP
        if ($carpetasEncontradas.Count -eq 0) {
            try {
                $servidor = ($rutaLimpia.TrimStart("\")).Split("\")[0]
                $nvOut = net view "\\$servidor" 2>$null
                $enTabla = $false
                foreach ($linea in $nvOut) {
                    if ($linea -match "---") { $enTabla = $true; continue }
                    if ($enTabla -and -not [string]::IsNullOrWhiteSpace($linea)) {
                        $partes = $linea.Trim() -split "\s+"
                        if ($partes.Count -gt 0) {
                            $share = $partes[0].Trim()
                            if (-not $share.EndsWith("$")) {
                                $carpetasEncontradas.Add($share)
                            }
                        }
                    }
                }
            } catch {}
        }

        if ($carpetasEncontradas.Count -gt 0) {
            Write-ColectorLog "Carpetas encontradas en '$nasNombre': $($carpetasEncontradas -join ', ')"

            # Enviar lista a cPanel
            $urlReporteCarpetas = "$ApiUrl?action=reportar_carpetas_detectadas"
            $payloadCarpetas = @{
                nas_id = $nasId
                carpetas = @($carpetasEncontradas | ForEach-Object { @{ nombre_carpeta = $_ } })
            }
            $jsonCarpetas = $payloadCarpetas | ConvertTo-Json -Depth 5
            try {
                $respCarp = Invoke-RestMethod -Uri $urlReporteCarpetas -Method Post -Headers $headers -Body $jsonCarpetas -ContentType "application/json; charset=utf-8"
                if ($respCarp.success) {
                    Write-ColectorLog "¡Éxito! Carpetas de '$nasNombre' reportadas a cPanel. Ya aparecen en la pantalla del portal."
                } else {
                    Write-ColectorLog "El servidor respondió con aviso: $($respCarp.error)" "WARN"
                }
            } catch {
                Write-ColectorLog "Error al enviar carpetas a cPanel: $_" "ERROR"
            }
        } else {
            Write-ColectorLog "No se detectaron carpetas en '$ipRuta'. Verifica que la IP esté accesible desde esta máquina." "WARN"
        }
    }

    Write-ColectorLog "=== FIN DEL DESCUBRIMIENTO ==="
    exit 0
}

# MODO ESCANEO COMPLETO DE RESPALDOS
$extTemporales = @(".tmp", ".temp", ".part", ".partial", ".crdownload", ".aria2")
$resultadosEquipos = [System.Collections.Generic.List[PSObject]]::new()

foreach ($nas in $nasList) {
    $nasNombre = $nas.nombre
    $ipRuta = $nas.ip_ruta_red.Trim()
    $usuario = $nas.usuario_smb
    $pass = $nas.password_smb

    Write-ColectorLog "Procesando NAS '$nasNombre' ($ipRuta)..."

    # Mapeo de credenciales en Windows
    if (![string]::IsNullOrEmpty($usuario) -and ![string]::IsNullOrEmpty($pass)) {
        try {
            $servidor = ($ipRuta -replace "/", "\").Split("\") | Where-Object { $_ } | Select-Object -First 1
            if ($servidor) {
                net use "\\$servidor" /user:"$usuario" "$pass" /persistent:no | Out-Null
            }
        } catch {}
    }

    foreach ($carp in $nas.carpetas) {
        $carpNombre = $carp.nombre_carpeta
        $areaNombre = if ($carp.area_nombre) { $carp.area_nombre } else { "General" }
        $rutaCarpeta = Join-Path $ipRuta $carpNombre

        if (-not (Test-Path $rutaCarpeta)) {
            Write-ColectorLog "Ruta inaccesible: $rutaCarpeta" "WARN"
            continue
        }

        Write-ColectorLog "Escaneando área: $carpNombre [$areaNombre]..."

        try {
            $subCarpetas = Get-ChildItem -Path $rutaCarpeta -Directory -ErrorAction SilentlyContinue
        } catch {
            continue
        }

        foreach ($sub in $subCarpetas) {
            $descartar = $false
            foreach ($p in $patronesDescarte) {
                if ($sub.Name.ToLower().Contains($p.ToLower())) {
                    $descartar = $true
                    break
                }
            }
            if ($descartar) { continue }

            $nombreEquipo = $sub.Name.ToUpper()
            $rutaSub = $sub.FullName

            $archivos = Get-ChildItem -Path $rutaSub -Recurse -File -ErrorAction SilentlyContinue
            $ultimoArchivo = $null
            $temporales = [System.Collections.Generic.List[string]]::new()

            foreach ($f in $archivos) {
                $esTemp = $false
                foreach ($ext in $extTemporales) {
                    if ($f.Extension.ToLower() -eq $ext -or $f.Name.StartsWith("~$")) {
                        $esTemp = $true
                        $temporales.Add($f.Name)
                        break
                    }
                }
                if ($esTemp) { continue }

                if ($null -eq $ultimoArchivo -or $f.LastWriteTime -gt $ultimoArchivo.LastWriteTime) {
                    $ultimoArchivo = $f
                }
            }

            $ultimoFechaStr = if ($ultimoArchivo) { $ultimoArchivo.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss") } else { $null }
            $ultimoNombreStr = if ($ultimoArchivo) { $ultimoArchivo.Name } else { $null }
            $ultimoBytes = if ($ultimoArchivo) { $ultimoArchivo.Length } else { 0 }

            $resultadosEquipos.Add([PSCustomObject]@{
                nombre_equipo = $nombreEquipo
                usuario_responsable = $sub.Name
                area_nombre = $areaNombre
                nas_id = $nas.id
                carpeta_id = $carp.id
                ruta_carpeta = $rutaSub
                ultimo_archivo_nombre = $ultimoNombreStr
                ultimo_archivo_fecha = $ultimoFechaStr
                tamano_bytes = $ultimoBytes
                tiene_temporales = ($temporales.Count -gt 0)
                archivos_temporales = $temporales
            })
        }
    }
}

$stopwatch.Stop()
$duracionSeg = [math]::Round($stopwatch.Elapsed.TotalSeconds, 0)
Write-ColectorLog "Escaneo terminado. Equipos encontrados: $($resultadosEquipos.Count) en $duracionSeg s."

if ($resultadosEquipos.Count -gt 0) {
    $urlReporte = "$ApiUrl?action=reportar_respaldo_consolidado"
    Write-ColectorLog "Enviando reporte consolidado a cPanel..."

    $payload = @{
        agente_ip = "127.0.0.1"
        fecha_escaneo = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        duracion_segundos = $duracionSeg
        equipos = $resultadosEquipos
    }

    $jsonBody = $payload | ConvertTo-Json -Depth 5

    try {
        $respPost = Invoke-RestMethod -Uri $urlReporte -Method Post -Headers $headers -Body $jsonBody -ContentType "application/json; charset=utf-8" -TimeoutSec 60
        if ($respPost.success) {
            Write-ColectorLog "¡Reporte procesado exitosamente en cPanel! Total procesados: $($respPost.procesados)"
        } else {
            Write-ColectorLog "El servidor respondió con error: $($respPost.error)" "ERROR"
        }
    } catch {
        Write-ColectorLog "Error al enviar reporte a cPanel: $_" "ERROR"
    }
} else {
    Write-ColectorLog "No se detectaron respaldos para reportar." "WARN"
}

Write-ColectorLog "=== FIN DEL PROCESO ==="
