<!-- CONTENIDO SECCIÓN 2: SITE PRINCIPAL INTERACTIVO (VISTA 3D, VISTA 2D, CONFIGURACIÓN CON VISTAS DUALES Y PALETA DE HERRAMIENTAS) -->
<?php if ($seccion_activa === 'site'): 
    $s = $registros[0] ?? [
        'nombre_site' => 'SITE Principal - CPD Agencia',
        'ubicacion' => 'Edificio A, Piso 1, Sala 102',
        'tipo_espacio' => 'Cuarto de Comunicaciones Dedicado',
        'temperatura_objetivo' => '18°C - 21°C',
        'aire_acondicionado' => 'MiniSplit Inverter LG Commercial',
        'btu' => '36,000 BTU',
        'aire_estatus' => 'Operativo / Normal',
        'ups_principal' => 'APC Smart-UPS Online RT',
        'cap_ups' => '10 kVA / 9000W',
        'planta_luz' => 'Diésel CATERPILLAR 45 kVA',
        'control_acceso' => 'Biométrico Huella + Tarjeta RFID',
        'contra_incendio' => 'Extintor Solkaflam + Agente FM-200',
        'observaciones' => ''
    ];
?>
<!-- HUD FLOATING BADGE VIDEO GAME STYLE -->
<div id="site3DHudTooltip" style="position: fixed; display: none; pointer-events: none; z-index: 999999; background: rgba(8, 19, 37, 0.96); border: 1.5px solid #00f2fe; box-shadow: 0 10px 30px rgba(0, 242, 254, 0.4); backdrop-filter: blur(14px); color: #fff; padding: 12px 16px; border-radius: 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace; min-width: 250px; max-width: 320px; transition: opacity 0.12s ease;">
    <div style="font-weight: bold; color: #00f2fe; font-size: 13px; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1px solid rgba(0, 242, 254, 0.25); padding-bottom: 4px;">
        <div style="display: flex; align-items: center; gap: 6px; overflow: hidden;">
            <span id="hudItemIcon">💻</span>
            <span id="hudItemTitle" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">EQUIPO</span>
        </div>
        <span id="hudItemBadgeVinculo" style="font-size: 9.5px; background: rgba(34, 197, 94, 0.2); color: #22c55e; border: 1px solid #22c55e; padding: 1px 6px; border-radius: 8px;">🔗 VINCULADO</span>
    </div>
    <div style="font-size: 11px; color: #94a3b8; margin-bottom: 3px;">
        Ubicación: <span id="hudItemRack" style="color: #f1f5f9; font-weight: 600;">RACK 1 (U10)</span>
    </div>
    <div id="hudItemIpRow" style="font-size: 11px; color: #94a3b8; margin-bottom: 3px;">
        Dirección IP: <span id="hudItemIp" style="color: #38bdf8; font-weight: 700; font-family: monospace;">192.168.1.1</span>
    </div>
    <div id="hudItemSerieRow" style="font-size: 11px; color: #94a3b8; margin-bottom: 3px;">
        N° Serie: <span id="hudItemSerie" style="color: #f1f5f9; font-family: monospace;">Sin Serie</span>
    </div>
    <div id="hudItemUsuarioRow" style="font-size: 11px; color: #94a3b8; margin-bottom: 3px;">
        Responsable: <span id="hudItemUsuario" style="color: #f1f5f9;">Administrador</span>
    </div>
    <div style="font-size: 11px; color: #94a3b8; margin-bottom: 2px;">
        Estatus: <span id="hudItemEstatus" style="color: #22c55e; font-weight: 700;">● OPERATIVO ONLINE</span>
    </div>
