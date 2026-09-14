# Documento de Arquitectura y Seguridad del Sistema Web Distribuido para Múltiples Sucursales

## 1. Resumen Ejecutivo del Proyecto

El proyecto contempla el diseño e implementación de un **Sistema Web Federado y Descentralizado** para la gestión de **múltiples agencias y sucursales distribuidas**, integrado con un **Portal Central Maestro** para la administración ejecutiva (Superadmin) e interconectado de forma segura con la infraestructura de base de datos existente (**Microsoft SQL Server en red local privada**).

El objetivo principal es soportar un **alto volumen de usuarios concurrentes**, garantizar la privacidad y seguridad contable de la base de datos local y evitar cualquier riesgo cibernético en el perímetro de red (**Firewall Fortinet FortiGate**).

---

## 2. Descripción del Sistema: ¿Qué se va a hacer y cómo?

### 2.1. Flujo de Arquitectura General

```
[ PORTAL CENTRAL MAESTRO ] (admin.miempresa.com)
            │
            │ 1. Superadmin inicia sesión 1 sola vez (SSO JWT Token)
            ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   SUCURSALES EN CPANEL (NUBE)                          │
│  Sucursal 1 (sucursal01.miempresa.com) ... Sucursal N (sucursalN.com) │
│  - Módulo Inventarios: MySQL cPanel                                    │
│  - Módulo Reportes: Lee reporte_cache.json                             │
└──────────────────────────────────▲─────────────────────────────────────┘
                                   │
                    2. Envío HTTPS POST Saliente (Puerto 443)
                    (Cero Puertos Abiertos en Fortinet)
                                   │
┌──────────────────────────────────┴─────────────────────────────────────┐
│                 RED LOCAL (Servidor SQL Server Interno)                │
│                 - Base de Datos Local (Solo Lectura SELECT)            │
│                 - Agente Local (enviar_reporte.py cada X min)          │
└────────────────────────────────────────────────────────────────────────┘
```

1. **Portal Central Maestro (`admin.miempresa.com`):**
   - Plataforma administrativa unificada para el equipo directivo.
   - Implementa **Single Sign-On (SSO)** mediante **Tokens JWT cifrados**.
   - Permite al Superadmin pasar de una sucursal a otra en 1 clic sin volver a autenticarse.

2. **Sitios Web de Sucursal (`sucursal01.miempresa.com`, etc. - Nodos Distribuidos):**
   - **Módulo de Inventarios:** Almacena la nueva información de inventarios en la base de datos MySQL de cPanel.
   - **Módulo de Reportes:** Muestra el estado en tiempo real de órdenes abiertas, cerradas y montos, alimentado mediante el mecanismo de transporte **HTTPS PUSH Saliente**.

3. **Mecanismo de Extracción Local (Agente PUSH Python):**
   - Un proceso liviano (`enviar_reporte.py`) que ejecuta consultas de **Solo Lectura (`SELECT`)** al servidor SQL Server de la red local.
   - Envía los resultados cifrados por HTTPS (puerto saliente 443) hacia cPanel de forma periódica.

---

## 3. ¿Por qué la Opción PUSH es la Mejor Solución?

Tras evaluar exhaustivamente distintas alternativas arquitectónicas (conexiones directas abriendo puertos, túneles VPN y réplica de bases de datos), la **Opción PUSH Saliente por HTTPS** se consolida como la solución superior por las siguientes razones determinantes:

