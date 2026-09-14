import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import toast, { Toaster } from 'react-hot-toast';
import {
  Laptop,
  Trash2,
  Zap,
  Building,
  Smartphone,
  Tv,
  Video,
  Server,
  Plus,
  Search,
  ArrowLeft,
  FileSpreadsheet,
  Eye,
  Edit,
  X,
  Download,
  Boxes,
  Printer
} from 'lucide-react';

export interface EquipoItem {
  id: string;
  // Columnas Visibles en Tabla Principal
  nombreUsuario: string;
  puesto: string;
  departamento: string;
  laptopOPc: 'Laptop' | 'Desktop' | 'All in One';
  modelo: string;
  nombreMaquina: string;
  contrasena: string;

  // Todos los campos detallados para la Ficha Completa (Ojo 👁️)
  usuario: string;
  estado: string;
  desktop: string;
  laptop: string;
  expedienteCompleto: string;
  dominio: string;
  logmein: string;

  // Hardware
  serie: string;
  dd: string;
  procesador: string;
  ghz: string;
  ram: string;
  direccionMac: string;
  macWifi: string;
  macEthernet: string;
  sistemaOp: string;

  // Garantías y Compras
  fechaCompra: string;
  folioFactura: string;
  inicioGarantia: string;
  finGarantia: string;
  garantia2: string;
  renovacionEquipo: string;
  compra: string;
  proveedor: string;

  // Credenciales & Software
  usuarioEquipoDominio: string;
  office: string;
  serieOffice: string;
  claveCandado: string;

  // GDS & Remotos
  gds: string;
  porUsuario: string;
  equipoGds: string;
  remoto: string;
  usuarioGds: string;

  // Correos & Tel
  correo: string;
  correoOficialVW: string;
  correoOficialSEAT: string;
  extension: string;

  // Plataformas & Contraseñas
  powerPB: string;
  contrasenaPB: string;
  poc: string;
  contrasenaPOC: string;
  msqp: string;
  contrasenaMSQP: string;
  grp: string;
  contrasenaGRP: string;
  etka: string;
  contrasenaETKA: string;
  facebook: string;
  contrasenaFacebook: string;
  instagram: string;
  contrasenaInstagram: string;
  wish: string;
  contrasenaWish: string;
  marketingCloud: string;
  contrasenaMarketingCloud: string;
  salesCloudForce: string;
  contrasenaSalesCloudForce: string;
  paginaLaVilla: string;
  contrasenaPaginaLaVilla: string;
  urbanScience: string;
  contrasenaUrbanScience: string;
  canva: string;
  contrasenaCanva: string;
  cuentaIntegral: string;
  contrasenaCuentaIntegral: string;

  // Seguridad & Respaldos & No-Break
  antivirus: string;
  diaRespaldo: string;
  horaRespaldo: string;
  noBreak: string;
  modeloNoBreak: string;
  serieNoBreak: string;
  columna1: string;
  columna2: string;

  // Sucursal
  agency: string;
}

