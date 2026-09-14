import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import toast, { Toaster } from 'react-hot-toast';
import {
  ArrowLeft,
  FileSpreadsheet,
  Check,
  X,
  Cloud,
  Search,
  PieChart,
  ClipboardList,
  Calendar as CalendarIcon,
  AlertTriangle,
  Users,
  CheckCircle2,
  TrendingUp,
  TrendingDown,
  Edit3,
  Save,
  Eye,
  Laptop,
  Server,
  Clock,
  HardDrive
} from 'lucide-react';

interface RespaldoRow {
  id: string;
  department: string;
  puestoUsuario: string;
  equipo: string;
  nombre: string;
  s1: 'realizado' | 'no-realizado' | 'no-aplica';
  s2: 'realizado' | 'no-realizado' | 'no-aplica';
  s3: 'realizado' | 'no-realizado' | 'no-aplica';
  s4: 'realizado' | 'no-realizado' | 'no-aplica';
  s5: 'realizado' | 'no-realizado' | 'no-aplica';
  semanasAplicables: number;

  // Detalles adicionales para el Ojito 👁️
  tipoEquipo: 'Laptop' | 'PC Desktop' | 'All in One';
  rutaRespaldo: string;
  diaProgramado: string;
  horaProgramada: string;
  almacenamientoDestino: string;
}

interface SemanaDates {
  start: string;
  end: string;
}

