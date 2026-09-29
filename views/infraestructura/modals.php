<!-- MODAL PARA CREAR NUEVO PLANO 2D -->
<div class="modal fade" id="modalCrearPlano" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="guardar_plano_2d">
                <input type="hidden" name="plano_id" value="0">
                
                <div class="modal-header border-secondary border-opacity-25">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-plus-circle text-success me-2"></i> Crear Nuevo Plano 2D de Agencia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nombre del Plano *</label>
                        <input type="text" name="nombre_plano" class="form-control form-control-sm" required placeholder="Ej. Plano Agencia VW Planta Baja">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Imagen de Fondo del Plano (Opcional)</label>
                        <input type="file" name="imagen_fondo_file" class="form-control form-control-sm" accept="image/*">
                        <span class="text-secondary small d-block mt-1">Puedes subir la imagen del plano arquitectónico ahora o usar las figuras para dibujarlo.</span>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm px-4 fw-bold">Crear Plano 2D</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL FICHA TÉCNICA Y DETALLES DEL ELEMENTO (DOBLE CLIC EN EL PLANO) -->
<div class="modal fade" id="modalFichaTecnicaElemento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom shadow-lg border border-secondary border-opacity-50">
            <div class="modal-header border-secondary border-opacity-25 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div id="fichaIconBox" class="nav-icon-box" style="width: 46px; height: 46px; font-size: 1.4rem; background: rgba(56, 189, 248, 0.2); color: #38bdf8;">
                        <i id="fichaIcon" class="bi bi-cpu-fill"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="fichaNombre">Ficha Técnica del Componente</h5>
                        <span class="badge bg-dark border border-secondary text-info font-monospace small mt-1" id="fichaTipo">ELEMENTO 2D</span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1">Dirección IP:</span>
                        <span class="fw-bold font-monospace text-info fs-6" id="fichaIP">No asignada</span>
                    </div>
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1">Departamento / Área:</span>
                        <span class="fw-semibold text-white" id="fichaDept">General</span>
                    </div>

                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-ethernet me-1" style="color: #c084fc;"></i> Nodo de Red:</span>
                        <span class="fw-bold font-monospace fs-6" id="fichaNodoRed" style="color: #c084fc;">No asignado</span>
                    </div>
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-diagram-3-fill text-info me-1"></i> Puerto SW / Switch:</span>
                        <span class="fw-bold font-monospace text-info fs-6" id="fichaPuertoSW">No asignado</span>
                    </div>

                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1">Coordenadas en Plano:</span>
                        <span class="fw-semibold text-light font-monospace" id="fichaCoords">(0, 0)</span>
                    </div>
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1">Dimensiones (Ancho x Alto):</span>
                        <span class="fw-semibold text-light font-monospace" id="fichaDim">60 x 60 px</span>
                    </div>

                    <div class="col-12" id="fichaGroupCobertura" style="display:none;">
                        <div class="p-3 rounded-3 bg-dark border border-secondary border-opacity-50">
                            <span class="text-secondary small d-block mb-1 fw-bold">📡 Cobertura & Configuración Visión:</span>
                            <div class="d-flex justify-content-between">
                                <span class="small text-info font-monospace" id="fichaRadioText">Radio: 100 px</span>
                                <span class="small text-warning font-monospace" id="fichaAnguloText">Ángulo: 0°</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12" id="fichaGroupInventario" style="display:none;">
                        <div class="p-3 rounded-3" style="background: rgba(15, 23, 42, 0.85); border: 1.5px solid rgba(56, 189, 248, 0.4);">
                            <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom border-secondary border-opacity-25">
                                <span class="text-info fw-bold small"><i class="bi bi-pc-display me-1"></i> DATOS DE REGISTRO EN INVENTARIO</span>
                                <span class="badge bg-primary bg-opacity-25 text-info font-monospace small" id="fichaInvModulo">Módulo</span>
                            </div>
                            <div class="row g-2 small">
                                <div class="col-6">
                                    <span class="text-secondary d-block">Usuario / Responsable:</span>
                                    <span class="text-white fw-semibold" id="fichaInvUsuario">--</span>
                                </div>
                                <div class="col-6">
                                    <span class="text-secondary d-block">Departamento / Puesto:</span>
                                    <span class="text-white fw-semibold" id="fichaInvDeptPuesto">--</span>
                                </div>
                                <div class="col-6">
                                    <span class="text-secondary d-block">Marca / Modelo:</span>
                                    <span class="text-white fw-semibold" id="fichaInvMarcaModelo">--</span>
                                </div>
                                <div class="col-6">
                                    <span class="text-secondary d-block">Número de Serie / Clave:</span>
                                    <span class="text-warning font-monospace fw-bold" id="fichaInvSerie">--</span>
                                </div>
                                <div class="col-6" id="fichaInvGroupEspecs">
                                    <span class="text-secondary d-block">Especificaciones (Hardware):</span>
                                    <span class="text-info font-monospace" id="fichaInvEspecs">--</span>
                                </div>
                                <div class="col-6" id="fichaInvGroupEstado">
                                    <span class="text-secondary d-block">Estatus / Ubicación:</span>
                                    <span class="text-success fw-bold" id="fichaInvEstado">--</span>
                                </div>
                                <div class="col-12" id="fichaInvGroupNotas" style="display:none;">
                                    <span class="text-secondary d-block">Observaciones / Notas:</span>
                                    <span class="text-light italic" id="fichaInvNotas">--</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12" id="fichaGroupFoto" style="display:none;">
                        <div class="p-2 rounded-3 bg-dark border border-info border-opacity-50 text-center">
                            <span class="text-secondary small d-block mb-1 fw-bold"><i class="bi bi-image text-info me-1"></i> Fotografía del Dispositivo:</span>
                            <img id="fichaFotoImg" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E" class="img-fluid rounded-3 border border-secondary shadow-sm" style="max-height: 140px; cursor: pointer; object-fit: contain;" onclick="abrirLightboxFoto(this.src, 'Fotografía del Dispositivo')" title="Haz clic para amplificar la fotografía en tamaño completo">
                            <div class="text-info fs-7 mt-1 fw-semibold"><i class="bi bi-zoom-in me-1"></i> Haz clic en la fotografía para ver en tamaño completo</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25 justify-content-between">
                <button type="button" class="btn btn-outline-info btn-sm rounded-3 fw-semibold" onclick="centrarEnElementoDesdeFicha()">
                    <i class="bi bi-crosshair me-1"></i> Centrar en Plano
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm rounded-3 px-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-warning btn-sm rounded-3 fw-bold px-3" onclick="abrirInspectorPropiedades()">
                        <i class="bi bi-pencil-square me-1"></i> Modificar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- MODAL FLOTANTE: EXPEDIENTE DEL PERSONAL (USUARIO)   -->
