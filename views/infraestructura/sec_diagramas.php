<!-- CONTENIDO SECCIÓN 1: MAPEO E INTERFAZ 2D DE AGENCIA -->
<?php if ($seccion_activa === 'diagramas'): ?>

    <!-- PANTALLA PRINCIPAL CON LISTA DE PLANOS Y SUS 3 BOTONES -->
    <div id="pantallaInicioDiagramas" class="card-custom mb-4 p-4 border-start border-4 border-info shadow-lg" style="<?php echo (isset($_GET['modo']) && in_array($_GET['modo'], ['2d','3d','configurar'])) ? 'display: none;' : 'display: block;'; ?>">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary border-opacity-25 gap-3">
            <div>
                <h3 class="fw-bold text-white mb-1">
                    <i class="bi bi-diagram-3-fill text-info me-2 fs-2 align-middle"></i> Mis Diagramas y Planos de Agencia
                </h3>
                <p class="text-secondary small mb-0">Selecciona el plano con el que deseas trabajar e ingresa directamente a su vista 2D, recorrido 3D o panel de configuración.</p>
            </div>
            <button type="button" class="btn btn-success rounded-3 fw-bold shadow-sm px-3 py-2" onclick="crearNuevoPlanoModal()">
                <i class="bi bi-plus-lg me-1"></i> Crear Nuevo Diagrama / Plano
            </button>
        </div>

        <?php if (empty($planos2D)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="bi bi-bounding-box-circles display-1 d-block mb-3 opacity-50 text-info"></i>
                <h5 class="fw-bold text-white">No hay diagramas o planos registrados</h5>
                <p class="small text-secondary mb-3">Crea tu primer diagrama de agencia para posicionar paredes, cuartos, gabinetes y equipos de inventario.</p>
                <button type="button" class="btn btn-primary rounded-3 fw-bold px-4" onclick="crearNuevoPlanoModal()">
                    <i class="bi bi-plus-lg me-1"></i> Crear Primer Plano
                </button>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-4">
                <?php foreach ($planos2D as $pItem): 
                    $numElem = 0;
                    if (!empty($pItem['elementos_json'])) {
                        $arrEl = json_decode($pItem['elementos_json'], true);
                        if (is_array($arrEl)) $numElem = count($arrEl);
                    }
                    $esActual = ($planoActual && $planoActual['id'] == $pItem['id']);
                ?>
                    <div class="p-3 rounded-4 border <?php echo $esActual ? 'border-info bg-dark bg-opacity-75' : 'border-secondary border-opacity-30 bg-dark bg-opacity-40'; ?> shadow-sm">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25 gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-info bg-opacity-25 text-info p-2 rounded-3 fs-5">📐</span>
                                <div>
                                    <h5 class="fw-bold text-white mb-0"><?php echo htmlspecialchars($pItem['nombre_plano']); ?></h5>
                                    <span class="text-secondary font-monospace" style="font-size: 0.75rem;">
                                        ID #<?php echo $pItem['id']; ?> | <?php echo $numElem; ?> componentes mapeados
                                        <?php if ($esActual): ?>
                                            <span class="badge bg-success bg-opacity-25 text-success ms-2">Seleccionado activo</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>

                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Estás seguro de que deseas eliminar permanentemente el plano \'<?php echo addslashes(htmlspecialchars($pItem['nombre_plano'])); ?>\' y todo su contenido?');">
                                <input type="hidden" name="accion" value="eliminar_plano_2d">
                                <input type="hidden" name="plano_id" value="<?php echo $pItem['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-3 px-3 fw-bold d-flex align-items-center gap-1 shadow-sm" title="Eliminar este plano">
                                    <i class="bi bi-trash-fill"></i> Eliminar Plano
                                </button>
                            </form>
                        </div>

                        <div class="row g-3">
                            <!-- BOTÓN 1: VER PLANO 2D -->
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-primary btn-hero-diagrama w-100 p-3 rounded-4 text-center d-flex flex-column align-items-center justify-content-center h-100 border-2" onclick="abrirDiagramaModo(<?php echo $pItem['id']; ?>, '2d')" style="background: linear-gradient(145deg, rgba(14, 165, 233, 0.12), rgba(15, 23, 42, 0.8)); min-height: 150px; transition: all 0.25s ease;">
                                    <div class="mb-2 text-info"><i class="bi bi-aspect-ratio fs-2"></i></div>
                                    <h5 class="fw-bold text-white mb-1">VER PLANO 2D</h5>
                                    <p class="text-light small mb-0 opacity-75" style="font-size: 0.78rem;">Lienzo interactivo plano, zonas, nodos e inventario.</p>
                                </button>
                            </div>

                            <!-- BOTÓN 2: VER PLANO EN 3D -->
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-info btn-hero-diagrama w-100 p-3 rounded-4 text-center d-flex flex-column align-items-center justify-content-center h-100 border-2" onclick="abrirDiagramaModo(<?php echo $pItem['id']; ?>, '3d')" style="background: linear-gradient(145deg, rgba(6, 182, 212, 0.12), rgba(15, 23, 42, 0.8)); min-height: 150px; transition: all 0.25s ease;">
                                    <div class="mb-2 text-info"><i class="bi bi-box-seam-fill fs-2"></i></div>
                                    <h5 class="fw-bold text-white mb-1">VER PLANO EN 3D</h5>
                                    <p class="text-light small mb-0 opacity-75" style="font-size: 0.78rem;">Recorrido 3D WebGL interactivo en 1ª Persona.</p>
                                </button>
                            </div>

                            <!-- BOTÓN 3: CONFIGURACIÓN -->
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-warning btn-hero-diagrama w-100 p-3 rounded-4 text-center d-flex flex-column align-items-center justify-content-center h-100 border-2" onclick="abrirDiagramaModo(<?php echo $pItem['id']; ?>, 'configurar')" style="background: linear-gradient(145deg, rgba(245, 158, 11, 0.12), rgba(15, 23, 42, 0.8)); min-height: 150px; transition: all 0.25s ease;">
                                    <span class="fs-2 mb-2">⚙️</span>
                                    <h5 class="fw-bold text-warning mb-1">CONFIGURACIÓN</h5>
                                    <p class="text-light small mb-0 opacity-75" style="font-size: 0.78rem;">Barra de herramientas, paredes, equipos y edición.</p>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- SECCIÓN DE TRABAJO DEL PLANO (OCULTA POR DEFECTO EN INICIO) -->
    <div id="seccionWorkspacePlano" style="<?php echo (isset($_GET['modo']) && in_array($_GET['modo'], ['2d','3d','configurar'])) ? 'display: block;' : 'display: none;'; ?>">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <button type="button" class="btn btn-outline-light btn-sm rounded-3 fw-bold" onclick="activarModoNavegacion('inicio')">
                <i class="bi bi-arrow-left me-1"></i> ⬅️ Volver a Lista de Planos
            </button>
            <span class="badge bg-dark border border-secondary text-info px-3 py-1">
                📐 Plano Activo: <b><?php echo htmlspecialchars($planoActual['nombre_plano'] ?? 'Plano Agencia'); ?></b>
            </span>
        </div>

        <!-- BARRA SUPERIOR DE HERRAMIENTAS Y SELECCIÓN DE PLANO 2D -->
        <div class="card-custom mb-3 p-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-white small me-1"><i class="bi bi-layers-fill text-success me-1"></i> Plano Activo:</label>
                    <select id="selectPlanoId" class="form-select form-select-sm bg-dark text-white border-secondary" style="min-width: 220px;" onchange="location.href='infraestructura.php?sec=diagramas&plano_id=' + this.value;">
                        <?php if (empty($planos2D)): ?>
                            <option value="0">-- Crear Primer Plano 2D --</option>
                        <?php else: ?>
                            <?php foreach ($planos2D as $pItem): ?>
                                <option value="<?php echo $pItem['id']; ?>" <?php echo ($planoActual && $planoActual['id'] == $pItem['id']) ? 'selected' : ''; ?>>
                                    📐 <?php echo htmlspecialchars($pItem['nombre_plano']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <button type="button" class="tool-btn" id="btnNuevoPlanoModal" onclick="crearNuevoPlanoModal()">
                        <i class="bi bi-file-earmark-plus-fill text-success"></i> Nuevo Plano
                    </button>
                </div>

                <?php if ($planoActual): ?>
                    <div class="d-flex align-items-center gap-2" id="toolbarEditarPlano">
                        <button type="button" class="tool-btn" onclick="document.getElementById('inputFondoImage').click()">
                            <i class="bi bi-image-fill text-info"></i> Subir Fondo Plano
                        </button>
                        <input type="file" id="inputFondoImage" class="d-none" accept="image/*" onchange="cargarImagenFondoCanvas(this)">

                        <button type="button" class="btn btn-success btn-sm rounded-3 px-3 fw-bold shadow" onclick="guardarPlano2DEnServidor()">
                            <i class="bi bi-floppy-fill me-1"></i> Guardar Plano 2D
                        </button>

                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Seguro que deseas eliminar este plano 2D completo?');">
                            <input type="hidden" name="accion" value="eliminar_plano_2d">
                            <input type="hidden" name="plano_id" value="<?php echo $planoActual['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-3" title="Eliminar este plano 2D">
                                <i class="bi bi-trash-fill"></i>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$planoActual): ?>
            <div class="card-custom text-center py-5">
                <i class="bi bi-bounding-box-circles fs-1 text-success d-block mb-3 opacity-75"></i>
                <h4 class="fw-bold text-white">Comienza a mapear la agencia en 2D</h4>
                <p class="text-secondary small mb-4">Crea tu primer plano para colocar paredes, salas, oficinas y ubicar los equipos del inventario, nodos, APs y cámaras.</p>
                <button type="button" class="btn btn-success rounded-3 px-4 fw-bold" onclick="crearNuevoPlanoModal()">
                    <i class="bi bi-plus-lg me-1"></i> Crear Primer Plano 2D
                </button>
            </div>
        <?php else: ?>

            <div class="row g-3">
                <!-- PALETA IZQUIERDA DE COMPONENTES E INVENTARIO -->
                <div class="col-lg-3" id="colSidebarPalette">
                    <div class="card-custom h-100 p-3 sidebar-palette-container futuristic-palette">
                        
                        <!-- ENCABEZADO FUTURISTA DE PALETA -->
                        <div class="d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom border-secondary border-opacity-25">
                            <h6 class="fw-bold text-white mb-0 d-flex align-items-center gap-2" style="font-size: 0.88rem;">
                                <span class="p-1.5 rounded-2 bg-info bg-opacity-25 text-info">
                                    <i class="bi bi-cpu-fill"></i>
                                </span>
                                <span>Paleta de Herramientas</span>
                            </h6>
                            <span class="badge bg-primary bg-opacity-25 text-info font-monospace" style="font-size: 0.65rem;">HUD 2D/3D</span>
                        </div>

                        <div class="accordion d-flex flex-column gap-2" id="accordionPaletaHerramientas">
                            
                            <!-- COMBO 1: FIGURAS Y ESTRUCTURA PLANO -->
                            <div class="futuristic-accordion-item">
                                <button class="futuristic-accordion-btn" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFiguras" aria-expanded="true" aria-controls="collapseFiguras">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-bounding-box text-info"></i>
                                        <span>CONSTRUCCIÓN Y PAREDES</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span class="badge bg-dark text-info border border-secondary border-opacity-30" style="font-size: 0.62rem;">6</span>
                                        <i class="bi bi-chevron-down chevron-icon small text-secondary"></i>
                                    </span>
                                </button>
                                <div id="collapseFiguras" class="collapse show" data-bs-parent="#accordionPaletaHerramientas">
                                    <div class="p-2">
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'figura', 'rect')" onclick="agregarFiguraCanvas('rect')">
                                                    <i class="bi bi-square-fill text-primary"></i> Cuadrado
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'figura', 'polygon')" onclick="agregarFiguraCanvas('polygon')">
                                                    <i class="bi bi-pentagon-fill" style="color: #c084fc;"></i> Cuarto L / T
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'figura', 'triangle')" onclick="agregarFiguraCanvas('triangle')">
                                                    <i class="bi bi-triangle-fill text-warning"></i> Triángulo
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'figura', 'circle')" onclick="agregarFiguraCanvas('circle')">
                                                    <i class="bi bi-circle-fill text-success"></i> Círculo / Zona
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'figura', 'line')" onclick="agregarFiguraCanvas('line')">
                                                    <i class="bi bi-dash-lg text-info"></i> Línea
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'figura', 'line')" onclick="agregarFiguraCanvas('line')">
                                                    <i class="bi bi-border-width text-info"></i> Pared / Muro
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- COMBO 2: INFRAESTRUCTURA Y EQUIPOS DE RED -->
                            <div class="futuristic-accordion-item">
                                <button class="futuristic-accordion-btn" type="button" data-bs-toggle="collapse" data-bs-target="#collapseRed" aria-expanded="true" aria-controls="collapseRed">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-router-fill text-success"></i>
                                        <span>INFRAESTRUCTURA DE RED</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span class="badge bg-dark text-success border border-secondary border-opacity-30" style="font-size: 0.62rem;">8</span>
                                        <i class="bi bi-chevron-down chevron-icon small text-secondary"></i>
                                    </span>
                                </button>
                                <div id="collapseRed" class="collapse show" data-bs-parent="#accordionPaletaHerramientas">
                                    <div class="p-2">
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'nodo')" onclick="agregarElementoCanvas('nodo')">
                                                    <i class="bi bi-ethernet text-info"></i> Nodo Red
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'pc')" onclick="agregarElementoCanvas('pc')">
                                                    <i class="bi bi-pc-display-horizontal text-primary"></i> PC / Comp
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'ap')" onclick="agregarElementoCanvas('ap')">
                                                    <i class="bi bi-wifi text-info"></i> AP Wi-Fi
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'camara')" onclick="agregarElementoCanvas('camara')">
                                                    <i class="bi bi-camera-fill text-warning"></i> Cámara CCTV
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'site')" onclick="agregarElementoCanvas('site')">
                                                    <i class="bi bi-hdd-rack-fill text-success"></i> SITE / Rack
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'impresora')" onclick="agregarElementoCanvas('impresora')">
                                                    <i class="bi bi-printer-fill" style="color: #c084fc;"></i> Impresora
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'telefono')" onclick="agregarElementoCanvas('telefono')">
                                                    <i class="bi bi-telephone-fill" style="color: #06b6d4;"></i> Teléfono IP
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'pantalla')" onclick="agregarElementoCanvas('pantalla')">
                                                    <i class="bi bi-tv-fill text-info"></i> Monitor TV
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- COMBO 3: ARQUITECTURA & MOBILIARIO CAD -->
                            <div class="futuristic-accordion-item">
                                <button class="futuristic-accordion-btn collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMobiliario" aria-expanded="false" aria-controls="collapseMobiliario">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-layers-half text-warning"></i>
                                        <span>ARQUITECTURA & MOBILIARIO</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span class="badge bg-dark text-warning border border-secondary border-opacity-30" style="font-size: 0.62rem;">14</span>
                                        <i class="bi bi-chevron-down chevron-icon small text-secondary"></i>
                                    </span>
                                </button>
                                <div id="collapseMobiliario" class="collapse" data-bs-parent="#accordionPaletaHerramientas">
                                    <div class="p-2">
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'puerta')" onclick="agregarElementoCanvas('puerta')">
                                                    <i class="bi bi-door-open-fill text-danger"></i> Puerta 2D
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'ventana')" onclick="agregarElementoCanvas('ventana')">
                                                    <i class="bi bi-distribute-horizontal text-secondary"></i> Ventana
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'escalera_recta')" onclick="agregarElementoCanvas('escalera_recta')">
                                                    <i class="bi bi-layers-half text-warning"></i> Esc. Recta
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'escalera_l')" onclick="agregarElementoCanvas('escalera_l')">
                                                    <i class="bi bi-diagram-3-fill text-warning"></i> Esc. en L
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'escalera_espiral')" onclick="agregarElementoCanvas('escalera_espiral')">
                                                    <i class="bi bi-arrow-repeat text-warning"></i> Esc. Espiral
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'escritorio')" onclick="agregarElementoCanvas('escritorio')">
                                                    <i class="bi bi-display text-warning"></i> Escritorio
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'sofa')" onclick="agregarElementoCanvas('sofa')">
                                                    <i class="bi bi-square-half text-secondary"></i> Sofá / Sillón
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'comedor')" onclick="agregarElementoCanvas('comedor')">
                                                    <i class="bi bi-grid-3x3-gap text-secondary"></i> Comedor
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'cama')" onclick="agregarElementoCanvas('cama')">
                                                    <i class="bi bi-border-outer text-secondary"></i> Cama
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'bano')" onclick="agregarElementoCanvas('bano')">
                                                    <i class="bi bi-droplet-half text-info"></i> Sanitario WC
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'carro')" onclick="agregarElementoCanvas('carro')">
                                                    <i class="bi bi-car-front-fill text-secondary"></i> Auto / Carro
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'planta')" onclick="agregarElementoCanvas('planta')">
                                                    <i class="bi bi-flower1 text-success"></i> Planta CAD
                                                </button>
                                            </div>
                                            <div class="col-12">
                                                <button type="button" class="futuristic-tool-btn" draggable="true" ondragstart="iniciarDragPalette(event, 'elemento', 'cuadro_imagen')" onclick="agregarElementoCanvas('cuadro_imagen')">
                                                    <i class="bi bi-image-fill" style="color: #ec4899;"></i> Cuadro Pared / Imagen
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- COMBO 4: INVENTARIO REAL REGISTRADO -->
                            <div class="futuristic-accordion-item">
                                <button class="futuristic-accordion-btn collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseInventario" aria-expanded="false" aria-controls="collapseInventario">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-database-fill-gear text-primary"></i>
                                        <span>INVENTARIO DE EQUIPOS</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span class="badge bg-dark text-primary border border-secondary border-opacity-30" style="font-size: 0.62rem;"><?php echo count($equiposInventario); ?></span>
                                        <i class="bi bi-chevron-down chevron-icon small text-secondary"></i>
                                    </span>
                                </button>
                                <div id="collapseInventario" class="collapse" data-bs-parent="#accordionPaletaHerramientas">
                                    <div class="p-2">
                                        <select id="selectEquipoInventario" class="form-select form-select-sm bg-dark text-white border-secondary mb-2" onchange="actualizarEstadoBotonesInventario()">
                                            <option value="">-- Seleccionar Equipo del Inventario --</option>
                                            <?php foreach ($equiposInventario as $eq): ?>
                                                <option value="<?php echo htmlspecialchars(json_encode($eq)); ?>">
                                                    [<?php echo $eq['tipo']; ?>] <?php echo htmlspecialchars($eq['label']); ?> (IP: <?php echo htmlspecialchars($eq['ip']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <div class="d-grid gap-1.5" id="groupBotonesVincularInventario">
                                            <button type="button" id="btnVincularElementoSeleccionado" class="btn btn-warning btn-sm w-100 rounded-3 fw-bold shadow-sm mb-1" onclick="vincularEquipoInventarioAElementoSeleccionado()" style="display: none; background: #f59e0b; color: #0f172a;">
                                                <i class="bi bi-link-45deg me-1"></i> Vincular y Ver Ficha Técnica
                                            </button>
                                            <button type="button" id="btnAgregarNuevoEquipoInventario" class="btn btn-primary btn-sm w-100 rounded-3 fw-semibold" draggable="true" ondragstart="iniciarDragInventario(event)" onclick="agregarEquipoInventarioAlCanvas()">
                                                <i class="bi bi-plus-circle me-1"></i> Agregar al Plano 2D/3D
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- COMBO 5: COMPONENTES COLOCADOS EN EL PLANO -->
                            <div class="futuristic-accordion-item">
                                <button class="futuristic-accordion-btn" type="button" data-bs-toggle="collapse" data-bs-target="#collapseComponentesPlano" aria-expanded="true" aria-controls="collapseComponentesPlano">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-list-task text-warning"></i>
                                        <span>COMPONENTES EN PLANO</span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span class="badge bg-dark text-warning border border-secondary border-opacity-30" style="font-size: 0.62rem;" id="countElementosLista">0</span>
                                        <i class="bi bi-chevron-down chevron-icon small text-secondary"></i>
                                    </span>
                                </button>
                                <div id="collapseComponentesPlano" class="collapse show" data-bs-parent="#accordionPaletaHerramientas">
                                    <div class="p-2">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="text-secondary font-monospace" style="font-size: 0.68rem;">Elementos activos en lienzo</span>
                                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 small" onclick="vaciarTodosLosElementosPlano()" title="Vaciar todos los componentes del plano" style="font-size: 0.68rem;">
                                                <i class="bi bi-trash3-fill me-1"></i> Vaciar Todo
                                            </button>
                                        </div>

                                        <div id="contenedorListaElementosCanvas" class="list-group list-group-flush bg-dark border border-secondary border-opacity-25 rounded-3 p-1" style="max-height: 240px; overflow-y: auto;">
                                            <div class="text-center text-secondary p-3 small opacity-75">No hay componentes en el plano.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- INSPECTOR / PROPIEDADES DE ELEMENTO O FIGURA SELECCIONADA -->
                         <div id="panelPropiedades" class="mt-4 border-top border-secondary border-opacity-25 pt-3 d-none">
                             <div class="d-flex justify-content-between align-items-center mb-2">
                                 <h6 class="fw-bold text-warning small mb-0"><i class="bi bi-box-seam me-1"></i> Propiedades 2D / 3D</h6>
                                 <div class="d-flex gap-1">
                                     <button type="button" class="btn btn-outline-info btn-sm rounded-2 py-0 px-2 small" onclick="duplicarElementoSeleccionado()" title="Duplicar elemento (Ctrl+D)">
                                         <i class="bi bi-copy me-1"></i> Duplicar
                                     </button>
                                     <button type="button" class="btn btn-danger btn-sm rounded-2 py-0 px-2 small" onclick="eliminarElementoSeleccionado()" title="Eliminar (Supr)">
                                         <i class="bi bi-trash-fill"></i>
                                     </button>
                                 </div>
                             </div>

                             <div class="mb-2">
                                 <label class="text-secondary small" style="font-size: 0.7rem;">Etiqueta / Nombre:</label>
                                 <input type="text" id="propLabel" class="form-control form-control-sm bg-dark text-white border-secondary" oninput="actualizarPropiedadElemento()">
                             </div>

                             <!-- DIMENSIONES 3D & 2D (X, Y, Z) -->
                             <div class="card bg-dark border-secondary border-opacity-50 p-2 mb-2">
                                 <span class="text-info small fw-bold d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-aspect-ratio me-1"></i> Tamaño 3D / 2D (Ejes X, Y, Z)</span>
                                 <div class="row g-2 mb-1">
                                     <div class="col-4">
                                         <label class="text-secondary small" style="font-size: 0.65rem;">Ancho X (px):</label>
                                         <input type="number" id="propWidth" min="5" max="2000" class="form-control form-control-sm bg-dark text-white border-secondary px-1" oninput="actualizarPropiedadElemento()">
                                     </div>
                                     <div class="col-4">
                                         <label class="text-secondary small" style="font-size: 0.65rem;">Largo Y (px):</label>
                                         <input type="number" id="propHeight" min="5" max="2000" class="form-control form-control-sm bg-dark text-white border-secondary px-1" oninput="actualizarPropiedadElemento()">
                                     </div>
                                     <div class="col-4">
                                         <label class="text-secondary small" style="font-size: 0.65rem;">Altura Z (px):</label>
                                         <input type="number" id="propHeight3D" min="1" max="1000" class="form-control form-control-sm bg-dark text-white border-secondary px-1" oninput="actualizarPropiedadElemento()">
                                     </div>
                                 </div>
                                 <div class="mb-1">
                                     <div class="d-flex justify-content-between">
                                         <label class="text-secondary small" style="font-size: 0.65rem;">Elevación del Suelo 3D:</label>
                                         <span id="valElevation3D" class="text-info small fw-bold" style="font-size: 0.65rem;">0px</span>
                                     </div>
                                     <input type="range" id="propElevation3D" min="0" max="300" value="0" class="form-range" oninput="actualizarPropiedadElemento()">
                                 </div>
                             </div>

                             <!-- ROTACIÓN Y GIRO (TODOS LOS COMPONENTES) -->
                             <div class="card bg-dark border-secondary border-opacity-50 p-2 mb-2">
                                 <span class="text-warning small fw-bold d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-arrow-repeat me-1"></i> Rotación / Giro (Todos los Nodos)</span>
                                 
                                 <div class="mb-2">
                                     <div class="d-flex justify-content-between align-items-center mb-1">
                                         <label class="text-secondary small mb-0" style="font-size: 0.65rem;">Giro Plano Y 2D/3D:</label>
                                         <span id="valAnguloY" class="text-warning small fw-bold" style="font-size: 0.65rem;">0°</span>
                                     </div>
                                     <input type="range" id="propAngulo" min="0" max="360" value="0" class="form-range" oninput="actualizarPropiedadElemento()">
                                     
                                     <div class="d-flex gap-1 mt-1">
                                         <button type="button" class="btn btn-outline-secondary btn-sm py-0 flex-grow-1 text-white" style="font-size: 0.65rem;" onclick="rotarElementoRapido(-45)">-45°</button>
                                         <button type="button" class="btn btn-outline-secondary btn-sm py-0 flex-grow-1 text-white" style="font-size: 0.65rem;" onclick="rotarElementoRapido(45)">+45°</button>
                                         <button type="button" class="btn btn-outline-secondary btn-sm py-0 flex-grow-1 text-white" style="font-size: 0.65rem;" onclick="rotarElementoRapido(90)">90°</button>
                                         <button type="button" class="btn btn-outline-secondary btn-sm py-0 flex-grow-1 text-white" style="font-size: 0.65rem;" onclick="rotarElementoRapido(180)">180°</button>
                                     </div>
                                 </div>

                                 <div class="row g-2">
                                     <div class="col-6">
                                         <div class="d-flex justify-content-between">
                                             <label class="text-secondary small" style="font-size: 0.65rem;">Inclinación X 3D:</label>
                                             <span id="valAnguloX" class="text-white small" style="font-size: 0.65rem;">0°</span>
                                         </div>
                                         <input type="range" id="propAnguloX" min="-180" max="180" value="0" class="form-range" oninput="actualizarPropiedadElemento()">
                                     </div>
                                     <div class="col-6">
                                         <div class="d-flex justify-content-between">
                                             <label class="text-secondary small" style="font-size: 0.65rem;">Inclinación Z 3D:</label>
                                             <span id="valAnguloZ" class="text-white small" style="font-size: 0.65rem;">0°</span>
                                         </div>
                                         <input type="range" id="propAnguloZ" min="-180" max="180" value="0" class="form-range" oninput="actualizarPropiedadElemento()">
                                     </div>
                                 </div>
                             </div>

                             <!-- SUBTIPO DE COMPUTADORA (DESKTOP, LAPTOP, ALL IN ONE) -->
                             <div id="groupSubtipoPC" class="card bg-dark border-secondary border-opacity-50 p-2 mb-2" style="display: none;">
                                 <span class="text-info small fw-bold d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-pc-display me-1"></i> Tipo de Computadora</span>
                                 <select id="propSubtipoPC" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="actualizarPropiedadElemento()">
                                     <option value="desktop">🖥️ Desktop (Torre + Monitor)</option>
                                     <option value="laptop">💻 Laptop (Computadora Portátil)</option>
                                     <option value="allinone">🖥️ All In One (Monitor AIO Integrado)</option>
                                 </select>
                             </div>

                             <!-- TRANSPARENCIA / OPACIDAD -->
                             <div id="groupPropOpacidad" class="card bg-dark border-secondary border-opacity-50 p-2 mb-2">
                                 <div class="d-flex justify-content-between align-items-center mb-1">
                                     <span class="text-info small fw-bold" style="font-size: 0.7rem;"><i class="bi bi-eye me-1"></i> Transparencia / Opacidad:</span>
                                     <span id="valOpacidad" class="text-info small fw-bold" style="font-size: 0.7rem;">100%</span>
                                 </div>
                                 <input type="range" id="propOpacidad" min="10" max="100" step="5" value="100" class="form-range" oninput="actualizarPropiedadElemento()">
                             </div>

                             <!-- Controles para Figuras / Colores -->
                             <div id="groupShapeProps" class="mb-2">
                                 <label class="text-secondary small" style="font-size: 0.7rem;">Color de Relleno / Elemento:</label>
                                 <input type="color" id="propColor" class="form-control form-control-color w-100 bg-dark border-secondary mb-1" style="height: 32px;" oninput="actualizarPropiedadElemento()">
                                 <div class="d-flex gap-1 flex-wrap mb-2">
                                     <button type="button" class="btn btn-sm py-0 px-1 border border-secondary" style="background: rgba(56,189,248,0.3); color:#fff; font-size: 0.65rem;" onclick="setPresetColor('#38bdf8', '#38bdf8')">Cyan AP</button>
                                     <button type="button" class="btn btn-sm py-0 px-1 border border-secondary" style="background: rgba(34,197,94,0.3); color:#fff; font-size: 0.65rem;" onclick="setPresetColor('#22c55e', '#22c55e')">Verde</button>
                                     <button type="button" class="btn btn-sm py-0 px-1 border border-secondary" style="background: rgba(249,115,22,0.3); color:#fff; font-size: 0.65rem;" onclick="setPresetColor('#fb923c', '#fb923c')">Naranja</button>
                                     <button type="button" class="btn btn-sm py-0 px-1 border border-secondary" style="background: rgba(148,163,184,0.3); color:#fff; font-size: 0.65rem;" onclick="setPresetColor('#94a3b8', '#94a3b8')">Gris CAD</button>
                                 </div>

                                 <div id="groupBorderWidthWrap" class="mb-2">
                                     <label class="text-secondary small" style="font-size: 0.7rem;">Grosor de Borde (px):</label>
                                     <input type="range" id="propBorderWidth" min="0" max="15" class="form-range" oninput="actualizarPropiedadElemento()">
                                 </div>
                             </div>

                              <!-- FOTOGRAFÍA / IMAGEN DE CUADRO DE PARED Y PANTALLA -->
                              <div id="groupCuadroImagen" class="card bg-dark border-secondary border-opacity-50 p-2 mb-2" style="display: none;">
                                  <span class="text-info small fw-bold d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-image me-1"></i> Imagen / Fotografía del Cuadro o TV</span>
                                  
                                  <div class="mb-2">
                                      <label class="text-secondary small" style="font-size: 0.65rem;">Subir Foto desde tu Equipo:</label>
                                      <input type="file" id="inputFileCuadroImagen" accept="image/*" class="form-control form-control-sm bg-dark text-white border-secondary" onchange="cargarImagenParaCuadro(this)">
                                  </div>

                                  <div class="mb-1">
                                      <label class="text-secondary small" style="font-size: 0.65rem;">o Pegar URL de Imagen:</label>
                                      <input type="text" id="propImagenUrl" placeholder="https://..." class="form-control form-control-sm bg-dark text-white border-secondary" oninput="actualizarPropiedadElemento()">
                                  </div>

                                  <div id="previewCuadroWrap" class="mt-2 text-center" style="display: none;">
                                      <img id="imgPreviewCuadro" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E" style="max-height: 80px; max-width: 100%; border-radius: 6px; border: 1px solid #64748b; object-fit: cover;">
                                  </div>
                              </div>

                              <!-- CONTROLES DE COLORES POR PARED INDIVIDUAL EN 3D -->
                              <div id="containerColoresParedes" class="card bg-dark border-secondary border-opacity-50 p-2 mb-2" style="display: none;">
                                  <span class="text-info small fw-bold d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-palette-fill me-1"></i> Colores por Pared (Segmentos 3D)</span>
                                  <div id="listaColoresParedes" class="d-flex flex-column gap-1"></div>
                              </div>

                              <!-- POSICIÓN PRECISA EN EL PLANO (X, Y) -->
                              <div class="card bg-dark border-secondary border-opacity-50 p-2 mb-2">
                                  <span class="text-warning small fw-bold d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-geo-alt-fill me-1"></i> Posición Pared / Componente (X, Y)</span>
                                  <div class="row g-2">
                                      <div class="col-6">
                                          <label class="text-secondary small" style="font-size: 0.65rem;">Posición X (px):</label>
                                          <input type="number" id="propPosX" class="form-control form-control-sm bg-dark text-white border-secondary px-1" oninput="actualizarPropiedadElemento()">
                                      </div>
                                      <div class="col-6">
                                          <label class="text-secondary small" style="font-size: 0.65rem;">Posición Y (px):</label>
                                          <input type="number" id="propPosY" class="form-control form-control-sm bg-dark text-white border-secondary px-1" oninput="actualizarPropiedadElemento()">
                                      </div>
                                  </div>
                              </div>

                             <!-- Puntos de Dobleces para Polígonos Irregulares -->
                             <div id="groupPolygonControls" class="mb-2 d-none">
                                 <label class="text-secondary small fw-bold d-block mb-1" style="font-size: 0.7rem;">📍 Puntos de Doblez / Vértices:</label>
                                 <div class="d-flex gap-2">
                                     <button type="button" class="btn btn-outline-info btn-sm flex-grow-1 rounded-2 fw-semibold" style="font-size: 0.75rem;" onclick="agregarPuntoVerticePolygon()">
                                         ➕ Agregar Doblez
                                     </button>
                                     <button type="button" class="btn btn-outline-secondary btn-sm flex-grow-1 rounded-2 fw-semibold" style="font-size: 0.75rem;" onclick="eliminarPuntoVerticePolygon()">
                                         ➖ Quitar Punto
                                     </button>
                                 </div>
                             </div>

                              <div class="mb-2" id="groupPropRadio">
                                  <div class="d-flex align-items-center justify-content-between mb-1">
                                      <label class="text-secondary small" style="font-size: 0.7rem;">Radio Cobertura AP (px):</label>
                                      <input type="number" id="propRadioNum" min="20" max="3000" class="form-control form-control-sm bg-dark text-white border-secondary px-1 text-center" style="width: 75px; font-size: 0.75rem;" oninput="syncRadioFromNum(this.value)">
                                  </div>
                                  <input type="range" id="propRadio" min="20" max="1500" step="5" class="form-range" oninput="syncRadioFromSlider(this.value)">
                              </div>

                             <button type="button" class="btn btn-danger btn-sm w-100 rounded-3 mt-3 shadow-sm fw-bold" onclick="eliminarElementoSeleccionado()">
                                 <i class="bi bi-trash-fill me-1"></i> Eliminar Componente Seleccionado (Supr)
                             </button>
                         </div>
                    </div>
                </div>

                <!-- CANVAS PRINCIPAL 2D -->
                <div class="col-lg-9" id="colMainCanvas">
                    <div class="card-custom p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 border-bottom border-secondary border-opacity-25 pb-2">
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-outline-info text-white btn-sm rounded-3 me-2 fw-semibold" onclick="activarModoNavegacion('inicio')" title="Volver a la pantalla principal de diagramas">
                                    <i class="bi bi-arrow-left me-1"></i> ↩️ Volver a Diagramas
                                </button>
                                <span class="fw-bold text-white small">
                                    <i class="bi bi-aspect-ratio text-success me-1"></i> Lienzo de Mapeo: <span class="text-info"><?php echo htmlspecialchars($planoActual['nombre_plano']); ?></span>
                                </span>
                            </div>

                            <!-- BARRA DE ZOOM, 2D/3D, CONFIGURAR Y HISTORIAL PARA EDICIÓN -->
                            <div class="d-flex align-items-center gap-2">
                                 <!-- CONMUTADOR DE VISTAS (SUB-VISTAS EN CONFIGURACIÓN) -->
                                 <div class="btn-group btn-group-sm me-2" id="grupoSwitchVista">
                                     <button type="button" id="btnVista2D" class="btn btn-primary active text-white fw-bold rounded-start-3" onclick="alternarSubVistaConfig('2d')" title="Vista 2D Esquemática">
                                         <i class="bi bi-aspect-ratio me-1"></i> Vista 2D
                                     </button>
                                     <button type="button" id="btnVista3D" class="btn btn-outline-info text-white fw-bold rounded-end-3" onclick="alternarSubVistaConfig('3d')" title="Vista 3D Tridimensional">
                                         <i class="bi bi-box-seam-fill me-1"></i> Vista 3D
                                     </button>
                                 </div>

                                 <button type="button" id="btnImprimirPDF2D" class="btn btn-outline-success text-white btn-sm rounded-3 me-2 fw-semibold shadow-sm" onclick="exportarPlano2DPDF()" title="Descargar plano 2D directamente en archivo PDF con iconografía (Ctrl+P)" style="display: none;">
                                     <i class="bi bi-file-earmark-pdf-fill text-success me-1"></i> 📄 Exportar PDF
                                 </button>

                                <button type="button" id="btnFondo3D" class="btn btn-outline-light btn-sm rounded-3 me-2 fw-semibold shadow-sm" onclick="alternarFondo3D()" title="Cambiar a Fondo Oscuro en 3D" style="display: none;">
                                    <i class="bi bi-moon-stars-fill text-info me-1" id="iconFondo3D"></i> <span id="labelFondo3D">Fondo Oscuro</span>
                                </button>

                                <button type="button" id="btnCaminar3D" class="btn btn-outline-warning text-white btn-sm rounded-3 me-2 fw-semibold shadow-sm" onclick="alternarModoRecorrido3D()" title="Caminar adentro en 1ª Persona (WASD + Flechas)" style="display: none;">
                                    <i class="bi bi-person-walking text-warning me-1" id="iconCaminar3D"></i> <span id="labelCaminar3D">Caminar Adentro 🚶‍♂️</span>
                                </button>

                                <button type="button" id="btnFullscreen" class="btn btn-outline-info text-white btn-sm rounded-3 me-2 fw-semibold shadow-sm" onclick="alternarPantallaCompleta()" title="Pantalla Completa / Vista Completa (F11 / ESC para salir)">
                                    <i class="bi bi-arrows-fullscreen me-1" id="iconFullscreen"></i> <span id="labelFullscreen">🖥️ Vista Completa</span>
                                </button>

                                <div id="barraEditingHeader" class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-outline-warning text-white btn-sm rounded-3 me-2 fw-semibold" onclick="seleccionarTodoElPlano()" title="Seleccionar todos los componentes del plano (Ctrl+A)">
                                        <i class="bi bi-check2-all me-1"></i> ☑️ Seleccionar Todo
                                    </button>

                                    <div class="btn-group btn-group-sm me-2">
                                        <button type="button" id="btnUndoCanvas" class="btn btn-outline-secondary text-white rounded-start-3" onclick="deshacerAccion()" title="Deshacer última acción (Ctrl+Z)" disabled>
                                            <i class="bi bi-arrow-counterclockwise text-warning me-1"></i> Deshacer
                                        </button>
                                        <button type="button" id="btnRedoCanvas" class="btn btn-outline-secondary text-white rounded-end-3" onclick="rehacerAccion()" title="Rehacer (Ctrl+Y o Ctrl+Shift+Z)" disabled>
                                            <i class="bi bi-arrow-clockwise text-info me-1"></i> Rehacer
                                        </button>
                                    </div>
                                </div>

                                <!-- DROPDOWN DE FILTRO DE CAPAS Y VISIBILIDAD DE COMPONENTES -->
                                <div class="dropdown me-2">
                                    <button type="button" class="btn btn-outline-info btn-sm rounded-3 dropdown-toggle fw-semibold shadow-sm" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside" title="Mostrar / Ocultar Capas de Componentes">
                                        <i class="bi bi-eye-fill text-info me-1"></i> 👁️ Visibilidad
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-dark p-3 shadow-lg border-secondary" style="min-width: 260px; z-index: 1050;">
                                        <h6 class="fw-bold text-white small border-bottom border-secondary pb-2 mb-2">
                                            <i class="bi bi-layers-fill text-info me-1"></i> Ver / Ocultar Capas
                                        </h6>
                                        
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisNodo" checked onchange="alternarVisibilidadCategoria('nodo', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisNodo">
                                                🔌 Nodos de Red
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisPc" checked onchange="alternarVisibilidadCategoria('pc', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisPc">
                                                💻 PCs / Computadoras
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisImpresora" checked onchange="alternarVisibilidadCategoria('impresora', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisImpresora">
                                                🖨️ Impresoras / Multifuncionales
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisTelefono" checked onchange="alternarVisibilidadCategoria('telefono', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisTelefono">
                                                📞 Teléfonos IP / PoE
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisPantalla" checked onchange="alternarVisibilidadCategoria('pantalla', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisPantalla">
                                                📺 Pantallas / TVs / Fotos
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisAp" checked onchange="alternarVisibilidadCategoria('ap', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisAp">
                                                📡 Access Points (AP Wi-Fi)
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisCamara" checked onchange="alternarVisibilidadCategoria('camara', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisCamara">
                                                📹 Cámaras de Seguridad
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="chkVisSite" checked onchange="alternarVisibilidadCategoria('site', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisSite">
                                                🗄️ Racks & SITE
                                            </label>
                                        </div>

                                        <div class="form-check mb-0">
                                            <input class="form-check-input" type="checkbox" id="chkVisArquitectura" checked onchange="alternarVisibilidadCategoria('arquitectura', this.checked)">
                                            <label class="form-check-label small text-white cursor-pointer" for="chkVisArquitectura">
                                                🧱 Paredes & Arquitectura CAD
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <span class="text-secondary small me-1"><i class="bi bi-zoom-in text-info me-1"></i> Zoom:</span>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary text-white rounded-start-3" onclick="cambiarZoom(-0.15)" title="Alejar Zoom (-)">
                                        <i class="bi bi-dash-lg"></i>
                                    </button>
                                    <span id="badgeZoomLevel" class="badge bg-dark border border-secondary text-info d-flex align-items-center px-3 font-monospace fw-bold" style="font-size: 0.85rem;">
                                        100%
                                    </span>
                                    <button type="button" class="btn btn-outline-secondary text-white" onclick="cambiarZoom(0.15)" title="Acercar Zoom (+)">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary text-white rounded-end-3" onclick="resetearZoom()" title="Restablecer Zoom a 100%">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> 100%
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="canvas-container-outer" id="canvasOuter" style="position: relative; background: #ffffff; background-image: linear-gradient(rgba(0, 0, 0, 0.06) 1px, transparent 1px), linear-gradient(90deg, rgba(0, 0, 0, 0.06) 1px, transparent 1px); background-size: 25px 25px; border: 2px solid #cbd5e1; border-radius: 16px; min-height: 650px; max-height: 800px; overflow: auto; user-select: none; cursor: grab;">
                             <!-- Contenedor 3D WebGL (Three.js) -->
                             <div id="canvas3DContainer" style="display: none; position: absolute; top:0; left:0; width: 100%; height: 100%; min-height: 650px; z-index: 50; background: #f8fafc;">
                                 <div id="canvas3DOverlayControls" style="position: absolute; top: 16px; right: 16px; z-index: 100; display: flex; gap: 8px;">
                                     <button type="button" class="btn btn-dark btn-sm rounded-3 shadow border border-secondary text-white opacity-75 hover-opacity-100" onclick="alternarModoRecorrido3D()" title="Caminar Adentro / Vista Aérea (WASD + Flechas)">
                                         <i class="bi bi-person-walking text-warning" id="iconOverlayWalk"></i>
                                     </button>
                                     <button type="button" class="btn btn-dark btn-sm rounded-3 shadow border border-secondary text-white opacity-75 hover-opacity-100" onclick="alternarFondo3D()" title="Alternar Fondo Claro / Oscuro (3D)">
                                         <i class="bi bi-moon-stars-fill text-warning"></i>
                                     </button>
                                     <button type="button" class="btn btn-dark btn-sm rounded-3 shadow border border-secondary text-white opacity-75 hover-opacity-100" onclick="alternarPantallaCompleta3D()" title="Pantalla Completa / Salir (F11 o ESC)">
                                         <i class="bi bi-arrows-fullscreen text-info" id="iconOverlayFS"></i>
                                     </button>
                                 </div>

                                 <!-- HUD DE NAVEGACIÓN TIPO VIDEOJUEGO / STREET VIEW -->
                                 <div id="canvas3DHUD" style="position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%); z-index: 100; pointer-events: none; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.15); border-radius: 30px; padding: 6px 18px; color: #ffffff; font-size: 0.75rem; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.3);" class="d-flex align-items-center gap-3">
                                     <span><i class="bi bi-controller text-warning me-1"></i> <b>Controles:</b> Flechas / WASD para Caminar</span>
                                     <span class="text-white-50">|</span>
                                     <span><b>Shift:</b> Correr</span>
                                     <span class="text-white-50">|</span>
                                     <span><b>Q / E:</b> Altura</span>
                                     <span class="text-white-50">|</span>
                                     <span><i class="bi bi-eye text-info me-1"></i> <b>Arrastrar Ratón:</b> Girar Cabeza (Mirar a donde Ver)</span>
                                 </div>
                                 <!-- FICHA TÉCNICA FLOTANTE DENTRO DE LA VISTA 3D -->
                                 <div id="fichaTecnica3DOverlay" style="display: none; position: absolute; z-index: 150; width: 330px; background: rgba(9, 26, 48, 0.95); backdrop-filter: blur(12px); border: 1.5px solid rgba(56, 189, 248, 0.5); border-radius: 14px; box-shadow: 0 15px 35px rgba(0,0,0,0.7); padding: 14px; color: #ffffff;">
                                     <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom border-secondary border-opacity-30">
                                         <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
                                             <span id="ficha3DIconBox" class="badge p-1.5 rounded-3" style="background: rgba(56,189,248,0.2); color: #38bdf8; font-size: 1.1rem;">
                                                 <i id="ficha3DIcon" class="bi bi-display-fill"></i>
                                             </span>
                                             <div class="text-truncate">
                                                 <h6 id="ficha3DNombre" class="fw-bold text-white mb-0 text-truncate" style="font-size: 0.9rem;">Nombre Componente</h6>
                                                 <span id="ficha3DTipo" class="badge bg-dark text-info font-monospace" style="font-size: 0.65rem;">ELEMENTO 3D</span>
                                             </div>
                                         </div>
                                         <button type="button" class="btn-close btn-close-white btn-sm" onclick="cerrarFichaTecnica3D()"></button>
                                     </div>

                                     <div class="small" style="font-size: 0.78rem;">
                                         <div class="row g-2 mb-2">
                                             <div class="col-6">
                                                 <span class="text-secondary d-block">IP:</span>
                                                 <span id="ficha3DIP" class="fw-bold text-info font-monospace">--</span>
                                             </div>
                                             <div class="col-6">
                                                 <span class="text-secondary d-block">Depto:</span>
                                                 <span id="ficha3DDept" class="fw-semibold text-white">--</span>
                                             </div>
                                         </div>

                                         <div id="ficha3DGroupInv" class="p-2 rounded-3 mb-2" style="background: rgba(15, 23, 42, 0.9); border: 1px solid rgba(56, 189, 248, 0.3); display: none;">
                                             <div class="d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom border-secondary border-opacity-25">
                                                 <span class="text-info fw-bold" style="font-size: 0.72rem;"><i class="bi bi-pc-display me-1"></i> REGISTRO INVENTARIO</span>
                                                 <span id="ficha3DModulo" class="badge bg-primary bg-opacity-25 text-info" style="font-size: 0.6rem;">Módulo</span>
                                             </div>
                                             <div class="row g-1" style="font-size: 0.72rem;">
                                                 <div class="col-12 text-truncate">
                                                     <span class="text-secondary">Responsable:</span> <span id="ficha3DUsuario" class="text-white fw-semibold">--</span>
                                                 </div>
                                                 <div class="col-12 text-truncate">
                                                     <span class="text-secondary">Marca/Modelo:</span> <span id="ficha3DMarcaModelo" class="text-white fw-semibold">--</span>
                                                 </div>
                                                 <div class="col-12 text-truncate">
                                                     <span class="text-secondary">Serie:</span> <span id="ficha3DSerie" class="text-warning font-monospace fw-bold">--</span>
                                                 </div>
                                                 <div class="col-12 text-truncate" id="ficha3DGroupEspecs">
                                                     <span class="text-secondary">Specs:</span> <span id="ficha3DEspecs" class="text-info font-monospace">--</span>
                                                 </div>
                                                 <div class="col-12 text-truncate" id="ficha3DGroupEstado">
                                                     <span class="text-secondary">Estatus:</span> <span id="ficha3DEstado" class="text-success fw-bold">--</span>
                                                 </div>
                                             </div>
                                         </div>

                                         <div class="d-flex justify-content-between align-items-center pt-1 text-secondary font-monospace" style="font-size: 0.68rem;">
                                             <span id="ficha3DCoords">X:0 Y:0 Z:0</span>
                                             <button type="button" class="btn btn-warning btn-sm py-0 px-2 fw-bold" style="font-size: 0.7rem;" onclick="abrirFichaModalCompletaDesde3D()">
                                                 <i class="bi bi-box-arrow-up-right me-1"></i> Ver Modal Completo
                                             </button>
                                         </div>
                                     </div>
                                 </div>
                             </div>

                            <div id="canvasViewport" style="position: relative; width: 2400px; height: 1800px; transform-origin: 0 0; transition: transform 0.08s ease-out;">
                                <!-- Imagen de Fondo del Plano -->
                                <img id="imgFondoPlano" class="canvas-bg-img" src="<?php echo !empty($planoActual['imagen_fondo_url']) ? htmlspecialchars($planoActual['imagen_fondo_url']) : 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\'/%3E'; ?>" style="<?php echo empty($planoActual['imagen_fondo_url']) ? 'display:none;' : ''; ?>">
                                
                                <!-- Contenedor donde se renderizan dinámicamente los nodos y figuras 2D -->
                                <div id="contenedorNodosCanvas" style="position: absolute; top:0; left:0; width:100%; height:100%;"></div>
                            </div>

                            <!-- MINIMAPA RADAR 2D FLOTANTE & UBICACIÓN EN TIEMPO REAL -->
                            <div id="minimapWrapper" style="position: sticky; bottom: 14px; right: 14px; margin-left: auto; width: 210px; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(8px); border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 8px; z-index: 100; box-shadow: 0 8px 24px rgba(0,0,0,0.15); pointer-events: auto;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-primary fw-bold" style="font-size: 0.7rem;"><i class="bi bi-radar me-1"></i> Minimapa 2D</span>
                                    <span id="minimapCoords" class="text-secondary font-monospace" style="font-size: 0.65rem;">(0, 0)</span>
                                </div>
                                <canvas id="minimapCanvas" width="194" height="120" style="background: #ffffff; border-radius: 6px; cursor: crosshair; display: block; border: 1px solid #cbd5e1;"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div> <!-- Fin #seccionWorkspacePlano -->
<?php endif; ?>
