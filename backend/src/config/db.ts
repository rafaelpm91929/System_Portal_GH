import sql from 'mssql';
import dotenv from 'dotenv';

dotenv.config();

const dbHost = process.env.DB_HOST || 'localhost';
const dbPort = parseInt(process.env.DB_PORT || '1433', 10);
const dbName = process.env.DB_NAME || 'systems_portal';
const dbUser = process.env.DB_USER || '';
const dbPassword = process.env.DB_PASSWORD || '';
const trustCert = process.env.DB_TRUST_SERVER_CERTIFICATE !== 'false';

const config: sql.config = {
  server: dbHost,
  port: dbPort,
  database: dbName,
  options: {
    encrypt: true,
    trustServerCertificate: trustCert,
    enableArithAbort: true,
  },
  pool: {
    max: 10,
    min: 0,
    idleTimeoutMillis: 30000,
  },
};

// Si se proporcionan usuario y contraseña, se usa SQL Authentication; si no, Windows Auth
if (dbUser && dbPassword) {
  config.user = dbUser;
  config.password = dbPassword;
} else {
  (config.options as any).trustedConnection = true;
}

let pool: sql.ConnectionPool | null = null;

export const getDbPool = async (): Promise<sql.ConnectionPool> => {
  if (pool) {
    return pool;
  }
  try {
    pool = await new sql.ConnectionPool(config).connect();
    console.log(`[SQL Server] Conectado exitosamente a la base de datos: ${dbName} en ${dbHost}`);
    return pool;
  } catch (error) {
    console.error('[SQL Server Error] Error al conectar a la base de datos:', error);
    throw error;
  }
};

export { sql };