<!-- =================================================== -->
<div class="modal fade" id="modalExpedienteUsuario" tabindex="-1" aria-hidden="true" style="z-index: 100050;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom shadow-lg border border-info border-opacity-50">
            <div class="modal-header border-secondary border-opacity-25 p-4" style="background: rgba(9, 24, 43, 0.95);">
                <div class="d-flex align-items-center gap-3">
                    <div id="userExpAvatarBox"></div>
                    <div>
                        <h4 class="modal-title fw-bold text-white mb-0" id="userExpNombre">---</h4>
                        <span class="text-info font-monospace small fw-bold d-block mt-0.5" id="userExpEmail">---</span>
                        <div class="mt-1 text-secondary small" style="font-size: 0.75rem;">
                            <i class="bi bi-zoom-in me-1 text-info"></i> HAZ CLIC EN LA FOTOGRAFÍA PARA VERLA EN TAMAÑO COMPLETO
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <div class="p-3 rounded-3" style="background: rgba(14, 36, 68, 0.85); border: 1px solid rgba(56, 189, 248, 0.3);">
                    <h6 class="fw-bold text-info border-bottom border-secondary border-opacity-50 pb-2 mb-3">
                        <i class="bi bi-person-badge-fill me-2"></i> Expediente del Personal
                    </h6>
                    <div class="row g-3 small">
                        <div class="col-6">
                            <span class="text-secondary d-block mb-1">AGENCIA / SUCURSAL</span>
                            <span class="text-white fw-bold" id="userExpAgencia">---</span>
                        </div>
                        <div class="col-6">
                            <span class="text-secondary d-block mb-1">ÁREA / DEPARTAMENTO</span>
                            <span class="text-white fw-bold" id="userExpArea">---</span>
                        </div>
                        <div class="col-6">
                            <span class="text-secondary d-block mb-1">PUESTO / CARGO</span>
                            <span class="text-white fw-bold" id="userExpPuesto">---</span>
                        </div>
                        <div class="col-6">
                            <span class="text-secondary d-block mb-1">TELÉFONO / EXTENSIÓN</span>
                            <span class="text-white fw-bold" id="userExpTelefono">---</span>
                        </div>
                        <div class="col-6">
                            <span class="text-secondary d-block mb-1">ACCESO AL PORTAL</span>
                            <span id="userExpAccesoBadge">---</span>
                        </div>
                        <div class="col-6">
                            <span class="text-secondary d-block mb-1">ROL DE USUARIO</span>
                            <span class="text-white fw-bold" id="userExpRol">---</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-secondary border-opacity-25 justify-content-end">
                <button type="button" class="btn btn-secondary btn-sm rounded-3 px-4 fw-bold" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- =================================================== -->
