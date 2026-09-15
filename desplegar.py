import os
import ftplib
import sys

# Cargar variables de entorno desde .env si existe
def cargar_env(ruta_env):
    if os.path.exists(ruta_env):
        with open(ruta_env, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, val = line.split('=', 1)
                    os.environ[key.strip()] = val.strip()

env_path = os.path.join(os.path.dirname(__file__), '.env')
cargar_env(env_path)

# Datos de conexión FTP (Se leen del .env o de argumentos)
FTP_HOST = os.getenv("FTP_HOST", "portal.grupohuerta.mx")
FTP_USER = os.getenv("FTP_USER", "")
FTP_PASS = os.getenv("FTP_PASS", "")
REMOTE_DIR = os.getenv("REMOTE_DIR", "public_html/sistemas")

# Archivos a sincronizar con el cPanel
ARCHIVOS_A_SUBIR = [
    "recibir_reporte.php",
    "reportes.php",
    "menu.php",
    "login.php",
    "logout.php",
    "conexion.php",
    "prueba.php",
    "index.php",
    ".htaccess"
]

def desplegar_por_ftp():
    host = input(f"Ingresa Host FTP [{FTP_HOST}]: ").strip() or FTP_HOST if not FTP_HOST else FTP_HOST
    if not FTP_USER or not FTP_PASS:
        print("[AVISO] Agrega FTP_USER y FTP_PASS en tu archivo .env para desplegar en 1 clic.")
        usuario = input("Ingresa tu usuario de cPanel/FTP: ").strip()
        password = input("Ingresa tu contraseña de cPanel/FTP: ").strip()
    else:
        usuario = FTP_USER
        password = FTP_PASS

    try:
        print(f"[FTP] Conectando a {host}...")
        ftp = ftplib.FTP(host)
        ftp.login(user=usuario, passwd=password)
        print("[FTP] Conexión exitosa.")

        # Intentar cambiar al directorio remoto, si no existe lo crea
        dirs = REMOTE_DIR.split('/')
        for d in dirs:
            if d:
                try:
                    ftp.cwd(d)
                except ftplib.error_perm:
                    print(f"[FTP] Creando directorio distante: {d}")
                    ftp.mkd(d)
                    ftp.cwd(d)

        print(f"[FTP] Subiendo archivos a /{REMOTE_DIR}...")
        for archivo in ARCHIVOS_A_SUBIR:
            ruta_local = os.path.join(os.path.dirname(__file__), archivo)
            if os.path.exists(ruta_local):
                with open(ruta_local, 'rb') as f:
                    ftp.storbinary(f'STOR {archivo}', f)
                print(f"  └─ ✅ {archivo} subido correctamente.")
            else:
                print(f"  └─ ⚠️ Archivo no encontrado localmente: {archivo}")

        ftp.quit()
        print("\n[ÉXITO] ¡Despliegue completado! Puedes verificar en:")
        print("https://portal.grupohuerta.mx/login.php")

    except Exception as e:
        print("\n[ERROR] No se pudo realizar el despliegue FTP:", str(e))

if __name__ == "__main__":
    desplegar_por_ftp()