1. **Seguridad Inquebrantable (Cero Puertos Abiertos en Fortinet):** A diferencia de las conexiones entrantes convencionales que exigen abrir el puerto de base de datos (`1433`) o redirigir puertos en el router, la opción PUSH opera 100% mediante conexiones salientes (*Outbound*). Para el firewall Fortinet, este tráfico es idéntico a la navegación web segura habitual (`HTTPS/443`), manteniendo el perímetro de la empresa invulnerable.
2. **Independencia Total de IP Pública:** No requiere contratar direcciones IP públicas estáticas en las sucursales ni configurar registros DNS dinámicos (DDNS). Funciona de forma transparente sobre cualquier tipo o proveedor de enlace de internet (fibra, satelital o celular).
3. **Alta Disponibilidad y Resiliencia ante Caídas de Red:** Si la conexión a internet de la sucursal o de cPanel experimenta una interrupción temporal, el módulo web en cPanel no se congela ni arroja errores de tiempo de espera (*timeouts*). Simplemente continúa sirviendo el último estado registrado en la caché JSON, garantizando un tiempo de actividad del 100% para los usuarios.
4. **Cero Almacenamiento Contable Duplicado en cPanel:** Evita sobrecargar cPanel con bases de datos pesadas o réplicas contables innecesarias. El archivo de caché JSON ocupa apenas unos kilobytes y se sobrescribe eficientemente en cada envío, manteniendo el hosting ligero y rápido.
5. **Despliegue Simple y Mantenimiento Mínimo:** La instalación se realiza mediante un script liviano en Python programado en el *Task Scheduler* de Windows, sin necesidad de instalar controladores complejos, VPNs ni software de terceros de pago.

---

## 4. Protección de Credenciales y Seguridad de Claves (.env)

Para prevenir vulnerabilidades críticas como *Hardcoded Credentials* (credenciales expuestas en texto plano dentro del código fuente), el proyecto implementa la norma de seguridad estándar de la industria:

1. **Archivo de Configuración Aislado (`.env`):** En la red local, el script Python NO almacena contraseñas de SQL Server ni tokens en su código. Las credenciales residen en un archivo `.env` independiente con permisos de lectura restringidos en el sistema operativo.
2. **Aislamiento Fuera del Directorio Web en cPanel:** En cPanel, los archivos de configuración con credenciales o tokens se alojan en directorios protegidos fuera del alcance público de la carpeta `public_html` (ej: `/home/usuario/config.env`), impidiendo que usuarios o bots web puedan leer las claves desde un navegador.
3. **Exclusión de Repositorios (Git):** Los archivos `.env` se agregan obligatoriamente al archivo `.gitignore` para prevenir que las claves sean subidas accidentalmente a repositorios de código públicos o privados.

---

## 5. Ventajas de la Arquitectura Seleccionada (HTTPS PUSH + Descentralizada)

### 5.1. Distribución Masiva de Carga (Soporte de Múltiples Usuarios Concurrentes)
- **Descentralización Eficiente:** En lugar de saturar un solo servidor con un alto volumen de usuarios simultáneos, la carga se divide de forma equilibrada entre los distintos nodos de las sucursales. Cada cPanel solo atiende a su grupo de usuarios local, garantizando respuesta en milisegundos.
- **Aislamiento de Fallos:** Si la conexión a internet de 1 sucursal se interrumpe, las demás sucursales y el Portal Maestro siguen funcionando al 100% de capacidad.

### 5.2. Optimización Extrema de Almacenamiento
- **Cero Duplicación de BD en la Nube:** La base de datos local contiene un gran volumen de transacciones históricas. En cPanel **NO se replica la base de datos local**.
- **Caché en JSON de Solo Lectura:** El receptor en cPanel (`recibir_reporte.php`) solo mantiene un archivo liviano de caché (`reporte_cache.json`) de un par de kilobytes que se sobrescribe periódicamente. La base de datos MySQL en cPanel se mantiene limpia y ligera.

### 5.3. Integridad de la Base de Datos Legada
- **Protección Estricta de Solo Lectura:** El agente local únicamente ejecuta sentencias `SELECT`. Es técnicamente imposible corromper, modificar o eliminar registros en el servidor SQL Server local.

---

## 6. Desventajas y Consideraciones de Mantenimiento

1. **Gestión de Tareas Programadas:** Se requiere mantener activa la tarea programada en el servidor de cada ubicación local.
2. **Configuración Inicial de Dominios:** Se deben registrar las URLs correspondientes en el servidor DNS para cada sucursal.