export const InventoryDashboard: React.FC = () => {
  const navigate = useNavigate();
  const [activeMenu, setActiveMenu] = useState<string>('equipos');
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedAgency, setSelectedAgency] = useState<string>('todas');

  // Modales
  const [selectedDevice, setSelectedDevice] = useState<EquipoItem | null>(null);
  const [selectedResponsiva, setSelectedResponsiva] = useState<EquipoItem | null>(null);
  const [modalTab, setModalTab] = useState<'general' | 'hardware' | 'garantia' | 'credenciales' | 'plataformas' | 'respaldos'>('general');

  // Menú Lateral
  const sidebarItems = [
    { id: 'equipos', label: 'Equipos (Laptops, PCs & AIO)', icon: <Laptop size={18} />, count: 3 },
    { id: 'equipos-baja', label: 'Equipos Baja', icon: <Trash2 size={18} />, count: 1 },
    { id: 'nobreak-baja', label: 'Nobreak Baja', icon: <Zap size={18} />, count: 1 },
    { id: 'equipos-corporativo', label: 'Equipos Corporativo', icon: <Building size={18} />, count: 1 },
    { id: 'celulares-tablets', label: 'Celulares Tablets Dispositivos', icon: <Smartphone size={18} />, count: 2 },
    { id: 'monitores', label: 'Monitores', icon: <Tv size={18} />, count: 1 },
    { id: 'dvr-nvr', label: 'DVR / NVR', icon: <Video size={18} />, count: 1 },
    { id: 'site', label: 'SITE', icon: <Server size={18} />, count: 1 },
  ];

  // Datos de Prueba
  const [equiposList] = useState<EquipoItem[]>([
    {
      id: 'EQ-001',
      nombreUsuario: 'Carlos Mendoza',
      puesto: 'Gerente de Ventas',
      departamento: 'Ventas',
      laptopOPc: 'Laptop',
      modelo: 'Latitude 5430',
      nombreMaquina: 'DESKTOP-VLV-VEN01',
      contrasena: 'Ventas2026*',

      usuario: 'cmendoza',
      estado: 'Activo',
      desktop: 'No',
      laptop: 'Sí',
      expedienteCompleto: 'Sí',
      dominio: 'grupohuerta.mx',
      logmein: 'cmendoza@grupohuerta.mx',

      serie: 'CN-08F79D-70163',
      dd: '512 GB NVMe SSD',
      procesador: 'Intel Core i7-1265U',
      ghz: '2.70 GHz',
      ram: '16 GB DDR4',
      direccionMac: 'A4:BB:6D:90:12:FE',
      macWifi: 'A4:BB:6D:90:12:FF',
      macEthernet: 'A4:BB:6D:90:12:FE',
      sistemaOp: 'Windows 11 Pro 64-bit',

      fechaCompra: '2025-03-15',
      folioFactura: 'F-88912',
      inicioGarantia: '2025-03-15',
      finGarantia: '2028-03-15',
      garantia2: 'Vigente Dell ProSupport',
      renovacionEquipo: '2028-03-15',
      compra: 'Directa',
      proveedor: 'Dell México',

      usuarioEquipoDominio: 'grupohuerta\\cmendoza',
      office: 'Office 365 Business',
      serieOffice: 'O365-LIC-9912',
      claveCandado: 'CND-4401',

      gds: 'Sí',
      porUsuario: 'Sí',
      equipoGds: 'VLV-GDS-01',
      remoto: 'Habilitado',
      usuarioGds: 'GDS_CMENDOZA',

      correo: 'cmendoza@grupohuerta.mx',
      correoOficialVW: 'carlos.mendoza@divollavilla.vw.mx',
      correoOficialSEAT: 'N/A',
      extension: '1042',

      powerPB: 'PowerPB_Usr',
      contrasenaPB: 'PB#2026',
      poc: 'POC_Admin',
      contrasenaPOC: 'POC#2026',
      msqp: 'MSQP_Usr',
      contrasenaMSQP: 'MSQP#2026',
      grp: 'GRP_Usr',
      contrasenaGRP: 'GRP#2026',
      etka: 'ETKA_Usr',
      contrasenaETKA: 'ETKA#2026',
      facebook: 'VW Divol La Villa Oficial',
      contrasenaFacebook: 'FB#Divol2026',
      instagram: '@vwdivollavilla',
      contrasenaInstagram: 'IG#Divol2026',
      wish: 'N/A',
      contrasenaWish: 'N/A',
      marketingCloud: 'MC_VLV',
      contrasenaMarketingCloud: 'MC#2026',
      salesCloudForce: 'SF_CMendoza',
      contrasenaSalesCloudForce: 'SF#2026',
      paginaLaVilla: 'Admin_LaVilla',
      contrasenaPaginaLaVilla: 'Web#2026',
      urbanScience: 'US_VLV',
      contrasenaUrbanScience: 'US#2026',
      canva: 'cmendoza@grupohuerta.mx',
      contrasenaCanva: 'Canva#2026',
      cuentaIntegral: 'Integral_CM',
      contrasenaCuentaIntegral: 'CI#2026',

      antivirus: 'Cylance Protect',
      diaRespaldo: 'Viernes',
      horaRespaldo: '18:00 hrs',
      noBreak: 'Sí',
      modeloNoBreak: 'Koblenz 5216 R',
      serieNoBreak: 'KOB-99120',
      columna1: 'Revisado OK',
      columna2: 'Sin observaciones',

      agency: 'VW Divol La Villa',
    },
    {
      id: 'EQ-002',
      nombreUsuario: 'Ana Paola Gómez',
      puesto: 'Cajera Principal',
      departamento: 'Caja & Administración',
      laptopOPc: 'Desktop',
      modelo: 'HP ProDesk 400 G6',
      nombreMaquina: 'DESKTOP-VLV-CAJ02',
      contrasena: 'Caja2026$',

      usuario: 'agomez',
      estado: 'Activo',
      desktop: 'Sí',
      laptop: 'No',
      expedienteCompleto: 'Sí',
      dominio: 'grupohuerta.mx',
      logmein: 'agomez@grupohuerta.mx',

      serie: 'MXL129482B',
      dd: '1 TB SATA HDD',
      procesador: 'Intel Core i5-10500',
      ghz: '3.10 GHz',
      ram: '8 GB DDR4',
      direccionMac: 'C8:D3:FF:11:88:AA',
      macWifi: 'N/A',
      macEthernet: 'C8:D3:FF:11:88:AA',
      sistemaOp: 'Windows 10 Pro 64-bit',

      fechaCompra: '2024-06-10',
      folioFactura: 'F-74100',
      inicioGarantia: '2024-06-10',
      finGarantia: '2027-06-10',
      garantia2: 'Vigente HP Care Pack',
      renovacionEquipo: '2027-06-10',
      compra: 'Directa',
      proveedor: 'HP Inc. México',

      usuarioEquipoDominio: 'grupohuerta\\agomez',
      office: 'Office 2021 Home & Business',
      serieOffice: 'H&B-2021-X992',
      claveCandado: 'CND-8821',

      gds: 'No',
      porUsuario: 'No',
      equipoGds: 'N/A',
      remoto: 'Habilitado',
      usuarioGds: 'N/A',

      correo: 'agomez@grupohuerta.mx',
      correoOficialVW: 'ana.gomez@divollavilla.vw.mx',
      correoOficialSEAT: 'N/A',
      extension: '1015',

      powerPB: 'PowerPB_Caja',
      contrasenaPB: 'PB#Caja2026',
      poc: 'N/A',
      contrasenaPOC: 'N/A',
      msqp: 'N/A',
      contrasenaMSQP: 'N/A',
      grp: 'N/A',
      contrasenaGRP: 'N/A',
      etka: 'N/A',
      contrasenaETKA: 'N/A',
      facebook: 'N/A',
      contrasenaFacebook: 'N/A',
      instagram: 'N/A',
      contrasenaInstagram: 'N/A',
      wish: 'N/A',
      contrasenaWish: 'N/A',
      marketingCloud: 'N/A',
      contrasenaMarketingCloud: 'N/A',
      salesCloudForce: 'N/A',
      contrasenaSalesCloudForce: 'N/A',
      paginaLaVilla: 'N/A',
      contrasenaPaginaLaVilla: 'N/A',
      urbanScience: 'N/A',
      contrasenaUrbanScience: 'N/A',
      canva: 'N/A',
      contrasenaCanva: 'N/A',
      cuentaIntegral: 'Integral_Caja',
      contrasenaCuentaIntegral: 'CI#Caja2026',

      antivirus: 'Cylance Protect',
      diaRespaldo: 'Jueves',
      horaRespaldo: '17:00 hrs',
      noBreak: 'Sí',
      modeloNoBreak: 'TrippLite 750VA',
      serieNoBreak: 'TL-88219',
      columna1: 'Revisado OK',
      columna2: 'Sin observaciones',

      agency: 'Seat La Villa',
    },
    {
      id: 'EQ-003',
      nombreUsuario: 'Oswaldo Pineda',
      puesto: 'Gerente de Servicio',
      departamento: 'GERENCIA / DIRECCIÓN',
      laptopOPc: 'Laptop',
      modelo: 'ThinkPad T14 Gen 3',
      nombreMaquina: 'MX38080VC0036',
      contrasena: 'Servicio2026#',

      usuario: 'opineda',
      estado: 'Activo',
      desktop: 'No',
      laptop: 'Sí',
      expedienteCompleto: 'Sí',
      dominio: 'grupohuerta.mx',
      logmein: 'opineda@grupohuerta.mx',

      serie: 'PF-49102X',
      dd: '512 GB SSD',
      procesador: 'Intel Core i7-1260P',
      ghz: '2.80 GHz',
      ram: '16 GB DDR4',
      direccionMac: 'B2:C4:E6:88:99:00',
      macWifi: 'B2:C4:E6:88:99:01',
      macEthernet: 'B2:C4:E6:88:99:00',
      sistemaOp: 'Windows 11 Pro',

      fechaCompra: '2025-01-20',
      folioFactura: 'F-99012',
      inicioGarantia: '2025-01-20',
      finGarantia: '2028-01-20',
      garantia2: 'Vigente Lenovo Onsite',
      renovacionEquipo: '2028-01-20',
      compra: 'Directa',
      proveedor: 'Lenovo México',

      usuarioEquipoDominio: 'grupohuerta\\opineda',
      office: 'Office 365 ProPlus',
      serieOffice: 'O365-SERV-0021',
      claveCandado: 'CND-1290',

      gds: 'Sí',
      porUsuario: 'Sí',
      equipoGds: 'MX38080VC0036',
      remoto: 'Habilitado',
      usuarioGds: 'GDS_OPINEDA',

      correo: 'opineda@grupohuerta.mx',
      correoOficialVW: 'oswaldo.pineda@divollavilla.vw.mx',
      correoOficialSEAT: 'N/A',
      extension: '1090',

      powerPB: 'PowerPB_Serv',
      contrasenaPB: 'PB#Serv2026',
      poc: 'POC_Serv',
      contrasenaPOC: 'POC#Serv2026',
      msqp: 'MSQP_Serv',
      contrasenaMSQP: 'MSQP#Serv2026',
      grp: 'GRP_Serv',
      contrasenaGRP: 'GRP#Serv2026',
      etka: 'ETKA_Serv',
      contrasenaETKA: 'ETKA#Serv2026',
      facebook: 'N/A',
      contrasenaFacebook: 'N/A',
      instagram: 'N/A',
      contrasenaInstagram: 'N/A',
      wish: 'N/A',
      contrasenaWish: 'N/A',
      marketingCloud: 'N/A',
      contrasenaMarketingCloud: 'N/A',
      salesCloudForce: 'N/A',
      contrasenaSalesCloudForce: 'N/A',
      paginaLaVilla: 'N/A',
      contrasenaPaginaLaVilla: 'N/A',
      urbanScience: 'N/A',
      contrasenaUrbanScience: 'N/A',
      canva: 'N/A',
      contrasenaCanva: 'N/A',
      cuentaIntegral: 'Integral_Serv',
      contrasenaCuentaIntegral: 'CI#Serv2026',

      antivirus: 'Cylance Protect',
      diaRespaldo: 'Viernes',
      horaRespaldo: '18:00 hrs',
      noBreak: 'Sí',
      modeloNoBreak: 'Koblenz 7521',
      serieNoBreak: 'KOB-88120',
      columna1: 'Revisado OK',
      columna2: 'Sin observaciones',

      agency: 'VW Divol La Villa',
    },
  ]);

  const filteredEquipos = equiposList.filter((eq) => {
    const isAgencyMatch = selectedAgency === 'todas' || eq.agency === selectedAgency;
    const isSearchMatch =
      eq.nombreUsuario.toLowerCase().includes(searchTerm.toLowerCase()) ||
      eq.puesto.toLowerCase().includes(searchTerm.toLowerCase()) ||
      eq.departamento.toLowerCase().includes(searchTerm.toLowerCase()) ||
      eq.nombreMaquina.toLowerCase().includes(searchTerm.toLowerCase()) ||
      eq.modelo.toLowerCase().includes(searchTerm.toLowerCase()) ||
      eq.serie.toLowerCase().includes(searchTerm.toLowerCase());

    return isAgencyMatch && isSearchMatch;
  });

  // Función para imprimir la Carta Responsiva (Dispara vista de impresión PDF)
  const printResponsivaDoc = (eq: EquipoItem) => {
    const printWindow = window.open('', '_blank', 'width=900,height=1000');
    if (!printWindow) {
      toast.error('Por favor permite ventanas emergentes para imprimir la responsiva');
      return;
    }

    const currentDateFormatted = '03 de agosto de 2026';

    const printHTML = `
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <title>Carta Responsiva - ${eq.nombreUsuario}</title>
        <style>
          @page {
            size: letter portrait;
            margin: 15mm;
          }
          body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #111827;
            line-height: 1.4;
            margin: 0;
            padding: 0;
            background: #fff;
          }
          .page-container {
            width: 100%;
            height: 100%;
            page-break-after: always;
            position: relative;
            box-sizing: border-box;
            padding-bottom: 40px;
          }
          .page-container:last-child {
            page-break-after: avoid;
          }
          .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
          }
          .brand-logo {
            font-weight: 900;
            font-size: 14px;
            color: #002d72;
            text-transform: uppercase;
            letter-spacing: -0.5px;
          }
          .brand-sub {
            font-size: 9px;
            color: #4b5563;
          }
          .vw-circle-logo {
            width: 38px;
            height: 38px;
            border: 2px solid #002d72;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 16px;
            color: #002d72;
            margin-left: auto;
          }
          .company-title {
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            color: #002d72;
            margin-bottom: 6px;
          }
          .banner-blue {
            background-color: #002d72;
            color: #ffffff;
            text-align: center;
            font-size: 12px;
            font-weight: 800;
            padding: 6px 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
          }
          .date-right {
            text-align: right;
            font-style: italic;
            color: #002d72;
            font-size: 11px;
            margin-bottom: 15px;
          }
          ol.policy-list {
            padding-left: 20px;
            margin: 0 0 25px 0;
          }
          ol.policy-list li {
            margin-bottom: 12px;
            text-align: justify;
          }
          .underline-bold {
            text-decoration: underline;
            font-weight: bold;
          }
          .signatures-row {
            margin-top: 40px;
            width: 100%;
            display: table;
          }
          .signature-col {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: bottom;
          }
          .sig-line {
            width: 75%;
            margin: 0 auto 5px auto;
            border-top: 1px solid #4b5563;
          }
          .sig-label {
            font-size: 10px;
            color: #374151;
            font-weight: 600;
          }
          .footer-sticky {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            border-top: 1px solid #d1d5db;
            padding-top: 5px;
            font-size: 9px;
            color: #6b7280;
            display: flex;
            justify-content: space-between;
          }
          /* TABLAS DE RESPONSIVA (PÁGINA 2) */
          .data-table-section {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
          }
          .data-table-section th {
            background-color: #002d72;
            color: #ffffff;
            text-transform: uppercase;
            font-size: 10px;
            font-weight: 800;
            padding: 5px 8px;
            text-align: center;
            border: 1px solid #002d72;
          }
          .data-table-section td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: center;
            width: 25%;
            vertical-align: top;
          }
          .field-label {
            font-size: 9px;
            font-weight: 700;
            color: #002d72;
            display: block;
            margin-bottom: 2px;
          }
          .field-val {
            font-size: 10.5px;
            color: #111827;
            font-weight: 600;
          }
        </style>
      </head>
      <body>

        <!-- PÁGINA 1: POLÍTICA SOBRE EL USO DE PROGRAMAS -->
        <div class="page-container">
          <table class="header-table">
            <tr>
              <td style="width: 50%;">
                <div class="brand-logo">DIVOL LA VILLA</div>
                <div class="brand-sub">S.A.P.I. DE C.V.</div>
              </td>
              <td style="width: 50%; text-align: right;">
                <div class="vw-circle-logo">W</div>
              </td>
            </tr>
          </table>

          <div class="company-title">DIVOL LA VILLA S.A.P.I. DE C.V.</div>
          <div class="banner-blue">POLÍTICA SOBRE EL USO DE PROGRAMAS DE COMPUTADORAS</div>
          <div class="date-right">Ciudad de México, ${currentDateFormatted}</div>

          <ol class="policy-list">
            <li>Este equipo es propiedad de Divol La Villa S.A.P.I. de C.V. con las siguientes características y licencias que se enlistan en el presente documento.</li>
            <li>Según las leyes de derechos de autor, las personas implicadas en la reproducción ilegal de software pueden ser demandadas por daños y perjuicios y enfrentar penas criminales, incluyendo multas y prisión. Divol La Villa S.A.P.I. de C.V. no permite la duplicación ilegal de software. Los empleados de Divol La Villa S.A.P.I. de C.V. que realicen, adquieran o usen copias no autorizadas de programas de computadoras serán recriminados según las circunstancias. Esta conducta puede ser motivo de rescisión de contrato laboral.</li>
            <li>Antes de proceder, cualquier duda sobre si un empleado puede copiar, instalar o usar un programa de computadora deberá elevarse al Administrador de Sistemas asignado. En caso contrario la responsabilidad recae directamente sobre el empleado designado a dicho equipo de cómputo.</li>
            <li>Todas las contraseñas otorgadas son confidenciales, personales e intransferibles y es absoluta responsabilidad del empleado el buen o mal uso que se les dé, así mismo el equipo telefónico y de cómputo para sus tareas.</li>
            <li>Deberá mantener en buenas condiciones el equipo de trabajo que se le asigne, ya que cualquier desperfecto por mal uso será su responsabilidad; por ello es importante reportar inmediatamente cualquier mal funcionamiento para que se otorgue el mantenimiento correspondiente. Quien recibe dicho equipo se hace responsable del uso del mismo, así como de los daños que este pudiese sufrir por mal uso.</li>
            <li>Es de mi conocimiento que la música y las fotos también tienen derechos de autor, por lo tanto no debo tener música ni fotos en mi equipo; en caso de tenerlas, deberé demostrar que cuento con las licencias correspondientes para su uso, deslindando a Divol La Villa S.A.P.I. de C.V. de este mal uso.</li>
            <li>Se me explica la manera de bloquear mi equipo, lo cual debo hacer cada vez que salga de mi lugar, esto por cuestiones de seguridad y para que nadie pueda acceder a mi equipo y a la información contenida en el mismo.</li>
            <li class="underline-bold">Es de mi conocimiento dejar prendido el equipo los días que me indicaron para la generación de mi respaldo; en caso de no dejarlo prendido, es mi responsabilidad avisar para que se realice mi respaldo periódico, por lo que Sistemas no es responsable si no se hizo el respaldo en su día.</li>
            <li>El personal de Sistemas tiene todas las facultades para hacer revisiones periódicas de mi equipo, con la finalidad de mantener en buen funcionamiento el mismo.</li>
          </ol>

          <div class="signatures-row" style="margin-top: 50px;">
            <div class="signature-col">
              <div class="sig-line"></div>
              <div class="sig-label">Nombre y firma del empleado</div>
            </div>
            <div class="signature-col">
              <div class="sig-line"></div>
              <div class="sig-label">Sistemas</div>
            </div>
          </div>

          <div class="footer-sticky">
            <div>Gerencia de Sistemas · Divol La Villa S.A.P.I. de C.V.</div>
            <div>Página 1 de 3</div>
          </div>
        </div>

        <!-- PÁGINA 2: RESPONSIVA DE EQUIPO -->
        <div class="page-container">
          <table class="header-table">
            <tr>
              <td style="width: 50%;">
                <div class="brand-logo">DIVOL LA VILLA</div>
                <div class="brand-sub">S.A.P.I. DE C.V.</div>
              </td>
              <td style="width: 50%; text-align: right;">
                <div class="vw-circle-logo">W</div>
              </td>
            </tr>
          </table>

          <div class="banner-blue">RESPONSIVA DE EQUIPO</div>
          <div class="date-right">Ciudad de México, ${currentDateFormatted}</div>

          <!-- TABLA DATOS PERSONALES -->
          <table class="data-table-section">
            <thead>
              <tr><th colSpan="4">DATOS PERSONALES</th></tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <span class="field-label">Nombre</span>
                  <span class="field-val">${eq.nombreUsuario}</span>
                </td>
                <td>
                  <span class="field-label">Departamento</span>
                  <span class="field-val">${eq.departamento}</span>
                </td>
                <td>
                  <span class="field-label">Puesto</span>
                  <span class="field-val">${eq.puesto}</span>
                </td>
                <td>
                  <span class="field-label">Extensión</span>
                  <span class="field-val">${eq.extension}</span>
                </td>
              </tr>
              <tr>
                <td colSpan="1">
                  <span class="field-label">Correo</span>
                  <span class="field-val">${eq.correo}</span>
                </td>
                <td colSpan="1">
                  <span class="field-label">Correo oficial VW</span>
                  <span class="field-val">${eq.correoOficialVW}</span>
                </td>
                <td colSpan="2">
                  <span class="field-label">Correo oficial SEAT</span>
                  <span class="field-val">${eq.correoOficialSEAT}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- TABLA DATOS DEL EQUIPO -->
          <table class="data-table-section">
            <thead>
              <tr><th colSpan="4">DATOS DEL EQUIPO</th></tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <span class="field-label">Nombre de equipo</span>
                  <span class="field-val">${eq.nombreMaquina}</span>
                </td>
                <td>
                  <span class="field-label">Modelo</span>
                  <span class="field-val">${eq.modelo}</span>
                </td>
                <td>
                  <span class="field-label">Tipo</span>
                  <span class="field-val">${eq.laptopOPc}</span>
                </td>
                <td>
                  <span class="field-label">RAM</span>
                  <span class="field-val">${eq.ram}</span>
                </td>
              </tr>
              <tr>
                <td>
                  <span class="field-label">Número de serie</span>
                  <span class="field-val">${eq.serie}</span>
                </td>
                <td>
                  <span class="field-label">Procesador</span>
                  <span class="field-val">${eq.procesador}</span>
                </td>
                <td>
                  <span class="field-label">GHz</span>
                  <span class="field-val">${eq.ghz}</span>
                </td>
                <td>
                  <span class="field-label">No-Break</span>
                  <span class="field-val">${eq.noBreak}</span>
                </td>
              </tr>
              <tr>
                <td>
                  <span class="field-label">Usuario</span>
                  <span class="field-val">${eq.usuarioEquipoDominio}</span>
                </td>
                <td>
                  <span class="field-label">Contraseña</span>
                  <span class="field-val">${eq.contrasena}</span>
                </td>
                <td>
                  <span class="field-label">Día de respaldo</span>
                  <span class="field-val">${eq.diaRespaldo}</span>
                </td>
                <td>
                  <span class="field-label">Clave candado</span>
                  <span class="field-val">${eq.claveCandado}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- TABLA DATOS DE FACTURACIÓN -->
          <table class="data-table-section">
            <thead>
              <tr><th colSpan="4">DATOS DE FACTURACIÓN</th></tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <span class="field-label">Proveedor</span>
                  <span class="field-val">${eq.proveedor}</span>
                </td>
                <td>
                  <span class="field-label">Fecha de factura</span>
                  <span class="field-val">${eq.fechaCompra}</span>
                </td>
                <td>
                  <span class="field-label">Factura de equipo</span>
                  <span class="field-val">${eq.folioFactura}</span>
                </td>
                <td>
                  <span class="field-label">Tipo de compra</span>
                  <span class="field-val">${eq.compra}</span>
                </td>
              </tr>
              <tr>
                <td colSpan="4">
                  <span class="field-label">Garantía</span>
                  <span class="field-val">${eq.finGarantia} (${eq.garantia2})</span>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- TABLA DATOS DE PROGRAMAS -->
          <table class="data-table-section">
            <thead>
              <tr><th colSpan="4">DATOS DE PROGRAMAS</th></tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <span class="field-label">Windows</span>
                  <span class="field-val">${eq.sistemaOp}</span>
                </td>
                <td>
                  <span class="field-label">Office</span>
                  <span class="field-val">${eq.office}</span>
                </td>
                <td>
                  <span class="field-label">LogMeIn</span>
                  <span class="field-val">Sí</span>
                </td>
                <td>
                  <span class="field-label">Antivirus</span>
                  <span class="field-val">${eq.antivirus}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <div class="signatures-row" style="margin-top: 40px;">
            <div class="signature-col">
              <div class="sig-line"></div>
              <div class="sig-label">Nombre y firma · Usuario</div>
            </div>
            <div class="signature-col">
              <div class="sig-line"></div>
              <div class="sig-label">Nombre y firma · Jefe inmediato</div>
            </div>
          </div>

          <div class="footer-sticky">
            <div>Gerencia de Sistemas · Divol La Villa S.A.P.I. de C.V.</div>
            <div>Página 2 de 3</div>
          </div>
        </div>

        <!-- PÁGINA 3: CONTRATO DE CONFIDENCIALIDAD -->
        <div class="page-container">
          <table class="header-table">
            <tr>
              <td style="width: 50%;">
                <div class="brand-logo">DIVOL LA VILLA</div>
                <div class="brand-sub">S.A.P.I. DE C.V.</div>
              </td>
              <td style="width: 50%; text-align: right;">
                <div class="vw-circle-logo">W</div>
              </td>
            </tr>
          </table>

          <div class="company-title">DIVOL LA VILLA S.A.P.I. DE C.V.</div>
          <div class="banner-blue">CONTRATO DE CONFIDENCIALIDAD DE INFORMACIÓN</div>
          <div class="date-right">Ciudad de México, ${currentDateFormatted}</div>

          <p style="text-align: justify; margin-bottom: 15px;">
            En mi capacidad de empleado (ya sea fijo o temporal) y en consideración de la relación laboral que mantengo con la organización Divol La Villa, así como del acceso que se me permite a sus bases de información, constato que:
          </p>

          <ol class="policy-list">
            <li>
              Soy consciente de la importancia de mis responsabilidades en cuanto a no poner en peligro la integridad, disponibilidad y confidencialidad de la información que maneja la empresa. En concreto, he leído, entiendo y me comprometo a cumplir los Procedimientos de Seguridad de los Sistemas de Información que corresponden a mi función en la empresa.
            </li>
            <li>
              Me comprometo a cumplir, así mismo, todas las disposiciones relativas a la política de la empresa en materia de uso y divulgación de información, y a no divulgar la información que reciba a lo largo de mi relación con la empresa, subsistiendo este deber de secreto aun después de que finalice dicha relación, tanto si esta información es de su propiedad como si pertenece a un cliente de la misma o a alguna otra sociedad que nos proporcione acceso a dicha información, cualquiera que sea la forma de acceso a tales datos y el soporte en el que consten, quedando absolutamente prohibido obtener copias sin previa autorización.
            </li>
            <li>
              Entiendo que el incumplimiento de cualesquiera de las obligaciones que constan en el presente documento, intencionadamente o por negligencia, podría implicar, en su caso, las sanciones disciplinarias correspondientes por parte de la empresa y la posible reclamación por parte de la misma de los daños económicos causados.
            </li>
          </ol>

          <div class="signatures-row" style="margin-top: 100px;">
            <div class="signature-col" style="width: 100%; display: block; text-align: center;">
              <div class="sig-line" style="width: 50%;"></div>
              <div class="sig-label" style="font-size: 11px; font-weight: 800;">${eq.nombreUsuario}</div>
              <div class="sig-label" style="font-size: 10px; color: #4b5563;">${eq.puesto}</div>
            </div>
          </div>

          <div class="footer-sticky">
            <div>Gerencia de Sistemas · Divol La Villa S.A.P.I. de C.V.</div>
            <div>Página 3 de 3</div>
          </div>
        </div>

        <script>
          window.onload = function() {
            setTimeout(function() {
              window.print();
            }, 500);
          }
        </script>
      </body>
      </html>
    `;

    printWindow.document.write(printHTML);
    printWindow.document.close();
  };

  return (
    <div className="min-h-screen bg-[#060b17] text-slate-100 flex font-sans relative overflow-hidden">
      <Toaster position="top-right" />

      {/* Grid Background Pattern */}
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
              <div className="w-10 h-10 bg-[#1554aa] text-white rounded-2xl flex items-center justify-center shadow-lg shadow-blue-900/30">
                <Boxes size={22} />
              </div>
              <div>
                <span className="text-[10px] font-bold text-[#486596] uppercase tracking-widest block">GRUPO HUERTA</span>
                <h2 className="text-base font-black text-white tracking-tight">INVENTARIOS</h2>
              </div>
            </div>
          </div>

          <nav className="space-y-1">
            <div className="text-[10px] font-extrabold text-[#7088b3] uppercase tracking-wider px-3 mb-2">
              Categorías de Inventario
            </div>
            {sidebarItems.map((item) => {
              const isActive = activeMenu === item.id;
              return (
                <button
                  key={item.id}
                  onClick={() => {
                    setActiveMenu(item.id);
                    setSearchTerm('');
                  }}
                  className={`w-full flex items-center justify-between px-3.5 py-3 rounded-xl text-xs font-bold transition-all duration-200 cursor-pointer ${
                    isActive
                      ? 'bg-[#1554aa] text-white shadow-lg shadow-blue-900/40 border border-blue-400/40'
                      : 'text-[#8ea5cc] hover:text-white hover:bg-[#121c35]/80'
                  }`}
                >
                  <div className="flex items-center space-x-3">
                    <span className={isActive ? 'text-white' : 'text-[#7088b3]'}>{item.icon}</span>
                    <span className="text-left">{item.label}</span>
                  </div>
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-mono border bg-blue-500/20 text-blue-400 border-blue-500/30">
                    {item.count}
                  </span>
                </button>
              );
            })}
          </nav>
        </div>

        <div className="p-4 border-t border-[#1b2b4d] bg-[#060b17]/60 text-[11px] text-[#7088b3] flex justify-between items-center">
          <span>Servidor BD: <code className="text-blue-400 font-mono">192.168.26.97</code></span>
        </div>
      </aside>

      {/* CONTENIDO PRINCIPAL */}
      <main className="flex-1 p-6 sm:p-8 space-y-6 overflow-y-auto z-10">
        
        {/* Header */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[#1b2b4d] pb-6">
          <div>
            <div className="flex items-center space-x-2 text-xs text-[#7088b3] font-medium mb-1">
              <span>Inventarios</span>
              <span>/</span>
              <span className="text-blue-400 font-bold">Equipos (Laptops & PCs)</span>
            </div>
            <h1 className="text-2xl sm:text-3xl font-black text-white tracking-tight">
              Control de Equipos de Cómputo
            </h1>
            <p className="text-xs text-[#7088b3] mt-1 font-medium">
              Listado principal con ficha técnica, credenciales e impresión de Carta Responsiva
            </p>
          </div>

          <div className="flex items-center space-x-3">
            <button
              onClick={() => toast.success('Exportando catálogo completo a Excel...')}
              className="flex items-center space-x-2 bg-[#0c1529] hover:bg-[#121c35] text-slate-200 border border-[#1b2b4d] px-4 py-2.5 rounded-xl text-xs font-bold transition-all shadow-sm cursor-pointer"
            >
              <FileSpreadsheet size={16} className="text-emerald-400" />
              <span>Exportar Excel</span>
            </button>

            <button
              onClick={() => toast('Formulario para nuevo registro de equipo', { icon: '➕' })}
              className="flex items-center space-x-2 bg-[#1554aa] hover:bg-blue-600 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition-all shadow-lg shadow-blue-600/30 cursor-pointer"
            >
              <Plus size={16} />
              <span>+ Nuevo Equipo</span>
            </button>
          </div>
        </div>

        {/* Buscador & Filtro de Sucursal */}
        <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl p-4 sm:p-5 shadow-xl flex flex-col md:flex-row justify-between items-center gap-4">
          <div className="relative w-full md:w-96">
            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[#7088b3]">
              <Search size={18} />
            </div>
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Buscar por usuario, puesto, maquina, serie o modelo..."
              className="w-full pl-10 pr-4 py-2.5 bg-[#060b17] border border-[#1b2b4d] rounded-xl text-white placeholder-[#7088b3] focus:outline-none focus:border-[#1554aa] text-xs font-medium transition-all"
            />
          </div>

          <div className="flex items-center space-x-3 w-full md:w-auto">
            <span className="text-xs text-[#7088b3] font-bold uppercase">Sucursal:</span>
            <select
              value={selectedAgency}
              onChange={(e) => setSelectedAgency(e.target.value)}
              className="bg-[#060b17] border border-[#1b2b4d] text-slate-200 text-xs px-3.5 py-2.5 rounded-xl focus:outline-none focus:border-[#1554aa] font-medium"
            >
              <option value="todas">Todas las Sucursales</option>
              <option value="VW Divol La Villa">VW Divol La Villa</option>
              <option value="Seat La Villa">Seat La Villa</option>
              <option value="Cupra Garage La Villa">Cupra Garage La Villa</option>
            </select>
          </div>
        </div>

        {/* TABLA PRINCIPAL DE EQUIPOS CON ACCIÓN DE IMPRESIÓN DE RESPONSIVA */}
        <div className="bg-[#0c1529]/95 border border-[#1b2b4d] rounded-2xl shadow-xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-[#060b17]/90 text-[#7088b3] uppercase font-bold border-b border-[#1b2b4d]">
                <tr>
                  <th className="px-5 py-4">Nombre Usuario</th>
                  <th className="px-5 py-4">Puesto</th>
                  <th className="px-5 py-4">Departamento</th>
                  <th className="px-5 py-4">Laptop o PC</th>
                  <th className="px-5 py-4">Modelo</th>
                  <th className="px-5 py-4">Nombre de la Máquina</th>
                  <th className="px-5 py-4">Contraseña</th>
                  <th className="px-5 py-4 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#1b2b4d]/60 text-slate-200 font-medium">
                {filteredEquipos.length > 0 ? (
                  filteredEquipos.map((eq) => (
                    <tr key={eq.id} className="hover:bg-[#121c35]/50 transition-colors">
                      <td className="px-5 py-4 font-bold text-white flex items-center space-x-2">
                        <div className="w-7 h-7 rounded-lg bg-blue-600/20 text-blue-400 flex items-center justify-center font-bold text-[10px]">
                          {eq.nombreUsuario.charAt(0)}
                        </div>
                        <span>{eq.nombreUsuario}</span>
                      </td>
                      <td className="px-5 py-4 text-slate-300">{eq.puesto}</td>
                      <td className="px-5 py-4 text-[#8ea5cc]">{eq.departamento}</td>
                      <td className="px-5 py-4 font-bold">
                        <span className={`px-2.5 py-1 rounded-full text-[10px] border ${
                          eq.laptopOPc === 'Laptop'
                            ? 'bg-blue-500/10 text-blue-400 border-blue-500/30'
                            : 'bg-purple-500/10 text-purple-400 border-purple-500/30'
                        }`}>
                          {eq.laptopOPc}
                        </span>
                      </td>
                      <td className="px-5 py-4 font-semibold text-white">{eq.modelo}</td>
                      <td className="px-5 py-4 font-mono text-emerald-400">{eq.nombreMaquina}</td>
                      <td className="px-5 py-4 font-mono text-amber-300 bg-[#060b17]/40 px-2 py-1 rounded border border-[#1b2b4d]/60 max-w-[120px] truncate">
                        {eq.contrasena}
                      </td>

                      <td className="px-5 py-4 text-right space-x-2">
                        {/* BOTÓN IMPRIMIR RESPONSIVA 🖨️ SOLICITADO */}
                        <button
                          onClick={() => printResponsivaDoc(eq)}
                          className="p-2 bg-[#121c35] hover:bg-emerald-600 text-emerald-400 hover:text-white rounded-lg transition-colors border border-[#1e2f54] cursor-pointer shadow-sm"
                          title="Imprimir Carta Responsiva de Equipo (PDF)"
                        >
                          <Printer size={16} />
                        </button>
                        <button
                          onClick={() => setSelectedDevice(eq)}
                          className="p-2 bg-[#121c35] hover:bg-[#1554aa] text-blue-400 hover:text-white rounded-lg transition-colors border border-[#1e2f54] cursor-pointer"
                          title="Ver Ficha Técnica Completa (Ojo 👁️)"
                        >
                          <Eye size={16} />
                        </button>
                        <button
                          onClick={() => toast(`Editar registro de ${eq.nombreUsuario}`, { icon: '✏️' })}
                          className="p-2 bg-[#121c35] hover:bg-[#1554aa] text-slate-300 hover:text-white rounded-lg transition-colors border border-[#1e2f54] cursor-pointer"
                          title="Editar Equipo"
                        >
                          <Edit size={16} />
                        </button>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center text-[#7088b3]">
                      No se encontraron registros de equipos de cómputo.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

      </main>

      {/* FICHA TÉCNICA DETALLADA (OJO 👁️) */}
      {selectedDevice && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#060b17]/85 backdrop-blur-md animate-fadeIn">
          <div className="bg-[#0c1529] border border-[#1b2b4d] rounded-3xl max-w-4xl w-full max-h-[90vh] shadow-2xl relative flex flex-col overflow-hidden">
            
            {/* Modal Header */}
            <div className="p-6 border-b border-[#1b2b4d] flex items-center justify-between bg-[#081023]">
              <div className="flex items-center space-x-3">
                <div className="w-12 h-12 bg-[#1554aa] text-white rounded-2xl flex items-center justify-center shadow-lg">
                  <Laptop size={24} />
                </div>
                <div>
                  <span className="text-[10px] font-extrabold text-[#486596] uppercase tracking-widest block">FICHA COMPLETA DE EQUIPO Y CREDENCIALES</span>
                  <h3 className="text-xl font-black text-white">{selectedDevice.nombreUsuario} — {selectedDevice.nombreMaquina}</h3>
                  <p className="text-xs text-[#7088b3]">{selectedDevice.puesto} | {selectedDevice.departamento} ({selectedDevice.agency})</p>
                </div>
              </div>
              <button
                onClick={() => setSelectedDevice(null)}
                className="text-slate-400 hover:text-white p-2 rounded-xl hover:bg-slate-800 transition-colors cursor-pointer"
              >
                <X size={22} />
              </button>
            </div>

            {/* Modal Tabs Navigation */}
            <div className="flex border-b border-[#1b2b4d] bg-[#060b17] px-6 pt-2 overflow-x-auto text-xs font-bold gap-2">
              <button
                onClick={() => setModalTab('general')}
                className={`px-4 py-2.5 rounded-t-xl border-b-2 transition-all cursor-pointer ${
                  modalTab === 'general' ? 'border-[#1554aa] text-white bg-[#0c1529]' : 'border-transparent text-[#7088b3] hover:text-slate-200'
                }`}
              >
                General & Dominio
              </button>
              <button
                onClick={() => setModalTab('hardware')}
                className={`px-4 py-2.5 rounded-t-xl border-b-2 transition-all cursor-pointer ${
                  modalTab === 'hardware' ? 'border-[#1554aa] text-white bg-[#0c1529]' : 'border-transparent text-[#7088b3] hover:text-slate-200'
                }`}
              >
                Hardware & Red
              </button>
              <button
                onClick={() => setModalTab('garantia')}
                className={`px-4 py-2.5 rounded-t-xl border-b-2 transition-all cursor-pointer ${
                  modalTab === 'garantia' ? 'border-[#1554aa] text-white bg-[#0c1529]' : 'border-transparent text-[#7088b3] hover:text-slate-200'
                }`}
              >
                Garantías & Facturas
              </button>
              <button
                onClick={() => setModalTab('credenciales')}
                className={`px-4 py-2.5 rounded-t-xl border-b-2 transition-all cursor-pointer ${
                  modalTab === 'credenciales' ? 'border-[#1554aa] text-white bg-[#0c1529]' : 'border-transparent text-[#7088b3] hover:text-slate-200'
                }`}
              >
                Credenciales & Office
              </button>
              <button
                onClick={() => setModalTab('plataformas')}
                className={`px-4 py-2.5 rounded-t-xl border-b-2 transition-all cursor-pointer ${
                  modalTab === 'plataformas' ? 'border-[#1554aa] text-white bg-[#0c1529]' : 'border-transparent text-[#7088b3] hover:text-slate-200'
                }`}
              >
                Plataformas & Claves Web
              </button>
              <button
                onClick={() => setModalTab('respaldos')}
                className={`px-4 py-2.5 rounded-t-xl border-b-2 transition-all cursor-pointer ${
                  modalTab === 'respaldos' ? 'border-[#1554aa] text-white bg-[#0c1529]' : 'border-transparent text-[#7088b3] hover:text-slate-200'
                }`}
              >
                Respaldos & No-Break
              </button>
            </div>

            {/* Modal Body */}
            <div className="p-6 overflow-y-auto space-y-4 text-xs font-medium max-h-[60vh]">
              
              {/* TAB GENERAL */}
              {modalTab === 'general' && (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Nombre Usuario</span>
                    <span className="font-bold text-white">{selectedDevice.nombreUsuario}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Puesto</span>
                    <span className="font-medium text-slate-200">{selectedDevice.puesto}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Departamento</span>
                    <span className="font-medium text-slate-200">{selectedDevice.departamento}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Usuario</span>
                    <span className="font-mono text-blue-400">{selectedDevice.usuario}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Estado</span>
                    <span className="font-bold text-emerald-400">{selectedDevice.estado}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Dominio</span>
                    <span className="font-mono text-slate-300">{selectedDevice.dominio}</span>
                  </div>
                </div>
              )}

              {/* TAB HARDWARE */}
              {modalTab === 'hardware' && (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Número de Serie</span>
                    <span className="font-mono text-amber-300">{selectedDevice.serie}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Disco Duro</span>
                    <span className="font-medium text-slate-200">{selectedDevice.dd}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Procesador</span>
                    <span className="font-medium text-white">{selectedDevice.procesador} ({selectedDevice.ghz})</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">RAM</span>
                    <span className="font-mono text-purple-300">{selectedDevice.ram}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Dirección MAC</span>
                    <span className="font-mono text-blue-400">{selectedDevice.direccionMac}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Sistema Operativo</span>
                    <span className="font-bold text-white">{selectedDevice.sistemaOp}</span>
                  </div>
                </div>
              )}

              {/* TAB GARANTÍA */}
              {modalTab === 'garantia' && (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Fecha de Compra</span>
                    <span className="font-medium text-slate-200">{selectedDevice.fechaCompra}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Folio de Factura</span>
                    <span className="font-mono text-blue-400">{selectedDevice.folioFactura}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Proveedor</span>
                    <span className="font-bold text-white">{selectedDevice.proveedor}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Fin de Garantía</span>
                    <span className="font-mono text-amber-400">{selectedDevice.finGarantia}</span>
                  </div>
                </div>
              )}

              {/* TAB CREDENCIALES */}
              {modalTab === 'credenciales' && (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Usuario / Dominio</span>
                    <span className="font-mono text-blue-400">{selectedDevice.usuarioEquipoDominio}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Contraseña Equipo</span>
                    <span className="font-mono text-amber-300">{selectedDevice.contrasena}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Office</span>
                    <span className="font-bold text-white">{selectedDevice.office}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Clave Candado</span>
                    <span className="font-mono text-slate-300">{selectedDevice.claveCandado}</span>
                  </div>
                </div>
              )}

              {/* TAB PLATAFORMAS */}
              {modalTab === 'plataformas' && (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="bg-[#060b17] p-3 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block">Power PB / Pass</span>
                    <span className="font-bold text-white">{selectedDevice.powerPB}</span> — <span className="font-mono text-amber-300">{selectedDevice.contrasenaPB}</span>
                  </div>
                  <div className="bg-[#060b17] p-3 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block">POC / Pass</span>
                    <span className="font-bold text-white">{selectedDevice.poc}</span> — <span className="font-mono text-amber-300">{selectedDevice.contrasenaPOC}</span>
                  </div>
                  <div className="bg-[#060b17] p-3 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block">ETKA / Contraseña</span>
                    <span className="font-bold text-white">{selectedDevice.etka}</span> — <span className="font-mono text-amber-300">{selectedDevice.contrasenaETKA}</span>
                  </div>
                </div>
              )}

              {/* TAB RESPALDOS & NO-BREAK */}
              {modalTab === 'respaldos' && (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Antivirus</span>
                    <span className="font-bold text-emerald-400">{selectedDevice.antivirus}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">Día & Hora Respaldo</span>
                    <span className="font-bold text-white">{selectedDevice.diaRespaldo} a las {selectedDevice.horaRespaldo}</span>
                  </div>
                  <div className="bg-[#060b17] p-3.5 rounded-xl border border-[#1b2b4d]">
                    <span className="text-[#7088b3] block font-semibold mb-1">No-Break</span>
                    <span className="font-bold text-white">{selectedDevice.noBreak} ({selectedDevice.modeloNoBreak})</span>
                  </div>
                </div>
              )}

            </div>

            {/* Modal Footer Actions */}
            <div className="p-4 border-t border-[#1b2b4d] bg-[#081023] flex justify-between items-center">
              <span className="text-[11px] text-[#7088b3]">ID Ficha: <code className="text-blue-400 font-mono">{selectedDevice.id}</code></span>
              
              <div className="flex space-x-3">
                <button
                  onClick={() => printResponsivaDoc(selectedDevice)}
                  className="py-2.5 px-5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition-all shadow-lg cursor-pointer flex items-center space-x-2"
                >
                  <Printer size={16} />
                  <span>Imprimir Responsiva PDF</span>
                </button>

                <button
                  onClick={() => {
                    toast.success(`Exportando Ficha Completa PDF de ${selectedDevice.nombreUsuario}`);
                    setSelectedDevice(null);
                  }}
                  className="py-2.5 px-5 bg-[#1554aa] hover:bg-blue-600 text-white font-bold rounded-xl text-xs transition-all shadow-lg cursor-pointer flex items-center space-x-2"
                >
                  <Download size={16} />
                  <span>Exportar Ficha Completa</span>
                </button>
              </div>
            </div>

          </div>
        </div>
      )}
    </div>
  );
};
