<!-- CONTENIDO SECCIÓN 3: RED E IDFS -->
<?php if ($seccion_activa === 'red'): ?>
    
    <!-- MENÚ PRINCIPAL: 2 BOTONES GRANDES (NODOS y VLANS) -->
    <div id="redMenuPrincipal">
        <div class="text-center mb-5 mt-2">
            <h3 class="fw-bold text-white mb-2"><i class="bi bi-diagram-2 text-info me-2"></i> Infraestructura de Red & Segmentación</h3>
            <p class="text-secondary fs-6">Selecciona una de las siguientes opciones para gestionar la red de la agencia:</p>
        </div>

        <div class="row g-4 justify-content-center my-3">
            <!-- BOTÓN 1: NODOS DE RED (EN GRANDE) -->
            <div class="col-md-4 col-lg-4">
                <div class="card h-100 p-4 text-center futuristic-big-card" onclick="mostrarSubseccionRed('nodos')" style="background: linear-gradient(145deg, rgba(15, 23, 42, 0.95), rgba(30, 27, 75, 0.85)); border: 2px solid rgba(192, 132, 252, 0.4); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.6);">
                    <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 90px; height: 90px; background: rgba(192, 132, 252, 0.15); border: 2.5px solid rgba(192, 132, 252, 0.6); box-shadow: 0 0 30px rgba(192, 132, 252, 0.35);">
                        <i class="bi bi-ethernet" style="font-size: 3.2rem; color: #c084fc;"></i>
                    </div>
                    <h2 class="fw-bold text-white mb-2" style="letter-spacing: 1.5px;">NODOS</h2>
                    <p class="text-secondary small mb-4">Gestión completa de nodos de red, rosetas, puertos de patch panel, switch y conexiones físicas.</p>
                    <div class="mt-auto">
                        <button type="button" class="btn btn-outline-info rounded-pill px-4 py-2 fw-bold w-100 fs-6" style="border-color: #c084fc; color: #c084fc;">
                            <i class="bi bi-arrow-right-circle-fill me-2"></i> Entrar a Nodos de Red
                        </button>
                    </div>
                </div>
            </div>

            <!-- BOTÓN 2: VLANS (EN GRANDE) -->
            <div class="col-md-4 col-lg-4">
                <div class="card h-100 p-4 text-center futuristic-big-card" onclick="mostrarSubseccionRed('vlans')" style="background: linear-gradient(145deg, rgba(15, 23, 42, 0.95), rgba(12, 74, 110, 0.85)); border: 2px solid rgba(56, 189, 248, 0.4); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.6);">
                    <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 90px; height: 90px; background: rgba(56, 189, 248, 0.15); border: 2.5px solid rgba(56, 189, 248, 0.6); box-shadow: 0 0 30px rgba(56, 189, 248, 0.35);">
                        <i class="bi bi-diagram-3-fill" style="font-size: 3.2rem; color: #38bdf8;"></i>
                    </div>
                    <h2 class="fw-bold text-white mb-2" style="letter-spacing: 1.5px;">VLANs</h2>
                    <p class="text-secondary small mb-4">Segmentación de red, IDs de VLAN, subredes IP, puertas de enlace y rangos DHCP.</p>
                    <div class="mt-auto">
                        <button type="button" class="btn btn-outline-info rounded-pill px-4 py-2 fw-bold w-100 fs-6">
                            <i class="bi bi-arrow-right-circle-fill me-2"></i> Entrar a VLANs
                        </button>
                    </div>
                </div>
            </div>

            <!-- BOTÓN 3: SWITCHES (EN GRANDE) -->
            <div class="col-md-4 col-lg-4">
                <div class="card h-100 p-4 text-center futuristic-big-card" onclick="mostrarSubseccionRed('switches')" style="background: linear-gradient(145deg, rgba(15, 23, 42, 0.95), rgba(6, 78, 59, 0.85)); border: 2px solid rgba(16, 185, 129, 0.4); border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.6);">
                    <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 90px; height: 90px; background: rgba(16, 185, 129, 0.15); border: 2.5px solid rgba(16, 185, 129, 0.6); box-shadow: 0 0 30px rgba(16, 185, 129, 0.35);">
                        <i class="bi bi-hdd-network-fill" style="font-size: 3.2rem; color: #10b981;"></i>
                    </div>
                    <h2 class="fw-bold text-white mb-2" style="letter-spacing: 1.5px;">SWITCHES</h2>
                    <p class="text-secondary small mb-4">Conmutadores Core y distribución, mapeo de puertos RJ45, enlaces troncales y estado operativo.</p>
                    <div class="mt-auto">
                        <button type="button" class="btn btn-outline-success rounded-pill px-4 py-2 fw-bold w-100 fs-6" style="border-color: #10b981; color: #10b981;">
                            <i class="bi bi-arrow-right-circle-fill me-2"></i> Entrar a Switches
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SUBSECCIÓN 1: NODOS DE RED FLOTANTES INTERACTIVOS -->
    <div id="subseccionRedNodos" style="display:none;">
        <div class="card-custom">
            <!-- TOP CONTROLS & HEADER -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn btn-outline-light btn-sm rounded-3 px-3 fw-bold" onclick="volverAMenuRed()">
                        <i class="bi bi-arrow-left me-1.5"></i> Volver a Menú
                    </button>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-primary bg-opacity-25 text-info border border-info px-2.5 py-1 rounded-pill fs-7 fw-bold font-monospace">
                                <i class="bi bi-ethernet me-1"></i> MATRIZ DE NODOS DE RED
                            </span>
                            <span class="badge bg-success bg-opacity-25 text-success border border-success px-2.5 py-1 rounded-pill fs-7">
                                <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> CONEXIONES ACTIVAS
                            </span>
                        </div>
                        <h4 class="fw-bold text-white mb-0">Nodos de Red & Puertos RJ45</h4>
                        <span class="text-secondary small">Selecciona cualquier nodo flotante para ver su expediente técnico completo, puerto de switch y roseta.</span>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- BUSCADOR EN TIEMPO REAL -->
                    <div class="position-relative" style="width: 210px;">
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-info"></i>
                        <input type="text" id="nodoSearchInput" class="form-control form-control-sm ps-5 bg-dark text-white border-secondary rounded-pill" placeholder="Buscar nodo, ubic, SW..." oninput="filtrarNodosFlotantes()">
                    </div>
                    
                    <!-- BOTÓN IMPRIMIR REPORTE DE NODOS CON ENCABEZADO INSTITUCIONAL -->
                    <a href="reporte_nodos.php" target="_blank" class="btn btn-outline-info btn-sm rounded-pill px-3 py-2 fw-bold shadow-sm d-flex align-items-center gap-1.5" title="Imprimir / Exportar Cédula y Reporte de Nodos en PDF con Encabezado Institucional">
                        <i class="bi bi-printer-fill fs-6 text-warning"></i> Imprimir Nodos
                    </a>

                    <button type="button" class="btn btn-info btn-sm text-dark rounded-pill px-4 py-2 fw-bold shadow-sm" onclick="abrirModalNuevoRed('nodo')">
                        <i class="bi bi-plus-lg me-1"></i> Registrar Nuevo Nodo
                    </button>
                </div>
            </div>

            <!-- REJILLA MATRIZ DE NODOS RJ45 FLOTANTES -->
            <?php if (empty($registrosNodos)): ?>
                <div class="text-center py-5">
                    <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; background: rgba(56, 189, 248, 0.1); border: 2px dashed rgba(56, 189, 248, 0.4);">
                        <i class="bi bi-ethernet text-info" style="font-size: 2.5rem;"></i>
                    </div>
                    <h5 class="text-white fw-bold">No hay Nodos de Red registrados</h5>
                    <p class="text-secondary small mb-3">Haz clic en "Registrar Nuevo Nodo" para agregar el primer puerto RJ45 a la matriz de red.</p>
                    <button type="button" class="btn btn-info btn-sm text-dark rounded-pill px-4 fw-bold" onclick="abrirModalNuevoRed('nodo')">
                        <i class="bi bi-plus-lg me-1"></i> Registrar Primer Nodo
                    </button>
                </div>
            <?php else: ?>
                <div class="row g-4" id="contenedorNodosFlotantes">
                    <?php foreach ($registrosNodos as $nd): 
                        $jsonNd = htmlspecialchars(json_encode($nd), ENT_QUOTES, 'UTF-8');
                        $estatusColor = ($nd['estatus'] === 'Activo') ? '#10b981' : (($nd['estatus'] === 'Disponible') ? '#3b82f6' : '#f59e0b');
                    ?>
                        <div class="col-6 col-sm-4 col-md-3 col-lg-2.4 col-xl-2 nodo-card-item" 
                             data-codigo="<?php echo strtolower($nd['codigo_nodo'] ?? ''); ?>" 
                             data-ubicacion="<?php echo strtolower($nd['ubicacion'] ?? ''); ?>" 
                             data-switch="<?php echo strtolower($nd['switch_puerto'] ?? ''); ?>"
                             data-vlan="<?php echo strtolower($nd['vlan'] ?? ''); ?>">
                            
                            <div class="nodo-floating-card text-center p-3 rounded-4" 
                                 onclick='verDetalleNodoFlotante(<?php echo $jsonNd; ?>)'
                                 title="Haz clic para ver expediente completo del nodo <?php echo htmlspecialchars($nd['codigo_nodo']); ?>">
                                
                                <!-- ICONO RJ45 AZUL CON GLOW FLOTANTE -->
                                <div class="nodo-icon-glow mx-auto mb-2 position-relative">
                                    <i class="bi bi-ethernet"></i>
                                    <span class="position-absolute bottom-0 end-0 p-1 rounded-circle" style="background-color: <?php echo $estatusColor; ?>; border: 2px solid #0f172a;" title="Estatus: <?php echo htmlspecialchars($nd['estatus']); ?>"></span>
                                </div>

                                <!-- CÓDIGO DEL NODO -->
                                <h6 class="fw-bold font-monospace mb-1 text-truncate px-1" style="color: #38bdf8 !important;">
                                    <?php echo htmlspecialchars($nd['codigo_nodo']); ?>
                                </h6>
                                
                                <!-- TIPO / UBICACIÓN COMPACTO -->
                                <div class="small text-secondary text-truncate" style="font-size: 0.75rem;">
                                    <i class="bi bi-geo-alt text-info me-0.5"></i> <?php echo htmlspecialchars($nd['ubicacion'] ?: 'Sin ubic.'); ?>
                                </div>

                                <!-- EXTENSIÓN SI TIENE TELÉFONO POE ASIGNADO (SOLAMENTE SU EXTENSIÓN) -->
                                <?php 
                                    $extNodo = trim($nd['telefono_extension'] ?? '');
                                    if (empty($extNodo) && !empty($nd['telefono_poe_info'])) {
                                        preg_match('/(?:Ext\.?\s*)([0-9A-Za-z]+)/i', $nd['telefono_poe_info'], $mExt);
                                        if (!empty($mExt[1])) $extNodo = $mExt[1];
                                    }
                                ?>
                                <?php if (!empty($extNodo)): ?>
                                    <div class="mt-1">
                                        <span class="badge bg-warning bg-opacity-25 text-warning font-monospace" style="font-size: 0.72rem; border: 1px solid rgba(234, 179, 8, 0.45);" title="Teléfono PoE asignado">
                                            <i class="bi bi-telephone-fill me-1"></i>Ext. <?php echo htmlspecialchars($extNodo); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <!-- BADGE VLAN SI EXISTE -->
                                <?php if (!empty($nd['vlan'])): ?>
                                    <span class="badge bg-info bg-opacity-25 text-info font-monospace small mt-2" style="font-size: 0.7rem; border: 1px solid rgba(56, 189, 248, 0.4);">
                                        <?php echo htmlspecialchars($nd['vlan']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SUBSECCIÓN 2: VLANS & SUBREDES (MAPA DE TOPOLOGÍA Y TARJETAS FLOTANTES FUTURISTAS) -->
    <div id="subseccionRedVlans" style="display:none;">
        <div class="card-custom mb-4">
            <!-- BANNER CABECERA DE TOPOLOGÍA FUTURISTA -->
            <div class="p-4 rounded-4 mb-4 position-relative overflow-hidden" style="background: linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.9)); border: 1.5px solid rgba(56, 189, 248, 0.35); box-shadow: 0 10px 30px rgba(0,0,0,0.5), inset 0 0 30px rgba(56, 189, 248, 0.08);">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative" style="z-index: 2;">
                    <div class="d-flex align-items-center gap-3">
                        <button type="button" class="btn btn-outline-light btn-sm rounded-3 px-3 fw-bold" onclick="volverAMenuRed()">
                            <i class="bi bi-arrow-left me-1.5"></i> Menú Principal
                        </button>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2.5 py-1 rounded-pill fs-7 fw-bold font-monospace">
                                    <i class="bi bi-cpu-fill me-1"></i> TOPOLOGÍA VIRTUAL L2/L3
                                </span>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success px-2.5 py-1 rounded-pill fs-7">
                                    <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> OPERATIVO
                                </span>
                            </div>
                            <h4 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-diagram-3-fill text-warning fs-3"></i> Matriz & Mapa Diagrama de VLANs
                            </h4>
                            <span class="text-secondary small">Visualización gráfica de segmentos IP, switches troncales y direccionamiento lógico de red.</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="position-relative" style="min-width: 240px;">
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-secondary"></i>
                            <input type="text" id="vlanSearchInput" onkeyup="filtrarVlansDiagrama()" class="form-control form-control-sm ps-5 bg-dark border-secondary text-white rounded-pill" placeholder="Buscar VLAN o Subred IP...">
                        </div>
                        <button type="button" class="btn btn-warning btn-sm rounded-pill px-3.5 py-2 fw-bold shadow-lg" onclick="abrirModalNuevoRed('vlan')">
                            <i class="bi bi-plus-lg me-1"></i> Registrar Nueva VLAN
                        </button>
                    </div>
                </div>

                <!-- MÉTRICAS DE RED DE CABECERA -->
                <div class="row g-3 mt-3 pt-3 border-top border-secondary border-opacity-25">
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-diagram-3 text-info fs-4"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">VLANs Mapeadas</span>
                                <span class="fw-bold text-white font-monospace fs-6"><?php echo count($registrosVlans); ?> Segmentos</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-ethernet text-warning fs-4"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">Nodos Conectados</span>
                                <span class="fw-bold text-white font-monospace fs-6"><?php echo count($registrosNodos); ?> Puertos</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-router text-success fs-4"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">Puerta de Enlace Core</span>
                                <span class="fw-bold text-success font-monospace fs-6">192.168.1.1 / Core</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-shield-check text-purple fs-4" style="color: #c084fc;"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">Aislamiento L2</span>
                                <span class="fw-bold text-white font-monospace fs-6">Habilitado</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($registrosVlans)): ?>
                <div class="text-center py-5 rounded-4 p-4 my-3" style="background: rgba(15, 23, 42, 0.6); border: 2px dashed rgba(251, 191, 36, 0.3);">
                    <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 76px; height: 76px; background: rgba(251, 191, 36, 0.12); border: 1.5px solid rgba(251, 191, 36, 0.4);">
                        <i class="bi bi-diagram-3-fill text-warning" style="font-size: 2.5rem;"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-2">No hay VLANs flotantes registradas aún</h5>
                    <p class="text-secondary small max-w-lg mx-auto mb-4" style="max-width: 480px;">Presiona el botón de abajo para registrar los segmentos de red y ver sus bloques de topología flotante.</p>
                    <button type="button" class="btn btn-warning btn-sm rounded-pill px-4 py-2 fw-bold" onclick="abrirModalNuevoRed('vlan')">
                        <i class="bi bi-plus-circle me-1.5"></i> Registrar Nueva VLAN Flotante
                    </button>
                </div>
            <?php else: ?>
                <!-- RETÍCULA DE TARJETAS FLOTANTES FUTURISTAS -->
                <div class="row g-4" id="vlanGridContainer">
                    <?php 
                    $palette = [
                        ['border' => '#38bdf8', 'glow' => 'rgba(56, 189, 248, 0.2)', 'bg' => 'rgba(56, 189, 248, 0.15)', 'text' => '#38bdf8'],
                        ['border' => '#c084fc', 'glow' => 'rgba(192, 132, 252, 0.2)', 'bg' => 'rgba(192, 132, 252, 0.15)', 'text' => '#c084fc'],
                        ['border' => '#34d399', 'glow' => 'rgba(52, 211, 153, 0.2)', 'bg' => 'rgba(52, 211, 153, 0.15)', 'text' => '#34d399'],
                        ['border' => '#fbbf24', 'glow' => 'rgba(251, 191, 36, 0.2)', 'bg' => 'rgba(251, 191, 36, 0.15)', 'text' => '#fbbf24'],
                        ['border' => '#fb923c', 'glow' => 'rgba(251, 146, 60, 0.2)', 'bg' => 'rgba(251, 146, 60, 0.15)', 'text' => '#fb923c']
                    ];
                    $idx = 0;
                    foreach ($registrosVlans as $vl):
                        $col = $palette[$idx % count($palette)];
                        $idx++;

                        // Icono dinámico según el nombre/propósito de la VLAN
                        $nombreUpper = strtoupper($vl['nombre_vlan'] ?? '');
                        $iconClass = 'bi-diagram-3-fill';
                        if (strpos($nombreUpper, 'VOIP') !== false || strpos($nombreUpper, 'TEL') !== false) {
                            $iconClass = 'bi-telephone-fill';
                        } elseif (strpos($nombreUpper, 'CCTV') !== false || strpos($nombreUpper, 'CAMARA') !== false || strpos($nombreUpper, 'SEGURIDAD') !== false) {
                            $iconClass = 'bi-camera-video-fill';
                        } elseif (strpos($nombreUpper, 'WIFI') !== false || strpos($nombreUpper, 'WIRELESS') !== false || strpos($nombreUpper, 'INVITADO') !== false) {
                            $iconClass = 'bi-wifi';
                        } elseif (strpos($nombreUpper, 'DATOS') !== false || strpos($nombreUpper, 'CORP') !== false) {
                            $iconClass = 'bi-pc-display-horizontal';
                        } elseif (strpos($nombreUpper, 'DMZ') !== false || strpos($nombreUpper, 'SERVER') !== false) {
                            $iconClass = 'bi-hdd-network-fill';
                        }

                        // Conteo de nodos asociados a esta VLAN
                        $nodosEnVlan = 0;
                        $vlanSearchTag = 'VLAN ' . $vl['vlan_id'];
                        foreach ($registrosNodos as $nItem) {
                            if (strpos(strtoupper($nItem['vlan'] ?? ''), (string)$vl['vlan_id']) !== false) {
                                $nodosEnVlan++;
                            }
                        }

                        // Pre-codificar JSON de manera segura para evitar errores de sintaxis HTML
                        $vlJsonAttr = htmlspecialchars(json_encode($vl), ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="col-6 col-sm-4 col-md-3 col-lg-2.4 col-xl-2 vlan-card-item" 
                             data-vlan-id="<?php echo $vl['vlan_id']; ?>" 
                             data-vlan-name="<?php echo htmlspecialchars(strtolower($vl['nombre_vlan'])); ?>" 
                             data-vlan-subred="<?php echo htmlspecialchars(strtolower($vl['subred'] ?? '')); ?>">
                            
                            <div class="nodo-floating-card text-center p-3 rounded-4" 
                                 onclick='verDetalleVlanFlotante(<?php echo $vlJsonAttr; ?>)'
                                 title="Haz clic para ver la ficha técnica completa de la VLAN <?php echo htmlspecialchars($vl['nombre_vlan']); ?>">
                                
                                <!-- ICONO DE VLAN FLOTANTE CON GLOW -->
                                <div class="nodo-icon-glow mx-auto mb-2 position-relative" style="background: <?php echo $col['bg']; ?>; border-color: <?php echo $col['border']; ?>; color: <?php echo $col['text']; ?>; box-shadow: 0 0 20px <?php echo $col['glow']; ?>;">
                                    <i class="bi <?php echo $iconClass; ?>"></i>
                                    <span class="position-absolute bottom-0 end-0 p-1 rounded-circle" style="background-color: <?php echo ($vl['estatus'] === 'Activa') ? '#10b981' : '#f59e0b'; ?>; border: 2px solid #0f172a;" title="Estatus: <?php echo htmlspecialchars($vl['estatus']); ?>"></span>
                                </div>

                                <!-- CÓDIGO / TAG DE LA VLAN -->
                                <span class="badge rounded-pill px-2 py-0.5 font-monospace mb-1" style="background: <?php echo $col['bg']; ?>; color: <?php echo $col['text']; ?>; border: 1px solid <?php echo $col['border']; ?>; font-size: 0.72rem;">
                                    VLAN <?php echo htmlspecialchars($vl['vlan_id']); ?>
                                </span>

                                <!-- NOMBRE DE LA VLAN -->
                                <h6 class="fw-bold text-white font-monospace mb-1 text-truncate px-1" style="font-size: 0.85rem;">
                                    <?php echo htmlspecialchars($vl['nombre_vlan']); ?>
                                </h6>
                                
                                <!-- SUBRED IP COMPACTA -->
                                <div class="small text-secondary font-monospace text-truncate" style="font-size: 0.73rem;">
                                    <i class="bi bi-globe text-info me-0.5"></i> <?php echo htmlspecialchars($vl['subred'] ?: 'Sin subred'); ?>
                                </div>

                                <!-- BADGE NODOS ASOCIADOS -->
                                <span class="badge bg-dark border border-secondary text-light font-monospace small mt-2" style="font-size: 0.68rem;">
                                    <?php echo $nodosEnVlan; ?> Nodos
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SUBSECCIÓN 3: SWITCHES DE RED & CONMUTACIÓN -->
    <div id="subseccionRedSwitches" style="display:none;">
        <div class="card-custom mb-4">
            <!-- BANNER CABECERA DE SWITCHES FUTURISTA -->
            <div class="p-4 rounded-4 mb-4 position-relative overflow-hidden" style="background: linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(6, 78, 59, 0.9)); border: 1.5px solid rgba(16, 185, 129, 0.35); box-shadow: 0 10px 30px rgba(0,0,0,0.5), inset 0 0 30px rgba(16, 185, 129, 0.08);">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative" style="z-index: 2;">
                    <div class="d-flex align-items-center gap-3">
                        <button type="button" class="btn btn-outline-light btn-sm rounded-3 px-3 fw-bold" onclick="volverAMenuRed()">
                            <i class="bi bi-arrow-left me-1.5"></i> Menú Principal
                        </button>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-success bg-opacity-25 text-success border border-success px-2.5 py-1 rounded-pill fs-7 fw-bold font-monospace">
                                    <i class="bi bi-hdd-network-fill me-1"></i> CONMUTACIÓN & CAPA 2/3
                                </span>
                                <span class="badge bg-info bg-opacity-25 text-info border border-info px-2.5 py-1 rounded-pill fs-7">
                                    <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> OPERATIVO 24/7
                                </span>
                            </div>
                            <h4 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-hdd-network-fill text-success fs-3"></i> Switches de Red & Conmutación
                            </h4>
                            <span class="text-secondary small">Gestión y monitoreo de switches Core y distribución, puertos PoE/Gigabit y enlaces a rosetas.</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="position-relative" style="min-width: 240px;">
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-secondary"></i>
                            <input type="text" id="switchSearchInput" onkeyup="filtrarSwitchesRed()" class="form-control form-control-sm ps-5 bg-dark border-secondary text-white rounded-pill" placeholder="Buscar switch, IP, modelo...">
                        </div>
                        <button type="button" class="btn btn-success btn-sm rounded-pill px-3.5 py-2 fw-bold shadow-lg" onclick="abrirModalNuevoRed('switch')">
                            <i class="bi bi-plus-lg me-1"></i> Registrar Nuevo Switch
                        </button>
                    </div>
                </div>

                <!-- MÉTRICAS DE SWITCHES DE CABECERA -->
                <div class="row g-3 mt-3 pt-3 border-top border-secondary border-opacity-25">
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-hdd-network text-success fs-4"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">Switches Mapeados</span>
                                <span class="fw-bold text-white font-monospace fs-6"><?php echo count($registrosSwitches ?? []); ?> Conmutadores</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-ethernet text-info fs-4"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">Nodos Conectados</span>
                                <span class="fw-bold text-white font-monospace fs-6"><?php echo count($registrosNodos ?? []); ?> Puertos</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-hdd-stack text-warning fs-4"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">Puertos Estimados</span>
                                <span class="fw-bold text-warning font-monospace fs-6"><?php echo max(24, count($registrosSwitches ?? []) * 24); ?> Puertos</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.06);">
                            <i class="bi bi-shield-check text-success fs-4"></i>
                            <div>
                                <span class="text-secondary d-block" style="font-size: 0.7rem;">Estatus Red</span>
                                <span class="fw-bold text-success font-monospace fs-6">● 100% Online</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($registrosSwitches)): ?>
                <div class="text-center py-5 rounded-4 p-4 my-3" style="background: rgba(15, 23, 42, 0.6); border: 2px dashed rgba(16, 185, 129, 0.3);">
                    <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center" style="width: 76px; height: 76px; background: rgba(16, 185, 129, 0.12); border: 1.5px solid rgba(16, 185, 129, 0.4);">
                        <i class="bi bi-hdd-network text-success" style="font-size: 2.5rem;"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-2">No hay Switches registrados aún</h5>
                    <p class="text-secondary small max-w-lg mx-auto mb-4" style="max-width: 480px;">Registra los conmutadores de red de la agencia para mapear sus puertos con los nodos RJ45.</p>
                    <button type="button" class="btn btn-success btn-sm rounded-pill px-4 py-2 fw-bold" onclick="abrirModalNuevoRed('switch')">
                        <i class="bi bi-plus-circle me-1.5"></i> Registrar Primer Switch
                    </button>
                </div>
            <?php else: ?>
                <!-- RETÍCULA DE TARJETAS FLOTANTES DE SWITCHES -->
                <div class="row g-4" id="switchGridContainer">
                    <?php 
                    foreach ($registrosSwitches as $sw):
                        $swLabel = $sw['label'] ?? 'Switch';
                        $swSub = $sw['sub'] ?? ($sw['tipo'] ?? 'Switch 24P');
                        $swIp = $sw['ip'] ?? 'Sin IP';
                        $swUbicacion = $sw['ubicacion'] ?? 'SITE';
                        $swJsonAttr = htmlspecialchars(json_encode($sw), ENT_QUOTES, 'UTF-8');

                        // Conteo de nodos asociados a este switch
                        $nodosEnSwitch = 0;
                        if (!empty($registrosNodos)) {
                            foreach ($registrosNodos as $ndItem) {
                                $ndSw = strtolower($ndItem['switch_puerto'] ?? '');
                                if (!empty($ndSw) && (stripos($ndSw, strtolower($swLabel)) !== false || (isset($sw['id']) && stripos($ndSw, (string)$sw['id']) !== false))) {
                                    $nodosEnSwitch++;
                                }
                            }
                        }
                    ?>
                        <div class="col-12 col-md-6 col-lg-4 switch-card-item" 
                             data-switch-name="<?php echo htmlspecialchars(strtolower($swLabel)); ?>"
                             data-switch-ip="<?php echo htmlspecialchars(strtolower($swIp)); ?>"
                             data-switch-ubic="<?php echo htmlspecialchars(strtolower($swUbicacion)); ?>">
                            
                            <div class="card h-100 p-3.5 rounded-4 position-relative overflow-hidden" 
                                 style="background: linear-gradient(145deg, rgba(15, 23, 42, 0.95), rgba(16, 185, 129, 0.08)); border: 1.5px solid rgba(16, 185, 129, 0.35); box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
                                
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="rounded-3 d-flex align-items-center justify-content-center" 
                                             style="width: 48px; height: 48px; background: rgba(16, 185, 129, 0.15); border: 1.5px solid rgba(16, 185, 129, 0.5); box-shadow: 0 0 15px rgba(16, 185, 129, 0.25);">
                                            <i class="bi bi-hdd-network-fill text-success fs-4"></i>
                                        </div>
                                        <div>
                                            <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-0.5 rounded-pill font-monospace" style="font-size: 0.65rem;">
                                                ● ACTIVO ONLINE
                                            </span>
                                            <h6 class="fw-bold text-white mb-0 mt-1 font-monospace text-truncate" style="max-width: 200px;">
                                                <?php echo htmlspecialchars($swLabel); ?>
                                            </h6>
                                        </div>
                                    </div>
                                    <span class="badge bg-dark border border-secondary text-info font-monospace small">
                                        <?php echo htmlspecialchars($sw['tipo'] ?? 'Switch'); ?>
                                    </span>
                                </div>

                                <div class="p-2.5 rounded-3 mb-3" style="background: rgba(8, 19, 37, 0.7); border: 1px solid rgba(255,255,255,0.06); font-size: 0.75rem;">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-secondary"><i class="bi bi-geo-alt me-1 text-danger"></i> Ubicación:</span>
                                        <span class="text-white fw-semibold"><?php echo htmlspecialchars($swUbicacion); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-secondary"><i class="bi bi-globe me-1 text-info"></i> Dirección IP:</span>
                                        <span class="text-info font-monospace fw-bold"><?php echo htmlspecialchars($swIp); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-secondary"><i class="bi bi-box me-1 text-warning"></i> Modelo:</span>
                                        <span class="text-light text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($swSub); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-secondary"><i class="bi bi-cpu me-1 text-info"></i> Capacidad Puertos:</span>
                                        <span class="badge bg-info bg-opacity-25 text-info border border-info font-monospace"><?php echo intval($sw['cantidad_puertos'] ?? 48); ?> Puertos</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-secondary"><i class="bi bi-ethernet me-1 text-success"></i> Nodos Vinculados:</span>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success"><?php echo $nodosEnSwitch; ?> Nodos</span>
                                    </div>
                                </div>

                                <div class="mt-auto d-flex gap-2">
                                    <button type="button" class="btn btn-info btn-sm rounded-pill flex-grow-1 fw-bold text-dark shadow-sm" style="font-size: 0.72rem;" onclick='abrirVistaPuertosSwitch2D(<?php echo $swJsonAttr; ?>)'>
                                        <i class="bi bi-ethernet me-1"></i> Puertos
                                    </button>
                                    <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-bold" style="font-size: 0.72rem;" onclick='verDetalleSwitchFlotante(<?php echo $swJsonAttr; ?>)'>
                                        <i class="bi bi-card-heading me-1"></i> Ficha
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SCRIPT DE DATOS PARA JS -->
    <script>
        window.equiposInventarioMap = <?php echo json_encode($equiposInventario ?? []); ?>;
        window.registrosVlansMap = <?php echo json_encode($registrosVlans ?? []); ?>;
        window.registrosNodosMap = <?php echo json_encode($registrosNodos ?? []); ?>;
        window.registrosSwitchesMap = <?php echo json_encode($registrosSwitches ?? []); ?>;
    </script>

    <!-- ===================================================================== -->
    <!-- MODAL VISTA 2D PUERTOS DE SWITCH (INTERACTIVO, PUERTOS 16/24/48, LEDS) -->
    <!-- ===================================================================== -->
    <div class="modal fade" id="modalSwitch2DPuertos" tabindex="-1" aria-hidden="true" style="z-index: 1055;">
        <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1240px;">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: #091122; border: 1.5px solid rgba(56, 189, 248, 0.35) !important; border-radius: 16px; backdrop-filter: blur(16px);">
                
                <!-- HEADER DEL MODAL -->
                <div class="modal-header border-bottom border-secondary border-opacity-25 py-3 px-4" style="background: rgba(15, 23, 42, 0.95);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-flex align-items-center justify-content-center" 
                             style="width: 48px; height: 48px; background: rgba(56, 189, 248, 0.15); border: 1.5px solid #38bdf8; box-shadow: 0 0 15px rgba(56, 189, 248, 0.3);">
                            <i class="bi bi-hdd-network-fill text-info fs-4"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h5 class="modal-title fw-bold text-white mb-0 font-monospace" id="sw2d_titulo_nombre">Switch Core</h5>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-0.5 small" id="sw2d_badge_online">● ONLINE</span>
                                <span class="badge bg-dark border border-secondary text-info font-monospace small" id="sw2d_badge_puertos">48 Puertos</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 mt-1 text-secondary small font-monospace" style="font-size: 0.75rem;">
                                <span><i class="bi bi-globe me-1 text-info"></i> IP: <strong class="text-white" id="sw2d_ip_texto">192.168.1.1</strong></span>
                                <span><i class="bi bi-box me-1 text-warning"></i> Modelo: <strong class="text-white" id="sw2d_modelo_texto">Cisco Catalyst</strong></span>
                                <span><i class="bi bi-geo-alt me-1 text-danger"></i> <span id="sw2d_ubicacion_texto">SITE Principal</span></span>
                            </div>
                        </div>
                    </div>

                    <!-- FILTROS Y CONTROLES SUPERIORES -->
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-info active fw-bold" id="btnSwFilterTodos" onclick="filtrarPuertosVista2D('todos')">Todos</button>
                            <button type="button" class="btn btn-outline-success fw-bold" id="btnSwFilterOcupados" onclick="filtrarPuertosVista2D('ocupados')">Enlazados</button>
                            <button type="button" class="btn btn-outline-secondary fw-bold" id="btnSwFilterLibres" onclick="filtrarPuertosVista2D('libres')">Libres</button>
                        </div>
                        <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>

                <!-- CUERPO CON VISTA 2D DEL SWITCH -->
                <div class="modal-body p-4" style="overflow-x: auto; background: radial-gradient(circle at center, #0d1a33 0%, #060b17 100%);">
                    
                    <!-- BARRA DE GUÍA Y BÚSQUEDA -->
                    <div class="d-flex align-items-center justify-content-between mb-3 px-2">
                        <div class="d-flex align-items-center gap-3" style="font-size: 0.75rem;">
                            <span class="text-secondary"><i class="bi bi-info-circle text-info me-1"></i> Haz clic en cualquier puerto para <strong>asignar o liberar</strong> equipo de inventario.</span>
                            <span class="d-flex align-items-center gap-1.5"><span class="badge rounded-circle p-1" style="background: #10b981; box-shadow: 0 0 8px #10b981;">&nbsp;</span> <span class="text-success fw-semibold">Puerto Conectado</span></span>
                            <span class="d-flex align-items-center gap-1.5"><span class="badge rounded-circle p-1" style="background: #475569;">&nbsp;</span> <span class="text-secondary">Puerto Disponible</span></span>
                        </div>
                        <div class="position-relative" style="width: 220px;">
                            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2.5 text-secondary" style="font-size: 0.7rem;"></i>
                            <input type="text" id="inputBuscarPuertoEnSwitch2D" onkeyup="buscarEnPuertosSwitch2D(this.value)" class="form-control form-control-sm ps-4 bg-dark border-secondary text-white rounded-pill" style="font-size: 0.72rem;" placeholder="Buscar equipo o IP...">
                        </div>
                    </div>

                    <!-- CHASIS METÁLICO ALUMINIO ANODIZADO ESTILO UNIFI USW (IMAGEN 1) -->
                    <div id="sw2d_chassis_container" class="position-relative rounded-2 my-3 shadow-2xl" 
                         style="background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 25%, #cbd5e1 65%, #94a3b8 100%); border: 1.5px solid #94a3b8; box-shadow: 0 15px 35px rgba(0,0,0,0.6), inset 0 1px 0 #ffffff; min-width: 980px; padding: 6px 14px 10px 14px;">
                        
                        <!-- RANURAS DE VENTILACIÓN SUPERIORES (TOP SLITS) -->
                        <div class="sw-unifi-top-grooves d-flex align-items-center gap-3 px-3 mb-2 pt-1">
                            <div class="sw-unifi-groove flex-grow-1"></div>
                            <div class="sw-unifi-groove flex-grow-1"></div>
                            <div class="sw-unifi-groove flex-grow-1"></div>
                            <div class="sw-unifi-groove" style="width: 140px;"></div>
                            <div class="sw-unifi-groove" style="width: 80px;"></div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between px-2">
                            <!-- PANEL IZQUIERDO: PANTALLA TÁCTIL LCM 1.3" CON ICONOS & LOGO USW -->
                            <div class="d-flex flex-column align-items-center me-3" style="min-width: 72px;">
                                <div class="sw-unifi-lcm-screen shadow" title="Pantalla Táctil LCM 1.3&quot; de Estado">
                                    <div class="sw-lcm-content">
                                        <div class="sw-lcm-top-row">
                                            <i class="bi bi-hdd-network-fill text-info" style="font-size: 0.58rem;"></i>
                                            <i class="bi bi-activity text-success" style="font-size: 0.58rem;"></i>
                                        </div>
                                        <div class="sw-lcm-center-circle">
                                            <div class="sw-lcm-ring"></div>
                                            <i class="bi bi-power text-white" style="font-size: 0.65rem;"></i>
                                        </div>
                                        <div class="sw-lcm-bottom-row">
                                            <i class="bi bi-info-circle text-primary" style="font-size: 0.55rem;"></i>
                                            <i class="bi bi-cloud-check-fill text-info" style="font-size: 0.55rem;"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-1 d-flex align-items-center gap-1 font-monospace fw-bold" style="font-size: 0.68rem; color: #1e293b; letter-spacing: 0.5px;">
                                    <span id="sw2d_chassis_brand_text">USW</span>
                                    <i class="bi bi-lightning-charge-fill text-primary" style="font-size: 0.6rem;"></i>
                                </div>
                            </div>

                            <!-- CENTRO: BANCOS DE PUERTOS RJ45 SHIELDED EN 2 FILAS -->
                            <div id="sw2d_ports_grid_wrapper" class="flex-grow-1 d-flex align-items-center justify-content-center px-1 py-1">
                                <!-- Generado dinámicamente según cantidad de puertos (16, 24, 48) -->
                            </div>

                            <!-- PANEL DERECHO: SFP UPLINK & RESET -->
                            <div class="d-flex align-items-center gap-2 ms-3" style="min-width: 90px;">
                                <!-- Módulo SFP / SFP+ -->
                                <div id="sw2d_sfp_unifi_box" class="d-flex flex-column align-items-center">
                                    <!-- Cages SFP generados dinámicamente -->
                                </div>
                                <!-- Pinhole Reset -->
                                <div class="d-flex align-items-center gap-1 font-monospace ms-2" style="font-size: 0.55rem; color: #334155;">
                                    <span class="sw-unifi-reset-pin"></span>
                                    <strong style="letter-spacing: 0.5px;">RESET</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- EFECTO REFLEJO METÁLICO INFERIOR (COMO EN IMAGEN 1) -->
                    <div class="sw-unifi-mirror-reflection d-none d-md-block"></div>

                    <!-- METRICAS DE OCUPACIÓN Y RESUMEN INFERIOR -->
                    <div class="row g-3 mt-3">
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3 d-flex align-items-center justify-content-between" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255,255,255,0.06);">
                                <div>
                                    <span class="text-secondary micro d-block">Puertos Totales</span>
                                    <span class="fw-bold text-white fs-5 font-monospace" id="sw2d_stat_total">48</span>
                                </div>
                                <i class="bi bi-hdd-network text-info fs-3 opacity-75"></i>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3 d-flex align-items-center justify-content-between" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(16, 185, 129, 0.2);">
                                <div>
                                    <span class="text-secondary micro d-block">Puertos Enlazados</span>
                                    <span class="fw-bold text-success fs-5 font-monospace" id="sw2d_stat_ocupados">0</span>
                                </div>
                                <i class="bi bi-check-circle-fill text-success fs-3 opacity-75"></i>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3 d-flex align-items-center justify-content-between" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255,255,255,0.06);">
                                <div>
                                    <span class="text-secondary micro d-block">Puertos Disponibles</span>
                                    <span class="fw-bold text-secondary fs-5 font-monospace" id="sw2d_stat_libres">48</span>
                                </div>
                                <i class="bi bi-dash-circle text-secondary fs-3 opacity-75"></i>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-3 d-flex flex-column justify-content-center" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(56, 189, 248, 0.2);">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-secondary micro">Tasa de Ocupación</span>
                                    <span class="fw-bold text-info font-monospace small" id="sw2d_stat_porcentaje">0%</span>
                                </div>
                                <div class="progress" style="height: 6px; background: rgba(255,255,255,0.08);">
                                    <div id="sw2d_stat_progress_bar" class="progress-bar bg-info" style="width: 0%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TABLA / LISTADO RÁPIDO DE DISPOSITIVOS CONECTADOS -->
                    <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="fw-bold text-white mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-diagram-3 text-info"></i> Matriz de Enlaces del Switch
                            </h6>
                            <span class="text-secondary micro" id="sw2d_tabla_resumen_conteo">0 dispositivos conectados</span>
                        </div>
                        <div class="table-responsive rounded-3 border border-secondary border-opacity-25" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-dark table-hover table-sm mb-0 align-middle" style="font-size: 0.74rem;">
                                <thead>
                                    <tr class="table-active text-secondary font-monospace">
                                        <th class="ps-3" style="width: 80px;">Puerto</th>
                                        <th>Dispositivo Conectado</th>
                                        <th>Tipo / Categoría</th>
                                        <th>IP Asignada</th>
                                        <th>Nodo de Red</th>
                                        <th>VLAN</th>
                                        <th class="text-end pe-3">Acción</th>
                                    </tr>
                                </thead>
                                <tbody id="sw2d_tabla_enlaces_body">
                                    <tr>
                                        <td colspan="7" class="text-center py-3 text-secondary italic">No hay enlaces registrados en este switch aún. Haz clic en un puerto para asignar uno.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- FOOTER DEL MODAL -->
                <div class="modal-footer border-top border-secondary border-opacity-25 py-2.5 px-4 justify-content-between">
                    <div class="d-flex align-items-center gap-2 text-secondary small">
                        <i class="bi bi-shield-lock-fill text-success"></i>
                        <span>Monitoreo 2D de Puertos • Capa de Acceso L2</span>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cerrar Visualizador</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- MODAL ASIGNAR / LIBERAR EQUIPO EN PUERTO DE SWITCH                     -->
    <!-- ===================================================================== -->
    <div class="modal fade" id="modalAsignarPuertoSwitch2D" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: #0b1426; border: 1.5px solid #10b981 !important; border-radius: 14px;">
                <form id="formAsignarPuertoSwitch" onsubmit="guardarAsignacionPuertoSwitchSubmit(event)">
                    <input type="hidden" id="formSw_switch_key" value="">
                    <input type="hidden" id="formSw_switch_nombre" value="">
                    <input type="hidden" id="formSw_puerto_num" value="">
                    
                    <div class="modal-header border-bottom border-secondary border-opacity-25 py-3 px-3">
                        <div class="d-flex align-items-center gap-2.5">
                            <div class="rounded-3 d-flex align-items-center justify-content-center" 
                                 style="width: 40px; height: 40px; background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981;">
                                <i class="bi bi-ethernet text-success fs-5"></i>
                            </div>
                            <div>
                                <h6 class="modal-title fw-bold text-white mb-0" id="modalAsignarPuertoTitulo">Asignar Equipo al Puerto 1</h6>
                                <span class="text-info font-monospace small" id="modalAsignarPuertoSub">Switch Core</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-3.5">
                        <!-- Estado actual del puerto -->
                        <div id="panelPuertoEstadoActual" class="p-2.5 rounded-3 mb-3" style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255,255,255,0.08); font-size: 0.75rem;">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-secondary">Estatus Puerto:</span>
                                <span id="badgeEstadoPuertoActual" class="badge bg-secondary">Disponible</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-secondary">Equipo Conectado:</span>
                                <strong id="textoEquipoConectadoActual" class="text-white">Ninguno</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">IP del Dispositivo:</span>
                                <span id="textoIpConectadoActual" class="font-monospace text-info">--</span>
                            </div>
                        </div>

                        <!-- Selector de Equipo del Inventario -->
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                <i class="bi bi-pc-display me-1 text-info"></i> Seleccionar Equipo del Inventario *
                            </label>
                            <select id="selectEquipoParaPuerto" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="alSeleccionarEquipoParaPuerto(this.value)">
                                <option value="">-- Seleccionar Equipo o Dispositivo --</option>
                                <?php if (!empty($equiposInventario)): ?>
                                    <optgroup label="💻 Equipos de Cómputo VW">
                                        <?php foreach ($equiposInventario as $eq): ?>
                                            <?php if (($eq['origen'] ?? '') === 'inv_equipos_vw' || ($eq['origen'] ?? '') === 'equipos'): ?>
                                                <option value="<?php echo htmlspecialchars($eq['key']); ?>" 
                                                        data-label="<?php echo htmlspecialchars($eq['label']); ?>"
                                                        data-tipo="<?php echo htmlspecialchars($eq['tipo']); ?>"
                                                        data-ip="<?php echo htmlspecialchars($eq['ip'] ?? ''); ?>"
                                                        data-nodo="<?php echo htmlspecialchars($eq['nodo'] ?? ($eq['raw']['numero_nodo'] ?? '')); ?>"
                                                        data-ubic="<?php echo htmlspecialchars($eq['ubicacion'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($eq['label'] . ' (' . $eq['tipo'] . ' - IP: ' . $eq['ip'] . ')'); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="🏢 Equipos Corporativo">
                                        <?php foreach ($equiposInventario as $eq): ?>
                                            <?php if (($eq['origen'] ?? '') === 'inv_equipos_corp'): ?>
                                                <option value="<?php echo htmlspecialchars($eq['key']); ?>" 
                                                        data-label="<?php echo htmlspecialchars($eq['label']); ?>"
                                                        data-tipo="<?php echo htmlspecialchars($eq['tipo']); ?>"
                                                        data-ip="<?php echo htmlspecialchars($eq['ip'] ?? ''); ?>"
                                                        data-nodo="<?php echo htmlspecialchars($eq['nodo'] ?? ($eq['raw']['numero_nodo'] ?? '')); ?>"
                                                        data-ubic="<?php echo htmlspecialchars($eq['ubicacion'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($eq['label'] . ' (' . $eq['tipo'] . ' - IP: ' . $eq['ip'] . ')'); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="🖨️ Impresoras de Red">
                                        <?php foreach ($equiposInventario as $eq): ?>
                                            <?php if (($eq['origen'] ?? '') === 'inv_monitores'): ?>
                                                <option value="<?php echo htmlspecialchars($eq['key']); ?>"
                                                        data-label="<?php echo htmlspecialchars($eq['label']); ?>"
                                                        data-tipo="<?php echo htmlspecialchars($eq['tipo']); ?>"
                                                        data-ip="<?php echo htmlspecialchars($eq['ip'] ?? ''); ?>"
                                                        data-nodo="<?php echo htmlspecialchars($eq['nodo'] ?? ($eq['raw']['numero_nodo'] ?? '')); ?>"
                                                        data-ubic="<?php echo htmlspecialchars($eq['ubicacion'] ?? ''); ?>">
                                                    🖨️ <?php echo htmlspecialchars($eq['label'] . ' - IP: ' . $eq['ip']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="📞 Teléfonos IP PoE">
                                        <?php foreach ($equiposInventario as $eq): ?>
                                            <?php if (($eq['origen'] ?? '') === 'inv_telefonos_poe'): ?>
                                                <option value="<?php echo htmlspecialchars($eq['key']); ?>"
                                                        data-label="<?php echo htmlspecialchars($eq['label']); ?>"
                                                        data-tipo="<?php echo htmlspecialchars($eq['tipo']); ?>"
                                                        data-ip="<?php echo htmlspecialchars($eq['ip'] ?? ''); ?>"
                                                        data-nodo="<?php echo htmlspecialchars($eq['nodo'] ?? ($eq['raw']['numero_nodo'] ?? '')); ?>"
                                                        data-ubic="<?php echo htmlspecialchars($eq['ubicacion'] ?? ''); ?>">
                                                    📞 <?php echo htmlspecialchars($eq['label'] . ' - IP: ' . $eq['ip']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="🖧 Servidores & SITE Core">
                                        <?php foreach ($equiposInventario as $eq): ?>
                                            <?php if (($eq['origen'] ?? '') === 'inv_site_vw'): ?>
                                                <option value="<?php echo htmlspecialchars($eq['key']); ?>"
                                                        data-label="<?php echo htmlspecialchars($eq['label']); ?>"
                                                        data-tipo="<?php echo htmlspecialchars($eq['tipo']); ?>"
                                                        data-ip="<?php echo htmlspecialchars($eq['ip'] ?? ''); ?>"
                                                        data-nodo="<?php echo htmlspecialchars($eq['nodo'] ?? ($eq['raw']['numero_nodo'] ?? '')); ?>"
                                                        data-ubic="<?php echo htmlspecialchars($eq['ubicacion'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($eq['label'] . ' (' . $eq['tipo'] . ' - IP: ' . $eq['ip'] . ')'); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <option value="__custom__">✏️ Ingresar Equipo / Dispositivo Manualmente...</option>
                            </select>
                        </div>

                        <!-- Campos manuales si se requiere -->
                        <div id="boxCustomEquipoPuerto" style="display: none;">
                            <div class="row g-2 mb-3">
                                <div class="col-8">
                                    <label class="form-label micro text-secondary mb-1">Nombre / Hostname</label>
                                    <input type="text" id="inputCustomNombreEquipoPuerto" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="Ej. Impresora HP Ventas">
                                </div>
                                <div class="col-4">
                                    <label class="form-label micro text-secondary mb-1">Tipo</label>
                                    <input type="text" id="inputCustomTipoEquipoPuerto" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="Impresora">
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-secondary mb-1"><i class="bi bi-globe me-1 text-info"></i> Dirección IP</label>
                                <input type="text" id="inputIpEquipoPuerto" class="form-control form-control-sm bg-dark text-white font-monospace border-secondary" placeholder="192.168.1.50">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-secondary mb-1"><i class="bi bi-diagram-3 me-1 text-warning"></i> VLAN Asignada</label>
                                <select id="selectVlanParaPuerto" class="form-select form-select-sm bg-dark text-white border-secondary">
                                    <option value="">-- Sin VLAN --</option>
                                    <?php if (!empty($registrosVlans)): ?>
                                        <?php foreach ($registrosVlans as $vl): ?>
                                            <option value="<?php echo htmlspecialchars('VLAN ' . $vl['vlan_id'] . ' (' . $vl['nombre_vlan'] . ')'); ?>">
                                                VLAN <?php echo htmlspecialchars($vl['vlan_id'] . ' - ' . $vl['nombre_vlan']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary mb-1"><i class="bi bi-geo-alt me-1 text-danger"></i> Nodo de Red Vinculado</label>
                            <select id="selectNodoParaPuerto" class="form-select form-select-sm bg-dark text-white border-secondary">
                                <option value="">-- Sin Nodo de Red Vinculado --</option>
                                <?php if (!empty($registrosNodos)): ?>
                                    <?php foreach ($registrosNodos as $nd): ?>
                                        <option value="<?php echo htmlspecialchars($nd['codigo_nodo']); ?>">
                                            Nodo <?php echo htmlspecialchars($nd['codigo_nodo'] . ' (' . ($nd['ubicacion'] ?: 'Sin Ubicación') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Pregunta: ¿Tiene Teléfono IP PoE en cascada en este puerto? -->
                        <div class="p-2.5 rounded-3 mb-3" style="background: rgba(15, 23, 42, 0.8); border: 1.5px solid rgba(234, 179, 8, 0.4);">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <div>
                                    <label class="form-label small fw-bold text-white mb-0">
                                        <i class="bi bi-telephone-plus-fill text-warning me-1"></i> ¿Tiene Teléfono IP PoE en cascada en este puerto?
                                    </label>
                                    <small class="text-secondary d-block" style="font-size: 0.72rem;">
                                        Permite conectar una computadora y un teléfono IP PoE en serie compartiendo este puerto físico del switch.
                                    </small>
                                </div>
                                <div class="form-check form-switch fs-5 mb-0">
                                    <input class="form-check-input" type="checkbox" id="check_puerto_tiene_telefono" onchange="togglePuertoTelefonoSection(this.checked)">
                                </div>
                            </div>
                            <div id="box_puerto_telefono_select" style="display: none;" class="mt-2 pt-2 border-top border-secondary border-opacity-25">
                                <label class="form-label micro text-warning mb-1">Teléfono IP PoE conectado en cascada:</label>
                                <select id="selectTelefonoParaPuertoCascada" class="form-select form-select-sm bg-dark text-white border-warning border-opacity-50">
                                    <option value="">-- Seleccionar Teléfono PoE --</option>
                                    <?php if (!empty($telefonosPoeDisponibles)): ?>
                                        <?php foreach ($telefonosPoeDisponibles as $tOpt): ?>
                                            <option value="<?php echo $tOpt['id']; ?>">
                                                📞 Ext. <?php echo htmlspecialchars($tOpt['extension'] ?: 'S/E'); ?> - <?php echo htmlspecialchars($tOpt['usuario'] ?: 'Sin Asignar'); ?> (IP: <?php echo htmlspecialchars($tOpt['ip'] ?: 'Sin IP'); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-semibold text-secondary mb-1">Notas / Observaciones</label>
                            <input type="text" id="inputNotasPuerto" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="Ej. Patch cord azul Cat6, roseta piso 1...">
                        </div>
                    </div>

                    <div class="modal-footer border-top border-secondary border-opacity-25 py-2 px-3 justify-content-between">
                        <button type="button" id="btnLiberarPuertoModal" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="liberarPuertoSwitchActual()" style="display: none;">
                            <i class="bi bi-link-45deg me-1"></i> Desconectar / Liberar
                        </button>
                        <div class="d-flex gap-2 ms-auto">
                            <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-success btn-sm rounded-pill px-4 fw-bold shadow">
                                <i class="bi bi-check-circle-fill me-1"></i> Guardar Enlace
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ESTILOS CSS UNIFI USW SILVER METALLIC (ESTILO IMAGEN 1) -->
    <style>
    .sw-unifi-top-grooves {
        height: 6px;
        width: 100%;
    }
    .sw-unifi-groove {
        height: 3px;
        background: #0f172a;
        border-radius: 2px;
        box-shadow: inset 0 1px 2px rgba(0,0,0,0.9), 0 1px 0 rgba(255,255,255,0.7);
    }

    /* Pantalla táctil LCM 1.3" */
    .sw-unifi-lcm-screen {
        width: 52px;
        height: 52px;
        background: radial-gradient(circle at center, #0f172a 0%, #020617 100%);
        border: 2px solid #1e293b;
        border-radius: 8px;
        box-shadow: inset 0 0 10px rgba(0,0,0,0.95), 0 2px 5px rgba(0,0,0,0.35);
        padding: 3px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sw-lcm-content {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: center;
    }
    .sw-lcm-top-row, .sw-lcm-bottom-row {
        width: 100%;
        display: flex;
        justify-content: space-between;
        padding: 0 2px;
    }
    .sw-lcm-center-circle {
        position: relative;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #020617;
        border: 1.5px solid #38bdf8;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 0 8px rgba(56, 189, 248, 0.4);
    }
    .sw-lcm-ring {
        position: absolute;
        inset: -3px;
        border-radius: 50%;
        border: 1px dashed rgba(56, 189, 248, 0.4);
        animation: swLcmSpin 14s linear infinite;
    }
    @keyframes swLcmSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    /* Marco metálico Shielded de los bancos de puertos */
    .sw-unifi-port-bank {
        background: #020617;
        border: 2px solid #94a3b8;
        border-radius: 5px;
        padding: 2px 4px;
        margin: 0 4px;
        box-shadow: inset 0 2px 5px rgba(0,0,0,0.9), 0 1px 0 rgba(255,255,255,0.9);
        position: relative;
    }

    /* Columna de puertos (Top / Bottom) */
    .sw-unifi-port-col {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 0 1.5px;
    }

    /* Números y badges de puertos en el chasis */
    .sw-unifi-port-num-label {
        font-size: 0.56rem;
        font-family: 'SF Mono', Menlo, Consolas, monospace;
        font-weight: 800;
        color: #1e293b;
        line-height: 1;
        margin-bottom: 2px;
        display: flex;
        align-items: center;
        gap: 1px;
    }
    .sw-unifi-port-num-label-bottom {
        margin-bottom: 0;
        margin-top: 2px;
    }
    .sw-unifi-poe-icon {
        font-size: 0.5rem;
        color: #f59e0b;
    }

    /* Contenedor del Jack RJ45 */
    .sw-unifi-rj45-box {
        width: 27px;
        height: 25px;
        background: #000000;
        border: 1px solid #334155;
        border-radius: 3px;
        position: relative;
        cursor: pointer;
        transition: all 0.15s ease;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 1.5px 2px;
    }
    .sw-unifi-rj45-box:hover {
        border-color: #0284c7 !important;
        transform: translateY(-2px) scale(1.08);
        box-shadow: 0 0 12px rgba(2, 132, 199, 0.8) !important;
        z-index: 20;
    }
    .sw-unifi-rj45-box.port-active {
        border-color: #10b981;
        background: #031410;
    }
    .sw-unifi-rj45-box.port-highlight {
        border-color: #f59e0b !important;
        box-shadow: 0 0 14px #f59e0b !important;
        transform: scale(1.12);
    }
    .sw-unifi-rj45-box.port-dimmed {
        opacity: 0.25;
        filter: grayscale(80%);
    }

    /* Cavidad del Jack con pines dorados */
    .sw-unifi-jack-cavity {
        width: 100%;
        height: 14px;
        background: #050a14;
        border: 1px solid #1e293b;
        border-radius: 2px;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sw-unifi-jack-pins {
        position: absolute;
        top: 1px;
        left: 2px;
        right: 2px;
        height: 3px;
        background: repeating-linear-gradient(90deg, #d97706, #d97706 1px, transparent 1px, transparent 2.2px);
    }
    .sw-unifi-jack-tab {
        position: absolute;
        bottom: 0px;
        width: 8px;
        height: 3px;
        background: #000;
        border-top: 1px solid #334155;
    }

    /* LEDs duales de UniFi (PoE Amber a la izquierda, Link Green a la derecha) */
    .sw-unifi-leds-bar {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 1px;
    }
    .sw-unifi-led {
        width: 4px;
        height: 3px;
        border-radius: 1px;
        background: #1e293b;
    }
    .sw-unifi-led-poe-active {
        background: #f59e0b;
        box-shadow: 0 0 5px #f59e0b;
    }
    .sw-unifi-led-link-active {
        background: #10b981;
        box-shadow: 0 0 6px #10b981, 0 0 10px rgba(16, 185, 129, 0.8);
    }

    /* Jaula metálica SFP / SFP+ */
    .sw-unifi-sfp-cage {
        width: 28px;
        background: #020617;
        border: 1.5px solid #94a3b8;
        border-radius: 3px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: space-between;
        padding: 2px 1px;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.9), 0 1px 0 rgba(255,255,255,0.8);
    }
    .sw-unifi-sfp-slot {
        width: 20px;
        height: 18px;
        background: #000;
        border: 1px solid #334155;
        border-radius: 2px;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sw-unifi-sfp-num {
        font-size: 0.55rem;
        font-family: monospace;
        font-weight: 800;
        color: #1e293b;
        line-height: 1;
    }

    /* Botón reset y reflejo inferior */
    .sw-unifi-reset-pin {
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #0f172a;
        border: 1px solid #475569;
        box-shadow: inset 0 1px 1px rgba(0,0,0,0.9);
        display: inline-block;
    }
    .sw-unifi-mirror-reflection {
        height: 35px;
        background: linear-gradient(180deg, rgba(226, 232, 240, 0.28) 0%, rgba(203, 213, 225, 0.08) 50%, transparent 100%);
        filter: blur(1.5px);
        margin-top: -8px;
        border-radius: 4px;
        transform: scaleY(-1);
        opacity: 0.45;
    }
    </style>

<?php endif; ?>