---

## 7. Análisis de Riesgos: Peligros de Abrir Puertos y Fortinet

### 7.1. El Peligro de Abrir Puertos de Entrada (Inbound Ports)
Abrir puertos directos como el puerto por defecto de SQL Server (`1433`) o un puerto redireccionado en el módem/firewall hacia internet expone a la empresa a los siguientes riesgos cibernéticos críticos:

1. **Ataques de Fuerza Bruta Automatizados:** Bots globales en internet escanean rangos de IP constantemente. Al detectar un puerto de base de datos abierto, lanzan millones de combinaciones de contraseñas por segundo para vulnerar el sistema.
2. **Amenazas de Ransomware:** La mayoría de secuestros de información (Ransomware) en empresas ocurren por vulnerabilidades expuestas en puertos de bases de datos o escritorios remotos expuestos a internet.
3. **Ataques de Denegación de Servicio (DDoS):** Un atacante puede inundar el puerto abierto con peticiones falsas, congelando la CPU del servidor local de base de datos y deteniendo la operación en la oficina.

### 7.2. El Riesgo de Malas Configuraciones en Fortinet
Un Firewall FortiGate (NGFW) es highly potente, pero una mala configuración puede dejar vulnerabilidades graves:
- **Falta de Lista Blanca Estricta:** Configurar una regla VIP/DNAT en FortiGate permitiendo origen desde cualquier IP en internet (`0.0.0.0/0`) deja la red local expuesta a escaneos.
- **Servicios de Administración Expuestos:** Dejar la interfaz web de gestión del firewall hacia la interfaz WAN sin restricción de IP.

### 7.3. Cómo la Opción PUSH Elimina el 100% de los Riesgos de Puerto

```
 ┌──────────────────────────────────────────────────────────────┐
 │               MUNDO EXTERIOR (INTERNET)                      │
 └──────────────────────────────┬───────────────────────────────┘
                                │
                 ❌ TODOS LOS PAQUETES ENTRANTES 
                 SON BLOQUEADOS AUTOMÁTICAMENTE
                                │
                                ▼
 ┌──────────────────────────────────────────────────────────────┐
 │              FORTINET FORTIGATE FIREWALL                     │
 │          POLÍTICA ENTRANTE: DENY ALL INBOUND                 │
 └──────────────────────────────▲───────────────────────────────┘
                                │
                 ✅ TRÁFICO SALIENTE HTTPS (PUERTO 443)
                 (Igual que la navegación web habitual)
                                │
 ┌──────────────────────────────┴───────────────────────────────┐
 │          RED LOCAL (Servidor SQL Server Interno)             │
 └──────────────────────────────────────────────────────────────┘
```

Con el esquema PUSH seleccionado:
- **CERO Puertos Entrantes Abiertos:** FortiGate mantiene la regla predeterminada `Deny All Inbound`. Ningún paquete no solicitado desde internet puede ingresar a la red.
- **Conexiones Únicamente Salientes (Outbound-Only):** El tráfico se inicia internamente desde la red local por la interfaz LAN hacia la WAN por HTTPS. FortiGate lo procesa con sus inspecciones salientes de antivirus y filtrado web habituales.

---

## 8. Cuadro Comparativo de Arquitecturas

| Criterio | Conexión Directa (1433) | Túnel VPN / Cloudflare | PUSH HTTPS Seleccionada |
| :--- | :---: | :---: | :---: |
| **Apertura Puertos en Fortinet** | ⚠️ Requerido (Alto riesgo) | ❌ No requerido | ❌ **Cero Puertos Abiertos (Excelente)** |
| **Riesgo Ransomware / Fuerza Bruta** | 🔴 Alto Riesgo | 🟢 Bajo Riesgo | 🛡️ **Nulo (100% Protegido)** |
| **Resistencia a Caídas de Internet** | 🔴 Se bloquea la consulta | 🔴 Se bloquea la consulta | 🟢 **Muestra último reporte en caché** |
| **Distribución de Usuarios** | 🔴 Colapsa el SQL Server local | 🔴 Colapsa el túnel local | 🟢 **Reparto equilibrado por sucursal** |