<!-- LIGHTBOX OVERLAY FLOTANTE (VER FOTO EN TAMAÑO COMPLETO) -->
<!-- =================================================== -->
<div id="customFotoLightbox" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 999999; background: rgba(3, 8, 20, 0.94); backdrop-filter: blur(10px); display: none; align-items: center; justify-content: center; flex-direction: column; cursor: pointer; padding: 20px;" onclick="cerrarCustomLightbox()">
    <div style="position: absolute; top: 20px; right: 30px; font-size: 2.5rem; color: #ffffff; font-weight: bold; line-height: 1; cursor: pointer; text-shadow: 0 0 10px rgba(0,0,0,0.8);" onclick="cerrarCustomLightbox()">&times;</div>
    <div class="text-center" style="max-width: 90vw; max-height: 90vh;" onclick="event.stopPropagation()">
        <h5 class="text-white fw-bold mb-3"><i class="bi bi-image text-info me-2"></i> <span id="lightbox_titulo" class="text-info">Fotografía</span></h5>
        <img id="lightbox_img" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E" class="img-fluid rounded-4 border border-info shadow-lg mb-3" style="max-height: 75vh; max-width: 85vw; object-fit: contain; border-width: 3px !important; box-shadow: 0 15px 40px rgba(0,0,0,0.8) !important;">
        <div>
            <button type="button" class="btn btn-outline-light btn-sm rounded-pill px-4 py-1 fw-bold" onclick="cerrarCustomLightbox()">
                <i class="bi bi-x-circle me-1"></i> Cerrar Visualizador
            </button>
        </div>
    </div>
</div>

<!-- MENÚ CONTEXTUAL DE CLIC DERECHO EN ELEMENTOS Y PLANO -->
<div id="customCanvasContextMenu" class="dropdown-menu dropdown-menu-dark shadow-lg border border-secondary border-opacity-50 py-1" style="display: none; position: fixed; z-index: 10000; min-width: 220px; backdrop-filter: blur(10px); background: rgba(9, 26, 48, 0.95); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.7);">
    <div class="dropdown-header text-info fw-bold border-bottom border-secondary border-opacity-25 pb-1 mb-1" id="ctxMenuHeader">
        <i class="bi bi-gear-fill me-1"></i> Opción Componente
    </div>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptSeleccionar" onclick="ejecutarCtxAccion(event, 'seleccionar')">
        <i class="bi bi-check2-square text-warning me-2"></i> 🎯 Seleccionar Componente
    </a>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptSeleccionarTodo" onclick="ejecutarCtxAccion(event, 'seleccionar_todo')">
        <i class="bi bi-check2-all text-warning me-2"></i> ☑️ Seleccionar Todo (Ctrl+A)
    </a>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptCopiar" onclick="ejecutarCtxAccion(event, 'copiar')">
        <i class="bi bi-copy text-info me-2"></i> 📋 Copiar (Ctrl+C)
    </a>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptPegar" onclick="ejecutarCtxAccion(event, 'pegar')">
        <i class="bi bi-clipboard-plus text-success me-2"></i> 📥 Pegar (Ctrl+V)
    </a>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptDuplicar" onclick="ejecutarCtxAccion(event, 'duplicar')">
        <i class="bi bi-layers text-primary me-2"></i> 📋 Duplicar Componente (Ctrl+D)
    </a>
    <div class="dropdown-divider border-secondary border-opacity-25 my-1" id="ctxDivider1"></div>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptFicha" onclick="ejecutarCtxAccion(event, 'ficha')">
        <i class="bi bi-card-heading text-info me-2"></i> 📑 Ver Ficha Técnica
    </a>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptCentrar" onclick="ejecutarCtxAccion(event, 'centrar')">
        <i class="bi bi-crosshair text-success me-2"></i> 🎯 Centrar Vista en Plano
    </a>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptVertice" style="display:none;" onclick="ejecutarCtxAccion(event, 'vertice')">
        <i class="bi bi-geo-alt-fill text-purple me-2" style="color:#c084fc;"></i> 📍 Añadir Punto Doblez Aquí
    </a>
    <a class="dropdown-item small text-white py-1.5" href="#" id="ctxOptModificar" onclick="ejecutarCtxAccion(event, 'modificar')">
        <i class="bi bi-sliders text-primary me-2"></i> ✏️ Modificar Propiedades
    </a>
    <div class="dropdown-divider border-secondary border-opacity-25 my-1" id="ctxDivider2"></div>
    <a class="dropdown-item small text-danger py-1.5 fw-semibold" href="#" id="ctxOptEliminar" onclick="ejecutarCtxAccion(event, 'eliminar')">
        <i class="bi bi-trash-fill text-danger me-2"></i> 🗑️ Eliminar Componente (Supr)
    </a>
