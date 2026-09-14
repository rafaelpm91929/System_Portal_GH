"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.sql = exports.getDbPool = void 0;
const mssql_1 = __importDefault(require("mssql"));
exports.sql = mssql_1.default;
const dotenv_1 = __importDefault(require("dotenv"));
dotenv_1.default.config();
const dbHost = process.env.DB_HOST || 'localhost';
const dbPort = parseInt(process.env.DB_PORT || '1433', 10);
const dbName = process.env.DB_NAME || 'systems_portal';
const dbUser = process.env.DB_USER || '';
const dbPassword = process.env.DB_PASSWORD || '';
const trustCert = process.env.DB_TRUST_SERVER_CERTIFICATE !== 'false';
const config = {
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
}
else {
    config.options.trustedConnection = true;
}
let pool = null;
const getDbPool = async () => {
    if (pool) {
        return pool;
    }
    try {
        pool = await new mssql_1.default.ConnectionPool(config).connect();
        console.log(`[SQL Server] Conectado exitosamente a la base de datos: ${dbName} en ${dbHost}`);
        return pool;
    }
    catch (error) {
        console.error('[SQL Server Error] Error al conectar a la base de datos:', error);
        throw error;
    }
};
exports.getDbPool = getDbPool;