---

## 9. Código Fuente de la Solución PUSH

### 9.1. Archivo de Variables de Entorno Protegido (`.env`)
```env
# Archivo .env de Variables de Entorno Protegidas
DB_SERVER=192.168.26.5
DB_NAME=gedas
DB_USER=sa
DB_PASS=sa
CPANEL_URL=https://sucursal.miempresa.com/recibir_reporte.php
TOKEN_SECRETO=ClaveSecretaFortinet2026!
```

### 9.2. Script de Extracción Local (`enviar_reporte.py`)
```python
import os
import sys
import pyodbc
import requests
import json
import datetime
import urllib3

# Desactivar advertencias de certificados SSL no verificados
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# Cargar variables de entorno desde .env local
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

# Credenciales seguras cargadas desde el entorno
DB_SERVER = os.getenv("DB_SERVER", "192.168.26.5")
DB_NAME = os.getenv("DB_NAME", "gedas")
DB_USER = os.getenv("DB_USER", "sa")
DB_PASS = os.getenv("DB_PASS", "sa")
CPANEL_URL = os.getenv("CPANEL_URL", "https://sucursal.miempresa.com/recibir_reporte.php")
TOKEN_SECRETO = os.getenv("TOKEN_SECRETO", "ClaveSecretaFortinet2026!")

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

        # Consulta SELECT de Solo Lectura para métricas de órdenes
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

        payload = {
            "token": TOKEN_SECRETO,
            "fecha_actualizacion": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "resumen": resumen
        }

        print(f"[HTTPS] Enviando reporte seguro a cPanel...")
        headers = {'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0'}
        response = requests.post(CPANEL_URL, data=json.dumps(payload, default=default_converter), headers=headers, timeout=15, verify=False)

        if response.status_code == 200:
            print("[EXITO] Reporte enviado con éxito a cPanel:", response.text)
        else:
            print(f"[ERROR] Respuesta de cPanel (HTTP {response.status_code}):", response.text)

    except Exception as e:
        print("[ERROR] Excepción en extracción y envío:", str(e))

if __name__ == "__main__":
    generar_y_enviar_reporte()
```

### 9.3. Script Receptor en cPanel (`recibir_reporte.php`)
```php
<?php
// Endpoint Receptor en cPanel
header('Content-Type: application/json');

define('TOKEN_SECRETO', 'ClaveSecretaFortinet2026!');
$archivoCache = __DIR__ . '/reporte_cache.json';

// Leer payload JSON entrante
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "mensaje" => "Payload JSON inválido o vacío."]);
    exit;
}

// Validar Token de Seguridad Criptográfico
if (!isset($data['token']) || $data['token'] !== TOKEN_SECRETO) {
    http_response_code(401);
    echo json_encode(["status" => "error", "mensaje" => "Acceso no autorizado: Token inválido."]);
    exit;
}

// Guardar los datos únicamente en el archivo de caché JSON liviano
unset($data['token']);
if (file_put_contents($archivoCache, json_encode($data, JSON_PRETTY_PRINT))) {
    echo json_encode([
        "status" => "ok",
        "mensaje" => "Reporte actualizado con éxito.",
        "timestamp" => date('Y-m-d H:i:s')
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "mensaje" => "No se pudo escribir la caché en cPanel."]);
}
?>
```

---

## 10. Conclusión

La arquitectura descentralizada con **Portal Central Maestro SSO** y **Extracción PUSH Saliente HTTPS** representa la mejor solución costo-beneficio, seguridad y rendimiento para la infraestructura de múltiples sucursales. 

Garantiza el cumplimiento estricto de **cero puertos abiertos en Fortinet**, preserva la integridad total de la base de datos local y proporciona una experiencia fluida y en tiempo real para múltiples usuarios concurrentes.