</div>

<!-- MODAL FORMULARIO SITE / RED -->
<div class="modal fade" id="modalInfraForm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-content-custom shadow-lg">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" id="form_accion" value="guardar_site">
                <input type="hidden" name="id" id="form_id" value="0">
                
                <div class="modal-header border-secondary border-opacity-25">
                    <h5 class="modal-title fw-bold text-white" id="modalFormTitle">
                        <i class="bi bi-plus-circle text-success me-2"></i> Nuevo Registro
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if ($seccion_activa === 'site'): ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Nombre SITE *</label>
                                <input type="text" name="nombre_site" id="field_nombre_site" class="form-control form-control-sm" required value="SITE Principal">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Ubicación Física</label>
                                <input type="text" name="ubicacion" id="field_ubicacion" class="form-control form-control-sm" placeholder="Ej. Edificio A, Piso 1, Sala 102">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold text-secondary mb-1">Climatización / Aire</label>
                                <input type="text" name="aire_acondicionado" id="field_aire_acondicionado" class="form-control form-control-sm" placeholder="Ej. MiniSplit Inverter LG">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold text-secondary mb-1">Capacidad (BTU)</label>
                                <input type="text" name="btu" id="field_btu" class="form-control form-control-sm" placeholder="Ej. 36,000 BTU">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold text-secondary mb-1">Temperatura Objetivo</label>
                                <input type="text" name="temperatura_objetivo" id="field_temperatura_objetivo" class="form-control form-control-sm" placeholder="Ej. 18°C - 21°C">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">UPS Principal</label>
                                <input type="text" name="ups_principal" id="field_ups_principal" class="form-control form-control-sm" placeholder="Ej. APC Smart-UPS Online">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Capacidad UPS</label>
                                <input type="text" name="cap_ups" id="field_cap_ups" class="form-control form-control-sm" placeholder="Ej. 10 kVA">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Control de Acceso</label>
                                <input type="text" name="control_acceso" id="field_control_acceso" class="form-control form-control-sm" placeholder="Ej. Biométrico + Tarjeta RFID">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Sistema Contra Incendios</label>
                                <input type="text" name="contra_incendio" id="field_contra_incendio" class="form-control form-control-sm" placeholder="Ej. Extintor Solkaflam / FM-200">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold text-secondary mb-1">Fotografía del SITE (Opcional)</label>
                                <input type="file" name="foto_site_file" class="form-control form-control-sm" accept="image/*">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold text-secondary mb-1">Observaciones</label>
                                <textarea name="observaciones" id="field_observaciones" rows="2" class="form-control form-control-sm"></textarea>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Nombre IDF / Gabinete *</label>
                                <input type="text" name="nombre_idf" id="field_nombre_idf" class="form-control form-control-sm" required placeholder="Ej. IDF Piso 2 - Taller">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Ubicación</label>
                                <input type="text" name="ubicacion" id="field_ubicacion" class="form-control form-control-sm" placeholder="Ej. Muro Norte, Área de Refacciones">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Rango de IPs / Segmentos</label>
                                <input type="text" name="rango_ips" id="field_rango_ips" class="form-control form-control-sm" placeholder="Ej. 192.168.10.0/24">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">VLANs Configuradas</label>
                                <input type="text" name="vlans" id="field_vlans" class="form-control form-control-sm" placeholder="Ej. VLAN 10 (Datos), VLAN 20 (Voz)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary mb-1">Switch Troncal Vinculado</label>
                                <input type="text" name="switch_principal" id="field_switch_principal" class="form-control form-control-sm" placeholder="Ej. Switch Cisco Catalyst 2960">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold text-secondary mb-1">Cantidad Puertos</label>
                                <input type="text" name="no_puertos" id="field_no_puertos" class="form-control form-control-sm" placeholder="Ej. 48">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold text-secondary mb-1">Cantidad Racks/U</label>
                                <input type="text" name="no_racks" id="field_no_racks" class="form-control form-control-sm" placeholder="Ej. Gabinete 12U">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold text-secondary mb-1">Fotografía del IDF (Opcional)</label>
                                <input type="file" name="foto_idf_file" class="form-control form-control-sm" accept="image/*">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold text-secondary mb-1">Notas Adicionales</label>
                                <textarea name="notas" id="field_notas" rows="2" class="form-control form-control-sm"></textarea>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm px-4 fw-bold">Guardar Registro</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL FORMULARIO NODO DE RED -->
