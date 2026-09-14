import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import toast, { Toaster } from 'react-hot-toast';
import {
  Boxes,
  FileText,
  Server,
  Cloud,
  Wrench,
  Mail,
  TrendingUp,
  FileBarChart,
  Receipt,
  Ticket,
  BookOpen,
  Globe,
  LogOut,
  User,
  X,
  CheckCircle2,
  AlertCircle
} from 'lucide-react';

interface ModuleCardProps {
  id: string;
  title: string;
  description: string;
  icon: React.ReactNode;
  iconBg: string;
  onSelect: (module: any) => void;
}

const ModuleCard: React.FC<ModuleCardProps> = ({ id, title, description, icon, iconBg, onSelect }) => (
  <div className="bg-[#0c1529]/95 border border-[#1b2b4d]/90 hover:border-[#2b4170] rounded-2xl p-6 flex flex-col justify-between items-center text-center shadow-xl hover:shadow-2xl hover:shadow-purple-950/20 transition-all duration-300 group">
    <div className="flex flex-col items-center flex-1 w-full">
      <div className={`w-12 h-12 ${iconBg} text-white rounded-2xl flex items-center justify-center mb-4 shadow-md transition-transform group-hover:scale-105`}>
        {icon}
      </div>
      <h3 className="text-white font-extrabold text-sm sm:text-base mb-2 tracking-tight">
        {title}
      </h3>
      <p className="text-[#7088b3] text-[11px] sm:text-xs leading-relaxed max-w-[210px] mb-6 flex-1 font-normal">
        {description}
      </p>
    </div>
    
    <button
      onClick={() => onSelect({ id, title, description, icon, iconBg })}
      className="w-full bg-[#121c35] hover:bg-[#1554aa] text-[#8ea5cc] hover:text-white py-2.5 rounded-xl font-bold text-xs border border-[#1e2f54] hover:border-blue-500/50 transition-all duration-200 shadow-sm cursor-pointer active:scale-[0.98]"
    >
      Ingresar
    </button>
  </div>
);