export const RespaldosDashboard: React.FC = () => {
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState<'registro' | 'graficas'>('registro');
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedDept, setSelectedDept] = useState('todos');

  // Modal para ver detalles con el ojito 👁️
  const [selectedRowDetail, setSelectedRowDetail] = useState<RespaldoRow | null>(null);

  // Periodo seleccionado (Mes)
  const [periodoMesInput, setPeriodoMesInput] = useState('2026-07');
  const [isEditingHeader, setIsEditingHeader] = useState(false);

  // Fechas concretas de cada semana
  const [semanaDates, setSemanaDates] = useState<{
    s1: SemanaDates;
    s2: SemanaDates;
    s3: SemanaDates;
    s4: SemanaDates;
    s5: SemanaDates;
  }>({
    s1: { start: '2026-07-01', end: '2026-07-04' },
    s2: { start: '2026-07-05', end: '2026-07-11' },
    s3: { start: '2026-07-12', end: '2026-07-18' },
    s4: { start: '2026-07-19', end: '2026-07-25' },
    s5: { start: '2026-07-26', end: '2026-07-31' },
  });

  const formatPeriodoLabel = (yyyyMm: string) => {
    if (!yyyyMm) return 'Julio 2026';
    const [year, monthStr] = yyyyMm.split('-');
    const months = [
      'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
      'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
    ];
    const monthIdx = parseInt(monthStr, 10) - 1;
    return `${months[monthIdx] || 'Julio'} ${year}`;
  };

  const formatSemanaLabel = (dates: SemanaDates) => {
    if (!dates.start || !dates.end) return 'Sin fecha';
    const months = [
      'ene', 'feb', 'mar', 'abr', 'may', 'jun',
      'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
    ];

    const dStart = new Date(dates.start + 'T00:00:00');
    const dEnd = new Date(dates.end + 'T00:00:00');

    const dayStart = String(dStart.getDate()).padStart(2, '0');
    const dayEnd = String(dEnd.getDate()).padStart(2, '0');
    const monthName = months[dEnd.getMonth()];

    return `${dayStart}-${dayEnd} ${monthName}`;
  };

  // Datos de Registro con información ampliada de respaldo
  const [rows, setRows] = useState<RespaldoRow[]>([
    {
      id: 'R-01',
      department: 'GERENCIA / DIRECCIÓN',
      puestoUsuario: 'Gerente de Servicio',
      equipo: 'MX38080VC0036',
      nombre: 'Oswaldo Pineda',
      s1: 'no-realizado',
      s2: 'realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'Laptop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\Gerencia_Servicio\\OswaldoPineda',
      diaProgramado: 'Viernes',
      horaProgramada: '18:00 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
    {
      id: 'R-02',
      department: 'VENTAS',
      puestoUsuario: 'Gerente de Afasa',
      equipo: 'MX38080VL0037_1',
      nombre: 'Silvia Roman',
      s1: 'no-realizado',
      s2: 'no-realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'Laptop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\Ventas\\SilviaRoman',
      diaProgramado: 'Viernes',
      horaProgramada: '17:30 hrs',
      almacenamientoDestino: 'Servidor Central de Respaldos (Disco R:)',
    },
    {
      id: 'R-03',
      department: 'VENTAS',
      puestoUsuario: 'Admin de Ventas',
      equipo: 'MX38080VC0052',
      nombre: 'Cristinia Garcia',
      s1: 'realizado',
      s2: 'realizado',
      s3: 'realizado',
      s4: 'realizado',
      s5: 'realizado',
      semanasAplicables: 5,
      tipoEquipo: 'PC Desktop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\AdminVentas\\CristinaGarcia',
      diaProgramado: 'Jueves',
      horaProgramada: '18:00 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
    {
      id: 'R-04',
      department: 'VENTAS',
      puestoUsuario: 'Gerente Comercial',
      equipo: 'MX38080VL0035',
      nombre: 'Alejandro Tejeda',
      s1: 'no-realizado',
      s2: 'no-realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'Laptop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\Ventas\\AlejandroTejeda',
      diaProgramado: 'Viernes',
      horaProgramada: '18:30 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
    {
      id: 'R-05',
      department: 'VENTAS',
      puestoUsuario: 'Ventas Digitales',
      equipo: 'MX38080VC0055',
      nombre: 'Yestal Tejeda',
      s1: 'no-realizado',
      s2: 'realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'All in One',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\VentasDigitales\\YestalTejeda',
      diaProgramado: 'Viernes',
      horaProgramada: '16:00 hrs',
      almacenamientoDestino: 'Servidor Central de Respaldos (Disco R:)',
    },
    {
      id: 'R-06',
      department: 'VENTAS',
      puestoUsuario: 'Gerente Flotillas',
      equipo: 'MX38080VL0027',
      nombre: 'Gerente de Flotillas',
      s1: 'realizado',
      s2: 'realizado',
      s3: 'realizado',
      s4: 'realizado',
      s5: 'realizado',
      semanasAplicables: 5,
      tipoEquipo: 'Laptop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\Flotillas\\GerenteFlotillas',
      diaProgramado: 'Sábado',
      horaProgramada: '13:00 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
    {
      id: 'R-07',
      department: 'VENTAS',
      puestoUsuario: 'Call Center',
      equipo: 'MX38080VC0063',
      nombre: 'Call Center',
      s1: 'realizado',
      s2: 'realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'PC Desktop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\CallCenter\\EquipoCC',
      diaProgramado: 'Sábado',
      horaProgramada: '14:00 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
    {
      id: 'R-08',
      department: 'VENTAS',
      puestoUsuario: 'Ventanilla Única',
      equipo: 'MX38080VC0026',
      nombre: 'Ventanilla Unica',
      s1: 'realizado',
      s2: 'realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'PC Desktop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\Ventanilla\\VentanillaUnica',
      diaProgramado: 'Viernes',
      horaProgramada: '17:00 hrs',
      almacenamientoDestino: 'Servidor Central de Respaldos (Disco R:)',
    },
    {
      id: 'R-09',
      department: 'VENTAS',
      puestoUsuario: 'Vendedor de flotillas',
      equipo: 'MX38080VL0028',
      nombre: 'Vendedor de flotillas',
      s1: 'no-realizado',
      s2: 'no-realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'Laptop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\Flotillas\\VendedorFlotillas',
      diaProgramado: 'Viernes',
      horaProgramada: '18:00 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
    {
      id: 'R-10',
      department: 'SERVICIOS FINANCIEROS',
      puestoUsuario: 'Retention Manager',
      equipo: 'MX3808VC00',
      nombre: 'Adrina Alcazar',
      s1: 'realizado',
      s2: 'realizado',
      s3: 'realizado',
      s4: 'realizado',
      s5: 'realizado',
      semanasAplicables: 5,
      tipoEquipo: 'Laptop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\ServiciosFinancieros\\AdrinaAlcazar',
      diaProgramado: 'Viernes',
      horaProgramada: '17:00 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
    {
      id: 'R-11',
      department: 'SERVICIOS FINANCIEROS',
      puestoUsuario: 'Asistente S.F.',
      equipo: 'MX38080VC0053',
      nombre: 'Erandi Miranda',
      s1: 'realizado',
      s2: 'realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'PC Desktop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\ServiciosFinancieros\\ErandiMiranda',
      diaProgramado: 'Viernes',
      horaProgramada: '18:00 hrs',
      almacenamientoDestino: 'Servidor Central de Respaldos (Disco R:)',
    },
    {
      id: 'R-12',
      department: 'SERVICIOS FINANCIEROS',
      puestoUsuario: 'Gerente S.F.',
      equipo: 'MX38080VL0036',
      nombre: 'Cesar Rodriguez',
      s1: 'realizado',
      s2: 'realizado',
      s3: 'no-aplica',
      s4: 'no-aplica',
      s5: 'no-aplica',
      semanasAplicables: 5,
      tipoEquipo: 'Laptop',
      rutaRespaldo: '\\\\SERVER-SITE-01\\Respaldos\\ServiciosFinancieros\\CesarRodriguez',
      diaProgramado: 'Viernes',
      horaProgramada: '19:00 hrs',
      almacenamientoDestino: 'NAS QNAP SITE Principal (Disco Z:)',
    },
  ]);

  const toggleWeekStatus = (id: string, week: 's1' | 's2' | 's3' | 's4' | 's5') => {
    setRows((prev) =>
      prev.map((r) => {
        if (r.id === id) {
          const current = r[week];
          let next: 'realizado' | 'no-realizado' | 'no-aplica' = 'realizado';
          if (current === 'realizado') next = 'no-realizado';
          else if (current === 'no-realizado') next = 'no-aplica';
          else next = 'realizado';

          toast.success(`Estatus de ${r.nombre} (${week.toUpperCase()}) cambiado a: ${next.toUpperCase()}`);
          return { ...r, [week]: next };
        }
        return r;
      })
    );
  };

  const calcRowTotal = (row: RespaldoRow) => {
    let count = 0;
    if (row.s1 === 'realizado') count++;
    if (row.s2 === 'realizado') count++;
    if (row.s3 === 'realizado') count++;
    if (row.s4 === 'realizado') count++;
    if (row.s5 === 'realizado') count++;
    return count;
  };

  const calcRowPct = (row: RespaldoRow) => {
    const total = calcRowTotal(row);
    return Math.round((total / row.semanasAplicables) * 100);
  };

  const totalUsuarios = rows.length;
  const usuarios100Pct = rows.filter((r) => calcRowPct(r) === 100).length;
  const usuarios0Pct = rows.filter((r) => calcRowPct(r) === 0).length;
  const usuariosBajo50Pct = rows.filter((r) => calcRowPct(r) < 50);
  
  const promedioEmpresarial = Math.round(
    rows.reduce((acc, r) => acc + calcRowPct(r), 0) / totalUsuarios
  );

  const departments = Array.from(new Set(rows.map((r) => r.department)));

  return (
    <div className="min-h-screen bg-[#060b17] text-slate-100 flex font-sans relative overflow-hidden">
      <Toaster position="top-right" />

      {/* Grid Background Pattern */}
      <div className="absolute inset-0 bg-grid-pattern pointer-events-none opacity-40"></div>

      {/* MENÚ LATERAL DEL MÓDULO DE RESPALDOS */}
      <aside className="w-64 bg-[#0c1529]/95 border-r border-[#1b2b4d] flex flex-col justify-between z-20 shadow-2xl relative">
        <div className="p-6 space-y-6">
          <div>
            <button
              onClick={() => navigate('/dashboard')}
              className="inline-flex items-center space-x-2 text-xs font-bold text-[#7088b3] hover:text-white transition-colors mb-4 cursor-pointer"
            >
              <ArrowLeft size={16} />
              <span>Volver a Portal Principal</span>
            </button>
            <div className="flex items-center space-x-3">
              <div className="w-10 h-10 bg-[#0284c7] text-white rounded-2xl flex items-center justify-center shadow-lg shadow-sky-900/30">
                <Cloud size={22} />
              </div>
              <div>
                <span className="text-[10px] font-bold text-[#486596] uppercase tracking-widest block">CONTROL DE TI</span>
                <h2 className="text-base font-black text-white tracking-tight">RESPALDOS</h2>
              </div>
            </div>
          </div>

          <nav className="space-y-1">
            <div className="text-[10px] font-extrabold text-[#7088b3] uppercase tracking-wider px-3 mb-2">
              Secciones
            </div>
            
            <button
              onClick={() => setActiveTab('registro')}
              className={`w-full flex items-center space-x-3 px-3.5 py-3 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'registro'
                  ? 'bg-[#0284c7] text-white shadow-lg shadow-sky-900/40 border border-sky-400/40'
                  : 'text-[#8ea5cc] hover:text-white hover:bg-[#121c35]/80'
              }`}
            >
              <ClipboardList size={18} />
              <span>Registro de Respaldos</span>
            </button>

            <button
              onClick={() => setActiveTab('graficas')}
              className={`w-full flex items-center space-x-3 px-3.5 py-3 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'graficas'
                  ? 'bg-[#0284c7] text-white shadow-lg shadow-sky-900/40 border border-sky-400/40'
                  : 'text-[#8ea5cc] hover:text-white hover:bg-[#121c35]/80'
              }`}
            >
              <PieChart size={18} />
              <span>Gráficas & Reporte</span>
            </button>
          </nav>
        </div>

        <div className="p-4 border-t border-[#1b2b4d] bg-[#060b17]/60 text-[11px] text-[#7088b3]">
          <span>Módulo de Control Mensual TI</span>
        </div>
      </aside>

      {/* CONTENIDO PRINCIPAL */}
      <main className="flex-1 p-6 sm:p-8 space-y-6 overflow-y-auto z-10">
        
        {/* Header Superior */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[#1b2b4d] pb-5">
          <div>
            <div className="flex items-center space-x-2 text-xs text-[#7088b3] font-medium mb-1">
              <span>Respaldos</span>
              <span>/</span>
              <span className="text-sky-400 font-bold">
                {activeTab === 'registro' ? 'Matriz de Registro' : 'Panel de Gráficas'}
              </span>
            </div>
            <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight">
              {activeTab === 'registro' ? 'Registro de Respaldos Semanales' : 'Gráficas & Analítica de Cumplimiento'}
            </h1>
            <p className="text-xs text-[#7088b3] mt-1 font-medium">
              Gestión mensual y monitoreo de cumplimiento de archivos corporativos
            </p>
          </div>

          <div className="flex items-center space-x-3">
            <button
              onClick={() => toast.success('Exportando informe a Excel...')}
              className="flex items-center space-x-2 bg-[#0c1529] hover:bg-[#121c35] text-slate-200 border border-[#1b2b4d] px-4 py-2.5 rounded-xl text-xs font-bold transition-all shadow-sm cursor-pointer"
            >
              <FileSpreadsheet size={16} className="text-emerald-400" />
              <span>Exportar Excel</span>
            </button>
          </div>
        </div>

        {/* PESTAÑA 1: REGISTRO DE RESPALDOS */}
        {activeTab === 'registro' && (
          <div className="space-y-6">
            
            {/* ENCABEZADO AZUL */}
            <div className="bg-[#002d72] rounded-2xl border border-blue-800 overflow-hidden shadow-2xl">
              <div className="p-4 flex flex-col md:flex-row md:items-center justify-between border-b border-blue-800/80 text-white font-bold text-xs gap-3">
                <div className="flex items-center space-x-3">
                  <span className="text-sm tracking-wide uppercase font-black">
                    MONITOREO SEMANAL DE CUMPLIMIENTO - ÁREA TI
                  </span>
                  <button
                    onClick={() => {
                      setIsEditingHeader(!isEditingHeader);
                      if (isEditingHeader) {
                        toast.success('Periodo y fechas del calendario guardados');
                      }
                    }}
                    className={`px-3 py-1 rounded-lg text-xs font-bold transition-all flex items-center space-x-1.5 cursor-pointer border ${
                      isEditingHeader
                        ? 'bg-emerald-600 hover:bg-emerald-500 text-white border-emerald-400 shadow-md'
                        : 'bg-blue-900/80 hover:bg-blue-800 text-blue-200 border-blue-700'
                    }`}
                  >
                    {isEditingHeader ? (
                      <>
                        <Save size={14} />
                        <span>Guardar Fechas</span>
                      </>
                    ) : (
                      <>
                        <CalendarIcon size={14} />
                        <span>Abrir Calendarios Semanales</span>
                      </>
                    )}
                  </button>
                </div>

                <div className="flex items-center space-x-4 text-[11px] font-semibold text-blue-100">
                  <span className="flex items-center space-x-1">
                    <span className="w-4 h-4 rounded bg-slate-700 text-slate-300 inline-flex items-center justify-center font-mono text-[10px]">Q</span>
                    <span>= No aplica semana</span>
                  </span>
                  <span className="flex items-center space-x-1">
                    <span className="w-4 h-4 rounded bg-emerald-600 text-white inline-flex items-center justify-center font-mono text-[10px]">☑</span>
                    <span>= Realizado</span>
                  </span>
                  <span className="flex items-center space-x-1">
                    <span className="w-4 h-4 rounded bg-rose-600 text-white inline-flex items-center justify-center font-mono text-[10px]">☒</span>
                    <span>= No Realizado</span>
                  </span>
                </div>
              </div>

              {/* BARRA DE MES Y FECHAS DE SEMANA */}
              <div className="bg-[#001b44] p-3.5 text-xs font-semibold text-blue-200 flex flex-wrap justify-between items-center gap-3">
                <div className="flex items-center space-x-2">
                  <span className="text-white font-bold">Responsable:</span>
                  <span className="text-slate-300">Área de TI</span>
                  <span className="text-blue-400 font-bold px-1">|</span>
                  <span className="text-white font-bold flex items-center space-x-1">
                    <CalendarIcon size={14} className="text-amber-400" />
                    <span>Periodo:</span>
                  </span>

                  {isEditingHeader ? (
                    <input
                      type="month"
                      value={periodoMesInput}
                      onChange={(e) => setPeriodoMesInput(e.target.value)}
                      className="bg-[#002d72] text-amber-300 font-bold border border-amber-400/80 rounded px-2.5 py-1 text-xs focus:outline-none cursor-pointer"
                    />
                  ) : (
                    <span className="text-amber-300 font-bold font-mono bg-blue-900/60 px-2.5 py-1 rounded border border-blue-700">
                      {formatPeriodoLabel(periodoMesInput)}
                    </span>
                  )}
                </div>

                <div className="flex items-center space-x-2 flex-wrap text-xs">
                  <span className="text-white font-bold font-sans">Semanas:</span>

                  {(['s1', 's2', 's3', 's4', 's5'] as const).map((sKey, idx) => {
                    const label = formatSemanaLabel(semanaDates[sKey]);
                    const weekName = `S${idx + 1}`;

                    return (
                      <div key={sKey} className="inline-flex items-center space-x-1.5 bg-[#002d72] px-2.5 py-1 rounded-xl border border-blue-800 shadow-sm">
                        <strong className="text-white font-mono">{weekName}</strong>
                        
                        {isEditingHeader ? (
                          <div className="flex items-center space-x-1 text-[11px]">
                            <input
                              type="date"
                              value={semanaDates[sKey].start}
                              onChange={(e) =>
                                setSemanaDates({
                                  ...semanaDates,
                                  [sKey]: { ...semanaDates[sKey], start: e.target.value },
                                })
                              }
                              className="bg-[#060b17] text-emerald-400 font-mono border border-emerald-500/50 rounded px-1.5 py-0.5 text-[11px] focus:outline-none cursor-pointer"
                            />
                            <span className="text-slate-400">a</span>
                            <input
                              type="date"
                              value={semanaDates[sKey].end}
                              onChange={(e) =>
                                setSemanaDates({
                                  ...semanaDates,
                                  [sKey]: { ...semanaDates[sKey], end: e.target.value },
                                })
                              }
                              className="bg-[#060b17] text-emerald-400 font-mono border border-emerald-500/50 rounded px-1.5 py-0.5 text-[11px] focus:outline-none cursor-pointer"
                            />
                          </div>
                        ) : (
                          <span className="text-slate-200 font-mono text-[11px]">
                            ({label})
                          </span>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>

            {/* Buscador & Filtro */}
            <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-4 shadow-xl flex flex-col md:flex-row justify-between items-center gap-4">
              <div className="relative w-full md:w-80">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-[#7088b3]">
                  <Search size={16} />
                </div>
                <input
                  type="text"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  placeholder="Buscar por usuario, equipo o puesto..."
                  className="w-full pl-9 pr-4 py-2 bg-[#060b17] border border-[#1b2b4d] rounded-xl text-white placeholder-[#7088b3] text-xs font-medium focus:outline-none focus:border-sky-500"
                />
              </div>

              <div className="flex items-center space-x-3 w-full md:w-auto">
                <span className="text-xs text-[#7088b3] font-bold uppercase">Filtrar Área:</span>
                <select
                  value={selectedDept}
                  onChange={(e) => setSelectedDept(e.target.value)}
                  className="bg-[#060b17] border border-[#1b2b4d] text-slate-200 text-xs px-3 py-2 rounded-xl focus:outline-none font-medium"
                >
                  <option value="todos">Todas las Áreas</option>
                  {departments.map((d) => (
                    <option key={d} value={d}>{d}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* TABLA PRINCIPAL CON COLUMNA DE ACCIÓN "OJO 👁️" SOLICITADA */}
            <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl shadow-2xl overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead className="bg-[#002d72] text-white uppercase font-black text-[11px] border-b border-blue-900">
                    <tr>
                      <th className="px-4 py-3.5 w-60 border-r border-blue-900">PUESTO / USUARIO</th>
                      <th className="px-4 py-3.5 w-32 border-r border-blue-900">EQUIPO</th>
                      <th className="px-4 py-3.5 w-44 border-r border-blue-900">NOMBRE</th>
                      <th className="px-3 py-3.5 text-center w-14 border-r border-blue-900">S1</th>
                      <th className="px-3 py-3.5 text-center w-14 border-r border-blue-900">S2</th>
                      <th className="px-3 py-3.5 text-center w-14 border-r border-blue-900">S3</th>
                      <th className="px-3 py-3.5 text-center w-14 border-r border-blue-900">S4</th>
                      <th className="px-3 py-3.5 text-center w-14 border-r border-blue-900">S5</th>
                      <th className="px-3 py-3.5 text-center w-20 border-r border-blue-900">REALIZADO</th>
                      <th className="px-3 py-3.5 text-center w-24 border-r border-blue-900">APLICABLES</th>
                      <th className="px-3 py-3.5 text-center w-20 border-r border-blue-900">% CUMPL.</th>
                      <th className="px-4 py-3.5 text-center w-20">FICHA (👁️)</th>
                    </tr>
                  </thead>

                  <tbody>
                    {departments.map((dept) => {
                      if (selectedDept !== 'todos' && selectedDept !== dept) return null;

                      const deptRows = rows.filter((r) => {
                        const matchDept = r.department === dept;
                        const matchSearch =
                          r.puestoUsuario.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          r.nombre.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          r.equipo.toLowerCase().includes(searchTerm.toLowerCase());
                        return matchDept && matchSearch;
                      });

                      if (deptRows.length === 0) return null;

                      return (
                        <React.Fragment key={dept}>
                          <tr className="bg-[#14325c] text-white font-extrabold text-xs uppercase tracking-wider border-y border-blue-900">
                            <td colSpan={12} className="px-4 py-2.5 bg-gradient-to-r from-[#14325c] to-[#0c1529]">
                              {dept}
                            </td>
                          </tr>

                          {deptRows.map((r) => {
                            const totalRealizado = calcRowTotal(r);
                            const porcentaje = calcRowPct(r);

                            return (
                              <tr key={r.id} className="hover:bg-[#122240] transition-colors border-b border-[#1b2b4d]/60 bg-white/5 text-slate-100 font-medium">
                                <td className="px-4 py-3 border-r border-[#1b2b4d]/40 font-bold text-slate-200">
                                  {r.puestoUsuario}
                                </td>
                                <td className="px-4 py-3 border-r border-[#1b2b4d]/40 font-mono text-blue-300 font-bold">
                                  {r.equipo}
                                </td>
                                <td className="px-4 py-3 border-r border-[#1b2b4d]/40 text-slate-100 font-semibold">
                                  {r.nombre}
                                </td>

                                {(['s1', 's2', 's3', 's4', 's5'] as const).map((w) => {
                                  const val = r[w];
                                  return (
                                    <td
                                      key={w}
                                      onClick={() => toggleWeekStatus(r.id, w)}
                                      className="px-2 py-3 text-center border-r border-[#1b2b4d]/40 cursor-pointer select-none hover:bg-blue-600/20 transition-colors"
                                    >
                                      {val === 'realizado' && (
                                        <span className="w-5 h-5 rounded bg-emerald-500 text-slate-950 inline-flex items-center justify-center font-bold text-xs shadow-md">
                                          <Check size={13} strokeWidth={3} />
                                        </span>
                                      )}
                                      {val === 'no-realizado' && (
                                        <span className="w-5 h-5 rounded bg-rose-500 text-white inline-flex items-center justify-center font-bold text-xs shadow-md">
                                          <X size={13} strokeWidth={3} />
                                        </span>
                                      )}
                                      {val === 'no-aplica' && (
                                        <span className="w-5 h-5 rounded bg-slate-800 text-slate-400 border border-slate-700 inline-flex items-center justify-center font-mono text-[10px]">
                                          Q
                                        </span>
                                      )}
                                    </td>
                                  );
                                })}

                                <td className="px-3 py-3 text-center border-r border-[#1b2b4d]/40 font-bold text-white font-mono text-xs">
                                  {totalRealizado}
                                </td>
                                <td className="px-3 py-3 text-center border-r border-[#1b2b4d]/40 font-bold text-slate-400 font-mono text-xs">
                                  {r.semanasAplicables}
                                </td>
                                <td className="px-3 py-3 text-center border-r border-[#1b2b4d]/40 font-mono font-black text-xs">
                                  <span className={`px-2 py-0.5 rounded font-mono ${
                                    porcentaje >= 80 ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' :
                                    porcentaje >= 50 ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' :
                                    'bg-rose-500/20 text-rose-400 border border-rose-500/30'
                                  }`}>
                                    {porcentaje}%
                                  </span>
                                </td>

                                {/* BOTÓN DE OJO 👁️ SOLICITADO */}
                                <td className="px-4 py-3 text-center">
                                  <button
                                    onClick={() => setSelectedRowDetail(r)}
                                    className="p-1.5 bg-[#121c35] hover:bg-[#0284c7] text-sky-400 hover:text-white rounded-lg transition-colors border border-sky-500/30 cursor-pointer shadow-sm"
                                    title="Ver Ruta de Respaldo, Tipo de Equipo y Día Programado"
                                  >
                                    <Eye size={16} />
                                  </button>
                                </td>
                              </tr>
                            );
                          })}
                        </React.Fragment>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* PESTAÑA 2: GRÁFICAS & REPORTES */}
        {activeTab === 'graficas' && (
          <div className="space-y-8 animate-fadeIn">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-5 shadow-xl flex items-center space-x-4">
                <div className="p-3.5 bg-blue-600/10 border border-blue-500/30 text-blue-400 rounded-2xl">
                  <Users size={26} />
                </div>
                <div>
                  <span className="text-[11px] font-bold text-[#7088b3] uppercase tracking-wider block">Total Usuarios</span>
                  <span className="text-2xl font-black text-white">{totalUsuarios} Monitoreados</span>
                </div>
              </div>

              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-5 shadow-xl flex items-center space-x-4">
                <div className="p-3.5 bg-emerald-600/10 border border-emerald-500/30 text-emerald-400 rounded-2xl">
                  <CheckCircle2 size={26} />
                </div>
                <div>
                  <span className="text-[11px] font-bold text-[#7088b3] uppercase tracking-wider block">Cumplimiento 100%</span>
                  <span className="text-2xl font-black text-emerald-400">{usuarios100Pct} Usuarios</span>
                </div>
              </div>

              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-5 shadow-xl flex items-center space-x-4">
                <div className="p-3.5 bg-rose-600/10 border border-rose-500/30 text-rose-400 rounded-2xl">
                  <TrendingDown size={26} />
                </div>
                <div>
                  <span className="text-[11px] font-bold text-[#7088b3] uppercase tracking-wider block">Cumplimiento 0%</span>
                  <span className="text-2xl font-black text-rose-400">{usuarios0Pct} Usuarios</span>
                </div>
              </div>

              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-5 shadow-xl flex items-center space-x-4">
                <div className="p-3.5 bg-sky-600/10 border border-sky-500/30 text-sky-400 rounded-2xl">
                  <TrendingUp size={26} />
                </div>
                <div>
                  <span className="text-[11px] font-bold text-[#7088b3] uppercase tracking-wider block">Promedio Global</span>
                  <span className="text-2xl font-black text-sky-400">{promedioEmpresarial}%</span>
                </div>
              </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-6 shadow-xl space-y-4">
                <div className="flex items-center justify-between border-b border-[#1b2b4d] pb-3">
                  <h3 className="text-sm font-extrabold text-white uppercase tracking-wider flex items-center space-x-2">
                    <TrendingUp size={18} className="text-sky-400" />
                    <span>Cumplimiento Promedio por Departamento ({formatPeriodoLabel(periodoMesInput)})</span>
                  </h3>
                </div>

                <div className="space-y-4 pt-2">
                  {departments.map((d) => {
                    const deptItems = rows.filter((r) => r.department === d);
                    const avg = Math.round(
                      deptItems.reduce((acc, r) => acc + calcRowPct(r), 0) / deptItems.length
                    );

                    return (
                      <div key={d} className="space-y-1.5">
                        <div className="flex justify-between text-xs font-semibold">
                          <span className="text-slate-200">{d}</span>
                          <span className="font-mono text-sky-400 font-bold">{avg}%</span>
                        </div>
                        <div className="w-full bg-[#060b17] h-3 rounded-full overflow-hidden border border-[#1b2b4d]">
                          <div
                            className={`h-full rounded-full transition-all duration-500 ${
                              avg >= 80 ? 'bg-gradient-to-r from-emerald-600 to-emerald-400' :
                              avg >= 40 ? 'bg-gradient-to-r from-amber-600 to-amber-400' :
                              'bg-gradient-to-r from-rose-600 to-rose-400'
                            }`}
                            style={{ width: `${avg}%` }}
                          ></div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>

              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-6 shadow-xl space-y-4">
                <div className="flex items-center justify-between border-b border-[#1b2b4d] pb-3">
                  <h3 className="text-sm font-extrabold text-white uppercase tracking-wider flex items-center space-x-2">
                    <PieChart size={18} className="text-purple-400" />
                    <span>Distribución General de Usuarios</span>
                  </h3>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 items-center">
                  <div className="relative w-40 h-40 mx-auto flex items-center justify-center">
                    <svg className="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                      <path
                        className="text-slate-800"
                        strokeWidth="4"
                        stroke="currentColor"
                        fill="none"
                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                      />
                      <path
                        className="text-emerald-500"
                        strokeDasharray={`${(usuarios100Pct / totalUsuarios) * 100}, 100`}
                        strokeWidth="4"
                        strokeLinecap="round"
                        stroke="currentColor"
                        fill="none"
                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                      />
                    </svg>
                    <div className="absolute flex flex-col items-center justify-center text-center">
                      <span className="text-xl font-black text-white">{promedioEmpresarial}%</span>
                      <span className="text-[10px] text-[#7088b3] font-bold">Meta Cumplida</span>
                    </div>
                  </div>

                  <div className="space-y-3 text-xs font-semibold">
                    <div className="flex items-center justify-between p-2 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                      <span className="text-emerald-400 font-bold">100% Excelente</span>
                      <span className="font-mono text-white font-bold">{usuarios100Pct} usrs</span>
                    </div>
                    <div className="flex items-center justify-between p-2 rounded-xl bg-amber-500/10 border border-amber-500/20">
                      <span className="text-amber-400 font-bold">50% - 99% Regular</span>
                      <span className="font-mono text-white font-bold">{totalUsuarios - usuarios100Pct - usuarios0Pct} usrs</span>
                    </div>
                    <div className="flex items-center justify-between p-2 rounded-xl bg-rose-500/10 border border-rose-500/20">
                      <span className="text-rose-400 font-bold">&lt; 50% Crítico (Atención)</span>
                      <span className="font-mono text-white font-bold">{usuariosBajo50Pct.length} usrs</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

      </main>

      {/* MODAL DETALLE AL PRESIONAR EL OJITO 👁️ SOLICITADO */}
      {selectedRowDetail && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#060b17]/85 backdrop-blur-md animate-fadeIn">
          <div className="bg-[#0c1529] border border-sky-500/40 rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl relative space-y-6">
            <button
              onClick={() => setSelectedRowDetail(null)}
              className="absolute top-6 right-6 text-slate-400 hover:text-white p-1.5 rounded-xl hover:bg-slate-800 transition-colors cursor-pointer"
            >
              <X size={20} />
            </button>

            <div className="flex items-center space-x-4">
              <div className="w-12 h-12 bg-sky-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-sky-900/40">
                <Server size={24} />
              </div>
              <div>
                <span className="text-[10px] font-extrabold text-[#486596] uppercase tracking-wider block">FICHA DE PROGRAMACIÓN DE RESPALDO</span>
                <h3 className="text-xl font-black text-white">{selectedRowDetail.nombre}</h3>
                <p className="text-xs text-sky-400 font-medium">{selectedRowDetail.puestoUsuario} | {selectedRowDetail.department}</p>
              </div>
            </div>

            <div className="bg-[#060b17] p-5 rounded-2xl border border-[#1b2b4d] space-y-3.5 text-xs">
              <div className="flex justify-between border-b border-[#1b2b4d] pb-2.5">
                <span className="text-[#7088b3] font-semibold flex items-center space-x-1.5">
                  <Laptop size={15} className="text-sky-400" />
                  <span>Tipo de Equipo:</span>
                </span>
                <span className="font-bold text-white px-2 py-0.5 rounded bg-sky-500/10 border border-sky-500/20">{selectedRowDetail.tipoEquipo}</span>
              </div>

              <div className="flex justify-between border-b border-[#1b2b4d] pb-2.5">
                <span className="text-[#7088b3] font-semibold">Nº / ID Equipo:</span>
                <span className="font-mono text-blue-400 font-bold">{selectedRowDetail.equipo}</span>
              </div>

              <div className="flex justify-between border-b border-[#1b2b4d] pb-2.5">
                <span className="text-[#7088b3] font-semibold flex items-center space-x-1.5">
                  <Clock size={15} className="text-amber-400" />
                  <span>Día & Hora Programado:</span>
                </span>
                <span className="font-bold text-amber-300">{selectedRowDetail.diaProgramado} a las {selectedRowDetail.horaProgramada}</span>
              </div>

              <div className="space-y-1.5 border-b border-[#1b2b4d] pb-2.5">
                <span className="text-[#7088b3] font-semibold block flex items-center space-x-1.5">
                  <Server size={15} className="text-emerald-400" />
                  <span>Ruta / Dirección de Guardado del Respaldo:</span>
                </span>
                <div className="font-mono text-emerald-400 bg-[#0c1529] p-2.5 rounded-xl border border-[#1b2b4d] text-[11px] break-all">
                  {selectedRowDetail.rutaRespaldo}
                </div>
              </div>

              <div className="flex justify-between pt-1">
                <span className="text-[#7088b3] font-semibold flex items-center space-x-1.5">
                  <HardDrive size={15} className="text-purple-400" />
                  <span>Almacenamiento Destino:</span>
                </span>
                <span className="font-medium text-slate-200">{selectedRowDetail.almacenamientoDestino}</span>
              </div>
            </div>

            <div className="pt-2 flex justify-end">
              <button
                onClick={() => setSelectedRowDetail(null)}
                className="w-full py-3 bg-[#0284c7] hover:bg-sky-600 text-white font-bold rounded-xl text-xs transition-all shadow-lg shadow-sky-600/30 cursor-pointer"
              >
                Cerrar Detalle de Respaldo
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
