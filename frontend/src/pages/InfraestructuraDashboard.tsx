import React, { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import toast, { Toaster } from 'react-hot-toast';
import {
  ArrowLeft,
  FileSpreadsheet,
  Server,
  Building2,
  Network,
  Radio,
  Wifi,
  Search,
  Plus,
  Zap,
  Thermometer,
  Shield,
  Activity,
  Layers,
  MapPin,
  X,
  Edit2,
  Trash2,
  Save,
  Move,
  Camera,
  Layers3,
  Sliders,
  Maximize2
} from 'lucide-react';

interface MapZone {
  id: string;
  name: string;
  code: string;
  borderColor: string;
  bgColor: string;
  x: number; // % (0-100)
  y: number; // % (0-100)
  width: number; // %
  height: number; // %
}

interface MapPinItem {
  id: string;
  label: string;
  type: 'site' | 'idf' | 'wifi' | 'cctv' | 'nodo';
  ipAddress?: string;
  x: number;
  y: number;
}

interface NetworkNode {
  id: string;
  patchPort: string;
  agency: string;
  location: string;
  switchPort: string;
  vlan: string;
  device: string;
  ipAddress: string;
  status: 'Activo' | 'Inactivo' | 'Mantenimiento';
}

export const InfraestructuraDashboard: React.FC = () => {
  const navigate = useNavigate();
  const canvasRef = useRef<HTMLDivElement>(null);

  const [activeTab, setActiveTab] = useState<'agencia' | 'red' | 'site' | 'nodos'>('agencia');
  const [selectedAgency, setSelectedAgency] = useState<string>('VW Divol La Villa');
  const [selectedFloor, setSelectedFloor] = useState<string>('Planta Baja');
  const [searchTerm, setSearchTerm] = useState('');

  // Modo Edición & Arrastre Canva / Word
  const [isEditMode, setIsEditMode] = useState(false);
  const [selectedPinDetail, setSelectedPinDetail] = useState<MapPinItem | null>(null);

  // Estado del arrastre activo
  const [dragItem, setDragItem] = useState<{
    id: string;
    type: 'pin' | 'zone';
    startX: number;
    startY: number;
    initialItemX: number;
    initialItemY: number;
  } | null>(null);

  // Modales
  const [showAddFloorModal, setShowAddFloorModal] = useState(false);
  const [showAddZoneModal, setShowAddZoneModal] = useState(false);
  const [showAddPinModal, setShowAddPinModal] = useState(false);

  // Formulario nuevo piso
  const [newFloorName, setNewFloorName] = useState('');

  // Formulario nueva zona
  const [newZoneName, setNewZoneName] = useState('');
  const [newZoneCode, setNewZoneCode] = useState('');
  const [newZoneColor, setNewZoneColor] = useState('blue');

  // Formulario nuevo pin
  const [newPinLabel, setNewPinLabel] = useState('');
  const [newPinType, setNewPinType] = useState<'site' | 'idf' | 'wifi' | 'cctv' | 'nodo'>('wifi');
  const [newPinIp, setNewPinIp] = useState('');

  // Pisos por Sucursal
  const [agencyFloors, setAgencyFloors] = useState<Record<string, string[]>>({
    'VW Divol La Villa': ['Planta Baja', 'Planta Alta / Mezzanine'],
    'Seat La Villa': ['Planta Baja', 'Piso 1'],
    'Cupra Garage La Villa': ['Planta Baja', 'Piso 1'],
  });

  // Zonas del Mapa por (Sucursal + Piso)
  const [agencyZones, setAgencyZones] = useState<Record<string, MapZone[]>>({
    'VW Divol La Villa_Planta Baja': [
      { id: 'z1', name: 'SITE PRINCIPAL TI', code: 'ZONA 01', borderColor: 'border-blue-500', bgColor: 'bg-blue-950/40', x: 5, y: 5, width: 22, height: 60 },
      { id: 'z2', name: 'Showroom & Asesores Ventas', code: 'ZONA 02', borderColor: 'border-slate-700', bgColor: 'bg-[#0c1529]/70', x: 30, y: 5, width: 42, height: 35 },
      { id: 'z3', name: 'Caja & Administración', code: 'ZONA 03', borderColor: 'border-amber-500', bgColor: 'bg-amber-950/30', x: 75, y: 5, width: 20, height: 35 },
      { id: 'z4', name: 'Taller de Servicio & Recepción', code: 'ZONA 04', borderColor: 'border-purple-500', bgColor: 'bg-purple-950/30', x: 30, y: 45, width: 42, height: 50 },
      { id: 'z5', name: 'Almacén Refacciones', code: 'ZONA 05', borderColor: 'border-emerald-500', bgColor: 'bg-emerald-950/30', x: 75, y: 45, width: 20, height: 50 },
    ],
    'VW Divol La Villa_Planta Alta / Mezzanine': [
      { id: 'z6', name: 'Gerencia General & Finanzas', code: 'ZONA 06', borderColor: 'border-blue-500', bgColor: 'bg-blue-950/40', x: 10, y: 10, width: 40, height: 40 },
      { id: 'z7', name: 'Sala de Juntas Corporativa', code: 'ZONA 07', borderColor: 'border-amber-500', bgColor: 'bg-amber-950/30', x: 55, y: 10, width: 35, height: 40 },
    ],
  });

  // Pines de Equipos por (Sucursal + Piso)
  const [agencyPins, setAgencyPins] = useState<Record<string, MapPinItem[]>>({
    'VW Divol La Villa_Planta Baja': [
      { id: 'p1', label: 'SITE Central (Rack 42U)', type: 'site', ipAddress: '192.168.26.1', x: 15, y: 35 },
      { id: 'p2', label: 'AP Aruba Showroom', type: 'wifi', ipAddress: '192.168.26.200', x: 45, y: 20 },
      { id: 'p3', label: 'Isla Ventas (8 Nodos)', type: 'nodo', ipAddress: '192.168.26.45', x: 60, y: 20 },
      { id: 'p4', label: 'Caja Principal (N-04)', type: 'nodo', ipAddress: '192.168.26.88', x: 84, y: 20 },
      { id: 'p5', label: 'IDF Taller Servicio', type: 'idf', ipAddress: '192.168.26.5', x: 45, y: 70 },
      { id: 'p6', label: 'Cámara CCTV Taller 4K', type: 'cctv', ipAddress: '192.168.28.12', x: 60, y: 70 },
    ],
    'VW Divol La Villa_Planta Alta / Mezzanine': [
      { id: 'p7', label: 'AP Mezzanine WiFi', type: 'wifi', ipAddress: '192.168.26.203', x: 30, y: 30 },
      { id: 'p8', label: 'Nodo Gerencia General', type: 'nodo', ipAddress: '192.168.26.90', x: 70, y: 30 },
    ],
  });

  const currentKey = `${selectedAgency}_${selectedFloor}`;
  const currentZones = agencyZones[currentKey] || [];
  const currentPins = agencyPins[currentKey] || [];
  const currentFloors = agencyFloors[selectedAgency] || ['Planta Baja'];

  // INICIO DEL ARRASTRE AL PRESIONAR CLICK (onMouseDown)
  const startDrag = (
    e: React.MouseEvent,
    id: string,
    type: 'pin' | 'zone',
    initialX: number,
    initialY: number
  ) => {
    if (!isEditMode) return;
    e.stopPropagation();

    setDragItem({
      id,
      type,
      startX: e.clientX,
      startY: e.clientY,
      initialItemX: initialX,
      initialItemY: initialY,
    });
  };

  // MANEJADOR GLOBAL DE MOVIMIENTO DE MOUSE MIENTRAS ARRASTRA (Sin Soltar el Cursor)
  useEffect(() => {
    const handleMouseMove = (e: MouseEvent) => {
      if (!dragItem || !canvasRef.current) return;

      const rect = canvasRef.current.getBoundingClientRect();
      const deltaXPct = ((e.clientX - dragItem.startX) / rect.width) * 100;
      const deltaYPct = ((e.clientY - dragItem.startY) / rect.height) * 100;

      const newX = Math.max(1, Math.min(95, dragItem.initialItemX + deltaXPct));
      const newY = Math.max(1, Math.min(95, dragItem.initialItemY + deltaYPct));

      if (dragItem.type === 'pin') {
        setAgencyPins((prev) => ({
          ...prev,
          [currentKey]: (prev[currentKey] || []).map((p) =>
            p.id === dragItem.id ? { ...p, x: Math.round(newX), y: Math.round(newY) } : p
          ),
        }));
      } else if (dragItem.type === 'zone') {
        setAgencyZones((prev) => ({
          ...prev,
          [currentKey]: (prev[currentKey] || []).map((z) =>
            z.id === dragItem.id ? { ...z, x: Math.round(newX), y: Math.round(newY) } : z
          ),
        }));
      }
    };

    const handleMouseUp = () => {
      if (dragItem) {
        setDragItem(null);
      }
    };

    if (dragItem) {
      window.addEventListener('mousemove', handleMouseMove);
      window.addEventListener('mouseup', handleMouseUp);
    }

    return () => {
      window.removeEventListener('mousemove', handleMouseMove);
      window.removeEventListener('mouseup', handleMouseUp);
    };
  }, [dragItem, currentKey]);

  // Cambiar dimensiones de zona (Ancho / Alto)
  const resizeZone = (zoneId: string, dWidth: number, dHeight: number) => {
    setAgencyZones((prev) => ({
      ...prev,
      [currentKey]: (prev[currentKey] || []).map((z) => {
        if (z.id === zoneId) {
          return {
            ...z,
            width: Math.max(10, Math.min(90, z.width + dWidth)),
            height: Math.max(10, Math.min(90, z.height + dHeight)),
          };
        }
        return z;
      }),
    }));
  };

  // Agregar Nuevo Piso
  const handleAddFloor = () => {
    if (!newFloorName) {
      toast.error('Ingresa el nombre del piso o nivel');
      return;
    }

    setAgencyFloors((prev) => ({
      ...prev,
      [selectedAgency]: [...(prev[selectedAgency] || []), newFloorName],
    }));

    setSelectedFloor(newFloorName);
    toast.success(`Piso "${newFloorName}" creado correctamente`);
    setNewFloorName('');
    setShowAddFloorModal(false);
  };

  // Agregar Nueva Zona
  const handleAddZone = () => {
    if (!newZoneName) {
      toast.error('Ingresa el nombre de la zona');
      return;
    }

    const colorMap: Record<string, { border: string; bg: string }> = {
      blue: { border: 'border-blue-500', bg: 'bg-blue-950/40' },
      emerald: { border: 'border-emerald-500', bg: 'bg-emerald-950/40' },
      purple: { border: 'border-purple-500', bg: 'bg-purple-950/40' },
      amber: { border: 'border-amber-500', bg: 'bg-amber-950/40' },
      rose: { border: 'border-rose-500', bg: 'bg-rose-950/40' },
    };

    const chosenColor = colorMap[newZoneColor] || colorMap.blue;

    const newZoneObj: MapZone = {
      id: 'z_' + Date.now(),
      name: newZoneName,
      code: newZoneCode || `ZONA ${currentZones.length + 1}`,
      borderColor: chosenColor.border,
      bgColor: chosenColor.bg,
      x: 10,
      y: 10,
      width: 25,
      height: 35,
    };

    setAgencyZones((prev) => ({
      ...prev,
      [currentKey]: [...(prev[currentKey] || []), newZoneObj],
    }));

    toast.success(`Zona "${newZoneName}" agregada. ¡Arrástrala para posicionarla!`);
    setNewZoneName('');
    setNewZoneCode('');
    setShowAddZoneModal(false);
  };

  // Agregar Nuevo Pin/Equipo
  const handleAddPin = () => {
    if (!newPinLabel) {
      toast.error('Ingresa el nombre o etiqueta del equipo');
      return;
    }

    const newPinObj: MapPinItem = {
      id: 'pin_' + Date.now(),
      label: newPinLabel,
      type: newPinType,
      ipAddress: newPinIp || '192.168.26.X',
      x: 50,
      y: 50,
    };

    setAgencyPins((prev) => ({
      ...prev,
      [currentKey]: [...(prev[currentKey] || []), newPinObj],
    }));

    toast.success(`Equipo "${newPinLabel}" colocado en ${selectedFloor}. ¡Arrástralo sin soltar el cursor!`);
    setNewPinLabel('');
    setNewPinIp('');
    setShowAddPinModal(false);
  };

  // Eliminar Pin
  const handleDeletePin = (pinId: string) => {
    setAgencyPins((prev) => ({
      ...prev,
      [currentKey]: (prev[currentKey] || []).filter((p) => p.id !== pinId),
    }));
    toast.success('Equipo eliminado del plano');
  };

  // Eliminar Zona
  const handleDeleteZone = (zoneId: string) => {
    setAgencyZones((prev) => ({
      ...prev,
      [currentKey]: (prev[currentKey] || []).filter((z) => z.id !== zoneId),
    }));
    toast.success('Zona eliminada del plano');
  };

  // Datos de Nodos de Red
  const [nodosList] = useState<NetworkNode[]>([
    {
      id: 'NOD-101',
      patchPort: 'PP-A-01',
      agency: 'VW Divol La Villa',
      location: 'Caja Ventas 1',
      switchPort: 'SW-CORE-01 (Port 12)',
      vlan: 'VLAN 10 (Cómputo)',
      device: 'PC Desktop HP ProDesk (MX38080VC0052)',
      ipAddress: '192.168.26.45',
      status: 'Activo',
    },
  ]);

  const filteredNodos = nodosList.filter((n) => {
    const isAgencyMatch = selectedAgency === 'Todas' || n.agency === selectedAgency;
    const isSearchMatch =
      n.id.toLowerCase().includes(searchTerm.toLowerCase()) ||
      n.location.toLowerCase().includes(searchTerm.toLowerCase()) ||
      n.device.toLowerCase().includes(searchTerm.toLowerCase());

    return isAgencyMatch && isSearchMatch;
  });

  return (
    <div className="min-h-screen bg-[#060b17] text-slate-100 flex font-sans relative overflow-hidden">
      <Toaster position="top-right" />

      {/* Grid Pattern Background */}
      <div className="absolute inset-0 bg-grid-pattern pointer-events-none opacity-40"></div>

      {/* MENÚ LATERAL */}
      <aside className="w-72 bg-[#0c1529]/95 border-r border-[#1b2b4d] flex flex-col justify-between z-20 shadow-2xl relative">
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
              <div className="w-10 h-10 bg-[#10b981] text-white rounded-2xl flex items-center justify-center shadow-lg shadow-emerald-900/30">
                <Server size={22} />
              </div>
              <div>
                <span className="text-[10px] font-bold text-[#486596] uppercase tracking-widest block">CONTROL DE TI</span>
                <h2 className="text-base font-black text-white tracking-tight">INFRAESTRUCTURA</h2>
              </div>
            </div>
          </div>

          <nav className="space-y-1">
            <div className="text-[10px] font-extrabold text-[#7088b3] uppercase tracking-wider px-3 mb-2">
              Secciones
            </div>
            
            <button
              onClick={() => setActiveTab('agencia')}
              className={`w-full flex items-center space-x-3 px-3.5 py-3 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'agencia'
                  ? 'bg-[#10b981] text-white shadow-lg shadow-emerald-900/40 border border-emerald-400/40'
                  : 'text-[#8ea5cc] hover:text-white hover:bg-[#121c35]/80'
              }`}
            >
              <Building2 size={18} />
              <span>AGENCIA (Diseñador Canva)</span>
            </button>

            <button
              onClick={() => setActiveTab('red')}
              className={`w-full flex items-center space-x-3 px-3.5 py-3 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'red'
                  ? 'bg-[#10b981] text-white shadow-lg shadow-emerald-900/40 border border-emerald-400/40'
                  : 'text-[#8ea5cc] hover:text-white hover:bg-[#121c35]/80'
              }`}
            >
              <Network size={18} />
              <span>RED (Topología & VLANs)</span>
            </button>

            <button
              onClick={() => setActiveTab('site')}
              className={`w-full flex items-center space-x-3 px-3.5 py-3 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'site'
                  ? 'bg-[#10b981] text-white shadow-lg shadow-emerald-900/40 border border-emerald-400/40'
                  : 'text-[#8ea5cc] hover:text-white hover:bg-[#121c35]/80'
              }`}
            >
              <Server size={18} />
              <span>SITE (Racks & Servidores)</span>
            </button>

            <button
              onClick={() => setActiveTab('nodos')}
              className={`w-full flex items-center space-x-3 px-3.5 py-3 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                activeTab === 'nodos'
                  ? 'bg-[#10b981] text-white shadow-lg shadow-emerald-900/40 border border-emerald-400/40'
                  : 'text-[#8ea5cc] hover:text-white hover:bg-[#121c35]/80'
              }`}
            >
              <Layers size={18} />
              <span>NODOS (Cableado & Puertos)</span>
            </button>
          </nav>
        </div>

        <div className="p-4 border-t border-[#1b2b4d] bg-[#060b17]/60 text-[11px] text-[#7088b3]">
          <span>SITE Principal: <code className="text-emerald-400 font-mono">192.168.26.1</code></span>
        </div>
      </aside>

      {/* CONTENIDO PRINCIPAL */}
      <main className="flex-1 p-6 sm:p-8 space-y-6 overflow-y-auto z-10">
        
        {/* Header Superior */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[#1b2b4d] pb-5">
          <div>
            <div className="flex items-center space-x-2 text-xs text-[#7088b3] font-medium mb-1">
              <span>Infraestructura</span>
              <span>/</span>
              <span className="text-emerald-400 font-bold uppercase">{activeTab}</span>
            </div>
            <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight uppercase">
              {activeTab === 'agencia' && 'Diseñador de Plano — Arrastre Continuo (Estilo Canva / Word)'}
              {activeTab === 'red' && 'Topología de Red & VLANs'}
              {activeTab === 'site' && 'Control de SITE Principal & Racks'}
              {activeTab === 'nodos' && 'Matriz de Nodos & Puertos'}
            </h1>
            <p className="text-xs text-[#7088b3] mt-1 font-medium">
              Arrastra componentes y zonas libremente con el cursor sin soltar
            </p>
          </div>

          <div className="flex items-center space-x-3">
            <select
              value={selectedAgency}
              onChange={(e) => {
                setSelectedAgency(e.target.value);
                const firstFloor = (agencyFloors[e.target.value] || ['Planta Baja'])[0];
                setSelectedFloor(firstFloor);
              }}
              className="bg-[#0c1529] border border-[#1b2b4d] text-white text-xs px-3.5 py-2.5 rounded-xl font-bold focus:outline-none focus:border-emerald-500 cursor-pointer"
            >
              <option value="VW Divol La Villa">VW Divol La Villa</option>
              <option value="Seat La Villa">Seat La Villa</option>
              <option value="Cupra Garage La Villa">Cupra Garage La Villa</option>
            </select>
          </div>
        </div>

        {/* 🏢 SECCIÓN 1: AGENCIA CON ARRASTRE CONTINUO ESTILO CANVA / WORD */}
        {activeTab === 'agencia' && (
          <div className="space-y-6 animate-fadeIn">
            
            <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-3xl p-6 shadow-2xl space-y-6">
              
              {/* Barra de Herramientas de Pisos & Diseñador */}
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[#1b2b4d] pb-4">
                
                {/* SELECTOR DE PISOS */}
                <div className="flex items-center space-x-2 overflow-x-auto">
                  <span className="text-xs font-bold text-[#7088b3] uppercase flex items-center space-x-1 pr-2">
                    <Layers3 size={16} className="text-emerald-400" />
                    <span>Nivel:</span>
                  </span>

                  {currentFloors.map((fl) => (
                    <button
                      key={fl}
                      onClick={() => setSelectedFloor(fl)}
                      className={`px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap border ${
                        selectedFloor === fl
                          ? 'bg-[#10b981] text-white border-emerald-400 shadow-md'
                          : 'bg-[#060b17] text-[#8ea5cc] border-[#1b2b4d] hover:text-white'
                      }`}
                    >
                      {fl}
                    </button>
                  ))}

                  <button
                    onClick={() => setShowAddFloorModal(true)}
                    className="p-2 bg-[#060b17] hover:bg-[#121c35] text-emerald-400 border border-[#1b2b4d] rounded-xl text-xs font-bold transition-all cursor-pointer"
                    title="+ Añadir Nuevo Piso"
                  >
                    <Plus size={16} />
                  </button>
                </div>

                {/* Botones Modo Edición / Canva */}
                <div className="flex flex-wrap items-center gap-3">
                  <button
                    onClick={() => setIsEditMode(!isEditMode)}
                    className={`px-4 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center space-x-2 cursor-pointer shadow-md border ${
                      isEditMode
                        ? 'bg-amber-600 hover:bg-amber-500 text-white border-amber-400'
                        : 'bg-[#121c35] hover:bg-[#1b2b4d] text-emerald-400 border-emerald-500/40'
                    }`}
                  >
                    {isEditMode ? (
                      <>
                        <Save size={16} />
                        <span>Guardar Diseño</span>
                      </>
                    ) : (
                      <>
                        <Move size={16} />
                        <span>🖐️ Activar Arrastre Canva / Word</span>
                      </>
                    )}
                  </button>

                  {isEditMode && (
                    <>
                      <button
                        onClick={() => setShowAddZoneModal(true)}
                        className="px-3.5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-bold transition-all flex items-center space-x-1.5 cursor-pointer shadow-md"
                      >
                        <Plus size={16} />
                        <span>+ Nueva Zona</span>
                      </button>

                      <button
                        onClick={() => setShowAddPinModal(true)}
                        className="px-3.5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold transition-all flex items-center space-x-1.5 cursor-pointer shadow-md"
                      >
                        <Plus size={16} />
                        <span>+ Colocar Equipo</span>
                      </button>
                    </>
                  )}
                </div>
              </div>

              {/* Mensaje Informativo si está en Modo Arrastre */}
              {isEditMode && (
                <div className="bg-emerald-500/10 border border-emerald-500/30 p-3 rounded-2xl text-xs text-emerald-300 font-medium flex items-center space-x-2">
                  <Move size={16} />
                  <span>
                    <strong>Arrastre Fluido (Canva / Word):</strong> Mantén presionado el clic sobre cualquier zona o equipo y arrástralo libremente por el plano sin soltar el cursor.
                  </span>
                </div>
              )}

              {/* LIENZO DE PLANO INTERACTIVO CON EVENTOS DE ARRASTRE CONTINUO */}
              <div 
                ref={canvasRef}
                className={`relative w-full h-[550px] bg-[#060b17] rounded-2xl border-2 border-[#1e2f54] overflow-hidden p-4 shadow-inner select-none ${
                  isEditMode ? 'cursor-grab active:cursor-grabbing' : ''
                }`}
              >
                <div className="absolute inset-0 bg-grid-pattern opacity-30 pointer-events-none"></div>

                <div className="absolute top-4 right-6 text-2xl font-black text-slate-800 uppercase tracking-widest pointer-events-none">
                  {selectedAgency} — {selectedFloor}
                </div>

                {/* DIBUJO DE ZONAS DIBUJABLES Y ARRASTRABLES CONTINUAMENTE */}
                {currentZones.map((z) => (
                  <div
                    key={z.id}
                    onMouseDown={(e) => startDrag(e, z.id, 'zone', z.x, z.y)}
                    className={`absolute rounded-2xl border-2 ${z.borderColor} ${z.bgColor} p-3 flex flex-col justify-between transition-shadow shadow-lg group ${
                      isEditMode ? 'cursor-grab active:cursor-grabbing hover:border-white hover:shadow-blue-500/50' : ''
                    }`}
                    style={{
                      left: `${z.x}%`,
                      top: `${z.y}%`,
                      width: `${z.width}%`,
                      height: `${z.height}%`,
                    }}
                  >
                    <div className="flex justify-between items-start">
                      <div>
                        <span className="text-[9px] font-extrabold uppercase tracking-wider text-slate-300 block">{z.code}</span>
                        <h4 className="text-xs font-black text-white leading-tight">{z.name}</h4>
                      </div>

                      {isEditMode && (
                        <div className="flex items-center space-x-1" onClick={(e) => e.stopPropagation()}>
                          {/* Botones de Cambio de Tamaño Estilo Canva */}
                          <button
                            onClick={() => resizeZone(z.id, 4, 4)}
                            className="p-1 bg-blue-900/80 hover:bg-blue-700 text-blue-200 rounded border border-blue-600 text-[10px] font-bold cursor-pointer"
                            title="Agrandar Zona"
                          >
                            <Maximize2 size={12} />
                          </button>

                          <button
                            onClick={() => handleDeleteZone(z.id)}
                            className="p-1 bg-rose-950/80 hover:bg-rose-800 text-rose-300 rounded border border-rose-700 cursor-pointer"
                            title="Eliminar Zona"
                          >
                            <Trash2 size={12} />
                          </button>
                        </div>
                      )}
                    </div>
                  </div>
                ))}

                {/* DIBUJO DE PINES DE EQUIPOS CON ARRASTRE CONTINUO */}
                {currentPins.map((p) => (
                  <div
                    key={p.id}
                    onMouseDown={(e) => startDrag(e, p.id, 'pin', p.x, p.y)}
                    className={`absolute transform -translate-x-1/2 -translate-y-1/2 flex flex-col items-center group z-30 ${
                      isEditMode ? 'cursor-grab active:cursor-grabbing' : ''
                    }`}
                    style={{ left: `${p.x}%`, top: `${p.y}%` }}
                  >
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        if (!isEditMode) {
                          setSelectedPinDetail(p);
                        }
                      }}
                      className={`w-10 h-10 rounded-2xl flex items-center justify-center shadow-xl transition-all duration-200 ${
                        p.type === 'site'
                          ? 'bg-blue-600 text-white hover:scale-110 shadow-blue-600/50'
                          : p.type === 'idf'
                          ? 'bg-purple-600 text-white hover:scale-110 shadow-purple-600/50'
                          : p.type === 'wifi'
                          ? 'bg-emerald-600 text-white hover:scale-110 shadow-emerald-600/50'
                          : p.type === 'cctv'
                          ? 'bg-rose-600 text-white hover:scale-110 shadow-rose-600/50'
                          : 'bg-amber-600 text-white hover:scale-110 shadow-amber-600/50'
                      }`}
                      title={p.label}
                    >
                      {p.type === 'site' && <Server size={20} />}
                      {p.type === 'idf' && <Server size={18} />}
                      {p.type === 'wifi' && <Wifi size={18} />}
                      {p.type === 'cctv' && <Camera size={18} />}
                      {p.type === 'nodo' && <Layers size={18} />}
                    </button>

                    <div className="px-2 py-0.5 rounded-lg text-[10px] font-bold mt-1 shadow-md whitespace-nowrap bg-[#0c1529]/95 text-white border border-[#1b2b4d]">
                      {p.label}
                    </div>

                    {isEditMode && (
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          handleDeletePin(p.id);
                        }}
                        className="mt-1 text-rose-400 hover:text-rose-200 text-[10px] font-bold underline bg-slate-950/80 px-1 rounded cursor-pointer"
                      >
                        Borrar
                      </button>
                    )}
                  </div>
                ))}

              </div>

            </div>

          </div>
        )}

        {/* SECCIONES RED, SITE, NODOS */}
        {activeTab === 'red' && (
          <div className="space-y-6 animate-fadeIn">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-5 shadow-xl flex items-center space-x-4">
                <div className="p-3.5 bg-blue-600/10 border border-blue-500/30 text-blue-400 rounded-2xl">
                  <Network size={24} />
                </div>
                <div>
                  <span className="text-[11px] font-bold text-[#7088b3] uppercase block">Enlace ISP Principal</span>
                  <span className="text-lg font-black text-white">Fibra 500 Mbps</span>
                </div>
              </div>

              <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-5 shadow-xl flex items-center space-x-4">
                <div className="p-3.5 bg-emerald-600/10 border border-emerald-500/30 text-emerald-400 rounded-2xl">
                  <Shield size={24} />
                </div>
                <div>
                  <span className="text-[11px] font-bold text-[#7088b3] uppercase block">Gateway / Firewall</span>
                  <span className="text-lg font-black text-emerald-400">192.168.26.1 (FortiGate)</span>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'site' && (
          <div className="space-y-6 animate-fadeIn">
            <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-6 shadow-xl space-y-4">
              <h3 className="text-base font-extrabold text-white flex items-center space-x-2">
                <Server size={20} className="text-emerald-400" />
                <span>Diagrama de RACK Principal 42U — {selectedAgency} ({selectedFloor})</span>
              </h3>

              <div className="bg-[#060b17] p-4 rounded-xl border border-[#1b2b4d] space-y-2 font-mono text-xs">
                <div className="bg-blue-950/80 border border-blue-600 p-3 rounded flex justify-between items-center text-blue-200">
                  <span className="font-bold">[U40-U41] Router FortiGate 60F Firewall Gateway (192.168.26.1)</span>
                  <span className="text-[10px] bg-blue-500/20 text-blue-300 px-2 py-0.5 rounded">CONECTADO</span>
                </div>
                <div className="bg-purple-950/80 border border-purple-600 p-3 rounded flex justify-between items-center text-purple-200">
                  <span className="font-bold">[U30-U34] Servidor Dell PowerEdge T340 (BD systems_portal 192.168.26.97)</span>
                  <span className="text-[10px] bg-purple-500/20 text-purple-300 px-2 py-0.5 rounded">ONLINE</span>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'nodos' && (
          <div className="space-y-6 animate-fadeIn">
            <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl shadow-xl overflow-hidden">
              <table className="w-full text-left text-xs">
                <thead className="bg-[#060b17]/90 text-[#7088b3] uppercase font-bold border-b border-[#1b2b4d]">
                  <tr>
                    <th className="px-6 py-4">ID Nodo</th>
                    <th className="px-6 py-4">Patch Panel</th>
                    <th className="px-6 py-4">Ubicación</th>
                    <th className="px-6 py-4">Puerto Switch</th>
                    <th className="px-6 py-4">Estado</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#1b2b4d]/60 text-slate-200 font-medium">
                  {filteredNodos.map((n) => (
                    <tr key={n.id} className="hover:bg-[#121c35]/50">
                      <td className="px-6 py-4 font-mono font-bold text-emerald-400">{n.id}</td>
                      <td className="px-6 py-4 font-mono">{n.patchPort}</td>
                      <td className="px-6 py-4">{n.location}</td>
                      <td className="px-6 py-4 font-mono text-blue-300">{n.switchPort}</td>
                      <td className="px-6 py-4">
                        <span className="bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 px-2.5 py-1 rounded-full font-bold text-[10px]">
                          {n.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

      </main>

      {/* MODAL CREAR NUEVO PISO */}
      {showAddFloorModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#060b17]/85 backdrop-blur-md">
          <div className="bg-[#0c1529] border border-[#1b2b4d] rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div className="flex justify-between items-center border-b border-[#1b2b4d] pb-3">
              <h3 className="text-base font-black text-white">+ Añadir Nuevo Piso / Nivel</h3>
              <button onClick={() => setShowAddFloorModal(false)} className="text-slate-400 hover:text-white">
                <X size={18} />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div>
                <label className="text-[#7088b3] font-bold block mb-1">Nombre del Piso o Nivel:</label>
                <input
                  type="text"
                  value={newFloorName}
                  onChange={(e) => setNewFloorName(e.target.value)}
                  placeholder="ej. Piso 2, Mezzanine, Sótano..."
                  className="w-full bg-[#060b17] border border-[#1b2b4d] rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>

            <div className="flex justify-end space-x-3 pt-2">
              <button
                onClick={handleAddFloor}
                className="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition-all shadow-md cursor-pointer"
              >
                Crear Piso en la Agencia
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL CREAR NUEVA ZONA */}
      {showAddZoneModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#060b17]/85 backdrop-blur-md">
          <div className="bg-[#0c1529] border border-[#1b2b4d] rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div className="flex justify-between items-center border-b border-[#1b2b4d] pb-3">
              <h3 className="text-base font-black text-white">+ Agregar Nueva Zona a {selectedFloor}</h3>
              <button onClick={() => setShowAddZoneModal(false)} className="text-slate-400 hover:text-white">
                <X size={18} />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div>
                <label className="text-[#7088b3] font-bold block mb-1">Nombre del Área / Zona:</label>
                <input
                  type="text"
                  value={newZoneName}
                  onChange={(e) => setNewZoneName(e.target.value)}
                  placeholder="ej. Taller Mecánico 2, Cubículos Finanzas..."
                  className="w-full bg-[#060b17] border border-[#1b2b4d] rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                />
              </div>

              <div>
                <label className="text-[#7088b3] font-bold block mb-1">Código Identificador (Opcional):</label>
                <input
                  type="text"
                  value={newZoneCode}
                  onChange={(e) => setNewZoneCode(e.target.value)}
                  placeholder="ej. ZONA 08"
                  className="w-full bg-[#060b17] border border-[#1b2b4d] rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                />
              </div>

              <div>
                <label className="text-[#7088b3] font-bold block mb-1">Color del Marco:</label>
                <select
                  value={newZoneColor}
                  onChange={(e) => setNewZoneColor(e.target.value)}
                  className="w-full bg-[#060b17] border border-[#1b2b4d] rounded-xl px-3 py-2 text-white font-bold focus:outline-none"
                >
                  <option value="blue">Azul (SITE / Sistemas)</option>
                  <option value="emerald">Verde (WiFi / General)</option>
                  <option value="purple">Morado (IDF / Telecom)</option>
                  <option value="amber">Amarillo (Caja / Administración)</option>
                  <option value="rose">Rojo (CCTV / Alerta)</option>
                </select>
              </div>
            </div>

            <div className="flex justify-end space-x-3 pt-2">
              <button
                onClick={handleAddZone}
                className="w-full py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-xl text-xs transition-all shadow-md cursor-pointer"
              >
                Guardar Zona en {selectedFloor}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL CREAR NUEVO PIN DE EQUIPO */}
      {showAddPinModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#060b17]/85 backdrop-blur-md">
          <div className="bg-[#0c1529] border border-[#1b2b4d] rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div className="flex justify-between items-center border-b border-[#1b2b4d] pb-3">
              <h3 className="text-base font-black text-white">+ Colocar Nuevo Equipo en {selectedFloor}</h3>
              <button onClick={() => setShowAddPinModal(false)} className="text-slate-400 hover:text-white">
                <X size={18} />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div>
                <label className="text-[#7088b3] font-bold block mb-1">Nombre / Etiqueta del Equipo:</label>
                <input
                  type="text"
                  value={newPinLabel}
                  onChange={(e) => setNewPinLabel(e.target.value)}
                  placeholder="ej. AP Wi-Fi Mezzanine, Cámara Taller 02..."
                  className="w-full bg-[#060b17] border border-[#1b2b4d] rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div>
                <label className="text-[#7088b3] font-bold block mb-1">Tipo de Dispositivo / Icono:</label>
                <select
                  value={newPinType}
                  onChange={(e) => setNewPinType(e.target.value as any)}
                  className="w-full bg-[#060b17] border border-[#1b2b4d] rounded-xl px-3 py-2 text-white font-bold focus:outline-none"
                >
                  <option value="wifi">📶 Access Point Wi-Fi</option>
                  <option value="site">🖥️ SITE Principal</option>
                  <option value="idf">🎛️ IDF Secundario</option>
                  <option value="cctv">🎥 Cámara CCTV</option>
                  <option value="nodo">🔌 Nodo de Red PC</option>
                </select>
              </div>

              <div>
                <label className="text-[#7088b3] font-bold block mb-1">Dirección IP (Opcional):</label>
                <input
                  type="text"
                  value={newPinIp}
                  onChange={(e) => setNewPinIp(e.target.value)}
                  placeholder="ej. 192.168.26.210"
                  className="w-full bg-[#060b17] border border-[#1b2b4d] rounded-xl px-3 py-2 text-white font-mono focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>

            <div className="flex justify-end space-x-3 pt-2">
              <button
                onClick={handleAddPin}
                className="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition-all shadow-md cursor-pointer"
              >
                Colocar Equipo en {selectedFloor}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL DETALLE PIN */}
      {selectedPinDetail && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#060b17]/85 backdrop-blur-md">
          <div className="bg-[#0c1529] border border-[#1b2b4d] rounded-3xl max-w-sm w-full p-6 shadow-2xl relative space-y-4">
            <button onClick={() => setSelectedPinDetail(null)} className="absolute top-4 right-4 text-slate-400 hover:text-white">
              <X size={18} />
            </button>

            <div className="flex items-center space-x-3">
              <div className="w-10 h-10 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shadow-lg">
                <MapPin size={20} />
              </div>
              <div>
                <span className="text-[10px] font-bold text-[#486596] uppercase block">EQUIPO EN PLANO ({selectedFloor})</span>
                <h4 className="text-sm font-black text-white">{selectedPinDetail.label}</h4>
              </div>
            </div>

            <div className="bg-[#060b17] p-3 rounded-xl border border-[#1b2b4d] text-xs space-y-2 font-mono">
              <div className="flex justify-between">
                <span className="text-[#7088b3]">Tipo:</span>
                <span className="text-white font-bold uppercase">{selectedPinDetail.type}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-[#7088b3]">Dirección IP:</span>
                <span className="text-emerald-400 font-bold">{selectedPinDetail.ipAddress}</span>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