export const Dashboard: React.FC = () => {
  const [user, setUser] = useState<any>(null);
  const [selectedModule, setSelectedModule] = useState<any>(null);
  const navigate = useNavigate();

  useEffect(() => {
    const savedUser = localStorage.getItem('systems_portal_user');
    const token = localStorage.getItem('systems_portal_token');

    if (!token || !savedUser) {
      navigate('/login');
      return;
    }

    try {
      setUser(JSON.parse(savedUser));
    } catch {
      navigate('/login');
    }
  }, [navigate]);

  const handleLogout = () => {
    localStorage.removeItem('systems_portal_token');
    localStorage.removeItem('systems_portal_user');
    toast.success('Sesión cerrada');
    navigate('/login');
  };

  const modules = [
    {
      id: 'inventarios',
      title: 'Inventarios',
      description: 'Administra PCs, laptops, servidores, impresoras y equipos móviles asignados por sucursal, nomenclatura y responsiva.',
      icon: <Boxes size={24} />,
      iconBg: 'bg-[#1554aa]',
    },
    {
      id: 'licencias',
      title: 'Licencias',
      description: 'Matriz de licenciamiento de software por equipo y sucursal, con vigencias y alertas de vencimiento.',
      icon: <FileText size={24} />,
      iconBg: 'bg-[#d97706]',
    },
    {
      id: 'infraestructura',
      title: 'Infraestructura (SITE / IDF)',
      description: 'Fichas de SITE e IDF, racks, cableado y red por agencia, con sus diagramas y evidencia asociada.',
      icon: <Server size={24} />,
      iconBg: 'bg-[#10b981]',
    },
    {
      id: 'respaldos',
      title: 'Respaldos',
      description: 'Estado de los respaldos por tipo y sucursal: última ejecución, frecuencia y bitácora de restauración.',
      icon: <Cloud size={24} />,
      iconBg: 'bg-[#0284c7]',
    },
    {
      id: 'mantenimiento',
      title: 'Mantenimiento',
      description: 'Calendario anual de mantenimiento a infraestructura, con evidencia y responsable por evento.',
      icon: <Wrench size={24} />,
      iconBg: 'bg-[#f97316]',
    },
    {
      id: 'correo',
      title: 'Correo Institucional',
      description: 'Cuentas oficiales por sucursal, con seguimiento de altas, bajas y reactivaciones.',
      icon: <Mail size={24} />,
      iconBg: 'bg-[#ec4899]',
    },
    {
      id: 'estadisticas',
      title: 'Estadísticas de Cumplimiento',
      description: 'Semáforo consolidado por agencia y sucursal, alertas activas y tendencia de cumplimiento.',
      icon: <TrendingUp size={24} />,
      iconBg: 'bg-[#2563eb]',
    },
    {
      id: 'reportes-gds',
      title: 'Reportes Cierre de Mes GDS',
      description: 'Generación, revisión y consulta de reportes consolidados del cierre de mes para agencias GDS.',
      icon: <FileBarChart size={24} />,
      iconBg: 'bg-[#e11d48]',
    },
    {
      id: 'facturas',
      title: 'Facturas',
      description: 'Gestión, consulta y control de facturas electrónicas, comprobantes y documentación fiscal por agencia.',
      icon: <Receipt size={24} />,
      iconBg: 'bg-[#0d9488]',
    },
    {
      id: 'portal-tickets',
      title: 'Portal de Tickets',
      description: 'Centro de atención a usuarios, solicitud de soporte técnico, seguimiento de incidencias y SLA de solución.',
      icon: <Ticket size={24} />,
      iconBg: 'bg-[#4f46e5]',
    },
    {
      id: 'normas-politicas',
      title: 'Normas y Políticas',
      description: 'Consulta de reglamentos internos, políticas de uso de tecnología, seguridad de la información y normativas.',
      icon: <BookOpen size={24} />,
      iconBg: 'bg-[#7c3aed]',
    },
    {
      id: 'gds',
      title: 'GDS',
      description: 'Acceso centralizado, consulta y gestión operativa del sistema de distribución global (GDS).',
      icon: <Globe size={24} />,
      iconBg: 'bg-[#e20074]', // Magenta exacto GDS extraído de la imagen (#e20074)
    },
  ];

  if (!user) return null;

  return (
    <div className="min-h-screen bg-[#060b17] text-slate-100 p-6 sm:p-10 relative overflow-hidden font-sans flex flex-col justify-between">
      <Toaster position="top-right" />

      {/* Grid Pattern Background */}
      <div className="absolute inset-0 bg-grid-pattern pointer-events-none opacity-40"></div>

      {/* Purple Ambient Light Glow at Bottom */}
      <div 
        className="absolute bottom-0 left-0 right-0 h-96 pointer-events-none"
        style={{
          background: 'radial-gradient(ellipse at 50% 120%, rgba(88, 28, 135, 0.3) 0%, rgba(6, 11, 23, 0) 70%)'
        }}
      ></div>

      <div className="max-w-7xl w-full mx-auto z-10 space-y-8 my-auto">
        
        {/* Header Section */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <span className="text-[11px] font-bold text-[#486596] uppercase tracking-widest block mb-1">
              GRUPO HUERTA
            </span>
            <h1 className="text-3xl sm:text-[34px] font-black text-white tracking-tight">
              Portal de Sistemas
            </h1>
            <p className="text-xs sm:text-sm text-[#7088b3] mt-1 font-medium">
              Selecciona un módulo para continuar
            </p>
          </div>

          {/* User Info Badge & Logout */}
          <div className="flex items-center space-x-3 bg-[#0c1529]/90 border border-[#1b2b4d] px-4 py-2 rounded-2xl shadow-lg self-start sm:self-auto">
            <div className="w-8 h-8 rounded-xl bg-[#1554aa] text-white flex items-center justify-center font-bold text-xs">
              <User size={16} />
            </div>
            <div className="text-left text-xs pr-2">
              <div className="font-bold text-white leading-tight">{user.name}</div>
              <div className="text-[#7088b3] text-[11px]">@{user.username || 'usuario'}</div>
            </div>
            <button
              onClick={handleLogout}
              title="Cerrar sesión"
              className="p-2 text-[#7088b3] hover:text-rose-400 hover:bg-rose-500/10 rounded-xl transition-colors cursor-pointer"
            >
              <LogOut size={18} />
            </button>
          </div>
        </div>

        {/* 12 Modules Grid */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
          {modules.map((m) => (
            <ModuleCard
              key={m.id}
              id={m.id}
              title={m.title}
              description={m.description}
              icon={m.icon}
              iconBg={m.iconBg}
              onSelect={(mod) => {
                if (mod.id === 'inventarios') {
                  navigate('/inventory');
                } else if (mod.id === 'respaldos') {
                  navigate('/respaldos');
                } else if (mod.id === 'infraestructura') {
                  navigate('/infraestructura');
                } else {
                  setSelectedModule(mod);
                }
              }}
            />
          ))}
        </div>
      </div>

      {/* Interactive Test Modal for Module */}
      {selectedModule && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#060b17]/80 backdrop-blur-md animate-fadeIn">
          <div className="bg-[#0c1529] border border-[#1b2b4d] rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl relative space-y-6">
            <button
              onClick={() => setSelectedModule(null)}
              className="absolute top-6 right-6 text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition-colors"
            >
              <X size={20} />
            </button>

            <div className="flex items-center space-x-4">
              <div className={`w-12 h-12 ${selectedModule.iconBg} text-white rounded-2xl flex items-center justify-center shadow-lg`}>
                {selectedModule.icon}
              </div>
              <div>
                <span className="text-[10px] font-bold text-[#486596] uppercase tracking-wider block">MÓDULO DE SISTEMAS</span>
                <h3 className="text-xl font-black text-white">{selectedModule.title}</h3>
              </div>
            </div>

            <p className="text-xs text-[#7088b3] leading-relaxed bg-[#060b17] p-4 rounded-2xl border border-[#1b2b4d]">
              {selectedModule.description}
            </p>

            <div className="space-y-3">
              <div className="text-xs font-bold text-slate-400 uppercase tracking-wider">Estado del Módulo (Local & Red):</div>
              <div className="flex items-center space-x-2 text-xs text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 p-3 rounded-xl font-medium">
                <CheckCircle2 size={16} />
                <span>Base de Datos Conectada: 192.168.26.97 (systems_portal)</span>
              </div>
              <div className="flex items-center space-x-2 text-xs text-blue-400 bg-blue-500/10 border border-blue-500/20 p-3 rounded-xl font-medium">
                <AlertCircle size={16} />
                <span>Modo de Prueba Activo para @{user.username}</span>
              </div>
            </div>

            <div className="pt-2 flex justify-end space-x-3">
              <button
                onClick={() => {
                  toast.success(`Accediendo al módulo: ${selectedModule.title}`);
                  setSelectedModule(null);
                }}
                className="w-full py-3 bg-[#1554aa] hover:bg-blue-600 text-white font-bold rounded-xl text-xs transition-all shadow-lg shadow-blue-600/30 cursor-pointer"
              >
                Confirmar Ingreso a {selectedModule.title}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