</div>
    <div class="card-custom mb-4">
        <!-- BARRA SUPERIOR TOOLBAR SITE PRINCIPAL -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 pb-3 mb-4 border-bottom border-secondary border-opacity-25">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background: rgba(16, 185, 129, 0.15); border: 2px solid rgba(16, 185, 129, 0.5); box-shadow: 0 0 20px rgba(16, 185, 129, 0.3);">
                    <i class="bi bi-hdd-rack-fill text-success fs-3"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-success bg-opacity-25 text-success border border-success px-2.5 py-0.5 rounded-pill fs-7 fw-bold font-monospace">
                            <i class="bi bi-cpu-fill me-1"></i> SITE PRINCIPAL CORE
                        </span>
                        <span class="badge bg-info bg-opacity-25 text-info border border-info px-2.5 py-0.5 rounded-pill fs-7">
                            <i class="bi bi-shield-check me-1"></i> OPERATIVO 24/7
                        </span>
                    </div>
                    <h4 class="fw-bold text-white mb-0"><?php echo htmlspecialchars($s['nombre_site']); ?></h4>
                    <span class="text-secondary small"><?php echo htmlspecialchars($s['ubicacion']); ?></span>
                </div>
            </div>
        </div>

        <!-- BARRA SUPERIOR DE ACCIONES CON SELECTOR DE VISTAS 2D / 3D -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 p-2 rounded-4 bg-dark border border-secondary border-opacity-30 shadow-sm" style="background: rgba(15, 23, 42, 0.95) !important;">
            <div class="d-flex align-items-center gap-2">
                <!-- BOTONES DE MODO: PLANO 2D, RACKS 2D Y DATACENTER 3D -->
                <div class="btn-group p-0.5 rounded-pill bg-black border border-secondary border-opacity-40" role="group">
                    <button type="button" id="btnSiteMode2D" class="btn btn-sm rounded-pill px-3 py-1 fw-bold text-dark bg-info shadow-sm" style="font-size: 0.78rem;" onclick="activarModoSite('2d')">
                        <i class="bi bi-bounding-box me-1"></i> Vista 2D Plano
                    </button>
                    <button type="button" id="btnSiteModeRacks" class="btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary" style="font-size: 0.78rem;" onclick="activarModoSite('racks')">
                        <i class="bi bi-hdd-rack me-1"></i> Vista 2D Racks
                    </button>
                    <button type="button" id="btnSiteMode3D" class="btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary" style="font-size: 0.78rem;" onclick="activarModoSite('3d')">
                        <i class="bi bi-box me-1"></i> Vista 3D (Datacenter)
                    </button>
                </div>
                <span class="text-secondary small d-none d-md-inline" id="siteModeHintText" style="font-size: 0.75rem;">Arrastra las orillas para escalar o el círculo superior para girar</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-2.5 py-1 fw-bold" id="btnResetCamera3D" style="display: none; font-size: 0.75rem;" onclick="resetearCamara3D()">
                    <i class="bi bi-camera-video me-1"></i> Centrar Cámara
                </button>
                <div class="btn-group" id="btnGroupExportarPDFSite">
                    <button type="button" class="btn btn-sm btn-outline-light rounded-start-pill px-3 py-1 fw-bold shadow-sm" style="font-size: 0.78rem;" id="btnExportarPDFSite" onclick="exportarPDFSiteHorizontal('actual')" title="Exportar plano en PDF horizontal A4">
                        <i class="bi bi-file-earmark-pdf-fill text-danger me-1"></i> Exportar PDF
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-light rounded-end-pill dropdown-toggle dropdown-toggle-split px-2 py-1" data-bs-toggle="dropdown" aria-expanded="false" style="font-size: 0.78rem;" title="Opciones de exportación PDF">
                        <span class="visually-hidden">Opciones PDF</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-lg border-secondary" style="font-size: 0.8rem; z-index: 1050;">
                        <li><a class="dropdown-item py-1.5" href="javascript:void(0)" onclick="exportarPDFSiteHorizontal('actual')"><i class="bi bi-file-earmark-pdf text-danger me-2"></i> Exportar Vista Actual</a></li>
                        <li><a class="dropdown-item py-1.5" href="javascript:void(0)" onclick="exportarPDFSiteHorizontal('plano')"><i class="bi bi-bounding-box text-info me-2"></i> Exportar Plano 2D Sala</a></li>
                        <li><a class="dropdown-item py-1.5" href="javascript:void(0)" onclick="exportarPDFSiteHorizontal('racks')"><i class="bi bi-hdd-rack text-warning me-2"></i> Exportar Elevación Racks 2D</a></li>
                        <li><hr class="dropdown-divider border-secondary opacity-50 my-1"></li>
                        <li><a class="dropdown-item py-1.5 text-success fw-bold" href="javascript:void(0)" onclick="exportarPDFSiteHorizontal('ambos')"><i class="bi bi-files text-success me-2"></i> Exportar Ambos Planos (2 Páginas A4)</a></li>
                    </ul>
                </div>
                <button type="button" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-bold shadow-sm" style="font-size: 0.78rem;" onclick="guardarPlanoSite2D()">
                    <i class="bi bi-floppy-fill me-1"></i> Guardar Plano
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold" id="btnLimpiarPlano" style="font-size: 0.78rem;" onclick="limpiarTodoPlano2D()">
                    <i class="bi bi-trash me-1"></i> Limpiar
                </button>
            </div>
        </div>

        <!-- ZONA DE VISUALIZACIÓN / EDICIÓN (LIENZOS 2D Y 3D) -->
        <div class="position-relative p-2 rounded-4 bg-dark text-center overflow-hidden border border-info border-opacity-30 shadow-lg" style="background: #030d1b !important; min-height: 600px;">
            
            <!-- VISTA 2D: LIENZO EDITABLE CON PALETA LATERAL FUERA DEL PLANO -->
            <div id="siteContainer2D" class="w-100 h-100 position-relative" style="display: flex; flex-direction: row; align-items: flex-start; gap: 10px; padding: 4px;">
                <!-- PALETA DE HERRAMIENTAS 2D (UBICADA FUERA DEL PLANO) -->
                <div id="sitePalette2D" class="flex-shrink-0 p-2 rounded-3 border border-secondary border-opacity-40 shadow-lg text-start" 
                     style="z-index: 20; width: 160px; max-height: calc(100vh - 170px); overflow-y: auto; background: rgba(8, 19, 37, 0.94); backdrop-filter: blur(10px); position: sticky; top: 0;">
                    <div class="d-flex align-items-center justify-content-between mb-1.5 pb-1 border-bottom border-secondary border-opacity-25">
                        <span class="fw-bold text-white small" style="font-size: 0.68rem;"><i class="bi bi-grid-fill text-info me-1"></i> HERRAMIENTAS</span>
                    </div>

                    <div class="d-flex flex-column gap-1.5">
                        <!-- RACK 42U -->
                        <button type="button" class="btn btn-sm btn-outline-info text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'rack')" 
                                onclick="agregarComponente2D('rack')"
                                title="Agregar Rack 42U">
                            <i class="bi bi-server fs-6 text-info"></i>
                            <span class="fw-semibold text-white">Rack 42U</span>
                        </button>

                        <!-- MINISPLIT AC -->
                        <button type="button" class="btn btn-sm btn-outline-primary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #38bdf8; border-color: #38bdf8;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'minisplit')" 
                                onclick="agregarComponente2D('minisplit')"
                                title="Agregar Minisplit AC">
                            <i class="bi bi-snow fs-6" style="color: #38bdf8;"></i>
                            <span class="fw-semibold text-white">Minisplit AC</span>
                        </button>

                        <!-- EXTINTOR ROJO -->
                        <button type="button" class="btn btn-sm btn-outline-danger text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'extintor')" 
                                onclick="agregarComponente2D('extintor')"
                                title="Agregar Extintor Rojo">
                            <i class="bi bi-fire fs-6 text-danger"></i>
                            <span class="fw-semibold text-white">Extintor Rojo</span>
                        </button>

                        <!-- EXTINTOR VERDE -->
                        <button type="button" class="btn btn-sm btn-outline-success text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #22c55e; border-color: #22c55e;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'extintor_verde')" 
                                onclick="agregarComponente2D('extintor_verde')"
                                title="Agregar Extintor Verde (Solkaflam)">
                            <i class="bi bi-fire fs-6 text-success"></i>
                            <span class="fw-semibold text-white">Extintor Verde</span>
                        </button>

                        <!-- LIBRETA / BITÁCORA -->
                        <button type="button" class="btn btn-sm btn-outline-warning text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'libreta')" 
                                onclick="agregarComponente2D('libreta')"
                                title="Agregar Bitácora / Libreta">
                            <i class="bi bi-journal-bookmark-fill fs-6 text-warning"></i>
                            <span class="fw-semibold text-white">Bitácora / Libreta</span>
                        </button>

                        <!-- DETECTOR DE HUMO -->
                        <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #e2e8f0; border-color: #64748b;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'detector_humo')" 
                                onclick="agregarComponente2D('detector_humo')"
                                title="Agregar Detector de Humo">
                            <i class="bi bi-shield-exclamation fs-6 text-danger"></i>
                            <span class="fw-semibold text-white">Detector Humo</span>
                        </button>

                        <!-- TERMÓMETRO DIGITAL -->
                        <button type="button" class="btn btn-sm text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #06b6d4; border: 1px solid #06b6d4;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'termometro_digital')" 
                                onclick="agregarComponente2D('termometro_digital')"
                                title="Agregar Termómetro Digital">
                            <i class="bi bi-thermometer-half fs-6" style="color: #06b6d4;"></i>
                            <span class="fw-semibold text-white">Termómetro Dig.</span>
                        </button>

                        <!-- CÁMARA DE SEGURIDAD (CCTV) -->
                        <button type="button" class="btn btn-sm text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #c084fc; border: 1px solid #a855f7;"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'camara_seguridad')" 
                                onclick="agregarComponente2D('camara_seguridad')"
                                title="Agregar Cámara de Seguridad CCTV">
                            <i class="bi bi-camera-video-fill fs-6" style="color: #c084fc;"></i>
                            <span class="fw-semibold text-white">Cámara CCTV</span>
                        </button>

                        <!-- LÍNEA DE COTA / MEDIDA -->
                        <button type="button" class="btn btn-sm text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #00f2fe; border: 1px solid #00f2fe; background: rgba(0, 242, 254, 0.1);"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'cota')" 
                                onclick="agregarComponente2D('cota')"
                                title="Agregar Línea de Cota / Medida al plano">
                            <i class="bi bi-rulers fs-6 text-info"></i>
                            <span class="fw-semibold text-white">Línea de Cota</span>
                        </button>

                        <!-- PARED / MURO -->
                        <button type="button" class="btn btn-sm text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #94a3b8; border: 1px solid #64748b; background: rgba(100, 116, 139, 0.1);"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'pared')" 
                                onclick="agregarComponente2D('pared')"
                                title="Agregar Pared / Muro Arquitectónico">
                            <i class="bi bi-border-width fs-6 text-secondary"></i>
                            <span class="fw-semibold text-white">Pared / Muro</span>
                        </button>

                        <!-- PISO TÉCNICO -->
                        <button type="button" class="btn btn-sm text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #38bdf8; border: 1px solid #0284c7; background: rgba(2, 132, 199, 0.1);"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'piso')" 
                                onclick="agregarComponente2D('piso')"
                                title="Agregar Baldosas de Piso Técnico Datacenter">
                            <i class="bi bi-grid-3x3 fs-6" style="color: #38bdf8;"></i>
                            <span class="fw-semibold text-white">Piso Técnico</span>
                        </button>

                        <!-- PUERTA DESLIZABLE -->
                        <button type="button" class="btn btn-sm text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                style="font-size: 0.7rem; color: #10b981; border: 1px solid #10b981; background: rgba(16, 185, 129, 0.1);"
                                draggable="true" 
                                ondragstart="onSidebarToolDragStart(event, 'puerta_deslizable')" 
                                onclick="agregarComponente2D('puerta_deslizable')"
                                title="Agregar Puerta Deslizable / Corrediza">
                            <i class="bi bi-door-closed-fill fs-6" style="color: #10b981;"></i>
                            <span class="fw-semibold text-white">Puerta Deslizable</span>
                        </button>
                    </div>
                </div>

                <!-- ÁREA PRINCIPAL DEL PLANO 2D -->
                <div class="flex-grow-1 position-relative d-flex justify-content-center align-items-center overflow-auto" style="min-width: 0;">
                    <!-- TOOLBAR FLOTANTE ULTRA COMPACTA TIPO CANVA SOBRE EL OBJETO SELECCIONADO -->
                    <div id="canvaFloatingToolbar" 
                         class="position-absolute align-items-center gap-1 px-2 py-1 rounded-pill shadow-lg" 
                         style="display: none !important; z-index: 100; transform: translate(-50%, -100%); background: rgba(15, 23, 42, 0.95) !important; border: 1px solid rgba(56, 189, 248, 0.6); backdrop-filter: blur(8px);">
                        <span id="canvaMedidasLabel" class="badge bg-black text-warning font-monospace px-1.5 py-0.5" style="font-size: 0.65rem;">90×130 px | 0°</span>
                        <button type="button" class="btn btn-xs btn-outline-info rounded-pill px-1.5 py-0" style="font-size: 0.65rem;" onclick="rotarSeleccionadoRapido()" title="Girar 90 grados">
                            <i class="bi bi-arrow-repeat"></i> 90°
                        </button>
                        <button type="button" class="btn btn-xs btn-outline-warning rounded-pill px-1.5 py-0" style="font-size: 0.65rem;" onclick="editarNombreComponente()" title="Editar Nombre">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-1.5 py-0" style="font-size: 0.65rem;" onclick="duplicarSeleccionado()" title="Duplicar">
                            <i class="bi bi-copy"></i>
                        </button>
                        <button type="button" class="btn btn-xs btn-outline-danger rounded-pill px-1.5 py-0" style="font-size: 0.65rem;" onclick="eliminarSeleccionado()" title="Eliminar">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>

                    <!-- CANVAS INTERACTIVO 2D -->
                    <canvas id="canvasPlanoSite2D"
                            width="1060"
                            height="580"
                            style="max-width: 100%; border-radius: 12px; background: #061325;"
                            ondragover="onCanvas2DDragOver(event)"
                            ondrop="onCanvas2DDrop(event)"></canvas>
                </div>
            </div>

            <!-- VISTA 2D RACKS: ELEVACIÓN FRONTAL DE GABINETES Y EQUIPOS -->
            <div id="siteContainerRacks2D" class="w-100 position-relative" style="display: none; flex-direction: row; align-items: flex-start; gap: 12px; padding: 4px; min-height: 580px; overflow-x: auto; border-radius: 12px; background: #061325;">
                <!-- PALETA LATERAL DE EQUIPOS RACKEABLES (UBICADA FUERA DEL PLANO / LIENZO) -->
                <div id="sitePaletteRacks2D" class="flex-shrink-0 p-2 rounded-3 border border-secondary border-opacity-40 shadow-lg text-start" 
                     style="z-index: 20; width: 195px; max-height: calc(100vh - 170px); overflow-y: auto; background: rgba(8, 19, 37, 0.94); backdrop-filter: blur(12px); position: sticky; top: 0;">
                    <div class="d-flex align-items-center justify-content-between mb-1.5 pb-1 border-bottom border-secondary border-opacity-25">
                        <span class="fw-bold text-white small" style="font-size: 0.68rem;">
                            <i class="bi bi-hdd-network-fill text-warning me-1"></i> EQUIPOS RACK
                        </span>
                    </div>

                    <!-- COMBO SELECT DE CATEGORÍAS PRINCIPALES -->
                    <div class="mb-2">
                        <select id="comboCategoriaEquipos" class="form-select form-select-sm bg-dark text-info border-info border-opacity-40 py-1 px-2 rounded-2 fw-semibold" 
                                style="font-size: 0.7rem; cursor: pointer; box-shadow: 0 0 10px rgba(56, 189, 248, 0.15);" 
                                onchange="filtrarCategoriaPalette(this.value)">
                            <option value="todos">📁 Todas las Categorías</option>
                            <option value="cat_inventario">📦 Vincular Inventario Real</option>
                            <option value="cat_servidores">🖥️ Servidores</option>
                            <option value="cat_nas">💾 Nas</option>
                            <option value="cat_isp">🌐 ISP</option>
                            <option value="cat_ups">🔋 UPS</option>
                            <option value="cat_sw">🔀 SW</option>
                            <option value="cerrar">🔒 Cerrar / Colapsar Todo</option>
                        </select>
                    </div>

                    <div class="d-flex flex-column gap-1.5" id="sitePaletteCategoriesContainer">
                        
                        <!-- 0. CATEGORÍA: VINCULAR INVENTARIO REAL -->
                        <div id="cat_inventario" class="palette-category-group rounded-2 border border-info border-opacity-40 p-1.5" style="background: rgba(14, 165, 233, 0.08); box-shadow: 0 0 10px rgba(14, 165, 233, 0.1);">
                            <button type="button" class="btn btn-xs w-100 text-start d-flex align-items-center justify-content-between p-1 text-info fw-bold" 
                                    onclick="toggleCategoriaPalette('cat_inventario')" 
                                    style="font-size: 0.7rem; cursor: pointer;">
                                <span><i class="bi bi-link-45deg fs-6 text-info me-1"></i> Vincular Inventario</span>
                                <i id="cat_inventario_icon" class="bi bi-chevron-down text-info" style="font-size: 0.65rem;"></i>
                            </button>
                            <div id="cat_inventario_collapse" class="flex-column gap-1.5 mt-1 pt-1 border-top border-info border-opacity-25" style="display: flex;">
                                <!-- Componente Seleccionado -->
                                <div id="panelSelCompRackInfo" class="p-1.5 rounded bg-black bg-opacity-60 border border-secondary border-opacity-30" style="font-size: 0.64rem;">
                                    <div class="text-secondary" style="font-size: 0.58rem;">Componente Seleccionado:</div>
                                    <div id="labelCompRackSeleccionado" class="fw-bold text-warning text-truncate">Ninguno (clic en un equipo)</div>
                                    <div id="subCompRackSeleccionado" class="text-secondary text-truncate mt-0.5" style="font-size: 0.56rem;">Haz clic sobre un equipo en el rack</div>
                                </div>

                                <!-- Selector de equipo de inventario real -->
                                <div class="mt-0.5">
                                    <label class="text-secondary fw-semibold d-block mb-0.5" style="font-size: 0.58rem;">Equipo de Inventario:</label>
                                    <select id="selectInventarioSiteRack" class="form-select form-select-sm bg-dark text-white border-info border-opacity-40 py-0.5 px-1 rounded" style="font-size: 0.63rem;">
                                        <option value="">-- Seleccionar Equipo --</option>
                                        <?php if (!empty($equiposInventario)): ?>
                                            <?php foreach ($equiposInventario as $eq): ?>
                                                <option value="<?php echo htmlspecialchars($eq['key']); ?>">
                                                    <?php echo htmlspecialchars($eq['label'] . ' (' . ($eq['sub'] ?: $eq['tipo']) . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <!-- Botones de Acción -->
                                <div class="d-flex gap-1 mt-0.5">
                                    <button type="button" class="btn btn-xs btn-primary flex-grow-1 py-1 px-1 rounded fw-bold d-flex align-items-center justify-content-center gap-1" style="font-size: 0.64rem;" onclick="vincularInventarioAComponenteRack()" title="Vincular el equipo seleccionado al componente">
                                        <i class="bi bi-link-45deg"></i> Vincular
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-danger py-1 px-1.5 rounded" style="font-size: 0.64rem;" onclick="desvincularInventarioDeComponenteRack()" title="Desvincular inventario del componente seleccionado">
                                        <i class="bi bi-link-45deg"></i> Desv.
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-info py-1 px-1.5 rounded" style="font-size: 0.64rem;" onclick="verFichaTecnicaModalSite()" title="Ver Ficha Técnica completa">
                                        <i class="bi bi-card-heading"></i>
                                    </button>
                                </div>

                                <!-- Draggable Box para arrastrar equipo de inventario directamente al rack -->
                                <div class="mt-0.5 p-1 rounded border border-info border-dashed text-center" 
                                     style="background: rgba(0, 242, 254, 0.06); cursor: grab; font-size: 0.61rem;"
                                     draggable="true" 
                                     ondragstart="onDragInventarioToRackStart(event)"
                                     title="Arrastra este bloque a cualquier unidad U del Rack para montarlo con sus datos de inventario">
                                    <i class="bi bi-grip-vertical text-info me-1"></i>
                                    <span class="text-info fw-bold">Arrastrar al Rack</span>
                                    <div class="text-secondary" style="font-size: 0.52rem;">Usa el equipo seleccionado arriba</div>
                                </div>
                            </div>
                        </div>

                        <!-- 1. CATEGORÍA: SERVIDORES -->
                        <div id="cat_servidores" class="palette-category-group rounded-2 border border-secondary border-opacity-25 p-1" style="background: rgba(15, 23, 42, 0.65);">
                            <button type="button" class="btn btn-xs w-100 text-start d-flex align-items-center justify-content-between p-1 text-white fw-bold" 
                                    onclick="toggleCategoriaPalette('cat_servidores')" 
                                    style="font-size: 0.7rem; cursor: pointer;">
                                <span><i class="bi bi-server text-success me-1"></i> Servidores</span>
                                <i id="cat_servidores_icon" class="bi bi-chevron-down text-secondary" style="font-size: 0.65rem;"></i>
                            </button>
                            <div id="cat_servidores_collapse" class="flex-column gap-1 mt-1 pt-1 border-top border-secondary border-opacity-25" style="display: flex;">
                                <!-- Servidor 1U -->
                                <button type="button" class="btn btn-sm btn-outline-info text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; border-color: rgba(1, 169, 130, 0.6);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'servidor')" 
                                        onclick="agregarHerramientaSite('servidor')"
                                        title="Servidor 1U HPE ProLiant - Arrastra al rack o haz clic">
                                    <i class="bi bi-hdd-stack fs-6" style="color: #01a982;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">Servidor</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U ProLiant</span>
                                    </div>
                                </button>
                                <!-- Servidor 2 Modular 3U -->
                                <button type="button" class="btn btn-sm btn-outline-warning text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #fbbf24; border-color: rgba(251, 191, 36, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'servidor_2')" 
                                        onclick="agregarHerramientaSite('servidor_2')"
                                        title="Servidor 2 Modular 3U (Permite 2 en el ancho) - Arrastra al rack o haz clic">
                                    <i class="bi bi-server fs-6 text-warning"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">servidor 2</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">3U Modular</span>
                                    </div>
                                </button>
                                <!-- Servidor Anterior Torre -->
                                <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #94a3b8; border-color: rgba(148, 163, 184, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'servidor_anterior')" 
                                        onclick="agregarHerramientaSite('servidor_anterior')"
                                        title="Servidor Anterior Torre - Arrastra al rack o haz clic">
                                    <i class="bi bi-cpu fs-6 text-secondary"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">Servidor Torre</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">Torre Anterior</span>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- 2. CATEGORÍA: NAS -->
                        <div id="cat_nas" class="palette-category-group rounded-2 border border-secondary border-opacity-25 p-1" style="background: rgba(15, 23, 42, 0.65);">
                            <button type="button" class="btn btn-xs w-100 text-start d-flex align-items-center justify-content-between p-1 text-white fw-bold" 
                                    onclick="toggleCategoriaPalette('cat_nas')" 
                                    style="font-size: 0.7rem; cursor: pointer;">
                                <span><i class="bi bi-hdd-rack text-info me-1"></i> Nas</span>
                                <i id="cat_nas_icon" class="bi bi-chevron-down text-secondary" style="font-size: 0.65rem;"></i>
                            </button>
                            <div id="cat_nas_collapse" class="flex-column gap-1 mt-1 pt-1 border-top border-secondary border-opacity-25" style="display: flex;">
                                <!-- NAS Datto 1U -->
                                <button type="button" class="btn btn-sm btn-outline-info text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #00b4d8; border-color: #00b4d8;"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'nas_datto')" 
                                        onclick="agregarHerramientaSite('nas_datto')"
                                        title="NAS Datto SIRIS BCDR 1U - Arrastra al rack o haz clic">
                                    <i class="bi bi-shield-check fs-6" style="color: #00b4d8;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">NAS datto</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U BCDR Backup</span>
                                    </div>
                                </button>
                                <!-- NAS Buffalo 2U -->
                                <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #cbd5e1; border-color: #94a3b8;"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'nas_buffalo')" 
                                        onclick="agregarHerramientaSite('nas_buffalo')"
                                        title="NAS Buffalo TeraStation 2U - Arrastra al rack o haz clic">
                                    <i class="bi bi-hdd-stack-fill fs-6 text-danger"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">NAS buffalo</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">2U TeraStation</span>
                                    </div>
                                </button>
                                <!-- NAS QNAP 1U -->
                                <button type="button" class="btn btn-sm btn-outline-light text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #f8fafc; border-color: rgba(248, 250, 252, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'nas_qnap')" 
                                        onclick="agregarHerramientaSite('nas_qnap')"
                                        title="NAS QNAP 1U 4-Bay - Arrastra al rack o haz clic">
                                    <i class="bi bi-hdd-rack fs-6 text-warning"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">NAS Qnap</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U 4-Bay Storage</span>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- 3. CATEGORÍA: ISP -->
                        <div id="cat_isp" class="palette-category-group rounded-2 border border-secondary border-opacity-25 p-1" style="background: rgba(15, 23, 42, 0.65);">
                            <button type="button" class="btn btn-xs w-100 text-start d-flex align-items-center justify-content-between p-1 text-white fw-bold" 
                                    onclick="toggleCategoriaPalette('cat_isp')" 
                                    style="font-size: 0.7rem; cursor: pointer;">
                                <span><i class="bi bi-globe text-primary me-1"></i> ISP</span>
                                <i id="cat_isp_icon" class="bi bi-chevron-down text-secondary" style="font-size: 0.65rem;"></i>
                            </button>
                            <div id="cat_isp_collapse" class="flex-column gap-1 mt-1 pt-1 border-top border-secondary border-opacity-25" style="display: flex;">
                                <!-- ONT Infinitum 1U -->
                                <button type="button" class="btn btn-sm btn-outline-warning text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #f59e0b; border-color: #f59e0b;"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'ont')" 
                                        onclick="agregarHerramientaSite('ont')"
                                        title="ONT Infinitum Telmex - Arrastra al rack o haz clic">
                                    <i class="bi bi-router-fill fs-6" style="color: #f59e0b;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">ONT</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">Módem Infinitum</span>
                                    </div>
                                </button>
                                <!-- Enlace MCM 1U -->
                                <button type="button" class="btn btn-sm btn-outline-primary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #c084fc; border-color: rgba(192, 132, 252, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'enlace_mcm')" 
                                        onclick="agregarHerramientaSite('enlace_mcm')"
                                        title="Enlace MCM 1U Telecom - Arrastra al rack o haz clic">
                                    <i class="bi bi-link-45deg fs-6" style="color: #c084fc;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">enlace MCM</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U Telecom Link</span>
                                    </div>
                                </button>
                                <!-- Router Cisco 1U -->
                                <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #94a3b8; border-color: rgba(148, 163, 184, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'router_cisco')" 
                                        onclick="agregarHerramientaSite('router_cisco')"
                                        title="Router Cisco 1841 - Arrastra al rack o haz clic">
                                    <i class="bi bi-diagram-3-fill fs-6 text-info"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">Router Cisco</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U Cisco 1841</span>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- 4. CATEGORÍA: UPS -->
                        <div id="cat_ups" class="palette-category-group rounded-2 border border-secondary border-opacity-25 p-1" style="background: rgba(15, 23, 42, 0.65);">
                            <button type="button" class="btn btn-xs w-100 text-start d-flex align-items-center justify-content-between p-1 text-white fw-bold" 
                                    onclick="toggleCategoriaPalette('cat_ups')" 
                                    style="font-size: 0.7rem; cursor: pointer;">
                                <span><i class="bi bi-battery-charging text-warning me-1"></i> UPS</span>
                                <i id="cat_ups_icon" class="bi bi-chevron-down text-secondary" style="font-size: 0.65rem;"></i>
                            </button>
                            <div id="cat_ups_collapse" class="flex-column gap-1 mt-1 pt-1 border-top border-secondary border-opacity-25" style="display: flex;">
                                <!-- UPS 1 Industronic 3U -->
                                <button type="button" class="btn btn-sm btn-outline-info text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #00d2ff; border-color: rgba(0, 210, 255, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'ups_1')" 
                                        onclick="agregarHerramientaSite('ups_1')"
                                        title="UPS 1 Industronic 3U (Permite 2 en el ancho) - Arrastra al rack o haz clic">
                                    <i class="bi bi-battery-charging fs-6" style="color: #00d2ff;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">ups 1</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">3U Industronic</span>
                                    </div>
                                </button>
                                <!-- UPS 2 APC 3000 3U -->
                                <button type="button" class="btn btn-sm btn-outline-danger text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #f87171; border-color: rgba(239, 68, 68, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'ups_2')" 
                                        onclick="agregarHerramientaSite('ups_2')"
                                        title="UPS 2 APC Smart-UPS 3000 3U (Permite 2 en el ancho) - Arrastra al rack o haz clic">
                                    <i class="bi bi-lightning-charge-fill fs-6 text-danger"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">ups 2</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">3U APC 3000</span>
                                    </div>
                                </button>
                                <!-- Barra PDU 1U -->
                                <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #94a3b8; border-color: rgba(148, 163, 184, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'barra_pdu')" 
                                        onclick="agregarHerramientaSite('barra_pdu')"
                                        title="Barra PDU 220V 1U - Arrastra al rack o haz clic">
                                    <i class="bi bi-power fs-6 text-warning"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">Barra PDU</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U PDU 220V</span>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- 5. CATEGORÍA: SW (SWITCHES & RED) -->
                        <div id="cat_sw" class="palette-category-group rounded-2 border border-secondary border-opacity-25 p-1" style="background: rgba(15, 23, 42, 0.65);">
                            <button type="button" class="btn btn-xs w-100 text-start d-flex align-items-center justify-content-between p-1 text-white fw-bold" 
                                    onclick="toggleCategoriaPalette('cat_sw')" 
                                    style="font-size: 0.7rem; cursor: pointer;">
                                <span><i class="bi bi-ethernet text-primary me-1"></i> SW</span>
                                <i id="cat_sw_icon" class="bi bi-chevron-down text-secondary" style="font-size: 0.65rem;"></i>
                            </button>
                            <div id="cat_sw_collapse" class="flex-column gap-1 mt-1 pt-1 border-top border-secondary border-opacity-25" style="display: flex;">
                                <!-- SW 1U -->
                                <button type="button" class="btn btn-sm btn-outline-primary text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #38bdf8; border-color: #38bdf8;"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'switch')" 
                                        onclick="agregarHerramientaSite('switch')"
                                        title="Switch 24 Puertos 1U - Arrastra al rack o haz clic">
                                    <i class="bi bi-ethernet fs-6" style="color: #38bdf8;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">SW</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U Switch 24P</span>
                                    </div>
                                </button>
                                <!-- Fortinet 1U -->
                                <button type="button" class="btn btn-sm btn-outline-danger text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem;"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'fortinet')" 
                                        onclick="agregarHerramientaSite('fortinet')"
                                        title="Fortinet Firewall 1U - Arrastra al rack o haz clic">
                                    <i class="bi bi-shield-shaded fs-6 text-danger"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">Fortinet</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U Firewall</span>
                                    </div>
                                </button>
                                <!-- UDM PRO 1U -->
                                <button type="button" class="btn btn-sm btn-outline-info text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #00f2fe; border-color: #00f2fe;"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'udm_pro')" 
                                        onclick="agregarHerramientaSite('udm_pro')"
                                        title="UniFi Dream Machine Pro 1U - Arrastra al rack o haz clic">
                                    <i class="bi bi-cpu fs-6" style="color: #00f2fe;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">UDM PRO</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U Gateway</span>
                                    </div>
                                </button>
                                <!-- BTAC BOX 1U -->
                                <button type="button" class="btn btn-sm btn-outline-info text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #38bdf8; border-color: #1f6ca5;"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'btac_box')" 
                                        onclick="agregarHerramientaSite('btac_box')"
                                        title="BTAC BOX 1U Ambiental - Arrastra al rack o haz clic">
                                    <i class="bi bi-cpu-fill fs-6" style="color: #38bdf8;"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">BTAC BOX</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">1U Environmental</span>
                                    </div>
                                </button>
                                <!-- Barra Tierra 1U -->
                                <button type="button" class="btn btn-sm btn-outline-success text-start d-flex align-items-center gap-1.5 py-1 px-1.5 rounded-2 w-100" 
                                        style="font-size: 0.68rem; color: #22c55e; border-color: rgba(34, 197, 94, 0.4);"
                                        draggable="true" 
                                        ondragstart="onSiteToolDragStart(event, 'barra_tierra')" 
                                        onclick="agregarHerramientaSite('barra_tierra')"
                                        title="Barra de Tierra Física - Arrastra al rack o haz clic">
                                    <i class="bi bi-lightning fs-6 text-success"></i>
                                    <div class="d-flex flex-column lh-1">
                                        <span class="fw-semibold text-white">Barra Tierra</span>
                                        <span class="text-secondary" style="font-size: 0.56rem;">Tierra Física</span>
                                    </div>
                                </button>
                            </div>
                        </div>

                    </div>

                    <!-- BOTÓN Y ZONA DE DESINSTALACIÓN / PAPELERA DE EQUIPOS -->
                    <div id="siteTrashDropZone" class="mt-2 pt-1 border-top border-secondary border-opacity-25"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect='copy';"
                         ondrop="onSiteTrashDrop(event)">
                        <button type="button" class="btn btn-sm btn-outline-danger w-100 py-1 px-1.5 rounded-2 d-flex align-items-center justify-content-center gap-1.5 shadow-sm"
                                onclick="eliminarComponenteSeleccionadoSite()" 
                                id="btnEliminarComponenteSite" 
                                title="Selecciona un equipo en el rack y pulsa para eliminarlo">
                            <i class="bi bi-trash3-fill text-danger" style="font-size: 0.8rem;"></i>
                            <span class="fw-bold" style="font-size: 0.68rem;">Eliminar Equipo</span>
                        </button>
                        <small class="text-secondary d-block mt-1 text-center font-monospace" style="font-size: 0.54rem;">
                            Selecciona y pulsa eliminar
                        </small>
                    </div>

                    <div class="mt-2 pt-1 border-top border-secondary border-opacity-25">
                        <small class="text-muted d-block" style="font-size: 0.58rem; line-height: 1.2;">
                            <i class="bi bi-info-circle text-info"></i> Arrastra a una U o haz clic
                        </small>
                    </div>
                </div>

                <!-- MODAL ELIMINAR COMPONENTES DE RACKS -->
                <div class="modal fade" id="modalEliminarComponenteSite" tabindex="-1" aria-hidden="true" style="z-index: 100000;">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content text-white border-0 shadow-2xl" style="background: #0b1528; border: 1px solid rgba(239, 68, 68, 0.4) !important; border-radius: 14px;">
                            <div class="modal-header border-bottom border-secondary border-opacity-25 py-2.5 px-3">
                                <h6 class="modal-title d-flex align-items-center gap-2 fw-bold text-danger fs-6 mb-0">
                                    <i class="bi bi-trash3-fill"></i> Retirar Equipos de Racks
                                </h6>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-3" id="modalEliminarComponenteSiteBody" style="max-height: 420px; overflow-y: auto;">
                            </div>
                            <div class="modal-footer border-top border-secondary border-opacity-25 py-2 px-3 justify-content-between">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="vaciarTodosLosRacksSite()">
                                    <i class="bi bi-trash me-1"></i> Vaciar Todos los Racks
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ÁREA PRINCIPAL DE ELEVACIÓN DE RACKS (SEPARADA Y FUERA DE LA PALETA) -->
                <div class="flex-grow-1 position-relative d-flex flex-column align-items-center justify-content-start overflow-auto py-1" style="min-width: 0;">
                    <div class="d-flex justify-content-end w-100 mb-1 pe-2">
                        <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2.5 py-1 rounded-pill font-monospace" style="font-size: 0.70rem; backdrop-filter: blur(8px);">
                            <i class="bi bi-hdd-stack me-1"></i> BASTIDORES 42U FRONT ELEVATION
                        </span>
                    </div>
                    <div class="d-flex justify-content-center align-items-center w-100">
                        <canvas id="siteCanvas2D" 
                                style="border-radius: 10px; max-width: 100%; cursor: grab;"
                                ondragover="onSite2DDragOver(event, 'siteCanvas2D')"
                                ondragleave="onSite2DDragLeave(event, 'siteCanvas2D')"
                                ondrop="onSite2DDrop(event, 'siteCanvas2D')"></canvas>
                    </div>
                </div>
            </div>

            <!-- VISTA 3D: DATACENTER (MODO VISUALIZACIÓN / SIN MODIFICACIÓN) -->
            <div id="siteContainer3D" class="w-100 position-relative" style="display: none; height: 580px; min-height: 580px; border-radius: 12px; overflow: hidden; background: #081325;">
                <!-- BADGES Y CONTROLES ESTILO VIDEOJUEGO -->
                <div class="position-absolute top-0 end-0 m-3 z-3 d-flex flex-wrap align-items-center justify-content-end gap-2">
                    <span class="badge bg-dark text-warning border border-warning border-opacity-50 px-2.5 py-1.5 rounded-pill font-monospace shadow-sm" style="font-size: 0.72rem; backdrop-filter: blur(8px);">
                        <i class="bi bi-controller me-1"></i> <strong>W/A/S/D</strong>: Moverse | <strong>Mouse</strong>: Vista cara | <strong>Q</strong>: Bajar | <strong>E/Espacio</strong>: Subir
                    </span>
                    <button type="button" id="btnSite3DPointerLock" class="btn btn-xs btn-outline-warning rounded-pill px-2.5 py-1 font-monospace shadow-sm" onclick="togglePointerLockSite3D()" title="Bloquear cursor para mirar directamente con el mouse">
                        <i class="bi bi-mouse2 me-1"></i> Bloquear Mouse
                    </button>
                    <button type="button" class="btn btn-xs btn-dark text-info border border-info rounded-pill px-2.5 py-1 shadow-sm" onclick="resetearCamara3D()" title="Restablecer posición de cámara">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Cámara
                    </button>
                </div>

                <!-- CONTENEDOR THREE.JS WEBGL -->
                <div id="siteCanvas3DContainer" class="w-100 h-100" style="min-height: 580px; width: 100%; height: 100%;"></div>
            </div>

        </div>
    </div>
