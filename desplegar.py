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
FTP_HOST = os.getenv("FTP_HOST", "grupohuerta.mx")
FTP_USER = os.getenv("FTP_USER", "")
FTP_PASS = os.getenv("FTP_PASS", "")
REMOTE_DIR = os.getenv("REMOTE_DIR", "public_html")

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
    host = FTP_HOST if FTP_HOST else input(f"Ingresa Host FTP [{FTP_HOST}]: ").strip()
    usuario = FTP_USER
    password = FTP_PASS

    if not usuario or not password:
        print("\n--- Credenciales FTP cPanel (Grupo Huerta) ---")
        host_input = input(f"Host FTP [{host}]: ").strip()
        if host_input: host = host_input
        usuario = input("Usuario cPanel/FTP (ej. usuario@grupohuerta.mx o usuario_cpanel): ").strip()
        password = input("Contraseña de FTP: ").strip()

    remote_dir = input(f"Ruta remota en cPanel [{REMOTE_DIR}]: ").strip() or REMOTE_DIR

    try:
        print(f"\n[FTP] Conectando a {host} con usuario '{usuario}'...")
        ftp = ftplib.FTP(host)
        ftp.login(user=usuario, passwd=password)
        print("[FTP] ✅ Conexión exitosa.")

        # Intentar cambiar al directorio remoto, si no existe lo crea
        dirs = remote_dir.split('/')
        for d in dirs:
            if d:
                try:
                    ftp.cwd(d)
                except ftplib.error_perm:
                    print(f"[FTP] Creando directorio distante: {d}")
                    ftp.mkd(d)
                    ftp.cwd(d)

        print(f"[FTP] Subiendo archivos a /{remote_dir}...")
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

    except ftplib.error_perm as e:
        print(f"\n[ERROR FTP] Error de permisos / autenticación: {e}")
        if "530" in str(e):
            print("\n💡 TIP DE AUTENTICACIÓN CPANEL:")
            print("1. Si usas una cuenta de FTP creada en cPanel, el usuario debe incluir el dominio: ej. usuario@grupohuerta.mx")
            print("2. Si usas la cuenta principal de cPanel, el usuario es solo tu nombre de cPanel (ej. grupohue).")
            print("3. Verifica que la contraseña sea exactamente la configurada en cPanel.")
    except Exception as e:
        print("\n[ERROR] No se pudo realizar el despliegue FTP:", str(e))

if __name__ == "__main__":
    desplegar_por_ftp()



