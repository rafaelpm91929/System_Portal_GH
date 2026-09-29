// =========================================================================
// JS INFRAESTRUCTURA - SECCIÓN RED Y VLANS
// =========================================================================

let currentRedSubseccion = window.currentRedSubseccion || new URLSearchParams(window.location.search).get('sub') || 'menu';

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('redMenuPrincipal')) {
        const initialSub = window.currentRedSubseccion || new URLSearchParams(window.location.search).get('sub') || 'menu';
        mostrarSubseccionRed(initialSub);
    }
});

function mostrarSubseccionRed(tipo) {
    currentRedSubseccion = tipo;
    window.currentRedSubseccion = tipo;
    const menu = document.getElementById('redMenuPrincipal');
    const secNodos = document.getElementById('subseccionRedNodos');
    const secVlans = document.getElementById('subseccionRedVlans');
    const secSwitches = document.getElementById('subseccionRedSwitches');

    if (menu) menu.style.display = 'none';
    if (secNodos) secNodos.style.display = 'none';
    if (secVlans) secVlans.style.display = 'none';
    if (secSwitches) secSwitches.style.display = 'none';

    if (tipo === 'nodos') {
        if (secNodos) secNodos.style.display = 'block';
    } else if (tipo === 'vlans') {
        if (secVlans) secVlans.style.display = 'block';
    } else if (tipo === 'switches') {
        if (secSwitches) secSwitches.style.display = 'block';
    } else {
        if (menu) menu.style.display = 'block';
    }

    if (history.replaceState && tipo) {
        const url = new URL(window.location);
        url.searchParams.set('sec', 'red');
        url.searchParams.set('sub', tipo);
        history.replaceState(null, '', url);
    }
}

function volverAMenuRed() {
    mostrarSubseccionRed('menu');
}

function abrirModalNuevoRed(tipoTarget) {
    const target = tipoTarget || currentRedSubseccion;
    if (target === 'nodo' || target === 'nodos') {
        document.getElementById('form_nodo_id').value = '0';
        document.getElementById('field_codigo_nodo').value = '';
        document.getElementById('field_nodo_ubicacion').value = '';
        document.getElementById('field_patch_panel').value = '';
        document.getElementById('field_switch_puerto').value = '';
        document.getElementById('field_nodo_vlan').value = '';
        document.getElementById('field_nodo_notas').value = '';
        const comboSw = document.getElementById('combo_nodo_switch_select');
        const comboPt = document.getElementById('combo_nodo_puerto_select');
        if (comboSw) comboSw.value = '';
        if (comboPt) comboPt.innerHTML = '<option value="">-- Puerto --</option>';

        const checkTel = document.getElementById('check_nodo_tiene_telefono');
        const selTel = document.getElementById('select_nodo_telefono_poe');
        const boxTel = document.getElementById('box_nodo_telefono_select');
        if (checkTel) checkTel.checked = false;
        if (selTel) selTel.value = '0';
        if (boxTel) boxTel.style.display = 'none';

        document.getElementById('modalNodoTitle').innerHTML = '<i class="bi bi-ethernet text-info me-2"></i> Registrar Nuevo Nodo de Red';
        new bootstrap.Modal(document.getElementById('modalNodoForm')).show();
    } else if (target === 'vlan' || target === 'vlans') {
        document.getElementById('form_vlan_id').value = '0';
        document.getElementById('field_vlan_id_num').value = '';
        document.getElementById('field_nombre_vlan').value = '';
        document.getElementById('field_subred').value = '';
        document.getElementById('field_gateway').value = '';
        document.getElementById('field_dhcp_rango').value = '';
        document.getElementById('field_vlan_descripcion').value = '';
        document.getElementById('modalVlanTitle').innerHTML = '<i class="bi bi-diagram-3 text-warning me-2"></i> Registrar Nueva VLAN';
        new bootstrap.Modal(document.getElementById('modalVlanForm')).show();
    } else if (target === 'switch' || target === 'switches') {
        const mInfra = document.getElementById('modalInfraForm');
        if (mInfra) {
            document.getElementById('form_accion').value = 'guardar_red';
            document.getElementById('form_id').value = '0';
            const fNombre = document.getElementById('field_nombre_idf');
            if (fNombre) fNombre.value = 'Switch Core / Distribución';
            const fSw = document.getElementById('field_switch_principal');
            if (fSw) fSw.value = '';
            document.getElementById('modalFormTitle').innerHTML = '<i class="bi bi-hdd-network-fill text-success me-2"></i> Registrar Nuevo Switch de Red';
            new bootstrap.Modal(mInfra).show();
        }
    }
}

function toggleNodoTelefonoSection(isChecked) {
    const box = document.getElementById('box_nodo_telefono_select');
    if (box) box.style.display = isChecked ? 'block' : 'none';
}