<?php endif; ?>

<script src="js/infraestructura/site_2d.js?v=<?php echo time(); ?>"></script>

<?php 
// Procesar datos y logotipo de la agencia para la exportación de planos a PDF
$logoBase64 = '';
$logoAncho = 1;
$logoAlto = 1;
if (!empty($agenciaData['logo_url'])) {
    $logoRel = $agenciaData['logo_url'];
    $logoPaths = [
        $logoRel,
        __DIR__ . '/../../' . $logoRel,
        __DIR__ . '/../' . $logoRel,
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . ltrim($logoRel, '/')
    ];
    foreach ($logoPaths as $lp) {
        if (!empty($lp) && file_exists($lp) && is_file($lp)) {
            $sz = @getimagesize($lp);
            if ($sz && !empty($sz['mime'])) {
                $logoAncho = $sz[0];
                $logoAlto = $sz[1];
                $logoContent = @file_get_contents($lp);
                if ($logoContent !== false) {
                    $logoBase64 = 'data:' . $sz['mime'] . ';base64,' . base64_encode($logoContent);
                    break;
                }
            }
        }
    }
}
?>
<script>
window.siteAgenciaData = {
    nombre: <?php echo json_encode(!empty($agenciaData['nombre']) ? $agenciaData['nombre'] : ($_SESSION['agencia'] ?? 'DIVOL LA VILLA')); ?>,
    razonSocial: <?php echo json_encode($agenciaData['razon_social'] ?? ''); ?>,
    encargado: <?php echo json_encode(!empty($agenciaData['encargado_sistemas']) ? $agenciaData['encargado_sistemas'] : ''); ?>,
    logoUrl: <?php echo json_encode($agenciaData['logo_url'] ?? ''); ?>,
    logoBase64: <?php echo json_encode($logoBase64); ?>,
    logoWidth: <?php echo intval($logoAncho); ?>,
    logoHeight: <?php echo intval($logoAlto); ?>,
    siteNombre: <?php echo json_encode($s['nombre_site'] ?? 'SITE Principal'); ?>,
    ubicacion: <?php echo json_encode($s['ubicacion'] ?? 'Sala de Servidores'); ?>
};
window.savedSiteRacksData = null;
window.savedSiteFloorPlanData = null;
<?php 
$savedObs = $s['observaciones'] ?? '';
if (!empty($savedObs)) {
    $decodedObs = json_decode($savedObs, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedObs)) {
        if (isset($decodedObs['racks'])) {
            echo "window.savedSiteRacksData = " . json_encode($decodedObs['racks']) . ";\n";
        }
        if (isset($decodedObs['floorplan'])) {
            echo "window.savedSiteFloorPlanData = " . json_encode($decodedObs['floorplan']) . ";\n";
        }
    } else {
        echo "window.savedSiteRacksData = " . json_encode($savedObs) . ";\n";
    }
}
?>

