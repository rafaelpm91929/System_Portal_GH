import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { Login } from './pages/Login';
import { Dashboard } from './pages/Dashboard';
import { InventoryDashboard } from './pages/InventoryDashboard';
import { RespaldosDashboard } from './pages/RespaldosDashboard';
import { InfraestructuraDashboard } from './pages/InfraestructuraDashboard';

const ProtectedRoute = ({ children }: { children: JSX.Element }) => {
  const token = localStorage.getItem('systems_portal_token');
  if (!token) {
    return <Navigate to="/login" replace />;
  }
  return children;
};

export const App: React.FC = () => {
  return (
    <Router>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route
          path="/dashboard"
          element={
            <ProtectedRoute>
              <Dashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/inventory"
          element={
            <ProtectedRoute>
              <InventoryDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/respaldos"
          element={
            <ProtectedRoute>
              <RespaldosDashboard />
            </ProtectedRoute>
          }
        />
        <Route
          path="/infraestructura"
          element={
            <ProtectedRoute>
              <InfraestructuraDashboard />
            </ProtectedRoute>
          }
        />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </Router>
  );
};

export default App;