function abrirModalEditarNodo(data) {
    document.getElementById('form_nodo_id').value = data.id || '0';
    document.getElementById('field_codigo_nodo').value = data.codigo_nodo || '';
    document.getElementById('field_tipo_nodo').value = data.tipo_nodo || 'Voz y Datos';
    document.getElementById('field_nodo_ubicacion').value = data.ubicacion || '';
    document.getElementById('field_patch_panel').value = data.patch_panel || '';
    document.getElementById('field_switch_puerto').value = data.switch_puerto || '';
    document.getElementById('field_nodo_vlan').value = data.vlan || '';
    document.getElementById('field_nodo_estatus').value = data.estatus || 'Activo';
    document.getElementById('field_nodo_notas').value = data.notas || '';

    // Sincronizar switch y teléfono PoE en serie
    const checkTel = document.getElementById('check_nodo_tiene_telefono');
    const selTel = document.getElementById('select_nodo_telefono_poe');
    const boxTel = document.getElementById('box_nodo_telefono_select');
    const tieneTel = parseInt(data.tiene_telefono_poe || 0) === 1 || (data.telefono_poe_id && parseInt(data.telefono_poe_id) > 0);
    if (checkTel) checkTel.checked = tieneTel;
    if (selTel) selTel.value = data.telefono_poe_id || '0';
    if (boxTel) boxTel.style.display = tieneTel ? 'block' : 'none';

    // Sincronizar combos de Switch y Puerto
    const swPuertoStr = data.switch_puerto || '';
    const comboSw = document.getElementById('combo_nodo_switch_select');
    const comboPt = document.getElementById('combo_nodo_puerto_select');
    if (comboSw && comboPt) {
        if (swPuertoStr.indexOf('/') !== -1) {
            const partes = swPuertoStr.split('/');
            const swNom = partes[0].trim();
            const ptNom = partes[1].trim();
            comboSw.value = swNom;
            alCambiarSwitchEnNodoForm(swNom);
            comboPt.value = ptNom;
        } else if (swPuertoStr) {
            comboSw.value = swPuertoStr;
            alCambiarSwitchEnNodoForm(swPuertoStr);
        } else {
            comboSw.value = '';
            comboPt.innerHTML = '<option value="">-- Puerto --</option>';
        }
    }

    document.getElementById('modalNodoTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Nodo de Red #' + (data.codigo_nodo || data.id);
    new bootstrap.Modal(document.getElementById('modalNodoForm')).show();
}

function abrirModalEditarVlan(data) {
    document.getElementById('form_vlan_id').value = data.id || '0';
    document.getElementById('field_vlan_id_num').value = data.vlan_id || '';
    document.getElementById('field_nombre_vlan').value = data.nombre_vlan || '';
    document.getElementById('field_subred').value = data.subred || '';
    document.getElementById('field_gateway').value = data.gateway || '';
    document.getElementById('field_dhcp_rango').value = data.dhcp_rango || '';
    document.getElementById('field_vlan_estatus').value = data.estatus || 'Activa';
    document.getElementById('field_vlan_descripcion').value = data.descripcion || '';
    document.getElementById('modalVlanTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar VLAN ' + (data.vlan_id ? 'VLAN ' + data.vlan_id : '#' + data.id);
    new bootstrap.Modal(document.getElementById('modalVlanForm')).show();
}

function filtrarVlansDiagrama() {
    const query = (document.getElementById('vlanSearchInput')?.value || '').toLowerCase().trim();
    const items = document.querySelectorAll('.vlan-card-item');
    items.forEach(card => {
        const name = card.getAttribute('data-vlan-name') || '';
        const id = card.getAttribute('data-vlan-id') || '';
        const subred = card.getAttribute('data-vlan-subred') || '';
        if (query === '' || name.includes(query) || id.includes(query) || subred.includes(query)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function autoCalcularGatewayYDhcp(val) {
    if (!val) return;
    const match = val.trim().match(/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.0(?:\/\d+)?$/);
    if (match) {
        const prefix = match[1];
        const gwField = document.getElementById('field_gateway');
        const dhcpField = document.getElementById('field_dhcp_rango');
        if (gwField && !gwField.value) gwField.value = prefix + '.1';
        if (dhcpField && !dhcpField.value) dhcpField.value = prefix + '.100 - ' + prefix + '.250';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function verDetalleNodoFlotante(data) {
    if (!data) return;
    document.getElementById('detNodoCodigo').innerText = data.codigo_nodo || 'Sin código';
    document.getElementById('detNodoEstatus').innerText = data.estatus || 'Activo';
    document.getElementById('detNodoTipo').innerText = data.tipo_nodo || 'Voz y Datos';
    document.getElementById('detNodoUbicacion').innerText = data.ubicacion || 'No especificada';
    document.getElementById('detNodoVlan').innerText = data.vlan || 'No asignada';
    document.getElementById('detNodoPatchPanel').innerText = data.patch_panel || 'No asignado';
    document.getElementById('detNodoSwitchPuerto').innerText = data.switch_puerto || 'No asignado';
    document.getElementById('detNodoEliminarId').value = data.id || '0';

    const groupNotas = document.getElementById('detNodoGroupNotas');
    const txtNotas = document.getElementById('detNodoNotas');
    if (data.notas && data.notas.trim() !== '') {
        txtNotas.innerText = data.notas;
        groupNotas.style.display = 'block';
    } else {
        groupNotas.style.display = 'none';
    }

    const groupTel = document.getElementById('detNodoGroupTelefono');
    const txtTel = document.getElementById('detNodoTelefonoExtension');
    let extTel = data.telefono_extension || '';
    if (!extTel && data.telefono_poe_info) {
        const m = String(data.telefono_poe_info).match(/Ext\.?\s*([0-9A-Za-z]+)/i);
        if (m) extTel = m[1];
    }
    if (extTel) {
        if (txtTel) txtTel.innerText = 'Ext. ' + extTel;
        if (groupTel) groupTel.style.display = 'block';
    } else {
        if (groupTel) groupTel.style.display = 'none';
    }

    const btnImprimir = document.getElementById('btnImprimirNodoIndividual');
    if (btnImprimir && data.codigo_nodo) {
        btnImprimir.href = 'reporte_nodos.php?nodo=' + encodeURIComponent(data.codigo_nodo);
    }

    const btnEditar = document.getElementById('btnEditarNodoFlotante');
    if (btnEditar) {
        btnEditar.onclick = function() {
            const modalDet = bootstrap.Modal.getInstance(document.getElementById('modalDetalleNodo'));
            if (modalDet) modalDet.hide();
            abrirModalEditarNodo(data);
        };
    }

    new bootstrap.Modal(document.getElementById('modalDetalleNodo')).show();
}

function filtrarNodosFlotantes() {
    const query = (document.getElementById('nodoSearchInput')?.value || '').toLowerCase().trim();
    const items = document.querySelectorAll('.nodo-card-item');
    items.forEach(card => {
        const codigo = card.getAttribute('data-codigo') || '';
        const ubicacion = card.getAttribute('data-ubicacion') || '';
        const sw = card.getAttribute('data-switch') || '';
        const vlan = card.getAttribute('data-vlan') || '';

        if (query === '' || codigo.includes(query) || ubicacion.includes(query) || sw.includes(query) || vlan.includes(query)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function verDetalleVlanFlotante(data) {
    if (!data) return;
    document.getElementById('detVlanNombre').innerText = data.nombre_vlan || 'VLAN';
    document.getElementById('detVlanTag').innerText = 'VLAN ' + (data.vlan_id || '0');
    document.getElementById('detVlanEstatus').innerText = data.estatus || 'Activa';
    document.getElementById('detVlanSubred').innerText = data.subred || 'Sin subred';
    document.getElementById('detVlanGateway').innerText = data.gateway || 'No asignado';
    document.getElementById('detVlanDhcp').innerText = data.dhcp_rango || 'No configurado';
    document.getElementById('detVlanEliminarId').value = data.id || '0';

    const groupDesc = document.getElementById('detVlanGroupDesc');
    const txtDesc = document.getElementById('detVlanDescripcion');
    if (data.descripcion && data.descripcion.trim() !== '') {
        txtDesc.innerText = data.descripcion;
        groupDesc.style.display = 'block';
    } else {
        groupDesc.style.display = 'none';
    }

    const btnEditar = document.getElementById('btnEditarVlanFlotante');
    if (btnEditar) {
        btnEditar.onclick = function() {
            const modalDet = bootstrap.Modal.getInstance(document.getElementById('modalDetalleVlan'));
            if (modalDet) modalDet.hide();
            abrirModalEditarVlan(data);
        };
    }

    new bootstrap.Modal(document.getElementById('modalDetalleVlan')).show();
}

function filtrarSwitchesRed() {
    const input = document.getElementById('switchSearchInput');
    if (!input) return;
    const q = input.value.toLowerCase().trim();
    const items = document.querySelectorAll('.switch-card-item');
    items.forEach(card => {
        const name = card.getAttribute('data-switch-name') || '';
        const ip = card.getAttribute('data-switch-ip') || '';
        const ubic = card.getAttribute('data-switch-ubic') || '';
        if (q === '' || name.includes(q) || ip.includes(q) || ubic.includes(q)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function filtrarNodosPorSwitch(swNombre) {
    mostrarSubseccionRed('nodos');
    const searchInput = document.getElementById('nodoSearchInput');
    if (searchInput) {
        searchInput.value = swNombre;
        if (typeof filtrarNodosFlotantes === 'function') {
            filtrarNodosFlotantes();
        }
    }
}

function verDetalleSwitchFlotante(swData) {
    if (!swData) return;
    const mModal = document.getElementById('modalFichaTecnicaElemento');
    if (!mModal) return;

    const fn = document.getElementById('fichaNombre');
    const ft = document.getElementById('fichaTipo');
    const fip = document.getElementById('fichaIP');
    const fdept = document.getElementById('fichaDept');
    const fcoords = document.getElementById('fichaCoords');
    const fdim = document.getElementById('fichaDim');
    const fgrpInv = document.getElementById('fichaGroupInventario');
    const finvUser = document.getElementById('fichaInvUsuario');
    const finvDept = document.getElementById('fichaInvDeptPuesto');
    const finvMarcaMod = document.getElementById('fichaInvMarcaModelo');
    const finvSerie = document.getElementById('fichaInvSerie');
    const finvModulo = document.getElementById('fichaInvModulo');

    if (fn) fn.textContent = swData.label || 'Switch de Red';
    if (ft) ft.textContent = 'SWITCH DE RED & DISTRIBUCIÓN';
    if (fip) fip.textContent = swData.ip || 'No asignada';
    if (fdept) fdept.textContent = swData.ubicacion || 'SITE / IDF';
    if (fcoords) fcoords.textContent = swData.ubicacion || 'SITE';
    if (fdim) fdim.textContent = '19" Rackeable 1U / 24P';

    if (fgrpInv) {
        fgrpInv.style.display = 'block';
        if (finvUser) finvUser.textContent = swData.usuario || 'Infraestructura TI';
        if (finvDept) finvDept.textContent = swData.departamento || swData.ubicacion || 'Sistemas';
        if (finvMarcaMod) finvMarcaMod.textContent = swData.sub || swData.tipo || '--';
        if (finvSerie) finvSerie.textContent = swData.serie || 'Sin Serie';
        if (finvModulo) finvModulo.textContent = swData.origen ? String(swData.origen).toUpperCase() : 'INVENTARIO RED';
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modalObj = bootstrap.Modal.getOrCreateInstance(mModal);
        modalObj.show();
    }
}

// =========================================================================
// VISTA 2D PUERTOS DE SWITCH (INTERACTIVA, LEDS, ASIGNACIÓN INVENTARIO)
// =========================================================================
let currentSwitch2D = null;
let currentSwitchPuertosMap = {};
let currentFiltroPuertos2D = 'todos';

/**
 * Abre el visualizador 2D interactivo del frontal de puertos del Switch
 * @param {Object} swData Datos del Switch
 */
function abrirVistaPuertosSwitch2D(swData) {
    if (!swData) return;
    currentSwitch2D = swData;
    currentSwitchPuertosMap = {};
    currentFiltroPuertos2D = 'todos';

    // Determinar cantidad de puertos exacta
    let portCount = parseInt(swData.cantidad_puertos || swData.puertos || swData.raw?.cantidad_puertos || 0, 10);
    if (!portCount || isNaN(portCount) || portCount < 4) {
        const str = (String(swData.sub || '') + ' ' + String(swData.label || '') + ' ' + String(swData.raw?.modelo || '')).toLowerCase();
        const m = str.match(/\b(8|12|16|24|48|52)\b/);
        portCount = m ? parseInt(m[1], 10) : 48;
    }
    swData.puertosCalculados = portCount;

    // Actualizar cabecera del modal
    const elNombre = document.getElementById('sw2d_titulo_nombre');
    const elBadgeP = document.getElementById('sw2d_badge_puertos');
    const elIp = document.getElementById('sw2d_ip_texto');
    const elModelo = document.getElementById('sw2d_modelo_texto');
    const elUbic = document.getElementById('sw2d_ubicacion_texto');
    const elBrand = document.getElementById('sw2d_chassis_brand');
    const elChassisMod = document.getElementById('sw2d_chassis_model');

    if (elNombre) elNombre.textContent = swData.label || 'Switch de Red';
    if (elBadgeP) elBadgeP.textContent = portCount + ' Puertos';
    if (elIp) elIp.textContent = swData.ip || 'Sin IP';
    if (elModelo) elModelo.textContent = swData.sub || swData.tipo || 'Gigabit Managed Switch';
    if (elUbic) elUbic.textContent = swData.ubicacion || 'SITE Principal';
    if (elBrand) elBrand.textContent = (swData.label || 'SWITCH').toUpperCase();
    if (elChassisMod) elChassisMod.textContent = (swData.sub || 'GIGABIT ETHERNET ' + portCount + 'P').toUpperCase();
    
    const elBrandText = document.getElementById('sw2d_chassis_brand_text');
    if (elBrandText) {
        elBrandText.textContent = 'USW-' + portCount;
    }

    // Resetear filtros visuales
    document.querySelectorAll('#modalSwitch2DPuertos .btn-group button').forEach(b => b.classList.remove('active'));
    const btnTodos = document.getElementById('btnSwFilterTodos');
    if (btnTodos) btnTodos.classList.add('active');
    const inpSearch = document.getElementById('inputBuscarPuertoEnSwitch2D');
    if (inpSearch) inpSearch.value = '';

    // Cargar asignaciones de puertos desde el servidor
    const formData = new FormData();
    formData.append('accion', 'obtener_puertos_switch');
    formData.append('switch_nombre', swData.label || '');
    formData.append('switch_key', swData.key || '');

    fetch('infraestructura.php?sec=red', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data && data.puertos && Array.isArray(data.puertos)) {
            data.puertos.forEach(p => {
                const num = parseInt(p.puerto_numero, 10);
                if (num > 0) {
                    currentSwitchPuertosMap[num] = p;
                }
            });
        }
        renderizarPuertosSwitch2D(swData, currentSwitchPuertosMap);
    })
    .catch(() => {
        // Renderizar aún si falla la conexión
        renderizarPuertosSwitch2D(swData, currentSwitchPuertosMap);
    });

    const mModal = document.getElementById('modalSwitch2DPuertos');
    if (mModal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modalObj = bootstrap.Modal.getOrCreateInstance(mModal);
        modalObj.show();
    }
}

/**
 * Renderiza la matriz 2D de puertos en el chasis del Switch estilo UniFi USW Silver (Imagen 1)
 */
function renderizarPuertosSwitch2D(swData, puertosMap) {
    const container = document.getElementById('sw2d_ports_grid_wrapper');
    const sfpContainer = document.getElementById('sw2d_sfp_unifi_box');
    if (!container) return;
    container.innerHTML = '';
    if (sfpContainer) sfpContainer.innerHTML = '';

    const portCount = swData.puertosCalculados || 48;
    
    // Determinar bancos de puertos (UniFi agrupa en bloques de 12 puertos para 24 y 48, o bloques de 8 para 16)
    let portsPerBank = (portCount === 16) ? 8 : (portCount % 12 === 0 ? 12 : 8);
    let totalBanks = Math.ceil(portCount / portsPerBank);

    let html = '';
    let portIdx = 1;
    let totalOcupados = 0;
    let enlacesArray = [];

    for (let b = 0; b < totalBanks; b++) {
        html += `<div class="sw-unifi-port-bank d-flex align-items-center">`;
        
        let bankCols = portsPerBank / 2; // Columnas por banco (2 filas: impar arriba, par abajo)
        for (let c = 0; c < bankCols; c++) {
            if (portIdx > portCount) break;

            const pTop = portIdx;
            const pBottom = portIdx + 1;
            portIdx += 2;

            // Datos del puerto superior
            const infoTop = puertosMap[pTop] || null;
            const isTopActive = !!infoTop;
            if (isTopActive) {
                totalOcupados++;
                enlacesArray.push(infoTop);
            }

            // Datos del puerto inferior
            const infoBottom = (pBottom <= portCount) ? (puertosMap[pBottom] || null) : null;
            const isBottomActive = !!infoBottom;
            if (isBottomActive) {
                totalOcupados++;
                enlacesArray.push(infoBottom);
            }

            const extTopStr = (infoTop && infoTop.telefono_poe_ext) ? ` [Ext. ${infoTop.telefono_poe_ext}]` : '';
            const titleTop = isTopActive ? 
                `Puerto ${pTop}: [${infoTop.equipo_nombre}]${extTopStr} (IP: ${infoTop.equipo_ip || 'N/A'}) - Clic para gestionar` : 
                `Puerto ${pTop}: DISPONIBLE / LIBRE - Clic para asignar`;

            const extBottomStr = (infoBottom && infoBottom.telefono_poe_ext) ? ` [Ext. ${infoBottom.telefono_poe_ext}]` : '';
            const titleBottom = (pBottom <= portCount) ? (isBottomActive ? 
                `Puerto ${pBottom}: [${infoBottom.equipo_nombre}]${extBottomStr} (IP: ${infoBottom.equipo_ip || 'N/A'}) - Clic para gestionar` : 
                `Puerto ${pBottom}: DISPONIBLE / LIBRE - Clic para asignar`) : '';

            html += `
                <div class="sw-unifi-port-col">
                    <!-- NÚMERO SUPERIOR CON RAYO POE (IMPAR) -->
                    <span class="sw-unifi-port-num-label">
                        ${pTop}<span class="sw-unifi-poe-icon">⚡</span>
                    </span>

                    <!-- JACK RJ45 SUPERIOR -->
                    <div class="sw-unifi-rj45-box sw-port-item ${isTopActive ? 'port-active' : 'port-empty'}" 
                         data-port="${pTop}" 
                         data-assigned="${isTopActive ? '1' : '0'}"
                         data-eq-name="${isTopActive ? (infoTop.equipo_nombre || '').toLowerCase() : ''}"
                         data-eq-ip="${isTopActive ? (infoTop.equipo_ip || '').toLowerCase() : ''}"
                         onclick="abrirModalAsignarPuertoSwitch(${pTop})" 
                         title="${titleTop}">
                        
                        <!-- DUAL LEDS SUPERIOR (PoE ámbar izquierda, Link verde derecha) -->
                        <div class="sw-unifi-leds-bar">
                            <span class="sw-unifi-led sw-unifi-led-poe-active" title="PoE Activo"></span>
                            <span class="sw-unifi-led ${isTopActive ? 'sw-unifi-led-link-active' : ''}" title="${isTopActive ? 'Link 1 Gbps' : 'Sin Enlace'}"></span>
                        </div>

                        <!-- CAVIDAD RJ45 CON PINES DORADOS -->
                        <div class="sw-unifi-jack-cavity">
                            <span class="sw-unifi-jack-pins"></span>
                            ${isTopActive ? '<i class="bi bi-ethernet" style="font-size: 0.65rem; color: #38bdf8; position: relative; z-index: 2;"></i>' : ''}
                            <span class="sw-unifi-jack-tab"></span>
                        </div>
                    </div>

                    <!-- PUERTO INFERIOR (PAR) -->
                    ${(pBottom <= portCount) ? `
                        <!-- JACK RJ45 INFERIOR -->
                        <div class="sw-unifi-rj45-box sw-port-item mt-1 ${isBottomActive ? 'port-active' : 'port-empty'}" 
                             data-port="${pBottom}" 
                             data-assigned="${isBottomActive ? '1' : '0'}"
                             data-eq-name="${isBottomActive ? (infoBottom.equipo_nombre || '').toLowerCase() : ''}"
                             data-eq-ip="${isBottomActive ? (infoBottom.equipo_ip || '').toLowerCase() : ''}"
                             onclick="abrirModalAsignarPuertoSwitch(${pBottom})" 
                             title="${titleBottom}">
                            
                            <!-- CAVIDAD RJ45 INFERIOR -->
                            <div class="sw-unifi-jack-cavity">
                                <span class="sw-unifi-jack-tab" style="top: 0; bottom: auto; border-top: none; border-bottom: 1px solid #334155;"></span>
                                ${isBottomActive ? '<i class="bi bi-ethernet" style="font-size: 0.65rem; color: #38bdf8; position: relative; z-index: 2;"></i>' : ''}
                                <span class="sw-unifi-jack-pins" style="bottom: 1px; top: auto;"></span>
                            </div>

                            <!-- DUAL LEDS INFERIOR -->
                            <div class="sw-unifi-leds-bar">
                                <span class="sw-unifi-led sw-unifi-led-poe-active" title="PoE Activo"></span>
                                <span class="sw-unifi-led ${isBottomActive ? 'sw-unifi-led-link-active' : ''}" title="${isBottomActive ? 'Link 1 Gbps' : 'Sin Enlace'}"></span>
                            </div>
                        </div>

                        <!-- NÚMERO INFERIOR CON RAYO POE -->
                        <span class="sw-unifi-port-num-label sw-unifi-port-num-label-bottom">
                            ${pBottom}<span class="sw-unifi-poe-icon">⚡</span>
                        </span>
                    ` : ''}
                </div>
            `;
        }

        html += `</div>`;
    }

    container.innerHTML = html;

    // Renderizar Cages SFP / SFP+ en el panel derecho (#sw2d_sfp_unifi_box)
    if (sfpContainer) {
        let sfpHtml = '';
        if (portCount <= 24) {
            // Switch de 24 puertos (o 16): 2 jaulas SFP apiladas verticalmente como en Imagen 1
            const sfp1 = portCount + 1;
            const sfp2 = portCount + 2;
            const infoSfp1 = puertosMap[sfp1] || null;
            const infoSfp2 = puertosMap[sfp2] || null;
            const sfp1Active = !!infoSfp1;
            const sfp2Active = !!infoSfp2;
            if (sfp1Active) { totalOcupados++; enlacesArray.push(infoSfp1); }
            if (sfp2Active) { totalOcupados++; enlacesArray.push(infoSfp2); }

            sfpHtml = `
                <div class="d-flex flex-column gap-1">
                    <div class="sw-unifi-sfp-cage sw-port-item ${sfp1Active ? 'port-active' : ''}" 
                         data-port="${sfp1}" 
                         data-assigned="${sfp1Active ? '1' : '0'}"
                         data-eq-name="${sfp1Active ? (infoSfp1.equipo_nombre || '').toLowerCase() : ''}"
                         data-eq-ip="${sfp1Active ? (infoSfp1.equipo_ip || '').toLowerCase() : ''}"
                         onclick="abrirModalAsignarPuertoSwitch(${sfp1})"
                         title="${sfp1Active ? `SFP+ Puerto ${sfp1}: [${infoSfp1.equipo_nombre}]` : `SFP+ Uplink Puerto ${sfp1}: Disponible`}">
                        <div class="d-flex align-items-center justify-content-between w-100 px-1">
                            <span class="sw-unifi-sfp-num">${sfp1}</span>
                            <span style="font-size: 0.5rem; color: #475569;">▼</span>
                        </div>
                        <div class="sw-unifi-sfp-slot">
                            <i class="bi bi-hdd-rack ${sfp1Active ? 'text-success' : 'text-secondary'}" style="font-size: 0.75rem;"></i>
                        </div>
                    </div>
                    <div class="sw-unifi-sfp-cage sw-port-item ${sfp2Active ? 'port-active' : ''}" 
                         data-port="${sfp2}" 
                         data-assigned="${sfp2Active ? '1' : '0'}"
                         data-eq-name="${sfp2Active ? (infoSfp2.equipo_nombre || '').toLowerCase() : ''}"
                         data-eq-ip="${sfp2Active ? (infoSfp2.equipo_ip || '').toLowerCase() : ''}"
                         onclick="abrirModalAsignarPuertoSwitch(${sfp2})"
                         title="${sfp2Active ? `SFP+ Puerto ${sfp2}: [${infoSfp2.equipo_nombre}]` : `SFP+ Uplink Puerto ${sfp2}: Disponible`}">
                        <div class="d-flex align-items-center justify-content-between w-100 px-1">
                            <span class="sw-unifi-sfp-num">${sfp2}</span>
                            <span style="font-size: 0.5rem; color: #475569;">▲</span>
                        </div>
                        <div class="sw-unifi-sfp-slot">
                            <i class="bi bi-hdd-rack ${sfp2Active ? 'text-success' : 'text-secondary'}" style="font-size: 0.75rem;"></i>
                        </div>
                    </div>
                </div>
            `;
        } else {
            // Switch de 48 puertos: 4 jaulas SFP+ (2 pares)
            const sfpBase = portCount;
            let cagesCols = '';
            for (let s = 1; s <= 4; s += 2) {
                const pA = sfpBase + s;
                const pB = sfpBase + s + 1;
                const infoA = puertosMap[pA] || null;
                const infoB = puertosMap[pB] || null;
                const actA = !!infoA;
                const actB = !!infoB;
                if (actA) { totalOcupados++; enlacesArray.push(infoA); }
                if (actB) { totalOcupados++; enlacesArray.push(infoB); }

                cagesCols += `
                    <div class="d-flex flex-column gap-1">
                        <div class="sw-unifi-sfp-cage sw-port-item ${actA ? 'port-active' : ''}" 
                             data-port="${pA}" 
                             data-assigned="${actA ? '1' : '0'}"
                             data-eq-name="${actA ? (infoA.equipo_nombre || '').toLowerCase() : ''}"
                             data-eq-ip="${actA ? (infoA.equipo_ip || '').toLowerCase() : ''}"
                             onclick="abrirModalAsignarPuertoSwitch(${pA})"
                             title="${actA ? `SFP+ Puerto ${pA}: [${infoA.equipo_nombre}]` : `SFP+ Uplink Puerto ${pA}: Disponible`}">
                            <div class="d-flex align-items-center justify-content-between w-100 px-1">
                                <span class="sw-unifi-sfp-num">${pA}</span>
                                <span style="font-size: 0.5rem; color: #475569;">▼</span>
                            </div>
                            <div class="sw-unifi-sfp-slot">
                                <i class="bi bi-hdd-rack ${actA ? 'text-success' : 'text-secondary'}" style="font-size: 0.75rem;"></i>
                            </div>
                        </div>
                        <div class="sw-unifi-sfp-cage sw-port-item ${actB ? 'port-active' : ''}" 
                             data-port="${pB}" 
                             data-assigned="${actB ? '1' : '0'}"
                             data-eq-name="${actB ? (infoB.equipo_nombre || '').toLowerCase() : ''}"
                             data-eq-ip="${actB ? (infoB.equipo_ip || '').toLowerCase() : ''}"
                             onclick="abrirModalAsignarPuertoSwitch(${pB})"
                             title="${actB ? `SFP+ Puerto ${pB}: [${infoB.equipo_nombre}]` : `SFP+ Uplink Puerto ${pB}: Disponible`}">
                            <div class="d-flex align-items-center justify-content-between w-100 px-1">
                                <span class="sw-unifi-sfp-num">${pB}</span>
                                <span style="font-size: 0.5rem; color: #475569;">▲</span>
                            </div>
                            <div class="sw-unifi-sfp-slot">
                                <i class="bi bi-hdd-rack ${actB ? 'text-success' : 'text-secondary'}" style="font-size: 0.75rem;"></i>
                            </div>
                        </div>
                    </div>
                `;
            }
            sfpHtml = `<div class="d-flex align-items-center gap-1">${cagesCols}</div>`;
        }
        sfpContainer.innerHTML = sfpHtml;
    }

    // Actualizar métricas del resumen inferior
    const elTot = document.getElementById('sw2d_stat_total');
    const elOcup = document.getElementById('sw2d_stat_ocupados');
    const elLib = document.getElementById('sw2d_stat_libres');
    const elPorc = document.getElementById('sw2d_stat_porcentaje');
    const elProg = document.getElementById('sw2d_stat_progress_bar');

    const libres = Math.max(0, portCount - totalOcupados);
    const pct = portCount > 0 ? Math.round((totalOcupados / portCount) * 100) : 0;

    if (elTot) elTot.textContent = portCount;
    if (elOcup) elOcup.textContent = totalOcupados;
    if (elLib) elLib.textContent = libres;
    if (elPorc) elPorc.textContent = pct + '%';
    if (elProg) {
        elProg.style.width = pct + '%';
        elProg.className = 'progress-bar ' + (pct > 80 ? 'bg-danger' : (pct > 50 ? 'bg-warning' : 'bg-info'));
    }

    // Actualizar tabla rápida de matriz de enlaces
    actualizarTablaEnlacesSwitch2D(enlacesArray);
}

/**
 * Actualiza la tabla inferior con los dispositivos conectados en este switch
 */
function actualizarTablaEnlacesSwitch2D(enlaces) {
    const tbody = document.getElementById('sw2d_tabla_enlaces_body');
    const badgeCount = document.getElementById('sw2d_tabla_resumen_conteo');
    if (!tbody) return;

    if (badgeCount) badgeCount.textContent = (enlaces.length) + ' dispositivo(s) conectado(s)';

    if (!enlaces || enlaces.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-3 text-secondary italic">No hay enlaces registrados en este switch aún. Haz clic en un puerto para asignar uno.</td></tr>`;
        return;
    }

    // Ordenar por número de puerto
    enlaces.sort((a, b) => parseInt(a.puerto_numero, 10) - parseInt(b.puerto_numero, 10));

    let html = '';
    enlaces.forEach(item => {
        html += `
            <tr>
                <td class="ps-3">
                    <span class="badge bg-success bg-opacity-25 text-success border border-success font-monospace px-2 py-0.5">
                        Puerto ${item.puerto_numero}
                    </span>
                </td>
                <td class="fw-bold text-white">
                    <i class="bi bi-hdd-network text-info me-1"></i> ${escapeHtml(item.equipo_nombre || 'Equipo Desconocido')}
                    ${item.telefono_poe_ext ? `<span class="badge bg-warning bg-opacity-25 text-warning border border-warning ms-1.5 font-monospace" style="font-size: 0.72rem;" title="Teléfono PoE conectado en serie"><i class="bi bi-telephone-fill me-1"></i>Ext. ${escapeHtml(item.telefono_poe_ext)}</span>` : ''}
                </td>
                <td>
                    <span class="badge bg-dark border border-secondary text-info">${escapeHtml(item.equipo_tipo || 'Dispositivo')}</span>
                </td>
                <td class="font-monospace text-info fw-semibold">
                    ${escapeHtml(item.equipo_ip || 'Sin IP')}
                </td>
                <td class="font-monospace text-purple" style="color: #c084fc;">
                    ${item.nodo_codigo ? '<i class="bi bi-ethernet me-1"></i> Nodo ' + escapeHtml(item.nodo_codigo) : '<span class="text-secondary small">Directo</span>'}
                </td>
                <td>
                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning small">${escapeHtml(item.vlan || 'VLAN Nativa')}</span>
                </td>
                <td class="text-end pe-3">
                    <button type="button" class="btn btn-outline-info btn-xs py-0 px-2 rounded" onclick="abrirModalAsignarPuertoSwitch(${item.puerto_numero})" title="Editar o Liberar este Puerto">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

/**
 * Filtro visual entre Todos, Enlazados y Libres
 */
function filtrarPuertosVista2D(tipo) {
    currentFiltroPuertos2D = tipo;
    document.querySelectorAll('#modalSwitch2DPuertos .btn-group button').forEach(b => b.classList.remove('active'));

    if (tipo === 'todos') {
        const b = document.getElementById('btnSwFilterTodos');
        if (b) b.classList.add('active');
    } else if (tipo === 'ocupados') {
        const b = document.getElementById('btnSwFilterOcupados');
        if (b) b.classList.add('active');
    } else if (tipo === 'libres') {
        const b = document.getElementById('btnSwFilterLibres');
        if (b) b.classList.add('active');
    }

    const items = document.querySelectorAll('.sw-port-item, .sw-unifi-rj45-box, .sw-unifi-sfp-cage');
    items.forEach(it => {
        const isAssigned = it.getAttribute('data-assigned') === '1';
        it.classList.remove('port-dimmed');

        if (tipo === 'ocupados' && !isAssigned) {
            it.classList.add('port-dimmed');
        } else if (tipo === 'libres' && isAssigned) {
            it.classList.add('port-dimmed');
        }
    });
}

/**
 * Búsqueda interactiva en los puertos del switch
 */
function buscarEnPuertosSwitch2D(query) {
    const q = (query || '').toLowerCase().trim();
    const items = document.querySelectorAll('.sw-port-item, .sw-unifi-rj45-box, .sw-unifi-sfp-cage');

    items.forEach(it => {
        it.classList.remove('port-highlight');
        if (!q) {
            it.classList.remove('port-dimmed');
            return;
        }

        const eqName = it.getAttribute('data-eq-name') || '';
        const eqIp = it.getAttribute('data-eq-ip') || '';
        const pNum = it.getAttribute('data-port') || '';

        if (eqName.includes(q) || eqIp.includes(q) || pNum === q) {
            it.classList.add('port-highlight');
            it.classList.remove('port-dimmed');
        } else {
            it.classList.add('port-dimmed');
        }
    });
}

/**
 * Abre el modal para asignar o desvincular un equipo en un puerto específico
 */
function abrirModalAsignarPuertoSwitch(puertoNum) {
    if (!currentSwitch2D) return;

    const fKey = document.getElementById('formSw_switch_key');
    const fNom = document.getElementById('formSw_switch_nombre');
    const fNum = document.getElementById('formSw_puerto_num');
    const elTit = document.getElementById('modalAsignarPuertoTitulo');
    const elSub = document.getElementById('modalAsignarPuertoSub');
    const btnLiberar = document.getElementById('btnLiberarPuertoModal');

    if (fKey) fKey.value = currentSwitch2D.key || '';
    if (fNom) fNom.value = currentSwitch2D.label || '';
    if (fNum) fNum.value = puertoNum;

    if (elTit) elTit.textContent = `Gestión de Puerto ${puertoNum}`;
    if (elSub) elSub.textContent = `${currentSwitch2D.label || 'Switch'} • Panel Frontal`;

    // Cargar asignación actual si existe
    const assigned = currentSwitchPuertosMap[puertoNum] || null;

    const badgeEstado = document.getElementById('badgeEstadoPuertoActual');
    const txtEquipo = document.getElementById('textoEquipoConectadoActual');
    const txtIp = document.getElementById('textoIpConectadoActual');
    const selEquipo = document.getElementById('selectEquipoParaPuerto');
    const inpIp = document.getElementById('inputIpEquipoPuerto');
    const selVlan = document.getElementById('selectVlanParaPuerto');
    const selNodo = document.getElementById('selectNodoParaPuerto');
    const inpNotas = document.getElementById('inputNotasPuerto');
    const boxCustom = document.getElementById('boxCustomEquipoPuerto');

    if (assigned) {
        if (badgeEstado) {
            badgeEstado.textContent = 'Enlazado / Activo';
            badgeEstado.className = 'badge bg-success';
        }
        if (txtEquipo) txtEquipo.textContent = `${assigned.equipo_nombre} (${assigned.equipo_tipo || 'Dispositivo'})`;
        if (txtIp) txtIp.textContent = assigned.equipo_ip || 'Sin IP';
        if (btnLiberar) btnLiberar.style.display = 'inline-block';

        if (selEquipo) {
            selEquipo.value = assigned.equipo_key || '__custom__';
            if (selEquipo.value === '__custom__' && boxCustom) {
                boxCustom.style.display = 'block';
                const inpCustNom = document.getElementById('inputCustomNombreEquipoPuerto');
                const inpCustTipo = document.getElementById('inputCustomTipoEquipoPuerto');
                if (inpCustNom) inpCustNom.value = assigned.equipo_nombre || '';
                if (inpCustTipo) inpCustTipo.value = assigned.equipo_tipo || '';
            } else if (boxCustom) {
                boxCustom.style.display = 'none';
            }
        }
        if (inpIp) inpIp.value = assigned.equipo_ip || '';
        if ((!assigned.equipo_ip || assigned.equipo_ip === 'Sin IP') && selEquipo && selEquipo.selectedOptions.length > 0) {
            const devIp = selEquipo.selectedOptions[0].getAttribute('data-ip');
            if (devIp && devIp !== 'Sin IP') {
                if (inpIp) inpIp.value = devIp;
                if (txtIp) txtIp.textContent = devIp;
            }
        }
        if (selVlan) selVlan.value = assigned.vlan || '';
        if (selNodo) selNodo.value = assigned.nodo_codigo || '';
        if (inpNotas) inpNotas.value = assigned.notas || '';

        const checkTelSw = document.getElementById('check_puerto_tiene_telefono');
        const selTelSw = document.getElementById('selectTelefonoParaPuertoCascada');
        const boxTelSw = document.getElementById('box_puerto_telefono_select');
        const tieneTelSw = parseInt(assigned.tiene_telefono_poe || 0) === 1 || Boolean(assigned.telefono_poe_key);
        if (checkTelSw) checkTelSw.checked = tieneTelSw;
        if (boxTelSw) boxTelSw.style.display = tieneTelSw ? 'block' : 'none';
        if (selTelSw) {
            if (assigned.telefono_poe_key) {
                selTelSw.value = assigned.telefono_poe_key.replace('TEL-', '');
            } else {
                selTelSw.value = '';
            }
        }
    } else {
        if (badgeEstado) {
            badgeEstado.textContent = 'Disponible / Libre';
            badgeEstado.className = 'badge bg-secondary';
        }
        if (txtEquipo) txtEquipo.textContent = 'Ninguno (Libre)';
        if (txtIp) txtIp.textContent = '--';
        if (btnLiberar) btnLiberar.style.display = 'none';

        if (selEquipo) selEquipo.value = '';
        if (boxCustom) boxCustom.style.display = 'none';
        if (inpIp) inpIp.value = '';
        if (selVlan) selVlan.value = '';
        if (selNodo) selNodo.value = '';
        if (inpNotas) inpNotas.value = '';

        const checkTelSw = document.getElementById('check_puerto_tiene_telefono');
        const selTelSw = document.getElementById('selectTelefonoParaPuertoCascada');
        const boxTelSw = document.getElementById('box_puerto_telefono_select');
        if (checkTelSw) checkTelSw.checked = false;
        if (selTelSw) selTelSw.value = '';
        if (boxTelSw) boxTelSw.style.display = 'none';
    }

    const mModal = document.getElementById('modalAsignarPuertoSwitch2D');
    if (mModal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modalObj = bootstrap.Modal.getOrCreateInstance(mModal);
        modalObj.show();
    }
}

function togglePuertoTelefonoSection(isChecked) {
    const box = document.getElementById('box_puerto_telefono_select');
    if (box) box.style.display = isChecked ? 'block' : 'none';
}

/**
 * Maneja el cambio de selección en el selector de equipo
 */
function alSeleccionarEquipoParaPuerto(val) {
    const boxCustom = document.getElementById('boxCustomEquipoPuerto');
    const inpIp = document.getElementById('inputIpEquipoPuerto');
    const selEquipo = document.getElementById('selectEquipoParaPuerto');
    const selNodo = document.getElementById('selectNodoParaPuerto');

    if (val === '__custom__') {
        if (boxCustom) boxCustom.style.display = 'block';
    } else {
        if (boxCustom) boxCustom.style.display = 'none';
        if (selEquipo && selEquipo.selectedOptions.length > 0) {
            const opt = selEquipo.selectedOptions[0];
            const ip = opt.getAttribute('data-ip');
            const nodo = opt.getAttribute('data-nodo');
            if (inpIp && ip && ip !== 'Sin IP') {
                inpIp.value = ip;
            }
            if (selNodo && nodo) {
                selNodo.value = nodo;
            }
        }
    }
}

/**
 * Guarda la asignación del puerto vía AJAX
 */
function guardarAsignacionPuertoSwitchSubmit(e) {
    e.preventDefault();

    const switchKey = document.getElementById('formSw_switch_key')?.value || '';
    const switchNombre = document.getElementById('formSw_switch_nombre')?.value || '';
    const puertoNum = parseInt(document.getElementById('formSw_puerto_num')?.value || 0, 10);

    const selEquipo = document.getElementById('selectEquipoParaPuerto');
    const equipoVal = selEquipo?.value || '';

    let equipoKey = equipoVal;
    let equipoNombre = '';
    let equipoTipo = '';

    if (equipoVal === '__custom__') {
        equipoNombre = document.getElementById('inputCustomNombreEquipoPuerto')?.value.trim() || 'Dispositivo';
        equipoTipo = document.getElementById('inputCustomTipoEquipoPuerto')?.value.trim() || 'Equipo';
        equipoKey = 'MANUAL-' + Date.now();
    } else if (equipoVal !== '') {
        const opt = selEquipo.selectedOptions[0];
        equipoNombre = opt.getAttribute('data-label') || equipoVal;
        equipoTipo = opt.getAttribute('data-tipo') || 'Equipo';
    } else {
        alert('Por favor selecciona un equipo del inventario o ingresa uno personalizado.');
        return;
    }

    const equipoIp = document.getElementById('inputIpEquipoPuerto')?.value.trim() || '';
    const vlan = document.getElementById('selectVlanParaPuerto')?.value || '';
    const nodoCodigo = document.getElementById('selectNodoParaPuerto')?.value || '';
    const notas = document.getElementById('inputNotasPuerto')?.value.trim() || '';

    const checkTelSw = document.getElementById('check_puerto_tiene_telefono');
    const selTelSw = document.getElementById('selectTelefonoParaPuertoCascada');
    const tieneTelSw = (checkTelSw && checkTelSw.checked) ? 1 : 0;
    const telKey = (tieneTelSw && selTelSw && selTelSw.value) ? ('TEL-' + selTelSw.value) : '';

    const formData = new FormData();
    formData.append('accion', 'guardar_puerto_switch');
    formData.append('tipo_operacion', 'asignar');
    formData.append('switch_key', switchKey);
    formData.append('switch_nombre', switchNombre);
    formData.append('puerto_numero', puertoNum);
    formData.append('equipo_key', equipoKey);
    formData.append('equipo_nombre', equipoNombre);
    formData.append('equipo_tipo', equipoTipo);
    formData.append('equipo_ip', equipoIp);
    formData.append('vlan', vlan);
    formData.append('nodo_codigo', nodoCodigo);
    formData.append('notas', notas);
    formData.append('tiene_telefono_poe', tieneTelSw);
    formData.append('telefono_key', telKey);

    fetch('infraestructura.php?sec=red', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            // Actualizar estado local
            // Desvincular este equipo o nodo de cualquier otro puerto en el mapa local
            for (const p in currentSwitchPuertosMap) {
                if (parseInt(p, 10) !== puertoNum) {
                    if (currentSwitchPuertosMap[p].equipo_key === equipoKey) {
                        delete currentSwitchPuertosMap[p];
                    } else if (nodoCodigo && currentSwitchPuertosMap[p].nodo_codigo === nodoCodigo) {
                        currentSwitchPuertosMap[p].nodo_codigo = '';
                    }
                }
            }

            currentSwitchPuertosMap[puertoNum] = {
                puerto_numero: puertoNum,
                switch_key: switchKey,
                switch_nombre: switchNombre,
                equipo_key: equipoKey,
                equipo_nombre: equipoNombre,
                equipo_tipo: equipoTipo,
                equipo_ip: equipoIp,
                vlan: vlan,
                nodo_codigo: nodoCodigo,
                notas: notas
            };

            // Rerenderizar vista 2D del Switch
            renderizarPuertosSwitch2D(currentSwitch2D, currentSwitchPuertosMap);

            // Cerrar modal de asignación
            const mModal = document.getElementById('modalAsignarPuertoSwitch2D');
            if (mModal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modalObj = bootstrap.Modal.getInstance(mModal);
                if (modalObj) modalObj.hide();
            }

            if (typeof mostrarNotificacionToast === 'function') {
                mostrarNotificacionToast(data.message || 'Puerto enlazado correctamente', 'success');
            } else {
                alert(data.message || 'Puerto enlazado correctamente');
            }
        } else {
            alert('Error: ' + (data.message || 'No se pudo guardar la asignación.'));
        }
    })
    .catch(err => {
        alert('Error al conectar con el servidor: ' + err.message);
    });
}

/**
 * Libera / Desconecta el equipo del puerto actual
 */
function liberarPuertoSwitchActual() {
    const switchKey = document.getElementById('formSw_switch_key')?.value || '';
    const switchNombre = document.getElementById('formSw_switch_nombre')?.value || '';
    const puertoNum = parseInt(document.getElementById('formSw_puerto_num')?.value || 0, 10);

    if (!confirm(`¿Estás seguro de que deseas liberar y desconectar el Puerto ${puertoNum} de ${switchNombre}?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('accion', 'guardar_puerto_switch');
    formData.append('tipo_operacion', 'liberar');
    formData.append('switch_key', switchKey);
    formData.append('switch_nombre', switchNombre);
    formData.append('puerto_numero', puertoNum);

    fetch('infraestructura.php?sec=red', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            delete currentSwitchPuertosMap[puertoNum];
            renderizarPuertosSwitch2D(currentSwitch2D, currentSwitchPuertosMap);

            const mModal = document.getElementById('modalAsignarPuertoSwitch2D');
            if (mModal && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modalObj = bootstrap.Modal.getInstance(mModal);
                if (modalObj) modalObj.hide();
            }

            if (typeof mostrarNotificacionToast === 'function') {
                mostrarNotificacionToast(data.message || 'Puerto liberado correctamente', 'info');
            } else {
                alert(data.message || 'Puerto liberado correctamente');
            }
        } else {
            alert('Error: ' + (data.message || 'No se pudo liberar el puerto.'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}

// =========================================================================
// SINCRONIZACIÓN DE COMBOS SWITCH & PUERTO EN FORMULARIO DE NODOS DE RED
// =========================================================================
function alCambiarSwitchEnNodoForm(swNombre) {
    const selPuerto = document.getElementById('combo_nodo_puerto_select');
    const hiddenInput = document.getElementById('field_switch_puerto');
    const selSwitch = document.getElementById('combo_nodo_switch_select');

    if (!selPuerto) return;
    selPuerto.innerHTML = '<option value="">-- Puerto --</option>';

    if (!swNombre) {
        if (hiddenInput) hiddenInput.value = '';
        return;
    }

    let cantPuertos = 48;
    if (selSwitch && selSwitch.selectedOptions.length > 0) {
        const pAttr = selSwitch.selectedOptions[0].getAttribute('data-puertos');
        if (pAttr) cantPuertos = parseInt(pAttr, 10) || 48;
    }

    for (let i = 1; i <= cantPuertos; i++) {
        const opt = document.createElement('option');
        opt.value = 'Puerto ' + i;
        opt.textContent = 'Puerto ' + i;
        selPuerto.appendChild(opt);
    }

    if (hiddenInput) {
        hiddenInput.value = swNombre;
    }
}

function alCambiarPuertoEnNodoForm(puertoVal) {
    const selSwitch = document.getElementById('combo_nodo_switch_select');
    const hiddenInput = document.getElementById('field_switch_puerto');
    const swNom = selSwitch ? selSwitch.value : '';

    if (hiddenInput) {
        if (swNom && puertoVal) {
            hiddenInput.value = swNom + ' / ' + puertoVal;
        } else if (swNom) {
            hiddenInput.value = swNom;
        } else {
            hiddenInput.value = puertoVal;
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Exportar a window para alcance global
window.filtrarSwitchesRed = filtrarSwitchesRed;
window.filtrarNodosPorSwitch = filtrarNodosPorSwitch;
window.verDetalleSwitchFlotante = verDetalleSwitchFlotante;
window.abrirVistaPuertosSwitch2D = abrirVistaPuertosSwitch2D;
window.filtrarPuertosVista2D = filtrarPuertosVista2D;
window.buscarEnPuertosSwitch2D = buscarEnPuertosSwitch2D;
window.abrirModalAsignarPuertoSwitch = abrirModalAsignarPuertoSwitch;
window.alSeleccionarEquipoParaPuerto = alSeleccionarEquipoParaPuerto;
window.guardarAsignacionPuertoSwitchSubmit = guardarAsignacionPuertoSwitchSubmit;
window.liberarPuertoSwitchActual = liberarPuertoSwitchActual;
window.alCambiarSwitchEnNodoForm = alCambiarSwitchEnNodoForm;
window.alCambiarPuertoEnNodoForm = alCambiarPuertoEnNodoForm;
window.toggleNodoTelefonoSection = toggleNodoTelefonoSection;
window.togglePuertoTelefonoSection = togglePuertoTelefonoSection;
