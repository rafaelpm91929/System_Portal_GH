import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import axios from 'axios';
import toast, { Toaster } from 'react-hot-toast';
import { Lock, User, ArrowRight } from 'lucide-react';

export const Login: React.FC = () => {
  const [username, setUsername] = useState('tilavilla');
  const [password, setPassword] = useState('1234');
  const [rememberMe, setRememberMe] = useState(false);
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  const getApiUrl = () => {
    const currentHost = window.location.hostname;
    if (currentHost === 'localhost' || currentHost === '127.0.0.1') {
      return 'http://localhost:4000/api';
    }
    return `http://${currentHost}:4000/api`;
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!username || !password) {
      toast.error('Por favor ingresa tu usuario y contraseña');
      return;
    }

    setLoading(true);
    const apiUrl = getApiUrl();

    try {
      const response = await axios.post(`${apiUrl}/auth/login`, {
        username: username.trim(),
        password: password.trim(),
      });

      const { token, user } = response.data;
      localStorage.setItem('systems_portal_token', token);
      localStorage.setItem('systems_portal_user', JSON.stringify(user));

      toast.success(`Bienvenido, ${user.name}`);
      setTimeout(() => {
        navigate('/dashboard');
      }, 300);
    } catch (error: any) {
      console.error('Error de login:', error);
      const errorMsg = error.response?.data?.error || 'No se pudo conectar con el servidor backend';
      toast.error(errorMsg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-[#060b17] text-slate-100 flex items-center justify-center p-6 relative overflow-hidden bg-grid-pattern font-sans">
      <Toaster position="top-right" />

      {/* Radial glow gradient */}
      <div 
        className="absolute inset-0 pointer-events-none"
        style={{
          background: 'radial-gradient(circle at 65% 50%, rgba(21, 84, 170, 0.12) 0%, rgba(6, 11, 23, 0) 70%)'
        }}
      ></div>

      <div className="max-w-5xl w-full grid grid-cols-1 md:grid-cols-2 gap-10 lg:gap-16 items-center z-10 my-auto">
        
        {/* Left Side: Brand Text and Agency Badges */}
        <div className="space-y-6 text-left">
          <div>
            <span className="text-[11px] font-bold text-[#486596] uppercase tracking-widest block mb-2">
              GRUPO HUERTA
            </span>
            <h1 className="text-4xl sm:text-[42px] font-black text-white tracking-tight leading-tight">
              Portal de Sistemas
            </h1>
            <p className="text-[#7088b3] text-xs sm:text-sm leading-relaxed max-w-sm mt-3 font-medium">
              Inventario, evidencia y estado de cumplimiento de todas las agencias y sucursales, en un solo lugar.
            </p>
          </div>

          {/* Agency Badges / Pills */}
          <div className="flex flex-wrap gap-2.5 pt-1">
            <span className="bg-[#0a1428] border border-[#1b2b4d] text-[#869ec7] text-xs px-3.5 py-1.5 rounded-full font-medium shadow-sm hover:border-[#283e6b] transition-all">
              VW Divol La Villa
            </span>
            <span className="bg-[#0a1428] border border-[#1b2b4d] text-[#869ec7] text-xs px-3.5 py-1.5 rounded-full font-medium shadow-sm hover:border-[#283e6b] transition-all">
              Seat La Villa
            </span>
            <span className="bg-[#0a1428] border border-[#1b2b4d] text-[#869ec7] text-xs px-3.5 py-1.5 rounded-full font-medium shadow-sm hover:border-[#283e6b] transition-all">
              Cupra Garage La Villa
            </span>
          </div>
        </div>

        {/* Right Side: Login White Card */}
        <div className="flex justify-center md:justify-end">
          <div className="bg-white rounded-[28px] p-8 sm:p-9 shadow-2xl shadow-blue-950/80 max-w-[400px] w-full text-slate-900 border border-slate-100">
            
            {/* Top Lock Blue Icon Box */}
            <div className="w-12 h-12 bg-[#1554aa] text-white rounded-2xl flex items-center justify-center mb-5 shadow-lg shadow-blue-600/30">
              <Lock size={22} />
            </div>

            <span className="text-[10px] font-extrabold text-slate-400 uppercase tracking-widest block mb-1">
              GRUPO HUERTA
            </span>
            <h2 className="text-2xl font-black text-slate-900 tracking-tight mb-1">
              Iniciar sesión
            </h2>
            <p className="text-xs text-slate-400 font-medium mb-6">
              Accede al portal de sistemas
            </p>

            <form onSubmit={handleLogin} className="space-y-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1.5">
                  Usuario
                </label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <User size={18} />
                  </div>
                  <input
                    type="text"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    placeholder="tilavilla"
                    className="w-full pl-10 pr-4 py-2.5 bg-[#f8fafc] border border-blue-400/80 rounded-xl text-slate-900 font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-600 transition-all shadow-sm"
                    required
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1.5">
                  Contraseña
                </label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <Lock size={18} />
                  </div>
                  <input
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full pl-10 pr-4 py-2.5 bg-[#f8fafc] border border-slate-200 rounded-xl text-slate-900 font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-600 transition-all shadow-sm"
                    required
                  />
                </div>
              </div>

              <div className="flex items-center justify-between pt-1 pb-2">
                <label className="flex items-center space-x-2 text-xs font-bold text-slate-600 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={rememberMe}
                    onChange={(e) => setRememberMe(e.target.checked)}
                    className="rounded border-slate-300 text-[#1554aa] focus:ring-[#1554aa]"
                  />
                  <span>Recordarme</span>
                </label>
                <a 
                  href="#forgot" 
                  onClick={(e) => { e.preventDefault(); toast('Contacta al administrador para restablecer acceso', { icon: 'ℹ️' }); }} 
                  className="text-xs font-extrabold text-[#1554aa] hover:underline"
                >
                  ¿Olvidaste tu contraseña?
                </a>
              </div>

              <button
                type="submit"
                disabled={loading}
                className="w-full py-3.5 px-4 bg-[#1554aa] hover:bg-[#11458c] text-white font-bold rounded-xl shadow-lg shadow-blue-700/30 transition-all flex items-center justify-center space-x-2 text-sm disabled:opacity-50 active:scale-[0.99] cursor-pointer"
              >
                {loading ? (
                  <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                ) : (
                  <>
                    <span>Iniciar sesión</span>
                    <ArrowRight size={18} />
                  </>
                )}
              </button>
            </form>

            <p className="text-[11px] text-slate-400 text-center font-medium mt-6">
              Acceso exclusivo para personal autorizado de Grupo Huerta
            </p>
          </div>
        </div>

      </div>
    </div>
  );
};
