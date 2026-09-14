import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import dotenv from 'dotenv';
import authRoutes from './routes/auth.routes';
import { seedAdminUser } from './controllers/auth.controller';

dotenv.config();

const app = express();
const PORT = parseInt(process.env.PORT || '4000', 10);
const HOST = process.env.HOST || '0.0.0.0';

// Middlewares globales
app.use(helmet());
app.use(cors({
  origin: '*', // Permitir solicitudes locales y desde la red IP
  methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization'],
}));
app.use(express.json());

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
app.use('/api/auth', authRoutes);

// Iniciar servidor y sembrar usuario Admin si no existe
app.listen(PORT, HOST, async () => {
  console.log(`==================================================`);
  console.log(`  🚀 SYSTEMS PORTAL BACKEND INICIADO EXITOSAMENTE `);
  console.log(`==================================================`);
  console.log(`  Local:   http://localhost:${PORT}/api/health`);
  console.log(`  Red:     http://${process.env.LOCAL_IP || '192.168.26.3'}:${PORT}/api/health`);
  console.log(`  Pública: http://${process.env.PUBLIC_IP || '192.168.26.97'}:${PORT}/api/health`);
  console.log(`==================================================`);

  await seedAdminUser();
});

export default app;