// CONTROLADOR DE MODOS SITE (2D CANVA vs 3D DATACENTER)
let modoSiteActual = '2d';

function activarModoSite(modo) {
    modoSiteActual = modo;
    const c2D = document.getElementById('siteContainer2D');
    const cRacks = document.getElementById('siteContainerRacks2D');
    const c3D = document.getElementById('siteContainer3D');
    const btn2D = document.getElementById('btnSiteMode2D');
    const btnRacks = document.getElementById('btnSiteModeRacks');
    const btn3D = document.getElementById('btnSiteMode3D');
    const btnResetCam = document.getElementById('btnResetCamera3D');
    const btnLimpiar = document.getElementById('btnLimpiarPlano');
    const btnPdfGroup = document.getElementById('btnGroupExportarPDFSite');
    const hint = document.getElementById('siteModeHintText');

    // Ocultar toolbar Canva 2D
    const tb = document.getElementById('canvaFloatingToolbar');
    if (tb) {
        tb.style.display = 'none';
        tb.style.setProperty('display', 'none', 'important');
    }

    // Ocultar HUD 3D de inmediato al cambiar o inicializar modo
    const hud3D = document.getElementById('site3DHudTooltip');
    if (hud3D) {
        hud3D.style.display = 'none';
        hud3D.style.setProperty('display', 'none', 'important');
    }

    if (modo === '2d') {
        if (c2D) {
            c2D.style.display = 'flex';
            c2D.style.setProperty('display', 'flex', 'important');
        }
        if (cRacks) {
            cRacks.style.display = 'none';
            cRacks.style.setProperty('display', 'none', 'important');
        }
        if (c3D) {
            c3D.style.display = 'none';
            c3D.style.setProperty('display', 'none', 'important');
        }

        if (btn2D) btn2D.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-dark bg-info shadow-sm';
        if (btnRacks) btnRacks.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary';
        if (btn3D) btn3D.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary';

        if (btnResetCam) btnResetCam.style.display = 'none';
        if (btnLimpiar) btnLimpiar.style.display = 'inline-block';
        if (btnPdfGroup) btnPdfGroup.style.display = 'inline-flex';
        if (hint) hint.textContent = 'Arrastra las orillas para escalar o el círculo superior para girar';

        if (typeof renderPlanoSite2D === 'function') {
            renderPlanoSite2D();
        }
    } else if (modo === 'racks') {
        if (c2D) {
            c2D.style.display = 'none';
            c2D.style.setProperty('display', 'none', 'important');
        }
        if (cRacks) {
            cRacks.style.display = 'flex';
            cRacks.style.setProperty('display', 'flex', 'important');
        }
        if (c3D) {
            c3D.style.display = 'none';
            c3D.style.setProperty('display', 'none', 'important');
        }

        if (btn2D) btn2D.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary';
        if (btnRacks) btnRacks.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-dark bg-warning shadow-sm';
        if (btn3D) btn3D.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary';

        if (btnResetCam) btnResetCam.style.display = 'none';
        if (btnLimpiar) btnLimpiar.style.display = 'none';
        if (btnPdfGroup) btnPdfGroup.style.display = 'inline-flex';
        if (hint) hint.textContent = 'Vista de elevación 2D: Distribución de Unidades (U) y módulos de cada Rack 42U';

        // Sincronizar y dibujar elevación 2D de racks
        setTimeout(() => {
            if (window.site2DObjects && Array.isArray(window.site2DObjects)) {
                if (typeof siteFloorPlanObjects !== 'undefined') {
                    siteFloorPlanObjects = window.site2DObjects;
                    const remainingRacks = siteFloorPlanObjects.filter(o => o && (o.type === 'rack' || (o.id && String(o.id).toLowerCase().includes('rack'))));
                    if (remainingRacks.length === 0 && typeof siteRacksData !== 'undefined') {
                        siteRacksData = {};
                    }
                }
            }
            if (typeof drawSite2DRackElevation === 'function') {
                drawSite2DRackElevation('siteCanvas2D');
            }
        }, 50);
    } else if (modo === '3d') {
        if (c2D) {
            c2D.style.display = 'none';
            c2D.style.setProperty('display', 'none', 'important');
        }
        if (cRacks) {
            cRacks.style.display = 'none';
            cRacks.style.setProperty('display', 'none', 'important');
        }
        if (c3D) {
            c3D.style.display = 'block';
            c3D.style.setProperty('display', 'block', 'important');
        }

        if (btn2D) btn2D.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary';
        if (btnRacks) btnRacks.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-secondary';
        if (btn3D) btn3D.className = 'btn btn-sm rounded-pill px-3 py-1 fw-bold text-dark bg-info shadow-sm';

        if (btnResetCam) btnResetCam.style.display = 'inline-block';
        if (btnLimpiar) btnLimpiar.style.display = 'none';
        if (btnPdfGroup) btnPdfGroup.style.display = 'none';
        if (hint) hint.textContent = 'Modo visualización 3D: WASD para moverte | Mouse para mirar | Q bajar | E subir';

        // Renderizar / inicializar 3D sincronizado exactamente con los objetos 2D
        setTimeout(() => {
            inicializarOActualizarSite3D();
        }, 60);
    }
}

