#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
=============================================================================
AGENTE COLECTOR DE RESPALDOS SMB / NAS (RED LOCAL LAN)
=============================================================================
Corre en una sola computadora o servidor dentro de la red local de la agencia.
1. Consulta la API del Portal cPanel para saber qué NAS y carpetas vigilar.
2. Modo Detección (--descubrir): Conecta a la NAS y reporta al portal las
   carpetas compartidas que existen para que aparezcan en pantalla con checkboxes.
3. Conecta por SMB local a los dispositivos NAS (QNAP, Buffalo, Synology, etc.).
4. Escanea las carpetas de las computadoras (evalúa el archivo más reciente en 
   las últimas 24h, detecta archivos temporales o incompletos .tmp / .part).
5. Envía un reporte JSON consolidado en un solo envío HTTPS a cPanel.
=============================================================================
"""

import os
import sys
import json
import time
import datetime
import urllib.request
import urllib.error
import ssl
import subprocess

# =============================================================================
# CONFIGURACIÓN PREDETERMINADA (Puede ser sobreescrita por colector_config.json)
# =============================================================================
CONFIG_FILE = "colector_config.json"
LOG_FILE = "colector_respaldos.log"

DEFAULT_CONFIG = {
    "api_url": "https://portal.pirineosmotors.com/api_respaldos.php",
    "api_token": "COPIAR_TOKEN_AQUI",
    "timeout_segundos": 45,
    "verificar_ssl": True,
    "extensiones_temporales": [".tmp", ".temp", ".part", ".partial", ".crdownload", ".aria2", "~$"],
    "patrones_descarte": ["@recycle", "#snapshot", "@recently-snapshot", ".dropbox.cache", "aquota.user", "aquota.group", "thumbs.db", "desktop.ini", ".ds_store"]
}

def log(mensaje, nivel="INFO"):
    ahora = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    linea = f"[{ahora}] [{nivel}] {mensaje}"
    print(linea)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(linea + "\n")
    except Exception:
        pass

def cargar_configuracion():
    cfg = DEFAULT_CONFIG.copy()
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                cargado = json.load(f)
                cfg.update(cargado)
                log(f"Configuración cargada exitosamente desde '{CONFIG_FILE}'")
        except Exception as e:
            log(f"Error al leer '{CONFIG_FILE}', usando valores por defecto: {e}", "WARN")
    else:
        try:
            with open(CONFIG_FILE, "w", encoding="utf-8") as f:
                json.dump(cfg, f, indent=4)
                log(f"Archivo de configuración inicial generado: '{CONFIG_FILE}'")
        except Exception as e:
            log(f"No se pudo crear '{CONFIG_FILE}': {e}", "WARN")
    return cfg

def montar_recurso_red_windows(ruta_unc, usuario=None, password=None):
    """
    En Windows, mapea credenciales para acceder a la ruta UNC si se proporcionan.
    """
    if os.name != "nt" or not usuario or not password:
        return
    try:
        # Extraer servidor de la ruta \\192.168.1.100\share -> \\192.168.1.100
        partes = ruta_unc.replace("/", "\\").split("\\")
        servidor = None
        for p in partes:
            if p:
                servidor = f"\\\\{p}"
                break
        if servidor:
            cmd = f'net use "{servidor}" /user:"{usuario}" "{password}" /persistent:no'
            subprocess.run(cmd, shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            log(f"Credenciales SMB aplicadas para: {servidor}")
    except Exception as e:
        log(f"Aviso al autenticar recurso de red: {e}", "WARN")

def consultar_rutas_api(cfg):
    """
    Consulta a la API del portal las NAS y carpetas a vigilar
    """
    url = f"{cfg['api_url']}?action=obtener_rutas_vigilar"
    log(f"Consultando rutas y configuración en: {url}")

    req = urllib.request.Request(url)
    req.add_header("Authorization", f"Bearer {cfg['api_token']}")
    req.add_header("User-Agent", "ColectorRespaldosLAN/2.0")

    ctx = ssl.create_default_context()
    if not cfg.get("verificar_ssl", True):
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    try:
        with urllib.request.urlopen(req, timeout=cfg.get("timeout_segundos", 30), context=ctx) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            if not data.get("success"):
                log(f"La API respondió con error: {data.get('error')}", "ERROR")
                return None
            return data
    except urllib.error.HTTPError as e:
        log(f"Error HTTP al consultar API ({e.code}): {e.reason}", "ERROR")
    except Exception as e:
        log(f"Falla de conexión al consultar API: {e}", "ERROR")
    return None

def es_nombre_descartable(nombre, patrones):
    nom_lower = nombre.lower()
    for p in patrones:
        if p.lower() in nom_lower:
            return True
    return False

def es_archivo_temporal(nombre, extensiones):
    nom_lower = nombre.lower()
    for ext in extensiones:
        if nom_lower.endswith(ext.lower()) or nom_lower.startswith("~$"):
            return True
    return False

def descubrir_carpetas_nas(nas, cfg):
    """
    Se conecta a la NAS especificada por SMB y lista las carpetas compartidas reales,
    enviándolas al cPanel para que aparezcan en el Asistente del Portal.
    """
    nas_id = nas.get("id")
    nas_nombre = nas.get("nombre", f"NAS_{nas_id}")
    ip_ruta = nas.get("ip_ruta_red", "").strip()
    usuario_smb = nas.get("usuario_smb")
    password_smb = nas.get("password_smb")

    log(f"Iniciando descubrimiento de carpetas en NAS '{nas_nombre}' ({ip_ruta})...")

    if os.name == "nt" and usuario_smb and password_smb:
        montar_recurso_red_windows(ip_ruta, usuario_smb, password_smb)

    carpetas_encontradas = []

    # Caso 1: La ruta dada ya es un recurso UNC o carpeta accesible
    ruta_limpia = ip_ruta.rstrip("\\/ ")
    if not ruta_limpia.startswith("\\\\") and not ruta_limpia.startswith("//"):
        ruta_limpia = f"\\\\{ruta_limpia}"

    # Si la ruta tiene subrecurso ej. \\192.168.1.100\Backups
    partes = [p for p in ruta_limpia.replace("/", "\\").split("\\") if p]
    if len(partes) >= 2 and os.path.exists(ruta_limpia):
        try:
            for elem in os.listdir(ruta_limpia):
                full = os.path.join(ruta_limpia, elem)
                if os.path.isdir(full):
                    carpetas_encontradas.append(elem)
        except Exception as e:
            log(f"Error al listar '{ruta_limpia}': {e}", "WARN")

    # Caso 2: En Windows, enumerar recursos compartidos del servidor si solo se puso la IP
    if not carpetas_encontradas and os.name == "nt":
        servidor = f"\\\\{partes[0]}" if partes else ruta_limpia
        try:
            cmd = f'net view "{servidor}"'
            out = subprocess.check_output(cmd, shell=True, stderr=subprocess.DEVNULL).decode("cp850", errors="ignore")
            lineas = out.splitlines()
            en_tabla = False
            for l in lineas:
                if "---" in l:
                    en_tabla = True
                    continue
                if en_tabla and l.strip():
                    partes_l = l.split()
                    if partes_l:
                        nombre_share = partes_l[0].strip()
                        # Omitir shares administrativos ocultos (IPC$, C$, ADMIN$)
                        if not nombre_share.endswith("$"):
                            carpetas_encontradas.append(nombre_share)
        except Exception as e:
            log(f"net view no pudo listar recursos de '{servidor}': {e}", "WARN")

    if not carpetas_encontradas:
        log(f"No se detectaron carpetas en '{ip_ruta}'. Verifica que la máquina local tenga acceso de red.", "WARN")
        return False

    log(f"Carpetas reales encontradas en '{nas_nombre}': {', '.join(carpetas_encontradas)}")

    # Enviar al portal cPanel
    url = f"{cfg['api_url']}?action=reportar_carpetas_detectadas"
    payload = {
        "nas_id": nas_id,
        "carpetas": [{"nombre_carpeta": c} for c in carpetas_encontradas]
    }
    json_data = json.dumps(payload, ensure_ascii=False).encode("utf-8")

    req = urllib.request.Request(url, data=json_data, method="POST")
    req.add_header("Authorization", f"Bearer {cfg['api_token']}")
    req.add_header("Content-Type", "application/json; charset=utf-8")
    req.add_header("User-Agent", "ColectorRespaldosLAN/2.0")

    ctx = ssl.create_default_context()
    if not cfg.get("verificar_ssl", True):
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    try:
        with urllib.request.urlopen(req, timeout=cfg.get("timeout_segundos", 45), context=ctx) as resp:
            resp_data = json.loads(resp.read().decode("utf-8"))
            if resp_data.get("success"):
                log(f"¡Éxito! Carpetas de '{nas_nombre}' sincronizadas con cPanel. Aparecerán de inmediato en el portal.")
                return True
            else:
                log(f"El servidor rechazó las carpetas: {resp_data.get('error')}", "ERROR")
    except Exception as e:
        log(f"Error al enviar carpetas detectadas al portal: {e}", "ERROR")
    return False

def escanear_directorio_equipo(ruta_equipo, extensiones_temp, patrones_descarte):
    """
    Escanea la carpeta de un equipo para encontrar el archivo más reciente
    y detectar archivos temporales o incompletos.
    """
    ultimo_mtime = 0
    ultimo_archivo_nombre = None
    ultimo_archivo_tamano = 0
    archivos_temporales = []
    total_archivos = 0

    try:
        for root, dirs, files in os.walk(ruta_equipo):
            dirs[:] = [d for d in dirs if not es_nombre_descartable(d, patrones_descarte)]

            for f in files:
                if es_nombre_descartable(f, patrones_descarte):
                    continue

                full_path = os.path.join(root, f)

                if es_archivo_temporal(f, extensiones_temp):
                    archivos_temporales.append(f)
                    continue

                try:
                    stat = os.stat(full_path)
                    total_archivos += 1
                    if stat.st_mtime > ultimo_mtime:
                        ultimo_mtime = stat.st_mtime
                        ultimo_archivo_nombre = f
                        ultimo_archivo_tamano = stat.st_size
                except Exception:
                    continue

    except Exception as e:
        log(f"Error al recorrer directorio '{ruta_equipo}': {e}", "WARN")

    fecha_formateada = None
    if ultimo_mtime > 0:
        fecha_formateada = datetime.datetime.fromtimestamp(ultimo_mtime).strftime("%Y-%m-%d %H:%M:%S")

    return {
        "ultimo_archivo_nombre": ultimo_archivo_nombre,
        "ultimo_archivo_fecha": fecha_formateada,
        "tamano_bytes": ultimo_archivo_tamano,
        "tiene_temporales": len(archivos_temporales) > 0,
        "archivos_temporales": archivos_temporales[:5],
        "total_archivos": total_archivos
    }

def ejecutar_escaneo(info_api, cfg):
    """
    Ejecuta el escaneo completo sobre todas las NAS y carpetas configuradas
    """
    nas_list = info_api.get("nas_list", [])
    config_remota = info_api.get("config", {})
    patrones_descarte = config_remota.get("patrones_descarte", cfg["patrones_descarte"])
    extensiones_temp = cfg["extensiones_temporales"]

    resultados_equipos = []
    log(f"Iniciando escaneo de {len(nas_list)} NAS configuradas...")

    for nas in nas_list:
        nas_id = nas.get("id")
        nas_nombre = nas.get("nombre", f"NAS_{nas_id}")
        ip_ruta = nas.get("ip_ruta_red", "").strip()
        usuario_smb = nas.get("usuario_smb")
        password_smb = nas.get("password_smb")
        carpetas = nas.get("carpetas", [])

        # Si el NAS aún no tiene carpetas monitoreadas, ejecutar auto-descubrimiento
        if not carpetas or "--descubrir" in sys.argv or "-d" in sys.argv:
            log(f"Ejecutando auto-detección de carpetas para '{nas_nombre}'...")
            descubrir_carpetas_nas(nas, cfg)
            if "--descubrir" in sys.argv or "-d" in sys.argv:
                continue

        log(f"Procesando NAS '{nas_nombre}' ({ip_ruta}) con {len(carpetas)} carpetas...")

        if os.name == "nt" and usuario_smb and password_smb:
            montar_recurso_red_windows(ip_ruta, usuario_smb, password_smb)

        for carp in carpetas:
            carp_id = carp.get("id")
            carp_nombre = carp.get("nombre_carpeta", "")
            area_nombre = carp.get("area_nombre", "General")

            ruta_unc_base = ip_ruta.rstrip("\\/ ")
            sep = "\\" if os.name == "nt" else "/"
            ruta_carpeta = f"{ruta_unc_base}{sep}{carp_nombre}"

            if not os.path.exists(ruta_carpeta):
                log(f"Ruta no alcanzable o vacía: '{ruta_carpeta}'", "WARN")
                continue

            log(f"Escaneando carpeta de área: '{carp_nombre}' [{area_nombre}]...")

            try:
                subelementos = os.listdir(ruta_carpeta)
            except Exception as e:
                log(f"No se pudo listar '{ruta_carpeta}': {e}", "WARN")
                continue

            for elem in subelementos:
                if es_nombre_descartable(elem, patrones_descarte):
                    continue

                ruta_elem = os.path.join(ruta_carpeta, elem)

                if os.path.isdir(ruta_elem):
                    nombre_equipo = elem.upper()
                    datos_escaneo = escanear_directorio_equipo(ruta_elem, extensiones_temp, patrones_descarte)

                    resultados_equipos.append({
                        "nombre_equipo": nombre_equipo,
                        "usuario_responsable": elem,
                        "area_nombre": area_nombre,
                        "nas_id": nas_id,
                        "carpeta_id": carp_id,
                        "ruta_carpeta": ruta_elem,
                        "ultimo_archivo_nombre": datos_escaneo["ultimo_archivo_nombre"],
                        "ultimo_archivo_fecha": datos_escaneo["ultimo_archivo_fecha"],
                        "tamano_bytes": datos_escaneo["tamano_bytes"],
                        "tiene_temporales": datos_escaneo["tiene_temporales"],
                        "archivos_temporales": datos_escaneo["archivos_temporales"]
                    })
                elif os.path.isfile(ruta_elem):
                    if es_archivo_temporal(elem, extensiones_temp):
                        continue

                    try:
                        stat = os.stat(ruta_elem)
                        mtime_str = datetime.datetime.fromtimestamp(stat.st_mtime).strftime("%Y-%m-%d %H:%M:%S")
                        nom_base = os.path.splitext(elem)[0].split("_")[0].split("-")[0].upper()
                        if len(nom_base) < 3:
                            nom_base = os.path.splitext(elem)[0].upper()

                        resultados_equipos.append({
                            "nombre_equipo": nom_base,
                            "usuario_responsable": nom_base,
                            "area_nombre": area_nombre,
                            "nas_id": nas_id,
                            "carpeta_id": carp_id,
                            "ruta_carpeta": ruta_carpeta,
                            "ultimo_archivo_nombre": elem,
                            "ultimo_archivo_fecha": mtime_str,
                            "tamano_bytes": stat.st_size,
                            "tiene_temporales": False,
                            "archivos_temporales": []
                        })
                    except Exception:
                        pass

    log(f"Escaneo finalizado. Total de máquinas analizadas: {len(resultados_equipos)}")
    return resultados_equipos

def enviar_reporte_consolidado(equipos, duracion_seg, cfg):
    """
    Envía en un solo POST HTTPS el reporte JSON consolidado a cPanel
    """
    url = f"{cfg['api_url']}?action=reportar_respaldo_consolidado"
    log(f"Enviando reporte consolidado ({len(equipos)} equipos) a: {url}")

    payload = {
        "agente_ip": "127.0.0.1",
        "fecha_escaneo": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "duracion_segundos": int(duracion_seg),
        "equipos": equipos
    }

    json_data = json.dumps(payload, ensure_ascii=False).encode("utf-8")

    req = urllib.request.Request(url, data=json_data, method="POST")
    req.add_header("Authorization", f"Bearer {cfg['api_token']}")
    req.add_header("Content-Type", "application/json; charset=utf-8")
    req.add_header("User-Agent", "ColectorRespaldosLAN/2.0")

    ctx = ssl.create_default_context()
    if not cfg.get("verificar_ssl", True):
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    try:
        with urllib.request.urlopen(req, timeout=cfg.get("timeout_segundos", 45), context=ctx) as resp:
            resp_data = json.loads(resp.read().decode("utf-8"))
            if resp_data.get("success"):
                log(f"Reporte enviado y procesado exitosamente en cPanel! Procesados: {resp_data.get('procesados', 0)}")
                return True
            else:
                log(f"El servidor rechazó el reporte: {resp_data.get('error')}", "ERROR")
    except urllib.error.HTTPError as e:
        log(f"Error HTTP al enviar reporte ({e.code}): {e.reason}", "ERROR")
    except Exception as e:
        log(f"Falla de conexión al enviar reporte: {e}", "ERROR")
    return False

def main():
    log("=== INICIANDO COLECTOR DE RESPALDOS SMB/NAS ===")
    t_inicio = time.time()

    cfg = cargar_configuracion()

    if cfg.get("api_token") == "COPIAR_TOKEN_AQUI":
        log("ERROR: Debes configurar 'api_token' con el Bearer Token del portal en colector_config.json", "ERROR")
        sys.exit(1)

    info_api = consultar_rutas_api(cfg)
    if not info_api:
        log("No se pudo obtener la lista de rutas desde el portal. Abortando escaneo.", "ERROR")
        sys.exit(1)

    if "--descubrir" in sys.argv or "-d" in sys.argv:
        log("Modo Detección Activo: Buscando carpetas compartidas en las NAS...")
        for nas in info_api.get("nas_list", []):
            descubrir_carpetas_nas(nas, cfg)
        log("=== DETECCIÓN COMPLETADA ===")
        sys.exit(0)

    equipos = ejecutar_escaneo(info_api, cfg)
    t_duracion = time.time() - t_inicio

    if equipos:
        enviar_reporte_consolidado(equipos, t_duracion, cfg)
    else:
        log("No se encontraron equipos o carpetas de respaldo para reportar.", "WARN")

    log(f"=== CICLO COMPLETADO EN {t_duracion:.2f} SEGUNDOS ===")

if __name__ == "__main__":
    main()
