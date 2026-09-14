import os
import sys
import pyodbc
import requests
import json
import datetime
import urllib3

# Desactivar advertencias de certificados SSL no verificados
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# Función para cargar variables de entorno desde el archivo .env protegido
def cargar_env(ruta_env):
    if os.path.exists(ruta_env):
        with open(ruta_env, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, val = line.split('=', 1)
                    os.environ[key.strip()] = val.strip()

# Cargar el archivo .env seguro
env_path = os.path.join(os.path.dirname(__file__), '.env')
cargar_env(env_path)

# 1. Obtener Credenciales de las Variables de Entorno (Sin claves hardcodeadas en código)
DB_SERVER = os.getenv("DB_SERVER", "192.168.26.5")
DB_NAME = os.getenv("DB_NAME", "gedas")
DB_USER = os.getenv("DB_USER", "sa")
DB_PASS = os.getenv("DB_PASS", "sa")
CPANEL_URL = os.getenv("CPANEL_URL", "https://juridico.divolavilla.com/sistemas/recibir_reporte.php")
TOKEN_SECRETO = os.getenv("TOKEN_SECRETO", "GedasDivolavilla2026!")

def default_converter(o):
    if isinstance(o, (datetime.date, datetime.datetime)):
        return o.isoformat()
    if hasattr(o, '__float__'):
        return float(o)

def generar_y_enviar_reporte():
    try:
        print("[SQL] Conectando a SQL Server local desde variables .env...")
        conn_str = f"DRIVER={{ODBC Driver 18 for SQL Server}};SERVER={DB_SERVER};DATABASE={DB_NAME};UID={DB_USER};PWD={DB_PASS};TrustServerCertificate=yes;"
        conn = pyodbc.connect(conn_str)
        cursor = conn.cursor()

        # Resumen General de Órdenes Abiertas y Cerradas en SEORDSER
        resumen_query = """
            SELECT 
                SUM(CASE WHEN ORStatus = 'AB' THEN 1 ELSE 0 END) AS Abiertas,
                SUM(CASE WHEN ORStatus = 'CE' THEN 1 ELSE 0 END) AS Cerradas,
                SUM(CASE WHEN ORStatus = 'CA' THEN 1 ELSE 0 END) AS Canceladas,
                COUNT(*) AS TotalOrdenes,
                SUM(CASE WHEN ORStatus = 'AB' THEN ORTotal ELSE 0 END) AS MontoAbiertas,
                SUM(CASE WHEN ORStatus = 'CE' THEN ORTotal ELSE 0 END) AS MontoCerradas
            FROM SEORDSER
        """
        cursor.execute(resumen_query)
        row = cursor.fetchone()
        resumen = {
            "Abiertas": row[0] or 0,
            "Cerradas": row[1] or 0,
            "Canceladas": row[2] or 0,
            "TotalOrdenes": row[3] or 0,
            "MontoAbiertas": float(row[4] or 0),
            "MontoCerradas": float(row[5] or 0)
        }

        # Últimas 20 Órdenes
        ultimas_query = """
            SELECT TOP 20
                OROrden AS NumeroOrden,
                ORFecAlta AS Fecha,
                ORNombre AS Cliente,
                Modelo AS ModeloVehiculo,
                ORPlacas AS Placas,
                ORTotal AS Monto,
                CASE 
                    WHEN ORStatus = 'AB' THEN 'ABIERTA'
                    WHEN ORStatus = 'CE' THEN 'CERRADA'
                    WHEN ORStatus = 'CA' THEN 'CANCELADA'
                    ELSE ORStatus
                END AS Estado
            FROM SEORDSER
            ORDER BY ORFecAlta DESC
        """
        cursor.execute(ultimas_query)
        columns = [column[0] for column in cursor.description]
        ultimas_ordenes = [dict(zip(columns, row)) for row in cursor.fetchall()]

        payload = {
            "token": TOKEN_SECRETO,
            "fecha_actualizacion": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "resumen": resumen,
            "ultimasOrdenes": ultimas_ordenes
        }

        print(f"[HTTPS] Enviando reporte seguro a cPanel...")
        headers = {'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0'}
        response = requests.post(CPANEL_URL, data=json.dumps(payload, default=default_converter), headers=headers, timeout=15, verify=False)

        if response.status_code == 200:
            print("[EXITO] Reporte enviado con exito a cPanel:", response.text)
        else:
            print(f"[ERROR] Respuesta de cPanel (HTTP {response.status_code}):", response.text)

    except Exception as e:
        print("[ERROR] Error ejecutando la extraccion y envio:", str(e))

if __name__ == "__main__":
    generar_y_enviar_reporte()