// SINCRONIZACIÓN Y RENDERIZADO 3D (SOLO VISUALIZACIÓN - NO SE MODIFICA NADA EN 3D)
function inicializarOActualizarSite3D() {
    if (typeof THREE === 'undefined') return;

    // Asegurar que siteFloorPlanObjects de site_engine use los objetos actuales de site2DObjects
    if (window.site2DObjects && Array.isArray(window.site2DObjects)) {
        if (typeof siteFloorPlanObjects !== 'undefined') {
            siteFloorPlanObjects = window.site2DObjects;
        }
    }

    const container = document.getElementById('siteCanvas3DContainer');
    if (!container) return;

    if (typeof initSite3DScene === 'function') {
        if (typeof siteScene3D === 'undefined' || !siteScene3D || !siteRenderer3D) {
            initSite3DScene('siteCanvas3DContainer');
        } else {
            if (typeof moverRender3DAContenedor === 'function') {
                moverRender3DAContenedor('siteCanvas3DContainer');
            }
        }
    }

    if (typeof onSiteWindowResize === 'function') {
        onSiteWindowResize();
    }

    if (typeof sincronizarEscena3DDesde2D === 'function') {
        sincronizarEscena3DDesde2D();
    }

    // Posicionar la cámara directamente en la puerta si existe, o en la perspectiva general
    if (typeof posicionarCamaraAlEntrar3D === 'function') {
        posicionarCamaraAlEntrar3D();
    }
}

function resetearCamara3D() {
    if (typeof posicionarCamaraAlEntrar3D === 'function') {
        posicionarCamaraAlEntrar3D();
    } else if (typeof resetSite3DCamera === 'function') {
        resetSite3DCamera();
    }
}
</script>
