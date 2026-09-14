"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const express_1 = __importDefault(require("express"));
const cors_1 = __importDefault(require("cors"));
const helmet_1 = __importDefault(require("helmet"));
const dotenv_1 = __importDefault(require("dotenv"));
const auth_routes_1 = __importDefault(require("./routes/auth.routes"));
const auth_controller_1 = require("./controllers/auth.controller");
dotenv_1.default.config();
const app = (0, express_1.default)();
const PORT = parseInt(process.env.PORT || '4000', 10);
const HOST = process.env.HOST || '0.0.0.0';
// Middlewares globales
app.use((0, helmet_1.default)());
app.use((0, cors_1.default)({
    origin: '*', // Permitir solicitudes locales y desde la red IP
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization'],
}));
app.use(express_1.default.json());
// Endpoint de salud / estado de la API
app.get('/api/health', (req, res) => {
    res.json({
        status: 'online',
        system: 'Systems Portal API',
        timestamp: new Date().toISOString(),
        ips: {
            publicIP: process.env.PUBLIC_IP || '192.168.26.97',
            localIP: process.env.LOCAL_IP || '192.168.26.3',
        },
    });
});
// Rutas API
app.use('/api/auth', auth_routes_1.default);
// Iniciar servidor y sembrar usuario Admin si no existe
app.listen(PORT, HOST, async () => {
    console.log(`==================================================`);
    console.log(`  🚀 SYSTEMS PORTAL BACKEND INICIADO EXITOSAMENTE `);
    console.log(`==================================================`);
    console.log(`  Local:   http://localhost:${PORT}/api/health`);
    console.log(`  Red:     http://${process.env.LOCAL_IP || '192.168.26.3'}:${PORT}/api/health`);
    console.log(`  Pública: http://${process.env.PUBLIC_IP || '192.168.26.97'}:${PORT}/api/health`);
    console.log(`==================================================`);
    await (0, auth_controller_1.seedAdminUser)();
});
exports.default = app;