<div class="modal fade" id="modalNodoForm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-content-custom shadow-lg">
            <form method="POST" action="infraestructura.php?sec=red&sub=nodos">
                <input type="hidden" name="accion" value="guardar_nodo">
                <input type="hidden" name="id" id="form_nodo_id" value="0">
                
                <div class="modal-header border-secondary border-opacity-25">
                    <h5 class="modal-title fw-bold text-white" id="modalNodoTitle">
                        <i class="bi bi-ethernet text-info me-2"></i> Registrar Nuevo Nodo de Red
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Código / Número de Nodo *</label>
                            <input type="text" name="codigo_nodo" id="field_codigo_nodo" class="form-control form-control-sm font-monospace text-info fw-bold" required placeholder="Ej. N-101 o NODO-VD-04">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Tipo de Nodo</label>
                            <select name="tipo_nodo" id="field_tipo_nodo" class="form-select form-select-sm">
                                <option value="Voz y Datos">📞💻 Voz y Datos (Híbrido)</option>
                                <option value="Datos Cat6">💻 Datos Cat6/6A</option>
                                <option value="Voz / Telefonía IP">📞 Voz / Telefonía IP</option>
                                <option value="Fibra Óptica">⚡ Fibra Óptica</option>
                                <option value="PoE Cámara / AP">📡 PoE Cámara / AP Wireless</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Ubicación Física / Departamento</label>
                            <input type="text" name="ubicacion" id="field_nodo_ubicacion" class="form-control form-control-sm" placeholder="Ej. Ventas - Escritorio 3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Patch Panel / Puerto</label>
                            <input type="text" name="patch_panel" id="field_patch_panel" class="form-control form-control-sm" placeholder="Ej. PP-A / Puerto 14">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1"><i class="bi bi-hdd-network text-info me-1"></i> Switch & Puerto Switch</label>
                            <div class="row g-1">
                                <div class="col-7">
                                    <select id="combo_nodo_switch_select" class="form-select form-select-sm" onchange="alCambiarSwitchEnNodoForm(this.value)">
                                        <option value="">-- Seleccionar Switch --</option>
                                        <?php if (!empty($registrosSwitches)): ?>
                                            <?php foreach ($registrosSwitches as $swOpt): ?>
                                                <?php 
                                                    $swCantP = intval($swOpt['cantidad_puertos'] ?? 48);
                                                    $swNomL = $swOpt['label'] ?? 'Switch';
                                                ?>
                                                <option value="<?php echo htmlspecialchars($swNomL); ?>" data-puertos="<?php echo $swCantP; ?>">
                                                    🖧 <?php echo htmlspecialchars($swNomL . " ({$swCantP}P)"); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-5">
                                    <select id="combo_nodo_puerto_select" class="form-select form-select-sm" onchange="alCambiarPuertoEnNodoForm(this.value)">
                                        <option value="">-- Puerto --</option>
                                    </select>
                                </div>
                            </div>
                            <input type="hidden" name="switch_puerto" id="field_switch_puerto">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">VLAN Asignada</label>
                            <input type="text" name="vlan" id="field_nodo_vlan" class="form-control form-control-sm" placeholder="Ej. VLAN 10">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary mb-1">Estatus</label>
                            <select name="estatus" id="field_nodo_estatus" class="form-select form-select-sm">
                                <option value="Activo">🟢 Activo</option>
                                <option value="Disponible">🔵 Disponible / Libre</option>
                                <option value="Mantenimiento">🟡 Mantenimiento</option>
                                <option value="Inactivo">🔴 Inactivo</option>
                            </select>
                        </div>
                        <!-- Pregunta Interactiva: ¿Tiene Teléfono PoE en serie? -->
                        <div class="col-12">
                            <div class="p-2.5 rounded-3" style="background: rgba(15, 23, 42, 0.75); border: 1.5px solid rgba(56, 189, 248, 0.35);">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <label class="form-label small fw-bold text-white mb-0">
                                            <i class="bi bi-telephone-fill text-warning me-1"></i> ¿Tiene Teléfono IP PoE conectado en serie?
                                        </label>
                                        <small class="text-secondary d-block" style="font-size: 0.73rem;">
                                            Permite compartir este nodo entre una computadora y un teléfono IP PoE conectados en cascada física.
                                        </small>
                                    </div>
                                    <div class="form-check form-switch fs-5 mb-0">
                                        <input class="form-check-input" type="checkbox" id="check_nodo_tiene_telefono" name="tiene_telefono_poe" value="1" onchange="toggleNodoTelefonoSection(this.checked)">
                                    </div>
                                </div>
                                <div id="box_nodo_telefono_select" style="display: none;" class="mt-2 pt-2 border-top border-secondary border-opacity-25">
                                    <label class="form-label micro text-info mb-1"><i class="bi bi-link-45deg me-1"></i> Seleccionar Teléfono PoE a enlazar:</label>
                                    <select name="telefono_poe_id" id="select_nodo_telefono_poe" class="form-select form-select-sm bg-dark text-white border-secondary">
                                        <option value="0">-- Conectado en serie (Sin asignar a uno específico aún) --</option>
                                        <?php if (!empty($telefonosPoeDisponibles)): ?>
                                            <?php foreach ($telefonosPoeDisponibles as $tOpt): ?>
                                                <option value="<?php echo $tOpt['id']; ?>" data-ext="<?php echo htmlspecialchars($tOpt['extension'] ?? ''); ?>" data-usuario="<?php echo htmlspecialchars($tOpt['usuario'] ?? ''); ?>">
                                                    📞 Ext. <?php echo htmlspecialchars($tOpt['extension'] ?: 'S/E'); ?> - <?php echo htmlspecialchars($tOpt['usuario'] ?: 'Sin Asignar'); ?> (<?php echo htmlspecialchars(($tOpt['modelo'] ?? 'PoE') . ' - IP: ' . ($tOpt['ip'] ?: 'Sin IP')); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary mb-1">Observaciones / Notas</label>
                            <textarea name="notas" id="field_nodo_notas" rows="2" class="form-control form-control-sm" placeholder="Detalles de cableado, roseta o remate..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info btn-sm px-4 fw-bold text-dark"><i class="bi bi-save me-1"></i> Guardar Nodo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL FORMULARIO VLAN -->
<div class="modal fade" id="modalVlanForm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-content-custom shadow-lg">
            <form method="POST" action="infraestructura.php?sec=red&sub=vlans">
                <input type="hidden" name="accion" value="guardar_vlan">
                <input type="hidden" name="id" id="form_vlan_id" value="0">
                
                <div class="modal-header border-secondary border-opacity-25">
                    <h5 class="modal-title fw-bold text-white" id="modalVlanTitle">
                        <i class="bi bi-diagram-3 text-warning me-2"></i> Registrar Nueva VLAN
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">ID de VLAN (Número) *</label>
                            <input type="number" name="vlan_id" id="field_vlan_id_num" class="form-control form-control-sm font-monospace text-warning fw-bold" required placeholder="Ej. 10" min="1" max="4094">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">Nombre de la VLAN *</label>
                            <input type="text" name="nombre_vlan" id="field_nombre_vlan" class="form-control form-control-sm fw-bold" required placeholder="Ej. VLAN_DATOS_VENTAS">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Segmento / Subred IP</label>
                            <input type="text" name="subred" id="field_subred" oninput="autoCalcularGatewayYDhcp(this.value)" class="form-control form-control-sm font-monospace text-info" placeholder="Ej. 192.168.10.0/24">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">Gateway (Puerta de Enlace)</label>
                            <input type="text" name="gateway" id="field_gateway" class="form-control form-control-sm font-monospace" placeholder="Ej. 192.168.10.1">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary mb-1">Rango DHCP</label>
                            <input type="text" name="dhcp_rango" id="field_dhcp_rango" class="form-control form-control-sm font-monospace" placeholder="Ej. 192.168.10.100 - 192.168.10.200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1">Estatus</label>
                            <select name="estatus" id="field_vlan_estatus" class="form-select form-select-sm">
                                <option value="Activa">🟢 Activa</option>
                                <option value="Inactiva">🔴 Inactiva</option>
                                <option value="Reservada">🟡 Reservada</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary mb-1">Descripción / Propósito</label>
                            <textarea name="descripcion" id="field_vlan_descripcion" rows="2" class="form-control form-control-sm" placeholder="Red exclusiva para estaciones de trabajo de ventas y facturación..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning btn-sm px-4 fw-bold shadow"><i class="bi bi-save me-1"></i> Guardar VLAN</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL FLOTANTE DETALLE TÉCNICO DE NODO DE RED -->
<div class="modal fade" id="modalDetalleNodo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom shadow-lg border border-info border-opacity-50">
            <div class="modal-header border-secondary border-opacity-25 pb-3" style="background: rgba(15, 23, 42, 0.95);">
                <div class="d-flex align-items-center gap-3">
                    <div class="nodo-icon-glow" style="width: 50px; height: 50px; font-size: 1.6rem;">
                        <i class="bi bi-ethernet"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title fw-bold text-white mb-0" id="detNodoCodigo">NODO-01</h5>
                            <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-0.5 small" id="detNodoEstatus">Activo</span>
                        </div>
                        <span class="text-info font-monospace small" id="detNodoTipo">Voz y Datos</span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-geo-alt text-info me-1"></i> Ubicación / Depto:</span>
                        <span class="fw-bold text-white fs-6" id="detNodoUbicacion">N/A</span>
                    </div>
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-diagram-3 text-warning me-1"></i> VLAN Asignada:</span>
                        <span class="fw-bold font-monospace text-warning fs-6" id="detNodoVlan">No asignada</span>
                    </div>

                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-hdd-network text-primary me-1"></i> Patch Panel / Puerto:</span>
                        <span class="fw-bold font-monospace text-info fs-6" id="detNodoPatchPanel">N/A</span>
                    </div>
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-cpu text-purple me-1" style="color:#c084fc;"></i> Switch & Puerto:</span>
                        <span class="fw-bold font-monospace fs-6" style="color:#c084fc;" id="detNodoSwitchPuerto">N/A</span>
                    </div>

                    <div class="col-12" id="detNodoGroupTelefono" style="display:none;">
                        <div class="p-2.5 rounded-3 bg-dark border border-warning border-opacity-50 d-flex align-items-center justify-content-between">
                            <span class="text-secondary small fw-bold"><i class="bi bi-telephone-fill text-warning me-1.5"></i> Teléfono PoE Asignado:</span>
                            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning font-monospace px-2.5 py-1 fs-6" id="detNodoTelefonoExtension">--</span>
                        </div>
                    </div>

                    <div class="col-12" id="detNodoGroupNotas" style="display:none;">
                        <div class="p-3 rounded-3 bg-dark border border-secondary border-opacity-50 mt-1">
                            <span class="text-secondary small d-block mb-1 fw-bold"><i class="bi bi-journal-text me-1"></i> Observaciones / Notas:</span>
                            <span class="text-light italic small" id="detNodoNotas">--</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25 justify-content-between">
                <div>
                    <form id="formEliminarNodoFlotante" method="POST" action="infraestructura.php?sec=red&sub=nodos" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este Nodo de Red?');" class="d-inline">
                        <input type="hidden" name="accion" value="eliminar_registro">
                        <input type="hidden" name="tipo_tabla" value="nodo">
                        <input type="hidden" name="registro_id" id="detNodoEliminarId" value="0">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-3">
                            <i class="bi bi-trash-fill me-1"></i> Eliminar
                        </button>
                    </form>
                </div>
                <div class="d-flex gap-2">
                    <a href="reporte_nodos.php" id="btnImprimirNodoIndividual" target="_blank" class="btn btn-outline-info btn-sm rounded-3 fw-bold px-3" title="Imprimir Cédula de este nodo en PDF">
                        <i class="bi bi-printer-fill me-1 text-warning"></i> Imprimir Ficha
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm rounded-3 px-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" id="btnEditarNodoFlotante" class="btn btn-warning btn-sm rounded-3 fw-bold px-3">
                        <i class="bi bi-pencil-square me-1"></i> Editar Nodo
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL FLOTANTE DETALLE TÉCNICO COMPLETO DE VLAN -->
<div class="modal fade" id="modalDetalleVlan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom shadow-lg border border-warning border-opacity-50">
            <div class="modal-header border-secondary border-opacity-25 pb-3" style="background: rgba(15, 23, 42, 0.95);">
                <div class="d-flex align-items-center gap-3">
                    <div class="nodo-icon-glow" style="width: 50px; height: 50px; font-size: 1.6rem; background: rgba(245, 158, 11, 0.15); border-color: #f59e0b; color: #f59e0b; box-shadow: 0 0 20px rgba(245, 158, 11, 0.4);">
                        <i class="bi bi-diagram-3-fill"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title fw-bold text-white mb-0" id="detVlanNombre">VLAN_NOMBRE</h5>
                            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning px-2.5 py-0.5 font-monospace fs-7" id="detVlanTag">VLAN 10</span>
                            <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-0.5 small" id="detVlanEstatus">Activa</span>
                        </div>
                        <span class="text-secondary font-monospace small">Tag IEEE 802.1Q • Segmentación L2/L3</span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-globe text-info me-1"></i> Segmento / Subred IP:</span>
                        <span class="fw-bold font-monospace text-info fs-6" id="detVlanSubred">N/A</span>
                    </div>
                    <div class="col-6">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-door-open text-warning me-1"></i> Puerta de Enlace (GW):</span>
                        <span class="fw-bold font-monospace text-warning fs-6" id="detVlanGateway">N/A</span>
                    </div>

                    <div class="col-12">
                        <span class="text-secondary small d-block mb-1"><i class="bi bi-shuffle text-success me-1"></i> Rango Pool DHCP:</span>
                        <span class="fw-bold font-monospace text-light fs-6" id="detVlanDhcp">N/A</span>
                    </div>

                    <div class="col-12" id="detVlanGroupDesc" style="display:none;">
                        <div class="p-3 rounded-3 bg-dark border border-secondary border-opacity-50 mt-1">
                            <span class="text-secondary small d-block mb-1 fw-bold"><i class="bi bi-journal-text me-1"></i> Descripción / Propósito:</span>
                            <span class="text-light italic small" id="detVlanDescripcion">--</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25 justify-content-between">
                <div>
                    <form id="formEliminarVlanFlotante" method="POST" action="infraestructura.php?sec=red&sub=vlans" onsubmit="return confirm('¿Estás seguro de que deseas eliminar esta VLAN?');" class="d-inline">
                        <input type="hidden" name="accion" value="eliminar_registro">
                        <input type="hidden" name="tipo_tabla" value="vlan">
                        <input type="hidden" name="registro_id" id="detVlanEliminarId" value="0">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-3">
                            <i class="bi bi-trash-fill me-1"></i> Eliminar VLAN
                        </button>
                    </form>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm rounded-3 px-3" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" id="btnEditarVlanFlotante" class="btn btn-warning btn-sm rounded-3 fw-bold px-3">
                        <i class="bi bi-pencil-square me-1"></i> Editar VLAN
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL PARA EDITAR COMPONENTE EN PLANO 2D/3D SITE -->
<div class="modal fade" id="modalEditarObjeto2D" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom border border-warning border-opacity-50 shadow-lg">
            <div class="modal-header border-secondary border-opacity-25 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="nav-icon-box" style="width: 44px; height: 44px; font-size: 1.3rem; background: rgba(234, 179, 8, 0.2); color: #eab308; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i id="modalIcon2DObj" class="bi bi-sliders"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0">Editar Componente 2D / 3D</h5>
                        <span class="badge bg-dark border border-warning text-warning font-monospace micro mt-1" id="modalBadgeTipo2D">CONFIGURACIÓN SITE</span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Etiqueta / Nombre del Componente *</label>
                    <input type="text" id="modalInputNombre2D" class="form-control form-control-sm bg-black text-warning font-monospace border-warning border-opacity-50" placeholder="Ej. RACK PRINCIPAL A1">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Tipo de Componente</label>
                        <select id="modalSelectTipo2D" class="form-select form-select-sm bg-dark text-white border-secondary" onchange="alCambiarTipoEnModal2D()">
                            <option value="rack">📦 Rack 42U</option>
                            <option value="minisplit">❄️ Minisplit Inverter</option>
                            <option value="extintor">🧯 Extintor</option>
                            <option value="ups">🔋 UPS / Respaldo</option>
                            <option value="puerta">🚪 Puerta / Acceso</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Rotación en Plano</label>
                        <select id="modalSelectRot2D" class="form-select form-select-sm bg-dark text-white border-secondary">
                            <option value="0">0° (Horizontal)</option>
                            <option value="90">90° (Vertical)</option>
                            <option value="180">180° (Invertido)</option>
                            <option value="270">270° (Vertical opuesto)</option>
                        </select>
                    </div>
                </div>

                <div class="p-3 mb-3 rounded-3 bg-black bg-opacity-50 border border-secondary border-opacity-30">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-warning fw-semibold micro"><i class="bi bi-aspect-ratio me-1"></i> TAMAÑO Y DIMENSIONES (PX)</span>
                        <button type="button" class="btn btn-link btn-sm text-info p-0 text-decoration-none micro" onclick="aplicarTamanoEstandardModal2D()">
                            <i class="bi bi-magic me-1"></i> Restablecer Tamaño Estándar
                        </button>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <span class="text-secondary micro d-block mb-1">Ancho (px):</span>
                            <input type="number" id="modalInputAncho2D" class="form-control form-control-sm bg-dark text-white text-center border-secondary" min="15" max="400" step="5">
                        </div>
                        <div class="col-6">
                            <span class="text-secondary micro d-block mb-1">Largo / Profundidad (px):</span>
                            <input type="number" id="modalInputLargo2D" class="form-control form-control-sm bg-dark text-white text-center border-secondary" min="15" max="400" step="5">
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Posición X (px)</label>
                        <input type="number" id="modalInputPosX2D" class="form-control form-control-sm bg-dark text-white text-center border-secondary" step="5">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Posición Y (px)</label>
                        <input type="number" id="modalInputPosY2D" class="form-control form-control-sm bg-dark text-white text-center border-secondary" step="5">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-info">Elevación Z 3D (px)</label>
                        <input type="number" id="modalInputPosZ2D" class="form-control form-control-sm bg-dark text-info text-center border-info border-opacity-50" step="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Color de Borde / Chasis</label>
                        <input type="color" id="modalInputColor2D" class="form-control form-control-sm form-control-color w-100 bg-dark border-secondary" value="#38bdf8">
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary border-opacity-25 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="eliminarObjetoDesdeModal2D()">
                    <i class="bi bi-trash-fill me-1"></i> Eliminar Objeto
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning btn-sm rounded-pill px-4 fw-bold text-dark" onclick="guardarCambiosModal2D()">
                        <i class="bi bi-check-circle-fill me-1"></i> Guardar Cambios
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
